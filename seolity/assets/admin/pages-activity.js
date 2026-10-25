/**
 * Live activity & Done timeline.
 *
 *  - Live activity: what SEOlity is doing right now (AI requests with their route, background jobs,
 *    AI edits, agent runs, chat answers), refreshed every 3 seconds, with the current step, the
 *    progress and — for failures — the exact error.
 *  - Done: an exact timeline of everything that happened on the site (data collected, pages edited,
 *    redirects created, AI answers and their errors…), filterable and searchable.
 * A small indicator in the top bar shows how many tasks are running on every screen.
 */
( function ( w, d ) {
	'use strict';
	var SL = w.SL;
	var App = SL.app;
	var C = SL.config || {};
	var t = SL.t;
	var h = SL.h;

	var KINDS = {
		ai: [ 'cpu', t( 'AI request' ) ],
		job: [ 'clock', t( 'Background job' ) ],
		agent: [ 'sparkles', t( 'AI agent' ) ],
		edit: [ 'edit', t( 'AI edit' ) ],
		chat: [ 'prompt', t( 'Chat' ) ],
		change: [ 'undo', t( 'Change' ) ],
		event: [ 'info', t( 'Event' ) ],
	};
	var STATUS = { queued: [ t( 'Queued' ), '' ], running: [ t( 'Running' ), 'brand' ], waiting: [ t( 'Waiting' ), 'warn' ], done: [ t( 'Done' ), 'good' ], failed: [ t( 'Failed' ), 'bad' ], info: [ t( 'Info' ), '' ], cancelled: [ t( 'Cancelled' ), '' ], timed_out: [ t( 'Timed out' ), 'bad' ], interrupted: [ t( 'Interrupted' ), 'bad' ] };
	/** Queue job states (task manager). */
	var JOB = { queued: [ t( 'Queued' ), '' ], scheduled: [ t( 'Scheduled' ), '' ], waiting: [ t( 'Waiting' ), 'warn' ], running: [ t( 'Running' ), 'brand' ], cancelling: [ t( 'Cancelling…' ), 'warn' ], interrupted: [ t( 'Interrupted — retrying' ), 'warn' ], done: [ t( 'Done' ), 'good' ], failed: [ t( 'Failed' ), 'bad' ], cancelled: [ t( 'Cancelled' ), '' ], timed_out: [ t( 'Timed out' ), 'bad' ] };
	var ACTIVE = [ 'queued', 'running', 'waiting' ];
	var RETRYABLE = [ 'failed', 'cancelled', 'timed_out', 'interrupted' ];
	var ROUTES = { direct: t( 'Direct' ), cloudflare: 'Cloudflare', github: 'GitHub', local: t( 'This server' ) };

	function dur( ms ) {
		var s = Math.round( ( ms || 0 ) / 1000 );
		if ( s < 60 ) {
			return s + 's';
		}
		return Math.floor( s / 60 ) + 'm ' + ( s % 60 ) + 's';
	}
	function utc( s ) {
		return new Date( String( s ).replace( ' ', 'T' ) + 'Z' );
	}
	function since( s ) {
		return Date.now() - utc( s ).getTime();
	}

	function canManage( a ) {
		return App.cap( 'manage' ) || ( a.user_id && C.user && Number( C.user.id ) === Number( a.user_id ) );
	}

	/** Cancel / retry buttons for one entry; reload() redraws the list afterwards. */
	function actions( a, reload ) {
		if ( ! canManage( a ) ) {
			return null;
		}
		var out = [];
		if ( ACTIVE.indexOf( a.status ) !== -1 ) {
			out.push( App.abtn( SL.il( 'x', t( 'Cancel' ), 13 ), function () {
				return SL.api( 'activity/' + a.id + '/cancel', { method: 'POST' } ).then( function ( r ) {
					SL.toast( r && r.status === 'cancelled' ? t( 'Cancelled.' ) : t( 'Cancelling — the task stops at its next safe point…' ), 'success' );
					reload();
				} );
			}, 'sm' ) );
		} else if ( RETRYABLE.indexOf( a.status ) !== -1 && a.kind !== 'ai' && a.kind !== 'event' && a.kind !== 'change' ) {
			out.push( App.abtn( SL.il( 'refresh', t( 'Retry' ), 13 ), function () {
				return SL.api( 'activity/' + a.id + '/retry', { method: 'POST' } ).then( function () {
					SL.toast( t( 'Queued again.' ), 'success' );
					reload();
				} );
			}, 'sm' ) );
		}
		return out.length ? h( 'div', { class: 'sl-row sl-act__actions' }, out ) : null;
	}

	function row( a, live, reload, select ) {
		var k = KINDS[ a.kind ] || KINDS.event;
		var st = STATUS[ a.status ] || [ a.status, '' ];
		var running = ACTIVE.indexOf( a.status ) !== -1;
		var bad = [ 'failed', 'timed_out', 'interrupted' ].indexOf( a.status ) !== -1;
		var steps = h( 'div', { class: 'sl-act__steps', hidden: true } );
		var item = h( 'div', { class: 'sl-act__item is-' + a.status },
			select ? h( 'input', { type: 'checkbox', class: 'sl-act__check', 'aria-label': t( 'Select' ), value: String( a.id ), onChange: select } ) : null,
			h( 'span', { class: 'sl-act__ic' }, a.status === 'running' || a.status === 'waiting' ? h( 'span', { class: 'sl-spinner sl-spinner--sm' } ) : SL.icon( a.status === 'queued' ? 'clock' : k[ 0 ], 16 ) ),
			h( 'div', { class: 'sl-act__main' },
				h( 'div', { class: 'sl-act__title' }, h( 'b', { dir: 'auto', text: a.title } ), SL.badge( st[ 0 ], st[ 1 ] ), a.route ? SL.badge( t( 'via %s', ROUTES[ a.route ] || a.route ), 'brand' ) : null ),
				a.detail ? h( 'div', { class: 'sl-act__detail' + ( bad ? ' is-error' : '' ), dir: 'auto', text: a.detail } ) : null,
				running && a.step ? h( 'div', { class: 'sl-act__step', dir: 'auto' }, SL.icon( 'arrow-' + ( C.rtl ? 'left' : 'right' ), 12 ), ' ', a.step ) : null,
				running && a.progress ? h( 'div', { class: 'sl-dock__bar' }, h( 'span', { style: 'width:' + a.progress + '%' } ) ) : null,
				reload ? actions( a, reload ) : null,
				steps
			),
			h( 'div', { class: 'sl-act__side' },
				h( 'span', { class: 'sl-act__time', text: live ? dur( running ? since( a.started_at ) : a.ms ) : utc( a.started_at ).toLocaleTimeString() } ),
				! live && a.ms ? h( 'span', { class: 'sl-muted sl-small', text: dur( a.ms ) } ) : null,
				a.user ? h( 'span', { class: 'sl-muted sl-small', text: a.user } ) : null,
				h( 'button', { type: 'button', class: 'sl-icon-btn sl-icon-btn--sm', title: t( 'Details' ), 'aria-label': t( 'Details' ), onClick: function () {
					if ( ! steps.hidden ) {
						steps.hidden = true;
						return;
					}
					steps.hidden = false;
					SL.clear( steps );
					steps.appendChild( SL.spinner() );
					SL.api( 'activity/' + a.id, { noWait: true } ).then( function ( full ) {
						SL.clear( steps );
						var list = ( full.meta && full.meta.steps ) || [];
						if ( ! list.length ) {
							steps.appendChild( h( 'p', { class: 'sl-muted sl-small', text: t( 'No step details for this entry.' ) } ) );
						}
						steps.appendChild( h( 'ol', { class: 'sl-agent__log' }, list.map( function ( s ) {
							return h( 'li', { class: 'is-done' }, h( 'span', { class: 'sl-agent__t', text: new Date( s.t ).toLocaleTimeString() } ), h( 'span', { dir: 'auto', text: s.text } ) );
						} ) ) );
						var m = full.meta || {};
						var extra = [];
						if ( m.model ) {
							extra.push( t( 'Model: %s', m.model ) );
						}
						if ( m.tokens ) {
							extra.push( t( 'Tokens: %1$s in / %2$s out', m.tokens.input, m.tokens.output ) );
						}
						if ( m.knowledge && m.knowledge.length ) {
							extra.push( t( 'Knowledge: %s', m.knowledge.join( ' · ' ) ) );
						}
						if ( m.error ) {
							extra.push( t( 'Error code: %s', m.error ) );
						}
						if ( extra.length ) {
							steps.appendChild( h( 'p', { class: 'sl-muted sl-small', text: extra.join( ' · ' ) } ) );
						}
						if ( m.lines && m.lines.length ) {
							steps.appendChild( h( 'ul', { class: 'sl-dock__lines' }, m.lines.map( function ( l ) {
								return h( 'li', null, SL.icon( 'check', 13 ), h( 'span', { dir: 'auto', text: l } ) );
							} ) ) );
						}
					} ).catch( function ( e ) {
						SL.clear( steps );
						steps.appendChild( h( 'p', { class: 'sl-muted', text: e.message } ) );
					} );
				} }, SL.icon( 'chevron-down', 14 ) )
			)
		);
		return item;
	}

	App.pages.activity = function ( v, q ) {
		var tab = q.tab === 'done' || ( q.tab === 'tasks' && App.cap( 'manage' ) ) ? q.tab : 'live';
		v.appendChild( App.header( t( 'Activity' ), t( 'What SEOlity is doing right now, and an exact timeline of everything it did on your site — every AI answer (with the route it took and any error), every edit, every data collection.' ) ) );
		var tabs = h( 'nav', { class: 'sl-subtabs sl-act__tabs' } );
		var box = h( 'div', { class: 'sl-stack' } );
		v.appendChild( tabs );
		v.appendChild( box );
		var timer = null;
		var filter = { kind: '', status: '', q: '', page: 1 };

		function drawTabs() {
			SL.clear( tabs );
			[ [ 'live', t( 'Live activity' ), 'activity' ], [ 'tasks', t( 'Task queue' ), 'layers' ], [ 'done', t( 'Completed work' ), 'list-checks' ] ].filter( function ( x ) {
				return x[ 0 ] !== 'tasks' || App.cap( 'manage' );
			} ).forEach( function ( x ) {
				tabs.appendChild( h( 'a', { href: '#/activity?tab=' + x[ 0 ], class: 'sl-subtab' + ( tab === x[ 0 ] ? ' is-active' : '' ), onClick: function ( e ) {
					e.preventDefault();
					tab = x[ 0 ];
					drawTabs();
					show();
				} }, SL.icon( x[ 2 ], 15 ), h( 'span', { text: x[ 1 ] } ) ) );
			} );
		}

		function live() {
			clearTimeout( timer );
			SL.api( 'activity/live', { noWait: true } ).then( function ( r ) {
				if ( tab !== 'live' || ! d.body.contains( box ) ) {
					return;
				}
				SL.clear( box );
				var c = r.counts || {};
				box.appendChild( h( 'div', { class: 'sl-kpis sl-act__kpis' },
					App.kpi( t( 'Running now' ), SL.num( c.running || 0, 0 ), null, { icon: 'activity', tone: 'blue' } ),
					App.kpi( t( 'Waiting (GitHub / retry)' ), SL.num( ( c.waiting || 0 ) + ( c.queued || 0 ), 0 ), null, { icon: 'clock', tone: 'amber' } ),
					App.kpi( t( 'Failed in the last hour' ), SL.num( c.failed || 0, 0 ), null, { icon: 'alert', tone: 'red' } ),
					App.kpi( t( 'Jobs in the queue' ), SL.num( r.queue && r.queue.pending ? r.queue.pending : 0, 0 ), null, { icon: 'layers', tone: 'violet' } )
				) );
				var items = r.items || [];
				box.appendChild( items.length ? h( 'div', { class: 'sl-act__list' }, items.map( function ( a ) {
					return row( a, true, live );
				} ) ) : SL.empty( t( 'Nothing is running right now' ), t( 'Start an analysis, an AI edit or a chat question — you will see every step here as it happens.' ) ) );
				box.appendChild( h( 'p', { class: 'sl-muted sl-small', text: t( 'Updates every 3 seconds · finished work moves to “Completed work”.' ) } ) );
				timer = setTimeout( live, 3000 );
			} ).catch( function ( e ) {
				SL.clear( box );
				box.appendChild( h( 'div', { class: 'sl-alert sl-alert--error', text: e.message } ) );
				timer = setTimeout( live, 8000 );
			} );
		}

		function done() {
			clearTimeout( timer );
			var kinds = h( 'select', { class: 'sl-input sl-input--sm', onChange: function ( e ) {
				filter.kind = e.target.value;
				filter.page = 1;
				done();
			} }, [ h( 'option', { value: '', text: t( 'Everything' ) } ) ].concat( Object.keys( KINDS ).map( function ( k ) {
				return h( 'option', { value: k, text: KINDS[ k ][ 1 ], selected: filter.kind === k } );
			} ) ) );
			var sts = h( 'select', { class: 'sl-input sl-input--sm', onChange: function ( e ) {
				filter.status = e.target.value;
				filter.page = 1;
				done();
			} }, [ [ '', t( 'All results' ) ], [ 'done', t( 'Done' ) ], [ 'failed', t( 'Failed' ) ], [ 'timed_out', t( 'Timed out' ) ], [ 'interrupted', t( 'Interrupted' ) ], [ 'cancelled', t( 'Cancelled' ) ] ].map( function ( x ) {
				return h( 'option', { value: x[ 0 ], text: x[ 1 ], selected: filter.status === x[ 0 ] } );
			} ) );
			var qi = h( 'input', { class: 'sl-input sl-input--sm', type: 'search', value: filter.q, placeholder: t( 'Search…' ), onChange: function ( e ) {
				filter.q = e.target.value;
				filter.page = 1;
				done();
			} } );
			App.load( box, SL.api( 'activity', { query: filter } ), function ( r ) {
				var chosen = {};
				var delBtn = App.abtn( SL.il( 'trash', t( 'Delete selected' ), 14 ), function () {
					var ids = Object.keys( chosen ).filter( function ( k ) {
						return chosen[ k ];
					} );
					if ( ! ids.length ) {
						SL.toast( t( 'Select entries first.' ) );
						return Promise.resolve();
					}
					return SL.api( 'activity/delete', { body: { ids: ids } } ).then( function ( x ) {
						SL.toast( t( '%s entries deleted.', x.deleted ), 'success' );
						done();
					} );
				}, 'sm' );
				var purge = App.abtn( SL.il( 'trash', t( 'Delete entries older than 30 days' ), 14 ), function () {
					return new Promise( function ( resolve ) {
						SL.confirm( t( 'Delete finished entries older than 30 days? Running work is never deleted.' ), function () {
							SL.api( 'activity/purge', { body: { older_than: 30, kind: filter.kind, status: filter.status } } ).then( function ( x ) {
								SL.toast( t( '%s entries deleted.', x.deleted ), 'success' );
								done();
							} ).catch( SL.fail ).then( resolve );
						}, true );
					} );
				}, 'sm' );
				box.appendChild( h( 'div', { class: 'sl-row sl-act__filters' }, kinds, sts, qi, h( 'span', { class: 'sl-muted sl-small', text: t( '%s entries', SL.num( r.total, 0 ) ) } ), App.cap( 'manage' ) ? delBtn : null, App.cap( 'manage' ) ? purge : null ) );
				var day = '';
				var list = h( 'div', { class: 'sl-act__list' } );
				( r.items || [] ).forEach( function ( a ) {
					var dd = utc( a.started_at ).toLocaleDateString();
					if ( dd !== day ) {
						day = dd;
						list.appendChild( h( 'h3', { class: 'sl-act__day', text: dd } ) );
					}
					list.appendChild( row( a, false, done, App.cap( 'manage' ) ? function ( e ) {
						chosen[ e.target.value ] = e.target.checked;
					} : null ) );
				} );
				box.appendChild( r.items && r.items.length ? list : SL.empty( t( 'Nothing here yet' ), t( 'Everything SEOlity does on your site will be listed here with the exact time.' ) ) );
				var pager = App.pager( r.total, r.page, r.per_page, function ( p ) {
					filter.page = p;
					done();
				} );
				if ( pager ) {
					box.appendChild( pager );
				}
			} );
		}

		/** Task manager: every background job with its real state, and what you can do with it. */
		var jfilter = { status: 'active', page: 1 };
		function tasks() {
			clearTimeout( timer );
			App.load( box, SL.api( 'tasks', { query: jfilter } ), function ( r ) {
				var s = r.stats || {};
				var chips = [ [ 'active', t( 'Active' ), ( s.queued || 0 ) + ( s.scheduled || 0 ) + ( s.waiting || 0 ) + ( s.running || 0 ) ], [ 'running', t( 'Running' ), s.running || 0 ], [ 'failed', t( 'Failed' ), s.failed || 0 ], [ 'timed_out', t( 'Timed out' ), s.timed_out || 0 ], [ 'cancelled', t( 'Cancelled' ), s.cancelled || 0 ], [ 'done', t( 'Done' ), s.done || 0 ] ];
				box.appendChild( h( 'div', { class: 'sl-row sl-act__filters' },
					h( 'div', { class: 'sl-chips' }, chips.map( function ( c ) {
						return h( 'button', { type: 'button', class: 'sl-chip' + ( jfilter.status === c[ 0 ] ? ' is-on' : '' ), onClick: function () {
							jfilter.status = c[ 0 ];
							jfilter.page = 1;
							tasks();
						} }, h( 'span', { text: c[ 1 ] } ), h( 'b', { text: SL.num( c[ 2 ], 0 ) } ) );
					} ) ),
					App.abtn( SL.il( 'activity', t( 'Check stuck tasks now' ), 14 ), function () {
						return SL.api( 'tasks/recover', { method: 'POST' } ).then( function ( x ) {
							SL.toast( x.fixed ? t( '%s tasks corrected.', x.fixed ) : t( 'All task states are correct.' ), 'success' );
							tasks();
						} );
					}, 'sm' ),
					App.abtn( SL.il( 'play', t( 'Run queue now' ), 14 ), function () {
						return SL.api( 'queue/run', { method: 'POST' } ).then( function ( x ) {
							SL.toast( t( '%s jobs processed.', x.processed ), 'success' );
							tasks();
						} );
					}, 'sm' ),
					[ 'failed', 'timed_out', 'cancelled', 'done' ].indexOf( jfilter.status ) !== -1 && r.total ? App.abtn( SL.il( 'trash', t( 'Delete all in this list' ), 14 ), function () {
						return new Promise( function ( resolve ) {
							SL.confirm( t( 'Delete these finished task records? This only removes history; nothing on your site changes.' ), function () {
								SL.api( 'tasks/purge', { body: { status: jfilter.status } } ).then( function ( x ) {
									SL.toast( t( '%s entries deleted.', x.deleted ), 'success' );
									tasks();
								} ).catch( SL.fail ).then( resolve );
							}, true );
						} );
					}, 'sm' ) : null
				) );
				if ( ! r.items || ! r.items.length ) {
					box.appendChild( SL.empty( t( 'No tasks here' ), jfilter.status === 'active' ? t( 'Nothing is queued or running. Background work appears here as soon as it is queued.' ) : t( 'No tasks in this state.' ) ) );
					return;
				}
				var ago = function ( x ) {
					return x ? dur( since( x ) ) : '—';
				};
				box.appendChild( App.table( [
					{ label: '#', key: 'id' },
					{ label: t( 'Task' ), render: function ( j ) {
						return h( 'div', null, h( 'b', { text: j.label } ), h( 'div', { class: 'sl-muted sl-small', dir: 'ltr', text: j.hook } ) );
					} },
					{ label: t( 'State' ), render: function ( j ) {
						var x = JOB[ j.state ] || [ j.state, '' ];
						return h( 'div', null, SL.badge( x[ 0 ], x[ 1 ] ), j.status === 'running' && j.progress ? h( 'div', { class: 'sl-dock__bar' }, h( 'span', { style: 'width:' + j.progress + '%' } ) ) : null );
					} },
					{ label: t( 'Attempts' ), render: function ( j ) {
						return j.attempts + ' / ' + j.max_attempts + ( j.interruptions ? ' · ' + t( '%s interruptions', j.interruptions ) : '' );
					} },
					{ label: t( 'Started' ), render: function ( j ) {
						return j.started_at ? utc( j.started_at ).toLocaleString() : ( j.state === 'scheduled' ? t( 'at %s', utc( j.available_at ).toLocaleTimeString() ) : '—' );
					} },
					{ label: t( 'Last sign of life' ), render: function ( j ) {
						return j.status === 'running' ? ago( j.heartbeat_at || j.reserved_at ) : '—';
					} },
					{ label: t( 'Error' ), render: function ( j ) {
						return j.last_error ? h( 'span', { class: 'sl-small sl-act__detail is-error', dir: 'auto', text: j.last_error } ) : '';
					} },
					{ label: '', render: function ( j ) {
						return h( 'div', { class: 'sl-row' },
							j.can.cancel ? App.abtn( t( 'Cancel' ), function () {
								return SL.api( 'tasks/' + j.id + '/cancel', { method: 'POST' } ).then( tasks );
							}, 'sm' ) : null,
							j.can.retry ? App.abtn( t( 'Retry' ), function () {
								return SL.api( 'tasks/' + j.id + '/retry', { method: 'POST' } ).then( tasks );
							}, 'sm' ) : null,
							j.can.delete ? App.abtn( SL.icon( 'trash', 14 ), function () {
								return SL.api( 'tasks/' + j.id + '/delete', { method: 'POST' } ).then( tasks );
							}, 'sm' ) : null
						);
					} },
				], r.items ) );
				var pager = App.pager( r.total, r.page, 50, function ( p ) {
					jfilter.page = p;
					tasks();
				} );
				if ( pager ) {
					box.appendChild( pager );
				}
				box.appendChild( h( 'p', { class: 'sl-muted sl-small', text: t( 'A task whose worker stops (PHP time limit, memory, server restart) is detected automatically, retried, and after repeated interruptions marked “Timed out” — it never stays “running” forever. Cancelling a running task stops it at its next safe point, never in the middle of a change.' ) } ) );
			} );
		}

		function show() {
			if ( tab === 'live' ) {
				SL.clear( box );
				box.appendChild( SL.spinner() );
				live();
			} else if ( tab === 'tasks' ) {
				tasks();
			} else {
				done();
			}
		}
		drawTabs();
		show();
	};

	/* ------------------------------------------------------------------ Top-bar indicator */
	var pill = null;
	function pulse() {
		SL.api( 'activity/live', { noWait: true } ).then( function ( r ) {
			var c = r.counts || {};
			var n = ( c.running || 0 ) + ( c.waiting || 0 ) + ( c.queued || 0 );
			var bar = d.querySelector( '.sl-top__actions' );
			if ( ! bar ) {
				return;
			}
			if ( ! pill || ! d.body.contains( pill ) ) {
				pill = h( 'a', { href: '#/activity', class: 'sl-act-pill' } );
				bar.insertBefore( pill, bar.firstChild );
			}
			SL.clear( pill );
			pill.className = 'sl-act-pill' + ( n ? ' is-busy' : '' ) + ( c.failed ? ' has-failed' : '' );
			pill.appendChild( n ? h( 'span', { class: 'sl-spinner sl-spinner--sm' } ) : SL.icon( 'activity', 15 ) );
			pill.appendChild( h( 'span', { text: n ? t( '%s running', n ) : t( 'Activity' ) } ) );
			pill.title = c.failed ? t( '%s failed in the last hour', c.failed ) : t( 'Live activity' );
		} ).catch( function () {} ).then( function () {
			setTimeout( pulse, 15000 );
		} );
	}
	if ( d.readyState === 'loading' ) {
		d.addEventListener( 'DOMContentLoaded', function () {
			setTimeout( pulse, 1500 );
		} );
	} else {
		setTimeout( pulse, 1500 );
	}
} )( window, document );
