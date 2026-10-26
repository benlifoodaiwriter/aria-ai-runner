/**
 * Operations UI: "Do it" popup → (optional instructions) → AI prepares → preview with
 * before/after for every item → approve all or some → live apply → verified results → undo.
 *
 * Distinguishes clearly between: the owner's instructions, the AI recommendation (plan),
 * the proposed modification (preview), the approved items, and the completed + verified result.
 */
( function ( w, d ) {
	'use strict';
	var SL = w.SL;
	var App = SL.app;
	var C = SL.config || {};
	var t = SL.t;
	var h = SL.h;

	var OP_STATUS = {
		preparing: [ t( 'Preparing' ), 'brand' ],
		awaiting_approval: [ t( 'Waiting for your approval' ), 'warn' ],
		approved: [ t( 'Approved — applying' ), 'brand' ],
		applying: [ t( 'Applying' ), 'brand' ],
		done: [ t( 'Done & verified' ), 'good' ],
		partial: [ t( 'Partly applied' ), 'warn' ],
		failed: [ t( 'Failed' ), 'bad' ],
		cancelled: [ t( 'Rejected — nothing changed' ), '' ],
		rolled_back: [ t( 'Undone' ), '' ],
	};

	/* ------------------------------------------------------------------ diff */
	function tokens( s ) {
		return String( s || '' ).replace( /<[^>]+>/g, function ( tag ) {
			return /^<\/?(p|h[1-6]|li|ul|ol|br|div|blockquote|table|tr)\b/i.test( tag ) ? '\n' : ' ';
		} ).replace( /&nbsp;/g, ' ' ).split( /(\s+)/ ).filter( function ( x ) {
			return x !== '';
		} );
	}
	/** Word-level diff (LCS) of two texts; long texts are compared paragraph by paragraph. */
	App.diff = function ( before, after ) {
		var a = tokens( before );
		var b = tokens( after );
		var box = h( 'div', { class: 'sl-diff', dir: 'auto' } );
		if ( a.length * b.length > 4000000 ) {
			box.appendChild( h( 'div', { class: 'sl-diff__col' }, h( 'b', { text: t( 'Before' ) } ), h( 'p', { text: a.join( '' ).slice( 0, 3000 ) } ) ) );
			box.appendChild( h( 'div', { class: 'sl-diff__col' }, h( 'b', { text: t( 'After' ) } ), h( 'p', { text: b.join( '' ).slice( 0, 3000 ) } ) ) );
			return box;
		}
		var n = a.length;
		var m = b.length;
		var dp = new Array( n + 1 );
		for ( var i = 0; i <= n; i++ ) {
			dp[ i ] = new Uint32Array( m + 1 );
		}
		for ( i = n - 1; i >= 0; i-- ) {
			for ( var j = m - 1; j >= 0; j-- ) {
				dp[ i ][ j ] = a[ i ] === b[ j ] ? dp[ i + 1 ][ j + 1 ] + 1 : Math.max( dp[ i + 1 ][ j ], dp[ i ][ j + 1 ] );
			}
		}
		i = 0;
		var j2 = 0;
		var p = h( 'p' );
		while ( i < n && j2 < m ) {
			if ( a[ i ] === b[ j2 ] ) {
				p.appendChild( d.createTextNode( a[ i ] ) );
				i++;
				j2++;
			} else if ( dp[ i + 1 ][ j2 ] >= dp[ i ][ j2 + 1 ] ) {
				p.appendChild( h( 'del', { text: a[ i++ ] } ) );
			} else {
				p.appendChild( h( 'ins', { text: b[ j2++ ] } ) );
			}
		}
		while ( i < n ) {
			p.appendChild( h( 'del', { text: a[ i++ ] } ) );
		}
		while ( j2 < m ) {
			p.appendChild( h( 'ins', { text: b[ j2++ ] } ) );
		}
		box.appendChild( p );
		return box;
	};

	/* ------------------------------------------------------------------ operation view */
	/**
	 * Preview / result of an operation inside a container. onChange() re-renders the caller.
	 */
	App.operationView = function ( box, op, onChange ) {
		var timer;
		function draw( o ) {
			if ( ! d.body.contains( box ) ) {
				clearTimeout( timer );
				return;
			}
			SL.clear( box );
			var st = OP_STATUS[ o.status ] || [ o.status, '' ];
			box.appendChild( h( 'div', { class: 'sl-row' }, h( 'h3', { text: t( 'Proposed changes' ) } ), SL.badge( st[ 0 ], st[ 1 ] ) ) );
			if ( o.instructions ) {
				box.appendChild( h( 'div', { class: 'sl-op__instr' }, h( 'b', { text: t( 'Your instructions:' ) + ' ' } ), h( 'span', { dir: 'auto', text: o.instructions } ) ) );
			}
			var awaiting = 'awaiting_approval' === o.status;
			var chosen = {};
			var list = h( 'div', { class: 'sl-op__items' } );
			( o.preview || [] ).forEach( function ( it ) {
				// Items that failed their checks start unticked: approving them is a deliberate choice.
				chosen[ it.key ] = 'note' !== it.kind && ! ( it.check && false === it.check.ok );
				var res = it.result;
				var head = h( 'div', { class: 'sl-op__head' },
					awaiting && 'note' !== it.kind ? h( 'input', { type: 'checkbox', checked: chosen[ it.key ], 'aria-label': t( 'Apply this item' ), onChange: function ( e ) {
						chosen[ it.key ] = e.target.checked;
					} } ) : ( res ? SL.icon( res.ok ? 'check-circle' : 'x-circle', 18, res.ok ? 'is-good' : 'is-bad' ) : ( it.approved === false ? SL.badge( t( 'Not applied' ) ) : null ) ),
					h( 'b', { dir: 'auto', text: itemTitle( it ) } ),
					it.risk ? SL.badge( t( 'Risk: %s', t( it.risk ) ), 'high' === it.risk ? 'warn' : '' ) : null,
					it.url ? App.ext( it.url ) : null,
					it.edit ? h( 'a', { class: 'sl-link', href: it.edit, target: '_blank', rel: 'noopener', text: t( 'Edit manually' ) } ) : null
				);
				var body = h( 'div', { class: 'sl-op__body' } );
				if ( 'change' === it.kind && undefined !== it.after ) {
					if ( 'post_content' === it.field || 'term_description' === it.field ) {
						body.appendChild( App.diff( it.before, it.after ) );
					} else {
						body.appendChild( h( 'div', { class: 'sl-op__ba' },
							h( 'div', null, h( 'small', { text: t( 'Before' ) } ), h( 'p', { dir: 'auto', class: 'is-before', text: it.before || '—' } ) ),
							h( 'div', null, h( 'small', { text: t( 'After' ) } ), h( 'p', { dir: 'auto', class: 'is-after', text: 'noindex' === it.field ? ( it.after ? 'noindex' : 'index' ) : it.after || '—' } ) )
						) );
					}
				}
				if ( it.reason ) {
					var ev = it.evidence || {};
					var evb = [];
					if ( ev.search_console && 'object' === typeof ev.search_console ) {
						evb.push( t( 'Search Console: %1$s clicks, %2$s impressions', ev.search_console.clicks, ev.search_console.impressions ) );
					}
					if ( ev.internal_links ) {
						evb.push( t( '%s internal links', ev.internal_links ) );
					}
					if ( ev.hits ) {
						evb.push( t( '%s hits', ev.hits ) );
					}
					if ( ev.referrer && 'none' !== ev.referrer ) {
						evb.push( t( 'referrer: %s', ev.referrer ) );
					}
					body.appendChild( h( 'p', { class: 'sl-small' }, h( 'b', { text: t( 'AI reasoning:' ) + ' ' } ), h( 'span', { dir: 'auto', text: it.reason } ), undefined !== it.confidence ? h( 'span', { class: 'sl-muted', text: ' · ' + t( 'confidence %s%', it.confidence ) } ) : null ) );
					if ( evb.length ) {
						body.appendChild( h( 'p', { class: 'sl-small sl-muted', dir: 'auto', text: evb.join( ' · ' ) } ) );
					}
					if ( 'note' === it.kind && it.posts && it.posts.length ) {
						body.appendChild( h( 'p', { class: 'sl-small', dir: 'auto', text: t( 'Linked from: %s', it.posts.join( '، ' ) ) } ) );
					}
				}
				if ( 'gone' === it.kind && it.check && ! it.check.ok ) {
					body.appendChild( h( 'p', { class: 'sl-small sl-text-bad', dir: 'auto', text: t( 'Problem: %s', ( it.check.problems || [] ).join( ' · ' ) ) } ) );
				}
				if ( 'redirect' === it.kind && it.check ) {
					body.appendChild( h( 'p', { class: 'sl-small ' + ( it.check.ok ? 'sl-muted' : 'sl-text-bad' ), dir: 'auto', text: it.check.ok ? t( 'Checked: the target is a live page and no loop is created.' ) : t( 'Problem: %s', ( it.check.problems || [] ).join( ' · ' ) ) } ) );
					if ( it.check.ok && it.check.problems && it.check.problems.length ) {
						body.appendChild( h( 'p', { class: 'sl-small sl-text-warn', dir: 'auto', text: it.check.problems.join( ' · ' ) } ) );
					}
				}
				if ( res ) {
					body.appendChild( h( 'p', { class: 'sl-small ' + ( res.ok ? 'sl-text-good' : 'sl-text-bad' ), dir: 'auto', text: ( res.ok ? t( 'Verified:' ) : t( 'Not confirmed:' ) ) + ' ' + res.detail } ) );
				}
				list.appendChild( h( 'div', { class: 'sl-op__item' + ( res ? ( res.ok ? ' is-ok' : ' is-bad' ) : '' ) }, head, body ) );
			} );
			if ( ! ( o.preview || [] ).length ) {
				// While the AI is still working there is simply nothing to show yet.
				list.appendChild( 'preparing' === o.status ? h( 'div', { class: 'sl-row sl-muted' }, SL.spinner(), h( 'span', { text: t( 'The AI is analyzing — the proposals appear here in a moment.' ) } ) ) : h( 'p', { class: 'sl-muted', text: o.error || t( 'Nothing to change was found.' ) } ) );
			}
			box.appendChild( list );
			if ( o.error && 'cancelled' !== o.status ) {
				box.appendChild( h( 'div', { class: 'sl-alert sl-alert--error', dir: 'auto', text: o.error } ) );
			}
			var actions = h( 'div', { class: 'sl-row sl-op__actions' } );
			if ( awaiting && App.cap( 'apply' ) ) {
				actions.appendChild( App.abtn( SL.il( 'check', t( 'Approve & apply selected' ), 16 ), function () {
					var keys = Object.keys( chosen ).filter( function ( k ) {
						return chosen[ k ];
					} );
					return SL.api( 'operations/' + o.id + '/approve', { body: { keys: keys } } ).then( function ( n ) {
						SL.toast( t( 'Approved — applying on the site…' ), 'success' );
						draw( n );
					} );
				}, 'primary' ) );
				actions.appendChild( App.abtn( t( 'Reject' ), function () {
					return SL.api( 'operations/' + o.id + '/reject', { method: 'POST' } ).then( function ( n ) {
						SL.toast( t( 'Rejected — nothing was changed.' ) );
						draw( n );
						if ( onChange ) {
							onChange();
						}
					} );
				} ) );
				actions.appendChild( h( 'span', { class: 'sl-muted sl-small', text: t( 'Nothing has been changed on your site yet.' ) } ) );
			}
			if ( [ 'done', 'partial', 'failed' ].indexOf( o.status ) !== -1 && o.rollback && o.rollback.length && App.cap( 'apply' ) ) {
				actions.appendChild( App.abtn( SL.il( 'undo', t( 'Undo all of it' ), 15 ), function () {
					return new Promise( function ( resolve ) {
						SL.confirm( t( 'Undo every change of this operation (restore the previous versions, remove the redirects)?' ), function () {
							SL.api( 'operations/' + o.id + '/rollback', { method: 'POST' } ).then( function ( n ) {
								SL.toast( t( 'Undone.' ), 'success' );
								draw( n );
								if ( onChange ) {
									onChange();
								}
							} ).catch( SL.fail ).then( resolve );
						}, true );
					} );
				} ) );
			}
			if ( 'done' === o.status ) {
				box.appendChild( h( 'div', { class: 'sl-alert sl-alert--success' }, SL.icon( 'check-circle', 16 ), h( 'span', { text: o.issue_id ? t( 'Applied and verified on the site. The roadmap item is marked as completed.' ) : t( 'Every approved item was applied and verified on the site; the related roadmap items are completed.' ) } ) ) );
			}
			box.appendChild( actions );
			if ( [ 'approved', 'applying', 'preparing' ].indexOf( o.status ) !== -1 ) {
				if ( o.activity_id ) {
					var live = h( 'div' );
					box.appendChild( live );
					App.agentLive( live, { id: o.activity_id, status: 'running', meta: {} }, true );
				}
				timer = setTimeout( function () {
					SL.api( 'operations/' + o.id, { noWait: true } ).then( function ( n ) {
						draw( n );
						if ( [ 'done', 'partial', 'failed' ].indexOf( n.status ) !== -1 && onChange ) {
							onChange();
						}
					} ).catch( function () {
						draw( o );
					} );
				}, 2500 );
			}
		}
		function itemTitle( it ) {
			switch ( it.kind ) {
				case 'change':
					return ( it.label || t( 'Change' ) ) + ( it.object ? ' — ' + it.object : '' );
				case 'redirect':
					return t( '301 redirect %1$s → %2$s', decodeURI( SL.path( it.source ) ), decodeURI( SL.path( it.target ) ) );
				case 'fix_chains':
					return t( 'Point %s redirect chains straight to their final URL', it.count || '' );
				case 'bulk':
					return t( 'Optimize %s items with AI in the background', ( it.ids || [] ).length );
				case 'plan':
					return t( 'Add the topic to the content planner' );
				case 'setting':
					return it.label;
				case 'gone':
					return t( '410 Gone: %s', decodeURI( SL.path( it.source ) ) );
				case 'keep_404':
					return t( 'Leave as 404: %s', decodeURI( SL.path( it.source ) ) );
				case 'note':
					return t( 'Fix the internal link by hand: %s', decodeURI( SL.path( it.source ) ) );
			}
			return it.kind;
		}
		draw( op );
	};

	/* ------------------------------------------------------------------ Do-it popup */
	App.agentPopup = function ( recId, refresh ) {
		var m = SL.modal( { title: t( 'Do it' ), wide: true, onClose: function () {
			if ( refresh ) {
				refresh();
			}
		} } );
		m.body.appendChild( SL.spinner() );
		SL.api( 'recommendations/' + recId + '/guide' ).then( function ( g ) {
			SL.clear( m.body );
			m.box.querySelector( '.sl-modal__head h2' ).textContent = g.title;
			var right = h( 'section', { class: 'sl-agent__ai' } );
			m.body.appendChild( h( 'div', { class: 'sl-row' }, SL.priorityBadge( g.priority ), g.url ? App.ext( g.url ) : null, g.edit ? h( 'a', { class: 'sl-btn sl-btn--sm', href: g.edit, target: '_blank', rel: 'noopener' }, SL.icon( 'edit', 14 ), ' ', t( 'Edit' ) ) : null, g.module ? h( 'a', { class: 'sl-btn sl-btn--sm sl-btn--ghost', href: g.module, onClick: m.close }, t( 'Open its section' ) ) : null ) );
			m.body.appendChild( h( 'p', { class: 'sl-agent__why', text: g.reason } ) );
			m.body.appendChild( h( 'div', { class: 'sl-agent' },
				h( 'section', { class: 'sl-agent__manual' }, h( 'h3', null, SL.icon( 'map', 17 ), h( 'span', { text: t( 'Do it yourself — exactly where' ) } ) ), App.guideSteps( g.guide ), g.done_when ? h( 'p', { class: 'sl-muted sl-small', text: t( 'Done when:' ) + ' ' + g.done_when } ) : null ),
				right
			) );
			function planView() {
				SL.clear( right );
				right.appendChild( h( 'h3', null, SL.icon( 'sparkles', 17 ), h( 'span', { text: t( 'Let the AI do it' ) } ) ) );
				right.appendChild( h( 'p', { class: 'sl-muted', text: t( 'The AI prepares the fix first and shows you every change before anything is written. You approve what should be applied; every change is verified on the site and can be undone.' ) } ) );
				right.appendChild( h( 'h4', { class: 'sl-aipanel__step', text: t( 'AI recommendation (plan)' ) } ) );
				right.appendChild( App.guideSteps( g.plan ) );
				var instr = h( 'textarea', { class: 'sl-input', rows: 3, dir: 'auto', placeholder: t( 'Optional: your instructions for the AI (what to keep, what to emphasize, style, which page stays primary…)' ) } );
				instr.value = g.instructions || '';
				right.appendChild( App.field( t( 'Your instructions for the AI' ), instr, t( 'Kept with this task and used on every new attempt until you change them.' ) ) );
				var why = ! g.ai_ready ? t( 'Connect an AI provider first (Settings → AI).' ) : ! g.can_run ? t( 'This task needs your decision; follow the steps on the left.' ) : '';
				if ( why ) {
					right.appendChild( h( 'div', { class: 'sl-alert sl-alert--warn', text: why } ) );
				} else if ( App.cap( 'ai' ) && App.cap( 'apply' ) ) {
					right.appendChild( App.abtn( h( 'span', { class: 'sl-row' }, SL.icon( 'sparkles', 16 ), h( 'span', { text: t( 'Prepare the fix with AI' ) } ) ), function () {
						return SL.api( 'agent/run', { body: { rec_id: recId, instructions: instr.value } } ).then( function ( item ) {
							SL.dock.track( item, { onFinish: refresh } );
							watch( item );
						} );
					}, 'primary' ) );
				}
			}
			/** Live preparation, then the preview for approval. */
			function watch( item ) {
				SL.clear( right );
				var live = h( 'div' );
				right.appendChild( live );
				App.agentLive( live, item, false, function ( a ) {
					var opId = a.meta && ( a.meta.operation_id || ( a.meta.result && a.meta.result.operation_id ) );
					if ( opId && 'done' === a.status ) {
						SL.api( 'operations/' + opId ).then( function ( op ) {
							SL.clear( right );
							App.operationView( right, op, refresh );
						} ).catch( SL.fail );
					}
				} );
			}
			var op = g.operation;
			if ( op && [ 'preparing', 'awaiting_approval', 'approved', 'applying' ].indexOf( op.status ) !== -1 ) {
				App.operationView( right, op, refresh );
			} else if ( op && [ 'done', 'partial', 'failed' ].indexOf( op.status ) !== -1 ) {
				App.operationView( right, op, refresh );
				right.appendChild( App.btn( t( 'Prepare a new fix' ), planView, 'sm' ) );
			} else {
				planView();
			}
		} ).catch( function ( e ) {
			SL.clear( m.body );
			m.body.appendChild( h( 'div', { class: 'sl-alert sl-alert--error', text: e.message } ) );
		} );
	};

	/** Live view of an activity (agent preparation or apply run): every step as it happens. */
	App.agentLive = function ( box, item, compact, onEnd ) {
		var timer;
		var BAD = [ 'failed', 'timed_out', 'interrupted', 'cancelled' ];
		function draw( a ) {
			if ( ! d.body.contains( box ) ) {
				clearTimeout( timer );
				return;
			}
			SL.clear( box );
			var running = [ 'queued', 'running', 'waiting' ].indexOf( a.status ) !== -1;
			var steps = ( a.meta && a.meta.steps ) || [];
			box.appendChild( h( 'h3', null, running ? h( 'span', { class: 'sl-spinner sl-spinner--sm' } ) : SL.icon( BAD.indexOf( a.status ) !== -1 ? 'x-circle' : 'check-circle', 18 ), h( 'span', { text: running ? ( 'queued' === a.status ? t( 'Queued — starting in a moment…' ) : t( 'The AI is working…' ) ) : BAD.indexOf( a.status ) !== -1 ? t( 'Stopped' ) : ( a.step || t( 'Done' ) ) } ) ) );
			box.appendChild( h( 'div', { class: 'sl-dock__bar sl-agent__bar' }, h( 'span', { style: 'width:' + ( running ? Math.max( 5, a.progress || 5 ) : 100 ) + '%' } ) ) );
			box.appendChild( h( 'ol', { class: 'sl-agent__log' }, steps.slice( compact ? -6 : 0 ).map( function ( s, i, arr ) {
				var last = i === arr.length - 1 && running;
				return h( 'li', { class: last ? 'is-active' : 'is-done' }, h( 'span', { class: 'sl-agent__t', text: new Date( s.t ).toLocaleTimeString() } ), h( 'span', { dir: 'auto', text: s.text } ) );
			} ) ) );
			if ( a.route ) {
				box.appendChild( h( 'p', { class: 'sl-muted sl-small', text: t( 'AI route: %s', a.route ) } ) );
			}
			if ( BAD.indexOf( a.status ) !== -1 ) {
				box.appendChild( h( 'div', { class: 'sl-alert sl-alert--error', dir: 'auto', text: a.detail } ) );
			}
			if ( running ) {
				timer = setTimeout( function () {
					SL.api( 'activity/' + a.id, { noWait: true } ).then( draw ).catch( function () {
						timer = setTimeout( function () {
							draw( a );
						}, 6000 );
					} );
				}, 2000 );
			} else if ( onEnd ) {
				onEnd( a );
			}
		}
		if ( item.meta && item.meta.steps ) {
			draw( item );
		} else {
			SL.api( 'activity/' + item.id, { noWait: true } ).then( draw ).catch( function () {
				draw( item );
			} );
		}
	};

	/* Dock: an operation card opens its preview / result. */
	if ( SL.dock && SL.dock.opener ) {
		SL.dock.opener( 'operation', function ( result ) {
			if ( result && result.operation_id ) {
				SL.api( 'operations/' + result.operation_id ).then( function ( op ) {
					var m = SL.modal( { title: op.title || t( 'Proposed changes' ), wide: true } );
					App.operationView( m.body, op );
				} ).catch( SL.fail );
			}
		} );
		SL.dock.opener( 'agent', function ( result ) {
			if ( result && result.operation_id ) {
				SL.api( 'operations/' + result.operation_id ).then( function ( op ) {
					var m = SL.modal( { title: op.title || t( 'Proposed changes' ), wide: true } );
					App.operationView( m.body, op );
				} ).catch( SL.fail );
			} else if ( result && result.rec ) {
				App.agentPopup( result.rec );
			}
		} );
	}
} )( window, document );
