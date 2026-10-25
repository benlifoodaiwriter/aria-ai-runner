/**
 * Indexability & crawl budget: which URL groups are technically indexable vs. which should be,
 * with the reason, the right mechanism (noindex / robots.txt / sitemap / redirect) and a
 * verified fix through the operations engine.
 */
( function ( w, d ) {
	'use strict';
	var SL = w.SL;
	var App = SL.app;
	var t = SL.t;
	var h = SL.h;

	var SEV = { high: [ t( 'High' ), 'bad' ], medium: [ t( 'Medium' ), 'warn' ], low: [ t( 'Low' ), '' ] };

	function stateBadges( s ) {
		if ( ! s ) {
			return null;
		}
		var out = h( 'div', { class: 'sl-chips' } );
		out.appendChild( SL.badge( s.status ? 'HTTP ' + s.status : t( 'No response' ), 200 === s.status ? 'good' : ( s.status >= 300 && s.status < 400 ? '' : 'bad' ) ) );
		out.appendChild( SL.badge( s.noindex ? 'noindex' : 'index', s.noindex ? '' : 'brand' ) );
		if ( s.robots_txt_blocked ) {
			out.appendChild( SL.badge( t( 'Blocked in robots.txt' ), 'warn' ) );
		}
		if ( s.in_sitemap ) {
			out.appendChild( SL.badge( t( 'In sitemap' ), '' ) );
		}
		if ( s.canonical ) {
			out.appendChild( h( 'span', { class: 'sl-muted sl-small', dir: 'ltr', title: s.canonical, text: 'canonical ✓' } ) );
		}
		if ( s.google && s.google.coverage ) {
			out.appendChild( SL.badge( 'Google: ' + s.google.coverage, 'PASS' === s.google.verdict ? 'good' : 'warn' ) );
		}
		return out;
	}

	function explainer() {
		return h( 'details', { class: 'sl-card sl-explain' },
			h( 'summary', { text: t( 'noindex, robots.txt and the sitemap do different jobs' ) } ),
			h( 'ul', {},
				h( 'li', { text: t( 'noindex (meta robots or X-Robots-Tag): the page may be crawled but is kept out of Google. Use it for pages that must exist but should not rank.' ) } ),
				h( 'li', { text: t( 'robots.txt Disallow: Google does not crawl the URL at all, so it never sees a noindex there. Use it only for crawl budget, after the URLs are already out of the index.' ) } ),
				h( 'li', { text: t( 'XML sitemap: a list of pages you want indexed. Never list noindexed, redirected or blocked URLs.' ) } ),
				h( 'li', { text: t( '301 redirect: the page should not exist on its own (e.g. attachment pages) — visitors and Google go to the right page.' ) } )
			)
		);
	}

	App.pages.indexability = function ( v ) {
		var body = h( 'div' );
		var timer;
		v.appendChild( App.header( t( 'Indexability & crawl budget' ), t( 'Checks templates, attachments, archives, search, parameters, feeds and pagination: live status, robots meta, X-Robots-Tag, canonical, robots.txt, sitemap, internal links and Rank Math settings — intended vs. technical indexability.' ), App.cap( 'manage' ) ? [ App.abtn( SL.il( 'pulse', t( 'Run the audit' ), 15 ), function () {
			return SL.api( 'indexability/scan', { method: 'POST' } ).then( function () {
				SL.toast( t( 'Audit started in the background.' ) );
				load();
			} );
		}, 'primary' ) ] : null ) );
		v.appendChild( explainer() );
		v.appendChild( body );

		function load() {
			clearTimeout( timer );
			App.load( body, SL.api( 'indexability' ), function ( r ) {
				if ( r.running ) {
					body.appendChild( h( 'div', { class: 'sl-alert sl-alert--info sl-row' }, SL.spinner(), h( 'span', { text: t( 'The audit is running — results refresh automatically.' ) } ) ) );
					timer = setTimeout( function () {
						if ( d.body.contains( body ) ) {
							load();
						}
					}, 4000 );
				}
				var last = r.last;
				if ( ! last ) {
					if ( ! r.running ) {
						body.appendChild( SL.empty( t( 'No audit yet' ), t( 'Run it to see what Google can index on this site.' ) ) );
					}
					return;
				}
				var high = last.findings.filter( function ( f ) {
					return 'high' === f.severity;
				} ).length;
				body.appendChild( h( 'div', { class: 'sl-kpis' },
					App.kpi( t( 'Findings' ), SL.num( last.findings.length, 0 ) ),
					App.kpi( t( 'High severity' ), SL.num( high, 0 ) ),
					App.kpi( t( 'URL groups checked' ), SL.num( last.inventory.length, 0 ) ),
					App.kpi( t( 'SEO plugin' ), last.rankmath ? 'Rank Math' : String( last.engine || '—' ) )
				) );
				body.appendChild( h( 'p', { class: 'sl-muted sl-small', text: t( 'Last audit: %s', SL.date( last.at ) ) + ( last.gsc ? '' : ' · ' + t( 'Connect Search Console to include Google’s own index status.' ) ) } ) );

				body.appendChild( h( 'h3', { text: t( 'Findings' ) } ) );
				if ( ! last.findings.length ) {
					body.appendChild( h( 'div', { class: 'sl-alert sl-alert--success', text: t( 'Nothing to fix: every URL group is indexed (or kept out) the way it should be.' ) } ) );
				}
				last.findings.forEach( function ( f ) {
					var sev = SEV[ f.severity ] || SEV.low;
					var card = h( 'div', { class: 'sl-card sl-finding' } );
					card.appendChild( h( 'div', { class: 'sl-row' }, h( 'b', { dir: 'auto', text: f.type } ), SL.badge( sev[ 0 ], sev[ 1 ] ), f.count ? h( 'span', { class: 'sl-muted sl-small', text: t( '%s URLs', SL.num( f.count, 0 ) ) } ) : null, h( 'span', { class: 'sl-muted sl-small', text: t( 'Source: %s', f.source ) } ) ) );
					if ( f.url ) {
						card.appendChild( h( 'a', { href: f.url, target: '_blank', rel: 'noopener', dir: 'ltr', class: 'sl-small', text: f.url } ) );
					}
					card.appendChild( stateBadges( f.state ) );
					card.appendChild( h( 'p', {}, h( 'b', { text: t( 'Problem:' ) + ' ' } ), h( 'span', { text: f.issue } ) ) );
					card.appendChild( h( 'p', { class: 'sl-muted' }, h( 'b', { text: t( 'Why it matters:' ) + ' ' } ), h( 'span', { text: f.reason } ) ) );
					card.appendChild( h( 'p', {}, h( 'b', { text: t( 'Solution:' ) + ' ' } ), h( 'span', { text: f.solution } ) ) );
					var acts = h( 'div', { class: 'sl-row' } );
					if ( f.fixable && App.cap( 'manage' ) ) {
						acts.appendChild( App.abtn( SL.il( 'wand', t( 'Fix it (preview first)' ), 15 ), function () {
							return SL.api( 'indexability/fix', { body: { key: f.key } } ).then( function ( op ) {
								var m = SL.modal( { title: op.title, wide: true, onClose: load } );
								var box = h( 'div' );
								m.body.appendChild( box );
								App.operationView( box, op, load );
							} );
						}, 'primary' ) );
					}
					if ( f.edit ) {
						acts.appendChild( h( 'a', { class: 'sl-btn', href: f.edit, text: t( 'Fix manually' ) } ) );
					}
					card.appendChild( acts );
					body.appendChild( card );
				} );

				body.appendChild( h( 'h3', { text: t( 'Everything that was checked' ) } ) );
				body.appendChild( App.table( [
					{ label: t( 'URL group' ), render: function ( x ) {
						return h( 'div', {}, h( 'b', { dir: 'auto', text: x.label } ), x.note ? h( 'div', { class: 'sl-muted sl-small', text: x.note } ) : null );
					} },
					{ label: t( 'Source' ), render: function ( x ) {
						return x.source;
					} },
					{ label: t( 'Count' ), render: function ( x ) {
						return null === x.count || undefined === x.count ? '—' : SL.num( x.count, 0 );
					} },
					{ label: t( 'Should be indexed' ), render: function ( x ) {
						return SL.badge( x.intended ? t( 'Yes' ) : t( 'No' ), x.intended ? 'brand' : '' );
					} },
					{ label: t( 'Live state' ), render: function ( x ) {
						return stateBadges( x.state );
					} },
					{ label: t( 'Sample URL' ), render: function ( x ) {
						return x.url ? h( 'a', { href: x.url, target: '_blank', rel: 'noopener', dir: 'ltr', class: 'sl-small', text: x.url } ) : '—';
					} },
				], last.inventory ) );
			} );
		}
		load();
	};
}( window, document ) );
