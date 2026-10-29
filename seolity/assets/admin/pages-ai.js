/**
 * Settings → AI: two connection methods, each in its own box.
 *
 *  - «هوش مصنوعی با API»: Anthropic, OpenAI, Gemini, OpenRouter, other compatible services, Ollama.
 *    Key → "Connect & load models" (the provider's own model list for this key) → pick a model →
 *    "Test" (one real request) → "Use for all AI features". Nothing counts as working until tested.
 *  - «اشتراکی»: Claude Pro/Max, ChatGPT Plus/Pro (official CLI on the GitHub runner or on this
 *    server) and GitHub Models (GitHub / Copilot account, official REST API, direct).
 * Each provider opens its own panel with status, models, test, activation and disconnection.
 */
( function ( w, d ) {
	'use strict';
	var SL = w.SL;
	var App = SL.app;
	var C = SL.config || {};
	var t = SL.t;
	var h = SL.h;

	var STATUS = {
		not_connected: [ t( 'Not connected' ), '' ],
		saved: [ t( 'Key saved — not checked yet' ), 'warn' ],
		authenticated: [ t( 'Connected — key works' ), 'brand' ],
		verified: [ t( 'Model tested ✓' ), 'good' ],
		error: [ t( 'Error' ), 'bad' ],
	};

	function badge( st ) {
		var s = STATUS[ st.status ] || [ st.status, '' ];
		return SL.badge( s[ 0 ], s[ 1 ] );
	}

	function mono( p ) {
		return h( 'span', { class: 'sl-mono', style: { '--c': ( p.mono && p.mono[ 1 ] ) || '#1D4ED8' }, text: ( p.mono && p.mono[ 0 ] ) || p.id.slice( 0, 2 ).toUpperCase() } );
	}

	/** "id — label · note" and a check mark for a tested model. */
	function modelOptions( st ) {
		var list = ( st.models || [] ).slice();
		if ( st.model && ! list.some( function ( m ) {
			return m.id === st.model;
		} ) ) {
			list.unshift( { id: st.model, label: st.model, note: t( 'current setting — not in the list from your account' ) } );
		}
		return list.map( function ( m ) {
			var c = st.checked && st.checked[ m.id ];
			var mark = c ? ( c.ok ? '✓ ' : '✗ ' ) : '';
			var txt = mark + ( m.id || t( 'Default model' ) ) + ( m.label && m.label !== m.id ? ' — ' + m.label : '' ) + ( m.note ? ' · ' + m.note : '' );
			return [ m.id, txt ];
		} );
	}

	function errorBox( err ) {
		if ( ! err ) {
			return null;
		}
		return h( 'div', { class: 'sl-alert sl-alert--error sl-stack' }, h( 'b', { dir: 'auto', text: err.message } ), err.fix ? h( 'span', { dir: 'auto', text: err.fix } ) : null );
	}

	/** Result of the last test of the selected model. */
	function checkLine( st, model ) {
		var c = st.checked && st.checked[ model ];
		if ( ! c ) {
			return h( 'p', { class: 'sl-muted sl-small', text: t( 'Not tested yet. “Test model” sends one short real request.' ) } );
		}
		if ( c.ok ) {
			return h( 'div', { class: 'sl-alert sl-alert--success' }, SL.icon( 'check', 16 ), h( 'span', { dir: 'auto', text: t( 'This model answered (%s).', ( c.ms ? SL.num( c.ms, 0 ) + ' ms · ' : '' ) + new Date( c.at ).toLocaleString() ) + ( c.answered_by && c.answered_by !== model ? ' ' + t( 'Answered by: %s', c.answered_by ) : '' ) } ) );
		}
		return h( 'div', { class: 'sl-alert sl-alert--error' }, SL.icon( 'x-circle', 16 ), h( 'span', { dir: 'auto', text: c.error || t( 'Failed' ) } ) );
	}

	/* ------------------------------------------------------------------ provider panel */
	function panel( p, reload, opts ) {
		var m = SL.modal( { title: p.label, wide: true, onClose: reload } );
		var body = h( 'div', { class: 'sl-stack sl-aipanel' } );
		m.body.appendChild( body );
		var st = p;

		function save( next ) {
			st = Object.assign( {}, p, next );
			draw();
		}
		function call( path, data ) {
			return SL.api( 'ai/providers/' + p.id + '/' + path, { body: data || {} } ).then( function ( r ) {
				save( r.state || r );
				return r;
			} ).catch( function ( e ) {
				// Show the stored result of the failed step (key rejected, model not available…) in the panel.
				return SL.api( 'ai/providers/' + p.id, { noWait: true } ).then( save, function () {} ).then( function () {
					throw e;
				} );
			} );
		}
		var poll = null;
		function watchPending() {
			clearTimeout( poll );
			if ( ! st.pending || ! d.body.contains( body ) ) {
				return;
			}
			poll = setTimeout( function () {
				SL.api( 'ai/providers/' + p.id, { noWait: true } ).then( function ( r ) {
					save( r );
					if ( r.verify_error ) {
						SL.toast( r.verify_error, 'error' );
					}
					watchPending();
				} ).catch( function () {
					watchPending();
				} );
			}, 5000 );
		}

		function draw() {
			SL.clear( body );
			var sub = p.group === 'subscription';
			var head = h( 'div', { class: 'sl-aipanel__head' }, mono( p ), h( 'div', null, h( 'b', { text: p.label } ), h( 'div', { class: 'sl-row' }, badge( st ), st.active ? SL.badge( t( 'In use' ), 'brand' ) : null ) ) );
			body.appendChild( head );
			if ( p.plans && p.plans.length ) {
				body.appendChild( h( 'div', { class: 'sl-row' }, h( 'span', { class: 'sl-muted sl-small', text: t( 'Supported plans:' ) } ), p.plans.map( function ( x ) {
					return SL.badge( x );
				} ) ) );
			}
			if ( p.note ) {
				body.appendChild( h( 'p', { class: 'sl-muted', dir: 'auto', text: p.note } ) );
			}
			if ( st.status === 'error' && st.error ) {
				body.appendChild( errorBox( st.error ) );
			}

			// 1. Credentials.
			if ( p.id === 'custom' ) {
				body.appendChild( opts.customCard() );
			} else if ( p.id === 'sub_claude' || p.id === 'sub_codex' ) {
				body.appendChild( subscriptionSetup() );
			} else {
				body.appendChild( keyStep() );
			}
			// 2. Models + test + use.
			if ( p.id !== 'custom' ) {
				body.appendChild( modelStep() );
			}
			if ( st.pending ) {
				body.appendChild( h( 'div', { class: 'sl-alert sl-alert--info' }, h( 'span', { class: 'sl-spinner sl-spinner--sm' } ), h( 'span', { text: t( 'The test request is running on your GitHub runner; the result appears here (usually within a minute).' ) } ) ) );
				watchPending();
			}
			if ( sub && p.id !== 'github_models' ) {
				body.appendChild( h( 'p', { class: 'sl-muted sl-small' }, h( 'a', { href: '#/subscription?engine=' + ( p.id === 'sub_codex' ? 'codex' : 'claude' ), onClick: function () {
					m.close();
				} }, t( 'Open the full step-by-step setup (GitHub runner, token, Cloudflare)' ) ) ) );
			}
		}

		function keyStep() {
			var box = h( 'div', { class: 'sl-stack' } );
			var isOllama = p.id === 'ollama';
			var input = isOllama ? h( 'input', { class: 'sl-input', dir: 'ltr', value: st.base_url || 'http://localhost:11434', placeholder: 'http://localhost:11434' } ) : h( 'input', { class: 'sl-input', type: 'password', autocomplete: 'new-password', dir: 'ltr', placeholder: st.has_key ? '•••••••• ' + t( '(saved — enter a new value to replace)' ) : ( p.hint || '' ) } );
			var links = h( 'div', { class: 'sl-row' }, p.key_url ? SL.xlink( p.key_url, isOllama ? t( 'Download Ollama' ) : p.id === 'github_models' ? t( 'Create a GitHub token (Models: Read)' ) : t( 'Get an API key' ) ) : null, p.docs ? SL.xlink( p.docs, t( 'Official documentation' ) ) : null );
			box.appendChild( h( 'h4', { class: 'sl-aipanel__step', text: isOllama ? t( '1. Ollama server address' ) : t( '1. API key' ) } ) );
			box.appendChild( links );
			box.appendChild( h( 'div', { class: 'sl-row' }, input, App.abtn( SL.il( 'zap', st.has_key && ! input.value ? t( 'Load models again' ) : t( 'Connect & load models' ), 15 ), function () {
				var data = isOllama ? { base_url: input.value.trim() } : ( input.value.trim() ? { key: input.value.trim() } : {} );
				return call( 'connect', data ).then( function ( r ) {
					SL.toast( t( 'Connected — %s models available to your account.', ( r.models || [] ).length ), 'success' );
				} );
			}, 'primary' ), st.has_key && ! isOllama ? App.abtn( SL.il( 'trash', t( 'Disconnect' ), 14 ), function () {
				return new Promise( function ( resolve ) {
					SL.confirm( t( 'Remove the saved key of %s? AI features that use it stop working.', p.label ), function () {
						call( 'disconnect' ).then( function () {
							SL.toast( t( 'Removed.' ) );
						} ).catch( SL.fail ).then( resolve );
					}, true );
				} );
			}, 'sm' ) : null ) );
			box.appendChild( h( 'p', { class: 'sl-muted sl-small', text: isOllama ? t( 'SEOlity asks this server which models are installed.' ) : t( 'The key is stored encrypted on your server and never sent back to the browser. SEOlity then asks the provider which models this key can use.' ) } ) );
			return box;
		}

		function subscriptionSetup() {
			var engine = p.id === 'sub_codex' ? 'codex' : 'claude';
			var box = h( 'div', { class: 'sl-stack' } );
			box.appendChild( h( 'h4', { class: 'sl-aipanel__step', text: t( '1. Where the official program runs' ) } ) );
			var cliPath = st.cli && st.cli[ engine ];
			var choices = [
				[ 'runner', t( 'Your private GitHub runner' ), st.runner && st.runner.ready ? t( 'Ready: %s', st.runner.repo ) : t( 'Not set up yet — use the step-by-step setup below (free, nothing to install on your host).' ), st.runner && st.runner.ready ],
				[ 'cli', t( 'This server (official CLI, optional)' ), cliPath ? t( 'Found: %s', cliPath ) : ( st.cli && st.cli.can_exec ? t( 'Not installed. See Connections → Server & speed.' ) : t( 'Not possible on this host (PHP cannot start programs). Typical for shared hosting.' ) ), !! cliPath ],
				[ 'auto', t( 'Automatic' ), t( 'This server when the CLI is installed, otherwise the GitHub runner (never GitHub when the route is “Direct”).' ), true ],
			];
			box.appendChild( h( 'div', { class: 'sl-choices' }, choices.map( function ( c ) {
				var on = ( st.method_setting || 'auto' ) === c[ 0 ];
				return h( 'button', { type: 'button', class: 'sl-choice' + ( on ? ' is-on' : '' ) + ( c[ 3 ] ? '' : ' is-off' ), 'aria-pressed': on ? 'true' : 'false', onClick: function () {
					if ( on ) {
						return;
					}
					SL.api( 'ai/providers/' + p.id + '/method', { body: { method: c[ 0 ] } } ).then( save ).catch( SL.fail );
				} }, h( 'span', { class: 'sl-choice__text' }, h( 'b', { text: c[ 1 ] } ), h( 'small', { dir: 'auto', text: c[ 2 ] } ) ), on ? h( 'span', { class: 'sl-choice__check' }, SL.icon( 'check', 16 ) ) : null );
			} ) ) );
			box.appendChild( h( 'p', { class: 'sl-small' }, h( 'b', { text: t( 'Used now:' ) + ' ' } ), h( 'span', { text: st.method === 'cli' ? t( 'this server (no GitHub)' ) : t( 'GitHub runner' ) } ) ) );
			box.appendChild( h( 'div', { class: 'sl-row' }, SL.badge( st.has_key ? ( engine === 'claude' ? t( 'Claude token saved' ) : t( 'ChatGPT login saved' ) ) : ( engine === 'claude' ? t( 'Claude token missing' ) : t( 'ChatGPT login missing' ) ), st.has_key ? 'good' : 'warn' ), st.has_key ? App.abtn( SL.il( 'trash', t( 'Disconnect' ), 14 ), function () {
				return new Promise( function ( resolve ) {
					SL.confirm( t( 'Remove the saved login of %s?', p.label ), function () {
						call( 'disconnect' ).catch( SL.fail ).then( resolve );
					}, true );
				} );
			}, 'sm' ) : null ) );
			if ( st.missing && st.missing.length ) {
				box.appendChild( h( 'div', { class: 'sl-alert sl-alert--warn', dir: 'auto', text: t( 'Missing: %s', st.missing.join( ' · ' ) ) } ) );
			}
			return box;
		}

		function modelStep() {
			var box = h( 'div', { class: 'sl-stack' } );
			box.appendChild( h( 'h4', { class: 'sl-aipanel__step', text: t( '2. Model' ) } ) );
			var opts2 = modelOptions( st );
			if ( ! opts2.length ) {
				box.appendChild( h( 'p', { class: 'sl-muted', text: st.has_key || p.id === 'ollama' ? t( 'Press “Connect & load models” to load the models of your account.' ) : t( 'Connect first to see the models of your account.' ) } ) );
				return box;
			}
			var current = st.model && opts2.some( function ( o ) {
				return o[ 0 ] === st.model;
			} ) ? st.model : opts2[ 0 ][ 0 ];
			var sel = App.select( opts2, current );
			var line = h( 'div' );
			var custom = h( 'input', { class: 'sl-input', dir: 'ltr', placeholder: t( 'or a model id (optional)' ) } );
			function chosen() {
				return custom.value.trim() || sel.value;
			}
			function drawLine() {
				SL.clear( line );
				line.appendChild( checkLine( st, chosen() ) );
			}
			sel.addEventListener( 'change', drawLine );
			custom.addEventListener( 'input', drawLine );
			drawLine();
			box.appendChild( h( 'div', { class: 'sl-row' }, sel, p.group === 'subscription' && p.id !== 'github_models' ? custom : null ) );
			box.appendChild( h( 'p', { class: 'sl-muted sl-small', text: p.group === 'subscription' && p.id !== 'github_models' ? t( 'Subscriptions have no official model list: these are the official aliases. A model only counts as available after a successful test.' ) : t( '%s models from your account’s model list (loaded %s). ✓ = tested successfully.', ( st.models || [] ).length, st.models_at ? new Date( st.models_at ).toLocaleString() : '—' ) } ) );
			box.appendChild( line );
			box.appendChild( h( 'div', { class: 'sl-row' },
				App.abtn( SL.il( 'activity', t( 'Test model' ), 15 ), function () {
					return call( 'verify', { model: chosen() } ).then( function ( r ) {
						if ( r.waiting ) {
							SL.toast( t( 'Test sent to your GitHub runner…' ) );
						} else {
							SL.toast( t( 'The model works.' ), 'success' );
						}
					} );
				} ),
				App.abtn( SL.il( 'check', st.active && st.model === chosen() ? t( 'In use' ) : t( 'Use for all AI features' ), 15 ), function () {
					return call( 'activate', { model: chosen() } ).then( function ( r ) {
						SL.toast( t( 'AI engine switched: %s', p.label + ' · ' + ( chosen() || t( 'Default model' ) ) ), 'success' );
						App.refreshBoot();
						return r;
					} );
				}, 'primary' )
			) );
			box.appendChild( effortStep() );
			return box;
		}

		/** Reasoning effort for this connection, sent in the provider's own parameter. */
		function effortStep() {
			var e = st.effort || p.effort;
			var wrap = h( 'div', { class: 'sl-stack' } );
			if ( ! e || ! e.levels || e.levels.length < 2 ) {
				return wrap;
			}
			var L = { auto: t( 'Auto (the provider’s default)' ), minimal: t( 'Minimal' ), low: t( 'Low — fastest' ), medium: t( 'Medium' ), high: t( 'High — deeper analysis' ), xhigh: t( 'Extra high' ), max: t( 'Maximum — strongest, slowest' ), off: t( 'Thinking off' ), on: t( 'Thinking on' ) };
			var sel = App.select( e.levels.map( function ( l ) {
				return [ l, L[ l ] || l ];
			} ), e.value, function ( v ) {
				SL.api( 'ai/providers/' + p.id + '/effort', { body: { level: v } } ).then( function () {
					SL.toast( t( 'Saved' ), 'success' );
				} ).catch( SL.fail );
			} );
			wrap.appendChild( h( 'h4', { class: 'sl-aipanel__step', text: t( '3. AI power (reasoning effort)' ) } ) );
			wrap.appendChild( sel );
			wrap.appendChild( h( 'p', { class: 'sl-muted sl-small', text: e.note + ' ' + t( 'Higher levels give the most thorough analyses but take longer and use more tokens / plan quota.' ) } ) );
			return wrap;
		}
		draw();
	}

	/* ------------------------------------------------------------------ the two boxes */
	App.aiConnections = function ( container, opts ) {
		opts = opts || {};
		function load() {
			App.load( container, SL.api( 'ai/providers' ), render );
		}
		function tile( p ) {
			return h( 'button', { type: 'button', class: 'sl-provider sl-provider--tile' + ( p.status === 'verified' || p.status === 'authenticated' ? ' is-ok' : '' ) + ( p.active ? ' is-active' : '' ), 'data-active-label': t( 'In use' ), onClick: function () {
				panel( p, load, opts );
			} },
				h( 'div', { class: 'sl-provider__head' },
					mono( p ),
					h( 'div', null, h( 'b', { text: p.label } ), h( 'small', { dir: 'ltr', text: p.model || ( p.models && p.models.length ? t( '%s models', p.models.length ) : '—' ) } ) )
				),
				h( 'div', { class: 'sl-provider__actions' }, badge( p ), h( 'span', { class: 'sl-link', text: t( 'Settings' ) } ) )
			);
		}
		function render( r ) {
			var a = r.active || {};
			var okState = a.ready && a.status !== 'error';
			container.appendChild( h( 'div', { class: 'sl-alert ' + ( okState ? ( a.status === 'verified' ? 'sl-alert--success' : 'sl-alert--info' ) : 'sl-alert--warn' ) },
				SL.icon( okState ? 'check-circle' : 'info', 18 ),
				h( 'div', null,
					h( 'b', { text: t( 'AI engine in use:' ) + ' ' + ( a.label || '—' ) + ( a.model ? ' · ' + a.model : '' ) } ),
					h( 'div', { class: 'sl-small', dir: 'auto', text: ! a.ready ? ( a.reason || t( 'AI not configured' ) ) : a.status === 'verified' ? t( 'Tested and working — every AI feature uses it.' ) : a.status === 'error' ? a.reason : t( 'Configured but not tested yet. Open it and press “Test model”.' ) } )
				)
			) );
			var boxes = h( 'div', { class: 'sl-aiboxes' } );
			boxes.appendChild( h( 'section', { class: 'sl-card sl-aibox' },
				h( 'header', { class: 'sl-aibox__head' }, h( 'span', { class: 'sl-card__ic' }, SL.icon( 'key', 20 ) ), h( 'div', null, h( 'h3', { text: t( 'AI with API' ) } ), h( 'p', { text: t( 'Pay per use with an API key from the provider. The most reliable option.' ) } ) ) ),
				h( 'div', { class: 'sl-providers' }, ( r.api || [] ).map( tile ) )
			) );
			boxes.appendChild( h( 'section', { class: 'sl-card sl-aibox' },
				h( 'header', { class: 'sl-aibox__head' }, h( 'span', { class: 'sl-card__ic' }, SL.icon( 'sparkles', 20 ) ), h( 'div', null, h( 'h3', { text: t( 'Subscription' ) } ), h( 'p', { text: t( 'Use an AI plan you already pay for — only through the official methods each company allows.' ) } ) ) ),
				h( 'div', { class: 'sl-providers' }, ( r.subscription || [] ).map( tile ) )
			) );
			container.appendChild( boxes );
		}
		load();
		return container;
	};
} )( window, document );
