/**
 * AI engines → Installation & diagnostics: one place to see whether Claude Code and Codex can
 * run on this server, install or repair them, sign in, test, and copy a support report.
 * Every action is a fixed REST call; nothing typed here is ever executed.
 */
( function ( w, d ) {
	'use strict';
	var SL = w.SL;
	var App = SL.app;
	var t = SL.t;
	var h = SL.h;

	var GROUP = {
		ready: [ '#16a34a', 'check-circle', t( 'Ready' ) ],
		needs_config: [ '#2563eb', 'settings', t( 'Needs configuration' ) ],
		auth_required: [ '#f59e0b', 'key', t( 'Authentication required' ) ],
		not_installed: [ '#6b7280', 'download', t( 'Not installed' ) ],
		permission_denied: [ '#dc2626', 'lock', t( 'Permission denied' ) ],
		unsupported: [ '#7f1d1d', 'x-circle', t( 'Unsupported environment' ) ],
		runtime_error: [ '#dc2626', 'alert', t( 'Runtime error' ) ],
	};
	var NAMES = { claude: 'Claude Code', codex: 'OpenAI Codex' };
	var STEPS = [ t( 'Diagnostics' ), t( 'Install' ), t( 'Sign in' ), t( 'Test' ), t( 'Ready' ) ];

	function step( e ) {
		if ( 'ready' === e.status ) {
			return 4;
		}
		if ( 'needs_config' === e.status ) {
			return 3;
		}
		if ( 'auth_required' === e.status ) {
			return 2;
		}
		return 'not_installed' === e.status || 'permission_denied' === e.status ? 1 : 0;
	}

	function metric( label, value, tone ) {
		return h( 'div', { class: 'sl-mapcard__m' + ( tone ? ' is-' + tone : '' ) }, h( 'b', { dir: 'auto', text: value } ), h( 'span', { text: label } ) );
	}

	/** Sign in again: device code for Codex (official `codex login --device-auth`), or paste. */
	function reconnect( e, done ) {
		var m = SL.modal( { title: t( 'Reconnect' ) + ' — ' + NAMES[ e.engine ], wide: true } );
		var timer;
		function poll() {
			clearTimeout( timer );
			if ( ! d.body.contains( m.body ) ) {
				return;
			}
			SL.api( 'cli/login' ).then( function ( s ) {
				SL.clear( dev );
				if ( 'waiting' === s.status ) {
					dev.appendChild( h( 'div', { class: 'sl-eng__device' },
						h( 'p', { text: t( 'Open this page, sign in with your ChatGPT account and enter the code:' ) } ),
						h( 'a', { class: 'sl-btn sl-btn--primary', href: s.url, target: '_blank', rel: 'noopener' }, SL.icon( 'external', 14 ), ' ', t( 'Open sign-in page' ) ),
						h( 'div', { class: 'sl-eng__code', dir: 'ltr', text: s.code } ),
						h( 'p', { class: 'sl-muted sl-small', text: t( 'The code is valid for 15 minutes. This window updates by itself.' ) } )
					) );
				} else if ( 'done' === s.status ) {
					dev.appendChild( h( 'div', { class: 'sl-alert sl-alert--success', text: t( 'Signed in. Now press “Test connection”.' ) } ) );
					done();
					return;
				} else if ( 'failed' === s.status ) {
					dev.appendChild( h( 'div', { class: 'sl-alert sl-alert--error', text: ( s.message || t( 'Sign-in failed.' ) ) } ) );
					return;
				} else {
					dev.appendChild( h( 'div', { class: 'sl-row' }, SL.spinner(), h( 'span', { text: t( 'Starting the sign-in…' ) } ) ) );
				}
				timer = setTimeout( poll, 3000 );
			} ).catch( SL.fail );
		}
		var dev = h( 'div' );
		if ( 'codex' === e.engine && e.device ) {
			m.body.appendChild( h( 'h4', { text: t( 'Sign in from here (recommended)' ) } ) );
			m.body.appendChild( h( 'p', { class: 'sl-muted', text: t( 'Uses the official device sign-in of Codex. Your password never passes through SEOlity; the login is saved encrypted on this server.' ) } ) );
			m.body.appendChild( h( 'div', {}, App.abtn( SL.il( 'key', t( 'Sign in with ChatGPT' ), 14 ), function () {
				return SL.api( 'cli/login', { method: 'POST' } ).then( poll );
			}, 'primary' ) ) );
			m.body.appendChild( dev );
			m.body.appendChild( h( 'h4', { text: t( 'Or paste a login from your computer' ) } ) );
		}
		var help = 'codex' === e.engine
			? t( 'On your computer run “codex login”, sign in with ChatGPT, then paste the whole content of ~/.codex/auth.json here.' )
			: t( 'On your computer run “claude setup-token”, sign in with your Claude account, then paste the token (starts with sk-ant-oat01-).' );
		var area = h( 'textarea', { class: 'sl-input', dir: 'ltr', rows: 'codex' === e.engine ? 6 : 2, autocomplete: 'off', spellcheck: 'false' } );
		m.body.appendChild( h( 'p', { class: 'sl-muted', text: help } ) );
		m.body.appendChild( area );
		m.body.appendChild( h( 'div', { class: 'sl-row' }, App.abtn( t( 'Save login' ), function () {
			if ( ! area.value.trim() ) {
				return Promise.resolve();
			}
			return SL.api( 'subscription/account', { body: { engine: e.engine, value: area.value, activate: 0 } } ).then( function () {
				area.value = '';
				SL.toast( t( 'Login saved. Now press “Test connection”.' ), 'success' );
				m.close();
				done();
			} );
		}, 'primary' ) ) );
		if ( 'codex' === e.engine && e.login && 'waiting' === e.login.status ) {
			poll();
		}
	}

	/** Choose the model right where the engine is installed (loaded from the installed CLI). */
	function modelPicker( e ) {
		var box = h( 'div', { class: 'sl-eng__model' }, h( 'b', { text: t( 'Model' ) } ), h( 'span', { class: 'sl-muted sl-small', text: e.model || ( 'codex' === e.engine ? t( 'Default model of your ChatGPT plan' ) : 'sonnet' ) } ) );
		box.appendChild( App.btn( t( 'Choose model' ), function () {
			SL.clear( box );
			box.appendChild( h( 'b', { text: t( 'Model' ) } ) );
			App.load( box, SL.api( 'cli/models', { query: { engine: e.engine } } ), function ( r ) {
				var known = r.models.some( function ( m ) {
					return m.id === r.model;
				} );
				var opts = r.models.map( function ( m ) {
					return [ m.id, m.label + ( m.id ? ' (' + m.id + ')' : '' ) ];
				} );
				if ( ! known && r.model ) {
					opts.push( [ r.model, r.model ] );
				}
				var sel = App.select( opts, r.model, function () {} );
				box.appendChild( h( 'div', { class: 'sl-row' }, sel, App.abtn( t( 'Save' ), function () {
					return SL.api( 'cli/model', { body: { engine: e.engine, model: sel.value } } ).then( function () {
						SL.toast( t( 'Model saved. Press “Test connection” to check it.' ), 'success' );
					} );
				}, 'primary' ) ) );
			} );
		}, 'sm' ) );
		return box;
	}

	function engineCard( e, prefix, reload ) {
		var g = GROUP[ e.status ] || GROUP.runtime_error;
		var at = step( e );
		var testOut = h( 'div' );
		var inst = e.install || {};
		return h( 'article', { class: 'sl-mapcard sl-eng', style: '--c:' + g[ 0 ] },
			h( 'div', { class: 'sl-mapcard__head' },
				h( 'span', { class: 'sl-mapcard__ic', 'aria-hidden': 'true' }, SL.icon( 'cpu', 22 ) ),
				h( 'div', { class: 'sl-mapcard__titles' }, h( 'b', { class: 'sl-mapcard__title', text: NAMES[ e.engine ] } ), h( 'span', { class: 'sl-muted sl-small', dir: 'ltr', text: e.package } ) ),
				h( 'span', { class: 'sl-mapcard__status' }, SL.icon( g[ 1 ], 16 ), h( 'b', { text: g[ 2 ] } ) )
			),
			h( 'ol', { class: 'sl-eng__steps' }, STEPS.map( function ( s, i ) {
				return h( 'li', { class: i < at ? 'is-done' : ( i === at ? 'is-now' : '' ) }, h( 'span', { text: String( i + 1 ) } ), s );
			} ) ),
			e.message && 'INSTALLING' !== e.code ? h( 'div', { class: 'sl-eng__why' }, h( 'b', { text: e.message } ), e.action ? h( 'p', { text: e.action } ) : null ) : null,
			'INSTALL_BLOCKED' === e.code || ( 'failed' === inst.status && 'INSTALL_BLOCKED' === inst.code ) ? h( 'div', { class: 'sl-eng__cmd' }, h( 'span', { text: t( 'One command over SSH:' ) } ), h( 'code', { dir: 'ltr', text: 'npm install --prefix ' + prefix + ' ' + e.package + '@latest' } ) ) : null,
			h( 'div', { class: 'sl-mapcard__metrics sl-eng__metrics' },
				metric( t( 'CLI version' ), e.version || '—', e.version ? 'good' : '' ),
				metric( t( 'Login' ), e.logged_in ? ( 'cli' === e.auth_mode ? t( 'From SSH' ) : t( 'Saved' ) ) : t( 'None' ), e.logged_in ? '' : 'warn' ),
				metric( t( 'Last successful connection' ), e.last_ok ? SL.date( e.last_ok ) : '—' ),
				metric( t( 'Requests answered by' ), 'cli' === e.route ? t( 'This server' ) : t( 'GitHub runner' ), 'cli' === e.route ? 'good' : '' ),
				metric( t( 'Last result' ), e.last_code || ( 'ok' === e.last_state ? t( 'OK' ) : '—' ), e.last_code ? 'bad' : '' )
			),
			h( 'details', { class: 'sl-eng__paths' }, h( 'summary', { text: t( 'Detected paths (administrators only)' ) } ),
				h( 'div', { dir: 'ltr', class: 'sl-small' }, h( 'div', { text: 'CLI: ' + ( e.path || '—' ) } ), h( 'div', { text: 'Node.js: ' + ( e.node || '—' ) } ) ) ),
			'running' === inst.status ? h( 'div', { class: 'sl-eng__progress', 'data-progress': e.engine }, h( 'div', { class: 'sl-row' }, SL.spinner(), h( 'span', { text: inst.stage || t( 'Installing… (a few minutes; this page updates by itself)' ) } ) ), h( 'div', { class: 'sl-eng__bar' }, h( 'i', { style: 'width:' + ( inst.pct || 5 ) + '%' } ) ) ) : null,
			e.version && 'running' !== inst.status ? modelPicker( e ) : null,
			'failed' === inst.status && inst.out ? h( 'details', null, h( 'summary', { text: t( 'Install log' ) } ), h( 'pre', { dir: 'ltr', class: 'sl-pre', text: inst.out } ) ) : null,
			testOut,
			h( 'div', { class: 'sl-mapcard__foot' },
				h( 'div', { class: 'sl-mapcard__actions' },
					App.abtn( SL.il( 'zap', t( 'Test connection' ), 14 ), function () {
						return SL.api( 'cli/test', { body: { engine: e.engine } } ).then( function ( r ) {
							SL.clear( testOut );
							testOut.appendChild( r.ok
								? h( 'div', { class: 'sl-alert sl-alert--success', text: t( 'It works' ) + ' — ' + ( r.ms / 1000 ).toFixed( 1 ) + ' s — ' + r.text } )
								: h( 'div', { class: 'sl-alert sl-alert--error' }, h( 'b', { text: r.message } ), r.action ? h( 'p', { text: r.action } ) : null ) );
							setTimeout( reload, r.ok ? 1500 : 4000 );
						} );
					}, 'primary', { disabled: [ 'not_installed', 'unsupported', 'permission_denied' ].indexOf( e.status ) >= 0 && 'CLI_PERMISSION_DENIED' !== e.code ? true : null } ),
					App.abtn( SL.il( 'download', e.version ? t( 'Install / repair' ) : t( 'Install' ), 14 ), function () {
						return SL.api( 'cli/install', { body: { engine: e.engine } } ).then( reload );
					} ),
					App.btn( SL.il( 'key', t( 'Reconnect' ), 14 ), function () {
						reconnect( e, reload );
					} ),
					e.logged_in && 'plugin' === e.auth_mode ? App.btn( t( 'Sign out' ), function () {
						SL.confirm( t( 'Remove the saved login of %s from this site?', NAMES[ e.engine ] ), function () {
							SL.api( 'cli/signout', { body: { engine: e.engine } } ).then( reload ).catch( SL.fail );
						}, true );
					}, 'ghost' ) : null
				)
			)
		);
	}

	function envCard( r ) {
		var list = h( 'ul', { class: 'sl-checks' }, r.environment.map( function ( c ) {
			return h( 'li', { class: 'sl-checks__row' + ( 'ok' === c.state ? '' : ' is-warn' ) }, SL.icon( 'ok' === c.state ? 'check-circle' : ( 'bad' === c.state ? 'x-circle' : 'alert' ), 18 ), h( 'span', { class: 'sl-checks__text' }, h( 'b', { text: c.label } ), h( 'small', { dir: 'auto', text: c.value } ), c.fix ? h( 'small', { class: 'sl-checks__fix', text: c.fix } ) : null ) );
		} ) );
		var nodes = r.nodes.length ? App.table( [
			{ label: '', render: function ( n ) {
				return n.selected ? SL.badge( t( 'In use' ), 'good' ) : '';
			} },
			{ label: 'Node.js', render: function ( n ) {
				return h( 'code', { dir: 'ltr', text: n.path } );
			} },
			{ label: t( 'Version' ), render: function ( n ) {
				return n.ok ? n.version : SL.badge( n.error, 'bad' );
			} },
			{ label: 'npm', render: function ( n ) {
				return n.npm ? '✓' : '—';
			} },
		], r.nodes ) : h( 'p', { class: 'sl-muted', text: t( 'No Node.js was found on this server.' ) } );
		return App.card( t( 'Server checks' ), h( 'div', { class: 'sl-stack' }, list, h( 'h4', { text: t( 'Node.js found on this server' ) } ), nodes ), { icon: 'server', sub: t( 'Checked from PHP itself — the same environment the AI runs in.' ) } );
	}

	function advancedCard( r, reload ) {
		var node = h( 'input', { class: 'sl-input', dir: 'ltr', placeholder: r.node ? r.node.path : '/opt/alt/alt-nodejs22/root/usr/bin/node' } );
		var home = h( 'input', { class: 'sl-input', dir: 'ltr', placeholder: '~/.codex' } );
		var modes = {};
		var body = h( 'div', { class: 'sl-stack' },
			h( 'label', { class: 'sl-field' }, h( 'span', { text: t( 'Node.js to use (empty = automatic)' ) } ), node ),
			[ 'claude', 'codex' ].map( function ( id ) {
				modes[ id ] = App.select( [ [ 'plugin', t( 'Login saved in SEOlity (recommended)' ) ], [ 'cli', t( 'Login made with the CLI over SSH' ) ] ], r.engines[ id ].auth_mode, function () {} );
				return h( 'label', { class: 'sl-field' }, h( 'span', { text: NAMES[ id ] + ' — ' + t( 'where the login comes from' ) } ), modes[ id ] );
			} ),
			h( 'label', { class: 'sl-field' }, h( 'span', { text: t( 'CODEX_HOME of an SSH login (empty = ~/.codex)' ) } ), home ),
			h( 'div', {}, App.abtn( t( 'Save' ), function () {
				var sel = function ( el ) {
					return el.value;
				};
				return SL.api( 'cli/settings', { body: { cli_node_path: node.value.trim(), cli_codex_home: home.value.trim(), cli_auth_claude: sel( modes.claude ), cli_auth_codex: sel( modes.codex ) } } ).then( function () {
					SL.toast( t( 'Saved' ), 'success' );
					reload();
				} );
			}, 'primary' ) )
		);
		return h( 'details', { class: 'sl-card sl-eng__adv' }, h( 'summary', { text: t( 'Advanced settings' ) } ), body );
	}

	App.pages.engines = function ( v ) {
		var body = h( 'div', { class: 'sl-stack' } );
		var timer;
		function load( fresh ) {
			clearTimeout( timer );
			App.load( body, SL.api( 'cli/doctor', { query: fresh ? { fresh: 1 } : {} } ), function ( r ) {
				var list = h( 'div', { class: 'sl-mapcards' } );
				var busy = false;
				[ 'claude', 'codex' ].forEach( function ( id ) {
					var e = r.engines[ id ];
					if ( e.install && 'running' === e.install.status ) {
						busy = id;
					}
					list.appendChild( engineCard( e, r.prefix, function () {
						load( false );
					} ) );
				} );
				body.appendChild( list );
				body.appendChild( envCard( r ) );
				body.appendChild( advancedCard( r, function () {
					load( true );
				} ) );
				if ( busy ) {
					// Poll the saved install state only (no program is started, nothing is re-drawn)
					// and reload the page once when the install has finished.
					var watch = function () {
						timer = setTimeout( function () {
							if ( ! d.body.contains( body ) ) {
								return;
							}
							SL.api( 'cli/status', { query: { engine: busy } } ).then( function ( s ) {
								var st = s.install || {};
								var box = body.querySelector( '[data-progress="' + busy + '"]' );
								if ( 'running' !== st.status ) {
									load( false );
									return;
								}
								if ( box ) {
									box.querySelector( 'span' ).textContent = st.stage || t( 'Installing…' );
									box.querySelector( 'i' ).style.width = ( st.pct || 5 ) + '%';
								}
								watch();
							} ).catch( watch );
						}, 4000 );
					};
					watch();
				}
			} );
		}
		v.appendChild( App.header( t( 'AI Engines — Installation & Diagnostics' ), t( 'Install, sign in and test Claude Code and Codex on this server. Each engine works on its own: one failing never stops the other.' ), [
			App.abtn( SL.il( 'refresh', t( 'Refresh diagnostics' ), 14 ), function () {
				load( true );
				return Promise.resolve();
			} ),
			App.abtn( SL.il( 'copy', t( 'Copy diagnostic report' ), 14 ), function () {
				return SL.api( 'cli/report' ).then( function ( r ) {
					var done = function () {
						SL.toast( t( 'Report copied — it contains no passwords or tokens.' ), 'success' );
					};
					if ( navigator.clipboard ) {
						return navigator.clipboard.writeText( r.report ).then( done );
					}
					var m = SL.modal( { title: t( 'Diagnostic report' ), wide: true } );
					m.body.appendChild( h( 'pre', { dir: 'ltr', class: 'sl-pre', text: r.report } ) );
				} );
			} ),
		] ) );
		v.appendChild( body );
		load( false );
	};
}( window, document ) );
