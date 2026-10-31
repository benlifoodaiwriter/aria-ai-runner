/**
 * AI SEO Chat.
 *
 * Questions and answers live on the server: the answer arrives even if the page was reloaded
 * or closed while the AI was working (the page reopens the last conversation and waits for
 * pending answers). Each answer shows the route it took (direct, Cloudflare, GitHub or this
 * server), the model, the time and the site data it used; failed answers show the error and
 * can be retried. Conversations can be searched, reopened, copied and deleted, and a question
 * can be tied to one page of the site.
 */
( function ( w, d ) {
	'use strict';
	var SL = w.SL;
	var App = SL.app;
	var t = SL.t;
	var h = SL.h;
	var KEY = 'seolity_chat_cid';
	var ROUTES = { direct: t( 'Direct' ), cloudflare: 'Cloudflare', github: 'GitHub', local: t( 'This server' ) };

	function store( k, v ) {
		try {
			if ( v ) {
				w.localStorage.setItem( k, v );
			} else {
				w.localStorage.removeItem( k );
			}
		} catch ( e ) {}
	}
	function load( k ) {
		try {
			return w.localStorage.getItem( k ) || '';
		} catch ( e ) {
			return '';
		}
	}

	App.pages.ai = function ( v ) {
		var cid = App.state.chatId || load( KEY );
		var page = null; // { id, label, url } the question is about.
		var poll = null;
		var busy = false;

		var log = h( 'div', { class: 'sl-chat__log', 'aria-live': 'polite' } );
		var input = h( 'textarea', { class: 'sl-input sl-chat__input', rows: 2, placeholder: t( 'e.g. Why did my organic sales drop this month?' ), dir: 'auto' } );
		var send = App.btn( h( 'span', { class: 'sl-row' }, SL.icon( 'send', 16 ), h( 'span', { text: t( 'Send' ) } ) ), ask, 'primary' );
		var pageChip = h( 'div', { class: 'sl-chat__page' } );
		var list = h( 'div', { class: 'sl-chat__convs' } );
		var search = h( 'input', { class: 'sl-input sl-input--sm', type: 'search', placeholder: t( 'Search conversations…' ), onInput: renderList } );
		var convs = [];

		App.warmAi();
		input.addEventListener( 'focus', App.warmAi );
		input.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' && ! e.shiftKey && ! e.isComposing ) {
				e.preventDefault();
				ask();
			}
		} );

		v.appendChild( App.header( t( 'AI SEO Chat' ), t( 'Ask anything about your site. Answers are grounded in your real Search Console, audit, competitor and experiment data — and stay here even if you reload the page.' ), [
			App.btn( h( 'span', { class: 'sl-row' }, SL.icon( 'copy', 15 ), h( 'span', { text: t( 'Copy conversation' ) } ) ), copyAll, 'sm' ),
			App.btn( h( 'span', { class: 'sl-row' }, SL.icon( 'plus', 15 ), h( 'span', { text: t( 'New conversation' ) } ) ), function () {
				open( '' );
			}, 'primary' ),
		] ) );

		var samples = [ t( 'Why has my organic traffic dropped?' ), t( 'Which pages should I optimize today?' ), t( 'Which new articles are not indexed yet?' ), t( 'What did my competitor do recently?' ), t( 'Why does this page have a low CTR?' ), t( 'Write 5 article ideas my competitors rank for' ), t( 'Should I redirect this URL?' ) ];
		var chips = h( 'div', { class: 'sl-chips sl-chat__samples' }, samples.map( function ( s ) {
			return h( 'button', { type: 'button', class: 'sl-chip sl-chip--btn', onClick: function () {
				input.value = s;
				input.focus();
			} }, s );
		} ) );

		var pickPage = h( 'button', { type: 'button', class: 'sl-btn sl-btn--sm sl-btn--ghost', onClick: choosePage }, SL.icon( 'content', 15 ), ' ', t( 'About a page…' ) );
		v.appendChild( h( 'div', { class: 'sl-chat' },
			h( 'div', { class: 'sl-chat__main' }, log, chips, h( 'div', { class: 'sl-chat__compose' }, h( 'div', { class: 'sl-chat__tools' }, pickPage, pageChip ), h( 'div', { class: 'sl-chat__row' }, input, send ), h( 'small', { class: 'sl-muted', text: t( 'Enter to send · Shift+Enter for a new line' ) } ) ) ),
			h( 'aside', { class: 'sl-chat__history' }, h( 'h4', { text: t( 'Conversations' ) } ), search, list )
		) );

		function renderPage() {
			SL.clear( pageChip );
			if ( page ) {
				pageChip.appendChild( h( 'span', { class: 'sl-chip' }, SL.icon( 'content', 13 ), ' ', page.label, ' ', h( 'button', { type: 'button', class: 'sl-chat__x', 'aria-label': t( 'Remove' ), onClick: function () {
					page = null;
					renderPage();
				} }, '×' ) ) );
			}
		}

		function choosePage() {
			var m = SL.modal( { title: t( 'Which page is the question about?' ) } );
			var q = h( 'input', { class: 'sl-input', type: 'search', placeholder: t( 'Search pages, posts, products…' ) } );
			var res = h( 'div', { class: 'sl-options' } );
			var tmr;
			q.addEventListener( 'input', function () {
				clearTimeout( tmr );
				tmr = setTimeout( function () {
					SL.api( 'search', { query: { q: q.value } } ).then( function ( items ) {
						SL.clear( res );
						items.filter( function ( x ) {
							return x.type === 'post';
						} ).forEach( function ( x ) {
							res.appendChild( h( 'button', { type: 'button', class: 'sl-option', onClick: function () {
								page = { id: x.id, label: x.label, url: x.url };
								renderPage();
								m.close();
								input.focus();
							} }, x.label + ' · ' + x.sub ) );
						} );
					} );
				}, 250 );
			} );
			m.body.appendChild( h( 'div', { class: 'sl-stack' }, q, res ) );
		}

		function bubble( msg ) {
			var meta = msg.meta || {};
			if ( msg.role === 'user' ) {
				return h( 'div', { class: 'sl-msg sl-msg--user' }, h( 'div', { dir: 'auto', text: msg.content } ), meta.page ? h( 'div', { class: 'sl-msg__page' }, SL.icon( 'content', 12 ), ' ', decodeURIComponent( meta.page.replace( /^https?:\/\/[^/]+/, '' ) ) ) : null );
			}
			var st = meta.status || ( msg.content ? 'done' : 'pending' );
			if ( st === 'pending' || st === 'waiting' ) {
				var secs = meta.started ? Math.round( ( Date.now() - new Date( meta.started ).getTime() ) / 1000 ) : 0;
				return h( 'div', { class: 'sl-msg sl-msg--assistant is-pending' },
					h( 'span', { class: 'sl-typing', 'aria-hidden': 'true' }, h( 'i' ), h( 'i' ), h( 'i' ) ),
					h( 'span', { class: 'sl-muted', text: ( st === 'waiting' ? t( 'The AI is answering through GitHub — the answer will appear here, even after a reload.' ) : t( 'Thinking with your site data and the SEO knowledge base…' ) ) + ( secs > 2 ? ' ' + secs + 's' : '' ) } )
				);
			}
			if ( st === 'failed' ) {
				return h( 'div', { class: 'sl-msg sl-msg--assistant is-failed' },
					h( 'div', { class: 'sl-alert sl-alert--error', dir: 'auto' }, h( 'b', { text: t( 'The AI could not answer:' ) + ' ' } ), meta.error || '' ),
					h( 'div', { class: 'sl-msg__foot' }, App.btn( h( 'span', { class: 'sl-row' }, SL.icon( 'refresh', 14 ), h( 'span', { text: t( 'Try again' ) } ) ), function () {
						retry( msg.id );
					}, 'sm' ), meta.code === 'seolity_ai_not_configured' || meta.code === 'seolity_route_failed' ? App.link( t( 'Check the connection' ), 'network' ) : null )
				);
			}
			var b = h( 'div', { class: 'sl-msg sl-msg--assistant' }, SL.markdown( msg.content ) );
			if ( meta.actions && meta.actions.length ) {
				b.appendChild( h( 'div', { class: 'sl-msg__actions' }, meta.actions.map( function ( a ) {
					return h( 'a', { href: a.route, class: 'sl-btn sl-btn--sm' }, a.title );
				} ) ) );
			}
			if ( meta.data_used && meta.data_used.length ) {
				b.appendChild( h( 'details', { class: 'sl-msg__data' }, h( 'summary', { text: t( 'Data used' ) } ), h( 'ul', null, meta.data_used.map( function ( x ) {
					return h( 'li', { text: x } );
				} ) ) ) );
			}
			var info = [];
			if ( meta.route ) {
				info.push( t( 'via %s', ROUTES[ meta.route ] || meta.route ) );
			}
			if ( meta.meta && meta.meta.model ) {
				info.push( meta.meta.model );
			}
			if ( meta.ms ) {
				info.push( ( meta.ms / 1000 ).toFixed( 1 ) + 's' );
			}
			b.appendChild( h( 'div', { class: 'sl-msg__foot' },
				h( 'span', { class: 'sl-muted sl-small', text: info.join( ' · ' ) } ),
				h( 'button', { type: 'button', class: 'sl-icon-btn sl-icon-btn--sm', title: t( 'Copy' ), 'aria-label': t( 'Copy' ), onClick: function () {
					if ( navigator.clipboard ) {
						navigator.clipboard.writeText( msg.content ).then( function () {
							SL.toast( t( 'Copied.' ) );
						} );
					}
				} }, SL.icon( 'copy', 14 ) ),
				h( 'button', { type: 'button', class: 'sl-icon-btn sl-icon-btn--sm', title: t( 'Answer again' ), 'aria-label': t( 'Answer again' ), onClick: function () {
					retry( msg.id );
				} }, SL.icon( 'refresh', 14 ) )
			) );
			return b;
		}

		var messages = [];
		function render() {
			SL.clear( log );
			if ( ! messages.length ) {
				log.appendChild( h( 'div', { class: 'sl-msg sl-msg--assistant' }, h( 'div', { text: t( 'Hi! I am your AI SEO strategist. Ask me about traffic, rankings, new articles that are not indexed yet, products, competitors or what to do next.' ) } ) ) );
			}
			messages.forEach( function ( m ) {
				log.appendChild( bubble( m ) );
			} );
			log.scrollTop = log.scrollHeight;
			var pending = messages.some( function ( m ) {
				return m.role === 'assistant' && m.meta && ( m.meta.status === 'pending' || m.meta.status === 'waiting' );
			} );
			chips.hidden = messages.length > 0;
			send.disabled = busy;
			clearTimeout( poll );
			if ( pending && cid && ! busy ) {
				poll = setTimeout( refresh, 2500 );
			}
		}

		function refresh() {
			if ( ! cid || ! d.body.contains( log ) ) {
				return;
			}
			SL.api( 'chat/' + cid, { noWait: true } ).then( function ( r ) {
				messages = r.messages || [];
				render();
			} ).catch( function () {
				poll = setTimeout( refresh, 6000 );
			} );
		}

		function open( id ) {
			cid = id;
			App.state.chatId = id;
			store( KEY, id );
			messages = [];
			render();
			renderList();
			if ( id ) {
				refresh();
			} else {
				input.focus();
			}
		}

		function ask() {
			var text = input.value.trim();
			if ( ! text || busy ) {
				return;
			}
			input.value = '';
			busy = true;
			messages.push( { role: 'user', content: text, meta: page ? { page: page.url } : {} } );
			messages.push( { role: 'assistant', content: '', meta: { status: 'pending', started: new Date().toISOString() } } );
			render();
			SL.api( 'chat', { body: { message: text, conversation_id: cid, page: page ? page.id : '' }, noWait: true } ).then( function ( r ) {
				busy = false;
				if ( r.conversation_id !== cid ) {
					cid = r.conversation_id;
					App.state.chatId = cid;
					store( KEY, cid );
					loadList();
				}
				refresh();
			} ).catch( function ( e ) {
				busy = false;
				messages.pop();
				messages.push( { role: 'assistant', content: '', meta: { status: 'failed', error: e.message } } );
				render();
				if ( cid ) {
					refresh();
				}
			} );
		}

		function retry( id ) {
			if ( ! id ) {
				return;
			}
			messages.forEach( function ( m ) {
				if ( m.id === id ) {
					m.content = '';
					m.meta = Object.assign( {}, m.meta, { status: 'pending', started: new Date().toISOString() } );
				}
			} );
			busy = true;
			render();
			SL.api( 'chat/message/' + id + '/retry', { method: 'POST', noWait: true } ).then( function () {
				busy = false;
				refresh();
			} ).catch( function ( e ) {
				busy = false;
				SL.fail( e );
				refresh();
			} );
		}

		function copyAll() {
			var txt = messages.filter( function ( m ) {
				return m.content;
			} ).map( function ( m ) {
				return ( m.role === 'user' ? '> ' : '' ) + m.content;
			} ).join( '\n\n' );
			if ( navigator.clipboard && txt ) {
				navigator.clipboard.writeText( txt ).then( function () {
					SL.toast( t( 'Copied.' ) );
				} );
			}
		}

		function renderList() {
			SL.clear( list );
			var q = ( search.value || '' ).toLowerCase();
			var items = convs.filter( function ( c ) {
				return ! q || ( c.first_question || '' ).toLowerCase().indexOf( q ) !== -1;
			} );
			if ( ! items.length ) {
				list.appendChild( h( 'p', { class: 'sl-muted sl-small', text: t( 'No conversations yet.' ) } ) );
			}
			items.forEach( function ( c ) {
				list.appendChild( h( 'div', { class: 'sl-chat__conv' + ( c.conversation_id === cid ? ' is-on' : '' ) },
					h( 'button', { type: 'button', class: 'sl-chat__conv-open', onClick: function () {
						open( c.conversation_id );
					} }, h( 'span', { dir: 'auto', text: ( c.first_question || '…' ).slice( 0, 70 ) } ), h( 'small', { text: SL.date( String( c.updated ).replace( ' ', 'T' ) + 'Z' ) + ' · ' + t( '%s messages', c.messages ) } ) ),
					h( 'button', { type: 'button', class: 'sl-icon-btn sl-icon-btn--sm', title: t( 'Delete' ), 'aria-label': t( 'Delete' ), onClick: function () {
						SL.confirm( t( 'Delete this conversation?' ), function () {
							SL.api( 'chat/' + c.conversation_id + '/delete', { method: 'POST' } ).then( function () {
								if ( c.conversation_id === cid ) {
									open( '' );
								}
								loadList();
							} ).catch( SL.fail );
						}, true );
					} }, SL.icon( 'trash', 14 ) )
				) );
			} );
		}
		function loadList() {
			SL.api( 'chat/conversations' ).then( function ( l ) {
				convs = l || [];
				renderList();
			} ).catch( function () {} );
		}

		loadList();
		open( cid );
	};
} )( window, document );
