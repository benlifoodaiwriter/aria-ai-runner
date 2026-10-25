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
	/**
	 * Card with an ARIAGH-style header: icon tile + title + optional subtitle.
	 * extra: { icon, sub, actions, class }. The icon defaults to the current page's icon.
	 */
	App.card = function ( title, body, extra ) {
		extra = extra || {};
		var icon = extra.icon === false ? null : extra.icon || ( App.current ? App.icon( App.current ) : null );
		return h( 'section', { class: 'sl-card' + ( extra.class ? ' ' + extra.class : '' ) },
			title || extra.actions ? h( 'header', { class: 'sl-card__head' },
				h( 'div', { class: 'sl-card__title' },
					title && icon ? h( 'span', { class: 'sl-card__ic' }, SL.icon( icon, 18 ) ) : null,
					h( 'div', null, h( 'h3', { text: title || '' } ), extra.sub ? h( 'p', { text: extra.sub } ) : null )
				),
				extra.actions ? h( 'div', { class: 'sl-card__actions' }, extra.actions ) : null
			) : null,
			h( 'div', { class: 'sl-card__body' }, body )
		);
	};
	App.kpi = function ( label, value, delta, opts ) {
		opts = opts || {};
		var good = delta === null || delta === undefined ? null : opts.invert ? delta < 0 : delta > 0;
		return h( 'div', { class: 'sl-kpi' + ( opts.icon ? ' has-icon' : '' ) },
			opts.icon ? h( 'span', { class: 'sl-kpi__ic is-' + ( opts.tone || 'blue' ) }, SL.icon( opts.icon, 22 ) ) : null,
			h( 'div', { class: 'sl-kpi__label', text: label } ),
			h( 'div', { class: 'sl-kpi__value', text: value } ),
			delta === null || delta === undefined ? h( 'div', { class: 'sl-kpi__delta sl-muted', text: opts.sub || '' } ) : h( 'div', { class: 'sl-kpi__delta ' + ( good ? 'is-up' : delta === 0 ? '' : 'is-down' ) }, delta ? SL.icon( delta > 0 ? 'arrow-up' : 'arrow-down', 13 ) : null, h( 'span', { text: opts.abs ? SL.num( Math.abs( delta ) ) : SL.pct( delta ) } ) ),
			opts.spark ? h( 'div', { class: 'sl-kpi__spark' }, SL.charts.sparkline( opts.spark, { invert: opts.invert } ) ) : null
		);
	};
	App.btn = function ( label, onClick, kind, attrs ) {
		attrs = attrs || {};
		// Icon-only buttons get an accessible name + tooltip.
		if ( label && label.getAttribute && label.getAttribute( 'data-icon' ) && ! attrs[ 'aria-label' ] ) {
			var names = { trash: t( 'Remove' ), x: t( 'Dismiss' ), refresh: t( 'Refresh' ), copy: t( 'Copy' ), edit: t( 'Edit' ) };
			var n = names[ label.getAttribute( 'data-icon' ) ];
			if ( n ) {
				attrs = Object.assign( { 'aria-label': n, title: n }, attrs );
			}
			kind = kind ? kind + ' sl-btn--icon' : 'icon';
		}
		return h( 'button', Object.assign( { type: 'button', class: 'sl-btn' + ( kind ? ' sl-btn--' + kind : '' ), onClick: onClick }, attrs ), label );
	};
	/** Async button: disables itself while the promise runs. */
	App.abtn = function ( label, fn, kind, attrs ) {
		var b = App.btn( label, function () {
			b.disabled = true;
			b.classList.add( 'is-busy' );
			Promise.resolve( fn() ).catch( SL.fail ).then( function () {
				b.disabled = false;
				b.classList.remove( 'is-busy' );
			} );
		}, kind, attrs );
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
		cols = cols.filter( Boolean ); // Allows conditional columns (null).
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
	var fieldSeq = 0;
	/** Form row: label + help on one side, control on the other (two columns inside .sl-form). */
	App.field = function ( label, input, help ) {
		var target = input && input.matches && input.matches( 'input,select,textarea' ) ? input : ( input && input.querySelector ? input.querySelector( 'input,select,textarea' ) : null );
		if ( target && ! target.id ) {
			target.id = 'sl-f' + ( ++fieldSeq );
		}
		return h( 'div', { class: 'sl-field' },
			h( 'div', { class: 'sl-field__text' }, h( 'label', { class: 'sl-field__label', for: target ? target.id : null, text: label } ), help ? h( 'small', { class: 'sl-field__help', text: help } ) : null ),
			h( 'div', { class: 'sl-field__control' }, input )
		);
	};
	/** Toggle switch row (ARIAGH style) for boolean settings. */
	App.toggle = function ( label, checked, onChange, help ) {
		var cb = h( 'input', { type: 'checkbox', checked: !! checked, onChange: function () {
			onChange( cb.checked );
		} } );
		return h( 'div', { class: 'sl-field sl-field--toggle' },
			h( 'div', { class: 'sl-field__text' }, h( 'label', { class: 'sl-field__label', text: label, onClick: function () {
				cb.click();
			} } ), help ? h( 'small', { class: 'sl-field__help', text: help } ) : null ),
			h( 'div', { class: 'sl-field__control' }, h( 'label', { class: 'sl-switch', 'aria-label': label }, cb, h( 'span' ) ) )
		);
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
		return h( 'div', { class: 'sl-page-head' },
			h( 'div', null, h( 'h1', null, App.current ? SL.icon( App.icon( App.current ), 24 ) : null, h( 'span', { text: title } ) ), subtitle ? h( 'p', { class: 'sl-muted', text: subtitle } ) : null ),
			actions ? h( 'div', { class: 'sl-page-head__actions' }, actions ) : null
		);
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
	App.priorityDot = function ( p ) {
		return SL.dot( p );
	};

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

	/** Numbered steps with the dashboard location and an "Open" button (guides & plans). */
	App.guideSteps = function ( list ) {
		return h( 'ol', { class: 'sl-rm-steps' }, ( list || [] ).map( function ( s, i ) {
			if ( typeof s === 'string' ) {
				s = { title: s };
			}
			return h( 'li', null,
				h( 'span', { class: 'sl-rm-steps__n', text: String( i + 1 ) } ),
				h( 'div', null,
					h( 'b', { text: s.title } ),
					s.detail ? h( 'p', { text: s.detail } ) : null,
					s.where || s.link ? h( 'div', { class: 'sl-row' }, s.where ? h( 'span', { class: 'sl-rm-where' }, SL.icon( 'map', 13 ), h( 'span', { text: s.where } ) ) : null, s.link ? h( 'a', { class: 'sl-btn sl-btn--sm', href: s.link, target: s.link.indexOf( 'http' ) === 0 && s.link.indexOf( location.host ) === -1 ? '_blank' : null, rel: 'noopener' }, t( 'Open' ), ' ', SL.icon( 'arrow-' + ( C.rtl ? 'left' : 'right' ), 13 ) ) : null ) : null
				)
			);
		} ) );
	};

	// App.agentPopup / App.agentLive / App.operationView live in pages-ops.js.

	App.recommendationRow = function ( r, refresh ) {
		return h( 'div', { class: 'sl-rec sl-rec--' + r.priority },
			h( 'div', { class: 'sl-rec__icon', 'aria-hidden': 'true' }, SL.dot( r.priority ) ),
			h( 'div', { class: 'sl-rec__main' },
				h( 'div', { class: 'sl-rec__title', text: r.title } ),
				h( 'div', { class: 'sl-rec__reason', text: r.reason } ),
				h( 'div', { class: 'sl-rec__meta' }, SL.priorityBadge( r.priority ), SL.badge( t( 'Impact' ) + ' ' + r.impact ), SL.badge( t( 'Effort' ) + ' ' + r.effort ), SL.badge( t( 'Confidence' ) + ' ' + r.confidence + '%' ), r.url ? App.ext( r.url ) : null )
			),
			h( 'div', { class: 'sl-rec__actions' },
				App.btn( t( 'Do it' ), function () {
					App.agentPopup( r.id, refresh );
				}, 'primary' ),
				App.btn( t( 'Why?' ), function () {
					SL.why( r.title, SL.api( 'recommendations/' + r.id + '/why' ) );
				} ),
				App.cap( 'apply' ) ? App.btn( SL.icon( 'check', 17 ), function () {
					SL.api( 'recommendations/' + r.id + '/status', { body: { status: 'done' } } ).then( refresh ).catch( SL.fail );
				}, 'ghost', { title: t( 'Mark as done' ), 'aria-label': t( 'Mark as done' ) } ) : null,
				App.cap( 'apply' ) ? App.btn( SL.icon( 'x', 17 ), function () {
					SL.api( 'recommendations/' + r.id + '/status', { body: { status: 'dismissed' } } ).then( refresh ).catch( SL.fail );
				}, 'ghost', { title: t( 'Dismiss' ), 'aria-label': t( 'Dismiss' ) } ) : null
			)
		);
	};

	/* ================================================================ shell */
	var root;
	var view;
	var nav;
	var ICONS = { dashboard: 'dashboard', actions: 'wand', ai: 'prompt', audit: 'pulse', gsc: 'chart', keywords: 'key', content: 'content', products: 'bag', images: 'image', links: 'link', competitors: 'swords', experiments: 'flask', changes: 'undo', redirects: 'corner', 404: 'unlink', schema: 'braces', sitemap: 'map', robots: 'bot', reports: 'review', alerts: 'bell', automation: 'zap', knowledge: 'book', settings: 'settings', rankmath: 'target', guide: 'lifebuoy', subscription: 'sparkles', github: 'github', cloudflare: 'cloud', network: 'server', activity: 'activity', indexability: 'eye-off', duplicates: 'copy' };
	/** Sidebar groups (ARIAGH-style navigation sections). */
	/**
	 * Simple navigation: a few sections; related pages are tabs inside a section.
	 * [ hub id, label, icon, child route slugs ] — every page keeps its own URL (#/slug).
	 */
	/*
	 * Grouped by function: everything that gathers site data (full analysis, Search Console,
	 * competitors and the knowledge base) lives under "Data collection"; every correction type
	 * lives in its own specialized section; the roadmap in the AI Center links to all of them.
	 */
	var HUBS = [
		[ 'home', 'Dashboard', 'dashboard', [ 'dashboard', 'alerts', 'reports' ] ],
		[ 'aicenter', 'AI Center', 'wand', [ 'actions', 'ai', 'activity', 'changes', 'experiments' ] ],
		[ 'datahub', 'Data collection', 'database', [ 'audit', 'gsc', 'competitors', 'knowledge' ] ],
		[ 'contenthub', 'Content', 'content', [ 'content', 'duplicates', 'products', 'images' ] ],
		[ 'kwhub', 'Keywords & links', 'key', [ 'keywords', 'rankmath', 'links' ] ],
		[ 'technical', 'Technical SEO', 'wrench', [ 'redirects', '404', 'indexability', 'schema', 'sitemap', 'robots' ] ],
		[ 'connections', 'Connections', 'link', [ 'network', 'subscription', 'github', 'cloudflare', 'guide' ] ],
		[ 'system', 'Settings', 'settings', [ 'settings', 'automation' ] ],
	];
	var HUB_LABELS = { Dashboard: t( 'Dashboard' ), 'AI Center': t( 'AI Center' ), 'Data collection': t( 'Data collection' ), Content: t( 'Content' ), 'Keywords & links': t( 'Keywords & links' ), 'Technical SEO': t( 'Technical SEO' ), Connections: t( 'Connections' ), Settings: t( 'Settings' ) };
	var HUB_COUNT = { home: 'alerts', aicenter: 'changes' }; // Counters shown on a section item.
	App.hubOf = function ( slug ) {
		for ( var i = 0; i < HUBS.length; i++ ) {
			if ( HUBS[ i ][ 3 ].indexOf( slug ) !== -1 ) {
				return HUBS[ i ];
			}
		}
		return null;
	};
	App.icon = function ( slug ) {
		return ICONS[ slug ] || 'info';
	};

	function navItem( r ) {
		return h( 'a', { href: '#/' + r.slug, class: 'sl-nav__item', 'data-route': r.slug }, SL.icon( App.icon( r.slug ), 18, 'sl-nav__icon' ), h( 'span', { class: 'sl-nav__label', text: r.label } ), h( 'em', { class: 'sl-nav__count', 'data-count': r.slug } ) );
	}

	function brandMark() {
		var name = ( C.brand && C.brand.name ) || 'SEOLITY';
		var custom = name.toLowerCase() !== 'seolity';
		return h( 'a', { href: '#/dashboard', class: 'sl-brand', 'aria-label': name },
			C.brand && C.brand.logo ? h( 'img', { src: C.brand.logo, alt: '', class: 'sl-brand__logo' } ) : null,
			h( 'span', { class: 'sl-brand__text' },
				custom ? h( 'span', { class: 'sl-wordmark', text: name } ) : h( 'span', { class: 'sl-wordmark', dir: 'ltr' }, 'ARIAGH.', h( 'b', { text: 'SEOLITY' } ) ),
				h( 'small', { text: t( 'AI SEO Intelligence for WordPress' ) + ' · ' + t( 'version %s', C.version ) } )
			)
		);
	}

	function shell() {
		root = d.getElementById( 'seolity-app' );
		if ( ! root ) {
			return false;
		}
		SL.clear( root );
		root.classList.add( 'sl-root' );
		// Explicit direction: the UI language can differ from the WordPress admin language.
		root.setAttribute( 'dir', C.rtl ? 'rtl' : 'ltr' );
		if ( C.rtl ) {
			root.classList.add( 'is-rtl' );
		}
		SL.applyTheme( root );
		var bySlug = {};
		( C.routes || [] ).forEach( function ( r ) {
			bySlug[ r.slug ] = r;
		} );
		nav = h( 'nav', { class: 'sl-nav', 'aria-label': t( 'SEOlity navigation' ) } );
		var used = {};
		HUBS.forEach( function ( hub, i ) {
			var kids = hub[ 3 ].filter( function ( slug ) {
				return bySlug[ slug ];
			} );
			if ( ! kids.length ) {
				return;
			}
			if ( hub[ 0 ] === 'connections' ) {
				nav.appendChild( h( 'div', { class: 'sl-nav__group', text: t( 'Setup' ) } ) );
			}
			kids.forEach( function ( k ) {
				used[ k ] = true;
			} );
			// The section's own pages, in small text under its title (what you find inside).
			var subs = kids.map( function ( k ) {
				return bySlug[ k ].label;
			} ).join( C.rtl ? '، ' : ', ' );
			nav.appendChild( h( 'a', { href: '#/' + kids[ 0 ], class: 'sl-nav__item', 'data-hub': hub[ 0 ], title: subs }, SL.icon( hub[ 2 ], 18, 'sl-nav__icon' ), h( 'span', { class: 'sl-nav__label' }, h( 'span', { class: 'sl-nav__title', text: HUB_LABELS[ hub[ 1 ] ] || hub[ 1 ] } ), kids.length > 1 ? h( 'small', { class: 'sl-nav__sub', text: subs } ) : null ), h( 'em', { class: 'sl-nav__count', 'data-count': HUB_COUNT[ hub[ 0 ] ] || kids[ 0 ] } ) ) );
		} );
		( C.routes || [] ).forEach( function ( r ) {
			if ( ! used[ r.slug ] ) {
				nav.appendChild( navItem( r ) ); // Routes added by extensions.
			}
		} );
		App.routeLabels = bySlug;
		var themeBtn = h( 'button', { class: 'sl-icon-btn', type: 'button', title: t( 'Toggle dark mode' ), 'aria-label': t( 'Toggle dark mode' ), onClick: function () {
			var cur = root.getAttribute( 'data-theme' ) === 'dark' ? 'light' : 'dark';
			try {
				w.localStorage.setItem( 'seolity-theme', cur );
			} catch ( e ) {}
			SL.applyTheme( root );
		} }, SL.icon( 'moon', 17 ) );
		var top = h( 'header', { class: 'sl-top' },
			h( 'button', { class: 'sl-icon-btn sl-menu-btn', type: 'button', 'aria-label': t( 'Menu' ), onClick: function () {
				root.classList.toggle( 'nav-open' );
			} }, SL.icon( 'menu', 18 ) ),
			brandMark(),
			h( 'div', { class: 'sl-top__status', id: 'sl-top-status' } ),
			h( 'div', { class: 'sl-top__actions' }, themeBtn, h( 'a', { href: '#/actions', class: 'sl-btn sl-btn--primary sl-top__cta' }, SL.icon( 'wand', 17 ), h( 'span', { text: t( 'What should I do today?' ) } ) ) )
		);
		view = h( 'main', { class: 'sl-view', id: 'sl-view', tabindex: '-1' } );
		root.appendChild( top );
		root.appendChild( h( 'div', { class: 'sl-layout' }, h( 'aside', { class: 'sl-side' }, h( 'div', { class: 'sl-engines', id: 'sl-engines' } ), nav, h( 'div', { class: 'sl-side__foot', text: ( ( C.brand && C.brand.name ) === 'SEOLITY' ? 'ARIAGH.SEOLITY' : ( C.brand && C.brand.name ) || 'SEOLITY' ) + ' ' + C.version } ) ), view ) );
		return true;
	}

	/** Engine cards at the top of the sidebar (like ARIAGH's text/image AI cards). */
	function engines( b ) {
		var box = d.getElementById( 'sl-engines' );
		if ( ! box ) {
			return;
		}
		SL.clear( box );
		var card = function ( icon, title, sub, ok, route ) {
			return h( 'a', { href: '#/' + route, class: 'sl-engine' + ( ok ? ' is-ok' : ' is-off' ) },
				h( 'span', { class: 'sl-engine__ic' }, SL.icon( icon, 18 ) ),
				h( 'span', { class: 'sl-engine__text' }, h( 'b', { text: title } ), h( 'small', { dir: 'auto', text: sub } ) )
			);
		};
		var ai = b.ai_state || {};
		var aiSub = b.ai_ready ? ( ai.label || b.ai_label || t( 'AI connected' ) ) + ( ai.model ? ' · ' + ai.model : '' ) + ( ai.status === 'verified' ? ' ✓' : ai.status === 'error' ? ' — ' + t( 'Error' ) : '' ) : ( ai.reason || t( 'AI not configured' ) );
		box.appendChild( card( 'cpu', t( 'AI engine' ), aiSub, b.ai_ready && ai.status !== 'error', 'settings?tab=ai' ) );
		box.appendChild( card( 'chart', t( 'Search Console' ), b.gsc ? ( b.gsc_property || t( 'Search Console connected' ) ).replace( /^sc-domain:/, '' ) : t( 'Search Console not connected' ), b.gsc, 'gsc' ) );
		if ( App.cap( 'manage' ) ) {
			var gh = b.github || {};
			box.appendChild( card( 'github', 'GitHub', gh.token ? ( gh.login ? '@' + gh.login : t( 'GitHub connected' ) ) : t( 'GitHub not connected' ), !! gh.token, 'github' ) );
		}
	}

	function topStatus( b ) {
		var box = d.getElementById( 'sl-top-status' );
		if ( ! box ) {
			return;
		}
		SL.clear( box );
		engines( b );
		var chip = function ( icon, text, kind, href ) {
			return h( href ? 'a' : 'span', { class: 'sl-chip-status' + ( kind ? ' is-' + kind : '' ), href: href || null }, SL.icon( icon, 15 ), h( 'span', { text: text } ) );
		};
		var modes = { manual: t( 'Manual approval' ), semi: t( 'Semi-automatic' ), autonomous: t( 'Autonomous' ) };
		box.appendChild( chip( 'shield', modes[ b.mode ] || b.mode, b.mode === 'autonomous' ? 'warn' : 'info', '#/automation' ) );
		box.appendChild( chip( 'cpu', b.ai_ready ? t( 'AI connected' ) : t( 'AI not configured' ), b.ai_ready ? 'ok' : 'warn', '#/settings?tab=ai' ) );
		var routeNames = { direct: t( 'Direct' ), cloudflare: 'Cloudflare', github: 'GitHub' };
		box.appendChild( chip( 'server', t( 'Route: %s', routeNames[ b.route ] || b.route ), b.region_set ? 'info' : 'warn', '#/network' ) );
		box.appendChild( chip( 'chart', b.gsc ? t( 'Search Console connected' ) : t( 'Search Console not connected' ), b.gsc ? 'ok' : 'warn', '#/gsc' ) );
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
		var hub = App.hubOf( slug );
		Array.prototype.forEach.call( nav.querySelectorAll( '.sl-nav__item' ), function ( a ) {
			var on = a.getAttribute( 'data-route' ) === slug || ( hub && a.getAttribute( 'data-hub' ) === hub[ 0 ] );
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
		var kids = hub ? hub[ 3 ].filter( function ( k ) {
			return App.routeLabels && App.routeLabels[ k ];
		} ) : [];
		if ( kids.length > 1 ) {
			view.appendChild( h( 'nav', { class: 'sl-subtabs', 'aria-label': HUB_LABELS[ hub[ 1 ] ] || hub[ 1 ] }, kids.map( function ( k ) {
				return h( 'a', { href: '#/' + k, class: 'sl-subtab' + ( k === slug ? ' is-active' : '' ), 'aria-current': k === slug ? 'page' : null }, SL.icon( App.icon( k ), 16 ), h( 'span', { text: App.routeLabels[ k ].label } ) );
			} ) ) );
		}
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
		v.appendChild( App.header( t( 'Dashboard' ), t( 'Your SEO command center: health, traffic, opportunities and AI activity.' ) ) );
		var body = h( 'div' );
		v.appendChild( body );
		App.load( body, SL.api( 'dashboard' ), function ( dsh ) {
			var b = App.state.boot || {};
			if ( ! b.region_set || ! b.ai_ready || ! b.gsc || ! dsh.audit ) {
				body.appendChild( App.onboarding( b, dsh ) );
			}
			var g = dsh.gsc;
			var series = g && g.series ? g.series.slice( -g.period.days ) : [];
			var kpis = h( 'div', { class: 'sl-kpis' },
				h( 'div', { class: 'sl-kpi sl-kpi--health' }, h( 'div', { class: 'sl-kpi__label', text: t( 'SEO Health Score' ) } ), SL.scoreRing( dsh.health.score, 84 ), h( 'a', { href: '#/audit?tab=health', class: 'sl-link sl-small' }, t( 'Why this score?' ) ) ),
				App.kpi( t( 'Organic clicks' ), g ? SL.num( g.current.clicks, 0 ) : '—', g ? g.delta.clicks : null, { spark: series.map( function ( r ) {
					return r.clicks;
				} ), sub: g ? '' : t( 'Connect Search Console' ), icon: 'trending', tone: 'blue' } ),
				App.kpi( t( 'Impressions' ), g ? SL.num( g.current.impressions, 0 ) : '—', g ? g.delta.impressions : null, { spark: series.map( function ( r ) {
					return r.impressions;
				} ), icon: 'eye', tone: 'violet' } ),
				App.kpi( t( 'CTR' ), g ? SL.num( g.current.ctr ) + '%' : '—', g ? g.delta.ctr : null, { icon: 'target', tone: 'green' } ),
				App.kpi( t( 'Avg. position' ), g ? SL.num( g.current.position ) : '—', g ? g.delta.position : null, { abs: true, spark: series.map( function ( r ) {
					return r.position;
				} ), invert: true, icon: 'chart', tone: 'amber' } ),
				App.kpi( t( 'Indexable pages' ), SL.num( dsh.indexed.indexable, 0 ), null, { sub: t( '%s crawled', SL.num( dsh.indexed.crawled, 0 ) ), icon: 'layers', tone: 'green' } )
			);
			body.appendChild( kpis );

			var counts = dsh.action.counts;
			var ac = h( 'div', { class: 'sl-actioncounts' }, [ [ 'critical', t( 'Critical issues' ) ], [ 'high', t( 'High priority opportunities' ) ], [ 'medium', t( 'Optimization opportunities' ) ], [ 'low', t( 'Improvements' ) ] ].map( function ( x ) {
				return h( 'a', { href: '#/actions?priority=' + x[ 0 ], class: 'sl-actioncount sl-actioncount--' + x[ 0 ] }, h( 'span', { class: 'sl-actioncount__n' }, SL.dot( x[ 0 ] ), h( 'span', { text: SL.num( counts[ x[ 0 ] ], 0 ) } ) ), h( 'span', { text: x[ 1 ] } ) );
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
			[ b.region_set, t( 'Choose where your server is' ), t( 'Iran or abroad: SEOlity then sends AI and Google requests over the fastest route that works.' ), 'network' ],
			[ b.ai_ready, t( 'Connect an AI provider' ), t( 'Add an API key (Anthropic, OpenAI, Gemini, OpenRouter) or a local LLM.' ), 'settings?tab=ai' ],
			[ b.gsc, t( 'Connect Google Search Console' ), t( 'Real clicks, impressions and queries power every recommendation.' ), 'gsc' ],
			[ !! dsh.audit, t( 'Run your first site audit' ), t( 'SEOlity crawls your site in the background without slowing it down.' ), 'audit' ],
			[ dsh.competitors.length > 0, t( 'Add competitors' ), t( 'Monitor competitor content and discover gaps.' ), 'competitors' ],
		];
		return App.card( t( 'Get started' ), h( 'ol', { class: 'sl-onboarding' }, steps.map( function ( s ) {
			return h( 'li', { class: s[ 0 ] ? 'is-done' : '' }, h( 'span', { class: 'sl-onboarding__check', 'aria-hidden': 'true' }, s[ 0 ] ? SL.icon( 'check', 15 ) : null ), h( 'div', null, h( 'strong', { text: s[ 1 ] } ), h( 'p', { class: 'sl-muted', text: s[ 2 ] } ) ), s[ 0 ] ? null : App.btn( t( 'Start' ), function () {
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
					var icon = { win: 'trending', loss: 'trending-down', risk: 'alert', info: 'info' }[ x.type ] || 'info';
					return h( 'li', { class: 'sl-highlight sl-highlight--' + ( x.type || 'info' ) }, SL.icon( icon, 17 ), h( 'strong', { text: x.title } ), x.detail ? h( 'div', { class: 'sl-muted sl-small', text: x.detail } ) : null );
				} ) ),
				b.actions && b.actions.length ? h( 'div', null, h( 'h4', { text: t( 'Do today' ) } ), h( 'ol', null, b.actions.map( function ( a ) {
					return h( 'li', null, SL.dot( a.priority ), ' ', h( 'strong', { text: a.title } ), h( 'div', { class: 'sl-muted sl-small', text: a.reason } ) );
				} ) ) ) : null
			);
		}
		return App.card( t( "Today's SEO Briefing" ), body, { icon: 'sun' } );
	};

	/* ================================================================ AI Action Center */
	// App.pages.actions (the SEO roadmap) lives in pages-roadmap.js.

	/** Ask the server to start a warm GitHub runner (no-op for direct / Cloudflare routes); at most once a minute. */
	var lastWarm = 0;
	App.warmAi = function () {
		if ( Date.now() - lastWarm < 60000 ) {
			return;
		}
		lastWarm = Date.now();
		SL.api( 'ai/warm', { method: 'POST', noWait: true } ).catch( function () {} );
	};

	// App.pages.ai (AI SEO Chat) lives in pages-chat.js.


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
