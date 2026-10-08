/**
 * SEOlity relay — Cloudflare Worker (deployed automatically by the SEOlity plugin).
 *
 *  - Mailbox: the subscription runner on GitHub Actions POSTs its encrypted,
 *    HMAC-signed results here. In "push" mode the worker forwards them to the
 *    WordPress site at once; in "pull" mode (local / firewalled sites) they wait
 *    in KV until WordPress collects them. Faster than reading results from git.
 *  - Heartbeat: a Cron Trigger calls the site every few minutes so SEOlity's
 *    background queue (Search Console sync, audits, automation, subscription
 *    answers) runs on time even when the site has few visitors (WP-Cron only
 *    runs on page views).
 *  - Diagnose: checks from Cloudflare's side that the site's REST API is reachable.
 *
 * Bindings (set by the plugin): SEOLITY_KV (KV), SITE_URL, WP_API, MODE (push|pull),
 * VERSION, and the secret SEOLITY_SHARED_SECRET (same key as the runner).
 * Every request between WordPress, GitHub and this worker is signed with
 * HMAC-SHA256 over "<timestamp>.<payload>"; results stay AES-256-GCM encrypted.
 */

const enc = new TextEncoder();
const TTL = 3 * 24 * 3600; // Undelivered results are kept for 3 days.

function hex( buf ) {
	return [ ...new Uint8Array( buf ) ].map( ( b ) => b.toString( 16 ).padStart( 2, '0' ) ).join( '' );
}

async function macKey( env ) {
	// Same derivation as PHP: hash('sha256', 'mac|' . secret) as a hex string key.
	return hex( await crypto.subtle.digest( 'SHA-256', enc.encode( 'mac|' + ( env.SEOLITY_SHARED_SECRET || '' ) ) ) );
}

async function hmac( env, message ) {
	const key = await crypto.subtle.importKey( 'raw', enc.encode( await macKey( env ) ), { name: 'HMAC', hash: 'SHA-256' }, false, [ 'sign' ] );
	return hex( await crypto.subtle.sign( 'HMAC', key, enc.encode( message ) ) );
}

function safeEqual( a, b ) {
	if ( typeof a !== 'string' || typeof b !== 'string' || a.length !== b.length ) {
		return false;
	}
	let r = 0;
	for ( let i = 0; i < a.length; i++ ) {
		r |= a.charCodeAt( i ) ^ b.charCodeAt( i );
	}
	return r === 0;
}

async function verify( env, req, payload ) {
	const ts = req.headers.get( 'x-seolity-timestamp' ) || '';
	const sig = req.headers.get( 'x-seolity-signature' ) || '';
	if ( ! env.SEOLITY_SHARED_SECRET || ! /^\d+$/.test( ts ) || Math.abs( Date.now() / 1000 - Number( ts ) ) > 600 ) {
		return null;
	}
	return safeEqual( await hmac( env, `${ ts }.${ payload }` ), sig ) ? { ts, sig } : null;
}

function json( data, status = 200 ) {
	return new Response( JSON.stringify( data ), { status, headers: { 'content-type': 'application/json; charset=utf-8', 'cache-control': 'no-store' } } );
}

async function signedFetch( env, url, body ) {
	const ts = String( Math.floor( Date.now() / 1000 ) );
	const sig = await hmac( env, `${ ts }.${ body }` );
	return fetch( url, {
		method: 'POST',
		headers: { 'content-type': 'application/json', 'user-agent': 'SEOlity-Relay/' + ( env.VERSION || '1' ), 'x-seolity-timestamp': ts, 'x-seolity-signature': sig },
		body,
		signal: AbortSignal.timeout( 25000 ),
	} );
}

async function forward( env, key, item ) {
	try {
		const res = await fetch( env.WP_API + '/remote/callback', {
			method: 'POST',
			headers: { 'content-type': 'application/json', 'user-agent': 'SEOlity-Relay/' + ( env.VERSION || '1' ), 'x-seolity-timestamp': item.ts, 'x-seolity-signature': item.sig },
			body: item.body,
			signal: AbortSignal.timeout( 25000 ),
		} );
		if ( res.ok ) {
			await env.SEOLITY_KV.delete( key );
			return true;
		}
	} catch ( e ) {}
	return false;
}

async function heartbeat( env ) {
	if ( env.MODE !== 'push' || ! env.WP_API ) {
		return { skipped: true };
	}
	const started = Date.now();
	try {
		const res = await signedFetch( env, env.WP_API + '/remote/heartbeat', JSON.stringify( { t: Date.now(), from: 'cron' } ) );
		return { status: res.status, ms: Date.now() - started };
	} catch ( e ) {
		return { error: String( e.message || e ), ms: Date.now() - started };
	}
}

export default {
	async fetch( req, env, ctx ) {
		const url = new URL( req.url );
		const path = url.pathname.replace( /\/+$/, '' ) || '/';

		if ( req.method === 'GET' && ( path === '/' || path === '/health' ) ) {
			return json( { ok: true, service: 'seolity-relay', version: env.VERSION || '', mode: env.MODE || 'pull', kv: !! env.SEOLITY_KV, secret: !! env.SEOLITY_SHARED_SECRET, time: new Date().toISOString() } );
		}

		// Runner → mailbox.
		if ( req.method === 'POST' && path === '/mailbox' ) {
			const body = await req.text();
			const auth = await verify( env, req, body );
			if ( ! auth ) {
				return json( { error: 'invalid signature' }, 401 );
			}
			const key = `mb:${ Date.now() }:${ crypto.randomUUID() }`;
			const item = { ts: auth.ts, sig: auth.sig, body };
			await env.SEOLITY_KV.put( key, JSON.stringify( item ), { expirationTtl: TTL } );
			let forwarded = false;
			if ( env.MODE === 'push' ) {
				forwarded = await forward( env, key, item );
			}
			return json( { stored: true, forwarded } );
		}

		// WordPress → read the mailbox (pull mode, or results the site could not take).
		if ( req.method === 'POST' && path === '/mailbox/list' ) {
			const body = await req.text();
			if ( ! ( await verify( env, req, body ) ) ) {
				return json( { error: 'invalid signature' }, 401 );
			}
			const list = await env.SEOLITY_KV.list( { prefix: 'mb:', limit: 50 } );
			const items = [];
			for ( const k of list.keys ) {
				const v = await env.SEOLITY_KV.get( k.name );
				if ( v ) {
					items.push( { key: k.name, ...JSON.parse( v ) } );
				}
			}
			return json( { items, more: ! list.list_complete } );
		}

		if ( req.method === 'POST' && path === '/mailbox/ack' ) {
			const body = await req.text();
			if ( ! ( await verify( env, req, body ) ) ) {
				return json( { error: 'invalid signature' }, 401 );
			}
			let keys = [];
			try {
				keys = ( JSON.parse( body ).keys || [] ).filter( ( k ) => typeof k === 'string' && k.startsWith( 'mb:' ) ).slice( 0, 100 );
			} catch ( e ) {}
			await Promise.all( keys.map( ( k ) => env.SEOLITY_KV.delete( k ) ) );
			return json( { deleted: keys.length } );
		}

		// WordPress → "can Cloudflare reach my site?"
		if ( req.method === 'POST' && path === '/diagnose' ) {
			const body = await req.text();
			if ( ! ( await verify( env, req, body ) ) ) {
				return json( { error: 'invalid signature' }, 401 );
			}
			const out = { wp_api: env.WP_API, mode: env.MODE };
			const started = Date.now();
			try {
				const res = await fetch( env.WP_API + '/remote/ping', { headers: { 'user-agent': 'SEOlity-Relay/' + ( env.VERSION || '1' ) }, signal: AbortSignal.timeout( 15000 ) } );
				out.status = res.status;
				out.body = ( await res.text() ).slice( 0, 300 );
			} catch ( e ) {
				out.error = String( e.message || e );
			}
			out.ms = Date.now() - started;
			out.heartbeat = await heartbeat( env );
			return json( out );
		}

		return json( { error: 'not found' }, 404 );
	},

	async scheduled( event, env, ctx ) {
		ctx.waitUntil( heartbeat( env ) );
	},
};
