/**
 * Indexability & crawl budget: which URL groups are technically indexable vs. which should be,
 * with the reason, the right mechanism (noindex / robots.txt / sitemap / redirect) and a
 * verified fix through the operations engine.
 */
( function ( w, d ) {
	'use strict';
	var SL = w.SL;
	var App = SL.app;
	var t = SL.t;
	var h = SL.h;

	var SEV = { high: [ t( 'High' ), 'bad' ], medium: [ t( 'Medium' ), 'warn' ], low: [ t( 'Low' ), '' ] };

	function stateBadges( s ) {
		if ( ! s ) {
			return null;
		}
		var out = h( 'div', { class: 'sl-chips' } );
		out.appendChild( SL.badge( s.status ? 'HTTP ' + s.status : t( 'No response' ), 200 === s.status ? 'good' : ( s.status >= 300 && s.status < 400 ? '' : 'bad' ) ) );
		out.appendChild( SL.badge( s.noindex ? 'noindex' : 'index', s.noindex ? '' : 'brand' ) );
		if ( s.robots_txt_blocked ) {
			out.appendChild( SL.badge( t( 'Blocked in robots.txt' ), 'warn' ) );
		}
		if ( s.in_sitemap ) {
			out.appendChild( SL.badge( t( 'In sitemap' ), '' ) );
		}
		if ( s.canonical ) {
			out.appendChild( h( 'span', { class: 'sl-muted sl-small', dir: 'ltr', title: s.canonical, text: 'canonical ✓' } ) );
		}
		if ( s.google && s.google.coverage ) {
			out.appendChild( SL.badge( 'Google: ' + s.google.coverage, 'PASS' === s.google.verdict ? 'good' : 'warn' ) );
		}
		return out;
	}

	function explainer() {
		return h( 'details', { class: 'sl-card sl-explain' },
			h( 'summary', { text: t( 'noindex, robots.txt and the sitemap do different jobs' ) } ),
			h( 'ul', {},
				h( 'li', { text: t( 'noindex (meta robots or X-Robots-Tag): the page may be crawled but is kept out of Google. Use it for pages that must exist but should not rank.' ) } ),
				h( 'li', { text: t( 'robots.txt Disallow: Google does not crawl the URL at all, so it never sees a noindex there. Use it only for crawl budget, after the URLs are already out of the index.' ) } ),
				h( 'li', { text: t( 'XML sitemap: a list of pages you want indexed. Never list noindexed, redirected or blocked URLs.' ) } ),
				h( 'li', { text: t( '301 redirect: the page should not exist on its own (e.g. attachment pages) — visitors and Google go to the right page.' ) } )
			)
		);
	}

	App.pages.indexability = function ( v ) {
		var body = h( 'div' );
		var timer;
		v.appendChild( App.header( t( 'Indexability & crawl budget' ), t( 'Checks templates, attachments, archives, search, parameters, feeds and pagination: live status, robots meta, X-Robots-Tag, canonical, robots.txt, sitemap, internal links and Rank Math settings — intended vs. technical indexability.' ), App.cap( 'manage' ) ? [ App.abtn( SL.il( 'pulse', t( 'Run the audit' ), 15 ), function () {
			return SL.api( 'indexability/scan', { method: 'POST' } ).then( function () {
				SL.toast( t( 'Audit started in the background.' ) );
				load();
			} );
		}, 'primary' ) ] : null ) );
		v.appendChild( explainer() );
		v.appendChild( body );

		function load() {
			clearTimeout( timer );
			App.load( body, SL.api( 'indexability' ), function ( r ) {
				if ( r.running ) {
					body.appendChild( h( 'div', { class: 'sl-alert sl-alert--info sl-row' }, SL.spinner(), h( 'span', { text: t( 'The audit is running — results refresh automatically.' ) } ) ) );
					timer = setTimeout( function () {
						if ( d.body.contains( body ) ) {
							load();
						}
					}, 4000 );
				}
				var last = r.last;
				if ( ! last ) {
					if ( ! r.running ) {
						body.appendChild( SL.empty( t( 'No audit yet' ), t( 'Run it to see what Google can index on this site.' ) ) );
					}
					return;
				}
				var high = last.findings.filter( function ( f ) {
					return 'high' === f.severity;
				} ).length;
				body.appendChild( h( 'div', { class: 'sl-kpis' },
					App.kpi( t( 'Findings' ), SL.num( last.findings.length, 0 ) ),
					App.kpi( t( 'High severity' ), SL.num( high, 0 ) ),
					App.kpi( t( 'URL groups checked' ), SL.num( last.inventory.length, 0 ) ),
					App.kpi( t( 'SEO plugin' ), last.rankmath ? 'Rank Math' : String( last.engine || '—' ) )
				) );
				body.appendChild( h( 'p', { class: 'sl-muted sl-small', text: t( 'Last audit: %s', SL.date( last.at ) ) + ( last.gsc ? '' : ' · ' + t( 'Connect Search Console to include Google’s own index status.' ) ) } ) );

				body.appendChild( h( 'h3', { text: t( 'Findings' ) } ) );
				if ( ! last.findings.length ) {
					body.appendChild( h( 'div', { class: 'sl-alert sl-alert--success', text: t( 'Nothing to fix: every URL group is indexed (or kept out) the way it should be.' ) } ) );
				}
				last.findings.forEach( function ( f ) {
					var sev = SEV[ f.severity ] || SEV.low;
					var card = h( 'div', { class: 'sl-card sl-finding' } );
					card.appendChild( h( 'div', { class: 'sl-row' }, h( 'b', { dir: 'auto', text: f.type } ), SL.badge( sev[ 0 ], sev[ 1 ] ), f.count ? h( 'span', { class: 'sl-muted sl-small', text: t( '%s URLs', SL.num( f.count, 0 ) ) } ) : null, h( 'span', { class: 'sl-muted sl-small', text: t( 'Source: %s', f.source ) } ) ) );
					if ( f.url ) {
						card.appendChild( h( 'a', { href: f.url, target: '_blank', rel: 'noopener', dir: 'ltr', class: 'sl-small', text: f.url } ) );
					}
					card.appendChild( stateBadges( f.state ) );
					card.appendChild( h( 'p', {}, h( 'b', { text: t( 'Problem:' ) + ' ' } ), h( 'span', { text: f.issue } ) ) );
					card.appendChild( h( 'p', { class: 'sl-muted' }, h( 'b', { text: t( 'Why it matters:' ) + ' ' } ), h( 'span', { text: f.reason } ) ) );
					card.appendChild( h( 'p', {}, h( 'b', { text: t( 'Solution:' ) + ' ' } ), h( 'span', { text: f.solution } ) ) );
					var acts = h( 'div', { class: 'sl-row' } );
					if ( f.fixable && App.cap( 'manage' ) ) {
						acts.appendChild( App.abtn( SL.il( 'wand', t( 'Fix it (preview first)' ), 15 ), function () {
							return SL.api( 'indexability/fix', { body: { key: f.key } } ).then( function ( op ) {
								var m = SL.modal( { title: op.title, wide: true, onClose: load } );
								var box = h( 'div' );
								m.body.appendChild( box );
								App.operationView( box, op, load );
							} );
						}, 'primary' ) );
					}
					if ( f.edit ) {
						acts.appendChild( h( 'a', { class: 'sl-btn', href: f.edit, text: t( 'Fix manually' ) } ) );
					}
					card.appendChild( acts );
					body.appendChild( card );
				} );

				body.appendChild( h( 'h3', { text: t( 'Everything that was checked' ) } ) );
				body.appendChild( App.table( [
					{ label: t( 'URL group' ), render: function ( x ) {
						return h( 'div', {}, h( 'b', { dir: 'auto', text: x.label } ), x.note ? h( 'div', { class: 'sl-muted sl-small', text: x.note } ) : null );
					} },
					{ label: t( 'Source' ), render: function ( x ) {
						return x.source;
					} },
					{ label: t( 'Count' ), render: function ( x ) {
						return null === x.count || undefined === x.count ? '—' : SL.num( x.count, 0 );
					} },
					{ label: t( 'Should be indexed' ), render: function ( x ) {
						return SL.badge( x.intended ? t( 'Yes' ) : t( 'No' ), x.intended ? 'brand' : '' );
					} },
					{ label: t( 'Live state' ), render: function ( x ) {
						return stateBadges( x.state );
					} },
					{ label: t( 'Sample URL' ), render: function ( x ) {
						return x.url ? h( 'a', { href: x.url, target: '_blank', rel: 'noopener', dir: 'ltr', class: 'sl-small', text: x.url } ) : '—';
					} },
				], last.inventory ) );
			} );
		}
		load();
	};

	/* ------------------------------------------------------- duplicates */
	var KIND = {
		duplicate: [ t( 'Duplicate' ), 'bad' ],
		near_duplicate: [ t( 'Near-duplicate' ), 'warn' ],
		cannibalization: [ t( 'Same topic (cannibalization)' ), 'brand' ],
	};
	var ISSUE_STATE = {
		open: [ t( 'Open' ), '' ],
		in_progress: [ t( 'In progress' ), 'brand' ],
		done: [ t( 'Done & verified' ), 'good' ],
		resolved: [ t( 'Resolved' ), 'good' ],
		dismissed: [ t( 'Dismissed' ), '' ],
	};

	function openOperation( id, onChange ) {
		return SL.api( 'operations/' + id ).then( function ( op ) {
			var m = SL.modal( { title: op.title, wide: true, onClose: onChange } );
			var box = h( 'div' );
			m.body.appendChild( box );
			App.operationView( box, op, onChange );
		} );
	}

	function clusterCard( c, r, reload ) {
		var k = KIND[ c.kind ] || [ c.kind, '' ];
		var issue = c.issue || {};
		var st = ISSUE_STATE[ issue.status ] || null;
		var card = h( 'div', { class: 'sl-card sl-finding sl-dup' } );
		var INTENT = { commercial: t( 'Commercial intent' ), informational: t( 'Informational intent' ), unknown: '' };
		if ( undefined !== c.severity ) {
			card.appendChild( h( 'div', { class: 'sl-dup__sev', title: t( 'Severity' ) }, h( 'span', { style: 'width:' + Math.max( 3, c.severity ) + '%' } ), h( 'b', { text: t( 'Severity %s/100', c.severity ) } ) ) );
		}
		card.appendChild( h( 'div', { class: 'sl-row' }, SL.badge( k[ 0 ], k[ 1 ] ), st ? SL.badge( ( 'done' === issue.status && issue.verified_at ? '✓ ' : '' ) + st[ 0 ], st[ 1 ] ) : null, h( 'span', { class: 'sl-muted sl-small', text: t( '%s articles', c.posts.length ) } ), c.clicks ? h( 'span', { class: 'sl-muted sl-small', text: t( '%s clicks (90 days)', SL.num( c.clicks, 0 ) ) } ) : null ) );
		var primary = c.primary;
		var merge = {};
		c.posts.forEach( function ( p ) {
			merge[ p.id ] = p.id !== primary;
		} );
		var rows = h( 'div', { class: 'sl-dup__posts' } );
		function drawRows() {
			SL.clear( rows );
			c.posts.forEach( function ( p ) {
				var isP = p.id === primary;
				rows.appendChild( h( 'div', { class: 'sl-dup__post' + ( isP ? ' is-primary' : '' ) },
					h( 'label', { class: 'sl-dup__pick', title: t( 'Keep this one (primary)' ) }, h( 'input', { type: 'radio', name: 'p_' + c.key, checked: isP, onChange: function () {
						primary = p.id;
						merge[ p.id ] = false;
						c.posts.forEach( function ( o ) {
							if ( o.id !== primary && undefined === merge[ o.id ] ) {
								merge[ o.id ] = true;
							}
						} );
						drawRows();
					} } ), h( 'span', { text: isP ? t( 'Primary' ) : t( 'Keep' ) } ) ),
					isP ? h( 'span', { class: 'sl-small sl-muted', text: '—' } ) : h( 'label', { class: 'sl-dup__pick', title: t( 'Merge into the primary article' ) }, h( 'input', { type: 'checkbox', checked: !! merge[ p.id ], onChange: function ( e ) {
						merge[ p.id ] = e.target.checked;
					} } ), h( 'span', { text: t( 'Merge' ) } ) ),
					h( 'div', { class: 'sl-dup__meta' },
						h( 'a', { class: 'sl-dup__title', href: p.url, target: '_blank', rel: 'noopener', dir: 'auto', text: p.title || p.url } ),
						h( 'span', { class: 'sl-dup__url', dir: 'ltr', text: decodeURI( p.url ) } ),
						h( 'div', { class: 'sl-dup__nums' },
							h( 'div', { class: 'sl-mapcard__m' }, h( 'b', { text: SL.num( p.clicks, 0 ) } ), h( 'span', { text: t( 'Clicks (90 days)' ) } ) ),
							h( 'div', { class: 'sl-mapcard__m' }, h( 'b', { text: SL.num( p.words, 0 ) } ), h( 'span', { text: t( 'Words' ) } ) ),
							h( 'div', { class: 'sl-mapcard__m' }, h( 'b', { text: SL.num( p.inlinks, 0 ) } ), h( 'span', { text: t( 'Internal links in' ) } ) )
						),
						INTENT[ p.intent ] ? SL.badge( INTENT[ p.intent ], 'commercial' === p.intent ? 'warn' : 'brand' ) : null ),
					h( 'a', { class: 'sl-btn sl-btn--ghost sl-btn--sm', href: p.edit, text: t( 'Edit' ) } )
				) );
			} );
		}
		drawRows();
		card.appendChild( rows );
		var why = c.pairs.map( function ( pr ) {
			var bits = [];
			if ( pr.content ) {
				bits.push( pr.content + '% ' + t( 'same content' ) );
			}
			if ( pr.title ) {
				bits.push( pr.title + '% ' + t( 'similar titles' ) );
			}
			if ( pr.h1 ) {
				bits.push( t( 'same H1' ) );
			}
			if ( pr.topic ) {
				bits.push( pr.topic + '% ' + t( 'same topic' ) );
			}
			if ( pr.queries && pr.queries.length ) {
				bits.push( t( 'both rank for: %s', pr.queries.join( '، ' ) ) );
			}
			if ( pr.split ) {
				bits.push( t( '%s% of the smaller page’s impressions are fought over', pr.split ) );
			}
			if ( pr.flips ) {
				bits.push( t( 'Google swapped them %s times', pr.flips ) );
			}
			if ( pr.same_intent ) {
				bits.push( t( 'same search intent' ) );
			}
			return bits.join( ' · ' );
		} ).filter( Boolean );
		card.appendChild( h( 'p', { class: 'sl-small' }, h( 'b', { text: t( 'Why they are grouped:' ) + ' ' } ), h( 'span', { text: why.join( ' | ' ) } ) ) );
		var STRAT = { merge: t( 'Merge into the primary' ), differentiate: t( 'Differentiate (retarget one)' ), canonical: t( 'Canonical to the primary' ), internal_link: t( 'Strengthen the primary with internal links' ) };
		var sg = c.strategy || { best: 'merge', why: '', alternatives: [] };
		card.appendChild( h( 'div', { class: 'sl-dup__strat' },
			h( 'div', null, h( 'b', { text: t( 'Recommended:' ) + ' ' } ), SL.badge( STRAT[ sg.best ] || sg.best, 'good' ), h( 'p', { class: 'sl-small', text: sg.why } ) ),
			sg.alternatives && sg.alternatives.length ? h( 'details', { class: 'sl-small' }, h( 'summary', { text: t( 'Other options' ) } ), h( 'ul', null, sg.alternatives.map( function ( a ) {
				return h( 'li', null, h( 'b', { text: ( STRAT[ a.id ] || a.id ) + ': ' } ), a.why );
			} ) ) ) : null,
			h( 'p', { class: 'sl-small sl-muted', text: t( 'Primary suggestion:' ) + ' ' + c.why } )
		) );
		var instr = h( 'textarea', { class: 'sl-input', rows: 2, dir: 'auto', placeholder: t( 'Instructions for this merge (optional), e.g. “keep the price table from the second article”, “do not change the introduction”.' ) } );
		instr.value = issue.instructions || '';
		card.appendChild( instr );
		var acts = h( 'div', { class: 'sl-row' } );
		if ( issue.operation_id ) {
			acts.appendChild( App.abtn( t( 'Open the last operation' ), function () {
				return openOperation( issue.operation_id, reload );
			} ) );
		}
		if ( App.cap( 'apply' ) && 'done' !== issue.status ) {
			acts.appendChild( App.abtn( SL.il( 'wand', t( 'Fix cannibalization' ), 15 ), function () {
				if ( ! r.ai ) {
					SL.toast( t( 'Connect an AI engine first (Settings → AI).' ), 'error' );
					return Promise.resolve();
				}
				var secs = Object.keys( merge ).filter( function ( id ) {
					return merge[ id ] && Number( id ) !== primary;
				} ).map( Number );
				return SL.api( 'duplicates/consolidate', { body: { key: c.key, primary: primary, secondaries: secs, instructions: instr.value } } ).then( function ( op ) {
					var m = SL.modal( { title: op.title, wide: true, onClose: reload } );
					m.body.appendChild( h( 'p', { class: 'sl-muted', text: t( 'The AI merges the articles first. You then see the full before/after of every step. Redirects and link updates run only after the merged article was saved and verified; nothing is deleted and every edit keeps a WordPress revision.' ) } ) );
					var box = h( 'div' );
					m.body.appendChild( box );
					App.operationView( box, op, reload );
				} );
			}, 'merge' === sg.best ? 'primary' : '' ) );
			var others = function () {
				return c.posts.filter( function ( p ) {
					return p.id !== primary;
				} );
			};
			acts.appendChild( App.abtn( t( 'Differentiate' ), function () {
				var target = others()[ 0 ];
				return SL.api( 'duplicates/differentiate', { body: { key: c.key, post: target.id, instructions: instr.value } } ).then( function ( op ) {
					var m = SL.modal( { title: op.title, wide: true, onClose: reload } );
					m.body.appendChild( h( 'p', { class: 'sl-muted', text: t( 'The AI retargets “%s” to a different query (title, SEO title, description, focus keyword) so both articles can rank. Review and approve.', target.title ) } ) );
					var box = h( 'div' );
					m.body.appendChild( box );
					App.operationView( box, op, reload );
				} );
			}, 'differentiate' === sg.best ? 'primary' : '' ) );
			acts.appendChild( App.abtn( t( 'Set canonical' ), function () {
				var target = others()[ 0 ];
				return SL.api( 'duplicates/canonical', { body: { key: c.key, post: target.id, primary: primary } } ).then( function ( op ) {
					var m = SL.modal( { title: op.title, wide: true, onClose: reload } );
					var box = h( 'div' );
					m.body.appendChild( box );
					App.operationView( box, op, reload );
				} );
			}, 'canonical' === sg.best ? 'primary' : '' ) );
		}
		card.appendChild( acts );
		return card;
	}

	App.pages.duplicates = function ( v ) {
		var body = h( 'div' );
		var timer;
		v.appendChild( App.header( t( 'Duplicate & near-duplicate articles' ), t( 'Checks only your blog articles (not pages or products): finds articles that repeat each other or compete for the same topic (cannibalization — content overlap, titles, H1, shared Search Console queries) and suggests merging them into the strongest one. Nothing changes until you press “Fix cannibalization” and approve the preview.' ), App.cap( 'manage' ) ? [ App.abtn( SL.il( 'pulse', t( 'Scan for duplicates' ), 15 ), function () {
			return SL.api( 'duplicates/scan', { method: 'POST' } ).then( function () {
				SL.toast( t( 'Scan started in the background.' ) );
				load();
			} );
		}, 'primary' ) ] : null ) );
		v.appendChild( body );

		function load() {
			clearTimeout( timer );
			App.load( body, SL.api( 'duplicates' ), function ( r ) {
				if ( r.running ) {
					body.appendChild( h( 'div', { class: 'sl-alert sl-alert--info sl-row' }, SL.spinner(), h( 'span', { text: t( 'The scan is running — results refresh automatically.' ) } ) ) );
					timer = setTimeout( function () {
						if ( d.body.contains( body ) ) {
							load();
						}
					}, 4000 );
				}
				// Standing instructions: stored once, reused by every merge.
				var std = h( 'textarea', { class: 'sl-input', rows: 3, dir: 'auto', placeholder: t( 'Rules for every merge, e.g. “keep our brand voice”, “never remove prices”, “keep FAQs at the end”.' ) } );
				std.value = r.instructions || '';
				body.appendChild( h( 'div', { class: 'sl-card sl-stack' }, h( 'b', { text: t( 'Your standing instructions for merges' ) } ), h( 'span', { class: 'sl-muted sl-small', text: t( 'Saved and added to every AI merge, together with the instructions of each group.' ) } ), std, App.cap( 'manage' ) ? h( 'div', {}, App.abtn( t( 'Save instructions' ), function () {
					return SL.api( 'duplicates/instructions', { body: { instructions: std.value } } ).then( function () {
						SL.toast( t( 'Saved' ) );
					} );
				} ) ) : null ) );
				var last = r.last;
				if ( ! last ) {
					if ( ! r.running ) {
						body.appendChild( SL.empty( t( 'No scan yet' ), t( 'Run it to find articles that repeat or compete with each other.' ) ) );
					}
					return;
				}
				var n = { duplicate: 0, near_duplicate: 0, cannibalization: 0 };
				last.clusters.forEach( function ( c ) {
					n[ c.kind ] = ( n[ c.kind ] || 0 ) + 1;
				} );
				body.appendChild( h( 'div', { class: 'sl-kpis' },
					App.kpi( t( 'Blog articles checked' ), SL.num( last.checked, 0 ) ),
					App.kpi( t( 'Duplicate groups' ), SL.num( n.duplicate, 0 ) ),
					App.kpi( t( 'Near-duplicate groups' ), SL.num( n.near_duplicate, 0 ) ),
					App.kpi( t( 'Competing topics' ), SL.num( n.cannibalization, 0 ) )
				) );
				body.appendChild( h( 'p', { class: 'sl-muted sl-small', text: t( 'Last scan: %s', SL.date( last.at ) ) + ( last.gsc ? '' : ' · ' + t( 'Connect Search Console to also find pages that compete for the same queries.' ) ) } ) );
				if ( ! last.clusters.length ) {
					body.appendChild( h( 'div', { class: 'sl-alert sl-alert--success', text: t( 'No duplicate or competing articles found.' ) } ) );
				}
				last.clusters.forEach( function ( c ) {
					body.appendChild( clusterCard( c, r, load ) );
				} );
			} );
		}
		load();
	};
}( window, document ) );
