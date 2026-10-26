/**
 * SEOlity admin pages: Competitors, Experiments, Redirects, 404 Monitor,
 * Schema, Sitemap, Robots.txt, Reports, Alerts.
 */
( function ( w ) {
	'use strict';
	var SL = w.SL;
	var App = SL.app;
	var t = SL.t;
	var h = SL.h;
	var C = SL.config;

	/* ================================================================ Competitors */
	App.pages.competitors = function ( v ) {
		v.appendChild( App.header( t( 'Competitor Intelligence' ), t( 'SEOlity monitors competitor sitemaps and pages (politely, respecting robots.txt), tracks new content and finds content gaps.' ) ) );
		if ( App.cap( 'manage' ) ) {
			var name = h( 'input', { class: 'sl-input', placeholder: t( 'Name' ) } );
			var domain = h( 'input', { class: 'sl-input', placeholder: 'competitor.com', dir: 'ltr' } );
			var freq = App.select( [ [ 'daily', t( 'Daily' ) ], [ 'weekly', t( 'Weekly' ) ], [ 'monthly', t( 'Monthly' ) ] ], 'weekly' );
			v.appendChild( App.card( t( 'Add competitor' ), h( 'div', { class: 'sl-toolbar' }, name, domain, freq, App.abtn( t( 'Add' ), function () {
				return SL.api( 'competitors', { body: { name: name.value, domain: domain.value, frequency: freq.value } } ).then( function () {
					SL.toast( t( 'Competitor added. First analysis is running in the background.' ), 'success' );
					App.pages.competitors( SL.clear( v ) );
				} );
			}, 'primary' ) ) ) );
		}
		var list = h( 'div' );
		v.appendChild( list );
		App.load( list, SL.api( 'competitors' ), function ( rows ) {
			if ( ! rows.length ) {
				list.appendChild( SL.empty( t( 'No competitors yet' ), t( 'Add 2–5 real SEO competitors (sites that rank for your keywords).' ) ) );
				return;
			}
			rows.forEach( function ( c ) {
				var s = c.summary || {};
				var ai = s.ai;
				var stats = s.stats || {};
				var body = h( 'div', { class: 'sl-stack' },
					h( 'div', { class: 'sl-kpis sl-kpis--sm' }, App.kpi( t( 'Pages analyzed' ), SL.num( c.crawled, 0 ), null, { sub: t( '%s known', SL.num( c.known, 0 ) ) } ), App.kpi( t( 'New pages' ), SL.num( s.new_pages || 0, 0 ) ), App.kpi( t( 'Avg. words' ), SL.num( stats.avg_words || 0, 0 ) ), App.kpi( t( 'Pages with FAQ' ), SL.num( stats.pages_with_faq || 0, 0 ) ) ),
					ai ? h( 'div', { class: 'sl-competitor-ai' },
						h( 'p', { text: ai.summary } ),
						h( 'div', { class: 'sl-grid-2' },
							h( 'div', null, h( 'h4', { text: t( 'Where they are stronger' ) } ), h( 'ul', null, ai.strengths.map( function ( x ) {
								return h( 'li', null, h( 'strong', { text: x.area + ': ' } ), x.insight, h( 'div', { class: 'sl-muted sl-small', text: x.evidence } ) );
							} ) ) ),
							h( 'div', null, h( 'h4', { text: t( 'Opportunities for you' ) } ), h( 'ul', null, ai.opportunities.map( function ( x ) {
								return h( 'li', { text: x } );
							} ) ), h( 'h4', { text: t( 'Threats' ) } ), h( 'ul', null, ai.threats.map( function ( x ) {
								return h( 'li', { text: x } );
							} ) ) )
						),
						ai.actions && ai.actions.length ? h( 'div', null, h( 'h4', { text: t( 'Recommended actions' ) } ), h( 'ol', null, ai.actions.map( function ( a ) {
							return h( 'li', null, SL.dot( a.priority ), ' ', h( 'strong', { text: a.title } ), h( 'div', { class: 'sl-muted sl-small', text: a.reason } ) );
						} ) ) ) : null
					) : h( 'p', { class: 'sl-muted', text: t( 'AI summary will appear after the analysis finishes (requires an AI provider).' ) } ),
					stats.sections ? h( 'div', null, h( 'h4', { text: t( 'Content sections' ) } ), SL.charts.bars( Object.keys( stats.sections ).slice( 0, 8 ).map( function ( k ) {
						return { label: k, value: stats.sections[ k ] };
					} ) ) ) : null,
					s.gap && s.gap.opportunities ? gapTable( s.gap ) : null
				);
				list.appendChild( App.card( c.name + ' · ' + c.domain, body, { actions: [
					h( 'span', { class: 'sl-muted sl-small', text: t( 'Last analyzed' ) + ': ' + SL.date( c.last_analyzed ) } ),
					App.btn( t( 'Pages' ), function () {
						pagesModal( c );
					}, 'sm' ),
					App.cap( 'ai' ) ? App.abtn( t( 'Content gap' ), function () {
						return SL.api( 'competitors/' + c.id + '/gap', { method: 'POST' } ).then( function () {
							App.pages.competitors( SL.clear( v ) );
						} );
					}, 'sm' ) : null,
					App.cap( 'manage' ) ? App.abtn( t( 'Analyze now' ), function () {
						return SL.api( 'competitors/' + c.id + '/analyze', { method: 'POST' } ).then( function () {
							SL.toast( t( 'Analysis queued.' ) );
						} );
					}, 'sm' ) : null,
					App.cap( 'manage' ) ? App.btn( SL.icon( 'trash', 16 ), function () {
						SL.confirm( t( 'Remove this competitor and its data?' ), function () {
							SL.api( 'competitors/' + c.id, { method: 'DELETE' } ).then( function () {
								App.pages.competitors( SL.clear( v ) );
							} ).catch( SL.fail );
						}, true );
					}, 'ghost', { 'aria-label': t( 'Delete' ) } ) : null,
				] } ) );
			} );
		} );

		function gapTable( gap ) {
			return h( 'div', null, h( 'h4', { text: t( 'Content gap opportunities' ) } ), h( 'p', { class: 'sl-small', text: gap.summary } ), App.table( [
				{ label: t( 'Keyword' ), render: function ( o ) {
					return h( 'div', null, h( 'strong', { dir: 'auto', text: o.keyword } ), h( 'div', { class: 'sl-muted sl-small', text: t( o.type ) + ' · ' + t( o.intent ) } ) );
				} },
				{ label: t( 'Suggested page' ), render: function ( o ) {
					return h( 'div', null, h( 'div', { dir: 'auto', text: o.suggested_title } ), h( 'code', { dir: 'ltr', class: 'sl-small', text: o.suggested_url } ) );
				} },
				{ label: t( 'Words' ), render: function ( o ) {
					return SL.num( o.recommended_word_count, 0 );
				} },
				{ label: t( 'Priority' ), render: function ( o ) {
					return SL.priorityBadge( o.priority );
				} },
				{ label: t( 'Brief' ), render: function ( o ) {
					return h( 'details', null, h( 'summary', { text: t( 'Outline' ) } ), h( 'p', { class: 'sl-small', text: 'H1: ' + o.suggested_h1 } ), h( 'ul', { class: 'sl-small' }, o.outline.map( function ( x ) {
						return h( 'li', { text: x } );
					} ) ), SL.chips( o.supporting_keywords ), h( 'p', { class: 'sl-small sl-muted', text: o.reason } ) );
				} },
			], gap.opportunities ) );
		}

		function pagesModal( c ) {
			var m = SL.modal( { title: c.name + ' — ' + t( 'Pages' ), wide: true } );
			var s = h( 'input', { class: 'sl-input', type: 'search', placeholder: t( 'Filter…' ) } );
			var list2 = h( 'div' );
			var load = function () {
				App.load( list2, SL.api( 'competitors/' + c.id + '/pages', { query: { search: s.value } } ), function ( rows ) {
					list2.appendChild( App.table( [
						{ label: t( 'Page' ), render: function ( p ) {
							return h( 'div', null, App.ext( p.url, p.title ), h( 'div', { class: 'sl-muted sl-small sl-clip', dir: 'auto', text: p.meta_description || '' } ) );
						} },
						{ label: t( 'Words' ), render: function ( p ) {
							return SL.num( p.word_count, 0 );
						} },
						{ label: t( 'Schema' ), render: function ( p ) {
							return SL.chips( ( p.schema_types || '' ).split( ',' ).filter( Boolean ) );
						} },
						{ label: 'FAQ', key: 'faq_count' },
						{ label: t( 'First seen' ), render: function ( p ) {
							return SL.date( p.first_seen );
						} },
					], rows ) );
				} );
			};
			s.addEventListener( 'change', load );
			m.body.appendChild( s );
			m.body.appendChild( list2 );
			load();
		}
	};

	/* ================================================================ Experiments */
	App.pages.experiments = function ( v ) {
		v.appendChild( App.header( t( 'SEO Experiments' ), t( 'Every applied change becomes an experiment: equal before/after windows, adjusted by the site-wide trend (control), judged Positive / Negative / Neutral / Inconclusive.' ), App.cap( 'manage' ) ? [ App.abtn( t( 'Evaluate now' ), function () {
			return SL.api( 'experiments/evaluate', { method: 'POST' } ).then( function () {
				SL.toast( t( 'Evaluation queued.' ) );
			} );
		} ) ] : null ) );
		var body = h( 'div' );
		v.appendChild( body );
		var outcomeKind = { positive: 'good', negative: 'bad', neutral: 'default', inconclusive: 'warn' };
		function load() {
			App.load( body, SL.api( 'experiments' ), function ( r ) {
				var s = r.stats;
				body.appendChild( h( 'div', { class: 'sl-kpis' }, App.kpi( t( 'Running' ), SL.num( s.running || 0, 0 ) ), App.kpi( t( 'Positive' ), SL.num( s.positive || 0, 0 ) ), App.kpi( t( 'Negative' ), SL.num( s.negative || 0, 0 ) ), App.kpi( t( 'Neutral' ), SL.num( s.neutral || 0, 0 ) ), App.kpi( t( 'Inconclusive' ), SL.num( s.inconclusive || 0, 0 ) ) ) );
				body.appendChild( App.card( t( 'Site-Specific SEO Intelligence' ), r.insights.length ? h( 'ul', { class: 'sl-insights' }, r.insights.map( function ( i ) {
					return h( 'li', null, h( 'span', { class: i.avg_delta > 0 ? 'is-up' : 'is-down' }, SL.icon( i.avg_delta > 0 ? 'arrow-up' : 'arrow-down', 14 ) ), ' ', i.text, ' ', SL.badge( t( 'win rate' ) + ' ' + i.win_rate + '%' ) );
				} ) ) : SL.empty( t( 'Learning in progress' ), t( 'After a few experiments are evaluated, SEOlity learns which changes work best on your site and uses it in every AI suggestion.' ) ) ) );
				body.appendChild( App.table( [
					{ label: t( 'Experiment' ), render: function ( e ) {
						return h( 'div', null, h( 'strong', { text: '#' + e.id + ' ' + e.name } ), h( 'div', { class: 'sl-muted sl-small' }, e.url ? App.ext( e.url ) : '' ) );
					} },
					{ label: t( 'Change' ), render: function ( e ) {
						return SL.chips( e.change_type.split( '+' ).map( SL.fieldLabel ) );
					} },
					{ label: t( 'Before → After' ), render: function ( e ) {
						var b = e.metrics_before && e.metrics_before.page;
						var a = e.metrics_after && e.metrics_after.page;
						if ( ! b || ! a ) {
							return h( 'span', { class: 'sl-muted', text: t( 'Window: %s days', e.window_days ) } );
						}
						var dl = e.metrics_after.deltas;
						return h( 'div', { class: 'sl-small' },
							h( 'div', null, t( 'Position' ) + ': ' + SL.num( b.position ) + ' → ' + SL.num( a.position ) ),
							h( 'div', null, t( 'CTR' ) + ': ', h( 'span', { class: dl.ctr >= 0 ? 'is-up' : 'is-down', text: SL.pct( dl.ctr ) } ) ),
							h( 'div', null, t( 'Clicks' ) + ': ', h( 'span', { class: dl.clicks >= 0 ? 'is-up' : 'is-down', text: SL.pct( dl.clicks ) } ) ),
							h( 'div', null, t( 'Impressions' ) + ': ', h( 'span', { class: dl.impressions >= 0 ? 'is-up' : 'is-down', text: SL.pct( dl.impressions ) } ) )
						);
					} },
					{ label: t( 'Outcome' ), render: function ( e ) {
						return e.outcome ? h( 'div', null, SL.badge( t( e.outcome ), outcomeKind[ e.outcome ] ), h( 'div', { class: 'sl-muted sl-small', text: t( 'Confidence' ) + ' ' + e.confidence + '%' } ) ) : SL.badge( t( e.status ) );
					} },
					{ label: t( 'Reason' ), render: function ( e ) {
						return h( 'span', { class: 'sl-small', text: e.reason || '' } );
					} },
					{ label: t( 'Started' ), render: function ( e ) {
						return SL.date( e.started_at );
					} },
					{ label: '', render: function ( e ) {
						return App.cap( 'apply' ) && ( e.status === 'evaluated' || e.status === 'running' ) ? App.btn( t( 'Revert' ), function () {
							SL.confirm( t( 'Revert all changes made in this experiment?' ), function () {
								SL.api( 'experiments/' + e.id + '/revert', { method: 'POST' } ).then( function () {
									SL.toast( t( 'Reverted.' ), 'success' );
									load();
								} ).catch( SL.fail );
							}, true );
						}, 'sm' ) : null;
					} },
				], r.items, { empty: t( 'No experiments yet' ), emptyText: t( 'Apply an AI change to a page that gets search traffic — SEOlity measures the result automatically.' ) } ) );
			} );
		}
		load();
	};

	/* ================================================================ Redirects */
	App.pages.redirects = function ( v ) {
		v.appendChild( App.header( t( 'Redirect Manager' ), t( '301 / 302 / 307 / 410 / 451, regex, bulk import/export, chain & loop detection, hit tracking.' ), [ h( 'a', { class: 'sl-btn', href: C.exportUrl + '&what=redirects' }, t( 'Export CSV' ) ) ] ) );
		var f = { search: '', page: 1, per_page: 50 };
		if ( App.cap( 'manage' ) ) {
			var src = h( 'input', { class: 'sl-input', placeholder: '/old-url', dir: 'ltr' } );
			var dst = h( 'input', { class: 'sl-input', placeholder: '/new-url ' + t( 'or' ) + ' https://…', dir: 'ltr' } );
			var type = App.select( [ [ 301, '301 ' + t( 'Permanent' ) ], [ 302, '302 ' + t( 'Temporary' ) ], [ 307, '307' ], [ 308, '308' ], [ 410, '410 ' + t( 'Gone' ) ], [ 451, '451' ] ], 301 );
			var regex = h( 'input', { type: 'checkbox' } );
			v.appendChild( App.card( t( 'Add redirect' ), h( 'div', { class: 'sl-toolbar' }, src, dst, type, h( 'label', { class: 'sl-check' }, regex, t( 'Regex' ) ), App.abtn( t( 'Add' ), function () {
				return SL.api( 'redirects', { body: { source: src.value, target: dst.value, type: type.value, is_regex: regex.checked } } ).then( function () {
					src.value = '';
					dst.value = '';
					load();
				} );
			}, 'primary' ) ), { actions: [ App.btn( t( 'Bulk import' ), function () {
				var m = SL.modal( { title: t( 'Bulk import (CSV)' ) } );
				var ta = h( 'textarea', { class: 'sl-input', rows: 10, dir: 'ltr', placeholder: 'source,target,type,regex\n/old,/new,301,0' } );
				m.body.appendChild( ta );
				m.foot.appendChild( App.abtn( t( 'Import' ), function () {
					return SL.api( 'redirects/import', { body: { csv: ta.value } } ).then( function ( r ) {
						SL.toast( t( '%s redirects imported.', r.created ), 'success' );
						if ( r.errors.length ) {
							SL.toast( t( '%s lines had errors.', r.errors.length ), 'error' );
						}
						m.close();
						load();
					} );
				}, 'primary' ) );
			}, 'sm' ) ] } ) );
		}
		var s = h( 'input', { class: 'sl-input', type: 'search', placeholder: t( 'Search redirects…' ) } );
		s.addEventListener( 'change', function () {
			f.search = s.value;
			load();
		} );
		v.appendChild( h( 'div', { class: 'sl-toolbar' }, s ) );
		var list = h( 'div' );
		v.appendChild( list );
		function load() {
			App.load( list, SL.api( 'redirects', { query: f } ), function ( r ) {
				if ( r.chains.length ) {
					list.appendChild( h( 'div', { class: 'sl-alert sl-alert--warn' }, h( 'strong', { text: t( '%s redirect chains/loops detected.', r.chains.length ) } ), h( 'ul', null, r.chains.slice( 0, 5 ).map( function ( c ) {
						return h( 'li', { dir: 'ltr', class: 'sl-iconline' }, c.loop ? SL.icon( 'refresh', 14 ) : null, h( 'span', { text: c.chain.join( ' → ' ) } ) );
					} ) ), App.cap( 'manage' ) ? App.abtn( t( 'Flatten chains' ), function () {
						return SL.api( 'redirects/fix-chains', { method: 'POST' } ).then( function ( x ) {
							SL.toast( t( '%s redirect chains fixed.', x.fixed ), 'success' );
							load();
						} );
					} ) : null ) );
				}
				list.appendChild( App.table( [
					{ label: t( 'From' ), render: function ( x ) {
						return h( 'code', { dir: 'ltr', text: ( +x.is_regex ? '~ ' : '' ) + x.source } );
					} },
					{ label: t( 'To' ), render: function ( x ) {
						return x.target ? h( 'code', { dir: 'ltr', text: SL.path( x.target ) } ) : '—';
					} },
					{ label: t( 'Type' ), key: 'type' },
					{ label: t( 'Hits' ), render: function ( x ) {
						return SL.num( x.hits, 0 );
					} },
					{ label: t( 'Last used' ), render: function ( x ) {
						return SL.date( x.last_hit );
					} },
					{ label: t( 'Origin' ), render: function ( x ) {
						return t( x.origin );
					} },
					{ label: t( 'Status' ), render: function ( x ) {
						if ( ! App.cap( 'manage' ) ) {
							return t( x.status );
						}
						var cb = h( 'input', { type: 'checkbox', checked: x.status === 'active', 'aria-label': t( 'Active' ), onChange: function () {
							SL.api( 'redirects/' + x.id, { method: 'PUT', body: { status: cb.checked ? 'active' : 'disabled' } } ).catch( SL.fail );
						} } );
						return cb;
					} },
					{ label: '', render: function ( x ) {
						return App.cap( 'manage' ) ? App.btn( SL.icon( 'trash', 16 ), function () {
							SL.confirm( t( 'Delete this redirect?' ), function () {
								SL.api( 'redirects/' + x.id, { method: 'DELETE' } ).then( load ).catch( SL.fail );
							}, true );
						}, 'ghost', { 'aria-label': t( 'Delete' ) } ) : null;
					} },
				], r.items, { empty: t( 'No redirects yet' ) } ) );
				SL.append( list, App.pager( r.total, f.page, f.per_page, function ( p ) {
					f.page = p;
					load();
				} ) );
			} );
		}
		load();
	};

	/* ================================================================ 404 Monitor */
	App.pages[ '404' ] = function ( v ) {
		v.appendChild( App.header( t( '404 Monitor' ), t( 'Every 404 is logged with referrer and hits; SEOlity suggests the best redirect target (slug similarity + AI).' ), App.cap( 'ai' ) ? [ App.abtn( SL.il( 'wand', t( 'AI review: 301, 410 or leave?' ), 15 ), function () {
			var m = SL.modal( { title: t( 'AI review of 404 errors' ), wide: true, onClose: load } );
			var instr = h( 'textarea', { class: 'sl-input', rows: 2, dir: 'auto', placeholder: t( 'Optional instructions, e.g. “old product URLs go to their category”, “never redirect to the home page”.' ) } );
			var box = h( 'div' );
			m.body.appendChild( h( 'p', { class: 'sl-muted', text: t( 'The AI judges every open 404 from real evidence — Search Console clicks and impressions, internal links still pointing to it, external referrers, human or Googlebot requests, deleted posts and how close each candidate page is — and decides: 301 to an equivalent page (to keep its rankings), 410 when it is gone for good, or leave it as 404 when it has no value. It also tells you honestly whether these 404s cost crawl budget on a site of your size. Nothing changes until you approve.' ) } ) );
			m.body.appendChild( instr );
			m.body.appendChild( box );
			box.appendChild( h( 'div', { class: 'sl-row' }, App.abtn( t( 'Start the review' ), function () {
				return SL.api( '404/triage', { body: { instructions: instr.value } } ).then( function ( op ) {
					instr.remove();
					App.operationView( box, op, load );
				} );
			}, 'primary' ) ) );
			return Promise.resolve();
		}, 'primary' ), App.abtn( t( 'Suggest targets' ), function () {
			return SL.api( '404/suggest', { method: 'POST' } ).then( function () {
				SL.toast( t( 'Computing suggestions in the background.' ) );
			} );
		} ) ] : null ) );
		var f = { status: 'open', search: '', page: 1, per_page: 50 };
		var body = h( 'div' );
		v.appendChild( body );
		function load() {
			App.load( body, SL.api( '404', { query: f } ), function ( r ) {
				body.appendChild( h( 'div', { class: 'sl-kpis' }, App.kpi( t( 'Open 404s' ), SL.num( r.stats.open, 0 ) ), App.kpi( t( 'Hits (7 days)' ), SL.num( r.stats.hits_7d, 0 ) ), App.kpi( t( 'New (24h)' ), SL.num( r.stats.new_24h, 0 ) ), App.kpi( t( 'With referrer' ), SL.num( r.stats.with_referrer, 0 ) ) ) );
				// Bulk: each selected URL goes to its own (editable) target.
				var sel = {};
				var targets = {};
				var boxes = [];
				var bulkOn = 'open' === f.status && App.cap( 'manage' );
				var counter = h( 'span', { class: 'sl-muted sl-small', text: '' } );
				function count() {
					var n = Object.keys( sel ).filter( function ( k ) {
						return sel[ k ];
					} ).length;
					counter.textContent = n ? t( '%s selected', n ) : '';
					bulkBtn.disabled = ! n;
				}
				var bulkBtn = App.abtn( SL.il( 'corner', t( 'Redirect selected to their own targets' ), 15 ), function () {
					var items = Object.keys( sel ).filter( function ( k ) {
						return sel[ k ];
					} ).map( function ( k ) {
						return { id: Number( k ), target: targets[ k ] ? targets[ k ].value.trim() : '' };
					} );
					return SL.api( '404/bulk-redirect', { body: { items: items } } ).then( function ( op ) {
						var m = SL.modal( { title: op.title, wide: true, onClose: load } );
						m.body.appendChild( h( 'p', { class: 'sl-muted', text: t( 'Every redirect was checked: the target must be a live page, no loop may be created and the old URL must really be gone. Untick anything you do not want, then approve.' ) } ) );
						var box = h( 'div' );
						m.body.appendChild( box );
						App.operationView( box, op, load );
					} );
				}, 'primary', { disabled: true } );
				var all = h( 'input', { type: 'checkbox', 'aria-label': t( 'Select all' ), title: t( 'Select all' ), onChange: function ( e ) {
					boxes.forEach( function ( b ) {
						b.checked = e.target.checked;
						sel[ b.value ] = e.target.checked;
					} );
					count();
				} } );
				body.appendChild( h( 'div', { class: 'sl-toolbar' }, App.select( [ [ 'open', t( 'Open' ) ], [ 'redirected', t( 'Redirected' ) ], [ 'ignored', t( 'Ignored' ) ] ], f.status, function ( x ) {
					f.status = x;
					load();
				} ), bulkOn ? bulkBtn : null, bulkOn ? counter : null ) );
				body.appendChild( App.table( [
					bulkOn ? { label: all, render: function ( x ) {
						var b = h( 'input', { type: 'checkbox', value: String( x.id ), 'aria-label': t( 'Select' ), onChange: function ( e ) {
							sel[ x.id ] = e.target.checked;
							count();
						} } );
						boxes.push( b );
						return b;
					} } : null,
					{ label: t( 'URL' ), render: function ( x ) {
						return h( 'code', { dir: 'ltr', text: x.url } );
					} },
					{ label: t( 'Referrer' ), render: function ( x ) {
						return x.referrer ? App.ext( x.referrer ) : '—';
					} },
					{ label: t( 'Hits' ), render: function ( x ) {
						return SL.num( x.hits, 0 );
					} },
					{ label: t( 'First seen' ), render: function ( x ) {
						return SL.date( x.first_seen );
					} },
					{ label: t( 'Last seen' ), render: function ( x ) {
						return SL.date( x.last_seen );
					} },
					{ label: t( 'Suggested redirect' ), render: function ( x ) {
						if ( bulkOn ) {
							targets[ x.id ] = h( 'input', { class: 'sl-input sl-input--sm', dir: 'ltr', value: x.suggested_target ? decodeURI( x.suggested_target ) : '', placeholder: x.suggested_target === '' ? t( 'no good match — enter a target' ) : t( 'pending' ) } );
							return h( 'div', { class: 'sl-stack' }, targets[ x.id ], x.suggested_target ? h( 'small', { class: 'sl-muted', text: t( 'match %s%', x.suggestion_score || 0 ) } ) : null );
						}
						return x.suggested_target ? h( 'div', null, App.ext( x.suggested_target ), h( 'small', { class: 'sl-muted', text: ' ' + ( x.suggestion_score || 0 ) + '%' } ) ) : h( 'span', { class: 'sl-muted', text: x.suggested_target === '' ? t( 'no good match' ) : t( 'pending' ) } );
					} },
					{ label: '', render: function ( x ) {
						if ( ! App.cap( 'manage' ) || x.status !== 'open' ) {
							return null;
						}
						return h( 'div', { class: 'sl-row' },
							App.btn( t( 'Redirect' ), function () {
								App.handleResult( { preview: { type: 'redirect', source: x.url, target: x.suggested_target || '', candidates: [] } }, load );
							}, x.suggested_target ? 'primary' : 'sm' ),
							App.btn( t( '410 Gone' ), function () {
								SL.api( 'redirects', { body: { source: x.url, target: '', type: 410 } } ).then( function () {
									SL.toast( t( 'Marked as gone (410).' ) );
									load();
								} ).catch( SL.fail );
							}, 'sm' ),
							App.btn( t( 'Ignore' ), function () {
								SL.api( '404/' + x.id + '/status', { body: { status: 'ignored' } } ).then( load ).catch( SL.fail );
							}, 'sm' )
						);
					} },
				], r.items, { empty: t( 'No 404 errors logged' ) } ) );
				SL.append( body, App.pager( r.total, f.page, f.per_page, function ( p ) {
					f.page = p;
					load();
				} ) );
			} );
		}
		load();
	};

	/* ================================================================ Schema */
	App.pages.schema = function ( v ) {
		v.appendChild( App.header( t( 'Schema Intelligence' ), t( 'Validate Product, Article, FAQ, Breadcrumb, Organization, LocalBusiness, Review, AggregateRating, WebSite, WebPage and Person markup; find missing opportunities.' ) ) );
		var url = h( 'input', { class: 'sl-input', type: 'url', placeholder: C.homeUrl, dir: 'ltr' } );
		var result = h( 'div' );
		var list = function ( title, items, kind ) {
			return items && items.length ? h( 'div', { class: 'sl-alert sl-alert--' + kind }, h( 'strong', { text: title } ), h( 'ul', null, items.map( function ( x ) {
				return h( 'li', { dir: 'auto', text: x } );
			} ) ) ) : null;
		};
		var render = function ( r ) {
			SL.clear( result );
			result.appendChild( h( 'div', { class: 'sl-stack' },
				h( 'div', null, h( 'strong', { text: t( 'Types found' ) + ': ' } ), SL.chips( r.types ) ),
				r.invalid_json ? h( 'div', { class: 'sl-alert sl-alert--error', text: t( '%s JSON-LD blocks contain invalid JSON.', r.invalid_json ) } ) : null,
				r.errors.length ? list( t( 'Errors' ), r.errors.map( function ( e ) {
					return e.message;
				} ), 'error' ) : h( 'div', { class: 'sl-alert sl-alert--success', text: t( 'No required properties missing.' ) } ),
				r.warnings.length ? h( 'details', null, h( 'summary', { text: t( 'Warnings (%s)', r.warnings.length ) } ), h( 'ul', null, r.warnings.map( function ( e ) {
					return h( 'li', { text: e.message } );
				} ) ) ) : null,
				list( t( 'Opportunities' ), r.opportunities, 'info' ),
				r.ai ? h( 'div', { class: 'sl-card sl-card--inset' }, h( 'h3', { class: 'sl-iconline' }, SL.il( 'sparkles', t( 'AI schema suggestions' ), 17 ) ),
					h( 'div', null, h( 'strong', { text: t( 'Eligible types' ) + ': ' } ), SL.chips( r.ai.eligible_types ) ),
					list( t( 'Issues' ), r.ai.issues, 'warn' ),
					list( t( 'Opportunities' ), r.ai.opportunities, 'info' ),
					h( 'p', { class: 'sl-small sl-muted', text: t( 'Only mark up information that is visible on the page.' ) } )
				) : null,
				h( 'details', null, h( 'summary', { text: t( 'Raw JSON-LD' ) } ), h( 'pre', { class: 'sl-json', dir: 'ltr', text: JSON.stringify( r.blocks, null, 2 ) } ) )
			) );
		};
		var actions = [ App.abtn( t( 'Check structured data' ), function () {
			return SL.api( 'schema/check', { body: { url: url.value || C.homeUrl } } ).then( render );
		}, 'primary' ) ];
		if ( App.cap( 'ai' ) ) {
			actions.push( App.abtn( SL.il( 'sparkles', t( 'AI schema suggestions' ) ), function () {
				return SL.api( 'schema/suggest', { body: { url: url.value || C.homeUrl } } ).then( render );
			} ) );
		}
		v.appendChild( App.card( t( 'Validate a URL' ), h( 'div', null, h( 'div', { class: 'sl-toolbar' }, [ url ].concat( actions ) ), result ) ) );
		var cov = h( 'div' );
		v.appendChild( cov );
		App.load( cov, SL.api( 'schema' ), function ( r ) {
			cov.appendChild( h( 'div', { class: 'sl-grid-2' },
				App.card( t( 'Schema coverage' ), h( 'div', null, h( 'p', { class: 'sl-muted', text: t( '%1$s of %2$s indexable pages have no structured data.', SL.num( r.without, 0 ), SL.num( r.pages, 0 ) ) } ), SL.charts.bars( Object.keys( r.types ).slice( 0, 12 ).map( function ( k ) {
					return { label: k, value: r.types[ k ] };
				} ), { colorBy: true } ) ) ),
				App.card( t( 'Schema issues' ), h( 'div', null, App.table( [ { label: t( 'Issue' ), key: 'check_code' }, { label: t( 'Pages' ), key: 'c' } ], r.issues, { empty: t( 'No schema issues' ) } ), h( 'p', { class: 'sl-small', text: r.outputs ? t( 'SEOlity outputs Organization, WebSite, WebPage, Breadcrumb and Article schema.' ) : t( 'Another SEO plugin outputs your main schema; SEOlity adds FAQ schema only.' ) } ), h( 'p', { class: 'sl-small', text: t( 'Pages with SEOlity FAQ schema' ) + ': ' + SL.num( r.faq_pages, 0 ) } ), App.link( t( 'Organization settings' ), 'settings?tab=schema' ) ) )
			) );
		} );
	};

	/* ================================================================ Sitemap */
	App.pages.sitemap = function ( v ) {
		v.appendChild( App.header( t( 'Sitemap Intelligence' ), t( 'Checks every sitemap URL against crawl data: 404s, redirects, noindex and canonicalized URLs, missing important pages and lastmod quality.' ), [ App.abtn( t( 'Re-check' ), function () {
			return SL.api( 'sitemap', { query: { fresh: 1 } } ).then( function () {
				App.pages.sitemap( SL.clear( v ) );
			} );
		} ) ] ) );
		var body = h( 'div' );
		v.appendChild( body );
		App.load( body, SL.api( 'sitemap' ), function ( r ) {
			body.appendChild( h( 'div', { class: 'sl-kpis' }, App.kpi( t( 'Sitemaps' ), SL.num( r.sitemaps.length, 0 ) ), App.kpi( t( 'URLs in sitemaps' ), SL.num( r.total_urls, 0 ) ), App.kpi( t( 'Problem URLs' ), SL.num( r.problem_urls.length, 0 ) ), App.kpi( t( 'Missing important pages' ), SL.num( r.missing_urls.length, 0 ) ) ) );
			if ( r.recommendations.length ) {
				body.appendChild( App.card( t( 'Recommendations' ), h( 'ul', null, r.recommendations.map( function ( x ) {
					return h( 'li', { text: x } );
				} ) ) ) );
			}
			body.appendChild( App.card( t( 'Sitemaps' ), App.table( [ { label: t( 'Sitemap' ), render: function ( s ) {
				return App.ext( s.url );
			} }, { label: t( 'Type' ), key: 'type' }, { label: t( 'Status' ), key: 'status' }, { label: t( 'Entries' ), key: 'count' }, { label: 'lastmod', key: 'with_lastmod' }, { label: t( 'Error' ), key: 'error' } ], r.sitemaps ), { actions: r.gsc && App.cap( 'manage' ) ? [ App.abtn( t( 'Submit to Google' ), function () {
				return SL.api( 'sitemap/submit', { body: { url: r.sitemaps[ 0 ] ? r.sitemaps[ 0 ].url : '' } } ).then( function () {
					SL.toast( t( 'Sitemap submitted to Search Console.' ), 'success' );
				} );
			} ) ] : null } ) );
			if ( r.gsc && Array.isArray( r.gsc ) ) {
				body.appendChild( App.card( t( 'Search Console sitemap status' ), App.table( [ { label: t( 'Sitemap' ), render: function ( s ) {
					return App.ext( s.path );
				} }, { label: t( 'Last downloaded' ), render: function ( s ) {
					return SL.date( s.lastDownloaded );
				} }, { label: t( 'Errors' ), key: 'errors' }, { label: t( 'Warnings' ), key: 'warnings' } ], r.gsc ) ) );
			}
			body.appendChild( h( 'div', { class: 'sl-grid-2' },
				App.card( t( 'Remove from sitemap' ), App.table( [ { label: t( 'URL' ), render: function ( p ) {
					return App.ext( p.url );
				} }, { label: t( 'Reason' ), render: function ( p ) {
					return SL.badge( t( p.reason ), 'warn' );
				} } ], r.problem_urls.slice( 0, 100 ), { empty: t( 'Sitemap is clean' ) } ) ),
				App.card( t( 'Add to sitemap (indexable pages missing)' ), App.table( [ { label: t( 'URL' ), render: function ( p ) {
					return App.ext( p.url );
				} }, { label: t( 'Inlinks' ), key: 'inlinks' } ], r.missing_urls, { empty: t( 'Nothing missing' ) } ) )
			) );
		} );
	};

	/* ================================================================ Robots */
	App.pages.robots = function ( v ) {
		v.appendChild( App.header( t( 'Robots.txt Intelligence' ), t( 'Google-compatible rule matching, blocked important URLs, crawl-waste detection and a safe editor with live testing.' ) ) );
		var body = h( 'div' );
		v.appendChild( body );
		App.load( body, SL.api( 'robots' ), function ( r ) {
			var ta = h( 'textarea', { class: 'sl-input sl-code', rows: 16, dir: 'ltr', spellcheck: 'false' } );
			ta.value = r.override ? r.custom : r.content;
			var override = h( 'input', { type: 'checkbox', checked: r.override } );
			var testUrl = h( 'input', { class: 'sl-input', type: 'url', dir: 'ltr', placeholder: C.homeUrl + 'shop/?orderby=price' } );
			var testOut = h( 'div' );
			if ( r.physical_file ) {
				body.appendChild( h( 'div', { class: 'sl-alert sl-alert--warn', text: t( 'A physical robots.txt file exists in your web root; WordPress (and SEOlity) cannot override it. Edit or delete that file.' ) } ) );
			}
			body.appendChild( h( 'div', { class: 'sl-grid-2' },
				App.card( t( 'Editor' ), h( 'div', { class: 'sl-stack' }, ta, h( 'label', { class: 'sl-check' }, override, t( 'Serve this content as robots.txt (virtual)' ) ), h( 'div', { class: 'sl-row' }, App.cap( 'manage' ) ? App.abtn( t( 'Save' ), function () {
					return SL.api( 'robots', { body: { content: ta.value, override: override.checked } } ).then( function () {
						SL.toast( t( 'robots.txt saved.' ), 'success' );
						App.pages.robots( SL.clear( v ) );
					} );
				}, 'primary' ) : null, App.btn( t( 'Load suggested' ), function () {
					ta.value = r.suggested;
				} ) ), h( 'div', { class: 'sl-toolbar' }, testUrl, App.abtn( t( 'Test URL' ), function () {
					return SL.api( 'robots/test', { body: { url: testUrl.value, content: ta.value } } ).then( function ( x ) {
						SL.clear( testOut );
						testOut.appendChild( SL.chips( [ [ SL.yes( x.googlebot, 14 ), ' Googlebot: ' + ( x.googlebot ? t( 'allowed' ) : t( 'blocked' ) ) ], [ SL.yes( x.bingbot, 14 ), ' Bingbot' ], [ SL.yes( x.any, 14 ), ' *' ] ] ) );
					} );
				} ) ), testOut ) ),
				h( 'div', null,
					App.card( t( 'Issues' ), r.issues.length ? h( 'ul', { class: 'sl-list' }, r.issues.map( function ( i ) {
						return h( 'li', null, SL.priorityBadge( i.severity ), ' ', i.message );
					} ) ) : SL.empty( t( 'No issues' ) ) ),
					App.card( t( 'Crawl-waste suggestions' ), r.suggestions.length ? h( 'ul', null, r.suggestions.map( function ( s ) {
						return h( 'li', { dir: 'auto', text: s } );
					} ) ) : SL.empty( t( 'Nothing to suggest' ) ) ),
					r.blocked_important.length ? App.card( t( 'Important URLs blocked' ), h( 'ul', { class: 'sl-urls' }, r.blocked_important.map( function ( u ) {
						return h( 'li', null, App.ext( u ) );
					} ) ) ) : null
				)
			) );
		} );
	};

	/* ================================================================ Reports */
	App.pages.reports = function ( v ) {
		v.appendChild( App.header( t( 'SEO Reports' ), t( 'Daily, weekly and monthly reports with traffic, rankings, issues, improvements, competitors, AI changes and recommendations — white-label ready.' ) ) );
		var type = App.select( [ [ 'daily', t( 'Daily' ) ], [ 'weekly', t( 'Weekly' ) ], [ 'monthly', t( 'Monthly' ) ] ], 'weekly' );
		var list = h( 'div' );
		v.appendChild( App.card( t( 'Generate report' ), h( 'div', { class: 'sl-toolbar' }, type, App.abtn( t( 'Generate' ), function () {
			return SL.api( 'reports/generate', { body: { type: type.value } } ).then( function () {
				SL.toast( t( 'Report generated.' ), 'success' );
				load();
			} );
		}, 'primary' ), App.link( t( 'Schedule & recipients' ), 'settings?tab=reports' ) ) ) );
		v.appendChild( list );
		function load() {
			App.load( list, SL.api( 'reports' ), function ( rows ) {
				list.appendChild( App.table( [
					{ label: t( 'Report' ), key: 'title' },
					{ label: t( 'Type' ), render: function ( r ) {
						return t( r.type );
					} },
					{ label: t( 'Period' ), render: function ( r ) {
						return SL.date( r.period_start ) + ' – ' + SL.date( r.period_end );
					} },
					{ label: t( 'Storage' ), render: function ( r ) {
						return r.archive_ref ? SL.badge( 'GitHub', 'brand' ) : SL.badge( t( 'Local' ) );
					} },
					{ label: t( 'Export' ), render: function ( r ) {
						return h( 'div', { class: 'sl-row' }, h( 'a', { class: 'sl-btn sl-btn--sm', target: '_blank', rel: 'noopener', href: C.exportUrl + '&id=' + r.id + '&format=html' }, t( 'View / PDF' ) ), h( 'a', { class: 'sl-btn sl-btn--sm', href: C.exportUrl + '&id=' + r.id + '&format=csv' }, 'CSV' ), h( 'a', { class: 'sl-btn sl-btn--sm', href: C.exportUrl + '&id=' + r.id + '&format=json' }, 'JSON' ), App.cap( 'manage' ) ? App.abtn( t( 'Email' ), function () {
							return SL.api( 'reports/' + r.id + '/email', { method: 'POST' } ).then( function ( x ) {
								SL.toast( x.sent ? t( 'Report sent.' ) : t( 'No recipients configured.' ), x.sent ? 'success' : 'error' );
							} );
						} ) : null );
					} },
				], rows, { empty: t( 'No reports yet' ) } ) );
			} );
		}
		load();
	};

	/* ================================================================ Alerts */
	App.pages.alerts = function ( v ) {
		v.appendChild( App.header( t( 'AI Alerts' ), t( 'Ranking, traffic, CTR and indexing drops, new 404s, lost/new keywords, deindexed pages, sitemap & Core Web Vitals problems, competitor growth.' ), [ App.abtn( t( 'Mark all as read' ), function () {
			return SL.api( 'alerts/read-all', { method: 'POST' } ).then( function () {
				App.refreshBoot();
				App.pages.alerts( SL.clear( v ) );
			} );
		} ) ] ) );
		var list = h( 'div' );
		v.appendChild( list );
		App.load( list, SL.api( 'alerts' ), function ( r ) {
			if ( ! r.items.length ) {
				list.appendChild( SL.empty( t( 'No alerts' ), t( 'SEOlity checks for important changes every hour.' ) ) );
				return;
			}
			r.items.forEach( function ( a ) {
				list.appendChild( h( 'div', { class: 'sl-alertitem' + ( a.status === 'new' ? ' is-new' : '' ) },
					SL.priorityBadge( a.severity ),
					h( 'div', { class: 'sl-alertitem__main' }, h( 'strong', { text: a.title } ), h( 'p', { class: 'sl-small', text: a.message } ), h( 'span', { class: 'sl-muted sl-small', text: SL.date( a.created_at ) + ' · ' + a.type } ) ),
					h( 'div', { class: 'sl-row' }, a.data && a.data.url ? App.ext( a.data.url, t( 'Open' ) ) : null, App.btn( SL.icon( 'x', 16 ), function () {
						SL.api( 'alerts/' + a.id, { body: { status: 'dismissed' } } ).then( function () {
							App.pages.alerts( SL.clear( v ) );
						} ).catch( SL.fail );
					}, 'ghost', { 'aria-label': t( 'Dismiss' ) } ) )
				) );
				if ( a.status === 'new' ) {
					SL.api( 'alerts/' + a.id, { body: { status: 'read' } } ).catch( function () {} );
				}
			} );
			App.refreshBoot();
		} );
	};
} )( window );
