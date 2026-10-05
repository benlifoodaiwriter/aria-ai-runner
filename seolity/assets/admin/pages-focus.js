/**
 * SEOlity admin page: Rank Math & focus keywords — GSC-driven focus keyword
 * autopilot (cannibalization-safe, with approval & undo), Rank Math score
 * triage by search demand, redirection import and pillar sync.
 */
( function ( w ) {
	'use strict';
	var SL = w.SL;
	var App = SL.app;
	var C = SL.config || {};
	var t = SL.t;
	var h = SL.h;

	function engineName( e ) {
		return { rankmath: 'Rank Math', yoast: 'Yoast SEO', seopress: 'SEOPress', aioseo: 'All in One SEO', seolity: 'SEOlity' }[ e ] || e;
	}
	function editLink( id ) {
		return ( C.adminUrl || '' ) + 'post.php?post=' + id + '&action=edit';
	}
	function scoreBadge( s ) {
		if ( s === null || s === undefined ) {
			return '—';
		}
		return SL.badge( String( s ), s >= 80 ? 'good' : s >= 50 ? 'warn' : 'bad' );
	}

	App.pages.rankmath = function ( v ) {
		v.appendChild( App.header( t( 'Rank Math & focus keywords' ), t( 'Focus keywords chosen from the queries Google already shows each page for — written straight into Rank Math (or your SEO plugin), cannibalization-safe, with approval and undo.' ) ) );
		var body = h( 'div' );
		v.appendChild( body );
		function load() {
			App.load( body, SL.api( 'rankmath' ), render );
		}
		function render( r ) {
			var cov = r.coverage || {};
			// Integration status.
			if ( r.active ) {
				body.appendChild( h( 'div', { class: 'sl-kpis' },
					App.kpi( t( 'Rank Math' ), r.version || '—', null, { icon: 'shield', tone: 'violet', sub: r.pro ? 'PRO' : t( 'Free' ) } ),
					App.kpi( t( 'Average Rank Math score' ), r.scores && r.scores.avg !== null ? String( r.scores.avg ) : '—', null, { icon: 'target', sub: t( '%s pages scored', SL.num( r.scores ? r.scores.scored : 0, 0 ) ) } ),
					App.kpi( t( 'Pages with focus keywords' ), SL.num( cov.with_keyword, 0 ) + ' / ' + SL.num( cov.total, 0 ), null, { icon: 'key', tone: 'green', sub: t( '%s without', SL.num( cov.without, 0 ) ) } ),
					App.kpi( t( 'Pillar content' ), SL.num( r.pillars, 0 ), null, { icon: 'layers', tone: 'amber', sub: t( 'Low score (<50): %s', SL.num( r.scores ? r.scores.low : 0, 0 ) ) } )
				) );
			} else {
				body.appendChild( h( 'div', { class: 'sl-alert sl-alert--info sl-iconline' }, SL.icon( 'info', 18 ), h( 'span', { text: t( 'Rank Math is not active. SEOlity saves focus keywords to %s and everything below still works.', engineName( r.engine ) ) } ) ) );
				body.appendChild( h( 'div', { class: 'sl-kpis' },
					App.kpi( t( 'Pages with focus keywords' ), SL.num( cov.with_keyword, 0 ) + ' / ' + SL.num( cov.total, 0 ), null, { icon: 'key', tone: 'green', sub: t( '%s without', SL.num( cov.without, 0 ) ) } ),
					App.kpi( t( 'SEO plugin' ), engineName( r.engine ), null, { icon: 'shield' } )
				) );
			}

			// Autopilot.
			var onlyEmpty = true;
			var minImpr = 30;
			var limit = 200;
			var out = h( 'div' );
			var autopilot = h( 'div', { class: 'sl-stack' },
				h( 'p', { class: 'sl-muted', text: t( 'For every page with search demand SEOlity picks a primary keyword the page already wins in Google and up to 4 secondary keywords. A query is never assigned to two pages: it belongs to the URL Google prefers. Every change goes to “AI Changes” for approval (or applies itself in Semi/Autonomous mode) and can be undone.' ) } ),
				h( 'div', { class: 'sl-form' },
					App.toggle( t( 'Only pages without a focus keyword' ), onlyEmpty, function ( x ) {
						onlyEmpty = x;
					}, t( 'Turn off to also re-check pages whose keyword gets no impressions or belongs to another page.' ) ),
					App.field( t( 'Minimum impressions (90 days)' ), App.select( [ [ 10, '10' ], [ 30, '30' ], [ 100, '100' ], [ 300, '300' ], [ 1000, '1000' ] ], minImpr, function ( x ) {
						minImpr = +x;
					} ) ),
					App.field( t( 'Pages per run' ), App.select( [ [ 50, '50' ], [ 200, '200' ], [ 500, '500' ] ], limit, function ( x ) {
						limit = +x;
					} ) )
				),
				cov.has_gsc ? h( 'div', { class: 'sl-row' },
					App.abtn( SL.il( 'eye', t( 'Preview' ) ), function () {
						return run( true );
					} ),
					App.cap( 'apply' ) ? App.abtn( SL.il( 'sparkles', t( 'Assign focus keywords' ) ), function () {
						return run( false );
					}, 'primary' ) : null
				) : h( 'div', { class: 'sl-alert sl-alert--warn sl-iconline' }, SL.icon( 'alert', 18 ), h( 'span', { text: t( 'Connect Google Search Console first: keywords are chosen from real search data.' ) + ' ' } ), App.link( t( 'Open the connection guide' ), 'guide?service=gsc' ) ),
				out
			);
			function run( dry ) {
				return SL.api( 'focus/bulk', { body: { only_empty: onlyEmpty, min_impressions: minImpr, limit: limit, dry_run: dry } } ).then( function ( res ) {
					SL.clear( out );
					if ( ! dry ) {
						SL.toast( t( '%1$s changes proposed, %2$s applied.', res.proposed, res.applied ), 'success' );
						out.appendChild( h( 'div', { class: 'sl-alert sl-alert--success sl-iconline' }, SL.icon( 'check', 18 ), h( 'span', { text: t( '%1$s changes proposed, %2$s applied.', res.proposed, res.applied ) + ' ' } ), res.proposed ? App.link( t( 'Review now' ), 'changes' ) : null ) );
					}
					out.appendChild( App.table( [
						{ label: t( 'Page' ), render: function ( x ) {
							return h( 'div', null, h( 'a', { href: editLink( x.object_id ), class: 'sl-link', dir: 'auto', text: x.title || SL.path( x.url ) } ), h( 'div', { class: 'sl-muted sl-small', dir: 'ltr', text: SL.path( x.url ) } ) );
						} },
						{ label: t( 'Impressions' ), render: function ( x ) {
							return SL.num( x.impressions, 0 );
						} },
						{ label: t( 'Before' ), render: function ( x ) {
							return h( 'span', { class: 'sl-muted', dir: 'auto', text: x.before || '—' } );
						} },
						{ label: t( 'After' ), render: function ( x ) {
							var k = x.after.split( ', ' );
							return h( 'div', { dir: 'auto' }, h( 'strong', { text: k[ 0 ] } ), k.length > 1 ? h( 'div', { class: 'sl-small sl-muted', text: k.slice( 1 ).join( '، ' ) } ) : null );
						} },
						dry ? null : { label: t( 'Status' ), render: function ( x ) {
							return x.status ? SL.badge( t( x.status ), x.status === 'applied' ? 'good' : 'warn' ) : '—';
						} },
					], res.items, { empty: t( 'Nothing to assign' ), emptyText: t( 'Every page with search demand already has a focus keyword.' ) } ) );
				} );
			}
			body.appendChild( App.card( t( 'Focus keyword autopilot (Search Console)' ), autopilot, { icon: 'sparkles', sub: t( 'Beyond Rank Math Content AI: uses the keywords you already rank for.' ) } ) );

			// High-demand pages without keywords.
			var kwBtn = function ( id ) {
				return App.btn( SL.il( 'key', t( 'GSC keywords' ), 15 ), function () {
					SL.keywordInsights( 'post', id, { onApply: load } );
				}, 'sm' );
			};
			if ( cov.demand_no_kw && cov.demand_no_kw.length ) {
				body.appendChild( App.card( t( 'High-demand pages without a focus keyword' ), App.table( [
					{ label: t( 'Page' ), render: function ( x ) {
						return h( 'a', { href: editLink( x.object_id ), class: 'sl-link', dir: 'auto', text: x.title || SL.path( x.url ) } );
					} },
					{ label: t( 'Impressions' ), render: function ( x ) {
						return SL.num( x.impressions, 0 );
					} },
					{ label: t( 'Clicks' ), render: function ( x ) {
						return SL.num( x.clicks, 0 );
					} },
					{ label: t( 'Avg. position' ), render: function ( x ) {
						return SL.num( x.position );
					} },
					{ label: '', render: function ( x ) {
						return kwBtn( x.object_id );
					} },
				], cov.demand_no_kw ), { icon: 'alert' } ) );
			}

			// Rank Math low scores ordered by demand.
			if ( r.active ) {
				body.appendChild( App.card( t( 'Low Rank Math score, highest search demand' ), App.table( [
					{ label: t( 'Page' ), render: function ( x ) {
						return h( 'div', null, h( 'a', { href: editLink( x.object_id ), class: 'sl-link', dir: 'auto', text: x.title } ), h( 'div', { class: 'sl-muted sl-small', dir: 'auto', text: x.keywords.length ? x.keywords.join( '، ' ) : t( 'No focus keyword' ) } ) );
					} },
					{ label: t( 'Rank Math score' ), render: function ( x ) {
						return scoreBadge( x.score );
					} },
					{ label: t( 'Impressions' ), render: function ( x ) {
						return x.impressions === null ? '—' : SL.num( x.impressions, 0 );
					} },
					{ label: t( 'Avg. position' ), render: function ( x ) {
						return x.position ? SL.num( x.position ) : '—';
					} },
					{ label: '', render: function ( x ) {
						return h( 'div', { class: 'sl-row' }, kwBtn( x.object_id ), App.cap( 'ai' ) ? App.btn( SL.il( 'sparkles', t( 'Optimize' ), 15 ), function () {
							SL.optimizer( { object_type: 'post', object_id: x.object_id, field: 'post_content', focus_keyword: x.keywords[ 0 ] || '' } );
						}, 'sm' ) : null );
					} },
				], r.low_pages, { empty: t( 'No low scores' ), emptyText: t( 'Rank Math scores are calculated when a post is opened and saved in the editor.' ) } ), { icon: 'trending-down', sub: t( 'Fix the pages Google already shows first — that is where a better score pays off.' ) } ) );
			}

			// Tools.
			var tools = h( 'div', { class: 'sl-stack' } );
			if ( r.active && r.redirections !== null && App.cap( 'manage' ) ) {
				tools.appendChild( h( 'div', { class: 'sl-row sl-row--between' }, h( 'div', null, h( 'strong', { text: t( 'Import Rank Math redirections' ) } ), h( 'div', { class: 'sl-small sl-muted', text: t( '%s active redirections in Rank Math. Exact, regex, contains, starts-with and ends-with rules are converted; Rank Math’s own table is not changed.', SL.num( r.redirections, 0 ) ) } ) ), App.abtn( SL.il( 'download', t( 'Import' ) ), function () {
					return SL.api( 'rankmath/import-redirects', { method: 'POST' } ).then( function ( res ) {
						SL.toast( t( '%1$s imported, %2$s skipped.', res.imported, res.skipped ), 'success' );
					} );
				} ) ) );
			}
			if ( App.cap( 'apply' ) ) {
				tools.appendChild( h( 'div', { class: 'sl-row sl-row--between' }, h( 'div', null, h( 'strong', { text: r.active ? t( 'Mark cluster pillars as Rank Math Pillar Content' ) : t( 'Mark cluster pillars as pillar content' ) } ), h( 'div', { class: 'sl-small sl-muted', text: t( 'Uses the topic clusters from Keywords → Topic clusters. Rank Math then suggests these pages for internal links.' ) } ) ), App.abtn( SL.il( 'layers', t( 'Sync pillars' ) ), function () {
					return SL.api( 'rankmath/sync-pillars', { method: 'POST' } ).then( function ( res ) {
						SL.toast( t( '%s changes proposed.', res.proposed ), 'success' );
					} );
				} ) ) );
			}
			body.appendChild( App.card( t( 'Tools' ), tools, { icon: 'wrench' } ) );

			// What SEOlity adds.
			body.appendChild( App.card( t( 'How SEOlity works with Rank Math' ), h( 'ul', { class: 'sl-checklist' }, [
				t( 'Writes Rank Math’s own fields: SEO title, description, all focus keywords, pillar flag, social title/description, robots and canonical.' ),
				t( 'Keeps the Rank Math editor in sync: changes made by SEOlity in Gutenberg or the classic editor appear live in the Rank Math sidebar and are not overwritten on save.' ),
				t( 'Chooses keywords from Search Console data (real impressions, clicks and positions), not from guesses — and never assigns one query to two pages.' ),
				t( 'Shows per query whether it appears in the title, description, headings and content, which queries are missing, questions for FAQ and striking-distance queries.' ),
				t( 'Every change is logged with before/after, approval, one-click undo and a measured SEO experiment.' ),
				t( 'Works with any AI: API keys, OpenRouter, local Ollama or your Claude / ChatGPT subscription.' ),
			].map( function ( x ) {
				return h( 'li', { class: 'sl-iconline' }, SL.icon( 'check', 16, 'is-good' ), h( 'span', { text: x } ) );
			} ) ), { icon: 'info' } ) );
		}
		load();
	};
} )( window );
