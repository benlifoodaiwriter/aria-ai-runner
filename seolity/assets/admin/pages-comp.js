/**
 * Competitor workspace: real Google results per tracked query (stored as history), one panel per
 * competitor with every query where it is above or below us, an estimated Search Console, and a
 * tab per analyzed query explaining why they rank where they do and what to do.
 */
( function ( w, d ) {
	'use strict';
	var SL = w.SL;
	var App = SL.app;
	var t = SL.t;
	var h = SL.h;

	var IMPACT = { high: [ t( 'High' ), 'bad' ], medium: [ t( 'Medium' ), 'warn' ], low: [ t( 'Low' ), '' ] };
	var PRIO = { critical: [ t( 'Critical' ), 'bad' ], high: [ t( 'High' ), 'warn' ], medium: [ t( 'Medium' ), 'brand' ], low: [ t( 'Low' ), '' ] };

	function pos( p ) {
		return null === p || undefined === p ? '—' : SL.num( p, 0 );
	}

	function trend( now, before ) {
		if ( null === before || undefined === before || now === before ) {
			return null;
		}
		var up = now < before;
		return h( 'span', { class: up ? 'is-up' : 'is-down', title: t( 'Previous check: %s', before ), text: ( up ? '▲' : '▼' ) + Math.abs( before - now ) } );
	}

	function link( url ) {
		return url ? h( 'a', { href: url, target: '_blank', rel: 'noopener', dir: 'ltr', class: 'sl-small sl-clip', title: url, text: decodeURI( SL.path( url ) ) } ) : '—';
	}

	/** Google tracking card: budget, tracked queries, check now, suggested competitors. */
	App.serpCard = function ( box, onChange ) {
		App.load( box, SL.api( 'serp/tracking' ), function ( r ) {
			var b = r.budget;
			var body = h( 'div', { class: 'sl-stack' } );
			body.appendChild( h( 'p', { class: 'sl-muted', text: t( 'SEOlity searches your tracked queries in Google through the SERP API — location-specific and not personalized, like an incognito search — and stores every result page. All competitor panels are built from this database; each new check adds history.' ) } ) );
			if ( ! b.available ) {
				body.appendChild( h( 'div', { class: 'sl-alert sl-alert--warn', text: t( 'Add a SerpApi key in Settings → Crawler & audits to search Google. Scraping Google directly from your server is against Google’s terms and is blocked with CAPTCHAs; a SERP API is the reliable, permitted way.' ) } ) );
			}
			body.appendChild( h( 'div', { class: 'sl-kpis' },
				App.kpi( t( 'Tracked queries' ), SL.num( r.queries.length, 0 ) ),
				App.kpi( t( 'Searches this month' ), SL.num( b.used, 0 ) + ( b.limit ? ' / ' + SL.num( b.limit, 0 ) : '' ) ),
				App.kpi( t( 'Last check' ), r.last_track ? SL.date( r.last_track.at ) : '—' )
			) );
			if ( r.last_track && r.last_track.errors && r.last_track.errors.length ) {
				body.appendChild( h( 'div', { class: 'sl-alert sl-alert--error', text: r.last_track.errors.join( ' · ' ) } ) );
			}
			var add = h( 'input', { class: 'sl-input', dir: 'auto', placeholder: t( 'Add queries to track, separated by commas' ) } );
			body.appendChild( h( 'div', { class: 'sl-toolbar' }, add, App.abtn( t( 'Add' ), function () {
				var qs = add.value.split( /[,،\n]/ ).map( function ( x ) {
					return x.trim();
				} ).filter( Boolean );
				return SL.api( 'serp/queries', { body: { queries: qs } } ).then( function () {
					App.serpCard( box, onChange );
				} );
			} ), App.cap( 'manage' ) && b.available ? App.abtn( SL.il( 'search', r.tracking ? t( 'Checking Google…' ) : t( 'Check Google now' ), 14 ), function () {
				return SL.api( 'serp/track', { method: 'POST' } ).then( function () {
					SL.toast( t( 'Google is being checked for the tracked queries in the background.' ) );
				} );
			}, 'primary', { disabled: r.tracking } ) : null ) );
			body.appendChild( h( 'details', null, h( 'summary', { text: t( 'Tracked queries (%s)', r.queries.length ) } ), h( 'div', { class: 'sl-chips' }, r.queries.map( function ( q ) {
				var own = r.own.indexOf( q ) >= 0;
				return h( 'span', { class: 'sl-chip', dir: 'auto' }, q, own ? h( 'button', { type: 'button', class: 'sl-chip__x', 'aria-label': t( 'Remove' ), text: '×', onClick: function () {
					SL.api( 'serp/queries', { body: { remove: q } } ).then( function () {
						App.serpCard( box, onChange );
					} );
				} } ) : null );
			} ) ), h( 'p', { class: 'sl-muted sl-small', text: t( 'Your own queries plus your most important Search Console queries. Each query uses one search per check (at most once a day).' ) } ) ) );
			if ( r.suggestions && r.suggestions.length ) {
				body.appendChild( h( 'h4', { text: t( 'Who you meet most in Google' ) } ) );
				body.appendChild( App.table( [
					{ label: t( 'Domain' ), render: function ( x ) {
						return h( 'b', { dir: 'ltr', text: x.domain } );
					} },
					{ label: t( 'Appears in' ), render: function ( x ) {
						return t( '%1$s of %2$s queries', x.queries, x.of );
					} },
					{ label: t( 'Avg. position' ), render: function ( x ) {
						return SL.num( x.avg_position );
					} },
					{ label: '', render: function ( x ) {
						return App.cap( 'manage' ) ? App.abtn( t( 'Add as competitor' ), function () {
							return SL.api( 'competitors', { body: { name: x.domain, domain: x.domain, frequency: 'weekly' } } ).then( function () {
								SL.toast( t( 'Competitor added. First analysis is running in the background.' ), 'success' );
								if ( onChange ) {
									onChange();
								}
							} );
						}, 'sm' ) : null;
					} },
				], r.suggestions ) );
			}
			box.appendChild( App.card( t( 'Google results database' ), body, { icon: 'search' } ) );
		} );
	};

	function analysisView( a ) {
		var box = h( 'div', { class: 'sl-stack sl-cq' } );
		if ( 'running' === a.status ) {
			box.appendChild( h( 'div', { class: 'sl-alert sl-alert--info sl-row' }, SL.spinner(), h( 'span', { text: t( 'The AI is comparing the pages — this tab updates by itself.' ) } ) ) );
			return box;
		}
		if ( 'failed' === a.status ) {
			box.appendChild( h( 'div', { class: 'sl-alert sl-alert--error', text: a.error || t( 'Failed' ) } ) );
			return box;
		}
		var r = a.result || {};
		box.appendChild( h( 'div', { class: 'sl-card' },
			h( 'p', { class: 'sl-cq__verdict', dir: 'auto', text: r.verdict } ),
			h( 'div', { class: 'sl-row' }, SL.badge( r.can_overtake ? t( 'Can be overtaken' ) : t( 'Hard to overtake — match them first' ), r.can_overtake ? 'good' : 'warn' ), SL.badge( t( 'Confidence %s%', r.confidence ), '' ), h( 'span', { class: 'sl-muted sl-small', text: SL.date( a.created_at ) } ) ),
			h( 'div', { class: 'sl-cq__pages' },
				h( 'div', null, h( 'small', { text: t( 'Their page' ) } ), link( a.their_url ) ),
				h( 'div', null, h( 'small', { text: t( 'Our page Google shows' ) } ), link( a.our_url ) ),
				h( 'div', null, h( 'small', { text: t( 'Our page that should rank' ) } ), link( r.target_page ) )
			),
			r.page_choice ? h( 'p', { class: 'sl-small' }, h( 'b', { text: t( 'Why Google shows this page of ours:' ) + ' ' } ), h( 'span', { dir: 'auto', text: r.page_choice } ) ) : null
		) );
		box.appendChild( h( 'h4', { text: t( 'Why they rank where they do' ) } ) );
		box.appendChild( App.table( [
			{ label: t( 'Factor' ), render: function ( f ) {
				return h( 'b', { dir: 'auto', text: f.factor } );
			} },
			{ label: t( 'Them' ), render: function ( f ) {
				return h( 'span', { dir: 'auto', class: 'sl-small', text: f.them } );
			} },
			{ label: t( 'Us' ), render: function ( f ) {
				return h( 'span', { dir: 'auto', class: 'sl-small', text: f.us } );
			} },
			{ label: t( 'Impact' ), render: function ( f ) {
				var i = IMPACT[ f.impact ] || [ f.impact, '' ];
				return SL.badge( i[ 0 ], i[ 1 ] );
			} },
			{ label: t( 'Basis' ), render: function ( f ) {
				return SL.badge( 'measured' === f.evidence ? t( 'Measured' ) : t( 'Hypothesis' ), 'measured' === f.evidence ? 'good' : '' );
			} },
		], r.factors || [] ) );
		box.appendChild( h( 'h4', { text: t( 'What to do, step by step' ) } ) );
		box.appendChild( h( 'ol', { class: 'sl-cq__steps' }, ( r.steps || [] ).map( function ( s ) {
			var p = PRIO[ s.priority ] || [ s.priority, '' ];
			return h( 'li', null, h( 'div', { class: 'sl-row' }, SL.badge( p[ 0 ], p[ 1 ] ), SL.badge( t( 'Effort: %s', t( s.effort ) ), '' ), h( 'b', { dir: 'auto', text: s.step } ) ), s.page ? link( s.page ) : null, h( 'p', { class: 'sl-muted sl-small', dir: 'auto', text: s.why } ) );
		} ) ) );
		box.appendChild( h( 'p', { class: 'sl-muted sl-small' }, t( 'These steps are also in the SEO roadmap (Competitor gaps).' ), ' ', h( 'a', { href: '#/actions', text: t( 'Open the roadmap' ) } ) ) );
		return box;
	}

	App.pages.competitor = function ( v, q ) {
		var id = parseInt( ( q && q.id ) || 0, 10 );
		var focus = q && q.q ? decodeURIComponent( q.q ) : '';
		var body = h( 'div' );
		var timer;
		v.appendChild( body );
		function load() {
			clearTimeout( timer );
			App.load( body, SL.api( 'competitors/' + id + '/intel' ), function ( r ) {
				var c = r.competitor || {};
				var ov = r.overview;
				var s = ov.stats;
				body.appendChild( App.header( c.name || t( 'Competitor' ), c.domain || '', [
					h( 'a', { class: 'sl-btn', href: '#/competitors', text: t( 'All competitors' ) } ),
					App.cap( 'manage' ) && r.budget.available ? App.abtn( SL.il( 'search', t( 'Check Google now' ), 14 ), function () {
						return SL.api( 'serp/track', { method: 'POST' } ).then( function () {
							SL.toast( t( 'Google is being checked for the tracked queries in the background.' ) );
						} );
					}, 'primary' ) : null,
					App.cap( 'ai' ) ? App.abtn( t( 'Discover their queries' ), function () {
						return SL.api( 'competitors/' + id + '/discover', { method: 'POST' } ).then( load );
					} ) : null,
				] ) );
				body.appendChild( h( 'div', { class: 'sl-kpis' },
					App.kpi( t( 'Queries where they appear' ), SL.num( s.queries, 0 ) ),
					App.kpi( t( 'Above us' ), SL.num( s.above_us, 0 ) ),
					App.kpi( t( 'Below us' ), SL.num( s.below_us, 0 ) ),
					App.kpi( t( 'Only them (we are not in top 20)' ), SL.num( s.only_them, 0 ) ),
					App.kpi( t( 'Their estimated clicks / month' ), SL.num( s.est_clicks, 0 ) )
				) );
				var tabs = [
					{ label: t( 'Queries' ), render: function () {
						return queriesTab( r );
					} },
					{ label: t( 'Estimated Search Console' ), render: estimateTab },
					{ label: t( 'Their pages' ), render: pagesTab },
				];
				var initial = 0;
				( r.analyses || [] ).forEach( function ( a ) {
					if ( focus && a.query === focus ) {
						initial = tabs.length;
					}
					tabs.push( { label: h( 'span', { class: 'sl-cq__tab', dir: 'auto' }, 'running' === a.status ? SL.spinner() : null, a.query ), render: function () {
						return analysisView( a );
					} } );
				} );
				body.appendChild( SL.tabs( tabs, null, initial ) );
				if ( ( r.analyses || [] ).some( function ( a ) {
					return 'running' === a.status;
				} ) || r.tracking ) {
					timer = setTimeout( function () {
						if ( d.body.contains( body ) ) {
							load();
						}
					}, 6000 );
				}
			} );
		}
		function queriesTab( r ) {
			var box = h( 'div', { class: 'sl-stack' } );
			if ( ! r.overview.queries.length ) {
				box.appendChild( SL.empty( t( 'No Google data for this competitor yet' ), r.budget.available ? t( 'Press “Check Google now”. The competitor appears here for every tracked query where it is in the top 20.' ) : t( 'Add a SerpApi key in Settings → Crawler & audits to search Google.' ) ) );
			} else {
				box.appendChild( App.table( [
					{ label: t( 'Query' ), render: function ( x ) {
						return h( 'b', { dir: 'auto', text: x.query } );
					} },
					{ label: t( 'Them' ), render: function ( x ) {
						return h( 'div', null, h( 'div', null, SL.badge( '#' + pos( x.their_position ), 'above_us' === x.status || 'only_them' === x.status ? 'bad' : 'good' ), ' ', trend( x.their_position, x.their_previous ) ), link( x.their_url ) );
					} },
					{ label: t( 'Us' ), render: function ( x ) {
						return h( 'div', null, h( 'div', null, x.our_position ? SL.badge( '#' + pos( x.our_position ), '' ) : SL.badge( t( 'not in top 20' ), 'warn' ), x.our_gsc ? h( 'span', { class: 'sl-muted sl-small', text: ' · ' + t( 'Search Console: %s', SL.num( x.our_gsc.position ) ) } ) : null ), link( x.our_url ) );
					} },
					{ label: t( 'Their est. clicks' ), render: function ( x ) {
						return null === x.their_est_clicks ? h( 'span', { class: 'sl-muted', text: t( 'no volume data' ) } ) : SL.num( x.their_est_clicks, 0 );
					} },
					{ label: '', render: function ( x ) {
						if ( x.analysis && 'done' === x.analysis.status ) {
							return h( 'a', { class: 'sl-btn sl-btn--sm', href: '#/competitor?id=' + id + '&q=' + encodeURIComponent( x.query ), onClick: function () {
								focus = x.query;
								setTimeout( load, 0 );
							}, text: t( 'Open analysis' ) } );
						}
						return App.cap( 'ai' ) ? App.abtn( t( 'Why? Analyze' ), function () {
							return SL.api( 'competitors/' + id + '/compare', { body: { query: x.query } } ).then( function () {
								focus = x.query;
								load();
							} );
						}, 'sm' ) : null;
					} },
				], r.overview.queries ) );
			}
			if ( r.candidates && r.candidates.length ) {
				var sel = {};
				box.appendChild( h( 'h4', { text: t( 'Queries their pages probably target (AI)' ) } ) );
				box.appendChild( h( 'div', { class: 'sl-chips' }, r.candidates.map( function ( c ) {
					return h( 'label', { class: 'sl-chip', dir: 'auto', title: c.page }, c.tracked ? h( 'span', { text: '✓ ' } ) : h( 'input', { type: 'checkbox', onChange: function ( e ) {
						sel[ c.query ] = e.target.checked;
					} } ), ' ' + c.query );
				} ) ) );
				box.appendChild( h( 'div', {}, App.abtn( t( 'Track selected and check Google' ), function () {
					var qs = Object.keys( sel ).filter( function ( k ) {
						return sel[ k ];
					} );
					return SL.api( 'serp/queries', { body: { queries: qs } } ).then( function () {
						return SL.api( 'serp/track', { body: { queries: qs } } );
					} ).then( function () {
						SL.toast( t( 'Google is being checked for the tracked queries in the background.' ) );
						load();
					} );
				}, 'primary' ) ) );
			}
			return box;
		}
		function estimateTab() {
			var box = h( 'div' );
			App.load( box, SL.api( 'competitors/' + id + '/estimate' ), function ( e ) {
				box.appendChild( h( 'div', { class: 'sl-alert sl-alert--info', text: e.method } ) );
				box.appendChild( h( 'div', { class: 'sl-kpis' }, App.kpi( t( 'Estimated clicks / month' ), SL.num( e.totals.est_clicks, 0 ) ), App.kpi( t( 'Queries found' ), SL.num( e.totals.queries, 0 ) ), App.kpi( t( 'With measured volume' ), SL.num( e.totals.with_volume, 0 ) ) ) );
				box.appendChild( h( 'h4', { text: t( 'Pages' ) } ) );
				box.appendChild( App.table( [
					{ label: t( 'Page' ), render: function ( p ) {
						return h( 'div', null, h( 'b', { dir: 'auto', text: p.title || '' } ), link( p.url ) );
					} },
					{ label: t( 'Queries' ), render: function ( p ) {
						return SL.chips( p.queries.map( function ( x ) {
							return x.query + ' #' + x.position;
						} ) );
					} },
					{ label: t( 'Est. impressions' ), render: function ( p ) {
						return SL.num( p.est_impressions, 0 );
					} },
					{ label: t( 'Est. clicks' ), render: function ( p ) {
						return SL.num( p.est_clicks, 0 );
					} },
				], e.pages ) );
				box.appendChild( h( 'h4', { text: t( 'Queries' ) } ) );
				box.appendChild( App.table( [
					{ label: t( 'Query' ), render: function ( x ) {
						return h( 'span', { dir: 'auto', text: x.query } );
					} },
					{ label: t( 'Position' ), render: function ( x ) {
						return pos( x.position );
					} },
					{ label: t( 'Est. impressions' ), render: function ( x ) {
						return null === x.est_impressions ? '—' : SL.num( x.est_impressions, 0 ) + ( 'minimum' === x.volume_kind ? '+' : '' );
					} },
					{ label: t( 'Est. clicks' ), render: function ( x ) {
						return null === x.est_clicks ? '—' : SL.num( x.est_clicks, 0 );
					} },
					{ label: t( 'Page' ), render: function ( x ) {
						return link( x.url );
					} },
				], e.queries ) );
			} );
			return box;
		}
		function pagesTab() {
			var box = h( 'div' );
			App.load( box, SL.api( 'competitors/' + id + '/pages' ), function ( r ) {
				var rows = r.items || r;
				box.appendChild( App.table( [
					{ label: t( 'Page' ), render: function ( p ) {
						return h( 'div', null, h( 'b', { dir: 'auto', text: p.title || '' } ), link( p.url ) );
					} },
					{ label: t( 'Words' ), render: function ( p ) {
						return SL.num( p.word_count, 0 );
					} },
					{ label: t( 'Schema' ), render: function ( p ) {
						return p.schema_types || '—';
					} },
					{ label: t( 'Updated' ), render: function ( p ) {
						return p.lastmod ? SL.date( p.lastmod ) : '—';
					} },
				], rows, { empty: t( 'No pages crawled yet' ), emptyText: t( 'Use “Analyze now” on the competitors page to read their sitemap and pages.' ) } ) );
			} );
			return box;
		}
		load();
	};
}( window, document ) );
