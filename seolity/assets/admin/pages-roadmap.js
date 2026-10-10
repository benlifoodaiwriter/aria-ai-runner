/**
 * SEO roadmap — the "What should I do today?" page.
 *
 * Nothing is analyzed until the owner presses "Analyze". Then SEOlity reads Search
 * Console, the audit, content and competitors, studies the SEO knowledge base and
 * writes a stable, versioned roadmap. Every task opens a guide with the affected pages,
 * one-click AI actions and exact dashboard steps.
 */
( function ( w, d ) {
	'use strict';
	var SL = w.SL;
	var App = SL.app;
	var t = SL.t;
	var h = SL.h;

	var PHASES = [
		[ 'now', function () {
			return t( 'This week' );
		}, 'zap' ],
		[ 'week', function () {
			return t( 'Next 2 weeks' );
		}, 'calendar' ],
		[ 'month', function () {
			return t( 'This month' );
		}, 'layers' ],
		[ 'later', function () {
			return t( 'Later' );
		}, 'clock' ],
	];
	var CATS = { indexing: [ 'search', t( 'Indexing' ) ], technical: [ 'wrench', t( 'Technical' ) ], content: [ 'content', t( 'Content' ) ], ctr: [ 'target', t( 'CTR' ) ], 'on-page': [ 'type', t( 'On-page' ) ], links: [ 'link', t( 'Links' ) ], products: [ 'bag', t( 'Products' ) ], images: [ 'image', t( 'Images' ) ], schema: [ 'braces', t( 'Schema' ) ], competitors: [ 'swords', t( 'Competitors' ) ], strategy: [ 'sparkles', t( 'Strategy' ) ] };
	var STAGES = [
		[ 'queued', t( 'Waiting to start…' ) ],
		[ 'recommendations', t( 'Reading Search Console and audit data' ) ],
		[ 'diagnose', t( 'Diagnosing every issue on the site' ) ],
		[ 'facts', t( 'Collecting new content, competitors and trends' ) ],
		[ 'ai', t( 'Studying the SEO knowledge base and writing the roadmap' ) ],
	];
	var PRIO = { critical: t( 'Critical' ), high: t( 'High' ), medium: t( 'Medium' ), low: t( 'Low' ) };

	function cat( c ) {
		var x = CATS[ c ] || [ 'info', c || t( 'Other' ) ];
		return h( 'span', { class: 'sl-rm-cat' }, SL.icon( x[ 0 ], 13 ), h( 'span', { text: x[ 1 ] } ) );
	}

	App.pages.actions = function ( v ) {
		var head = App.header( t( 'SEO roadmap — what should I do today?' ), t( 'A step-by-step plan for this site. SEOlity analyzes only when you press “Analyze”: it reads Search Console, the site audit, your content and competitors, studies the SEO knowledge base and keeps the plan stable between analyses.' ) );
		v.appendChild( head );
		var box = h( 'div', { class: 'sl-stack sl-rm' } );
		v.appendChild( box );
		var timer = null;
		var filter = { phase: 'all', cat: '', done: false };

		function analyzeBtn( label, kind ) {
			return App.cap( 'ai' ) ? App.abtn( h( 'span', { class: 'sl-row' }, SL.icon( 'refresh', 16 ), h( 'span', { text: label } ) ), function () {
				return SL.api( 'roadmap/analyze', { method: 'POST' } ).then( load );
			}, kind || 'primary' ) : null;
		}

		function load() {
			clearTimeout( timer );
			SL.api( 'roadmap' ).then( render ).catch( function ( e ) {
				SL.clear( box );
				box.appendChild( h( 'div', { class: 'sl-alert sl-alert--error', text: e.message } ) );
			} );
		}

		function render( st ) {
			if ( ! d.body.contains( box ) ) {
				return; // Page left.
			}
			SL.clear( box );
			var run = st.running;
			if ( run && run.status === 'running' ) {
				box.appendChild( progress( run ) );
				timer = setTimeout( load, 3000 );
			} else if ( run && run.status === 'failed' ) {
				box.appendChild( h( 'div', { class: 'sl-alert sl-alert--error' }, h( 'strong', { text: t( 'The last analysis failed:' ) + ' ' } ), run.error || '', ' ', analyzeBtn( t( 'Try again' ), 'sm' ) ) );
			}
			if ( ! st.roadmap ) {
				if ( ! run || run.status !== 'running' ) {
					box.appendChild( intro( st ) );
				}
				return;
			}
			box.appendChild( summary( st ) );
			box.appendChild( board( st.roadmap ) );
		}

		function intro( st ) {
			var checks = [
				[ st.ai_ready, t( 'AI provider connected' ), t( 'Without AI the roadmap is still built from your data and the built-in guides.' ), 'settings?tab=ai' ],
				[ st.gsc, t( 'Search Console connected' ), t( 'Needed for indexing, CTR, ranking and traffic tasks.' ), 'gsc' ],
				[ st.audit, t( 'Site audit done' ), t( 'Needed for technical and on-page tasks.' ), 'audit' ],
			];
			return App.card( t( 'Build your SEO roadmap' ), h( 'div', { class: 'sl-stack' },
				h( 'p', { text: t( 'SEOlity will read all your data once, study the SEO knowledge base and write a prioritized plan — from new articles that still need indexing to technical fixes, content, links and products. Every task comes with the exact pages and a step-by-step guide in your dashboard.' ) } ),
				h( 'ul', { class: 'sl-checks' }, checks.map( function ( c ) {
					return h( 'li', { class: 'sl-checks__row' + ( c[ 0 ] ? '' : ' is-warn' ) }, SL.icon( c[ 0 ] ? 'check-circle' : 'alert', 18 ), h( 'span', { class: 'sl-checks__text' }, h( 'b', { text: c[ 1 ] } ), h( 'small', { text: c[ 2 ] } ) ), c[ 0 ] ? null : App.link( t( 'Set up' ), c[ 3 ] ) );
				} ) ),
				h( 'div', null, analyzeBtn( t( 'Analyze my site' ) ) )
			), { icon: 'map' } );
		}

		function progress( run ) {
			var idx = 0;
			STAGES.forEach( function ( s, i ) {
				if ( s[ 0 ] === run.stage ) {
					idx = i;
				}
			} );
			if ( run.stage === 'waiting_ai' ) {
				idx = STAGES.length - 1;
			}
			var secs = Math.max( 0, Math.round( ( Date.now() - new Date( run.started_at.replace( ' ', 'T' ) + 'Z' ).getTime() ) / 1000 ) );
			return App.card( t( 'Analyzing your site…' ), h( 'div', { class: 'sl-stack' },
				h( 'ol', { class: 'sl-steps' }, STAGES.map( function ( s, i ) {
					return h( 'li', { class: i < idx ? 'is-done' : i === idx ? 'is-active' : '', text: s[ 1 ] } );
				} ) ),
				run.stage === 'waiting_ai' ? h( 'p', { class: 'sl-muted', text: t( 'The AI is answering through GitHub — this page updates by itself.' ) } ) : null,
				h( 'p', { class: 'sl-muted', text: t( 'Elapsed: %s s · usually 1–3 minutes. You can leave this page; the analysis continues.', secs ) } )
			), { icon: 'activity' } );
		}

		function summary( st ) {
			var rm = st.roadmap;
			var s = rm.summary || {};
			var ch = rm.changes || {};
			var c = s.counts || {};
			var done = rm.items.filter( function ( i ) {
				return i.status === 'done';
			} ).length;
			var pct = rm.items.length ? Math.round( 100 * done / rm.items.length ) : 0;
			var changes = null;
			if ( ch && ! ch.first && ( ( ch.added || [] ).length || ( ch.resolved || [] ).length || ( ch.priority || [] ).length ) ) {
				changes = h( 'details', { class: 'sl-explain' }, h( 'summary', { text: t( 'What changed since the previous analysis' ) } ), h( 'div', { class: 'sl-explain__body' },
					s.changes_note ? h( 'p', { text: s.changes_note } ) : null,
					( ch.added || [] ).length ? h( 'p', null, h( 'b', { text: t( 'New tasks:' ) + ' ' } ), ch.added.map( function ( x ) {
						return x.title;
					} ).join( ' · ' ) ) : null,
					( ch.resolved || [] ).length ? h( 'p', null, h( 'b', { text: t( 'Solved / gone:' ) + ' ' } ), ch.resolved.map( function ( x ) {
						return x.title;
					} ).join( ' · ' ) ) : null,
					( ch.priority || [] ).length ? h( 'ul', null, ch.priority.map( function ( x ) {
						return h( 'li', { text: x.title + ': ' + ( PRIO[ x.from ] || x.from ) + ' → ' + ( PRIO[ x.to ] || x.to ) + ( x.reason ? ' — ' + x.reason : '' ) } );
					} ) ) : null
				) );
			} else if ( ch && ! ch.first ) {
				changes = h( 'p', { class: 'sl-muted', text: s.changes_note || t( 'No important changes since the previous analysis — the plan stays the same.' ) } );
			}
			var list = function ( title, arr, icon ) {
				return arr && arr.length ? h( 'div', { class: 'sl-rm-list' }, h( 'h4', null, SL.icon( icon, 16 ), h( 'span', { text: title } ) ), h( 'ul', null, arr.map( function ( x ) {
					return h( 'li', { text: x } );
				} ) ) ) : null;
			};
			var body = h( 'div', { class: 'sl-stack' },
				h( 'div', { class: 'sl-rm-diag' }, SL.markdown( s.diagnosis || '' ) ),
				s.focus && s.focus.length ? h( 'div', { class: 'sl-rm-focus' }, h( 'h4', null, SL.icon( 'target', 16 ), h( 'span', { text: t( 'Focus this week' ) } ) ), h( 'ol', null, s.focus.map( function ( x ) {
					return h( 'li', { text: x } );
				} ) ) ) : null,
				h( 'div', { class: 'sl-grid-2 sl-grid-2--top' }, list( t( 'Strengths' ), s.strengths, 'check-circle' ), list( t( 'Risks' ), s.risks, 'alert' ) ),
				h( 'div', { class: 'sl-rm-progress' }, h( 'div', { class: 'sl-rm-progress__bar' }, h( 'span', { style: 'width:' + pct + '%' } ) ), h( 'span', { text: t( '%1$s of %2$s tasks done', done, rm.items.length ) } ) ),
				changes,
				h( 'p', { class: 'sl-muted sl-small', text: [ t( 'Version %s', rm.version ), SL.date( rm.finished_at.replace( ' ', 'T' ) + 'Z' ), s.ai ? t( 'AI: %s', s.model || '' ) : t( 'Built without AI' ), ( s.knowledge || [] ).length ? t( 'Knowledge: %s', s.knowledge.join( ' · ' ) ) : '' ].filter( Boolean ).join( ' · ' ) } ),
				rm.meta && rm.meta.ai_error ? h( 'div', { class: 'sl-alert sl-alert--warn', text: rm.meta.ai_error } ) : null
			);
			return App.card( t( 'Diagnosis' ), body, { icon: 'brain', sub: t( '%1$s tasks · %2$s critical · %3$s high', c.total || 0, c.critical || 0, c.high || 0 ), actions: analyzeBtn( t( 'Re-analyze' ) ) } );
		}

		function board( rm ) {
			var wrap = h( 'div', { class: 'sl-stack' } );
			var cats = {};
			rm.items.forEach( function ( i ) {
				cats[ i.category ] = ( cats[ i.category ] || 0 ) + 1;
			} );
			var tabs = h( 'div', { class: 'sl-subtabs sl-rm-tabs' }, [ [ 'all', function () {
				return t( 'All' );
			}, 'list' ] ].concat( PHASES ).map( function ( p ) {
				var n = rm.items.filter( function ( i ) {
					return ( p[ 0 ] === 'all' || i.phase === p[ 0 ] ) && ( filter.done || i.status !== 'done' ) && i.status !== 'dismissed';
				} ).length;
				return h( 'a', { href: '#', class: 'sl-subtab' + ( filter.phase === p[ 0 ] ? ' is-active' : '' ), onClick: function ( e ) {
					e.preventDefault();
					filter.phase = p[ 0 ];
					redraw();
				} }, SL.icon( p[ 2 ], 15 ), h( 'span', { text: p[ 1 ]() + ' (' + n + ')' } ) );
			} ) );
			var chips = h( 'div', { class: 'sl-chips' }, [ h( 'button', { type: 'button', class: 'sl-chip sl-chip--btn' + ( filter.cat ? '' : ' is-on' ), onClick: function () {
				filter.cat = '';
				redraw();
			} }, t( 'All categories' ) ) ].concat( Object.keys( cats ).map( function ( k ) {
				return h( 'button', { type: 'button', class: 'sl-chip sl-chip--btn' + ( filter.cat === k ? ' is-on' : '' ), onClick: function () {
					filter.cat = filter.cat === k ? '' : k;
					redraw();
				} }, cat( k ), ' ' + cats[ k ] );
			} ) ), h( 'label', { class: 'sl-check sl-rm-showdone' }, h( 'input', { type: 'checkbox', checked: filter.done, onChange: function ( e ) {
				filter.done = e.target.checked;
				redraw();
			} } ), h( 'span', { text: t( 'Show done tasks' ) } ) ) );
			var list = h( 'div', { class: 'sl-stack' } );
			function redraw() {
				var parent = wrap.parentNode;
				var fresh = board( rm );
				parent.replaceChild( fresh, wrap );
			}
			PHASES.forEach( function ( p ) {
				if ( filter.phase !== 'all' && filter.phase !== p[ 0 ] ) {
					return;
				}
				var items = rm.items.filter( function ( i ) {
					return i.phase === p[ 0 ] && i.status !== 'dismissed' && ( filter.done || i.status !== 'done' ) && ( ! filter.cat || i.category === filter.cat );
				} );
				if ( ! items.length ) {
					return;
				}
				list.appendChild( h( 'h3', { class: 'sl-rm-phase' }, SL.icon( p[ 2 ], 18 ), h( 'span', { text: p[ 1 ]() } ), h( 'em', { text: String( items.length ) } ) ) );
				items.forEach( function ( it ) {
					list.appendChild( row( it ) );
				} );
			} );
			if ( ! list.children.length ) {
				list.appendChild( SL.empty( t( 'Nothing here' ), t( 'All tasks in this view are done. Press “Re-analyze” after your changes have been live for a while.' ) ) );
			}
			wrap.appendChild( tabs );
			wrap.appendChild( chips );
			wrap.appendChild( list );
			return wrap;
		}

		function setStatus( it, status ) {
			return SL.api( 'roadmap/task', { body: { key: it.key, status: status } } ).then( function () {
				it.status = status;
				load();
			} ).catch( SL.fail );
		}

		function row( it ) {
			var cb = h( 'input', { type: 'checkbox', checked: it.status === 'done', 'aria-label': t( 'Mark as done' ), onChange: function ( e ) {
				setStatus( it, e.target.checked ? 'done' : 'todo' );
			} } );
			return h( 'div', { class: 'sl-rm-item sl-rm-item--' + it.priority + ( it.status === 'done' ? ' is-done' : '' ) },
				App.cap( 'apply' ) ? h( 'label', { class: 'sl-rm-item__check' }, cb ) : null,
				h( 'button', { type: 'button', class: 'sl-rm-item__main', onClick: function () {
					guide( it, load );
				} },
					h( 'span', { class: 'sl-rm-item__title' }, SL.dot( it.priority ), h( 'b', { text: it.title } ) ),
					h( 'span', { class: 'sl-rm-item__meta' }, cat( it.category ), SL.priorityBadge( it.priority ), it.count ? SL.badge( t( '%s pages', it.count ) ) : null, it.source === 'ai' ? SL.badge( t( 'AI strategy' ), 'brand' ) : null, it.is_new ? SL.badge( t( 'New' ), 'brand' ) : null, it.reappeared ? SL.badge( t( 'Came back' ), 'warn' ) : null, it.status === 'doing' ? SL.badge( t( 'In progress' ), 'warn' ) : null, it.previous && it.previous.priority !== it.priority ? SL.badge( t( 'Priority changed' ), 'warn' ) : null ),
					h( 'span', { class: 'sl-rm-item__why', text: it.analysis || it.why } )
				),
				h( 'div', { class: 'sl-rm-item__go' }, App.btn( h( 'span', { class: 'sl-row' }, SL.icon( 'book', 15 ), h( 'span', { text: t( 'Guide' ) } ) ), function () {
					guide( it, load );
				}, 'sm' ) )
			);
		}

		load();
	};

	/* ------------------------------------------------------------------ Task guide popup */
	function targetActions( it, tg, refresh ) {
		var acts = [];
		if ( tg.edit ) {
			acts.push( h( 'a', { class: 'sl-btn sl-btn--sm', href: tg.edit, target: '_blank', rel: 'noopener' }, SL.icon( 'edit', 14 ), ' ', t( 'Edit' ) ) );
		}
		if ( tg.url ) {
			acts.push( h( 'a', { class: 'sl-btn sl-btn--sm sl-btn--ghost', href: tg.url, target: '_blank', rel: 'noopener', title: t( 'View' ), 'aria-label': t( 'View' ) }, SL.icon( 'external', 14 ) ) );
		}
		if ( tg.inspect ) {
			acts.push( h( 'a', { class: 'sl-btn sl-btn--sm', href: tg.inspect, target: '_blank', rel: 'noopener' }, SL.icon( 'search', 14 ), ' ', t( 'Inspect in Search Console' ) ) );
		}
		var actions = ( it.guide || [] ).map( function ( s ) {
			return s.action;
		} );
		if ( App.cap( 'ai' ) && actions.indexOf( 'meta_targets' ) !== -1 && tg.object_id ) {
			acts.push( App.btn( h( 'span', { class: 'sl-row' }, SL.icon( 'sparkles', 14 ), h( 'span', { text: t( 'AI title & description' ) } ) ), function () {
				SL.metaModal( tg.object_type, tg.object_id, refresh, tg.label );
			}, 'primary sl-btn--sm' ) );
		} else if ( App.cap( 'ai' ) && actions.indexOf( 'ai_targets' ) !== -1 && tg.rec_id ) {
			acts.push( App.abtn( h( 'span', { class: 'sl-row' }, SL.icon( 'sparkles', 14 ), h( 'span', { text: t( 'Do it with AI' ) } ) ), function () {
				return SL.api( 'recommendations/' + tg.rec_id + '/execute', { method: 'POST' } ).then( function ( res ) {
					App.handleResult( res, refresh );
				} );
			}, 'primary sl-btn--sm' ) );
		} else if ( App.cap( 'ai' ) && actions.indexOf( 'ai_targets' ) !== -1 && tg.object_type === 'post' && tg.object_id ) {
			acts.push( App.btn( h( 'span', { class: 'sl-row' }, SL.icon( 'sparkles', 14 ), h( 'span', { text: t( 'Optimize with AI' ) } ) ), function () {
				SL.optimizer( { object_type: 'post', object_id: tg.object_id, field: 'post_content', label: tg.label } );
			}, 'primary sl-btn--sm' ) );
		}
		return acts;
	}

	function guide( it, refresh ) {
		var m = SL.modal( { title: it.title, wide: true } );
		var b = m.body;
		b.classList.add( 'sl-rm-guide' );
		b.appendChild( h( 'div', { class: 'sl-row' }, cat( it.category ), SL.priorityBadge( it.priority ), SL.badge( t( 'Impact' ) + ' ' + it.impact ), SL.badge( t( 'Effort' ) + ' ' + it.effort ), it.change_reason ? SL.badge( t( 'Changed' ), 'warn' ) : null ) );
		b.appendChild( h( 'section', null, h( 'h3', null, SL.icon( 'info', 17 ), h( 'span', { text: t( 'Why this matters' ) } ) ), h( 'p', { text: it.why } ), it.analysis && it.analysis !== it.why ? h( 'p', { class: 'sl-rm-ai' }, SL.icon( 'brain', 15 ), ' ', it.analysis ) : null, it.expected_result ? h( 'p', null, h( 'b', { text: t( 'Expected result:' ) + ' ' } ), it.expected_result ) : null, it.change_reason ? h( 'p', { class: 'sl-muted', text: t( 'Changed since the last analysis:' ) + ' ' + it.change_reason } ) : null ) );

		if ( it.targets && it.targets.length ) {
			var rows = it.targets.map( function ( tg ) {
				return h( 'tr', null,
					h( 'td', null, h( 'b', { dir: 'auto', text: tg.label || tg.url } ), tg.metric ? h( 'div', { class: 'sl-muted sl-small', dir: 'auto', text: tg.metric } ) : null, tg.problems && tg.problems.length ? h( 'div', { class: 'sl-rm-problems' }, tg.problems.map( function ( p ) {
						return SL.badge( p, 'warn' );
					} ) ) : null ),
					h( 'td', null, h( 'div', { class: 'sl-row' }, targetActions( it, tg, refresh ) ) )
				);
			} );
			b.appendChild( h( 'section', null, h( 'h3', null, SL.icon( 'list', 17 ), h( 'span', { text: t( 'Affected pages (%s)', it.targets.length ) } ) ), h( 'div', { class: 'sl-table-wrap' }, h( 'table', { class: 'sl-table' }, h( 'tbody', null, rows ) ) ) ) );
		}
		if ( it.plan && it.plan.length ) {
			b.appendChild( h( 'section', null, h( 'h3', null, SL.icon( 'brain', 17 ), h( 'span', { text: t( 'Plan for your site (AI)' ) } ) ), steps( it.plan ) ) );
		}
		if ( it.guide && it.guide.length ) {
			b.appendChild( h( 'section', null, h( 'h3', null, SL.icon( 'map', 17 ), h( 'span', { text: t( 'Step by step in your dashboard' ) } ) ), steps( it.guide ) ) );
		}
		if ( it.done_when ) {
			b.appendChild( h( 'div', { class: 'sl-alert sl-alert--success' }, SL.icon( 'check-circle', 16 ), ' ', h( 'b', { text: t( 'Done when:' ) + ' ' } ), it.done_when ) );
		}
		if ( App.cap( 'apply' ) ) {
			var set = function ( status ) {
				return SL.api( 'roadmap/task', { body: { key: it.key, status: status } } ).then( function () {
					m.close();
					refresh();
				} ).catch( SL.fail );
			};
			m.foot.appendChild( App.btn( t( 'Not relevant' ), function () {
				set( 'dismissed' );
			}, 'ghost' ) );
			m.foot.appendChild( App.btn( t( 'Working on it' ), function () {
				set( 'doing' );
			} ) );
			m.foot.appendChild( App.btn( h( 'span', { class: 'sl-row' }, SL.icon( 'check', 15 ), h( 'span', { text: t( 'Mark as done' ) } ) ), function () {
				set( 'done' );
			}, 'primary' ) );
		}
	}

	function steps( list ) {
		return h( 'ol', { class: 'sl-rm-steps' }, list.map( function ( s, i ) {
			return h( 'li', null,
				h( 'span', { class: 'sl-rm-steps__n', text: String( i + 1 ) } ),
				h( 'div', null,
					h( 'b', { text: s.title } ),
					s.detail ? h( 'p', { text: s.detail } ) : null,
					s.where || s.link ? h( 'div', { class: 'sl-row' }, s.where ? h( 'span', { class: 'sl-rm-where' }, SL.icon( 'map', 13 ), h( 'span', { text: s.where } ) ) : null, s.link ? h( 'a', { class: 'sl-btn sl-btn--sm', href: s.link, target: s.link.indexOf( 'http' ) === 0 && s.link.indexOf( location.host ) === -1 ? '_blank' : null, rel: 'noopener' }, t( 'Open' ), ' ', SL.icon( 'arrow-' + ( SL.config.rtl ? 'left' : 'right' ), 13 ) ) : null ) : null
				)
			);
		} ) );
	}
} )( window, document );
