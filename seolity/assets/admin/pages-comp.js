/**
 * Competitor workspace: real Google results per tracked query (stored as history), one panel per
 * competitor with every query where it is above or below us, an estimated Search Console, and a
 * tab per analyzed query explaining why they rank where they do and what to do.
 */
( function ( w, d ) {
	'use strict';
	var SL = w.SL;
	var App = SL.app;
	var t = SL.t;
	var h = SL.h;

	var IMPACT = { high: [ t( 'High' ), 'bad' ], medium: [ t( 'Medium' ), 'warn' ], low: [ t( 'Low' ), '' ] };
	var PRIO = { critical: [ t( 'Critical' ), 'bad' ], high: [ t( 'High' ), 'warn' ], medium: [ t( 'Medium' ), 'brand' ], low: [ t( 'Low' ), '' ] };

	function pos( p ) {
		return null === p || undefined === p ? '—' : SL.num( p, 0 );
	}

	function trend( now, before ) {
		if ( null === before || undefined === before || now === before ) {
			return null;
		}
		var up = now < before;
		return h( 'span', { class: up ? 'is-up' : 'is-down', title: t( 'Previous check: %s', before ), text: ( up ? '▲' : '▼' ) + Math.abs( before - now ) } );
	}

	function link( url ) {
		return url ? h( 'a', { href: url, target: '_blank', rel: 'noopener', dir: 'ltr', class: 'sl-small sl-clip', title: url, text: decodeURI( SL.path( url ) ) } ) : '—';
	}

	/** Google tracking card: budget, tracked queries, check now, suggested competitors. */
	App.serpCard = function ( box, onChange ) {
		App.load( box, SL.api( 'serp/tracking' ), function ( r ) {
			var b = r.budget;
			var body = h( 'div', { class: 'sl-stack' } );
			var sourceLabel = h( 'b', { text: '…' } );
			SL.api( 'serp/sources' ).then( function ( src ) {
				var c = src.sources[ src.source ] || {};
				sourceLabel.textContent = c.label || src.source;
				if ( 'manual' === src.source ) {
					body.appendChild( bookmarklet() );
				}
			} ).catch( function () {} );
			body.appendChild( h( 'p', { class: 'sl-muted', text: t( 'SEOlity searches your tracked queries in Google through the SERP API — location-specific and not personalized, like an incognito search — and stores every result page. All competitor panels are built from this database; each new check adds history.' ) } ) );
			if ( ! b.available ) {
				body.appendChild( h( 'div', { class: 'sl-alert sl-alert--info', text: t( 'Automatic Google checks need a results source. Choose one with “Change source” — several are free, and “Your own browser” needs no key or account at all.' ) } ) );
			}
			body.appendChild( h( 'div', { class: 'sl-row' }, h( 'span', { class: 'sl-muted', text: t( 'Source of Google results:' ) } ), sourceLabel, App.cap( 'manage' ) ? App.btn( t( 'Change source' ), function () {
				App.serpSources( function () {
					App.serpCard( box, onChange );
				} );
			}, 'sm' ) : null ) );
			body.appendChild( h( 'div', { class: 'sl-kpis' },
				App.kpi( t( 'Tracked queries' ), SL.num( r.queries.length, 0 ) ),
				App.kpi( t( 'Searches this month' ), SL.num( b.used, 0 ) + ( b.limit ? ' / ' + SL.num( b.limit, 0 ) : '' ) ),
				App.kpi( t( 'Last check' ), r.last_track ? SL.date( r.last_track.at ) : '—' )
			) );
			if ( r.last_track && r.last_track.errors && r.last_track.errors.length ) {
				body.appendChild( h( 'div', { class: 'sl-alert sl-alert--error', text: r.last_track.errors.join( ' · ' ) } ) );
			}
			var add = h( 'input', { class: 'sl-input', dir: 'auto', placeholder: t( 'Add queries to track, separated by commas' ) } );
			body.appendChild( h( 'div', { class: 'sl-toolbar' }, add, App.abtn( t( 'Add' ), function () {
				var qs = add.value.split( /[,،\n]/ ).map( function ( x ) {
					return x.trim();
				} ).filter( Boolean );
				return SL.api( 'serp/queries', { body: { queries: qs } } ).then( function () {
					App.serpCard( box, onChange );
				} );
			} ), App.cap( 'manage' ) && b.available ? App.abtn( SL.il( 'search', r.tracking ? t( 'Checking Google…' ) : t( 'Check Google now' ), 14 ), function () {
				return SL.api( 'serp/track', { method: 'POST' } ).then( function () {
					SL.toast( t( 'Google is being checked for the tracked queries in the background.' ) );
				} );
			}, 'primary', { disabled: r.tracking } ) : null ) );
			body.appendChild( h( 'details', null, h( 'summary', { text: t( 'Tracked queries (%s)', r.queries.length ) } ), h( 'div', { class: 'sl-chips' }, r.queries.map( function ( q ) {
				var own = r.own.indexOf( q ) >= 0;
				return h( 'span', { class: 'sl-chip', dir: 'auto' }, q, own ? h( 'button', { type: 'button', class: 'sl-chip__x', 'aria-label': t( 'Remove' ), text: '×', onClick: function () {
					SL.api( 'serp/queries', { body: { remove: q } } ).then( function () {
						App.serpCard( box, onChange );
					} );
				} } ) : null );
			} ) ), h( 'p', { class: 'sl-muted sl-small', text: t( 'Your own queries plus your most important Search Console queries. Each query uses one search per check (at most once a day).' ) } ) ) );
			if ( r.suggestions && r.suggestions.length ) {
				body.appendChild( h( 'h4', { text: t( 'Who you meet most in Google' ) } ) );
				var rivals = h( 'div', { class: 'sl-mapcards' } );
				r.suggestions.forEach( function ( x ) {
					var share = Math.round( 100 * x.queries / Math.max( 1, x.of ) );
					rivals.appendChild( h( 'article', { class: 'sl-mapcard sl-rival', style: '--c:' + ( x.avg_position <= 3 ? '#dc2626' : x.avg_position <= 10 ? '#f59e0b' : '#2563eb' ) },
						h( 'div', { class: 'sl-mapcard__head' },
							h( 'span', { class: 'sl-mapcard__ic', 'aria-hidden': 'true' }, SL.icon( 'globe', 22 ) ),
							h( 'div', { class: 'sl-mapcard__titles' },
								h( 'a', { class: 'sl-mapcard__title', href: 'https://' + x.domain, target: '_blank', rel: 'noopener', dir: 'ltr', text: x.domain } ),
								h( 'span', { class: 'sl-muted sl-small', text: t( '%1$s of %2$s queries', x.queries, x.of ) } )
							),
							App.cap( 'manage' ) ? App.abtn( t( 'Add as competitor' ), function () {
								return SL.api( 'competitors', { body: { name: x.domain, domain: x.domain, frequency: 'weekly' } } ).then( function () {
									SL.toast( t( 'Competitor added. First analysis is running in the background.' ), 'success' );
									if ( onChange ) {
										onChange();
									}
								} );
							}, 'primary' ) : null
						),
						h( 'div', { class: 'sl-rival__bar' }, h( 'span', { style: 'width:' + share + '%' } ) ),
						h( 'div', { class: 'sl-mapcard__metrics sl-rival__metrics' },
							h( 'div', { class: 'sl-mapcard__m' }, h( 'b', { text: SL.num( share, 0 ) + '%' } ), h( 'span', { text: t( 'Appears in' ) } ) ),
							h( 'div', { class: 'sl-mapcard__m' }, h( 'b', { text: SL.num( x.avg_position ) } ), h( 'span', { text: t( 'Avg. position' ) } ) )
						)
					) );
				} );
				body.appendChild( rivals );
			}
			box.appendChild( App.card( t( 'Google results database' ), body, { icon: 'search' } ) );
			var aiBox = h( 'div' );
			box.appendChild( aiBox );
			App.aiVisibilityCard( aiBox );
		} );
	};

	/** Bookmarklet: sends the Google results page the user opened to this site (no key, no account). */
	function bookmarklet() {
		var target = location.origin + location.pathname + '?page=seolity';
		var code = "javascript:(()=>{const S=" + JSON.stringify( target ) + ";const q=new URLSearchParams(location.search).get('q')||(document.querySelector('[name=q]')||{}).value||'';const seen=new Set(),res=[];document.querySelectorAll('a h3').forEach(h=>{const a=h.closest('a');if(!a)return;let u=a.href;try{const x=new URL(u);if(x.pathname==='/url')u=x.searchParams.get('q')||x.searchParams.get('url')||u;if(/(^|\.)google\./.test(new URL(u).hostname))return;}catch(e){return}if(!/^https?:/.test(u)||seen.has(u))return;seen.add(u);const b=a.closest('[data-hveid]')||a.parentElement;res.push({title:h.innerText,url:u,snippet:b?(b.innerText||'').replace(h.innerText,'').trim().slice(0,300):''})});const qs=[...document.querySelectorAll('[data-q]')].map(e=>e.getAttribute('data-q')).slice(0,10);if(!res.length){alert('SEOlity: no results found on this page.');return}window.open(S+'#/serp-import?d='+encodeURIComponent(JSON.stringify({query:q,results:res.slice(0,50),questions:qs})),'_blank')})()";
		return h( 'div', { class: 'sl-alert sl-alert--info sl-stack' },
			h( 'b', { text: t( 'No-key source: your own browser' ) } ),
			h( 'ol', { class: 'sl-small' },
				h( 'li', { text: t( 'Drag this button to your browser’s bookmarks bar:' ) } ),
				h( 'li', { text: t( 'Open google.com in an incognito window and search a query (set the country/language you target).' ) } ),
				h( 'li', { text: t( 'Click the bookmark on the results page: the results open here and are saved with one click.' ) } )
			),
			h( 'div', {}, h( 'a', { class: 'sl-btn sl-btn--primary', href: code, onClick: function ( e ) {
				e.preventDefault();
				SL.toast( t( 'Drag the button to the bookmarks bar, then use it on a Google results page.' ) );
			}, text: t( 'SEOlity: send Google results' ) } ) )
		);
	}

	/** Choose where Google results come from (free and paid options, with what signing up needs). */
	App.serpSources = function ( onSaved ) {
		var m = SL.modal( { title: t( 'Source of Google results' ), wide: true } );
		App.load( m.body, SL.api( 'serp/sources' ), function ( r ) {
			var chosen = r.source;
			var inputs = {};
			var list = h( 'div', { class: 'sl-srcs' } );
			Object.keys( r.sources ).forEach( function ( id ) {
				var c = r.sources[ id ];
				var fields = h( 'div', { class: 'sl-form' } );
				c.fields.forEach( function ( f ) {
					var secret = /key|password/.test( f );
					inputs[ id + ':' + f ] = h( 'input', { class: 'sl-input', dir: 'ltr', type: secret ? 'password' : 'text', autocomplete: 'off', placeholder: c.values[ f ] ? t( 'saved — enter a new one to replace' ) : f } );
					if ( ! secret ) {
						inputs[ id + ':' + f ].value = c.values[ f ] || '';
					}
					fields.appendChild( App.field( f, inputs[ id + ':' + f ] ) );
				} );
				var radio = h( 'input', { type: 'radio', name: 'sl-src', checked: id === chosen, onChange: function () {
					chosen = id;
				} } );
				list.appendChild( h( 'label', { class: 'sl-src' + ( id === chosen ? ' is-on' : '' ) },
					h( 'div', { class: 'sl-row' }, radio, h( 'b', { text: c.label } ), c.ready ? SL.badge( t( 'Ready' ), 'good' ) : null ),
					h( 'div', { class: 'sl-chips' }, SL.badge( c.cost, 'brand' ), SL.badge( c.signup, '' ) ),
					h( 'p', { class: 'sl-small sl-muted', text: c.note } ),
					c.url ? h( 'a', { class: 'sl-small', href: c.url, target: '_blank', rel: 'noopener', text: t( 'Get the key' ) + ' ↗' } ) : null,
					fields
				) );
			} );
			m.body.appendChild( h( 'p', { class: 'sl-muted', text: t( 'Google forbids scraping its result pages from servers (and blocks it with CAPTCHAs), so SEOlity uses official or commercial results APIs — or your own browser, which needs nothing at all.' ) } ) );
			m.body.appendChild( list );
			m.body.appendChild( h( 'div', { class: 'sl-row' }, App.abtn( t( 'Save' ), function () {
				var values = {};
				Object.keys( inputs ).forEach( function ( k ) {
					var p2 = k.split( ':' );
					if ( p2[ 0 ] === chosen && inputs[ k ].value.trim() ) {
						values[ p2[ 1 ] ] = inputs[ k ].value.trim();
					}
				} );
				return SL.api( 'serp/sources', { body: { source: chosen, values: values } } ).then( function ( res ) {
					SL.toast( res.ready ? t( 'Saved — the source is ready.' ) : t( 'Saved, but the source still needs its key.' ), res.ready ? 'success' : 'warn' );
					m.close();
					if ( onSaved ) {
						onSaved();
					}
				} );
			}, 'primary' ) ) );
		} );
	};

	/** AI search visibility: are we cited by Gemini / Claude / ChatGPT / Perplexity for our queries? */
	App.aiVisibilityCard = function ( box ) {
		var timer;
		function load() {
			clearTimeout( timer );
			Promise.all( [ SL.api( 'serp/ai-visibility' ), SL.api( 'serp/sources' ) ] ).then( function ( res ) {
				var r = res[ 0 ];
				var src = res[ 1 ];
				SL.clear( box );
				var body = h( 'div', { class: 'sl-stack' } );
				body.appendChild( h( 'p', { class: 'sl-muted', text: t( 'AI answer engines search the web and cite sources. SEOlity asks them your tracked queries and records whether your site is cited, in which place, and which competitors are cited instead. Uses the keys already saved for your AI connections.' ) } ) );
				var on = {};
				var engines = h( 'div', { class: 'sl-srcs' } );
				var pkey = h( 'input', { class: 'sl-input', dir: 'ltr', type: 'password', placeholder: 'pplx-…' } );
				Object.keys( src.ai_engines ).forEach( function ( id ) {
					var e = src.ai_engines[ id ];
					on[ id ] = src.ai_enabled.indexOf( id ) >= 0;
					engines.appendChild( h( 'label', { class: 'sl-src' },
						h( 'div', { class: 'sl-row' }, h( 'input', { type: 'checkbox', checked: on[ id ], onChange: function ( ev ) {
							on[ id ] = ev.target.checked;
						} } ), h( 'b', { text: e.label } ), e.has_key ? SL.badge( t( 'Key saved' ), 'good' ) : SL.badge( t( 'No key yet' ), 'warn' ) ),
						h( 'p', { class: 'sl-small sl-muted', text: e.cost } ),
						'perplexity' === id && ! e.has_key ? pkey : null
					) );
				} );
				body.appendChild( engines );
				body.appendChild( h( 'div', { class: 'sl-row' },
					App.cap( 'manage' ) ? App.abtn( t( 'Save engines' ), function () {
						var list = Object.keys( on ).filter( function ( k ) {
							return on[ k ];
						} );
						return SL.api( 'serp/sources', { body: { source: src.source, values: {}, ai_engines: list, perplexity_api_key: pkey.value.trim() } } ).then( load );
					} ) : null,
					App.cap( 'ai' ) ? App.abtn( SL.il( 'sparkles', r.running ? t( 'Checking AI search…' ) : t( 'Check AI search now' ), 14 ), function () {
						return SL.api( 'serp/ai-visibility/check', { method: 'POST' } ).then( load );
					}, 'primary', { disabled: r.running } ) : null
				) );
				if ( r.last && r.last.errors && Object.keys( r.last.errors ).length ) {
					body.appendChild( h( 'div', { class: 'sl-alert sl-alert--error', text: Object.keys( r.last.errors ).map( function ( k ) {
						return r.last.errors[ k ];
					} ).join( ' · ' ) } ) );
				}
				if ( r.items.length ) {
					var names = {};
					Object.keys( src.ai_engines ).forEach( function ( id ) {
						names[ id ] = src.ai_engines[ id ].label;
					} );
					// One large card per query: every AI engine as a tile (cited where / who is cited instead).
					var vis = h( 'div', { class: 'sl-mapcards' } );
					r.items.forEach( function ( x ) {
						var ids = Object.keys( src.ai_engines ).filter( function ( id ) {
							return x.engines[ id ];
						} );
						var cited = ids.filter( function ( id ) {
							return x.engines[ id ].cited_at;
						} ).length;
						vis.appendChild( h( 'article', { class: 'sl-mapcard sl-aivis', style: '--c:' + ( cited ? '#16a34a' : '#dc2626' ) },
							h( 'div', { class: 'sl-mapcard__head' },
								h( 'span', { class: 'sl-mapcard__ic', 'aria-hidden': 'true' }, SL.icon( 'sparkles', 22 ) ),
								h( 'div', { class: 'sl-mapcard__titles' }, h( 'b', { class: 'sl-mapcard__title', dir: 'auto', text: x.query } ) ),
								h( 'span', { class: 'sl-mapcard__status' }, h( 'b', { text: t( '%1$s of %2$s', cited, ids.length ) } ), h( 'span', { text: t( 'AI engines cite you' ) } ) )
							),
							h( 'div', { class: 'sl-aivis__engines' }, ids.map( function ( id ) {
								var e = x.engines[ id ];
								return h( 'div', { class: 'sl-aivis__e' + ( e.cited_at ? ' is-cited' : '' ), title: e.answer },
									h( 'b', { text: names[ id ] } ),
									e.cited_at ? SL.badge( t( 'Cited #%s', e.cited_at ), 'good' ) : SL.badge( t( 'Not cited' ), 'bad' ),
									e.competitors.length ? h( 'span', { class: 'sl-small sl-muted', dir: 'ltr', text: e.competitors.map( function ( c ) {
										return c.domain + ' #' + c.position;
									} ).join( ', ' ) } ) : null
								);
							} ) )
						) );
					} );
					body.appendChild( vis );
				}
				box.appendChild( App.card( t( 'Visibility in AI search' ), body, { icon: 'sparkles' } ) );
				if ( r.running ) {
					timer = setTimeout( function () {
						if ( d.body.contains( box ) ) {
							load();
						}
					}, 6000 );
				}
			} ).catch( SL.fail );
		}
		load();
	};

	/** Receives the results the bookmarklet read from a Google results page. */
	App.pages[ 'serp-import' ] = function ( v, q ) {
		var data = null;
		try {
			data = JSON.parse( q.d || '' );
		} catch ( e ) {
			data = null;
		}
		v.appendChild( App.header( t( 'Import Google results' ), t( 'Results read by the SEOlity bookmark from the Google page you opened.' ) ) );
		if ( ! data || ! data.results || ! data.results.length ) {
			v.appendChild( SL.empty( t( 'Nothing to import' ), t( 'Open a Google results page and click the SEOlity bookmark.' ) ) );
			return;
		}
		v.appendChild( h( 'p', null, h( 'b', { text: t( 'Query:' ) + ' ' } ), h( 'span', { dir: 'auto', text: data.query } ) ) );
		v.appendChild( App.table( [
			{ label: '#', render: function ( x, i ) {
				return String( data.results.indexOf( x ) + 1 );
			} },
			{ label: t( 'Result' ), render: function ( x ) {
				return h( 'div', null, h( 'b', { dir: 'auto', text: x.title } ), link( x.url ) );
			} },
		], data.results ) );
		v.appendChild( h( 'div', { class: 'sl-row' }, App.abtn( t( 'Save to the Google results database' ), function () {
			return SL.api( 'serp/import', { body: data } ).then( function ( r ) {
				SL.toast( t( '%s results saved.', r.results ), 'success' );
				location.hash = '#/competitors';
			} );
		}, 'primary' ) ) );
	};

	function analysisView( a ) {
		var box = h( 'div', { class: 'sl-stack sl-cq' } );
		if ( 'running' === a.status ) {
			box.appendChild( h( 'div', { class: 'sl-alert sl-alert--info sl-row' }, SL.spinner(), h( 'span', { text: t( 'The AI is comparing the pages — this tab updates by itself.' ) } ) ) );
			return box;
		}
		if ( 'failed' === a.status ) {
			box.appendChild( h( 'div', { class: 'sl-alert sl-alert--error', text: a.error || t( 'Failed' ) } ) );
			return box;
		}
		var r = a.result || {};
		box.appendChild( h( 'div', { class: 'sl-card' },
			h( 'p', { class: 'sl-cq__verdict', dir: 'auto', text: r.verdict } ),
			h( 'div', { class: 'sl-row' }, SL.badge( r.can_overtake ? t( 'Can be overtaken' ) : t( 'Hard to overtake — match them first' ), r.can_overtake ? 'good' : 'warn' ), SL.badge( t( 'Confidence %s%', r.confidence ), '' ), h( 'span', { class: 'sl-muted sl-small', text: SL.date( a.created_at ) } ) ),
			h( 'div', { class: 'sl-cq__pages' },
				h( 'div', null, h( 'small', { text: t( 'Their page' ) } ), link( a.their_url ) ),
				h( 'div', null, h( 'small', { text: t( 'Our page Google shows' ) } ), link( a.our_url ) ),
				h( 'div', null, h( 'small', { text: t( 'Our page that should rank' ) } ), link( r.target_page ) )
			),
			r.page_choice ? h( 'p', { class: 'sl-small' }, h( 'b', { text: t( 'Why Google shows this page of ours:' ) + ' ' } ), h( 'span', { dir: 'auto', text: r.page_choice } ) ) : null
		) );
		box.appendChild( h( 'h4', { text: t( 'Why they rank where they do' ) } ) );
		box.appendChild( App.table( [
			{ label: t( 'Factor' ), render: function ( f ) {
				return h( 'b', { dir: 'auto', text: f.factor } );
			} },
			{ label: t( 'Them' ), render: function ( f ) {
				return h( 'span', { dir: 'auto', class: 'sl-small', text: f.them } );
			} },
			{ label: t( 'Us' ), render: function ( f ) {
				return h( 'span', { dir: 'auto', class: 'sl-small', text: f.us } );
			} },
			{ label: t( 'Impact' ), render: function ( f ) {
				var i = IMPACT[ f.impact ] || [ f.impact, '' ];
				return SL.badge( i[ 0 ], i[ 1 ] );
			} },
			{ label: t( 'Basis' ), render: function ( f ) {
				return SL.badge( 'measured' === f.evidence ? t( 'Measured' ) : t( 'Hypothesis' ), 'measured' === f.evidence ? 'good' : '' );
			} },
		], r.factors || [] ) );
		box.appendChild( h( 'h4', { text: t( 'What to do, step by step' ) } ) );
		box.appendChild( h( 'ol', { class: 'sl-cq__steps' }, ( r.steps || [] ).map( function ( s ) {
			var p = PRIO[ s.priority ] || [ s.priority, '' ];
			return h( 'li', null, h( 'div', { class: 'sl-row' }, SL.badge( p[ 0 ], p[ 1 ] ), SL.badge( t( 'Effort: %s', t( s.effort ) ), '' ), h( 'b', { dir: 'auto', text: s.step } ) ), s.page ? link( s.page ) : null, h( 'p', { class: 'sl-muted sl-small', dir: 'auto', text: s.why } ) );
		} ) ) );
		box.appendChild( h( 'p', { class: 'sl-muted sl-small' }, t( 'These steps are also in the SEO roadmap (Competitor gaps).' ), ' ', h( 'a', { href: '#/actions', text: t( 'Open the roadmap' ) } ) ) );
		return box;
	}

	App.pages.competitor = function ( v, q ) {
		var id = parseInt( ( q && q.id ) || 0, 10 );
		var focus = q && q.q ? decodeURIComponent( q.q ) : '';
		var body = h( 'div' );
		var timer;
		v.appendChild( body );
		function load() {
			clearTimeout( timer );
			App.load( body, SL.api( 'competitors/' + id + '/intel' ), function ( r ) {
				var c = r.competitor || {};
				var ov = r.overview;
				var s = ov.stats;
				body.appendChild( App.header( c.name || t( 'Competitor' ), c.domain || '', [
					h( 'a', { class: 'sl-btn', href: '#/competitors', text: t( 'All competitors' ) } ),
					App.cap( 'manage' ) && r.budget.available ? App.abtn( SL.il( 'search', t( 'Check Google now' ), 14 ), function () {
						return SL.api( 'serp/track', { method: 'POST' } ).then( function () {
							SL.toast( t( 'Google is being checked for the tracked queries in the background.' ) );
						} );
					}, 'primary' ) : null,
					App.cap( 'ai' ) ? App.abtn( t( 'Discover their queries' ), function () {
						return SL.api( 'competitors/' + id + '/discover', { method: 'POST' } ).then( load );
					} ) : null,
				] ) );
				body.appendChild( h( 'div', { class: 'sl-kpis' },
					App.kpi( t( 'Queries where they appear' ), SL.num( s.queries, 0 ) ),
					App.kpi( t( 'Above us' ), SL.num( s.above_us, 0 ) ),
					App.kpi( t( 'Below us' ), SL.num( s.below_us, 0 ) ),
					App.kpi( t( 'Only them (we are not in top 20)' ), SL.num( s.only_them, 0 ) ),
					App.kpi( t( 'Their estimated clicks / month' ), SL.num( s.est_clicks, 0 ) )
				) );
				var tabs = [
					{ label: t( 'Queries' ), render: function () {
						return queriesTab( r );
					} },
					{ label: t( 'Estimated Search Console' ), render: estimateTab },
					{ label: t( 'Their pages' ), render: pagesTab },
				];
				var initial = 0;
				( r.analyses || [] ).forEach( function ( a ) {
					if ( focus && a.query === focus ) {
						initial = tabs.length;
					}
					tabs.push( { label: h( 'span', { class: 'sl-cq__tab', dir: 'auto' }, 'running' === a.status ? SL.spinner() : null, a.query ), render: function () {
						return analysisView( a );
					} } );
				} );
				body.appendChild( SL.tabs( tabs, null, initial ) );
				if ( ( r.analyses || [] ).some( function ( a ) {
					return 'running' === a.status;
				} ) || r.tracking ) {
					timer = setTimeout( function () {
						if ( d.body.contains( body ) ) {
							load();
						}
					}, 6000 );
				}
			} );
		}
		function queriesTab( r ) {
			var box = h( 'div', { class: 'sl-stack' } );
			if ( ! r.overview.queries.length ) {
				box.appendChild( SL.empty( t( 'No Google data for this competitor yet' ), r.budget.available ? t( 'Press “Check Google now”. The competitor appears here for every tracked query where it is in the top 20.' ) : t( 'Add a SerpApi key in Settings → Crawler & audits to search Google.' ) ) );
			} else {
				var qcards = h( 'div', { class: 'sl-mapcards' } );
				r.overview.queries.forEach( function ( x ) {
					var bad = 'above_us' === x.status || 'only_them' === x.status;
					var act;
					if ( x.analysis && 'done' === x.analysis.status ) {
						act = h( 'a', { class: 'sl-btn sl-btn--primary', href: '#/competitor?id=' + id + '&q=' + encodeURIComponent( x.query ), onClick: function () {
							focus = x.query;
							setTimeout( load, 0 );
						}, text: t( 'Open analysis' ) } );
					} else if ( App.cap( 'ai' ) ) {
						act = App.abtn( SL.il( 'wand', t( 'Why? Analyze' ), 14 ), function () {
							return SL.api( 'competitors/' + id + '/compare', { body: { query: x.query } } ).then( function () {
								focus = x.query;
								load();
							} );
						}, 'primary' );
					}
					qcards.appendChild( h( 'article', { class: 'sl-mapcard', style: '--c:' + ( bad ? '#dc2626' : '#16a34a' ) },
						h( 'div', { class: 'sl-mapcard__head' },
							h( 'span', { class: 'sl-mapcard__ic', 'aria-hidden': 'true' }, SL.icon( bad ? 'trending-down' : 'trending', 22 ) ),
							h( 'div', { class: 'sl-mapcard__titles' }, h( 'b', { class: 'sl-mapcard__title', dir: 'auto', text: x.query } ) ),
							h( 'span', { class: 'sl-mapcard__status' }, h( 'b', { text: bad ? t( 'They rank above you' ) : t( 'You rank above them' ) } ) )
						),
						h( 'div', { class: 'sl-mapcard__metrics sl-cqm' },
							h( 'div', { class: 'sl-mapcard__m' + ( bad ? ' is-bad' : '' ) }, h( 'b', null, '#' + pos( x.their_position ), ' ', trend( x.their_position, x.their_previous ) ), h( 'span', { text: t( 'Them' ) } ), link( x.their_url ) ),
							h( 'div', { class: 'sl-mapcard__m' + ( bad ? '' : ' is-good' ) }, h( 'b', { text: x.our_position ? '#' + pos( x.our_position ) : t( 'not in top 20' ) } ), h( 'span', { text: t( 'Us' ) + ( x.our_gsc ? ' — ' + t( 'Search Console: %s', SL.num( x.our_gsc.position ) ) : '' ) } ), link( x.our_url ) ),
							h( 'div', { class: 'sl-mapcard__m' }, h( 'b', { text: null === x.their_est_clicks ? '—' : SL.num( x.their_est_clicks, 0 ) } ), h( 'span', { text: null === x.their_est_clicks ? t( 'no volume data' ) : t( 'Their est. clicks' ) } ) )
						),
						act ? h( 'div', { class: 'sl-mapcard__foot' }, h( 'span' ), h( 'div', { class: 'sl-mapcard__actions' }, act ) ) : null
					) );
				} );
				box.appendChild( qcards );
			}
			if ( r.candidates && r.candidates.length ) {
				var sel = {};
				box.appendChild( h( 'h4', { text: t( 'Queries their pages probably target (AI)' ) } ) );
				box.appendChild( h( 'div', { class: 'sl-chips' }, r.candidates.map( function ( c ) {
					return h( 'label', { class: 'sl-chip', dir: 'auto', title: c.page }, c.tracked ? h( 'span', { text: '✓ ' } ) : h( 'input', { type: 'checkbox', onChange: function ( e ) {
						sel[ c.query ] = e.target.checked;
					} } ), ' ' + c.query );
				} ) ) );
				box.appendChild( h( 'div', {}, App.abtn( t( 'Track selected and check Google' ), function () {
					var qs = Object.keys( sel ).filter( function ( k ) {
						return sel[ k ];
					} );
					return SL.api( 'serp/queries', { body: { queries: qs } } ).then( function () {
						return SL.api( 'serp/track', { body: { queries: qs } } );
					} ).then( function () {
						SL.toast( t( 'Google is being checked for the tracked queries in the background.' ) );
						load();
					} );
				}, 'primary' ) ) );
			}
			return box;
		}
		function estimateTab() {
			var box = h( 'div' );
			App.load( box, SL.api( 'competitors/' + id + '/estimate' ), function ( e ) {
				box.appendChild( h( 'div', { class: 'sl-alert sl-alert--info', text: e.method } ) );
				box.appendChild( h( 'div', { class: 'sl-kpis' }, App.kpi( t( 'Estimated clicks / month' ), SL.num( e.totals.est_clicks, 0 ) ), App.kpi( t( 'Queries found' ), SL.num( e.totals.queries, 0 ) ), App.kpi( t( 'With measured volume' ), SL.num( e.totals.with_volume, 0 ) ) ) );
				box.appendChild( h( 'h4', { text: t( 'Pages' ) } ) );
				box.appendChild( App.table( [
					{ label: t( 'Page' ), render: function ( p ) {
						return h( 'div', null, h( 'b', { dir: 'auto', text: p.title || '' } ), link( p.url ) );
					} },
					{ label: t( 'Queries' ), render: function ( p ) {
						return SL.chips( p.queries.map( function ( x ) {
							return x.query + ' #' + x.position;
						} ) );
					} },
					{ label: t( 'Est. impressions' ), render: function ( p ) {
						return SL.num( p.est_impressions, 0 );
					} },
					{ label: t( 'Est. clicks' ), render: function ( p ) {
						return SL.num( p.est_clicks, 0 );
					} },
				], e.pages ) );
				box.appendChild( h( 'h4', { text: t( 'Queries' ) } ) );
				box.appendChild( App.table( [
					{ label: t( 'Query' ), render: function ( x ) {
						return h( 'span', { dir: 'auto', text: x.query } );
					} },
					{ label: t( 'Position' ), render: function ( x ) {
						return pos( x.position );
					} },
					{ label: t( 'Est. impressions' ), render: function ( x ) {
						return null === x.est_impressions ? '—' : SL.num( x.est_impressions, 0 ) + ( 'minimum' === x.volume_kind ? '+' : '' );
					} },
					{ label: t( 'Est. clicks' ), render: function ( x ) {
						return null === x.est_clicks ? '—' : SL.num( x.est_clicks, 0 );
					} },
					{ label: t( 'Page' ), render: function ( x ) {
						return link( x.url );
					} },
				], e.queries ) );
			} );
			return box;
		}
		function pagesTab() {
			var box = h( 'div' );
			App.load( box, SL.api( 'competitors/' + id + '/pages' ), function ( r ) {
				var rows = r.items || r;
				box.appendChild( App.table( [
					{ label: t( 'Page' ), render: function ( p ) {
						return h( 'div', null, h( 'b', { dir: 'auto', text: p.title || '' } ), link( p.url ) );
					} },
					{ label: t( 'Words' ), render: function ( p ) {
						return SL.num( p.word_count, 0 );
					} },
					{ label: t( 'Schema' ), render: function ( p ) {
						return p.schema_types || '—';
					} },
					{ label: t( 'Updated' ), render: function ( p ) {
						return p.lastmod ? SL.date( p.lastmod ) : '—';
					} },
				], rows, { empty: t( 'No pages crawled yet' ), emptyText: t( 'Use “Analyze now” on the competitors page to read their sitemap and pages.' ) } ) );
			} );
			return box;
		}
		load();
	};
}( window, document ) );
