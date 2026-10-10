/**
 * SEOlity UI toolkit (no build step, no framework).
 * Shared by the admin SPA and all editor integrations.
 *
 * Security: all dynamic text goes through text nodes (never innerHTML);
 * AI HTML previews render inside a sandboxed iframe without scripts.
 */
( function ( w, d ) {
	'use strict';

	var C = w.SEOLITY || {};
	var SL = ( w.SL = w.SL || {} );
	SL.config = C;

	/* ------------------------------------------------------------------ i18n */
	SL.t = function ( s ) {
		var dict = C.i18n || {};
		var out = Object.prototype.hasOwnProperty.call( dict, s ) && dict[ s ] ? dict[ s ] : s;
		for ( var i = 1; i < arguments.length; i++ ) {
			out = out.replace( '%s', arguments[ i ] ).replace( '%' + i + '$s', arguments[ i ] ).replace( '%d', arguments[ i ] );
		}
		return out;
	};
	var t = SL.t;
	var locale = ( C.locale || 'en' ).replace( '_', '-' );
	SL.num = function ( n, digits ) {
		if ( n === null || n === undefined || n === '' || isNaN( n ) ) {
			return '—';
		}
		try {
			return Number( n ).toLocaleString( locale, { maximumFractionDigits: digits === undefined ? 1 : digits } );
		} catch ( e ) {
			return String( n );
		}
	};
	SL.pct = function ( n ) {
		return n === null || n === undefined ? '—' : ( n > 0 ? '+' : '' ) + SL.num( n ) + '%';
	};
	SL.date = function ( s ) {
		if ( ! s ) {
			return '—';
		}
		var dt = new Date( String( s ).replace( ' ', 'T' ) + ( /Z|\+/.test( s ) || s.length <= 10 ? '' : 'Z' ) );
		try {
			return dt.toLocaleDateString( locale, { year: 'numeric', month: 'short', day: 'numeric' } );
		} catch ( e ) {
			return s;
		}
	};
	SL.path = function ( url ) {
		try {
			var u = new URL( url );
			return decodeURIComponent( u.pathname + u.search ) || '/';
		} catch ( e ) {
			return url || '';
		}
	};

	/* ------------------------------------------------------------------ DOM */
	SL.h = function h( tag, attrs ) {
		var el = d.createElement( tag );
		attrs = attrs || {};
		Object.keys( attrs ).forEach( function ( k ) {
			var v = attrs[ k ];
			if ( v === null || v === undefined || v === false ) {
				return;
			}
			if ( k === 'class' ) {
				el.className = v;
			} else if ( k === 'style' && typeof v === 'object' ) {
				Object.keys( v ).forEach( function ( p ) {
					el.style.setProperty( p, v[ p ] );
				} );
			} else if ( k.indexOf( 'on' ) === 0 && typeof v === 'function' ) {
				el.addEventListener( k.slice( 2 ).toLowerCase(), v );
			} else if ( k === 'text' ) {
				el.textContent = v;
			} else if ( k === 'value' ) {
				el.value = v;
			} else if ( v === true ) {
				el.setAttribute( k, '' );
			} else {
				el.setAttribute( k, v );
			}
		} );
		for ( var i = 2; i < arguments.length; i++ ) {
			SL.append( el, arguments[ i ] );
		}
		return el;
	};
	SL.append = function ( el, child ) {
		if ( child === null || child === undefined || child === false ) {
			return el;
		}
		if ( Array.isArray( child ) ) {
			child.forEach( function ( c ) {
				SL.append( el, c );
			} );
		} else if ( child instanceof Node ) {
			el.appendChild( child );
		} else {
			el.appendChild( d.createTextNode( String( child ) ) );
		}
		return el;
	};
	SL.clear = function ( el ) {
		while ( el && el.firstChild ) {
			el.removeChild( el.firstChild );
		}
		return el;
	};
	var h = SL.h;

	/* ------------------------------------------------------------------ API */
	SL.api = function ( path, opts ) {
		opts = opts || {};
		var method = opts.method || ( opts.body ? 'POST' : 'GET' );
		var url = ( C.root || '/wp-json/seolity/v1/' ) + path.replace( /^\//, '' );
		if ( opts.query ) {
			var q = Object.keys( opts.query ).filter( function ( k ) {
				return opts.query[ k ] !== '' && opts.query[ k ] !== null && opts.query[ k ] !== undefined;
			} ).map( function ( k ) {
				return encodeURIComponent( k ) + '=' + encodeURIComponent( opts.query[ k ] );
			} ).join( '&' );
			if ( q ) {
				url += ( url.indexOf( '?' ) > -1 ? '&' : '?' ) + q;
			}
		}
		return fetch( url, {
			method: method,
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': C.nonce },
			body: opts.body ? JSON.stringify( opts.body ) : undefined,
		} ).then( function ( res ) {
			return res.text().then( function ( txt ) {
				var data = null;
				try {
					data = txt ? JSON.parse( txt ) : null;
				} catch ( e ) {
					data = { message: txt.slice( 0, 300 ) };
				}
				if ( 202 === res.status && data && data.code === 'seolity_ai_deferred' && ! opts.noWait ) {
					// Subscription AI (GitHub runner): wait for the answer, then re-send the identical request.
					return SL.waitRemote( data.data.job ).then( function () {
						return SL.api( path, opts );
					} );
				}
				if ( ! res.ok ) {
					var err = new Error( ( data && data.message ) || res.statusText );
					err.status = res.status;
					err.data = data;
					throw err;
				}
				return data;
			} );
		} );
	};

	/* ------------------------------------------------------------------ Subscription AI waiting */
	var waits = {};
	var waitBox = null;
	var waitTimer = null;
	function renderWaits() {
		var ids = Object.keys( waits );
		if ( ! ids.length ) {
			if ( waitBox ) {
				waitBox.remove();
				waitBox = null;
			}
			clearInterval( waitTimer );
			waitTimer = null;
			return;
		}
		if ( ! waitBox ) {
			waitBox = h( 'div', { class: 'sl-remote-wait sl-root' + ( C.rtl ? ' is-rtl' : '' ), dir: C.rtl ? 'rtl' : 'ltr', role: 'status', 'aria-live': 'polite' } );
			d.body.appendChild( waitBox );
		}
		var oldest = Math.min.apply( null, ids.map( function ( k ) {
			return waits[ k ];
		} ) );
		var sec = Math.round( ( Date.now() - oldest ) / 1000 );
		SL.clear( waitBox );
		waitBox.appendChild( h( 'span', { class: 'sl-spinner sl-spinner--sm', 'aria-hidden': 'true' } ) );
		waitBox.appendChild( h( 'span', { text: t( 'AI is answering through GitHub (%s)…', ids.length ) + ' ' + Math.floor( sec / 60 ) + ':' + ( '0' + ( sec % 60 ) ).slice( -2 ) } ) );
	}
	SL.waitRemote = function ( job ) {
		waits[ job ] = waits[ job ] || Date.now();
		renderWaits();
		if ( ! waitTimer ) {
			waitTimer = setInterval( renderWaits, 1000 );
		}
		var started = Date.now();
		return new Promise( function ( resolve, reject ) {
			function done( fn, arg ) {
				delete waits[ job ];
				renderWaits();
				fn( arg );
			}
			function poll() {
				SL.api( 'ai/remote/' + job, { noWait: true } ).then( function ( r ) {
					if ( r.status === 'done' || r.status === 'failed' || r.status === 'unknown' ) {
						done( resolve ); // The re-sent request returns the answer (or the runner's error).
					} else if ( Date.now() - started > 65 * 60000 ) {
						done( reject, new Error( t( 'The subscription runner did not answer in time. Check the "SEOlity AI" workflow runs on GitHub.' ) ) );
					} else {
						// Answers from a warm runner arrive in seconds: poll quickly at first, then relax.
						setTimeout( poll, Date.now() - started < 120000 ? 2500 : 6000 );
					}
				} ).catch( function () {
					setTimeout( poll, 15000 ); // Transient network/server error: keep waiting.
				} );
			}
			setTimeout( poll, 2000 );
		} );
	};

	/* ------------------------------------------------------------------ Icons
	 * Line icon set (24x24, 1.75 stroke), shared with the ARIAGH AI Writer admin. Static,
	 * trusted markup only — never pass user data here. */
	SL.ICONS = {
		'arrow-right': '<path d="M5 12h14M13 6l6 6-6 6"/>',
		'arrow-left': '<path d="M19 12H5M11 6l-6 6 6 6"/>',
		'sun': '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
		'trending-down': '<path d="m22 17-8.5-8.5-5 5L2 7"/><path d="M16 17h6v-6"/>',
		'brain': '<path d="M9.5 2A2.5 2.5 0 0 1 12 4.5v15a2.5 2.5 0 0 1-4.96.44 2.5 2.5 0 0 1-2.96-3.08 3 3 0 0 1-.34-5.58 2.5 2.5 0 0 1 1.32-4.24 2.5 2.5 0 0 1 4.44-2.04Z"/><path d="M14.5 2A2.5 2.5 0 0 0 12 4.5v15a2.5 2.5 0 0 0 4.96.44 2.5 2.5 0 0 0 2.96-3.08 3 3 0 0 0 .34-5.58 2.5 2.5 0 0 0-1.32-4.24 2.5 2.5 0 0 0-4.44-2.04Z"/>',
		'landmark': '<path d="M3 21h18M5 21V10M9 21V10M15 21V10M19 21V10"/><path d="m12 3 9 5H3z"/>',
		'activity': '<path d="M22 12h-4l-3 8L9 4l-3 8H2"/>',
		'alert': '<path d="m21.73 18-8-14a2 2 0 0 0-3.46 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3z"/><path d="M12 9v4M12 17h.01"/>',
		'arrow-down': '<path d="M12 5v14M19 12l-7 7-7-7"/>',
		'arrow-up': '<path d="m5 12 7-7 7 7M12 19V5"/>',
		'bag': '<path d="M5 8h14l-1 12a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>',
		'bell': '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
		'book': '<path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"/>',
		'bot': '<rect x="4" y="8" width="16" height="12" rx="3"/><path d="M12 4v4"/><circle cx="12" cy="3" r="1"/><circle cx="9" cy="14" r="1.2"/><circle cx="15" cy="14" r="1.2"/><path d="M2 13v3M22 13v3"/>',
		'braces': '<path d="M8 3H7a2 2 0 0 0-2 2v5a2 2 0 0 1-2 2 2 2 0 0 1 2 2v5a2 2 0 0 0 2 2h1"/><path d="M16 21h1a2 2 0 0 0 2-2v-5a2 2 0 0 1 2-2 2 2 0 0 1-2-2V5a2 2 0 0 0-2-2h-1"/>',
		'calendar': '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
		'chart': '<path d="M3 3v18h18"/><path d="m7 15 4-4 3 3 5-6"/>',
		'check': '<path d="M20 6 9 17l-5-5"/>',
		'chevron-down': '<path d="m6 9 6 6 6-6"/>',
		'chevrons-down': '<path d="m7 13 5 5 5-5M7 6l5 5 5-5"/>',
		'chevrons-up': '<path d="m17 11-5-5-5 5M17 18l-5-5-5 5"/>',
		'clock': '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		'cloud': '<path d="M17.5 19a4.5 4.5 0 0 0 .5-8.97A6 6 0 0 0 6.34 9.2 4.5 4.5 0 0 0 6.5 19z"/>',
		'code': '<path d="m16 18 6-6-6-6M8 6l-6 6 6 6"/>',
		'content': '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h4"/>',
		'copy': '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1"/>',
		'corner': '<path d="M4 4v7a4 4 0 0 0 4 4h12"/><path d="m15 10 5 5-5 5"/>',
		'cpu': '<rect x="5" y="5" width="14" height="14" rx="2"/><rect x="9" y="9" width="6" height="6" rx="1"/><path d="M9 2v3M15 2v3M9 19v3M15 19v3M2 9h3M2 15h3M19 9h3M19 15h3"/>',
		'dashboard': '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
		'database': '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/><path d="M3 12c0 1.66 4 3 9 3s9-1.34 9-3"/>',
		'download': '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
		'edit': '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"/>',
		'external': '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
		'eye': '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
		'eye-off': '<path d="M9.9 4.24A9.1 9.1 0 0 1 12 4c6.5 0 10 8 10 8a17 17 0 0 1-2.16 3.19"/><path d="M6.61 6.61A17.6 17.6 0 0 0 2 12s3.5 8 10 8a9.7 9.7 0 0 0 5.39-1.61"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><path d="m2 2 20 20"/>',
		'filter': '<path d="M22 3H2l8 9.46V19l4 2v-8.54z"/>',
		'flask': '<path d="M9 3h6"/><path d="M10 3v6.5L4.6 18.4A1.75 1.75 0 0 0 6.1 21h11.8a1.75 1.75 0 0 0 1.5-2.6L14 9.5V3"/><path d="M7.5 15h9"/>',
		'folder': '<path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2z"/>',
		'github': '<path d="M9 19c-4.3 1.4-4.3-2.5-6-3m12 5v-3.5c0-1 .1-1.4-.5-2 2.8-.3 5.5-1.4 5.5-6a4.6 4.6 0 0 0-1.3-3.2 4.2 4.2 0 0 0-.1-3.2s-1.1-.3-3.5 1.3a12.3 12.3 0 0 0-6.2 0C6.5 2.8 5.4 3.1 5.4 3.1a4.2 4.2 0 0 0-.1 3.2A4.6 4.6 0 0 0 4 9.5c0 4.6 2.7 5.7 5.5 6-.6.6-.6 1.2-.5 2V21"/>',
		'globe': '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a14 14 0 0 1 0 18 14 14 0 0 1 0-18z"/>',
		'grip': '<circle cx="9" cy="6" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="18" r="1"/>',
		'hash': '<path d="M4 9h16M4 15h16M10 3 8 21M16 3l-2 18"/>',
		'image': '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.1-3.1a2 2 0 0 0-2.8 0L6 21"/>',
		'inbox': '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
		'info': '<circle cx="12" cy="12" r="9"/><path d="M12 16v-4M12 8h.01"/>',
		'key': '<circle cx="7.5" cy="15.5" r="4.5"/><path d="m10.7 12.3 9.8-9.8M17 6l3 3M14.5 8.5l2 2"/>',
		'keyboard': '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M6 9h.01M10 9h.01M14 9h.01M18 9h.01M6 13h.01M18 13h.01M9 16h6"/>',
		'layers': '<path d="m12 2 9 5-9 5-9-5z"/><path d="m3 12 9 5 9-5"/><path d="m3 17 9 5 9-5"/>',
		'link': '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
		'list': '<path d="M11 6h10M11 12h10M11 18h10"/><path d="m3 6 1.5 1.5L7 5"/><path d="m3 12 1.5 1.5L7 11"/><path d="m3 18 1.5 1.5L7 17"/>',
		'loader': '<path d="M21 12a9 9 0 1 1-6.22-8.56"/>',
		'lock': '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
		'map': '<path d="M9 4 3 6.5v13.5l6-2.5 6 2.5 6-2.5V4l-6 2.5z"/><path d="M9 4v13.5M15 6.5V20"/>',
		'menu': '<path d="M4 6h16M4 12h16M4 18h16"/>',
		'moon': '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
		'more': '<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>',
		'pause': '<rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/>',
		'play': '<path d="M7 4.5v15a1 1 0 0 0 1.5.86l12-7.5a1 1 0 0 0 0-1.72l-12-7.5A1 1 0 0 0 7 4.5z"/>',
		'plus': '<path d="M12 5v14M5 12h14"/>',
		'lifebuoy': '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3.5"/><path d="m5.6 5.6 3.9 3.9M14.5 14.5l3.9 3.9M18.4 5.6l-3.9 3.9M9.5 14.5l-3.9 3.9"/>',
		'list-checks': '<path d="M11 6h10M11 12h10M11 18h10"/><path d="m3 6 1.5 1.5L7 5M3 12l1.5 1.5L7 11M3 18l1.5 1.5L7 17"/>',
		'power': '<path d="M12 2v10"/><path d="M18.4 6.6a9 9 0 1 1-12.77.04"/>',
		'prompt': '<path d="M21 15a2 2 0 0 1-2 2H8l-5 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><path d="M8 8h8M8 12h5"/>',
		'pulse': '<path d="M3 12h4l2-5 4 10 2-5h6"/>',
		'refresh': '<path d="M21 12a9 9 0 0 1-15.5 6.2L3 16"/><path d="M3 12a9 9 0 0 1 15.5-6.2L21 8"/><path d="M21 3v5h-5M3 21v-5h5"/>',
		'review': '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="m9 15 2 2 4-4"/>',
		'rocket': '<path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/>',
		'save': '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8M7 3v5h8"/>',
		'search': '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
		'send': '<path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/>',
		'seo': '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/><path d="M8 11.5l2 2 4-4"/>',
		'server': '<rect x="2" y="3" width="20" height="8" rx="2"/><rect x="2" y="13" width="20" height="8" rx="2"/><path d="M6 7h.01M6 17h.01"/>',
		'settings': '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
		'shield': '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>',
		'sparkles': '<path d="M12 3l1.8 4.9L18.7 9.7l-4.9 1.8L12 16.4l-1.8-4.9L5.3 9.7l4.9-1.8z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/><path d="M5 2.5l.6 1.4L7 4.5l-1.4.6L5 6.5l-.6-1.4L3 4.5l1.4-.6z"/>',
		'stack': '<rect x="3" y="3" width="18" height="6" rx="1.5"/><rect x="3" y="15" width="18" height="6" rx="1.5"/><path d="M7 12h10"/>',
		'swords': '<path d="M14.5 17.5 3 6V3h3l11.5 11.5"/><path d="m13 19 6-6M16 16l4 4M19 21l2-2"/><path d="M14.5 6.5 18 3h3v3l-3.5 3.5"/><path d="m5 14 4 4M7 17l-3 3M3 19l2 2"/>',
		'target': '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/>',
		'terminal': '<path d="m4 17 6-6-6-6M12 19h8"/>',
		'trash': '<path d="M3 6h18"/><path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/>',
		'trending': '<path d="m22 7-8.5 8.5-5-5L2 17"/><path d="M16 7h6v6"/>',
		'type': '<path d="M4 7V4h16v3M9 20h6M12 4v16"/>',
		'undo': '<path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/>',
		'unlink': '<path d="m18.8 13.4 1.7-1.7a5 5 0 0 0-7.1-7.1l-1.7 1.7"/><path d="m5.2 10.6-1.7 1.7a5 5 0 0 0 7.1 7.1l1.7-1.7"/><path d="M8 2v3M2 8h3M16 22v-3M22 16h-3"/>',
		'upload': '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5"/><path d="M12 3v12"/>',
		'user': '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
		'wand': '<path d="m15 4-1-1M21 10l-1-1M18 3v2M22 7h-2"/><path d="M3 21 14.5 9.5"/><path d="m12.5 7.5 4 4"/>',
		'wrench': '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94z"/>',
		'x': '<path d="M18 6 6 18M6 6l12 12"/>',
		'check-circle': '<circle cx="12" cy="12" r="9"/><path d="m8.5 12 2.5 2.5 4.5-5"/>',
		'x-circle': '<circle cx="12" cy="12" r="9"/><path d="m15 9-6 6M9 9l6 6"/>',
		'zap': '<path d="M13 2 3 14h9l-1 8 10-12h-9z"/>',
	};
	var SVGNS = 'http://www.w3.org/2000/svg';
	/** Icon + label (for buttons, headings and chips). */
	SL.il = function ( name, label, size ) {
		return [ SL.icon( name, size || 16 ), h( 'span', { text: label } ) ];
	};
	/** Coloured priority dot (replaces coloured-circle emoji). */
	SL.dot = function ( level ) {
		return h( 'span', { class: 'sl-dot sl-dot--' + ( level || 'info' ), 'aria-hidden': 'true' } );
	};
	/** Yes / no state icon (replaces check-mark glyphs). */
	SL.yes = function ( ok, size ) {
		var el = SL.icon( ok ? 'check' : 'x', size || 16, ok ? 'is-good' : 'is-bad' );
		el.setAttribute( 'aria-hidden', 'false' );
		el.setAttribute( 'role', 'img' );
		el.setAttribute( 'aria-label', ok ? t( 'Yes' ) : t( 'No' ) );
		return el;
	};
	SL.icon = function ( name, size, cls ) {
		var svg = d.createElementNS( SVGNS, 'svg' );
		size = size || 18;
		svg.setAttribute( 'class', 'sl-ic' + ( cls ? ' ' + cls : '' ) );
		svg.setAttribute( 'data-icon', name );
		svg.setAttribute( 'width', size );
		svg.setAttribute( 'height', size );
		svg.setAttribute( 'viewBox', '0 0 24 24' );
		svg.setAttribute( 'fill', 'none' );
		svg.setAttribute( 'stroke', 'currentColor' );
		svg.setAttribute( 'stroke-width', '1.75' );
		svg.setAttribute( 'stroke-linecap', 'round' );
		svg.setAttribute( 'stroke-linejoin', 'round' );
		svg.setAttribute( 'aria-hidden', 'true' );
		svg.setAttribute( 'focusable', 'false' );
		svg.innerHTML = SL.ICONS[ name ] || SL.ICONS.info;
		return svg;
	};

	/* ------------------------------------------------------------------ Toasts */
	var toastBox;
	SL.toast = function ( msg, type ) {
		if ( ! toastBox ) {
			toastBox = h( 'div', { class: 'sl-toasts', role: 'status', 'aria-live': 'polite' } );
			d.body.appendChild( toastBox );
		}
		var el = h( 'div', { class: 'sl-toast sl-toast--' + ( type || 'info' ) }, msg );
		toastBox.appendChild( el );
		setTimeout( function () {
			el.classList.add( 'is-out' );
			setTimeout( function () {
				el.remove();
			}, 300 );
		}, type === 'error' ? 7000 : 3500 );
	};
	SL.fail = function ( e ) {
		SL.toast( ( e && e.message ) || t( 'Something went wrong.' ), 'error' );
	};

	/* ------------------------------------------------------------------ Modal */
	SL.modal = function ( opts ) {
		var prev = d.activeElement;
		var close;
		var overlay = h( 'div', { class: 'sl-modal-overlay sl-root' + ( C.rtl ? ' is-rtl' : '' ), role: 'presentation', dir: C.rtl ? 'rtl' : 'ltr' } );
		var box = h( 'div', { class: 'sl-modal' + ( opts.wide ? ' sl-modal--wide' : '' ), role: 'dialog', 'aria-modal': 'true', 'aria-label': opts.title || '' } );
		var body = h( 'div', { class: 'sl-modal__body' } );
		var foot = h( 'div', { class: 'sl-modal__foot' } );
		box.appendChild( h( 'div', { class: 'sl-modal__head' }, h( 'h2', { text: opts.title || '' } ), h( 'button', { class: 'sl-icon-btn', 'aria-label': t( 'Close' ), onClick: function () {
			close();
		} }, SL.icon( 'x', 18 ) ) ) );
		box.appendChild( body );
		box.appendChild( foot );
		overlay.appendChild( box );
		applyTheme( overlay );
		close = function () {
			overlay.remove();
			d.removeEventListener( 'keydown', onKey );
			if ( prev && prev.focus ) {
				prev.focus();
			}
			if ( opts.onClose ) {
				opts.onClose();
			}
		};
		function onKey( e ) {
			if ( e.key === 'Escape' ) {
				close();
			}
		}
		d.addEventListener( 'keydown', onKey );
		overlay.addEventListener( 'click', function ( e ) {
			if ( e.target === overlay ) {
				close();
			}
		} );
		d.body.appendChild( overlay );
		setTimeout( function () {
			var f = box.querySelector( 'button, input, textarea, select' );
			if ( f ) {
				f.focus();
			}
		}, 30 );
		return { body: body, foot: foot, close: close, box: box };
	};

	SL.confirm = function ( message, onYes, danger ) {
		var m = SL.modal( { title: t( 'Please confirm' ) } );
		m.body.appendChild( h( 'p', { text: message } ) );
		m.foot.appendChild( h( 'button', { class: 'sl-btn', onClick: m.close }, t( 'Cancel' ) ) );
		m.foot.appendChild( h( 'button', { class: 'sl-btn ' + ( danger ? 'sl-btn--danger' : 'sl-btn--primary' ), onClick: function () {
			m.close();
			onYes();
		} }, t( 'Confirm' ) ) );
	};

	/* ------------------------------------------------------------------ Theme */
	function applyTheme( el ) {
		var pref = 'auto';
		try {
			pref = w.localStorage.getItem( 'seolity-theme' ) || 'light';
		} catch ( e ) {}
		var dark = pref === 'dark' || ( pref === 'auto' && w.matchMedia && w.matchMedia( '(prefers-color-scheme: dark)' ).matches );
		el.setAttribute( 'data-theme', dark ? 'dark' : 'light' );
		// A custom white-label colour replaces the default blue (the old purple default is ignored).
		if ( C.brand && /^#[0-9a-f]{3,8}$/i.test( C.brand.color || '' ) && [ '#6d28d9', '#1d4ed8' ].indexOf( C.brand.color.toLowerCase() ) === -1 ) {
			el.style.setProperty( '--sl-brand', C.brand.color );
		}
	}
	SL.applyTheme = applyTheme;

	/* ------------------------------------------------------------------ Small components */
	SL.spinner = function ( label ) {
		return h( 'div', { class: 'sl-loading', role: 'status' }, h( 'span', { class: 'sl-spinner' } ), h( 'span', { text: label || t( 'Loading…' ) } ) );
	};
	SL.empty = function ( title, text, action ) {
		return h( 'div', { class: 'sl-empty' }, h( 'div', { class: 'sl-empty__icon', 'aria-hidden': 'true' }, SL.icon( 'inbox', 24 ) ), h( 'h3', { text: title } ), text ? h( 'p', { text: text } ) : null, action || null );
	};
	SL.badge = function ( text, kind ) {
		return h( 'span', { class: 'sl-badge sl-badge--' + ( kind || 'default' ) }, text );
	};
	SL.priorityBadge = function ( p ) {
		var map = { critical: t( 'Critical' ), high: t( 'High' ), medium: t( 'Medium' ), low: t( 'Low' ), info: t( 'Info' ) };
		return h( 'span', { class: 'sl-badge sl-badge--' + p }, SL.dot( p ), map[ p ] || p );
	};
	SL.scoreRing = function ( score, size, label ) {
		size = size || 64;
		var r = size / 2 - 5;
		var c = 2 * Math.PI * r;
		var v = score === null || score === undefined ? 0 : Math.max( 0, Math.min( 100, score ) );
		var color = score === null || score === undefined ? 'var(--sl-muted)' : v >= 80 ? 'var(--sl-good)' : v >= 55 ? 'var(--sl-warn)' : 'var(--sl-bad)';
		var ns = 'http://www.w3.org/2000/svg';
		var svg = d.createElementNS( ns, 'svg' );
		svg.setAttribute( 'width', size );
		svg.setAttribute( 'height', size );
		svg.setAttribute( 'viewBox', '0 0 ' + size + ' ' + size );
		svg.setAttribute( 'role', 'img' );
		svg.setAttribute( 'aria-label', ( label || t( 'Score' ) ) + ': ' + ( score === null || score === undefined ? '—' : score ) );
		var bg = d.createElementNS( ns, 'circle' );
		bg.setAttribute( 'cx', size / 2 );
		bg.setAttribute( 'cy', size / 2 );
		bg.setAttribute( 'r', r );
		bg.setAttribute( 'class', 'sl-ring-bg' );
		var fg = d.createElementNS( ns, 'circle' );
		fg.setAttribute( 'cx', size / 2 );
		fg.setAttribute( 'cy', size / 2 );
		fg.setAttribute( 'r', r );
		fg.setAttribute( 'stroke', color );
		fg.setAttribute( 'class', 'sl-ring-fg' );
		fg.setAttribute( 'stroke-dasharray', c );
		fg.setAttribute( 'stroke-dashoffset', c * ( 1 - v / 100 ) );
		fg.setAttribute( 'transform', 'rotate(-90 ' + size / 2 + ' ' + size / 2 + ')' );
		var tx = d.createElementNS( ns, 'text' );
		tx.setAttribute( 'x', '50%' );
		tx.setAttribute( 'y', '50%' );
		tx.setAttribute( 'dominant-baseline', 'central' );
		tx.setAttribute( 'text-anchor', 'middle' );
		tx.setAttribute( 'class', 'sl-ring-text' );
		tx.setAttribute( 'font-size', Math.round( size / 3.6 ) );
		tx.textContent = score === null || score === undefined ? '—' : SL.num( score, 0 );
		svg.appendChild( bg );
		svg.appendChild( fg );
		svg.appendChild( tx );
		return h( 'span', { class: 'sl-ring' }, svg );
	};
	SL.meter = function ( label, before, after ) {
		function bar( v, cls ) {
			return h( 'span', { class: 'sl-meter__bar ' + cls, style: { width: ( v === null || v === undefined ? 0 : v ) + '%' } } );
		}
		return h( 'div', { class: 'sl-meter' },
			h( 'div', { class: 'sl-meter__label' }, h( 'span', { text: label } ), h( 'span', { class: 'sl-meter__vals' }, ( before === null || before === undefined ? '—' : SL.num( before, 0 ) ) + ( after !== undefined ? ' → ' + ( after === null ? '—' : SL.num( after, 0 ) ) : '' ) ) ),
			h( 'div', { class: 'sl-meter__track' }, bar( before, 'is-before' ), after !== undefined ? bar( after, 'is-after' ) : null )
		);
	};
	SL.chips = function ( items, kind ) {
		return h( 'div', { class: 'sl-chips' }, ( items || [] ).filter( Boolean ).map( function ( x ) {
			return h( 'span', { class: 'sl-chip' + ( kind ? ' sl-chip--' + kind : '' ) }, x );
		} ) );
	};
	SL.tabs = function ( tabs, onChange ) {
		var wrap = h( 'div', { class: 'sl-tabs', role: 'tablist' } );
		var panel = h( 'div', { class: 'sl-tabpanel', role: 'tabpanel' } );
		function select( i ) {
			Array.prototype.forEach.call( wrap.children, function ( b, j ) {
				b.setAttribute( 'aria-selected', i === j ? 'true' : 'false' );
				b.classList.toggle( 'is-active', i === j );
			} );
			SL.clear( panel );
			var c = tabs[ i ].render();
			SL.append( panel, c );
			if ( onChange ) {
				onChange( i );
			}
		}
		tabs.forEach( function ( tab, i ) {
			wrap.appendChild( h( 'button', { class: 'sl-tab', role: 'tab', type: 'button', onClick: function () {
				select( i );
			} }, tab.label ) );
		} );
		setTimeout( function () {
			select( 0 );
		}, 0 );
		return h( 'div', null, wrap, panel );
	};
	SL.iframePreview = function ( html ) {
		var css = 'body{font-family:Vazirmatn,Tahoma,system-ui,sans-serif;line-height:1.8;padding:12px;color:#1f2937;' + ( C.rtl ? 'direction:rtl;' : '' ) + '}img{max-width:100%}table{border-collapse:collapse}td,th{border:1px solid #ddd;padding:4px 8px}';
		return h( 'iframe', { class: 'sl-preview', sandbox: '', title: t( 'Preview' ), srcdoc: '<!doctype html><meta charset="utf-8"><style>' + css + '</style>' + ( html || '' ) } );
	};

	/* ------------------------------------------------------------------ Safe mini-markdown */
	SL.markdown = function ( md ) {
		var root = h( 'div', { class: 'sl-md' } );
		var lines = String( md || '' ).split( /\n/ );
		var list = null;
		function inline( text, parent ) {
			var re = /(\*\*[^*]+\*\*|`[^`]+`|\[[^\]]+\]\((https?:\/\/[^\s)]+|#\/[^\s)]*)\))/g;
			var last = 0;
			var m;
			while ( ( m = re.exec( text ) ) ) {
				parent.appendChild( d.createTextNode( text.slice( last, m.index ) ) );
				var tok = m[ 0 ];
				if ( tok.indexOf( '**' ) === 0 ) {
					parent.appendChild( h( 'strong', { text: tok.slice( 2, -2 ) } ) );
				} else if ( tok.indexOf( '`' ) === 0 ) {
					parent.appendChild( h( 'code', { text: tok.slice( 1, -1 ) } ) );
				} else {
					var lm = /\[([^\]]+)\]\(([^)]+)\)/.exec( tok );
					parent.appendChild( h( 'a', { href: lm[ 2 ], target: lm[ 2 ].indexOf( '#' ) === 0 ? null : '_blank', rel: 'noopener noreferrer', text: lm[ 1 ] } ) );
				}
				last = m.index + tok.length;
			}
			parent.appendChild( d.createTextNode( text.slice( last ) ) );
			return parent;
		}
		lines.forEach( function ( line ) {
			var lm = /^\s*(?:[-*]|\d+[.)])\s+(.*)$/.exec( line );
			if ( lm ) {
				if ( ! list ) {
					list = h( /^\s*\d/.test( line ) ? 'ol' : 'ul' );
					root.appendChild( list );
				}
				list.appendChild( inline( lm[ 1 ], h( 'li' ) ) );
				return;
			}
			list = null;
			var hm = /^(#{1,4})\s+(.*)$/.exec( line );
			if ( hm ) {
				root.appendChild( inline( hm[ 2 ], h( 'h' + Math.min( 6, hm[ 1 ].length + 2 ) ) ) );
			} else if ( line.trim() ) {
				root.appendChild( inline( line, h( 'p' ) ) );
			}
		} );
		return root;
	};

	/* ------------------------------------------------------------------ Diff (Myers, word level) */
	SL.tokens = function ( text ) {
		return String( text || '' ).match( /\s+|[^\s]+/g ) || [];
	};
	SL.stripHtml = function ( html ) {
		var doc = new DOMParser().parseFromString( '<body>' + ( html || '' ).replace( /<\/(p|h[1-6]|li|div|tr)>/gi, '\n$&' ) + '</body>', 'text/html' );
		return ( doc.body.textContent || '' ).replace( /\n{3,}/g, '\n\n' ).trim();
	};
	function myers( a, b, cap ) {
		var n = a.length;
		var m = b.length;
		var v = { 1: 0 };
		var trace = [];
		var found = false;
		for ( var D = 0; D <= n + m && ! found; D++ ) {
			if ( D > cap ) {
				return null;
			}
			trace.push( Object.assign( {}, v ) );
			for ( var k = -D; k <= D; k += 2 ) {
				var x = k === -D || ( k !== D && v[ k - 1 ] < v[ k + 1 ] ) ? v[ k + 1 ] : v[ k - 1 ] + 1;
				var y = x - k;
				while ( x < n && y < m && a[ x ] === b[ y ] ) {
					x++;
					y++;
				}
				v[ k ] = x;
				if ( x >= n && y >= m ) {
					found = true;
					break;
				}
			}
		}
		var ops = [];
		var x2 = n;
		var y2 = m;
		for ( var D2 = trace.length - 1; D2 >= 0; D2-- ) {
			var vv = trace[ D2 ];
			var k2 = x2 - y2;
			var prevK = k2 === -D2 || ( k2 !== D2 && vv[ k2 - 1 ] < vv[ k2 + 1 ] ) ? k2 + 1 : k2 - 1;
			var px = vv[ prevK ] || 0;
			var py = px - prevK;
			while ( x2 > px && y2 > py ) {
				ops.push( { op: 'eq', text: a[ x2 - 1 ] } );
				x2--;
				y2--;
			}
			if ( D2 > 0 ) {
				if ( x2 === px ) {
					ops.push( { op: 'ins', text: b[ y2 - 1 ] } );
				} else {
					ops.push( { op: 'del', text: a[ x2 - 1 ] } );
				}
			}
			x2 = px;
			y2 = py;
		}
		return ops.reverse();
	}
	SL.diff = function ( a, b ) {
		// Word-level first; for heavy rewrites fall back to line-level to bound memory.
		var ops = myers( SL.tokens( a ), SL.tokens( b ), 1500 );
		if ( ! ops ) {
			var la = String( a || '' ).split( /(\n)/ );
			var lb = String( b || '' ).split( /(\n)/ );
			ops = myers( la, lb, 3000 ) || [ { op: 'del', text: a }, { op: 'ins', text: b } ];
		}
		var merged = [];
		ops.forEach( function ( o ) {
			var last = merged[ merged.length - 1 ];
			if ( last && last.op === o.op ) {
				last.text += o.text;
			} else {
				merged.push( { op: o.op, text: o.text } );
			}
		} );
		return merged;
	};
	SL.diffView = function ( before, after, isHtml ) {
		var a = isHtml ? SL.stripHtml( before ) : String( before || '' );
		var b = isHtml ? SL.stripHtml( after ) : String( after || '' );
		var box = h( 'div', { class: 'sl-diff', dir: 'auto' } );
		SL.diff( a, b ).forEach( function ( o ) {
			box.appendChild( o.op === 'eq' ? d.createTextNode( o.text ) : h( o.op === 'ins' ? 'ins' : 'del', { text: o.text } ) );
		} );
		return box;
	};

	/* ------------------------------------------------------------------ Change preview (Safe Mode) */
	var FIELD_LABELS = {
		post_content: 'Content', post_excerpt: 'Short description', post_title: 'Title', post_name: 'URL slug', meta_title: 'SEO title',
		meta_description: 'Meta description', focus_keyword: 'Focus keyword', faq_schema: 'FAQ', alt: 'Image ALT', image_title: 'Image title',
		caption: 'Caption', term_description: 'Category description', term_name: 'Name', user_description: 'Author bio', noindex: 'Noindex',
		nofollow: 'Nofollow', canonical: 'Canonical URL', product_tags: 'Product tags', robots_txt: 'robots.txt',
	};
	SL.fieldLabel = function ( f ) {
		return t( FIELD_LABELS[ f ] || f );
	};
	SL.preview = function ( changeOrId, onDone ) {
		var load = typeof changeOrId === 'object' ? Promise.resolve( changeOrId ) : SL.api( 'changes/' + changeOrId );
		var m = SL.modal( { title: t( 'Preview change' ), wide: true } );
		m.body.appendChild( SL.spinner() );
		load.then( function ( c ) {
			SL.clear( m.body );
			var isHtml = /content|description|excerpt/.test( c.field ) && /<[a-z]/i.test( ( c.after_value || '' ) + ( c.before_value || '' ) );
			var editor = h( 'textarea', { class: 'sl-input sl-textarea', rows: 10, dir: 'auto' } );
			editor.value = c.after_value || '';
			m.body.appendChild( h( 'div', { class: 'sl-row sl-row--between' },
				h( 'div', null, h( 'strong', { text: SL.fieldLabel( c.field ) } ), ' · ', h( 'span', { class: 'sl-muted', text: c.url ? SL.path( c.url ) : '' } ) ),
				h( 'div', null, SL.badge( t( 'Risk' ) + ': ' + t( c.risk ), c.risk === 'high' ? 'bad' : c.risk === 'medium' ? 'warn' : 'good' ), ' ', SL.badge( t( c.status ) ) )
			) );
			if ( c.reason ) {
				m.body.appendChild( h( 'p', { class: 'sl-reason', text: c.reason } ) );
			}
			m.body.appendChild( SL.tabs( [
				{ label: t( 'Before / After' ), render: function () {
					return h( 'div', { class: 'sl-grid-2' },
						h( 'div', { class: 'sl-panel' }, h( 'div', { class: 'sl-panel__title', text: t( 'BEFORE' ) } ), isHtml ? SL.iframePreview( c.before_value ) : h( 'div', { class: 'sl-pre', dir: 'auto', text: c.before_value || '—' } ) ),
						h( 'div', { class: 'sl-panel sl-panel--after' }, h( 'div', { class: 'sl-panel__title', text: t( 'AFTER' ) } ), isHtml ? SL.iframePreview( editor.value ) : h( 'div', { class: 'sl-pre', dir: 'auto', text: editor.value || '—' } ) )
					);
				} },
				{ label: t( 'Compare' ), render: function () {
					return SL.diffView( c.before_value, editor.value, isHtml );
				} },
				{ label: t( 'Edit' ), render: function () {
					return editor;
				} },
			] ) );
			if ( c.score_before !== null && c.score_after !== null && c.score_before !== undefined ) {
				m.body.appendChild( h( 'div', { class: 'sl-row' }, t( 'SEO score' ), ' ', SL.scoreRing( +c.score_before, 44 ), ' → ', SL.scoreRing( +c.score_after, 44 ) ) );
			}
			if ( c.ai_model ) {
				m.body.appendChild( h( 'p', { class: 'sl-muted sl-small', text: t( 'Generated by' ) + ' ' + c.ai_model + ' · ' + ( c.prompt_version || '' ) } ) );
			}
			var busy = function ( on ) {
				Array.prototype.forEach.call( m.foot.querySelectorAll( 'button' ), function ( b ) {
					b.disabled = on;
				} );
			};
			if ( c.status === 'pending' ) {
				m.foot.appendChild( h( 'button', { class: 'sl-btn', onClick: function () {
					busy( true );
					SL.api( 'changes/' + c.id + '/reject', { method: 'POST' } ).then( function () {
						SL.toast( t( 'Change rejected.' ) );
						m.close();
						if ( onDone ) {
							onDone( 'rejected' );
						}
					} ).catch( function ( e ) {
						busy( false );
						SL.fail( e );
					} );
				} }, t( 'Reject' ) ) );
				m.foot.appendChild( h( 'button', { class: 'sl-btn sl-btn--primary', onClick: function () {
					busy( true );
					var edited = editor.value !== c.after_value ? editor.value : null;
					SL.api( 'changes/' + c.id + '/apply', { method: 'POST', body: edited === null ? {} : { value: edited } } ).then( function () {
						SL.toast( t( 'Change applied. You can undo it anytime from version history.' ), 'success' );
						m.close();
						if ( onDone ) {
							onDone( 'applied' );
						}
					} ).catch( function ( e ) {
						busy( false );
						SL.fail( e );
					} );
				} }, t( 'Apply' ) ) );
			} else if ( c.status === 'applied' ) {
				m.foot.appendChild( h( 'button', { class: 'sl-btn sl-btn--danger', onClick: function () {
					busy( true );
					SL.api( 'changes/' + c.id + '/revert', { method: 'POST' } ).then( function () {
						SL.toast( t( 'Change reverted.' ), 'success' );
						m.close();
						if ( onDone ) {
							onDone( 'reverted' );
						}
					} ).catch( function ( e ) {
						busy( false );
						SL.fail( e );
					} );
				} }, t( 'Undo' ) ) );
			}
		} ).catch( function ( e ) {
			m.close();
			SL.fail( e );
		} );
	};

	SL.history = function ( type, id ) {
		var m = SL.modal( { title: t( 'Version history' ), wide: true } );
		m.body.appendChild( SL.spinner() );
		SL.api( 'history', { query: { object_type: type, object_id: id } } ).then( function ( rows ) {
			SL.clear( m.body );
			if ( ! rows.length ) {
				m.body.appendChild( SL.empty( t( 'No changes yet' ), t( 'Every change SEOlity makes is versioned here and can be restored.' ) ) );
				return;
			}
			var tbl = h( 'table', { class: 'sl-table' }, h( 'thead', null, h( 'tr', null, [ t( 'Version' ), t( 'Field' ), t( 'Status' ), t( 'Source' ), t( 'Date' ), '' ].map( function ( x ) {
				return h( 'th', { text: x } );
			} ) ) ) );
			var tb = h( 'tbody' );
			rows.forEach( function ( r ) {
				tb.appendChild( h( 'tr', null,
					h( 'td', { text: r.status === 'applied' || r.status === 'reverted' ? 'v' + r.version : '—' } ),
					h( 'td', { text: SL.fieldLabel( r.field ) } ),
					h( 'td', null, SL.badge( t( r.status ), r.status === 'applied' ? 'good' : r.status === 'pending' ? 'warn' : 'default' ) ),
					h( 'td', { text: t( r.source ) } ),
					h( 'td', { text: SL.date( r.applied_at || r.created_at ) } ),
					h( 'td', null,
						h( 'button', { class: 'sl-btn sl-btn--sm', onClick: function () {
							SL.preview( r.id, function () {
								m.close();
							} );
						} }, t( 'View' ) ),
						r.status === 'applied' || r.status === 'reverted' ? h( 'button', { class: 'sl-btn sl-btn--sm', onClick: function () {
							SL.confirm( t( 'Restore this version as the current value?' ), function () {
								SL.api( 'changes/' + r.id + '/restore', { method: 'POST' } ).then( function () {
									SL.toast( t( 'Version restored.' ), 'success' );
									m.close();
								} ).catch( SL.fail );
							} );
						} }, t( 'Restore' ) ) : null
					)
				) );
			} );
			tbl.appendChild( tb );
			m.body.appendChild( tbl );
		} ).catch( SL.fail );
	};

	/* ------------------------------------------------------------------ Why? */
	SL.why = function ( title, data ) {
		var m = SL.modal( { title: t( 'Why?' ) + ' — ' + title, wide: true } );
		function section( label, content ) {
			return h( 'div', { class: 'sl-why__sec' }, h( 'h4', { text: label } ), content );
		}
		var p = data.then ? data : Promise.resolve( data );
		m.body.appendChild( SL.spinner() );
		p.then( function ( x ) {
			SL.clear( m.body );
			m.body.appendChild( section( t( 'What did SEOlity find?' ), h( 'p', { text: x.found || '' } ) ) );
			m.body.appendChild( section( t( 'Why is this suggested?' ), h( 'p', { text: x.why || '' } ) ) );
			m.body.appendChild( section( t( 'Expected result' ), h( 'p', { text: x.expected || '' } ) ) );
			m.body.appendChild( section( t( 'Confidence' ), h( 'div', null, SL.meter( t( 'Confidence' ), x.confidence ) ) ) );
			if ( x.scores ) {
				m.body.appendChild( section( t( 'Scores' ), SL.chips( [ t( 'Impact' ) + ': ' + x.scores.impact, t( 'Effort' ) + ': ' + x.scores.effort, t( 'Confidence' ) + ': ' + x.scores.confidence ] ) ) );
			}
			if ( x.history && x.history.length ) {
				m.body.appendChild( section( t( 'What worked on this site before' ), h( 'ul', null, x.history.map( function ( i ) {
					return h( 'li', { text: i.text } );
				} ) ) ) );
			}
			m.body.appendChild( section( t( 'Data used' ), h( 'pre', { class: 'sl-json', dir: 'ltr', text: JSON.stringify( x.data || {}, null, 2 ) } ) ) );
		} ).catch( function ( e ) {
			m.close();
			SL.fail( e );
		} );
	};

	/* ------------------------------------------------------------------ AI Optimizer modal */
	SL.optimizer = function ( opts ) {
		// opts: object_type, object_id, field, getContent(), setContent(html) [optional, editor mode], title, focus_keyword
		var m = SL.modal( { title: t( 'Optimize with SEOlity AI' ), wide: true } );
		var kw = h( 'input', { class: 'sl-input', type: 'text', placeholder: t( 'Focus keyword (optional — SEOlity can pick it from Search Console)' ), value: opts.focus_keyword || '' } );
		var goal = h( 'input', { class: 'sl-input', type: 'text', placeholder: t( 'Goal (optional), e.g. rank for “buy espresso machine”, improve conversions…' ) } );
		var start = h( 'button', { class: 'sl-btn sl-btn--primary sl-btn--lg', onClick: run }, SL.il( 'sparkles', t( 'Analyze & optimize' ), 18 ) );
		m.body.appendChild( h( 'div', { class: 'sl-stack' }, h( 'p', { class: 'sl-muted', text: t( 'SEOlity analyzes your text, the real queries you rank for and SEO best practices, then proposes an improved version. Nothing changes until you apply it.' ) } ), kw, goal, start ) );

		var task = null;
		function run() {
			var content = opts.getContent ? opts.getContent() : null;
			var docTitle = opts.title ? opts.title() : '';
			m.close(); // The work continues in a small card; the page stays usable.
			task = SL.task( {
				title: t( 'Optimizing with SEOlity AI' ),
				subject: ( opts.label || ( opts.field === 'custom' ? t( 'Selected text' ) : SL.fieldLabel( opts.field ) ) ) + ( docTitle ? ' — ' + docTitle : '' ),
				steps: [ t( 'Analyzing current content…' ), t( 'Reading Search Console queries…' ), t( 'Consulting the SEO knowledge base…' ), t( 'Writing the optimized version…' ), t( 'Scoring before vs. after…' ) ],
			} );
			SL.api( 'optimize', { body: { object_type: opts.object_type, object_id: opts.object_id || 0, field: opts.field, content: content, title: docTitle, focus_keyword: kw.value, goal: goal.value } } ).then( function ( res ) {
				task.done( {
					summary: t( 'SEO score %1$s → %2$s', res.before.score, res.after.score ),
					lines: ( res.ai.changes || [] ).slice( 0, 6 ).map( function ( c ) {
						return c.area + ': ' + c.description;
					} ),
					action: { label: t( 'Review & apply' ), fn: function () {
						show( res );
					} },
				} );
			} ).catch( function ( e ) {
				var settings = e.data && e.data.code === 'seolity_ai_not_configured' ? { label: t( 'Open settings' ), fn: function () {
					location.href = ( C.adminUrl || '' ) + 'admin.php?page=seolity-settings';
				} } : null;
				task.fail( e.message, settings );
			} );
		}

		function show( res ) {
			m = SL.modal( { title: t( 'Optimize with SEOlity AI' ), wide: true } );
			var ai = res.ai;
			var editor = h( 'textarea', { class: 'sl-input sl-textarea', rows: 16, dir: 'auto' } );
			editor.value = res.optimized;
			var b = res.before;
			var a = res.after;
			var metrics = [
				[ t( 'Keyword coverage' ), 'keyword_coverage' ], [ t( 'Search intent match' ), 'intent_match' ], [ t( 'Readability' ), 'readability' ],
				[ t( 'Semantic coverage' ), 'semantic_coverage' ], [ t( 'Entity coverage' ), 'entity_coverage' ], [ t( 'Content depth' ), 'content_depth' ],
			];
			var side = h( 'aside', { class: 'sl-opt__side' },
				h( 'div', { class: 'sl-opt__scores' },
					h( 'div', null, h( 'div', { class: 'sl-muted sl-small', text: t( 'SEO Score Before' ) } ), SL.scoreRing( b.score, 72 ) ),
					h( 'div', { class: 'sl-opt__arrow', 'aria-hidden': 'true' }, SL.icon( C.rtl ? 'arrow-left' : 'arrow-right', 22 ) ),
					h( 'div', null, h( 'div', { class: 'sl-muted sl-small', text: t( 'SEO Score After' ) } ), SL.scoreRing( a.score, 72 ) )
				),
				metrics.map( function ( x ) {
					return SL.meter( x[ 0 ], b.metrics[ x[ 1 ] ], a.metrics[ x[ 1 ] ] );
				} ),
				h( 'h4', { text: t( 'Keywords' ) } ),
				h( 'div', { class: 'sl-small' }, h( 'strong', { text: t( 'Focus' ) + ': ' } ), ai.focus_keyword ),
				SL.chips( ai.secondary_keywords, 'kw' ),
				h( 'h4', { text: t( 'Semantic terms' ) } ), SL.chips( ai.semantic_terms ),
				h( 'h4', { text: t( 'Entities' ) } ), SL.chips( ai.entities, 'entity' ),
				h( 'div', { class: 'sl-small sl-muted' }, t( 'Intent' ) + ': ' + t( ai.search_intent ) )
			);
			var changes = h( 'div', { class: 'sl-opt__changes' }, h( 'h4', { text: t( 'What changed and why' ) } ), h( 'p', { text: ai.summary } ), h( 'ul', null, ( ai.changes || [] ).map( function ( c ) {
				return h( 'li', null, h( 'strong', { text: c.area + ': ' } ), c.description, h( 'div', { class: 'sl-muted sl-small', text: c.reason } ) );
			} ) ), ai.information_gain && ai.information_gain.length ? h( 'div', null, h( 'h4', { text: t( 'Information gain' ) } ), SL.chips( ai.information_gain ) ) : null );
			var warn = ( res.warnings || [] ).length ? h( 'div', { class: 'sl-alert sl-alert--warn' }, h( 'strong', { text: t( 'Please review:' ) } ), h( 'ul', null, res.warnings.map( function ( x ) {
				return h( 'li', { text: x } );
			} ) ) ) : null;
			var metaBox = null;
			var metaChecks = {};
			if ( res.changes && ( res.changes.meta_title || res.changes.meta_description ) ) {
				metaBox = h( 'div', { class: 'sl-panel' }, h( 'div', { class: 'sl-panel__title', text: t( 'Also suggested' ) } ) );
				[ [ 'meta_title', ai.suggested_title, t( 'SEO title' ) ], [ 'meta_description', ai.suggested_meta_description, t( 'Meta description' ) ], [ 'focus_keyword', ai.focus_keyword, t( 'Focus keyword' ) ] ].forEach( function ( x ) {
					if ( res.changes[ x[ 0 ] ] ) {
						var cb = h( 'input', { type: 'checkbox', checked: true } );
						metaChecks[ x[ 0 ] ] = cb;
						metaBox.appendChild( h( 'label', { class: 'sl-check' }, cb, h( 'span', null, h( 'strong', { text: x[ 2 ] + ': ' } ), x[ 1 ] ) ) );
					}
				} );
			}
			var main = h( 'div', { class: 'sl-opt__main' }, warn, SL.tabs( [
				{ label: t( 'AI Suggested' ), render: function () {
					return SL.iframePreview( editor.value );
				} },
				{ label: t( 'Original' ), render: function () {
					return SL.iframePreview( res.original );
				} },
				{ label: t( 'Compare' ), render: function () {
					return SL.diffView( res.original, editor.value, true );
				} },
				{ label: t( 'Edit HTML' ), render: function () {
					return editor;
				} },
			] ), changes, metaBox );
			m.body.appendChild( h( 'div', { class: 'sl-opt' }, main, side ) );
			m.body.appendChild( h( 'p', { class: 'sl-muted sl-small', text: t( 'Model' ) + ': ' + res.meta.model + ' · ' + res.meta.prompt_version + ( res.meta.knowledge_refs && res.meta.knowledge_refs.length ? ' · ' + t( 'Knowledge' ) + ': ' + res.meta.knowledge_refs.join( ', ' ) : '' ) } ) );

			var cid = res.changes && res.changes.content;
			function applyMeta() {
				return Promise.all( Object.keys( metaChecks ).filter( function ( k ) {
					return metaChecks[ k ].checked;
				} ).map( function ( k ) {
					return SL.api( 'changes/' + res.changes[ k ] + '/apply', { method: 'POST' } );
				} ) );
			}
			m.foot.appendChild( h( 'button', { class: 'sl-btn', onClick: function () {
				var p = cid ? SL.api( 'changes/' + cid + '/reject', { method: 'POST' } ) : Promise.resolve();
				p.then( function () {
					SL.toast( t( 'Suggestion rejected.' ) );
					if ( task ) {
						task.note( t( 'Suggestion rejected' ), false );
					}
					m.close();
				} ).catch( SL.fail );
			} }, t( 'Reject' ) ) );
			m.foot.appendChild( h( 'button', { class: 'sl-btn', onClick: function () {
				if ( navigator.clipboard ) {
					navigator.clipboard.writeText( editor.value ).then( function () {
						SL.toast( t( 'Copied.' ) );
					} );
				}
			} }, t( 'Copy' ) ) );
			m.foot.appendChild( h( 'button', { class: 'sl-btn sl-btn--primary', onClick: function ( e ) {
				e.target.disabled = true;
				var value = editor.value;
				var done = function () {
					SL.toast( t( 'Applied. Undo anytime from version history.' ), 'success' );
					if ( task ) {
						task.note( opts.setContent ? t( 'Applied to the editor — save the page to keep it' ) : t( 'Applied (undo anytime from version history)' ) );
					}
					m.close();
					if ( opts.onApplied ) {
						opts.onApplied( value );
					}
				};
				if ( opts.setContent ) {
					// Editor mode: put the text into the editor; it is versioned when the post is saved.
					opts.setContent( value );
					var mark = cid ? SL.api( 'changes/' + cid + '/editor-applied', { method: 'POST' } ) : Promise.resolve();
					mark.then( applyMeta ).then( done ).catch( function ( err ) {
						e.target.disabled = false;
						SL.fail( err );
					} );
				} else if ( cid ) {
					SL.api( 'changes/' + cid + '/apply', { method: 'POST', body: value !== res.optimized ? { value: value } : {} } ).then( applyMeta ).then( done ).catch( function ( err ) {
						e.target.disabled = false;
						SL.fail( err );
					} );
				}
			} }, opts.setContent ? t( 'Apply to editor' ) : t( 'Apply changes' ) ) );
		}
	};


	/* ------------------------------------------------------------------ Task dock
	 * Small, non-blocking cards for AI work (optimize a text, a product, titles…). While the
	 * AI works you keep editing; the card shows what is being edited and the progress. When it
	 * finishes, the card stays until you close it, with what was done and a button to open the
	 * full report. Finished cards survive a page reload (e.g. after saving a post). */
	var dockEl = null;
	var DOCK_KEY = 'seolity_dock_v1';
	function dockBox() {
		if ( ! dockEl || ! d.body.contains( dockEl ) ) {
			dockEl = h( 'div', { class: 'sl-dock sl-root' + ( C.rtl ? ' is-rtl' : '' ), dir: C.rtl ? 'rtl' : 'ltr', role: 'region', 'aria-label': t( 'SEOlity tasks' ), 'aria-live': 'polite' } );
			applyTheme( dockEl );
			d.body.appendChild( dockEl );
		}
		return dockEl;
	}
	function dockStore( fn ) {
		try {
			var list = JSON.parse( w.sessionStorage.getItem( DOCK_KEY ) || '[]' );
			list = fn( Array.isArray( list ) ? list : [] ) || list;
			w.sessionStorage.setItem( DOCK_KEY, JSON.stringify( list.slice( -6 ) ) );
		} catch ( e ) {}
	}
	function clock( ms ) {
		var s = Math.max( 0, Math.round( ms / 1000 ) );
		return Math.floor( s / 60 ) + ':' + ( '0' + ( s % 60 ) ).slice( -2 );
	}

	/**
	 * Start a task card.
	 * opts: { title, subject (what is being edited), icon, steps: [texts shown while running] }
	 * Returns { update( text ), done( { summary, lines: [], action: { label, fn } } ), fail( message, action ), note( text ) }.
	 */
	SL.task = function ( opts ) {
		opts = opts || {};
		var id = 't' + Date.now() + Math.floor( Math.random() * 1000 );
		var started = Date.now();
		var finished = false;
		var stepIdx = 0;
		var ic = h( 'span', { class: 'sl-dock__ic' }, h( 'span', { class: 'sl-spinner sl-spinner--sm', 'aria-hidden': 'true' } ) );
		var detail = h( 'span', { class: 'sl-dock__detail', text: ( opts.steps && opts.steps[ 0 ] ) || t( 'Working…' ) } );
		var time = h( 'span', { class: 'sl-dock__time', text: '0:00' } );
		var body = h( 'div', { class: 'sl-dock__body' } );
		var close = h( 'button', { type: 'button', class: 'sl-dock__close', 'aria-label': t( 'Close' ), title: t( 'Close' ), hidden: true, onClick: function () {
			card.remove();
			dockStore( function ( l ) {
				return l.filter( function ( x ) {
					return x.id !== id;
				} );
			} );
		} }, SL.icon( 'x', 15 ) );
		var card = h( 'div', { class: 'sl-dock__card is-running', 'data-task': id },
			h( 'div', { class: 'sl-dock__head' }, ic, h( 'div', { class: 'sl-dock__titles' }, h( 'b', { text: opts.title || t( 'SEOlity AI' ) } ), opts.subject ? h( 'span', { class: 'sl-dock__subject', dir: 'auto', text: opts.subject } ) : null, detail ), time, close ),
			body
		);
		dockBox().appendChild( card );
		var timer = setInterval( function () {
			if ( finished ) {
				return;
			}
			time.textContent = clock( Date.now() - started );
			if ( opts.steps && opts.steps.length > 1 && ( Date.now() - started ) / 4000 > stepIdx + 1 && stepIdx < opts.steps.length - 1 ) {
				stepIdx++;
				detail.textContent = opts.steps[ stepIdx ];
			}
		}, 1000 );
		function finish( kind, icon ) {
			finished = true;
			clearInterval( timer );
			card.classList.remove( 'is-running' );
			card.classList.add( 'is-' + kind );
			SL.clear( ic );
			ic.appendChild( SL.icon( icon, 18 ) );
			close.hidden = false;
		}
		function render( summary, lines, action ) {
			SL.clear( body );
			if ( summary ) {
				body.appendChild( h( 'p', { class: 'sl-dock__summary', text: summary } ) );
			}
			if ( lines && lines.length ) {
				body.appendChild( h( 'ul', { class: 'sl-dock__lines' }, lines.map( function ( l ) {
					return h( 'li', null, SL.icon( 'check', 13 ), h( 'span', { dir: 'auto', text: l } ) );
				} ) ) );
			}
			if ( action && action.fn ) {
				body.appendChild( h( 'button', { type: 'button', class: 'sl-btn sl-btn--sm sl-btn--primary', onClick: action.fn }, action.label ) );
			}
		}
		function persist( kind, summary, lines ) {
			dockStore( function ( l ) {
				return l.filter( function ( x ) {
					return x.id !== id;
				} ).concat( [ { id: id, kind: kind, title: opts.title, subject: opts.subject || '', detail: detail.textContent, summary: summary || '', lines: lines || [], time: time.textContent } ] );
			} );
		}
		var api = {
			update: function ( text ) {
				detail.textContent = text;
			},
			done: function ( r ) {
				r = r || {};
				finish( 'done', 'check-circle' );
				detail.textContent = t( 'Finished — review the result' );
				render( r.summary, r.lines, r.action );
				persist( 'done', r.summary, r.lines );
				api.lines = r.lines || [];
				api.summary = r.summary || '';
			},
			fail: function ( message, action ) {
				finish( 'failed', 'x-circle' );
				detail.textContent = t( 'Failed' );
				render( message, [], action );
				persist( 'failed', message, [] );
			},
			/** After the user acted on the result (applied, rejected…). */
			note: function ( text, ok ) {
				detail.textContent = text;
				card.classList.toggle( 'is-applied', ok !== false );
				SL.clear( body );
				render( api.summary, api.lines );
				persist( 'done', api.summary + ( api.summary ? ' · ' : '' ) + text, api.lines );
			},
		};
		return api;
	};
	/** Finished cards from before a reload (summary only). */
	function restoreDock() {
		var list = [];
		try {
			list = JSON.parse( w.sessionStorage.getItem( DOCK_KEY ) || '[]' );
		} catch ( e ) {}
		( Array.isArray( list ) ? list : [] ).forEach( function ( x ) {
			var close = h( 'button', { type: 'button', class: 'sl-dock__close', 'aria-label': t( 'Close' ), title: t( 'Close' ), onClick: function () {
				card.remove();
				dockStore( function ( l ) {
					return l.filter( function ( y ) {
						return y.id !== x.id;
					} );
				} );
			} }, SL.icon( 'x', 15 ) );
			var card = h( 'div', { class: 'sl-dock__card is-' + ( x.kind === 'failed' ? 'failed' : 'done' ) },
				h( 'div', { class: 'sl-dock__head' }, h( 'span', { class: 'sl-dock__ic' }, SL.icon( x.kind === 'failed' ? 'x-circle' : 'check-circle', 18 ) ), h( 'div', { class: 'sl-dock__titles' }, h( 'b', { text: x.title || '' } ), x.subject ? h( 'span', { class: 'sl-dock__subject', dir: 'auto', text: x.subject } ) : null, h( 'span', { class: 'sl-dock__detail', text: x.detail || '' } ) ), h( 'span', { class: 'sl-dock__time', text: x.time || '' } ), close ),
				h( 'div', { class: 'sl-dock__body' }, x.summary ? h( 'p', { class: 'sl-dock__summary', text: x.summary } ) : null, x.lines && x.lines.length ? h( 'ul', { class: 'sl-dock__lines' }, x.lines.map( function ( l ) {
					return h( 'li', null, SL.icon( 'check', 13 ), h( 'span', { dir: 'auto', text: l } ) );
				} ) ) : null )
			);
			dockBox().appendChild( card );
		} );
	}
	if ( d.readyState === 'loading' ) {
		d.addEventListener( 'DOMContentLoaded', restoreDock );
	} else {
		setTimeout( restoreDock, 0 );
	}

	/* ------------------------------------------------------------------ Meta suggestions modal */
	SL.metaModal = function ( type, id, onDone, subject ) {
		var task = SL.task( { title: t( 'AI title & description' ), subject: subject || '', steps: [ t( 'Analyzing real search queries…' ), t( 'Writing title and description options…' ) ] } );
		SL.api( 'meta', { body: { object_type: type, object_id: id } } ).then( function ( r ) {
			var first = r.task === 'ctr_rewrite' ? r.ai.title : ( r.ai.title_options && r.ai.title_options[ 0 ] ? r.ai.title_options[ 0 ].title : '' );
			task.done( { summary: t( 'New title and description options are ready.' ), lines: first ? [ first ] : [], action: { label: t( 'Choose & apply' ), fn: function () {
				open( r );
			} } } );
		} ).catch( function ( e ) {
			task.fail( e.message );
		} );
		function open( r ) {
			var m = SL.modal( { title: t( 'AI title & description' ), wide: true } );
			var d2 = r.ai;
			var titles = r.task === 'ctr_rewrite' ? [ { title: d2.title, rationale: d2.diagnosis } ].concat( ( d2.alternatives || [] ).map( function ( x ) {
				return { title: x.title, rationale: '' };
			} ) ) : d2.title_options;
			var descs = r.task === 'ctr_rewrite' ? [ { description: d2.meta_description, rationale: d2.expected_impact } ].concat( ( d2.alternatives || [] ).map( function ( x ) {
				return { description: x.meta_description, rationale: '' };
			} ) ) : d2.description_options;
			var tIn = h( 'input', { class: 'sl-input', value: titles[ 0 ] ? titles[ 0 ].title : '' } );
			var dIn = h( 'textarea', { class: 'sl-input', rows: 3 } );
			dIn.value = descs[ 0 ] ? descs[ 0 ].description : '';
			var serp = h( 'div', { class: 'sl-serp' } );
			function renderSerp() {
				SL.clear( serp );
				serp.appendChild( h( 'div', { class: 'sl-serp__url', text: C.homeUrl || '' } ) );
				serp.appendChild( h( 'div', { class: 'sl-serp__title', text: tIn.value } ) );
				serp.appendChild( h( 'div', { class: 'sl-serp__desc', text: dIn.value } ) );
				serp.appendChild( h( 'div', { class: 'sl-muted sl-small', text: tIn.value.length + ' / 60 · ' + dIn.value.length + ' / 158' } ) );
			}
			tIn.addEventListener( 'input', renderSerp );
			dIn.addEventListener( 'input', renderSerp );
			renderSerp();
			m.body.appendChild( h( 'div', { class: 'sl-grid-2' },
				h( 'div', { class: 'sl-stack' },
					h( 'div', { class: 'sl-panel' }, h( 'div', { class: 'sl-panel__title', text: t( 'BEFORE' ) } ), h( 'div', { text: r.current.title } ), h( 'div', { class: 'sl-muted', text: r.current.description || '—' } ) ),
					h( 'label', { class: 'sl-label', text: t( 'SEO title' ) } ), tIn,
					h( 'div', { class: 'sl-options' }, titles.map( function ( o ) {
						return h( 'button', { class: 'sl-option', type: 'button', title: o.rationale || '', onClick: function () {
							tIn.value = o.title;
							renderSerp();
						} }, o.title );
					} ) ),
					h( 'label', { class: 'sl-label', text: t( 'Meta description' ) } ), dIn,
					h( 'div', { class: 'sl-options' }, descs.map( function ( o ) {
						return h( 'button', { class: 'sl-option', type: 'button', onClick: function () {
							dIn.value = o.description;
							renderSerp();
						} }, o.description );
					} ) )
				),
				h( 'div', null, h( 'div', { class: 'sl-panel__title', text: t( 'Google preview' ) } ), serp, h( 'p', { class: 'sl-reason', text: d2.expected_impact || '' } ) )
			) );
			m.foot.appendChild( h( 'button', { class: 'sl-btn', onClick: function () {
				Object.keys( r.changes ).forEach( function ( k ) {
					SL.api( 'changes/' + r.changes[ k ] + '/reject', { method: 'POST' } );
				} );
				task.note( t( 'Suggestion rejected' ), false );
				m.close();
			} }, t( 'Reject' ) ) );
			m.foot.appendChild( h( 'button', { class: 'sl-btn sl-btn--primary', onClick: function () {
				var jobs = [];
				if ( r.changes.meta_title ) {
					jobs.push( SL.api( 'changes/' + r.changes.meta_title + '/apply', { method: 'POST', body: { value: tIn.value } } ) );
				}
				if ( r.changes.meta_description ) {
					jobs.push( SL.api( 'changes/' + r.changes.meta_description + '/apply', { method: 'POST', body: { value: dIn.value } } ) );
				}
				Promise.all( jobs ).then( function () {
					SL.seoSync( { title: tIn.value, description: dIn.value } );
					SL.toast( t( 'Title & description applied.' ), 'success' );
					task.note( t( 'Applied: %s', tIn.value ) );
					m.close();
					if ( onDone ) {
						onDone( tIn.value, dIn.value );
					}
				} ).catch( SL.fail );
			} }, t( 'Apply' ) ) );
		}
	};

	/* ------------------------------------------------------------------ SEO plugin editor sync */
	/**
	 * Push values into the Rank Math / Yoast editor stores (Gutenberg & classic)
	 * so their own "Save" does not overwrite what SEOlity just wrote, and their
	 * sidebars show the new keywords / snippet live. All calls are guarded.
	 * v: { keywords: 'a,b', title, description, pillar }
	 */
	SL.seoSync = function ( v ) {
		var data = w.wp && w.wp.data;
		if ( ! data || ! data.dispatch ) {
			return false;
		}
		var synced = false;
		function call( store, fn, arg ) {
			try {
				var s = data.dispatch( store );
				if ( s && typeof s[ fn ] === 'function' ) {
					s[ fn ]( arg );
					synced = true;
				}
			} catch ( e ) {}
		}
		if ( v.keywords !== undefined ) {
			call( 'rank-math', 'updateKeywords', String( v.keywords ).split( /\s*,\s*/ ).filter( Boolean ).join( ',' ) );
			call( 'yoast-seo/editor', 'setFocusKeyword', String( v.keywords ).split( ',' )[ 0 ].trim() );
		}
		if ( v.title ) {
			call( 'rank-math', 'updateSerpTitle', v.title );
		}
		if ( v.description ) {
			call( 'rank-math', 'updateSerpDescription', v.description );
		}
		if ( v.title || v.description ) {
			var y = {};
			if ( v.title ) {
				y.title = v.title;
			}
			if ( v.description ) {
				y.description = v.description;
			}
			call( 'yoast-seo/editor', 'updateData', y );
		}
		if ( v.pillar !== undefined ) {
			call( 'rank-math', 'updatePillarContent', !! v.pillar );
			call( 'yoast-seo/editor', 'setCornerstoneContent', !! v.pillar );
		}
		// Classic editor fallbacks: the plugins' metabox inputs + SEOlity's own box.
		if ( v.keywords !== undefined ) {
			var list = String( v.keywords ).split( /\s*,\s*/ ).filter( Boolean );
			[ [ '[name="rank_math_focus_keyword"]', list.join( ',' ) ], [ '[name="yoast_wpseo_focuskw"]', list[ 0 ] || '' ], [ '#seolity_focus_keyword', list.join( ', ' ) ] ].forEach( function ( x ) {
				var el = d.querySelector( x[ 0 ] );
				if ( el ) {
					el.value = x[ 1 ];
					el.dispatchEvent( new Event( 'input', { bubbles: true } ) );
					synced = synced || x[ 0 ] !== '#seolity_focus_keyword';
				}
			} );
			Array.prototype.forEach.call( d.querySelectorAll( '.seolity-kw-list' ), function ( el ) {
				el.textContent = list.join( ', ' );
			} );
		}
		return synced;
	};

	/* ------------------------------------------------------------------ GSC keyword intelligence modal */
	/**
	 * Focus keywords chosen from real Search Console data for one page:
	 * suggested set, coverage of every query, gaps, questions, conflicts,
	 * CTR gap and an optional AI keyword strategy. opts: { onApply(list), editor }
	 */
	SL.keywordInsights = function ( type, id, opts ) {
		opts = opts || {};
		var m = SL.modal( { title: t( 'GSC keyword intelligence' ), wide: true } );
		m.body.appendChild( SL.spinner( t( 'Reading the queries Google shows this page for…' ) ) );
		SL.api( 'focus/insights?object_type=' + ( type || 'post' ) + '&object_id=' + id ).then( function ( r ) {
			SL.clear( m.body );
			if ( ! r.has_gsc ) {
				m.body.appendChild( h( 'div', { class: 'sl-alert sl-alert--info' }, h( 'strong', { text: t( 'Connect Google Search Console' ) } ), h( 'p', { text: t( 'Focus keywords are chosen from the real queries Google shows each page for. Connect Search Console to use this.' ) } ), h( 'a', { class: 'sl-btn sl-btn--primary sl-btn--sm', href: ( C.adminUrl || '' ) + 'admin.php?page=seolity#/guide?service=gsc' }, SL.il( 'book', t( 'Open the connection guide' ), 16 ) ) ) );
				return;
			}
			var met = r.metrics || {};
			var kpis = h( 'div', { class: 'sl-kw-kpis' },
				[ [ t( 'Impressions' ), SL.num( met.impressions, 0 ), 'eye' ], [ t( 'Clicks' ), SL.num( met.clicks, 0 ), 'target' ], [ t( 'Avg. position' ), met.position ? SL.num( met.position ) : '—', 'trending' ], [ 'CTR', SL.num( met.ctr ) + '%', 'chart' ] ].map( function ( k ) {
					return h( 'div', { class: 'sl-kw-kpi' }, SL.icon( k[ 2 ], 18 ), h( 'span', { text: k[ 0 ] } ), h( 'strong', { text: k[ 1 ] } ) );
				} ),
				r.rank_math ? h( 'div', { class: 'sl-kw-kpi sl-kw-kpi--rm' }, SL.icon( 'shield', 18 ), h( 'span', { text: t( 'Rank Math score' ) } ), h( 'strong', { text: r.rank_math.score === null ? '—' : String( r.rank_math.score ) + '/100' } ) ) : null
			);
			m.body.appendChild( kpis );
			m.body.appendChild( h( 'p', { class: 'sl-muted sl-small', text: t( 'Last 28 days for this URL. Keyword data below covers the last 90 days.' ) } ) );

			// Keyword set editor.
			var suggested = [ r.suggested.primary ].concat( r.suggested.secondary || [] ).filter( Boolean );
			var input = h( 'input', { class: 'sl-input', dir: 'auto', value: ( suggested.length ? suggested : r.current ).join( ', ' ) } );
			function list() {
				return input.value.split( ',' ).map( function ( s ) {
					return s.trim();
				} ).filter( Boolean );
			}
			function add( q ) {
				var l = list();
				if ( l.map( function ( x ) {
					return x.toLowerCase();
				} ).indexOf( q.toLowerCase() ) === -1 ) {
					l.push( q );
				}
				input.value = l.join( ', ' );
			}
			var setBox = h( 'div', { class: 'sl-panel sl-kw-set' },
				h( 'div', { class: 'sl-row sl-row--between' }, h( 'div', { class: 'sl-panel__title' }, t( 'Focus keywords' ) ), r.engine !== 'seolity' ? SL.badge( t( 'Saved to %s', r.engine === 'rankmath' ? 'Rank Math' : r.engine === 'yoast' ? 'Yoast' : r.engine === 'seopress' ? 'SEOPress' : 'AIOSEO' ), 'info' ) : null ),
				h( 'div', { class: 'sl-small sl-muted' }, t( 'First keyword = primary. The others are secondary keywords (Rank Math shows each in its checklist).' ) ),
				input,
				h( 'div', { class: 'sl-small sl-iconline' }, SL.icon( 'sparkles', 14 ), h( 'span', { text: t( 'Suggested from Search Console' ) + ': ' } ), h( 'strong', { dir: 'auto', text: suggested.join( '، ' ) || '—' } ) ),
				h( 'div', { class: 'sl-small sl-iconline sl-muted' }, SL.icon( 'key', 14 ), h( 'span', { text: t( 'Current' ) + ': ' } ), h( 'span', { dir: 'auto', text: r.current.join( '، ' ) || t( 'none' ) } ) )
			);
			m.body.appendChild( setBox );

			// Tips.
			if ( r.tips && r.tips.length ) {
				m.body.appendChild( h( 'ul', { class: 'sl-kw-tips' }, r.tips.map( function ( x ) {
					return h( 'li', { class: 'sl-iconline' }, SL.dot( x.level ), h( 'span', { dir: 'auto', text: x.text } ) );
				} ) ) );
			}

			function cov( q ) {
				return h( 'span', { class: 'sl-kw-cov' }, [ [ 'T', q.in_title, t( 'In title' ) ], [ 'D', q.in_meta, t( 'In meta description' ) ], [ 'H', q.in_headings, t( 'In headings' ) ], [ 'C', q.in_body, t( 'In content' ) ] ].map( function ( c ) {
					return h( 'span', { class: 'sl-kw-cov__i' + ( c[ 1 ] ? ' is-on' : '' ), title: c[ 2 ] + ': ' + ( c[ 1 ] ? t( 'Yes' ) : t( 'No' ) ) }, c[ 0 ] );
				} ) );
			}
			var cols = function ( withOwner ) {
				return [
					{ label: t( 'Query' ), render: function ( q ) {
						return h( 'span', { class: 'sl-iconline', dir: 'auto' }, q.is_focus ? SL.icon( 'key', 14, 'is-good' ) : null, q.question ? SL.icon( 'info', 14 ) : null, h( 'span', { text: q.query } ) );
					} },
					{ label: t( 'Impr.' ), render: function ( q ) {
						return SL.num( q.impressions, 0 );
					} },
					{ label: t( 'Clicks' ), render: function ( q ) {
						return SL.num( q.clicks, 0 );
					} },
					{ label: t( 'Pos.' ), render: function ( q ) {
						return SL.num( q.position );
					} },
					{ label: t( 'Coverage' ), render: cov },
					withOwner ? { label: t( 'Owner' ), render: function ( q ) {
						return q.owner ? SL.badge( t( 'This page' ), 'good' ) : h( 'a', { href: q.owner_url, target: '_blank', rel: 'noopener', class: 'sl-link', dir: 'auto', text: SL.path( q.owner_url ) } );
					} } : null,
					{ label: '', render: function ( q ) {
						return q.owner ? h( 'button', { type: 'button', class: 'sl-btn sl-btn--sm sl-btn--icon', title: t( 'Add to focus keywords' ), 'aria-label': t( 'Add to focus keywords' ), onClick: function () {
							add( q.query );
						} }, SL.icon( 'plus', 15 ) ) : null;
					} },
				].filter( Boolean );
			};
			function table( rows, withOwner, empty ) {
				if ( ! rows || ! rows.length ) {
					return SL.empty( empty || t( 'Nothing here' ), '' );
				}
				var c = cols( withOwner );
				return h( 'div', { class: 'sl-table-wrap' }, h( 'table', { class: 'sl-table sl-table--compact' }, h( 'thead', null, h( 'tr', null, c.map( function ( x ) {
					return h( 'th', { scope: 'col' }, x.label );
				} ) ) ), h( 'tbody', null, rows.map( function ( q ) {
					return h( 'tr', { class: q.owner ? '' : 'is-muted' }, c.map( function ( x ) {
						return h( 'td', null, x.render( q ) );
					} ) );
				} ) ) ) );
			}
			var aiBox = h( 'div' );
			m.body.appendChild( SL.tabs( [
				{ label: t( 'All queries' ) + ' (' + r.queries.length + ')', render: function () {
					return table( r.queries, true );
				} },
				{ label: t( 'Not covered' ) + ' (' + ( r.missing || [] ).length + ')', render: function () {
					return [ h( 'p', { class: 'sl-small sl-muted', text: t( 'Google already shows this page for these queries, but the content barely mentions them. Add a section or paragraph for each.' ) } ), table( r.missing, false, t( 'Every important query is covered' ) ), opts.editor && SL.openOptimizer && ( r.missing || [] ).length > 0 ? h( 'button', { type: 'button', class: 'sl-btn sl-btn--primary sl-btn--sm', onClick: function () {
						m.close();
						SL.openOptimizer( 'post_content' );
					} }, SL.il( 'sparkles', t( 'Optimize content with AI' ) ) ) : null ];
				} },
				{ label: t( 'Questions' ) + ' (' + ( r.questions || [] ).length + ')', render: function () {
					return [ table( r.questions, false, t( 'No question queries yet' ) ), ( r.questions || [] ).length > 0 && SL.faqFor && type === 'post' ? h( 'button', { type: 'button', class: 'sl-btn sl-btn--sm', onClick: function () {
						m.close();
						SL.faqFor( id );
					} }, SL.il( 'sparkles', t( 'Generate FAQ' ) ) ) : null ];
				} },
				{ label: t( 'Striking distance' ) + ' (' + ( r.striking || [] ).length + ')', render: function () {
					return [ h( 'p', { class: 'sl-small sl-muted', text: t( 'Positions 4–20: the fastest wins. Strengthen these in headings, add internal links with these anchors.' ) } ), table( r.striking, false ) ];
				} },
				{ label: t( 'Cannibalization' ) + ' (' + ( r.conflicts || [] ).length + ')', render: function () {
					return [ h( 'p', { class: 'sl-small sl-muted', text: t( 'Another page of your site wins these queries. Do not target them here; link to the winning page instead.' ) } ), table( r.conflicts, true, t( 'No conflicts' ) ) ];
				} },
				{ label: t( 'AI strategy' ), render: function () {
					return aiBox;
				} },
			] ) );

			// AI keyword strategy (grounded in the same GSC data).
			function renderPlan( res ) {
				SL.clear( aiBox );
				var p = res.plan;
				var ti = h( 'input', { class: 'sl-input', dir: 'auto', value: p.seo_title || '' } );
				var de = h( 'textarea', { class: 'sl-input', dir: 'auto', rows: 2 } );
				de.value = p.meta_description || '';
				SL.append( aiBox, [
					h( 'div', { class: 'sl-row' }, SL.badge( t( p.search_intent ), 'info' ), p.pillar ? SL.badge( t( 'Pillar page' ), 'good' ) : null, SL.badge( t( 'Confidence' ) + ' ' + ( p.confidence || 0 ) + '%', 'neutral' ) ),
					h( 'p', { dir: 'auto' }, h( 'strong', { text: t( 'Primary' ) + ': ' } ), p.primary_keyword, ' · ', h( 'strong', { text: t( 'Secondary' ) + ': ' } ), ( p.secondary_keywords || [] ).join( '، ' ) ),
					h( 'p', { class: 'sl-reason', dir: 'auto', text: p.rationale } ),
					h( 'button', { type: 'button', class: 'sl-btn sl-btn--sm', onClick: function () {
						input.value = [ p.primary_keyword ].concat( p.secondary_keywords || [] ).filter( Boolean ).join( ', ' );
						SL.toast( t( 'Keywords copied to the set above. Press Apply to save.' ) );
					} }, SL.il( 'key', t( 'Use these keywords' ) ) ),
					h( 'label', { class: 'sl-label', text: t( 'SEO title' ) } ), ti,
					h( 'label', { class: 'sl-label', text: t( 'Meta description' ) } ), de,
					SL.listBlock( t( 'Content gaps (add these sections)' ), ( p.content_gaps || [] ).map( function ( g ) {
						return g.heading + ' — ' + g.query + ( g.why ? ' (' + g.why + ')' : '' );
					} ), 'info' ),
					SL.listBlock( t( 'FAQ questions' ), p.faq_questions || [], 'info' ),
					SL.listBlock( t( 'Internal link anchors to this page' ), p.link_anchors || [], 'info' ),
					SL.listBlock( t( 'Do not target here' ), p.avoid_keywords || [], 'warn' ),
				] );
				aiBox._plan = { title: ti, description: de };
			}
			aiBox.appendChild( h( 'div', { class: 'sl-empty' }, SL.icon( 'brain', 28 ), h( 'p', { text: t( 'AI combines these Search Console queries with your content and picks the keyword set, title, description, missing sections and FAQ — beyond a generic content AI, because it knows what Google already ranks you for.' ) } ), h( 'button', { type: 'button', class: 'sl-btn sl-btn--primary', onClick: function ( e ) {
				var b = e.currentTarget;
				b.disabled = true;
				SL.clear( aiBox );
				aiBox.appendChild( SL.spinner( t( 'Building the keyword strategy…' ) ) );
				SL.api( 'focus/plan', { body: { object_type: type, object_id: id } } ).then( renderPlan ).catch( function ( err ) {
					SL.clear( aiBox );
					aiBox.appendChild( h( 'div', { class: 'sl-alert sl-alert--error', text: err.message } ) );
				} );
			} }, SL.il( 'sparkles', t( 'Build AI keyword strategy' ) ) ) ) );

			m.foot.appendChild( h( 'button', { class: 'sl-btn', type: 'button', onClick: m.close }, t( 'Close' ) ) );
			m.foot.appendChild( h( 'button', { class: 'sl-btn sl-btn--primary', type: 'button', onClick: function ( e ) {
				var b = e.currentTarget;
				var kws = list();
				var body = { object_type: type, object_id: id, keywords: kws };
				if ( aiBox._plan ) {
					body.meta_title = aiBox._plan.title.value;
					body.meta_description = aiBox._plan.description.value;
				}
				b.disabled = true;
				SL.api( 'focus/apply', { body: body } ).then( function ( res ) {
					var synced = SL.seoSync( { keywords: kws.join( ',' ), title: body.meta_title, description: body.meta_description } );
					SL.toast( synced ? t( 'Saved and synced with the SEO plugin in this editor.' ) : t( 'Focus keywords saved.' ), 'success' );
					if ( opts.onApply ) {
						opts.onApply( kws, res );
					}
					m.close();
				} ).catch( function ( err ) {
					b.disabled = false;
					SL.fail( err );
				} );
			} }, SL.il( 'check', t( 'Apply' ) ) ) );
		} ).catch( function ( e ) {
			m.close();
			SL.fail( e );
		} );
	};

	/* ------------------------------------------------------------------ Setup-wizard building blocks */
	/** Copyable command / code block (LTR). */
	SL.cmd = function ( text ) {
		text = Array.isArray( text ) ? text.join( '\n' ) : String( text );
		return h( 'div', { class: 'sl-cmd', dir: 'ltr' }, h( 'code', { text: text } ), h( 'button', { type: 'button', class: 'sl-btn sl-btn--sm sl-btn--icon', title: t( 'Copy' ), 'aria-label': t( 'Copy' ), onClick: function () {
			( navigator.clipboard ? navigator.clipboard.writeText( text ) : Promise.reject() ).then( function () {
				SL.toast( t( 'Copied.' ) );
			} ).catch( function () {
				SL.toast( text );
			} );
		} }, SL.icon( 'copy', 15 ) ) );
	};
	/** Password field with a show/hide button. Returns the wrapper; the input is .input. */
	SL.secretInput = function ( placeholder, multiline ) {
		var input = multiline ? h( 'textarea', { class: 'sl-input sl-mono-input', rows: 5, dir: 'ltr', placeholder: placeholder || '', spellcheck: 'false', autocomplete: 'off' } ) : h( 'input', { class: 'sl-input', type: 'password', dir: 'ltr', placeholder: placeholder || '', autocomplete: 'new-password', spellcheck: 'false' } );
		var wrap = h( 'div', { class: 'sl-pass' }, input, multiline ? null : h( 'button', { type: 'button', class: 'sl-btn sl-btn--sm sl-btn--icon sl-btn--ghost', title: t( 'Show' ), 'aria-label': t( 'Show' ), onClick: function ( e ) {
			input.type = input.type === 'password' ? 'text' : 'password';
			SL.clear( e.currentTarget ).appendChild( SL.icon( input.type === 'password' ? 'eye' : 'eye-off', 16 ) );
		} }, SL.icon( 'eye', 16 ) ) );
		wrap.input = input;
		return wrap;
	};
	/** Numbered guide list; items are nodes, strings or arrays. */
	SL.steps = function ( items ) {
		return h( 'ol', { class: 'sl-howto' }, items.filter( Boolean ).map( function ( x ) {
			return h( 'li', null, x );
		} ) );
	};
	/** External link that opens in a new tab with an icon. */
	SL.xlink = function ( url, label ) {
		return h( 'a', { href: url, target: '_blank', rel: 'noopener noreferrer', class: 'sl-xlink' }, h( 'span', { text: label } ), SL.icon( 'external', 13 ) );
	};
	/**
	 * Wizard step card: number (or check when done), title, optional status badge.
	 * opts: { n, title, sub, done, state: [text, kind], id, body }
	 */
	SL.wizStep = function ( opts ) {
		return h( 'section', { class: 'sl-card sl-wiz' + ( opts.done ? ' is-done' : '' ) + ( opts.optional ? ' is-optional' : '' ), id: opts.id || null },
			h( 'div', { class: 'sl-wiz__num', 'aria-hidden': 'true' }, opts.done ? SL.icon( 'check', 18 ) : String( opts.n ) ),
			h( 'div', { class: 'sl-wiz__body' },
				h( 'div', { class: 'sl-wiz__head' }, h( 'div', null, h( 'h3', { text: opts.title } ), opts.sub ? h( 'p', { class: 'sl-muted', text: opts.sub } ) : null ), opts.state ? SL.badge( opts.state[ 0 ], opts.state[ 1 ] ) : null ),
				opts.body
			)
		);
	};
	/** Readiness list: [{level: ok|warn|fail|info, title, detail, fix}] */
	SL.checklist = function ( checks, onFix ) {
		var icon = { ok: [ 'check-circle', 'is-good' ], warn: [ 'alert', 'is-warn' ], fail: [ 'x-circle', 'is-bad' ], info: [ 'info', 'is-info' ] };
		return h( 'ul', { class: 'sl-checks' }, checks.map( function ( c ) {
			var ic = icon[ c.level ] || icon.info;
			return h( 'li', { class: 'sl-checks__row is-' + c.level },
				SL.icon( ic[ 0 ], 20, ic[ 1 ] ),
				h( 'div', { class: 'sl-checks__text' }, h( 'strong', { text: c.title } ), c.detail ? h( 'small', { dir: 'auto', text: c.detail } ) : null ),
				c.fix && c.level !== 'ok' && onFix ? h( 'button', { type: 'button', class: 'sl-btn sl-btn--sm', onClick: function () {
					onFix( c.fix );
				} }, c.level === 'info' ? t( 'Open' ) : t( 'Fix' ) ) : null
			);
		} ) );
	};

	/* ------------------------------------------------------------------ Shared list block */
	SL.listBlock = function ( title, items, kind ) {
		return items && items.length ? h( 'div', { class: 'sl-alert sl-alert--' + ( kind || 'info' ) }, h( 'strong', { text: title } ), h( 'ul', null, items.map( function ( x ) {
			return h( 'li', { dir: 'auto', text: x } );
		} ) ) ) : null;
	};

	/* ------------------------------------------------------------------ E-E-A-T analysis modal */
	SL.eeatFor = function ( type, id ) {
		var m = SL.modal( { title: t( 'E-E-A-T analysis' ), wide: true } );
		m.body.appendChild( SL.spinner( t( 'Checking experience, expertise, authority and trust signals…' ) ) );
		SL.api( 'eeat', { body: { object_type: type || 'post', object_id: id } } ).then( function ( r ) {
			var a = r.analysis;
			var s = r.signals || {};
			var yes = function ( v ) {
				return SL.yes( !! v, 15 );
			};
			SL.clear( m.body );
			m.body.appendChild( h( 'div', { class: 'sl-stack' },
				h( 'div', { class: 'sl-toolbar' }, SL.scoreRing( a.score, 72, t( 'E-E-A-T' ) ), h( 'p', { class: 'sl-muted', text: t( 'A guide for improving trust signals, not a Google metric.' ) } ) ),
				SL.listBlock( t( 'Signals found' ), a.signals_present, 'success' ),
				SL.listBlock( t( 'Missing signals' ), a.missing_signals, 'warn' ),
				SL.listBlock( t( 'Recommendations' ), a.recommendations, 'info' ),
				h( 'details', null, h( 'summary', { text: t( 'Site-wide trust pages' ) } ), h( 'ul', null,
					h( 'li', { class: 'sl-iconline' }, yes( s.https ), 'HTTPS' ),
					h( 'li', { class: 'sl-iconline' }, yes( s.about_page ), t( 'About page' ) ),
					h( 'li', { class: 'sl-iconline' }, yes( s.contact_page ), t( 'Contact page' ) ),
					h( 'li', { class: 'sl-iconline' }, yes( s.privacy_policy ), t( 'Privacy policy' ) ),
					h( 'li', { class: 'sl-iconline' }, yes( s.returns_policy ), t( 'Returns / refund policy' ) )
				) )
			) );
		} ).catch( function ( e ) {
			m.close();
			SL.fail( e );
		} );
	};

	/* ------------------------------------------------------------------ SERP analysis */
	SL.serpView = function ( r ) {
		var a = r.analysis;
		var intents = { informational: t( 'Informational' ), commercial: t( 'Commercial' ), transactional: t( 'Transactional' ), navigational: t( 'Navigational' ) };
		return h( 'div', { class: 'sl-stack' },
			h( 'div', { class: 'sl-toolbar' }, SL.badge( t( 'Search intent' ) + ': ' + ( intents[ a.intent ] || a.intent ), 'brand' ), r.yours.length ? SL.badge( t( 'You rank #%s', r.yours[ 0 ].position ), 'good' ) : SL.badge( t( 'Not in the top 10' ), 'warn' ) ),
			h( 'div', null, h( 'strong', { text: t( 'Winning format' ) + ': ' } ), h( 'span', { dir: 'auto', text: a.content_format } ) ),
			h( 'div', null, h( 'strong', { text: t( 'SERP features' ) + ': ' } ), SL.chips( r.serp.features.concat( a.features ).filter( function ( x, i, arr ) {
				return arr.indexOf( x ) === i;
			} ) ) ),
			SL.listBlock( t( 'Recommendations' ), a.recommendations, 'info' ),
			SL.listBlock( t( 'Title patterns' ), a.title_patterns, 'success' ),
			h( 'div', null, h( 'strong', { text: t( 'Topics to cover' ) + ': ' } ), SL.chips( a.common_topics ) ),
			SL.listBlock( t( 'Questions people ask' ), a.questions.length ? a.questions : r.serp.questions, 'warn' ),
			h( 'details', null, h( 'summary', { text: t( 'Top results (%s)', r.serp.results.length ) } ), h( 'div', null, r.serp.results.map( function ( x ) {
				var mine = r.yours.some( function ( y ) {
					return y.url === x.url;
				} );
				return h( 'div', { class: 'sl-serp-result' + ( mine ? ' is-yours' : '' ) },
					h( 'div', { class: 'sl-serp-result__url', text: '#' + x.position + ' · ' + x.url } ),
					h( 'div', { class: 'sl-serp-result__title', dir: 'auto', text: x.title } ),
					h( 'div', { class: 'sl-small', dir: 'auto', text: x.snippet } )
				);
			} ) ) ),
			r.serp.related.length ? h( 'div', null, h( 'strong', { text: t( 'Related searches' ) + ': ' } ), SL.chips( r.serp.related ) ) : null,
			h( 'p', { class: 'sl-small sl-muted', text: t( 'Live results fetched %s (cached for 24 hours).', SL.date( r.serp.fetched ) ) } )
		);
	};
	SL.serpFor = function ( keyword, market ) {
		var m = SL.modal( { title: t( 'SERP analysis' ) + ': ' + keyword, wide: true } );
		m.body.appendChild( SL.spinner( t( 'Fetching live Google results and analyzing them…' ) ) );
		SL.api( 'serp/analyze', { body: { keyword: keyword, market: market || '' } } ).then( function ( r ) {
			SL.clear( m.body );
			m.body.appendChild( SL.serpView( r ) );
		} ).catch( function ( e ) {
			m.close();
			SL.fail( e );
		} );
	};
} )( window, document );
