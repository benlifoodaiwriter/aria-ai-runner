/**
 * SEOlity admin pages: Site Audit + SEO Doctor + Health, Search Console,
 * Keywords, Content (inventory, planner, clusters, bulk), Products, Images,
 * Internal Links.
 */
( function ( w, d ) {
	'use strict';
	var SL = w.SL;
	var App = SL.app;
	var t = SL.t;
	var h = SL.h;
	var C = SL.config;

	/* ================================================================ Site Audit */
	App.pages.audit = function ( v, q ) {
		v.appendChild( App.header( t( 'Site Audit & SEO Doctor' ), t( 'Full crawl of your site with 60+ checks. The SEO Doctor explains what is wrong, why it matters, where, and how to fix it.' ), App.cap( 'manage' ) ? [
			App.abtn( t( 'Start full audit' ), function () {
				return SL.api( 'audit/start', { body: { type: 'full' } } ).then( function () {
					SL.toast( t( 'Audit started in the background.' ), 'success' );
					setTimeout( function () {
						App.pages.audit( SL.clear( v ), q );
					}, 1500 );
				} );
			}, 'primary' ),
			App.abtn( t( 'Incremental' ), function () {
				return SL.api( 'audit/start', { body: { type: 'incremental' } } ).then( function () {
					SL.toast( t( 'Incremental audit queued.' ) );
				} );
			} ),
			h( 'a', { class: 'sl-btn', href: C.exportUrl + '&what=issues' }, t( 'Export CSV' ) ),
		] : null ) );
		var progress = h( 'div' );
		v.appendChild( progress );
		function poll() {
			SL.api( 'audit' ).then( function ( a ) {
				SL.clear( progress );
				var au = a.audit;
				if ( ! au ) {
					progress.appendChild( SL.empty( t( 'No audit yet' ), t( 'Start your first audit — it runs in the background and never slows down your visitors.' ) ) );
					return;
				}
				if ( au.status === 'running' ) {
					var pct = au.urls_total ? Math.round( ( au.urls_done / au.urls_total ) * 100 ) : 0;
					progress.appendChild( App.card( t( 'Audit in progress' ), h( 'div', null, h( 'div', { class: 'sl-progressbar' }, h( 'span', { style: { width: pct + '%' } } ) ), h( 'p', { class: 'sl-muted', text: t( 'Phase: %1$s · %2$s of %3$s URLs', t( ( au.summary && au.summary.phase ) || 'crawling' ), SL.num( au.urls_done, 0 ), SL.num( au.urls_total, 0 ) ) } ) ) ) );
					if ( App.current === 'audit' ) {
						setTimeout( poll, 5000 );
					}
				} else {
					progress.appendChild( h( 'p', { class: 'sl-muted', text: t( 'Last audit: %1$s · %2$s URLs crawled', SL.date( au.finished_at || au.started_at ), SL.num( au.urls_done, 0 ) ) } ) );
				}
			} ).catch( function () {} );
		}
		poll();
		v.appendChild( SL.tabs( [
			{ label: t( 'SEO Doctor' ), render: doctor },
			{ label: t( 'Health score' ), render: health },
			{ label: t( 'All issues' ), render: issues },
			{ label: t( 'URL explorer' ), render: urls },
			{ label: t( 'Core Web Vitals' ), render: cwv },
		] ) );

		function doctor() {
			var box = h( 'div' );
			App.load( box, SL.api( 'doctor' ), function ( r ) {
				box.appendChild( h( 'div', { class: 'sl-actioncounts' }, [ 'critical', 'high', 'medium', 'low' ].map( function ( p ) {
					return h( 'div', { class: 'sl-actioncount sl-actioncount--' + p }, h( 'span', { class: 'sl-actioncount__n', text: App.priorityIcon[ p ] + ' ' + r.counts[ p ] } ), h( 'span', { text: t( p.charAt( 0 ).toUpperCase() + p.slice( 1 ) ) } ) );
				} ) ) );
				if ( ! r.diagnoses.length ) {
					box.appendChild( SL.empty( t( 'Your site looks healthy' ), t( 'No open diagnoses. Keep monitoring with scheduled audits.' ) ) );
					return;
				}
				r.diagnoses.forEach( function ( dg ) {
					box.appendChild( h( 'article', { class: 'sl-diag sl-diag--' + dg.priority },
						h( 'header', { class: 'sl-diag__head' }, h( 'span', { 'aria-hidden': 'true' }, App.priorityIcon[ dg.priority ] || '•' ), h( 'h3', { text: dg.title } ), dg.auto_fixable ? SL.badge( t( 'AI can fix' ), 'brand' ) : SL.badge( t( 'Manual fix' ) ) ),
						h( 'dl', { class: 'sl-diag__grid' },
							h( 'dt', { text: t( 'Why is it a problem?' ) } ), h( 'dd', { text: dg.why } ),
							h( 'dt', { text: t( 'Likely impact' ) } ), h( 'dd', { text: dg.impact } ),
							h( 'dt', { text: t( 'How to fix' ) } ), h( 'dd', { text: dg.fix } ),
							dg.sample_urls.length ? h( 'dt', { text: t( 'Where' ) } ) : null,
							dg.sample_urls.length ? h( 'dd', null, h( 'ul', { class: 'sl-urls' }, dg.sample_urls.map( function ( u ) {
								return h( 'li', null, /^https?:/.test( u ) ? App.ext( u ) : h( 'span', { dir: 'auto', text: u } ) );
							} ) ) ) : null
						),
						h( 'footer', { class: 'sl-row' }, h( 'a', { class: 'sl-btn sl-btn--primary', href: dg.route }, t( 'Fix it' ) ), dg.code ? App.btn( t( 'Show affected URLs' ), function () {
							App.go( 'audit?check=' + dg.code + '&tab=issues' );
							App.pages.audit( SL.clear( v ), { check: dg.code } );
						} ) : null )
					) );
				} );
			} );
			return box;
		}

		function health() {
			var box = h( 'div' );
			App.load( box, SL.api( 'health', { query: { fresh: 1 } } ), function ( hs ) {
				box.appendChild( h( 'div', { class: 'sl-health' }, h( 'div', { class: 'sl-health__score' }, SL.scoreRing( hs.score, 140 ), h( 'div', { class: 'sl-health__grade', text: hs.grade ? t( 'Grade' ) + ' ' + hs.grade : '' } ) ), hs.history && hs.history.length > 1 ? h( 'div', { class: 'sl-health__trend' }, SL.charts.line( hs.history, { x: 'date', series: [ { key: 'score', label: t( 'Health score' ) } ], height: 160 } ) ) : null ) );
				if ( hs.note ) {
					box.appendChild( h( 'div', { class: 'sl-alert sl-alert--info', text: hs.note } ) );
				}
				var cats = h( 'div', { class: 'sl-grid-2' } );
				Object.keys( hs.categories ).forEach( function ( k ) {
					var c = hs.categories[ k ];
					cats.appendChild( App.card( t( k.charAt( 0 ).toUpperCase() + k.slice( 1 ) ) + ' · ' + c.score + '/100', h( 'div', null, h( 'p', { class: 'sl-muted sl-small', text: c.about + ' ' + t( 'Weight' ) + ': ' + c.weight } ), SL.meter( t( 'Score' ), c.score ), h( 'ul', { class: 'sl-factors' }, c.factors.map( function ( f ) {
						return h( 'li', null, h( 'span', { text: f.label } ), h( 'strong', { text: String( f.value ) } ), f.impact ? h( 'span', { class: f.impact < 0 ? 'is-down' : 'is-up', text: ( f.impact > 0 ? '+' : '' ) + f.impact } ) : null );
					} ) ) ) ) );
				} );
				box.appendChild( cats );
				if ( hs.excluded && hs.excluded.length ) {
					box.appendChild( h( 'p', { class: 'sl-muted', text: t( 'Excluded (no data yet)' ) + ': ' + hs.excluded.map( function ( x ) {
						return t( x.charAt( 0 ).toUpperCase() + x.slice( 1 ) );
					} ).join( ', ' ) } ) );
				}
			} );
			return box;
		}

		function issues() {
			var f = { severity: '', category: '', check_code: q.check || '', status: 'open', page: 1, per_page: 50 };
			var box = h( 'div' );
			var list = h( 'div' );
			var bar = h( 'div', { class: 'sl-toolbar' },
				App.select( [ [ '', t( 'All severities' ) ], [ 'critical', t( 'Critical' ) ], [ 'high', t( 'High' ) ], [ 'medium', t( 'Medium' ) ], [ 'low', t( 'Low' ) ], [ 'info', t( 'Info' ) ] ], f.severity, function ( x ) {
					f.severity = x;
					f.page = 1;
					load();
				} ),
				App.select( [ [ '', t( 'All categories' ) ] ].concat( [ 'indexation', 'meta', 'content', 'links', 'images', 'schema', 'performance', 'security', 'mobile', 'international', 'technical' ].map( function ( c ) {
					return [ c, t( c.charAt( 0 ).toUpperCase() + c.slice( 1 ) ) ];
				} ) ), f.category, function ( x ) {
					f.category = x;
					f.page = 1;
					load();
				} ),
				App.select( [ [ 'open', t( 'Open' ) ], [ 'ignored', t( 'Ignored' ) ], [ 'fixed', t( 'Fixed' ) ] ], f.status, function ( x ) {
					f.status = x;
					f.page = 1;
					load();
				} ),
				f.check_code ? App.btn( '✕ ' + f.check_code, function () {
					f.check_code = '';
					load();
				}, 'sm' ) : null
			);
			box.appendChild( bar );
			box.appendChild( list );
			function load() {
				App.load( list, SL.api( 'issues', { query: f } ), function ( r ) {
					list.appendChild( App.table( [
						{ label: t( 'Issue' ), render: function ( i ) {
							return h( 'div', null, h( 'strong', { text: i.meta ? i.meta.title : i.check_code } ), h( 'div', { class: 'sl-muted sl-small', text: i.meta ? i.meta.fix : '' } ) );
						} },
						{ label: t( 'URL' ), render: function ( i ) {
							return i.url ? App.ext( i.url ) : t( 'Site-wide' );
						} },
						{ label: t( 'Severity' ), render: function ( i ) {
							return SL.priorityBadge( i.severity );
						} },
						{ label: t( 'Details' ), render: function ( i ) {
							return i.context && Object.keys( i.context ).length ? h( 'details', null, h( 'summary', { text: t( 'Details' ) } ), h( 'pre', { class: 'sl-json', dir: 'ltr', text: JSON.stringify( i.context, null, 1 ) } ) ) : '';
						} },
						{ label: t( 'First seen' ), render: function ( i ) {
							return SL.date( i.first_seen );
						} },
						{ label: '', render: function ( i ) {
							return h( 'div', { class: 'sl-row' },
								i.object_type && i.object_id && App.cap( 'ai' ) && /title|meta_desc/.test( i.check_code ) ? App.btn( t( 'AI fix' ), function () {
									SL.metaModal( i.object_type, i.object_id, load );
								}, 'primary' ) : null,
								i.object_type === 'post' && i.object_id && App.cap( 'ai' ) && /thin|h2_missing|heading/.test( i.check_code ) ? App.btn( t( 'AI fix' ), function () {
									SL.optimizer( { object_type: 'post', object_id: i.object_id, field: 'post_content' } );
								}, 'primary' ) : null,
								i.object_type === 'post' && i.object_id && /orphan/.test( i.check_code ) ? App.abtn( t( 'Find links' ), function () {
									return SL.api( 'links/suggest', { body: { post_id: i.object_id, direction: 'to' } } ).then( function ( res ) {
										App.linkSuggestions( res.suggestions, load );
									} );
								} ) : null,
								App.cap( 'apply' ) && i.status === 'open' ? App.btn( t( 'Ignore' ), function () {
									SL.api( 'issues/' + i.id + '/status', { body: { status: 'ignored' } } ).then( load ).catch( SL.fail );
								}, 'sm' ) : null
							);
						} },
					], r.items, { empty: t( 'No issues found' ) } ) );
					SL.append( list, App.pager( r.total, f.page, f.per_page, function ( p ) {
						f.page = p;
						load();
					} ) );
				} );
			}
			load();
			return box;
		}

		function urls() {
			var f = { search: '', orderby: 'inlinks', page: 1, per_page: 50 };
			var box = h( 'div' );
			var list = h( 'div' );
			var s = h( 'input', { class: 'sl-input', type: 'search', placeholder: t( 'Filter URLs…' ) } );
			s.addEventListener( 'change', function () {
				f.search = s.value;
				f.page = 1;
				load();
			} );
			box.appendChild( h( 'div', { class: 'sl-toolbar' }, s, App.select( [ [ 'inlinks', t( 'Most linked' ) ], [ 'word_count', t( 'Word count' ) ], [ 'response_ms', t( 'Slowest' ) ], [ 'depth', t( 'Deepest' ) ], [ 'status_code', t( 'Status code' ) ] ], f.orderby, function ( x ) {
				f.orderby = x;
				load();
			} ) ) );
			box.appendChild( list );
			function load() {
				App.load( list, SL.api( 'urls', { query: f } ), function ( r ) {
					list.appendChild( App.table( [
						{ label: t( 'URL' ), render: function ( u ) {
							return h( 'div', null, App.ext( u.url ), h( 'div', { class: 'sl-muted sl-small sl-clip', dir: 'auto', text: u.title || '' } ) );
						} },
						{ label: t( 'Status' ), render: function ( u ) {
							return SL.badge( String( u.status_code ), +u.status_code === 200 ? 'good' : +u.status_code >= 400 ? 'bad' : 'warn' );
						} },
						{ label: t( 'Indexable' ), render: function ( u ) {
							return +u.indexable ? '✓' : '✕';
						} },
						{ label: t( 'Words' ), render: function ( u ) {
							return SL.num( u.word_count, 0 );
						} },
						{ label: t( 'Inlinks' ), render: function ( u ) {
							return SL.num( u.inlinks, 0 );
						} },
						{ label: t( 'Depth' ), key: 'depth' },
						{ label: t( 'Response' ), render: function ( u ) {
							return SL.num( u.response_ms, 0 ) + 'ms';
						} },
						{ label: t( 'Sitemap' ), render: function ( u ) {
							return +u.in_sitemap ? '✓' : '';
						} },
					], r.items ) );
					SL.append( list, App.pager( r.total, f.page, f.per_page, function ( p ) {
						f.page = p;
						load();
					} ) );
				} );
			}
			load();
			return box;
		}

		function cwv() {
			var box = h( 'div' );
			var url = h( 'input', { class: 'sl-input', type: 'url', placeholder: C.homeUrl, dir: 'ltr' } );
			if ( App.cap( 'manage' ) ) {
				box.appendChild( h( 'div', { class: 'sl-toolbar' }, url, App.abtn( t( 'Test with PageSpeed Insights' ), function () {
					return SL.api( 'pagespeed/test', { body: { url: url.value || C.homeUrl } } ).then( function ( r ) {
						SL.toast( t( 'Performance score: %s', r.score ) );
						App.pages.audit( SL.clear( v ), q );
					} );
				}, 'primary' ) ) );
			}
			var list = h( 'div' );
			box.appendChild( list );
			App.load( list, SL.api( 'pagespeed' ), function ( rows ) {
				list.appendChild( App.table( [
					{ label: t( 'URL' ), render: function ( r ) {
						return App.ext( r.url );
					} },
					{ label: t( 'Score' ), render: function ( r ) {
						return SL.scoreRing( r.score, 40 );
					} },
					{ label: 'LCP', render: function ( r ) {
						return r.field && r.field.lcp ? SL.num( r.field.lcp / 1000, 1 ) + 's ' + t( '(field)' ) : r.lab && r.lab.lcp ? SL.num( r.lab.lcp / 1000, 1 ) + 's' : '—';
					} },
					{ label: 'INP', render: function ( r ) {
						return r.field && r.field.inp ? SL.num( r.field.inp, 0 ) + 'ms' : '—';
					} },
					{ label: 'CLS', render: function ( r ) {
						return r.field && r.field.cls !== null ? SL.num( r.field.cls, 2 ) : r.lab ? SL.num( r.lab.cls, 2 ) : '—';
					} },
					{ label: t( 'Top opportunities' ), render: function ( r ) {
						return h( 'ul', { class: 'sl-small' }, ( r.opportunities || [] ).slice( 0, 3 ).map( function ( o ) {
							return h( 'li', { text: o.title + ' (' + SL.num( o.savings_ms / 1000, 1 ) + 's)' } );
						} ) );
					} },
					{ label: t( 'Tested' ), render: function ( r ) {
						return SL.date( r.tested_at );
					} },
				], rows, { empty: t( 'No PageSpeed data yet' ), emptyText: t( 'SEOlity tests your most important pages daily (10 per day).' ) } ) );
			} );
			return box;
		}
	};

	/* ================================================================ Search Console */
	App.pages.gsc = function ( v, q ) {
		v.appendChild( App.header( t( 'Search Console Analytics' ), t( 'Real Google data, analyzed deeper than Search Console itself.' ) ) );
		var body = h( 'div' );
		v.appendChild( body );
		App.load( body, SL.api( 'gsc/status' ), function ( st ) {
			if ( ! st.connected ) {
				body.appendChild( connect( st ) );
				return;
			}
			body.appendChild( analytics( st ) );
		} );

		function connect( st ) {
			var box = h( 'div', { class: 'sl-stack' } );
			if ( ! App.cap( 'manage' ) ) {
				return SL.empty( t( 'Search Console is not connected' ), t( 'Ask an administrator to connect Google Search Console.' ) );
			}
			var cid = h( 'input', { class: 'sl-input', value: st.client_id || '', dir: 'ltr', placeholder: 'xxxxxxxx.apps.googleusercontent.com' } );
			var secret = h( 'input', { class: 'sl-input', type: 'password', dir: 'ltr', placeholder: st.has_secret ? '•••••••• ' + t( '(saved)' ) : 'GOCSPX-…', autocomplete: 'new-password' } );
			var redirect = h( 'input', { class: 'sl-input', readonly: true, value: st.redirect_uri, dir: 'ltr' } );
			box.appendChild( App.card( t( 'Step 1 — Google OAuth client' ), h( 'div', { class: 'sl-stack' },
				h( 'ol', { class: 'sl-small' }, [ t( 'Open Google Cloud Console → APIs & Services → enable “Google Search Console API”.' ), t( 'Create OAuth client ID → type “Web application”.' ), t( 'Add the Authorized redirect URI below (exactly).' ), t( 'Paste the Client ID and Client Secret here.' ) ].map( function ( x ) {
					return h( 'li', { text: x } );
				} ) ),
				App.field( t( 'Authorized redirect URI' ), h( 'div', { class: 'sl-row' }, redirect, App.btn( t( 'Copy' ), function () {
					navigator.clipboard.writeText( redirect.value ).then( function () {
						SL.toast( t( 'Copied.' ) );
					} );
				}, 'sm' ) ) ),
				App.field( t( 'Client ID' ), cid ),
				App.field( t( 'Client secret' ), secret, t( 'Stored encrypted. Never shown again or sent to the browser.' ) ),
				App.abtn( t( 'Save credentials' ), function () {
					return SL.api( 'gsc/credentials', { body: { client_id: cid.value, client_secret: secret.value } } ).then( function () {
						SL.toast( t( 'Saved.' ), 'success' );
						App.pages.gsc( SL.clear( v ), q );
					} );
				}, 'primary' )
			) ) );
			box.appendChild( App.card( t( 'Step 2 — Connect your Google account' ), h( 'div', null,
				h( 'p', { class: 'sl-muted', text: t( 'SEOlity requests read-only access (webmasters.readonly). You can revoke it anytime.' ) } ),
				App.abtn( st.account ? t( 'Reconnect Google account' ) : t( 'Connect Google account' ), function () {
					return SL.api( 'gsc/auth-url' ).then( function ( r ) {
						location.href = r.url;
					} );
				}, 'primary' )
			) ) );
			if ( st.account ) {
				var props = h( 'div' );
				box.appendChild( App.card( t( 'Step 3 — Choose a property' ), props ) );
				App.load( props, SL.api( 'gsc/sites' ), function ( sites ) {
					if ( ! sites.length ) {
						props.appendChild( SL.empty( t( 'No verified properties' ), t( 'Verify your site in Google Search Console first.' ) ) );
						return;
					}
					sites.forEach( function ( s ) {
						props.appendChild( h( 'div', { class: 'sl-row sl-row--between sl-propitem' }, h( 'div', null, h( 'strong', { dir: 'ltr', text: s.site_url } ), ' ', s.matches ? SL.badge( t( 'matches this site' ), 'good' ) : null, h( 'div', { class: 'sl-muted sl-small', text: s.permission } ) ), App.abtn( t( 'Use this property' ), function () {
							return SL.api( 'gsc/property', { body: { property: s.site_url } } ).then( function () {
								SL.toast( t( 'Property selected. Importing data in the background…' ), 'success' );
								App.refreshBoot();
								App.pages.gsc( SL.clear( v ), q );
							} );
						}, s.matches ? 'primary' : '' ) ) );
					} );
				} );
			}
			return box;
		}

		function analytics( st ) {
			var days = +q.days || 28;
			var box = h( 'div' );
			var head = h( 'div', { class: 'sl-toolbar' },
				App.select( [ [ 7, t( 'Last 7 days' ) ], [ 28, t( 'Last 28 days' ) ], [ 90, t( 'Last 3 months' ) ], [ 180, t( 'Last 6 months' ) ], [ 365, t( 'Last 12 months' ) ] ], days, function ( x ) {
					App.go( 'gsc?days=' + x );
				} ),
				h( 'span', { class: 'sl-muted sl-small', dir: 'ltr', text: st.property } ),
				h( 'span', { class: 'sl-muted sl-small', text: t( 'Last sync' ) + ': ' + SL.date( st.last_sync ) + ' · ' + SL.num( st.rows, 0 ) + ' ' + t( 'rows' ) } ),
				App.cap( 'manage' ) ? App.abtn( t( 'Sync now' ), function () {
					return SL.api( 'gsc/sync', { method: 'POST' } ).then( function () {
						SL.toast( t( 'Sync queued.' ) );
					} );
				} ) : null,
				App.cap( 'manage' ) ? App.btn( t( 'Change property' ), function () {
					SL.api( 'gsc/sites' ).then( function ( sites ) {
						var m = SL.modal( { title: t( 'Change property' ) } );
						sites.forEach( function ( s ) {
							m.body.appendChild( h( 'div', { class: 'sl-row sl-row--between sl-propitem' }, h( 'span', { dir: 'ltr', text: s.site_url } ), App.abtn( t( 'Select' ), function () {
								return SL.api( 'gsc/property', { body: { property: s.site_url } } ).then( function () {
									m.close();
									App.pages.gsc( SL.clear( v ), q );
								} );
							} ) ) );
						} );
					} ).catch( SL.fail );
				}, 'sm' ) : null,
				App.cap( 'manage' ) ? App.btn( t( 'Disconnect' ), function () {
					SL.confirm( t( 'Disconnect Google Search Console? Stored data stays until you change property.' ), function () {
						SL.api( 'gsc/disconnect', { method: 'POST' } ).then( function () {
							App.refreshBoot();
							App.pages.gsc( SL.clear( v ), q );
						} ).catch( SL.fail );
					}, true );
				}, 'sm' ) : null
			);
			box.appendChild( head );
			var top = h( 'div' );
			box.appendChild( top );
			App.load( top, SL.api( 'gsc/overview', { query: { days: days } } ), function ( o ) {
				if ( ! o.has_data ) {
					top.appendChild( h( 'div', { class: 'sl-alert sl-alert--info', text: t( 'Importing your Search Console data… the first import can take a few minutes.' ) } ) );
					return;
				}
				top.appendChild( h( 'div', { class: 'sl-kpis' },
					App.kpi( t( 'Clicks' ), SL.num( o.current.clicks, 0 ), o.delta.clicks ),
					App.kpi( t( 'Impressions' ), SL.num( o.current.impressions, 0 ), o.delta.impressions ),
					App.kpi( t( 'CTR' ), SL.num( o.current.ctr ) + '%', o.delta.ctr ),
					App.kpi( t( 'Avg. position' ), SL.num( o.current.position ), o.delta.position, { abs: true } )
				) );
				var metric = 'clicks';
				var chartBox = h( 'div' );
				var current = o.series.slice( -days );
				var drawChart = function () {
					SL.clear( chartBox );
					var labels = { clicks: t( 'Clicks' ), impressions: t( 'Impressions' ), ctr: t( 'CTR' ), position: t( 'Average position' ) };
					chartBox.appendChild( SL.charts.line( current, { x: 'date', series: [ { key: metric, label: labels[ metric ], invert: metric === 'position' } ] } ) );
				};
				top.appendChild( App.card( t( 'Trend' ), [ h( 'div', { class: 'sl-segment' }, [ [ 'clicks', t( 'Click Trend' ) ], [ 'impressions', t( 'Impression Trend' ) ], [ 'ctr', t( 'CTR Trend' ) ], [ 'position', t( 'Average Position' ) ] ].map( function ( x ) {
					return h( 'button', { type: 'button', class: 'sl-segment__btn' + ( x[ 0 ] === metric ? ' is-active' : '' ), onClick: function ( e ) {
						metric = x[ 0 ];
						Array.prototype.forEach.call( e.target.parentNode.children, function ( b ) {
							b.classList.remove( 'is-active' );
						} );
						e.target.classList.add( 'is-active' );
						drawChart();
					} }, x[ 1 ] );
				} ) ), chartBox ] ) );
				drawChart();
			} );
			box.appendChild( SL.tabs( [
				{ label: t( 'Opportunities' ), render: function () {
					return insightsTab( days, 'opps' );
				} },
				{ label: t( 'Top Queries' ), render: function () {
					return tableTab( 'query', days );
				} },
				{ label: t( 'Top Pages' ), render: function () {
					return tableTab( 'page', days );
				} },
				{ label: t( 'Winners & Losers' ), render: function () {
					return insightsTab( days, 'movers' );
				} },
				{ label: t( 'New & Lost Keywords' ), render: function () {
					return insightsTab( days, 'keywords' );
				} },
				{ label: t( 'Rising & Declining Pages' ), render: function () {
					return insightsTab( days, 'trending' );
				} },
				{ label: t( 'Ranking Distribution' ), render: function () {
					return insightsTab( days, 'distribution' );
				} },
				{ label: t( 'Countries & Devices' ), render: function () {
					return breakdownTab( days );
				} },
			] ) );
			return box;
		}

		function gscCols( by ) {
			return [
				{ label: by === 'query' ? t( 'Query' ) : t( 'Page' ), render: function ( r ) {
					return by === 'query' ? h( 'span', { dir: 'auto', text: r.query } ) : App.ext( r.page );
				} },
				{ label: t( 'Clicks' ), render: function ( r ) {
					return h( 'span', null, SL.num( r.clicks, 0 ), ' ', r.delta_clicks ? h( 'small', { class: r.delta_clicks > 0 ? 'is-up' : 'is-down', text: ( r.delta_clicks > 0 ? '+' : '' ) + SL.num( r.delta_clicks, 0 ) } ) : null );
				} },
				{ label: t( 'Impressions' ), render: function ( r ) {
					return SL.num( r.impressions, 0 );
				} },
				{ label: t( 'CTR' ), render: function ( r ) {
					return SL.num( r.ctr ) + '%';
				} },
				{ label: t( 'Position' ), render: function ( r ) {
					return h( 'span', null, SL.num( r.position ), r.prev_position ? h( 'small', { class: r.prev_position > r.position ? 'is-up' : 'is-down', text: ' (' + SL.num( r.prev_position ) + ')' } ) : null );
				} },
				{ label: by === 'query' ? t( 'Pages' ) : t( 'Queries' ), key: 'related' },
			];
		}

		function tableTab( by, days ) {
			var box = h( 'div' );
			var s = h( 'input', { class: 'sl-input', type: 'search', placeholder: t( 'Filter…' ) } );
			var order = 'clicks';
			var list = h( 'div' );
			var load = function () {
				App.load( list, SL.api( 'gsc/table', { query: { by: by, days: days, search: s.value, order: order, limit: 200 } } ), function ( rows ) {
					list.appendChild( App.table( gscCols( by ), rows ) );
				} );
			};
			s.addEventListener( 'change', load );
			box.appendChild( h( 'div', { class: 'sl-toolbar' }, s, App.select( [ [ 'clicks', t( 'Clicks' ) ], [ 'impressions', t( 'Impressions' ) ], [ 'delta_clicks', t( 'Click change' ) ], [ 'ctr', t( 'CTR' ) ], [ 'position', t( 'Position' ) ] ], order, function ( x ) {
				order = x;
				load();
			} ) ) );
			box.appendChild( list );
			load();
			return box;
		}

		var insightsCache = {};
		function insights( days ) {
			if ( ! insightsCache[ days ] ) {
				insightsCache[ days ] = SL.api( 'gsc/insights', { query: { days: days } } );
			}
			return insightsCache[ days ];
		}

		function insightsTab( days, which ) {
			var box = h( 'div' );
			App.load( box, insights( days ), function ( r ) {
				if ( which === 'opps' ) {
					box.appendChild( App.card( t( 'CTR Opportunities' ), [ h( 'p', { class: 'sl-muted sl-small', text: t( 'Pages with many impressions whose CTR is far below what their position should earn. Rewriting title + description is usually the fastest win.' ) } ), App.table( [
						{ label: t( 'Page' ), render: function ( p ) {
							return App.ext( p.page );
						} },
						{ label: t( 'Impressions' ), render: function ( p ) {
							return SL.num( p.impressions, 0 );
						} },
						{ label: t( 'CTR' ), render: function ( p ) {
							return SL.num( p.ctr ) + '% / ' + SL.num( p.expected_ctr ) + '%';
						} },
						{ label: t( 'Position' ), render: function ( p ) {
							return SL.num( p.position );
						} },
						{ label: t( 'Potential clicks' ), render: function ( p ) {
							return h( 'strong', { class: 'is-up', text: '+' + SL.num( p.estimated_gain, 0 ) } );
						} },
						{ label: t( 'Insight' ), render: function ( p ) {
							return h( 'span', { class: 'sl-small', text: t( 'This page has %1$s impressions but a low CTR; improving the title and meta description has high growth potential.', SL.num( p.impressions, 0 ) ) } );
						} },
						{ label: '', render: function ( p ) {
							return App.cap( 'ai' ) ? App.abtn( t( 'Fix with AI' ), function () {
								return fixByUrl( p.page );
							}, 'primary' ) : null;
						} },
					], r.ctr_opportunities, { empty: t( 'No CTR opportunities' ) } ) ] ) );
					box.appendChild( App.card( t( 'Position Opportunities' ), [ h( 'p', { class: 'sl-muted sl-small', text: t( 'URLs ranking for several keywords on positions 8–20 — improving content depth and internal links can push them onto page 1.' ) } ), App.table( [
						{ label: t( 'Page' ), render: function ( p ) {
							return App.ext( p.page );
						} },
						{ label: t( 'Keywords at 8–20' ), key: 'queries' },
						{ label: t( 'Impressions' ), render: function ( p ) {
							return SL.num( p.impressions, 0 );
						} },
						{ label: t( 'Avg. position' ), render: function ( p ) {
							return SL.num( p.position );
						} },
						{ label: t( 'Insight' ), render: function ( p ) {
							return h( 'span', { class: 'sl-small', text: t( 'This URL ranks for %1$s keywords in positions 8–20; improving content depth is likely worth it.', p.queries ) } );
						} },
						{ label: '', render: function ( p ) {
							return App.cap( 'ai' ) ? App.abtn( t( 'Optimize content' ), function () {
								return fixByUrl( p.page, 'content' );
							}, 'primary' ) : null;
						} },
					], r.position_opportunities, { empty: t( 'No position opportunities' ) } ) ] ) );
				} else if ( which === 'movers' ) {
					box.appendChild( h( 'div', { class: 'sl-grid-2' }, App.card( '📈 ' + t( 'Winners' ) + ' — ' + t( 'Pages' ), App.table( gscCols( 'page' ).slice( 0, 3 ), r.pages.winners ) ), App.card( '📉 ' + t( 'Losers' ) + ' — ' + t( 'Pages' ), App.table( gscCols( 'page' ).slice( 0, 3 ), r.pages.losers ) ), App.card( '📈 ' + t( 'Winners' ) + ' — ' + t( 'Queries' ), App.table( gscCols( 'query' ).slice( 0, 3 ), r.queries.winners ) ), App.card( '📉 ' + t( 'Losers' ) + ' — ' + t( 'Queries' ), App.table( gscCols( 'query' ).slice( 0, 3 ), r.queries.losers ) ) ) );
				} else if ( which === 'keywords' ) {
					var cols = [ { label: t( 'Query' ), render: function ( k ) {
						return h( 'span', { dir: 'auto', text: k.query } );
					} }, { label: t( 'Impressions' ), key: 'impressions' }, { label: t( 'Clicks' ), key: 'clicks' }, { label: t( 'Position' ), key: 'position' } ];
					box.appendChild( h( 'div', { class: 'sl-grid-2' }, App.card( t( 'New Keywords' ) + ' (' + r.keywords.new_count + ')', App.table( cols, r.keywords.new ) ), App.card( t( 'Lost Keywords' ) + ' (' + r.keywords.lost_count + ')', App.table( cols, r.keywords.lost ) ) ) );
				} else if ( which === 'trending' ) {
					var tcols = [ { label: t( 'Page' ), render: function ( p ) {
						return App.ext( p.page );
					} }, { label: t( 'Clicks' ), render: function ( p ) {
						return SL.num( p.prev_clicks, 0 ) + ' → ' + SL.num( p.clicks, 0 );
					} }, { label: t( 'Change' ), render: function ( p ) {
						return h( 'strong', { class: p.change > 0 ? 'is-up' : 'is-down', text: SL.pct( p.change ) } );
					} } ];
					box.appendChild( h( 'div', { class: 'sl-grid-2' }, App.card( t( 'Rising Pages' ), App.table( tcols, r.trending.rising ) ), App.card( t( 'Declining Pages' ), App.table( tcols, r.trending.declining ) ), App.card( t( 'New Pages' ), App.table( gscCols( 'page' ).slice( 0, 3 ), r.new_pages ) ) ) );
				} else if ( which === 'distribution' ) {
					var dist = r.distribution;
					box.appendChild( App.card( t( 'Ranking Distribution' ), SL.charts.columns( Object.keys( dist ), Object.keys( dist ).map( function ( k ) {
						return dist[ k ];
					} ), { colors: [ 'var(--sl-good)', 'var(--sl-c1)', 'var(--sl-warn)', 'var(--sl-orange)', 'var(--sl-bad)' ], height: 220 } ) ) );
				}
			} );
			return box;
		}

		function fixByUrl( url, mode ) {
			return SL.api( 'urls', { query: { search: SL.path( url ), per_page: 1 } } ).then( function ( r ) {
				var row = r.items[ 0 ];
				if ( ! row || ! row.object_id ) {
					throw new Error( t( 'This URL is not mapped to a WordPress object yet. Run a site audit first.' ) );
				}
				if ( mode === 'content' ) {
					SL.optimizer( { object_type: row.object_type, object_id: row.object_id, field: row.object_type === 'term' ? 'term_description' : 'post_content' } );
				} else {
					SL.metaModal( row.object_type, row.object_id );
				}
			} );
		}

		function breakdownTab( days ) {
			var box = h( 'div', { class: 'sl-grid-3' } );
			[ [ 'device', t( 'Devices' ) ], [ 'country', t( 'Countries' ) ], [ 'searchAppearance', t( 'Search Appearance' ) ] ].forEach( function ( x ) {
				var c = h( 'div' );
				box.appendChild( App.card( x[ 1 ], c ) );
				App.load( c, SL.api( 'gsc/breakdown', { query: { dimension: x[ 0 ], days: days } } ), function ( rows ) {
					c.appendChild( rows.length ? SL.charts.bars( rows.slice( 0, 12 ).map( function ( r ) {
						return { label: x[ 0 ] === 'country' ? r.value.toUpperCase() : r.value, value: r.clicks, sub: '· ' + SL.num( r.ctr ) + '%' };
					} ), { colorBy: true } ) : SL.empty( t( 'No data' ) ) );
				} );
			} );
			return box;
		}
	};

	/* ================================================================ Keywords */
	App.pages.keywords = function ( v, q ) {
		v.appendChild( App.header( t( 'Keyword Intelligence' ), t( 'Your keyword universe from Search Console + AI research, with intent, opportunity scores, URL mapping and cannibalization detection.' ), App.cap( 'manage' ) ? [ App.abtn( t( 'Sync from Search Console' ), function () {
			return SL.api( 'keywords/sync', { method: 'POST' } ).then( function () {
				SL.toast( t( 'Sync queued.' ) );
			} );
		} ) ] : null ) );
		var tabs = [
			{ label: t( 'Keywords' ), render: all },
			{ label: t( 'Keyword Research' ), render: research },
			{ label: t( 'Cannibalization' ), render: cannibal },
			{ label: t( 'Keyword Mapping' ), render: mapping },
		];
		if ( q.tab === 'cannibalization' ) {
			tabs.unshift( tabs.splice( 2, 1 )[ 0 ] );
		}
		v.appendChild( SL.tabs( tabs ) );

		function all() {
			var f = { search: '', intent: '', kw_type: '', orderby: 'opportunity', page: 1, per_page: 100 };
			var box = h( 'div' );
			var list = h( 'div' );
			var s = h( 'input', { class: 'sl-input', type: 'search', placeholder: t( 'Search keywords…' ) } );
			s.addEventListener( 'change', function () {
				f.search = s.value;
				f.page = 1;
				load();
			} );
			var add = h( 'input', { class: 'sl-input', placeholder: t( 'Add keywords (comma separated)' ) } );
			box.appendChild( h( 'div', { class: 'sl-toolbar' }, s,
				App.select( [ [ '', t( 'All intents' ) ], [ 'informational', t( 'Informational' ) ], [ 'commercial', t( 'Commercial' ) ], [ 'transactional', t( 'Transactional' ) ], [ 'navigational', t( 'Navigational' ) ] ], '', function ( x ) {
					f.intent = x;
					load();
				} ),
				App.select( [ [ '', t( 'All types' ) ], [ 'head', t( 'Head' ) ], [ 'long_tail', t( 'Long-tail' ) ], [ 'question', t( 'Questions' ) ], [ 'semantic', t( 'Semantic' ) ], [ 'entity', t( 'Entities' ) ] ], '', function ( x ) {
					f.kw_type = x;
					load();
				} ),
				App.cap( 'apply' ) ? h( 'span', { class: 'sl-row' }, add, App.abtn( t( 'Add' ), function () {
					return SL.api( 'keywords', { body: { keywords: add.value.split( /[,،\n]/ ).map( function ( x ) {
						return x.trim();
					} ).filter( Boolean ) } } ).then( function () {
						add.value = '';
						load();
					} );
				} ) ) : null
			) );
			box.appendChild( list );
			function load() {
				App.load( list, SL.api( 'keywords', { query: f } ), function ( r ) {
					list.appendChild( App.table( [
						{ label: t( 'Keyword' ), render: function ( k ) {
							return h( 'strong', { dir: 'auto', text: k.keyword } );
						} },
						{ label: t( 'Intent' ), render: function ( k ) {
							return SL.badge( t( k.intent || '—' ) );
						} },
						{ label: t( 'Type' ), render: function ( k ) {
							return t( k.kw_type || '' );
						} },
						{ label: t( 'Opportunity' ), render: function ( k ) {
							return SL.meter( '', k.opportunity );
						} },
						{ label: t( 'Difficulty (est.)' ), key: 'difficulty' },
						{ label: t( 'Position' ), render: function ( k ) {
							return k.data && k.data.position ? SL.num( k.data.position ) : '—';
						} },
						{ label: t( 'Impressions' ), render: function ( k ) {
							return k.data && k.data.impressions ? SL.num( k.data.impressions, 0 ) : '—';
						} },
						{ label: t( 'Target URL' ), render: function ( k ) {
							return k.target_url ? App.ext( k.target_url ) : h( 'span', { class: 'sl-muted', text: t( 'unmapped' ) } );
						} },
						{ label: t( 'Track' ), render: function ( k ) {
							var cb = h( 'input', { type: 'checkbox', checked: +k.tracked === 1, 'aria-label': t( 'Track' ), onChange: function () {
								SL.api( 'keywords/' + k.id, { method: 'PATCH', body: { tracked: cb.checked ? 1 : 0 } } ).catch( SL.fail );
							} } );
							return cb;
						} },
					], r.items, { empty: t( 'No keywords yet' ), emptyText: t( 'Connect Search Console or run keyword research.' ) } ) );
					SL.append( list, App.pager( r.total, f.page, f.per_page, function ( p ) {
						f.page = p;
						load();
					} ) );
				} );
			}
			load();
			return box;
		}

		function research() {
			var box = h( 'div', { class: 'sl-stack' } );
			var seeds = h( 'textarea', { class: 'sl-input', rows: 3, placeholder: t( 'Seed keywords, one per line (e.g. espresso machine, coffee grinder)' ), dir: 'auto' } );
			var market = h( 'input', { class: 'sl-input', placeholder: t( 'Market / language (optional), e.g. Iran – Persian' ) } );
			var out = h( 'div' );
			box.appendChild( seeds );
			box.appendChild( market );
			box.appendChild( App.cap( 'ai' ) ? App.abtn( '✨ ' + t( 'Research keywords' ), function () {
				SL.clear( out );
				out.appendChild( SL.spinner( t( 'Expanding seeds with AI and your Search Console data…' ) ) );
				return SL.api( 'keywords/research', { body: { seeds: seeds.value.split( /\n|,|،/ ).map( function ( x ) {
					return x.trim();
				} ).filter( Boolean ), market: market.value } } ).then( function ( r ) {
					SL.clear( out );
					out.appendChild( App.table( [
						{ label: t( 'Keyword' ), render: function ( k ) {
							return h( 'strong', { dir: 'auto', text: k.keyword } );
						} },
						{ label: t( 'Intent' ), render: function ( k ) {
							return SL.badge( t( k.intent ) );
						} },
						{ label: t( 'Type' ), render: function ( k ) {
							return t( k.type );
						} },
						{ label: t( 'Difficulty (est.)' ), key: 'difficulty' },
						{ label: t( 'Opportunity' ), render: function ( k ) {
							return SL.meter( '', k.opportunity );
						} },
						{ label: t( 'Why' ), render: function ( k ) {
							return h( 'span', { class: 'sl-small', text: k.rationale } );
						} },
					], r.keywords ) );
					out.appendChild( h( 'div', { class: 'sl-grid-2' }, App.card( t( 'Questions' ), SL.chips( r.questions ) ), App.card( t( 'Entities' ), SL.chips( r.entities, 'entity' ) ) ) );
					out.appendChild( App.card( t( 'Clusters' ), h( 'div', null, r.clusters.map( function ( c ) {
						return h( 'div', { class: 'sl-cluster' }, h( 'strong', { text: c.name } ), SL.chips( c.keywords ) );
					} ) ) ) );
					out.appendChild( App.card( t( 'Keyword → URL mapping' ), App.table( [ { label: t( 'Keyword' ), key: 'keyword' }, { label: t( 'URL' ), render: function ( m ) {
						return h( 'span', { dir: 'ltr', text: m.url } );
					} }, { label: t( 'New page?' ), render: function ( m ) {
						return m.is_new ? SL.badge( t( 'new' ), 'brand' ) : '';
					} } ], r.mapping ) ) );
				} ).catch( function ( e ) {
					SL.clear( out );
					throw e;
				} );
			}, 'primary' ) : null );
			box.appendChild( out );
			return box;
		}

		function cannibal() {
			var box = h( 'div' );
			App.load( box, SL.api( 'keywords/cannibalization', { query: { days: 28 } } ), function ( rows ) {
				box.appendChild( h( 'p', { class: 'sl-muted', text: t( 'Queries where several of your URLs share impressions. SEOlity can suggest merge, redirect, differentiate, internal linking or changing the keyword target.' ) } ) );
				box.appendChild( App.table( [
					{ label: t( 'Query' ), render: function ( c ) {
						return h( 'strong', { dir: 'auto', text: c.query } );
					} },
					{ label: t( 'Impressions' ), render: function ( c ) {
						return SL.num( c.impressions, 0 );
					} },
					{ label: t( 'Competing URLs' ), render: function ( c ) {
						return h( 'ul', { class: 'sl-urls' }, c.pages.map( function ( p ) {
							return h( 'li', null, App.ext( p.page ), h( 'small', { class: 'sl-muted', text: ' · ' + p.share + '% · #' + p.position } ) );
						} ) );
					} },
					{ label: '', render: function ( c ) {
						return App.cap( 'ai' ) ? App.abtn( t( 'AI advice' ), function () {
							return SL.api( 'keywords/cannibalization/advice', { body: { query: c.query } } ).then( App.cannibalModal );
						}, 'primary' ) : null;
					} },
				], rows, { empty: t( 'No cannibalization detected' ), emptyText: t( 'Requires Search Console data.' ) } ) );
			} );
			return box;
		}

		function mapping() {
			var box = h( 'div' );
			App.load( box, SL.api( 'keywords/mapping' ), function ( r ) {
				box.appendChild( h( 'div', { class: 'sl-grid-2' },
					App.card( t( 'Keywords by URL' ), App.table( [ { label: t( 'URL' ), render: function ( x ) {
						return App.ext( x.target_url );
					} }, { label: t( 'Keywords' ), render: function ( x ) {
						return SL.chips( x.keywords );
					} } ], r.by_url ) ),
					App.card( t( 'Unmapped keywords (content opportunities)' ), App.table( [ { label: t( 'Keyword' ), key: 'keyword' }, { label: t( 'Intent' ), render: function ( x ) {
						return t( x.intent || '' );
					} }, { label: t( 'Opportunity' ), key: 'opportunity' } ], r.unmapped ) )
				) );
			} );
			return box;
		}
	};

	/* ================================================================ Content */
	App.pages.content = function ( v, q ) {
		v.appendChild( App.header( t( 'Content' ), t( 'Inventory with SEO scores, AI optimization, content planner, topic clusters and bulk AI.' ) ) );
		var tabs = [
			{ label: t( 'Inventory' ), render: inventory },
			{ label: t( 'Content Planner' ), render: planner },
			{ label: t( 'Topic Clusters' ), render: clusters },
			{ label: t( 'Bulk AI' ), render: bulk },
		];
		if ( q.tab === 'planner' ) {
			tabs.unshift( tabs.splice( 1, 1 )[ 0 ] );
		}
		v.appendChild( SL.tabs( tabs ) );
		if ( q.analyze ) {
			SL.optimizer( { object_type: 'post', object_id: +q.analyze, field: 'post_content' } );
		}

		function inventory() {
			var f = { kind: 'post', filter: q.filter || '', search: '', post_type: '', taxonomy: '', page: 1, per_page: 30 };
			var box = h( 'div' );
			var list = h( 'div' );
			var s = h( 'input', { class: 'sl-input', type: 'search', placeholder: t( 'Search…' ) } );
			s.addEventListener( 'change', function () {
				f.search = s.value;
				f.page = 1;
				load();
			} );
			var typeSel = h( 'span' );
			box.appendChild( h( 'div', { class: 'sl-toolbar' }, s,
				App.select( [ [ 'post', t( 'Posts, pages & products' ) ], [ 'term', t( 'Categories & tags' ) ] ], f.kind, function ( x ) {
					f.kind = x;
					f.page = 1;
					load();
				} ),
				typeSel,
				App.select( [ [ '', t( 'All' ) ], [ 'title', t( 'Title problems' ) ], [ 'meta', t( 'Meta description problems' ) ], [ 'thin', t( 'Thin content' ) ], [ 'h1', t( 'H1 problems' ) ], [ 'low_score', t( 'Low SEO score' ) ], [ 'no_keyword', t( 'No focus keyword' ) ] ], f.filter, function ( x ) {
					f.filter = x;
					f.page = 1;
					load();
				} )
			) );
			box.appendChild( list );
			function load() {
				App.load( list, SL.api( 'content', { query: f } ), function ( r ) {
					SL.clear( typeSel );
					if ( r.post_types ) {
						typeSel.appendChild( App.select( [ [ '', t( 'All types' ) ] ].concat( r.post_types.map( function ( p ) {
							return [ p.name, p.label ];
						} ) ), f.post_type, function ( x ) {
							f.post_type = x;
							load();
						} ) );
					} else if ( r.taxonomies ) {
						typeSel.appendChild( App.select( r.taxonomies.map( function ( p ) {
							return [ p.name, p.label ];
						} ), f.taxonomy, function ( x ) {
							f.taxonomy = x;
							load();
						} ) );
					}
					list.appendChild( App.table( [
						{ label: t( 'Title' ), render: function ( c ) {
							return h( 'div', null, h( 'a', { href: c.edit_link, class: 'sl-link', dir: 'auto' }, c.title || t( '(no title)' ) ), h( 'div', { class: 'sl-muted sl-small', text: c.subtype + ' · ' + t( c.status ) + ( c.focus_keyword ? ' · 🔑 ' + c.focus_keyword : '' ) } ) );
						} },
						{ label: t( 'Score' ), render: function ( c ) {
							return SL.scoreRing( c.score, 40 );
						} },
						{ label: t( 'Words' ), render: function ( c ) {
							return SL.num( c.words, 0 );
						} },
						{ label: t( 'Meta' ), render: function ( c ) {
							return h( 'div', { class: 'sl-small' }, c.meta_title ? '✓ ' + t( 'title' ) : '✕ ' + t( 'title' ), ' · ', c.meta_description ? '✓ ' + t( 'description' ) : '✕ ' + t( 'description' ) );
						} },
						{ label: t( 'Issues' ), render: function ( c ) {
							return c.issues.length ? SL.chips( c.issues.slice( 0, 4 ), 'warn' ) : '✓';
						} },
						{ label: '', render: function ( c ) {
							return h( 'div', { class: 'sl-row' },
								App.cap( 'ai' ) ? App.btn( '✨ ' + t( 'Optimize' ), function () {
									SL.optimizer( { object_type: c.object_type, object_id: c.id, field: c.object_type === 'term' ? 'term_description' : 'post_content', focus_keyword: c.focus_keyword } );
								}, 'primary' ) : null,
								App.cap( 'ai' ) ? App.btn( t( 'Meta' ), function () {
									SL.metaModal( c.object_type, c.id, load );
								}, 'sm' ) : null,
								App.btn( t( 'History' ), function () {
									SL.history( c.object_type, c.id );
								}, 'sm' ),
								c.pending ? h( 'a', { href: '#/changes', class: 'sl-badge sl-badge--warn' }, t( '%s pending', c.pending ) ) : null
							);
						} },
					], r.items ) );
					SL.append( list, App.pager( r.total, f.page, f.per_page, function ( p ) {
						f.page = p;
						load();
					} ) );
				} );
			}
			load();
			return box;
		}

		function planner() {
			var box = h( 'div' );
			var cadence = App.select( [ [ 'daily', t( 'Daily' ) ], [ 'weekly', t( 'Weekly' ) ], [ 'monthly', t( 'Monthly' ) ] ], 'weekly' );
			var items = h( 'input', { class: 'sl-input sl-input--sm', type: 'number', min: 1, max: 30, value: 8 } );
			var focus = h( 'input', { class: 'sl-input', placeholder: t( 'Focus (optional): topic, product line, season…' ) } );
			var list = h( 'div' );
			if ( App.cap( 'ai' ) ) {
				box.appendChild( h( 'div', { class: 'sl-toolbar' }, cadence, items, focus, App.abtn( '✨ ' + t( 'Generate calendar' ), function () {
					return SL.api( 'planner/generate', { body: { cadence: cadence.value, items: items.value, focus: focus.value } } ).then( function ( r ) {
						SL.toast( t( '%s items planned.', r.created ), 'success' );
						load();
					} );
				}, 'primary' ) ) );
			}
			box.appendChild( list );
			function load() {
				App.load( list, SL.api( 'planner' ), function ( rows ) {
					list.appendChild( App.table( [
						{ label: t( 'Date' ), render: function ( p ) {
							return SL.date( p.scheduled_date );
						} },
						{ label: t( 'Topic' ), render: function ( p ) {
							return h( 'div', null, h( 'strong', { dir: 'auto', text: p.title || p.topic } ), h( 'div', { class: 'sl-muted sl-small', text: 'H1: ' + ( p.h1 || '—' ) } ), p.brief && p.brief.reason ? h( 'div', { class: 'sl-small', text: p.brief.reason } ) : null );
						} },
						{ label: t( 'Keyword' ), render: function ( p ) {
							return h( 'span', { dir: 'auto', text: p.keyword } );
						} },
						{ label: t( 'Intent' ), render: function ( p ) {
							return t( p.intent || '' );
						} },
						{ label: t( 'Priority' ), render: function ( p ) {
							return SL.priorityBadge( p.priority );
						} },
						{ label: t( 'Outline' ), render: function ( p ) {
							return p.brief && p.brief.outline ? h( 'details', null, h( 'summary', { text: t( 'Outline' ) } ), h( 'ul', { class: 'sl-small' }, p.brief.outline.map( function ( o ) {
								return h( 'li', { text: typeof o === 'string' ? o : o.heading } );
							} ) ), p.brief.supporting_keywords ? SL.chips( p.brief.supporting_keywords ) : null ) : '';
						} },
						{ label: t( 'Status' ), render: function ( p ) {
							return App.select( [ [ 'planned', t( 'Planned' ) ], [ 'in_progress', t( 'In progress' ) ], [ 'published', t( 'Published' ) ], [ 'skipped', t( 'Skipped' ) ] ], p.status, function ( x ) {
								SL.api( 'planner/' + p.id, { method: 'PATCH', body: { status: x } } ).catch( SL.fail );
							} );
						} },
						{ label: '', render: function ( p ) {
							return p.post_id ? h( 'a', { class: 'sl-btn sl-btn--sm', href: p.edit_link }, t( 'Edit draft' ) ) : App.cap( 'ai' ) ? App.abtn( t( 'Create draft with brief' ), function () {
								return SL.api( 'planner/' + p.id + '/draft', { method: 'POST' } ).then( function ( r ) {
									location.href = r.edit_link;
								} );
							}, 'primary' ) : null;
						} },
					], rows, { empty: t( 'No planned content' ), emptyText: t( 'Generate an AI content calendar from your keyword gaps, competitor gaps and topic clusters.' ) } ) );
				} );
			}
			load();
			return box;
		}

		function clusters() {
			var box = h( 'div' );
			if ( App.cap( 'ai' ) ) {
				box.appendChild( h( 'div', { class: 'sl-toolbar' }, App.abtn( t( 'Rebuild clusters' ), function () {
					return SL.api( 'clusters/build', { method: 'POST' } ).then( function () {
						SL.toast( t( 'Building clusters in the background.' ) );
					} );
				} ) ) );
			}
			var list = h( 'div' );
			box.appendChild( list );
			App.load( list, SL.api( 'clusters' ), function ( rows ) {
				if ( ! rows.length ) {
					list.appendChild( SL.empty( t( 'No topic clusters yet' ), t( 'SEOlity groups your pages into Pillar → Cluster → Supporting content and finds topic gaps.' ) ) );
					return;
				}
				rows.forEach( function ( c ) {
					list.appendChild( App.card( c.name + ' · ' + t( 'coverage' ) + ' ' + c.coverage + '%', h( 'div', { class: 'sl-cluster-tree' },
						h( 'div', { class: 'sl-cluster-tree__pillar' }, '🏛 ', t( 'Pillar page' ), ': ', c.pillar_url ? App.ext( c.pillar_url ) : '—' ),
						h( 'ul', null, ( c.members || [] ).map( function ( m ) {
							var unlinked = ( c.unlinked_members || [] ).indexOf( m.url ) > -1;
							return h( 'li', null, '↳ ', App.ext( m.url, m.title ), unlinked ? SL.badge( t( 'not linked with pillar' ), 'warn' ) : null );
						} ) ),
						c.gaps && c.gaps.length ? h( 'div', null, h( 'h4', { text: t( 'Topic gaps' ) } ), h( 'ul', null, c.gaps.map( function ( g ) {
							return h( 'li', null, h( 'strong', { text: g.topic } ), ' — ', h( 'span', { class: 'sl-muted', text: g.keyword + ' · ' + t( g.intent || '' ) } ) );
						} ) ) ) : null
					) ) );
				} );
			} );
			return box;
		}

		function bulk() {
			var box = h( 'div', { class: 'sl-stack' } );
			var tasks = [ [ 'meta', t( 'Optimize titles & meta descriptions' ) ], [ 'posts', t( 'Optimize posts content' ) ], [ 'categories', t( 'Optimize categories' ) ], [ 'faq', t( 'Generate FAQs' ) ], [ 'internal_links', t( 'Generate internal links' ) ], [ 'missing_h1', t( 'Fix missing H1 (empty titles)' ) ], [ 'products', t( 'Optimize products' ) ], [ 'alt', t( 'Optimize image ALT' ) ] ];
			var task = App.select( tasks, 'meta' );
			var source = App.select( [ [ 'meta', t( 'Pages with missing/weak meta' ) ], [ 'title', t( 'Pages with title problems' ) ], [ 'thin', t( 'Thin pages' ) ], [ 'low_score', t( 'Low SEO score' ) ], [ '', t( 'Latest content' ) ] ], 'meta' );
			var preview = h( 'div' );
			var selected = {};
			function loadItems() {
				var kind = task.value === 'categories' ? 'term' : 'post';
				App.load( preview, SL.api( 'content', { query: { kind: kind, filter: source.value, per_page: 100, post_type: task.value === 'products' ? 'product' : '' } } ), function ( r ) {
					selected = {};
					r.items.forEach( function ( i ) {
						selected[ i.id ] = true;
					} );
					preview.appendChild( h( 'p', { class: 'sl-muted', text: t( '%s items selected. Each item is processed as a separate background job; results arrive as pending changes (or auto-apply per your approval mode).', r.items.length ) } ) );
					preview.appendChild( App.table( [ { label: '', render: function ( i ) {
						var cb = h( 'input', { type: 'checkbox', checked: true, onChange: function () {
							selected[ i.id ] = cb.checked;
						} } );
						return cb;
					} }, { label: t( 'Title' ), key: 'title' }, { label: t( 'Score' ), render: function ( i ) {
						return SL.num( i.score, 0 );
					} } ], r.items ) );
				} );
			}
			task.addEventListener( 'change', loadItems );
			source.addEventListener( 'change', loadItems );
			box.appendChild( h( 'div', { class: 'sl-toolbar' }, task, source, App.cap( 'ai' ) ? App.abtn( t( 'Run bulk AI' ), function () {
				var ids = Object.keys( selected ).filter( function ( k ) {
					return selected[ k ];
				} );
				if ( task.value === 'alt' ) {
					App.go( 'images' );
					return Promise.resolve();
				}
				return SL.api( 'bulk', { body: { task: task.value, ids: ids, object_type: task.value === 'categories' ? 'term' : 'post' } } ).then( function ( r ) {
					SL.toast( t( '%s items queued for background AI processing.', r.queued ), 'success' );
					App.go( 'changes?batch_id=' + r.batch_id );
				} );
			}, 'primary' ) : null ) );
			box.appendChild( preview );
			loadItems();
			return box;
		}
	};

	/* ================================================================ Products */
	App.pages.products = function ( v, q ) {
		v.appendChild( App.header( t( 'AI Product SEO' ), t( 'WooCommerce product optimization: titles, descriptions, meta, keywords, FAQ, ALT, tags, schema and cannibalization — grounded in real product data.' ) ) );
		var f = { search: '', filter: q.filter || '', page: 1, per_page: 30 };
		var list = h( 'div' );
		var s = h( 'input', { class: 'sl-input', type: 'search', placeholder: t( 'Search products…' ) } );
		s.addEventListener( 'change', function () {
			f.search = s.value;
			f.page = 1;
			load();
		} );
		var selected = {};
		v.appendChild( h( 'div', { class: 'sl-toolbar' }, s, App.select( [ [ '', t( 'All products' ) ], [ 'no_short', t( 'No short description' ) ] ], f.filter, function ( x ) {
			f.filter = x;
			load();
		} ), App.cap( 'ai' ) ? App.abtn( t( 'Bulk optimize selected' ), function () {
			var ids = Object.keys( selected ).filter( function ( k ) {
				return selected[ k ];
			} );
			if ( ! ids.length ) {
				SL.toast( t( 'Select products first.' ) );
				return Promise.resolve();
			}
			return SL.api( 'bulk', { body: { task: 'products', ids: ids } } ).then( function ( r ) {
				SL.toast( t( '%s items queued for background AI processing.', r.queued ), 'success' );
			} );
		} ) : null ) );
		v.appendChild( list );
		function load() {
			App.load( list, SL.api( 'products', { query: f } ), function ( r ) {
				if ( ! r.woocommerce ) {
					list.appendChild( SL.empty( t( 'WooCommerce is not active' ) ) );
					return;
				}
				list.appendChild( App.table( [
					{ label: '', render: function ( p ) {
						var cb = h( 'input', { type: 'checkbox', 'aria-label': t( 'Select' ), onChange: function () {
							selected[ p.id ] = cb.checked;
						} } );
						return cb;
					} },
					{ label: t( 'Product' ), render: function ( p ) {
						return h( 'div', { class: 'sl-row' }, p.thumb ? h( 'img', { src: p.thumb, alt: '', class: 'sl-thumb' } ) : null, h( 'div', null, App.ext( p.url, p.name ), h( 'div', { class: 'sl-muted sl-small', text: ( p.focus_keyword ? '🔑 ' + p.focus_keyword : t( 'no focus keyword' ) ) + ' · ' + t( p.stock || '' ) } ) ) );
					} },
					{ label: t( 'Score' ), render: function ( p ) {
						return SL.scoreRing( p.score, 40 );
					} },
					{ label: t( 'Short desc.' ), render: function ( p ) {
						return p.has_short ? '✓' : SL.badge( t( 'missing' ), 'bad' );
					} },
					{ label: t( 'Description words' ), render: function ( p ) {
						return SL.num( p.desc_words, 0 );
					} },
					{ label: t( 'Meta' ), render: function ( p ) {
						return p.has_meta ? '✓' : SL.badge( t( 'missing' ), 'warn' );
					} },
					{ label: t( 'Images without ALT' ), render: function ( p ) {
						return p.images_no_alt ? SL.badge( p.images_no_alt + '/' + p.images, 'warn' ) : '✓';
					} },
					{ label: '', render: function ( p ) {
						return h( 'div', { class: 'sl-row' }, App.cap( 'ai' ) ? App.abtn( '✨ ' + t( 'AI Product Optimizer' ), function () {
							var m = SL.modal( { title: t( 'AI Product Optimizer' ) } );
							m.body.appendChild( SL.spinner( t( 'Analyzing product data, queries and competitors…' ) ) );
							return SL.api( 'products/' + p.id + '/optimize', { method: 'POST' } ).then( function ( res ) {
								m.close();
								App.productResult( res, load );
							} ).catch( function ( e ) {
								m.close();
								throw e;
							} );
						}, 'primary' ) : null, App.btn( t( 'History' ), function () {
							SL.history( 'post', p.id );
						}, 'sm' ), p.pending_changes ? SL.badge( t( '%s pending', p.pending_changes ), 'warn' ) : null );
					} },
				], r.items ) );
				SL.append( list, App.pager( r.total, f.page, f.per_page, function ( pg ) {
					f.page = pg;
					load();
				} ) );
			} );
		}
		load();
	};

	/* ================================================================ Images */
	App.pages.images = function ( v ) {
		v.appendChild( App.header( t( 'AI Image SEO' ), t( 'Detect missing, generic, duplicate or stuffed ALT text and bad filenames; generate context-aware ALT from page, product and nearby text.' ), App.cap( 'manage' ) ? [ App.abtn( t( 'Scan media library' ), function () {
			return SL.api( 'images/scan', { method: 'POST' } ).then( function () {
				SL.toast( t( 'Scan queued.' ) );
			} );
		} ) ] : null ) );
		var f = { issue: 'missing_alt', page: 1, per_page: 40 };
		var stats = h( 'div' );
		var grid = h( 'div' );
		var selected = {};
		v.appendChild( stats );
		v.appendChild( grid );
		var labels = { missing_alt: t( 'Missing ALT' ), generic_alt: t( 'Generic ALT' ), duplicate_alt: t( 'Duplicate ALT' ), stuffed_alt: t( 'Keyword stuffing' ), filename_alt: t( 'Filename as ALT' ), long_alt: t( 'ALT too long' ), bad_filename: t( 'Bad filename' ), redundant_prefix: t( '“Image of…” prefix' ) };
		function load() {
			App.load( grid, SL.api( 'images', { query: f } ), function ( r ) {
				SL.clear( stats );
				stats.appendChild( h( 'div', { class: 'sl-actioncounts' }, Object.keys( labels ).map( function ( k ) {
					return h( 'button', { type: 'button', class: 'sl-actioncount' + ( f.issue === k ? ' is-active' : '' ), onClick: function () {
						f.issue = k;
						f.page = 1;
						load();
					} }, h( 'span', { class: 'sl-actioncount__n', text: SL.num( r.stats[ k ], 0 ) } ), h( 'span', { text: labels[ k ] } ) );
				} ) ) );
				stats.appendChild( h( 'p', { class: 'sl-muted sl-small', text: t( '%1$s images indexed · last scan %2$s', SL.num( r.stats.total, 0 ), SL.date( r.scanned ? new Date( r.scanned * 1000 ).toISOString() : '' ) ) } ) );
				selected = {};
				var bar = h( 'div', { class: 'sl-toolbar' }, App.btn( t( 'Select all' ), function () {
					Array.prototype.forEach.call( grid.querySelectorAll( 'input[type=checkbox]' ), function ( cb ) {
						cb.checked = true;
						selected[ cb.value ] = true;
					} );
				}, 'sm' ), App.cap( 'ai' ) ? App.abtn( '✨ ' + t( 'Generate ALT for selected' ), function () {
					var ids = Object.keys( selected ).filter( function ( k ) {
						return selected[ k ];
					} );
					if ( ! ids.length ) {
						SL.toast( t( 'Select images first.' ) );
						return Promise.resolve();
					}
					return SL.api( 'images/generate', { body: { ids: ids } } ).then( function ( res ) {
						if ( res.queued !== undefined ) {
							SL.toast( t( '%s items queued for background AI processing.', res.queued ), 'success' );
							return;
						}
						var m = SL.modal( { title: t( 'Generated ALT text' ), wide: true } );
						var keys = Object.keys( res.changes );
						m.body.appendChild( App.table( [ { label: t( 'Image' ), render: function ( k ) {
							return '#' + k;
						} }, { label: 'ALT', render: function ( k ) {
							return h( 'span', { dir: 'auto', text: res.changes[ k ].alt } );
						} }, { label: t( 'Caption' ), render: function ( k ) {
							return h( 'span', { dir: 'auto', text: res.changes[ k ].caption } );
						} } ], keys ) );
						m.foot.appendChild( App.abtn( t( 'Apply all' ), function () {
							return SL.api( 'changes/batch/' + res.batch_id + '/apply', { method: 'POST' } ).then( function () {
								SL.toast( t( 'ALT text applied.' ), 'success' );
								m.close();
								load();
							} );
						}, 'primary' ) );
					} );
				}, 'primary' ) : null );
				grid.appendChild( bar );
				if ( ! r.items.length ) {
					grid.appendChild( SL.empty( t( 'No images with this issue' ) ) );
					return;
				}
				grid.appendChild( h( 'div', { class: 'sl-imggrid' }, r.items.map( function ( img ) {
					var cb = h( 'input', { type: 'checkbox', value: img.attachment_id, 'aria-label': t( 'Select' ), onChange: function () {
						selected[ img.attachment_id ] = cb.checked;
					} } );
					return h( 'figure', { class: 'sl-img' }, h( 'label', { class: 'sl-img__sel' }, cb ), img.thumb ? h( 'img', { src: img.thumb, alt: img.alt || '', loading: 'lazy' } ) : h( 'div', { class: 'sl-img__ph' } ), h( 'figcaption', null, h( 'div', { class: 'sl-small sl-clip', dir: 'ltr', text: img.filename } ), h( 'div', { class: 'sl-small', dir: 'auto', text: img.alt ? 'ALT: ' + img.alt : t( 'No ALT' ) } ), img.parent_title ? h( 'div', { class: 'sl-muted sl-small sl-clip', dir: 'auto', text: '↳ ' + img.parent_title } ) : null, SL.chips( ( img.issues || '' ).split( ',' ).filter( Boolean ).map( function ( i ) {
						return labels[ i ] || i;
					} ), 'warn' ) ) );
				} ) ) );
				SL.append( grid, App.pager( r.total, f.page, f.per_page, function ( p ) {
					f.page = p;
					load();
				} ) );
			} );
		}
		load();
	};

	/* ================================================================ Internal links */
	App.pages.links = function ( v ) {
		v.appendChild( App.header( t( 'Internal Linking AI' ), t( 'Suggestions use anchors that already exist in your text, so links are added without rewriting. Every link is a reviewable, revertible change.' ), App.cap( 'ai' ) ? [ App.abtn( t( 'Find new opportunities' ), function () {
			return SL.api( 'links/build', { method: 'POST' } ).then( function () {
				SL.toast( t( 'Analyzing your link graph in the background.' ) );
			} );
		}, 'primary' ) ] : null ) );
		var body = h( 'div' );
		v.appendChild( body );
		function load() {
			App.load( body, SL.api( 'links' ), function ( r ) {
				body.appendChild( h( 'div', { class: 'sl-kpis' }, App.kpi( t( 'Orphan pages' ), SL.num( r.stats.orphans, 0 ) ), App.kpi( t( 'Avg. internal links per page' ), SL.num( r.stats.avg_inlinks ) ), App.kpi( t( 'Internal links' ), SL.num( r.stats.edges, 0 ) ), App.kpi( t( 'Suggestions' ), SL.num( r.items.length, 0 ) ) ) );
				body.appendChild( App.card( t( 'Suggestions' ), App.table( [
					{ label: t( 'From' ), render: function ( l ) {
						return App.ext( l.source_url, l.source_title );
					} },
					{ label: t( 'To' ), render: function ( l ) {
						return h( 'div', null, App.ext( l.target_url, l.target_title ), h( 'div', { class: 'sl-muted sl-small', text: t( '%s internal links today', l.target_inlinks ) } ) );
					} },
					{ label: t( 'Anchor' ), render: function ( l ) {
						return h( 'strong', { dir: 'auto', text: l.anchor } );
					} },
					{ label: t( 'Relevance' ), render: function ( l ) {
						return SL.meter( '', +l.relevance );
					} },
					{ label: t( 'Reason' ), render: function ( l ) {
						return h( 'span', { class: 'sl-small', text: l.reason } );
					} },
					{ label: '', render: function ( l ) {
						return h( 'div', { class: 'sl-row' }, App.cap( 'apply' ) ? App.abtn( t( 'Apply' ), function () {
							return SL.api( 'links/' + l.id + '/apply', { method: 'POST' } ).then( function ( res ) {
								SL.preview( res.change_id, load );
							} );
						}, 'primary' ) : null, App.cap( 'apply' ) ? App.btn( '✕', function () {
							SL.api( 'links/' + l.id + '/dismiss', { method: 'POST' } ).then( load ).catch( SL.fail );
						}, 'ghost', { 'aria-label': t( 'Dismiss' ) } ) : null );
					} },
				], r.items, { empty: t( 'No suggestions yet' ), emptyText: t( 'Run a site audit, then click “Find new opportunities”.' ) } ) ) );
				body.appendChild( App.card( t( 'Most linked pages' ), SL.charts.bars( r.stats.top_linked.map( function ( p ) {
					return { label: p.title || SL.path( p.url ), value: +p.inlinks };
				} ) ) ) );
			} );
		}
		load();
	};
} )( window, document );
