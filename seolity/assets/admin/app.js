/**
 * SEOlity admin SPA — shell, router, shared page helpers and core pages
 * (Dashboard, AI Action Center, AI SEO Chat, AI Changes).
 */
( function ( w, d ) {
	'use strict';
	var SL = w.SL;
	var C = SL.config;
	var t = SL.t;
	var h = SL.h;

	var App = ( SL.app = { pages: {}, state: {} } );

	/* ================================================================ helpers */
	App.card = function ( title, body, extra ) {
		return h( 'section', { class: 'sl-card' + ( extra && extra.class ? ' ' + extra.class : '' ) },
			title || ( extra && extra.actions ) ? h( 'header', { class: 'sl-card__head' }, h( 'h3', { text: title || '' } ), extra && extra.actions ? h( 'div', { class: 'sl-card__actions' }, extra.actions ) : null ) : null,
			h( 'div', { class: 'sl-card__body' }, body )
		);
	};
	App.kpi = function ( label, value, delta, opts ) {
		opts = opts || {};
		var good = delta === null || delta === undefined ? null : opts.invert ? delta < 0 : delta > 0;
		return h( 'div', { class: 'sl-kpi' },
			h( 'div', { class: 'sl-kpi__label', text: label } ),
			h( 'div', { class: 'sl-kpi__value', text: value } ),
			delta === null || delta === undefined ? h( 'div', { class: 'sl-kpi__delta sl-muted', text: opts.sub || '' } ) : h( 'div', { class: 'sl-kpi__delta ' + ( good ? 'is-up' : delta === 0 ? '' : 'is-down' ), text: ( delta > 0 ? '▲ ' : delta < 0 ? '▼ ' : '' ) + ( opts.abs ? SL.num( Math.abs( delta ) ) : SL.pct( delta ) ) } ),
			opts.spark ? h( 'div', { class: 'sl-kpi__spark' }, SL.charts.sparkline( opts.spark, { invert: opts.invert } ) ) : null
		);
	};
	App.btn = function ( label, onClick, kind, attrs ) {
		return h( 'button', Object.assign( { type: 'button', class: 'sl-btn' + ( kind ? ' sl-btn--' + kind : '' ), onClick: onClick }, attrs || {} ), label );
	};
	/** Async button: disables itself while the promise runs. */
	App.abtn = function ( label, fn, kind ) {
		var b = App.btn( label, function () {
			b.disabled = true;
			b.classList.add( 'is-busy' );
			Promise.resolve( fn() ).catch( SL.fail ).then( function () {
				b.disabled = false;
				b.classList.remove( 'is-busy' );
			} );
		}, kind );
		return b;
	};
	App.link = function ( label, route ) {
		return h( 'a', { href: '#/' + route, class: 'sl-link' }, label );
	};
	App.ext = function ( url, label ) {
		return h( 'a', { href: url, target: '_blank', rel: 'noopener noreferrer', class: 'sl-link', title: url, dir: 'auto' }, label || SL.path( url ) );
	};
	/**
	 * Table. cols: [{label, render(row) | key, class}]
	 */
	App.table = function ( cols, rows, opts ) {
		opts = opts || {};
		if ( ! rows || ! rows.length ) {
			return SL.empty( opts.empty || t( 'Nothing here yet' ), opts.emptyText || '' );
		}
		var thead = h( 'thead', null, h( 'tr', null, cols.map( function ( c ) {
			return h( 'th', { class: c.class || '', scope: 'col' }, c.label );
		} ) ) );
		var tbody = h( 'tbody', null, rows.map( function ( r ) {
			return h( 'tr', null, cols.map( function ( c ) {
				var v = c.render ? c.render( r ) : r[ c.key ];
				return h( 'td', { class: c.class || '' }, v === null || v === undefined || v === '' ? '—' : v );
			} ) );
		} ) );
		return h( 'div', { class: 'sl-table-wrap' }, h( 'table', { class: 'sl-table' }, thead, tbody ) );
	};
	App.select = function ( options, value, onChange, attrs ) {
		var s = h( 'select', Object.assign( { class: 'sl-input sl-select', onChange: function () {
			if ( onChange ) {
				onChange( s.value );
			}
		} }, attrs || {} ), options.map( function ( o ) {
			var opt = h( 'option', { value: o[ 0 ] }, o[ 1 ] );
			if ( String( o[ 0 ] ) === String( value ) ) {
				opt.selected = true;
			}
			return opt;
		} ) );
		return s;
	};
	App.field = function ( label, input, help ) {
		return h( 'label', { class: 'sl-field' }, h( 'span', { class: 'sl-field__label', text: label } ), input, help ? h( 'span', { class: 'sl-field__help', text: help } ) : null );
	};
	App.pager = function ( total, page, per, onPage ) {
		var pages = Math.ceil( ( total || 0 ) / per );
		if ( pages <= 1 ) {
			return null;
		}
		return h( 'div', { class: 'sl-pager' },
			App.btn( t( 'Previous' ), function () {
				onPage( page - 1 );
			}, 'sm', { disabled: page <= 1 } ),
			h( 'span', { text: t( 'Page %1$s of %2$s', SL.num( page, 0 ), SL.num( pages, 0 ) ) } ),
			App.btn( t( 'Next' ), function () {
				onPage( page + 1 );
			}, 'sm', { disabled: page >= pages } )
		);
	};
	App.query = function () {
		var q = {};
		var qs = ( location.hash.split( '?' )[ 1 ] || '' ).split( '&' );
		qs.forEach( function ( p ) {
			if ( p ) {
				var kv = p.split( '=' );
				q[ decodeURIComponent( kv[ 0 ] ) ] = decodeURIComponent( kv[ 1 ] || '' );
			}
		} );
		return q;
	};
	App.go = function ( route ) {
		location.hash = '#/' + route.replace( /^#?\//, '' );
	};
	App.header = function ( title, subtitle, actions ) {
		return h( 'div', { class: 'sl-page-head' }, h( 'div', null, h( 'h1', { text: title } ), subtitle ? h( 'p', { class: 'sl-muted', text: subtitle } ) : null ), actions ? h( 'div', { class: 'sl-page-head__actions' }, actions ) : null );
	};
	App.load = function ( view, promise, render ) {
		SL.clear( view );
		view.appendChild( SL.spinner() );
		return promise.then( function ( data ) {
			SL.clear( view );
			render( data );
		} ).catch( function ( e ) {
			SL.clear( view );
			view.appendChild( h( 'div', { class: 'sl-alert sl-alert--error', text: e.message } ) );
		} );
	};
	App.cap = function ( c ) {
		return App.state.boot && App.state.boot.caps && App.state.boot.caps[ c ];
	};
	App.priorityIcon = { critical: '🔴', high: '🟠', medium: '🟡', low: '🟢' };

	/** Handle the result of executing a recommendation / generic AI action. */
	App.handleResult = function ( res, refresh ) {
		if ( ! res ) {
			return;
		}
		if ( res.route ) {
			App.go( res.route );
			return;
		}
		if ( res.preview && res.preview.type === 'redirect' ) {
			var m = SL.modal( { title: t( 'Create redirect' ) } );
			var target = h( 'input', { class: 'sl-input', value: res.preview.target || '', dir: 'ltr' } );
			m.body.appendChild( h( 'div', { class: 'sl-stack' }, App.field( t( 'From' ), h( 'code', { dir: 'ltr', text: SL.path( res.preview.source ) } ) ), App.field( t( 'To' ), target ), res.preview.candidates && res.preview.candidates.length ? h( 'div', { class: 'sl-options' }, res.preview.candidates.map( function ( c ) {
				return h( 'button', { class: 'sl-option', type: 'button', onClick: function () {
					target.value = c.url;
				} }, c.title + ' (' + c.score + '%)' );
			} ) ) : null ) );
			m.foot.appendChild( App.btn( t( 'Cancel' ), m.close ) );
			m.foot.appendChild( App.abtn( t( 'Create 301 redirect' ), function () {
				return SL.api( 'redirects', { body: { source: res.preview.source, target: target.value, type: 301 } } ).then( function () {
					SL.toast( t( 'Redirect created.' ), 'success' );
					m.close();
					if ( refresh ) {
						refresh();
					}
				} );
			}, 'primary' ) );
			return;
		}
		if ( res.suggestions ) {
			App.linkSuggestions( res.suggestions, refresh );
			return;
		}
		if ( res.advice ) {
			App.cannibalModal( res );
			return;
		}
		if ( res.ai && res.before && res.after && res.optimized !== undefined ) {
			// Content optimization result → show first pending content change.
			if ( res.changes && res.changes.content ) {
				SL.preview( res.changes.content, refresh );
			}
			return;
		}
		if ( res.product && res.changes ) {
			App.productResult( res, refresh );
			return;
		}
		if ( res.changes ) {
			var ids = Object.keys( res.changes ).map( function ( k ) {
				return res.changes[ k ];
			} );
			if ( res.task && ( res.changes.meta_title || res.changes.meta_description ) ) {
				SL.toast( t( 'Suggestions are ready for review.' ), 'success' );
				App.go( 'changes?batch_id=' + ( res.batch_id || '' ) );
				return;
			}
			if ( ids.length ) {
				SL.preview( ids[ 0 ], refresh );
			}
			return;
		}
		if ( res.batch_id && res.queued !== undefined ) {
			SL.toast( t( '%s items queued for background AI processing.', res.queued ), 'success' );
			return;
		}
		if ( res.fixed !== undefined ) {
			SL.toast( t( '%s redirect chains fixed.', res.fixed ), 'success' );
		}
		if ( res.plan_id ) {
			SL.toast( t( 'Added to the content planner.' ), 'success' );
		}
		if ( refresh ) {
			refresh();
		}
	};

	App.recommendationRow = function ( r, refresh ) {
		return h( 'div', { class: 'sl-rec sl-rec--' + r.priority },
			h( 'div', { class: 'sl-rec__icon', 'aria-hidden': 'true' }, App.priorityIcon[ r.priority ] || '•' ),
			h( 'div', { class: 'sl-rec__main' },
				h( 'div', { class: 'sl-rec__title', text: r.title } ),
				h( 'div', { class: 'sl-rec__reason', text: r.reason } ),
				h( 'div', { class: 'sl-rec__meta' }, SL.priorityBadge( r.priority ), SL.badge( t( 'Impact' ) + ' ' + r.impact ), SL.badge( t( 'Effort' ) + ' ' + r.effort ), SL.badge( t( 'Confidence' ) + ' ' + r.confidence + '%' ), r.url ? App.ext( r.url ) : null )
			),
			h( 'div', { class: 'sl-rec__actions' },
				App.cap( 'ai' ) ? App.abtn( t( 'Do it' ), function () {
					return SL.api( 'recommendations/' + r.id + '/execute', { method: 'POST' } ).then( function ( res ) {
						App.handleResult( res, refresh );
					} );
				}, 'primary' ) : null,
				App.btn( t( 'Why?' ), function () {
					SL.why( r.title, SL.api( 'recommendations/' + r.id + '/why' ) );
				} ),
				App.cap( 'apply' ) ? App.btn( '✓', function () {
					SL.api( 'recommendations/' + r.id + '/status', { body: { status: 'done' } } ).then( refresh ).catch( SL.fail );
				}, 'ghost', { title: t( 'Mark as done' ), 'aria-label': t( 'Mark as done' ) } ) : null,
				App.cap( 'apply' ) ? App.btn( '✕', function () {
					SL.api( 'recommendations/' + r.id + '/status', { body: { status: 'dismissed' } } ).then( refresh ).catch( SL.fail );
				}, 'ghost', { title: t( 'Dismiss' ), 'aria-label': t( 'Dismiss' ) } ) : null
			)
		);
	};

	/* ================================================================ shell */
	var root;
	var view;
	var nav;
	var ICONS = { dashboard: '◧', actions: '⚡', ai: '✦', audit: '⛑', gsc: '📈', keywords: '🔑', content: '✎', products: '🛍', images: '🖼', links: '🔗', competitors: '⚔', experiments: '🧪', changes: '⟲', redirects: '↪', 404: '⚠', schema: '{}', sitemap: '🗺', robots: '🤖', reports: '📄', alerts: '🔔', automation: '⚙', knowledge: '📚', settings: '☰' };

	function shell() {
		root = d.getElementById( 'seolity-app' );
		if ( ! root ) {
			return false;
		}
		SL.clear( root );
		root.classList.add( 'sl-root' );
		if ( C.rtl ) {
			root.classList.add( 'is-rtl' );
		}
		SL.applyTheme( root );
		nav = h( 'nav', { class: 'sl-nav', 'aria-label': t( 'SEOlity navigation' ) } );
		( C.routes || [] ).forEach( function ( r ) {
			nav.appendChild( h( 'a', { href: '#/' + r.slug, class: 'sl-nav__item', 'data-route': r.slug }, h( 'span', { class: 'sl-nav__icon', 'aria-hidden': 'true' }, ICONS[ r.slug ] || '•' ), h( 'span', { class: 'sl-nav__label', text: r.label } ), h( 'span', { class: 'sl-nav__count', 'data-count': r.slug } ) ) );
		} );
		var themeBtn = h( 'button', { class: 'sl-icon-btn', type: 'button', title: t( 'Toggle dark mode' ), 'aria-label': t( 'Toggle dark mode' ), onClick: function () {
			var cur = root.getAttribute( 'data-theme' ) === 'dark' ? 'light' : 'dark';
			try {
				w.localStorage.setItem( 'seolity-theme', cur );
			} catch ( e ) {}
			SL.applyTheme( root );
		} }, '◐' );
		var brand = h( 'div', { class: 'sl-brand' }, C.brand && C.brand.logo ? h( 'img', { src: C.brand.logo, alt: '' } ) : h( 'span', { class: 'sl-brand__mark', 'aria-hidden': 'true' }, '✦' ), h( 'div', null, h( 'strong', { text: ( C.brand && C.brand.name ) || 'SEOlity' } ), h( 'small', { text: t( 'AI SEO Intelligence for WordPress' ) } ) ) );
		var top = h( 'header', { class: 'sl-top' }, h( 'button', { class: 'sl-icon-btn sl-menu-btn', type: 'button', 'aria-label': t( 'Menu' ), onClick: function () {
			root.classList.toggle( 'nav-open' );
		} }, '☰' ), h( 'div', { class: 'sl-top__status', id: 'sl-top-status' } ), themeBtn );
		view = h( 'main', { class: 'sl-view', id: 'sl-view', tabindex: '-1' } );
		root.appendChild( h( 'div', { class: 'sl-layout' }, h( 'aside', { class: 'sl-side' }, brand, nav, h( 'div', { class: 'sl-side__foot', text: 'v' + C.version } ) ), h( 'div', { class: 'sl-main' }, top, view ) ) );
		return true;
	}

	function topStatus( b ) {
		var box = d.getElementById( 'sl-top-status' );
		if ( ! box ) {
			return;
		}
		SL.clear( box );
		var modes = { manual: t( 'Manual approval' ), semi: t( 'Semi-automatic' ), autonomous: t( 'Autonomous' ) };
		box.appendChild( SL.badge( modes[ b.mode ] || b.mode, b.mode === 'autonomous' ? 'warn' : 'default' ) );
		box.appendChild( SL.badge( b.ai_ready ? t( 'AI connected' ) : t( 'AI not configured' ), b.ai_ready ? 'good' : 'bad' ) );
		box.appendChild( SL.badge( b.gsc ? t( 'Search Console connected' ) : t( 'Search Console not connected' ), b.gsc ? 'good' : 'warn' ) );
		if ( b.pending_changes ) {
			box.appendChild( h( 'a', { href: '#/changes', class: 'sl-badge sl-badge--brand' }, t( '%s changes awaiting review', b.pending_changes ) ) );
		}
		var setCount = function ( slug, n ) {
			var el = root.querySelector( '[data-count="' + slug + '"]' );
			if ( el ) {
				el.textContent = n ? String( n ) : '';
			}
		};
		setCount( 'alerts', b.unread_alerts );
		setCount( 'changes', b.pending_changes );
	}
	App.refreshBoot = function () {
		return SL.api( 'bootstrap' ).then( function ( b ) {
			App.state.boot = b;
			topStatus( b );
			return b;
		} );
	};

	function route() {
		var hash = location.hash.replace( /^#\/?/, '' );
		var slug = hash.split( '?' )[ 0 ] || C.route || 'dashboard';
		if ( ! App.pages[ slug ] ) {
			slug = 'dashboard';
		}
		Array.prototype.forEach.call( nav.querySelectorAll( '.sl-nav__item' ), function ( a ) {
			var on = a.getAttribute( 'data-route' ) === slug;
			a.classList.toggle( 'is-active', on );
			if ( on ) {
				a.setAttribute( 'aria-current', 'page' );
			} else {
				a.removeAttribute( 'aria-current' );
			}
		} );
		root.classList.remove( 'nav-open' );
		SL.clear( view );
		App.current = slug;
		try {
			App.pages[ slug ]( view, App.query() );
		} catch ( e ) {
			view.appendChild( h( 'div', { class: 'sl-alert sl-alert--error', text: e.message } ) );
			if ( w.console ) {
				w.console.error( e );
			}
		}
		view.focus( { preventScroll: true } );
		w.scrollTo( 0, 0 );
	}

	function boot() {
		if ( ! shell() ) {
			return;
		}
		var notice = root.getAttribute( 'data-gsc-notice' );
		App.refreshBoot().then( function () {
			if ( ! location.hash && C.route && C.route !== 'dashboard' ) {
				location.hash = '#/' + C.route;
			}
			w.addEventListener( 'hashchange', route );
			route();
			if ( notice ) {
				var msgs = { connected: [ t( 'Google Search Console connected. Now choose a property.' ), 'success' ], denied: [ t( 'Google access was denied.' ), 'error' ], state_error: [ t( 'The connection link expired. Please try again.' ), 'error' ], token_error: [ t( 'Google did not return a refresh token. Remove SEOlity from your Google account permissions and connect again.' ), 'error' ] };
				if ( msgs[ notice ] ) {
					SL.toast( msgs[ notice ][ 0 ], msgs[ notice ][ 1 ] );
				}
			}
		} ).catch( function ( e ) {
			SL.clear( root );
			root.appendChild( h( 'div', { class: 'sl-alert sl-alert--error', text: e.message } ) );
		} );
	}

	/* ================================================================ Dashboard */
	App.pages.dashboard = function ( v ) {
		v.appendChild( App.header( t( 'Dashboard' ), t( 'Your SEO command center: health, traffic, opportunities and AI activity.' ), [ App.btn( t( 'What should I do today?' ), function () {
			App.go( 'actions' );
		}, 'primary' ) ] ) );
		var body = h( 'div' );
		v.appendChild( body );
		App.load( body, SL.api( 'dashboard' ), function ( dsh ) {
			var b = App.state.boot || {};
			if ( ! b.ai_ready || ! b.gsc || ! dsh.audit ) {
				body.appendChild( App.onboarding( b, dsh ) );
			}
			var g = dsh.gsc;
			var series = g && g.series ? g.series.slice( -g.period.days ) : [];
			var kpis = h( 'div', { class: 'sl-kpis' },
				h( 'div', { class: 'sl-kpi sl-kpi--health' }, h( 'div', { class: 'sl-kpi__label', text: t( 'SEO Health Score' ) } ), SL.scoreRing( dsh.health.score, 84 ), h( 'a', { href: '#/audit?tab=health', class: 'sl-link sl-small' }, t( 'Why this score?' ) ) ),
				App.kpi( t( 'Organic clicks' ), g ? SL.num( g.current.clicks, 0 ) : '—', g ? g.delta.clicks : null, { spark: series.map( function ( r ) {
					return r.clicks;
				} ), sub: g ? '' : t( 'Connect Search Console' ) } ),
				App.kpi( t( 'Impressions' ), g ? SL.num( g.current.impressions, 0 ) : '—', g ? g.delta.impressions : null, { spark: series.map( function ( r ) {
					return r.impressions;
				} ) } ),
				App.kpi( t( 'CTR' ), g ? SL.num( g.current.ctr ) + '%' : '—', g ? g.delta.ctr : null ),
				App.kpi( t( 'Avg. position' ), g ? SL.num( g.current.position ) : '—', g ? g.delta.position : null, { abs: true, spark: series.map( function ( r ) {
					return r.position;
				} ), invert: true } ),
				App.kpi( t( 'Indexable pages' ), SL.num( dsh.indexed.indexable, 0 ), null, { sub: t( '%s crawled', SL.num( dsh.indexed.crawled, 0 ) ) } )
			);
			body.appendChild( kpis );

			var counts = dsh.action.counts;
			var ac = h( 'div', { class: 'sl-actioncounts' }, [ [ 'critical', t( 'Critical issues' ) ], [ 'high', t( 'High priority opportunities' ) ], [ 'medium', t( 'Optimization opportunities' ) ], [ 'low', t( 'Improvements' ) ] ].map( function ( x ) {
				return h( 'a', { href: '#/actions?priority=' + x[ 0 ], class: 'sl-actioncount sl-actioncount--' + x[ 0 ] }, h( 'span', { class: 'sl-actioncount__n', text: App.priorityIcon[ x[ 0 ] ] + ' ' + SL.num( counts[ x[ 0 ] ], 0 ) } ), h( 'span', { text: x[ 1 ] } ) );
			} ) );
			body.appendChild( App.card( t( 'AI Action Center' ), [ ac, h( 'div', { class: 'sl-recs' }, dsh.action.today.slice( 0, 5 ).map( function ( r ) {
				return App.recommendationRow( r, function () {
					App.pages.dashboard( SL.clear( v ) );
				} );
			} ) ), dsh.action.today.length ? null : SL.empty( t( 'No recommendations yet' ), t( 'Run a site audit and connect Search Console — SEOlity will tell you exactly what to do.' ) ) ], { actions: [ App.link( t( 'See all' ), 'actions' ) ] } ) );

			var grid = h( 'div', { class: 'sl-grid-2' } );
			grid.appendChild( App.card( t( 'Click & impression trend' ), g && g.series.length ? SL.charts.line( g.series, { x: 'date', series: [ { key: 'clicks', label: t( 'Clicks' ) }, { key: 'impressions', label: t( 'Impressions' ), axis: 'right' } ] } ) : SL.empty( t( 'No Search Console data' ), t( 'Connect Google Search Console to see real traffic.' ), App.btn( t( 'Connect' ), function () {
				App.go( 'gsc' );
			}, 'primary' ) ) ) );
			grid.appendChild( App.briefingCard( dsh.briefing ) );
			body.appendChild( grid );

			var grid2 = h( 'div', { class: 'sl-grid-3' } );
			var errs = { critical: 0, high: 0, medium: 0, low: 0 };
			( dsh.errors || [] ).forEach( function ( e ) {
				errs[ e.severity ] = +e.c;
			} );
			grid2.appendChild( App.card( t( 'Errors' ), SL.charts.donut( [ { label: t( 'Critical' ), value: errs.critical, color: 'var(--sl-bad)' }, { label: t( 'High' ), value: errs.high, color: 'var(--sl-orange)' }, { label: t( 'Medium' ), value: errs.medium, color: 'var(--sl-warn)' }, { label: t( 'Low' ), value: errs.low, color: 'var(--sl-good)' } ], { size: 130 } ), { actions: [ App.link( t( 'Open audit' ), 'audit' ) ] } ) );
			var ex = dsh.experiments || {};
			grid2.appendChild( App.card( t( 'Experiments' ), h( 'div', { class: 'sl-statlist' }, [ [ t( 'Running' ), ex.running ], [ t( 'Positive' ), ex.positive ], [ t( 'Negative' ), ex.negative ], [ t( 'Neutral' ), ex.neutral ] ].map( function ( x ) {
				return h( 'div', null, h( 'span', { text: x[ 0 ] } ), h( 'strong', { text: SL.num( x[ 1 ] || 0, 0 ) } ) );
			} ) ), { actions: [ App.link( t( 'Details' ), 'experiments' ) ] } ) );
			grid2.appendChild( App.card( t( 'AI actions' ), h( 'div', { class: 'sl-statlist' }, h( 'div', null, h( 'span', { text: t( 'Applied (30 days)' ) } ), h( 'strong', { text: SL.num( dsh.ai_actions.applied_30d, 0 ) } ) ), h( 'div', null, h( 'span', { text: t( 'Awaiting review' ) } ), h( 'strong', { text: SL.num( dsh.ai_actions.pending, 0 ) } ) ) ), { actions: [ App.link( t( 'Review' ), 'changes' ) ] } ) );
			body.appendChild( grid2 );

			var grid3 = h( 'div', { class: 'sl-grid-2' } );
			grid3.appendChild( App.card( t( 'Alerts' ), dsh.alerts.length ? h( 'ul', { class: 'sl-list' }, dsh.alerts.map( function ( a ) {
				return h( 'li', null, SL.priorityBadge( a.severity ), ' ', a.title );
			} ) ) : SL.empty( t( 'All quiet' ), t( 'No new alerts.' ) ), { actions: [ App.link( t( 'All alerts' ), 'alerts' ) ] } ) );
			grid3.appendChild( App.card( t( 'Competitor status' ), dsh.competitors.length ? App.table( [ { label: t( 'Competitor' ), key: 'name' }, { label: t( 'Pages' ), render: function ( c ) {
				return SL.num( c.pages, 0 );
			} }, { label: t( 'New pages' ), render: function ( c ) {
				return SL.num( c.new_pages, 0 );
			} }, { label: t( 'Last analyzed' ), render: function ( c ) {
				return SL.date( c.last );
			} } ], dsh.competitors ) : SL.empty( t( 'No competitors yet' ), t( 'Add competitors to monitor their content and find gaps.' ), App.btn( t( 'Add competitor' ), function () {
				App.go( 'competitors' );
			} ) ) ) );
			body.appendChild( grid3 );
		} );
	};

	App.onboarding = function ( b, dsh ) {
		var steps = [
			[ b.ai_ready, t( 'Connect an AI provider' ), t( 'Add an API key (Anthropic, OpenAI, Gemini, OpenRouter) or a local LLM.' ), 'settings?tab=ai' ],
			[ b.gsc, t( 'Connect Google Search Console' ), t( 'Real clicks, impressions and queries power every recommendation.' ), 'gsc' ],
			[ !! dsh.audit, t( 'Run your first site audit' ), t( 'SEOlity crawls your site in the background without slowing it down.' ), 'audit' ],
			[ dsh.competitors.length > 0, t( 'Add competitors' ), t( 'Monitor competitor content and discover gaps.' ), 'competitors' ],
		];
		return App.card( t( 'Get started' ), h( 'ol', { class: 'sl-onboarding' }, steps.map( function ( s ) {
			return h( 'li', { class: s[ 0 ] ? 'is-done' : '' }, h( 'span', { class: 'sl-onboarding__check', 'aria-hidden': 'true' }, s[ 0 ] ? '✓' : '' ), h( 'div', null, h( 'strong', { text: s[ 1 ] } ), h( 'p', { class: 'sl-muted', text: s[ 2 ] } ) ), s[ 0 ] ? null : App.btn( t( 'Start' ), function () {
				App.go( s[ 3 ] );
			}, 'primary' ) );
		} ) ), { class: 'sl-card--onboarding' } );
	};

	App.briefingCard = function ( br ) {
		var body;
		if ( ! br || ! br.data || ! br.data.briefing ) {
			body = SL.empty( t( 'No briefing yet' ), t( 'Your daily SEO briefing is generated every morning.' ), App.cap( 'ai' ) ? App.abtn( t( 'Generate now' ), function () {
				return SL.api( 'briefing/generate', { method: 'POST' } ).then( function () {
					App.go( 'dashboard?r=' + Date.now() );
				} );
			}, 'primary' ) : null );
		} else {
			var b = br.data.briefing;
			body = h( 'div', { class: 'sl-briefing' },
				h( 'div', { class: 'sl-muted sl-small', text: SL.date( br.period_start ) } ),
				h( 'h4', { text: b.headline } ),
				h( 'p', { text: b.summary } ),
				h( 'ul', { class: 'sl-highlights' }, ( b.highlights || [] ).slice( 0, 6 ).map( function ( x ) {
					var icon = { win: '📈', loss: '📉', risk: '⚠️', info: 'ℹ️' }[ x.type ] || '•';
					return h( 'li', null, h( 'span', { 'aria-hidden': 'true' }, icon + ' ' ), h( 'strong', { text: x.title } ), x.detail ? h( 'div', { class: 'sl-muted sl-small', text: x.detail } ) : null );
				} ) ),
				b.actions && b.actions.length ? h( 'div', null, h( 'h4', { text: t( 'Do today' ) } ), h( 'ol', null, b.actions.map( function ( a ) {
					return h( 'li', null, App.priorityIcon[ a.priority ] + ' ', h( 'strong', { text: a.title } ), h( 'div', { class: 'sl-muted sl-small', text: a.reason } ) );
				} ) ) ) : null
			);
		}
		return App.card( '☀️ ' + t( "Today's SEO Briefing" ), body );
	};

	/* ================================================================ AI Action Center */
	App.pages.actions = function ( v, q ) {
		var filter = q.priority || '';
		v.appendChild( App.header( t( 'What Should I Do Today?' ), t( 'AI-prioritized actions ranked by impact, effort and confidence. Every suggestion explains itself — click “Why?”.' ), [ App.cap( 'ai' ) ? App.abtn( t( 'Refresh recommendations' ), function () {
			return SL.api( 'recommendations/refresh', { method: 'POST' } ).then( function () {
				SL.toast( t( 'Refreshing in the background — reload in a minute.' ) );
			} );
		} ) : null ] ) );
		var body = h( 'div' );
		v.appendChild( body );
		function load() {
			App.load( body, Promise.all( [ SL.api( 'action-center' ), SL.api( 'recommendations', { query: { priority: filter, limit: 100 } } ) ] ), function ( r ) {
				var counts = r[ 0 ].counts;
				body.appendChild( h( 'div', { class: 'sl-actioncounts' }, [ [ '', t( 'All' ) ], [ 'critical', t( 'Critical issues' ) ], [ 'high', t( 'High priority opportunities' ) ], [ 'medium', t( 'Optimization opportunities' ) ], [ 'low', t( 'Improvements' ) ] ].map( function ( x ) {
					var n = x[ 0 ] ? counts[ x[ 0 ] ] : counts.critical + counts.high + counts.medium + counts.low;
					return h( 'button', { type: 'button', class: 'sl-actioncount sl-actioncount--' + ( x[ 0 ] || 'all' ) + ( filter === x[ 0 ] ? ' is-active' : '' ), onClick: function () {
						filter = x[ 0 ];
						load();
					} }, h( 'span', { class: 'sl-actioncount__n', text: ( App.priorityIcon[ x[ 0 ] ] || '∑' ) + ' ' + SL.num( n, 0 ) } ), h( 'span', { text: x[ 1 ] } ) );
				} ) ) );
				if ( r[ 0 ].pending_changes ) {
					body.appendChild( h( 'div', { class: 'sl-alert sl-alert--info' }, t( '%s AI changes are waiting for your approval.', r[ 0 ].pending_changes ), ' ', App.link( t( 'Review now' ), 'changes' ) ) );
				}
				body.appendChild( r[ 1 ].length ? h( 'div', { class: 'sl-recs' }, r[ 1 ].map( function ( rec ) {
					return App.recommendationRow( rec, load );
				} ) ) : SL.empty( t( 'Nothing to do here' ), t( 'Great job — or SEOlity needs more data (audit + Search Console).' ) ) );
			} );
		}
		load();
	};

	/* ================================================================ AI SEO Chat */
	App.pages.ai = function ( v ) {
		var cid = App.state.chatId || '';
		v.appendChild( App.header( t( 'AI SEO Chat' ), t( 'Ask anything about your site. Answers are grounded in your real Search Console, audit, competitor and experiment data.' ), [ App.btn( t( 'New conversation' ), function () {
			App.state.chatId = '';
			App.pages.ai( SL.clear( v ) );
		} ) ] ) );
		var log = h( 'div', { class: 'sl-chat__log', 'aria-live': 'polite' } );
		var input = h( 'textarea', { class: 'sl-input sl-chat__input', rows: 2, placeholder: t( 'e.g. Why did my organic sales drop this month?' ), dir: 'auto' } );
		var send = App.btn( t( 'Send' ), ask, 'primary' );
		var samples = [ t( 'Why has my organic traffic dropped?' ), t( 'Why does this product not rank?' ), t( 'Which pages should I optimize today?' ), t( 'What did my competitor do recently?' ), t( 'Why does this page have a low CTR?' ), t( 'Should I redirect this URL?' ) ];
		var chips = h( 'div', { class: 'sl-chips' }, samples.map( function ( s ) {
			return h( 'button', { type: 'button', class: 'sl-chip sl-chip--btn', onClick: function () {
				input.value = s;
				input.focus();
			} }, s );
		} ) );
		function bubble( role, content, extra ) {
			var b = h( 'div', { class: 'sl-msg sl-msg--' + role }, role === 'assistant' ? SL.markdown( content ) : h( 'div', { dir: 'auto', text: content } ) );
			if ( extra && extra.actions && extra.actions.length ) {
				b.appendChild( h( 'div', { class: 'sl-msg__actions' }, extra.actions.map( function ( a ) {
					return h( 'a', { href: a.route, class: 'sl-btn sl-btn--sm' }, a.title );
				} ) ) );
			}
			if ( extra && extra.data_used && extra.data_used.length ) {
				b.appendChild( h( 'details', { class: 'sl-msg__data' }, h( 'summary', { text: t( 'Data used' ) } ), h( 'ul', null, extra.data_used.map( function ( x ) {
					return h( 'li', { text: x } );
				} ) ) ) );
			}
			log.appendChild( b );
			log.scrollTop = log.scrollHeight;
		}
		function ask() {
			var msg = input.value.trim();
			if ( ! msg ) {
				return;
			}
			input.value = '';
			bubble( 'user', msg );
			var thinking = h( 'div', { class: 'sl-msg sl-msg--assistant is-thinking' }, SL.spinner( t( 'Analyzing your data…' ) ) );
			log.appendChild( thinking );
			send.disabled = true;
			SL.api( 'chat', { body: { message: msg, conversation_id: cid } } ).then( function ( r ) {
				thinking.remove();
				cid = App.state.chatId = r.conversation_id;
				bubble( 'assistant', r.answer, r );
			} ).catch( function ( e ) {
				thinking.remove();
				bubble( 'assistant', '⚠️ ' + e.message );
			} ).then( function () {
				send.disabled = false;
			} );
		}
		input.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' && ! e.shiftKey ) {
				e.preventDefault();
				ask();
			}
		} );
		var history = h( 'div', { class: 'sl-chat__history' } );
		v.appendChild( h( 'div', { class: 'sl-chat' }, h( 'div', { class: 'sl-chat__main' }, log, chips, h( 'div', { class: 'sl-chat__compose' }, input, send ) ), history ) );
		if ( cid ) {
			SL.api( 'chat/' + cid ).then( function ( rows ) {
				rows.forEach( function ( r ) {
					bubble( r.role, r.content, r.meta || {} );
				} );
			} );
		} else {
			bubble( 'assistant', t( 'Hi! I am your AI SEO strategist. Ask me about traffic, rankings, products, competitors or what to do next.' ) );
		}
		SL.api( 'chat/conversations' ).then( function ( list ) {
			history.appendChild( h( 'h4', { text: t( 'Recent conversations' ) } ) );
			list.forEach( function ( c ) {
				history.appendChild( h( 'button', { type: 'button', class: 'sl-option', onClick: function () {
					App.state.chatId = c.conversation_id;
					App.pages.ai( SL.clear( v ) );
				} }, ( c.first_question || '…' ).slice( 0, 60 ) ) );
			} );
		} ).catch( function () {} );
	};

	/* ================================================================ AI Changes (approval queue + log) */
	App.pages.changes = function ( v, q ) {
		var f = { status: q.status || ( q.batch_id ? '' : 'pending' ), batch_id: q.batch_id || '', page: 1 };
		v.appendChild( App.header( t( 'AI Changes' ), t( 'Every change is previewed, approved, logged and versioned. Nothing destructive happens without your approval unless you enable Autonomous mode.' ) ) );
		var bar = h( 'div', { class: 'sl-toolbar' } );
		var body = h( 'div' );
		v.appendChild( bar );
		v.appendChild( body );
		bar.appendChild( App.select( [ [ '', t( 'All statuses' ) ], [ 'pending', t( 'Pending approval' ) ], [ 'applied', t( 'Applied' ) ], [ 'rejected', t( 'Rejected' ) ], [ 'reverted', t( 'Reverted' ) ] ], f.status, function ( val ) {
			f.status = val;
			f.page = 1;
			load();
		} ) );
		function load() {
			App.load( body, SL.api( 'changes', { query: f } ), function ( res ) {
				if ( f.batch_id && res.items.some( function ( c ) {
					return c.status === 'pending';
				} ) ) {
					body.appendChild( h( 'div', { class: 'sl-toolbar' }, App.abtn( t( 'Apply all in this batch' ), function () {
						return SL.api( 'changes/batch/' + f.batch_id + '/apply', { method: 'POST' } ).then( load );
					}, 'primary' ), App.abtn( t( 'Reject all' ), function () {
						return SL.api( 'changes/batch/' + f.batch_id + '/reject', { method: 'POST' } ).then( load );
					} ) ) );
				}
				body.appendChild( App.table( [
					{ label: t( 'Item' ), render: function ( c ) {
						return h( 'div', null, h( 'strong', { text: c.label || '#' + c.object_id } ), h( 'div', { class: 'sl-muted sl-small', text: SL.fieldLabel( c.field ) + ' · ' + t( c.object_type ) } ) );
					} },
					{ label: t( 'After' ), render: function ( c ) {
						return h( 'div', { class: 'sl-clip', dir: 'auto', text: SL.stripHtml( c.after_value || '' ).slice( 0, 140 ) } );
					} },
					{ label: t( 'Risk' ), render: function ( c ) {
						return SL.badge( t( c.risk ), c.risk === 'high' ? 'bad' : c.risk === 'medium' ? 'warn' : 'good' );
					} },
					{ label: t( 'Status' ), render: function ( c ) {
						return SL.badge( t( c.status ), c.status === 'applied' ? 'good' : c.status === 'pending' ? 'warn' : 'default' );
					} },
					{ label: t( 'Source' ), render: function ( c ) {
						return t( c.source );
					} },
					{ label: t( 'Score' ), render: function ( c ) {
						return c.score_before !== null && c.score_before !== undefined ? c.score_before + ' → ' + c.score_after : '—';
					} },
					{ label: t( 'Date' ), render: function ( c ) {
						return SL.date( c.applied_at || c.created_at );
					} },
					{ label: '', render: function ( c ) {
						return h( 'div', { class: 'sl-row' }, App.btn( c.status === 'pending' ? t( 'Review' ) : t( 'View' ), function () {
							SL.preview( c.id, load );
						}, c.status === 'pending' ? 'primary' : 'sm' ), App.btn( t( 'History' ), function () {
							SL.history( c.object_type, c.object_id );
						}, 'sm' ) );
					} },
				], res.items, { empty: t( 'No changes' ), emptyText: t( 'AI suggestions you generate will appear here for approval.' ) } ) );
				SL.append( body, App.pager( res.total, f.page, res.per_page, function ( p ) {
					f.page = p;
					load();
				} ) );
			} );
		}
		load();
	};

	/* ================================================================ Shared modals */
	App.linkSuggestions = function ( list, refresh ) {
		var m = SL.modal( { title: t( 'Internal link suggestions' ), wide: true } );
		if ( ! list.length ) {
			m.body.appendChild( SL.empty( t( 'No good link opportunities found' ), t( 'SEOlity only suggests links whose anchor text already exists in the source content.' ) ) );
			return;
		}
		m.body.appendChild( App.table( [
			{ label: t( 'Anchor' ), render: function ( s ) {
				return h( 'strong', { dir: 'auto', text: s.anchor } );
			} },
			{ label: t( 'Target' ), render: function ( s ) {
				return s.target_url ? App.ext( s.target_url ) : s.title || '#' + s.target_id;
			} },
			{ label: t( 'Relevance' ), render: function ( s ) {
				return SL.num( s.relevance, 0 ) + '%';
			} },
			{ label: t( 'Reason' ), render: function ( s ) {
				return h( 'span', { class: 'sl-small', text: s.reason } );
			} },
			{ label: '', render: function ( s ) {
				return s.id ? App.abtn( t( 'Apply' ), function () {
					return SL.api( 'links/' + s.id + '/apply', { method: 'POST' } ).then( function ( r ) {
						SL.preview( r.change_id, refresh );
					} );
				}, 'primary' ) : null;
			} },
		], list ) );
	};

	App.cannibalModal = function ( r ) {
		var m = SL.modal( { title: t( 'Cannibalization: %s', r.query ), wide: true } );
		var a = r.advice;
		var labels = { merge: t( 'Merge' ), redirect: t( 'Redirect' ), differentiate: t( 'Differentiate' ), internal_link: t( 'Internal link' ), retarget: t( 'Change keyword target' ), none: t( 'No action' ) };
		m.body.appendChild( h( 'div', { class: 'sl-alert sl-alert--info' }, h( 'strong', { text: t( 'Recommended action' ) + ': ' + ( labels[ a.action ] || a.action ) } ), ' · ', t( 'Confidence' ) + ' ' + a.confidence + '%' ) );
		m.body.appendChild( h( 'p', { text: a.explanation } ) );
		m.body.appendChild( h( 'p', null, h( 'strong', { text: t( 'Primary URL' ) + ': ' } ), App.ext( a.primary_url ) ) );
		m.body.appendChild( h( 'ol', null, a.steps.map( function ( s ) {
			return h( 'li', { text: s } );
		} ) ) );
		m.body.appendChild( App.table( [
			{ label: t( 'Page' ), render: function ( p ) {
				return App.ext( p.page );
			} },
			{ label: t( 'Clicks' ), key: 'clicks' },
			{ label: t( 'Impressions' ), key: 'impressions' },
			{ label: t( 'Position' ), key: 'position' },
			{ label: t( 'Share' ), render: function ( p ) {
				return p.share + '%';
			} },
		], r.pages ) );
		if ( a.action === 'redirect' || a.action === 'merge' ) {
			r.pages.filter( function ( p ) {
				return p.page !== a.primary_url;
			} ).forEach( function ( p ) {
				m.foot.appendChild( App.abtn( t( 'Redirect %s → primary', SL.path( p.page ) ), function () {
					return new Promise( function ( resolve ) {
						SL.confirm( t( 'Create a 301 redirect from %1$s to %2$s?', SL.path( p.page ), SL.path( a.primary_url ) ), function () {
							SL.api( 'redirects', { body: { source: p.page, target: a.primary_url, type: 301 } } ).then( function () {
								SL.toast( t( 'Redirect created.' ), 'success' );
								resolve();
							} ).catch( function ( e ) {
								SL.fail( e );
								resolve();
							} );
						} );
					} );
				} ) );
			} );
		}
	};

	App.productResult = function ( r, refresh ) {
		var m = SL.modal( { title: t( 'AI Product Optimizer' ) + ' — ' + r.product.name, wide: true } );
		var ai = r.ai;
		m.body.appendChild( h( 'div', { class: 'sl-opt__scores' }, h( 'div', null, h( 'div', { class: 'sl-small sl-muted', text: t( 'SEO Score Before' ) } ), SL.scoreRing( r.before.score, 64 ) ), h( 'div', { class: 'sl-opt__arrow' }, C.rtl ? '←' : '→' ), h( 'div', null, h( 'div', { class: 'sl-small sl-muted', text: t( 'SEO Score After' ) } ), SL.scoreRing( r.after.score, 64 ) ) ) );
		if ( r.warnings && r.warnings.length ) {
			m.body.appendChild( h( 'div', { class: 'sl-alert sl-alert--warn' }, h( 'ul', null, r.warnings.map( function ( x ) {
				return h( 'li', { text: x } );
			} ) ) ) );
		}
		var rows = Object.keys( r.changes ).map( function ( k ) {
			return { key: k, id: r.changes[ k ] };
		} );
		m.body.appendChild( h( 'h4', { text: t( 'Proposed changes' ) } ) );
		m.body.appendChild( App.table( [
			{ label: t( 'Field' ), render: function ( x ) {
				return x.key.indexOf( 'alt_' ) === 0 ? t( 'Image ALT' ) + ' #' + x.key.slice( 4 ) : SL.fieldLabel( x.key );
			} },
			{ label: '', render: function ( x ) {
				return h( 'div', { class: 'sl-row' }, App.btn( t( 'Preview' ), function () {
					SL.preview( x.id, refresh );
				}, 'sm' ), App.abtn( t( 'Apply' ), function () {
					return SL.api( 'changes/' + x.id + '/apply', { method: 'POST' } ).then( function () {
						SL.toast( t( 'Applied.' ), 'success' );
					} );
				}, 'primary' ) );
			} },
		], rows ) );
		var info = h( 'div', { class: 'sl-grid-2' },
			h( 'div', null, h( 'h4', { text: t( 'Keywords' ) } ), h( 'p', null, h( 'strong', { text: t( 'Focus' ) + ': ' } ), ai.focus_keyword ), h( 'div', { class: 'sl-small', text: t( 'Secondary' ) } ), SL.chips( ai.secondary_keywords, 'kw' ), h( 'div', { class: 'sl-small', text: t( 'Long-tail' ) } ), SL.chips( ai.long_tail_keywords ), h( 'div', { class: 'sl-small', text: t( 'Semantic' ) } ), SL.chips( ai.semantic_keywords ) ),
			h( 'div', null, h( 'h4', { text: t( 'Benefits' ) } ), h( 'ul', null, ai.benefits.map( function ( b ) {
				return h( 'li', { text: b } );
			} ) ), h( 'h4', { text: t( 'Content weaknesses' ) } ), h( 'ul', null, ai.content_weaknesses.map( function ( b ) {
				return h( 'li', { text: b } );
			} ) ) ),
			h( 'div', null, h( 'h4', { text: t( 'Missing attributes' ) } ), SL.chips( ai.missing_attributes, 'warn' ), h( 'h4', { text: t( 'Suggested categories' ) } ), SL.chips( ai.suggested_categories ), h( 'h4', { text: t( 'Suggested tags' ) } ), SL.chips( ai.suggested_tags ) ),
			h( 'div', null, h( 'h4', { text: t( 'CTA ideas' ) } ), h( 'ul', null, ai.cta_suggestions.map( function ( b ) {
				return h( 'li', { text: b } );
			} ) ), h( 'h4', { text: t( 'Schema' ) } ), h( 'ul', null, ai.schema_issues.map( function ( b ) {
				return h( 'li', { text: b } );
			} ) ), h( 'h4', { text: t( 'Competitor gaps' ) } ), h( 'ul', null, ai.competitor_gaps.map( function ( b ) {
				return h( 'li', { text: b } );
			} ) ) )
		);
		m.body.appendChild( info );
		if ( ai.internal_links && ai.internal_links.length ) {
			m.body.appendChild( h( 'h4', { text: t( 'Internal link ideas' ) } ) );
			m.body.appendChild( h( 'ul', null, ai.internal_links.map( function ( l ) {
				return h( 'li', null, h( 'strong', { text: l.anchor } ), ' → ', l.target_url, h( 'div', { class: 'sl-muted sl-small', text: l.reason } ) );
			} ) ) );
		}
		if ( r.batch_id ) {
			m.foot.appendChild( App.abtn( t( 'Apply all' ), function () {
				return SL.api( 'changes/batch/' + r.batch_id + '/apply', { method: 'POST' } ).then( function () {
					SL.toast( t( 'All changes applied.' ), 'success' );
					m.close();
					if ( refresh ) {
						refresh();
					}
				} );
			}, 'primary' ) );
		}
	};

	if ( d.readyState === 'loading' ) {
		d.addEventListener( 'DOMContentLoaded', boot );
	} else {
		setTimeout( boot, 0 );
	}
} )( window, document );
