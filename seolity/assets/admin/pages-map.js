/**
 * Site map explorer: every URL of the site — crawl, XML sitemap, WordPress and Search Console —
 * coloured by what Google can do with it, as a pixel map of the whole site, a folder tree, a
 * sortable list and a daily trend, with a detail panel per URL. Rebuilt every day.
 */
( function ( w, d ) {
	'use strict';
	var SL = w.SL;
	var App = SL.app;
	var t = SL.t;
	var h = SL.h;

	var IC = { indexable: 'check-circle', noindex: 'eye-off', blocked: 'lock', redirect: 'corner', error: 'x-circle', canonicalized: 'link', not_crawled: 'clock' };
	var ST = {
		indexable: [ '#16a34a', t( 'Indexable' ), t( 'Google can crawl and index this page.' ) ],
		noindex: [ '#f59e0b', t( 'noindex' ), t( 'Google can crawl it but is told not to index it.' ) ],
		blocked: [ '#dc2626', t( 'Blocked by robots.txt' ), t( 'Google is not allowed to crawl it, so it cannot see its content.' ) ],
		redirect: [ '#2563eb', t( 'Redirect' ), t( 'The URL forwards to another address; Google indexes the target.' ) ],
		error: [ '#7f1d1d', t( 'Error (4xx/5xx)' ), t( 'The page does not load; Google drops it from the index.' ) ],
		canonicalized: [ '#9333ea', t( 'Canonical elsewhere' ), t( 'The page says another URL is the original; Google usually indexes that one.' ) ],
		not_crawled: [ '#9ca3af', t( 'Not crawled yet' ), t( 'Known from the sitemap, WordPress or Search Console but not checked yet.' ) ],
	};
	var FL = {
		orphan: t( 'Orphan (no internal links)' ),
		not_in_sitemap: t( 'Indexable but not in the sitemap' ),
		sitemap_problem: t( 'In the sitemap but not indexable' ),
		deep: t( 'Deep (more than 3 clicks)' ),
		thin: t( 'Thin (under 300 words)' ),
		slow: t( 'Slow (over 1.5 s)' ),
		traffic: t( 'Gets search clicks' ),
		google_only: t( 'Only known from Google' ),
		in_sitemap: t( 'In the sitemap' ),
	};

	function dot( st ) {
		return h( 'span', { class: 'sl-map__dot', style: 'background:' + ( ST[ st ] ? ST[ st ][ 0 ] : '#999' ), title: ST[ st ] ? ST[ st ][ 1 ] : st } );
	}

	function path( u ) {
		try {
			return decodeURI( new URL( u ).pathname );
		} catch ( e ) {
			return u;
		}
	}

	/** Detail panel of one URL. */
	App.mapUrl = function ( url, onChange ) {
		var m = SL.modal( { title: path( url ), wide: true } );
		function draw( r ) {
			SL.clear( m.body );
			var it = r.map || {};
			var st = ST[ it.status ] || [ '#999', it.status, '' ];
			m.body.appendChild( h( 'div', { class: 'sl-row' }, h( 'span', { class: 'sl-map__badge', style: 'background:' + st[ 0 ], text: st[ 1 ] } ), h( 'span', { class: 'sl-muted', text: st[ 2 ] } ) ) );
			m.body.appendChild( h( 'a', { href: url, target: '_blank', rel: 'noopener', dir: 'ltr', class: 'sl-small', text: url } ) );
			if ( it.flags && it.flags.length ) {
				m.body.appendChild( SL.chips( it.flags.map( function ( f ) {
					return FL[ f ] || f;
				} ) ) );
			}
			var c = r.crawl || {};
			m.body.appendChild( h( 'div', { class: 'sl-kpis' },
				App.kpi( t( 'HTTP status' ), c.status_code ? String( c.status_code ) : '—' ),
				App.kpi( t( 'Clicks (90 days)' ), SL.num( it.clicks || 0, 0 ) ),
				App.kpi( t( 'Impressions' ), SL.num( it.impressions || 0, 0 ) ),
				App.kpi( t( 'Avg. position' ), it.position ? SL.num( it.position ) : '—' ),
				App.kpi( t( 'Internal links in' ), SL.num( c.inlinks || 0, 0 ) ),
				App.kpi( t( 'Click depth' ), c.depth !== undefined && c.depth !== null ? String( c.depth ) : '—' )
			) );
			m.body.appendChild( App.table( [
				{ label: t( 'Check' ), render: function ( x ) {
					return h( 'b', { text: x[ 0 ] } );
				} },
				{ label: t( 'Value' ), render: function ( x ) {
					return h( 'span', { dir: 'auto', class: 'sl-small', text: x[ 1 ] } );
				} },
			], [
				[ t( 'robots.txt' ), r.robots_txt_allowed ? t( 'Crawling allowed' ) : t( 'Crawling blocked' ) ],
				[ t( 'Meta robots' ), c.robots || 'index, follow' ],
				[ t( 'Canonical' ), c.canonical || t( 'self / none' ) ],
				[ t( 'Redirects to' ), c.redirect_to || '—' ],
				[ t( 'Title' ), c.title || '—' ],
				[ t( 'Meta description' ), c.meta_description || '—' ],
				[ t( 'Words' ), c.word_count ? String( c.word_count ) : '—' ],
				[ t( 'Response time' ), c.response_ms ? c.response_ms + ' ms' : '—' ],
				[ t( 'In XML sitemap' ), +c.in_sitemap ? t( 'Yes' ) : t( 'No' ) ],
				[ t( 'Found via' ), c.source || '—' ],
				[ t( 'Last checked' ), c.last_crawled ? SL.date( c.last_crawled ) : '—' ],
			] ) );
			if ( r.issues && r.issues.length ) {
				m.body.appendChild( h( 'h4', { text: t( 'Open problems' ) } ) );
				m.body.appendChild( h( 'ul', null, r.issues.map( function ( i ) {
					return h( 'li', null, h( 'b', { text: i.title } ), ' ', SL.badge( t( i.severity ), 'critical' === i.severity || 'high' === i.severity ? 'bad' : 'warn' ), i.fix ? h( 'div', { class: 'sl-small sl-muted', text: i.fix } ) : null );
				} ) ) );
			}
			if ( r.queries && r.queries.length ) {
				m.body.appendChild( h( 'h4', { text: t( 'Search queries (last 28 days)' ) } ) );
				m.body.appendChild( App.table( [
					{ label: t( 'Query' ), render: function ( q ) {
						return h( 'span', { dir: 'auto', text: q.query } );
					} },
					{ label: t( 'Clicks' ), render: function ( q ) {
						return SL.num( q.clicks_28d, 0 );
					} },
					{ label: t( 'Impressions' ), render: function ( q ) {
						return SL.num( q.impressions_28d, 0 );
					} },
					{ label: t( 'Position' ), render: function ( q ) {
						return h( 'span', null, q.position_28d ? SL.num( q.position_28d ) : '—', q.position_prev_28d && q.position_28d ? h( 'span', { class: q.position_28d <= q.position_prev_28d ? 'is-up' : 'is-down', text: ' (' + SL.num( q.position_prev_28d ) + ')' } ) : null );
					} },
				], r.queries ) );
			}
			var L = r.links || {};
			m.body.appendChild( h( 'div', { class: 'sl-row sl-map__actions' },
				h( 'a', { class: 'sl-btn', href: L.live, target: '_blank', rel: 'noopener', text: t( 'Open page' ) } ),
				L.edit ? h( 'a', { class: 'sl-btn', href: L.edit, target: '_blank', rel: 'noopener', text: t( 'Edit' ) } ) : null,
				h( 'a', { class: 'sl-btn', href: L.inspect, target: '_blank', rel: 'noopener', text: t( 'Inspect in Search Console' ) } ),
				h( 'a', { class: 'sl-btn', href: L.rich, target: '_blank', rel: 'noopener', text: t( 'Rich results test' ) } ),
				h( 'a', { class: 'sl-btn', href: L.speed, target: '_blank', rel: 'noopener', text: t( 'PageSpeed' ) } ),
				App.cap( 'manage' ) ? App.abtn( SL.il( 'refresh', t( 'Check again now' ), 14 ), function () {
					return SL.api( 'sitemap/map/check', { body: { url: url } } ).then( function ( x ) {
						draw( x );
						if ( onChange ) {
							onChange();
						}
					} );
				}, 'primary' ) : null
			) );
		}
		App.load( m.body, SL.api( 'sitemap/map/url', { query: { url: url } } ), draw );
	};

	/** Every URL as one coloured square (canvas, fast for tens of thousands of URLs). */
	function pixelMap( box, f ) {
		App.load( box, SL.api( 'sitemap/map/pixels' ), function ( r ) {
			var items = r.items.filter( function ( i ) {
				return ( ! f.status || i[ 1 ] === f.status ) && ( ! f.q || decodeURI( i[ 0 ] ).toLowerCase().indexOf( f.q.toLowerCase() ) >= 0 );
			} ).sort( function ( a, b ) {
				return a[ 0 ] < b[ 0 ] ? -1 : 1;
			} );
			if ( ! items.length ) {
				box.appendChild( SL.empty( t( 'No URLs match' ), '' ) );
				return;
			}
			var size = items.length > 5000 ? 6 : ( items.length > 1000 ? 10 : ( items.length > 200 ? 16 : 28 ) );
			var gap = items.length > 5000 ? 1 : 2;
			var wrap = h( 'div', { class: 'sl-map__canvaswrap' } );
			var cv = h( 'canvas', { class: 'sl-map__canvas' } );
			var tip = h( 'div', { class: 'sl-map__tip', hidden: true } );
			wrap.appendChild( cv );
			wrap.appendChild( tip );
			box.appendChild( h( 'p', { class: 'sl-muted sl-small', text: t( '%s URLs, one square each, sorted by address (folders sit together). Brighter border = gets search clicks. Click a square for details.', SL.num( items.length, 0 ) ) } ) );
			box.appendChild( wrap );
			var cols = 1;
			function paint() {
				var wpx = Math.max( 200, wrap.clientWidth - 4 );
				cols = Math.max( 1, Math.floor( wpx / ( size + gap ) ) );
				var rows = Math.ceil( items.length / cols );
				var ratio = w.devicePixelRatio || 1;
				cv.width = cols * ( size + gap ) * ratio;
				cv.height = rows * ( size + gap ) * ratio;
				cv.style.width = cols * ( size + gap ) + 'px';
				cv.style.height = rows * ( size + gap ) + 'px';
				var ctx = cv.getContext( '2d' );
				ctx.scale( ratio, ratio );
				items.forEach( function ( it, i ) {
					var x = ( i % cols ) * ( size + gap );
					var y = Math.floor( i / cols ) * ( size + gap );
					ctx.fillStyle = ST[ it[ 1 ] ] ? ST[ it[ 1 ] ][ 0 ] : '#999';
					ctx.fillRect( x, y, size, size );
					if ( it[ 2 ] > 0 && size >= 10 ) {
						ctx.strokeStyle = '#facc15';
						ctx.lineWidth = 2;
						ctx.strokeRect( x + 1, y + 1, size - 2, size - 2 );
					}
				} );
			}
			function at( e ) {
				var rc = cv.getBoundingClientRect();
				var col = Math.floor( ( e.clientX - rc.left ) / ( size + gap ) );
				var row = Math.floor( ( e.clientY - rc.top ) / ( size + gap ) );
				return col >= 0 && col < cols ? items[ row * cols + col ] : null;
			}
			cv.addEventListener( 'mousemove', function ( e ) {
				var it = at( e );
				if ( ! it ) {
					tip.hidden = true;
					return;
				}
				tip.hidden = false;
				tip.textContent = decodeURI( it[ 0 ] ) + ' — ' + ( ST[ it[ 1 ] ] ? ST[ it[ 1 ] ][ 1 ] : it[ 1 ] ) + ( it[ 2 ] ? ' · ' + t( '%s clicks', it[ 2 ] ) : '' );
				var rc = wrap.getBoundingClientRect();
				tip.style.left = Math.min( rc.width - 220, e.clientX - rc.left + 12 ) + 'px';
				tip.style.top = ( e.clientY - rc.top + 12 ) + 'px';
			} );
			cv.addEventListener( 'mouseleave', function () {
				tip.hidden = true;
			} );
			cv.addEventListener( 'click', function ( e ) {
				var it = at( e );
				if ( it ) {
					App.mapUrl( it[ 0 ], f.reload );
				}
			} );
			paint();
			w.addEventListener( 'resize', function () {
				if ( d.body.contains( cv ) ) {
					paint();
				}
			} );
		} );
	}

	function stack( counts, total ) {
		var bar = h( 'div', { class: 'sl-map__stack' } );
		Object.keys( ST ).forEach( function ( k ) {
			if ( counts[ k ] ) {
				bar.appendChild( h( 'span', { style: 'background:' + ST[ k ][ 0 ] + ';width:' + ( 100 * counts[ k ] / Math.max( 1, total ) ) + '%', title: ST[ k ][ 1 ] + ': ' + counts[ k ] } ) );
			}
		} );
		return bar;
	}

	function metric( label, value, tone ) {
		return h( 'div', { class: 'sl-mapcard__m' + ( tone ? ' is-' + tone : '' ) }, h( 'b', { text: value } ), h( 'span', { text: label } ) );
	}

	/** One URL as a large horizontal card: status, title, address, numbers, warnings, actions. */
	function urlCard( u, reload ) {
		var st = ST[ u.status ] || [ '#9ca3af', u.status, '' ];
		var warn = ( u.flags || [] ).filter( function ( x ) {
			return 'in_sitemap' !== x && 'traffic' !== x;
		} );
		var open = function () {
			App.mapUrl( u.url, reload );
		};
		return h( 'article', { class: 'sl-mapcard', style: '--c:' + st[ 0 ] },
			h( 'div', { class: 'sl-mapcard__head' },
				h( 'span', { class: 'sl-mapcard__ic', 'aria-hidden': 'true' }, SL.icon( IC[ u.status ] || 'info', 22 ) ),
				h( 'div', { class: 'sl-mapcard__titles' },
					h( 'button', { type: 'button', class: 'sl-mapcard__title', dir: 'auto', onClick: open, text: u.title || path( u.url ) } ),
					h( 'a', { class: 'sl-mapcard__url', href: u.url, target: '_blank', rel: 'noopener', dir: 'ltr', text: path( u.url ) } )
				),
				h( 'span', { class: 'sl-mapcard__status' }, h( 'b', { text: st[ 1 ] } ), u.code ? h( 'span', { text: 'HTTP ' + u.code } ) : null )
			),
			h( 'p', { class: 'sl-mapcard__why', text: st[ 2 ] } ),
			h( 'div', { class: 'sl-mapcard__metrics' },
				metric( t( 'Clicks (90 days)' ), SL.num( u.clicks || 0, 0 ), u.clicks ? 'good' : '' ),
				metric( t( 'Impressions' ), SL.num( u.impressions || 0, 0 ) ),
				metric( t( 'Avg. position' ), u.position ? SL.num( u.position ) : '—' ),
				metric( t( 'Internal links in' ), SL.num( u.inlinks || 0, 0 ), u.inlinks ? '' : 'bad' ),
				metric( t( 'Click depth' ), u.depth !== undefined && u.depth !== null ? String( u.depth ) : '—', u.depth > 3 ? 'warn' : '' ),
				metric( t( 'Words' ), SL.num( u.words || 0, 0 ), u.words && u.words < 300 ? 'warn' : '' )
			),
			h( 'div', { class: 'sl-mapcard__foot' },
				h( 'div', { class: 'sl-mapcard__flags' }, warn.length ? warn.map( function ( x ) {
					return h( 'span', { class: 'sl-mapcard__flag' }, SL.icon( 'alert', 13 ), h( 'span', { text: FL[ x ] || x } ) );
				} ) : h( 'span', { class: 'sl-mapcard__ok' }, SL.icon( 'check', 13 ), h( 'span', { text: t( 'No extra warnings' ) } ) ) ),
				h( 'div', { class: 'sl-mapcard__actions' },
					App.btn( t( 'Details' ), open, 'primary' ),
					h( 'a', { class: 'sl-btn', href: u.url, target: '_blank', rel: 'noopener' }, SL.icon( 'external', 14 ), ' ', t( 'Open page' ) )
				)
			)
		);
	}

	function cards( box, f ) {
		App.load( box, SL.api( 'sitemap/map', { query: { status: f.status, flag: f.flag, q: f.q, sort: f.sort, all: 1, per_page: f.cards } } ), function ( r ) {
			box.appendChild( h( 'div', { class: 'sl-toolbar' }, App.select( [ [ 'clicks', t( 'Sort: clicks' ) ], [ 'url', t( 'Sort: address' ) ], [ 'impressions', t( 'Sort: impressions' ) ], [ 'inlinks', t( 'Sort: internal links' ) ], [ 'depth', t( 'Sort: click depth' ) ], [ 'words', t( 'Sort: words' ) ], [ 'ms', t( 'Sort: response time' ) ] ], f.sort, function ( x ) {
				f.sort = x;
				cards( SL.clear( box ), f );
			} ), h( 'span', { class: 'sl-muted sl-small', text: t( '%s URLs', SL.num( r.found, 0 ) ) } ) ) );
			if ( ! r.urls.length ) {
				box.appendChild( SL.empty( t( 'No URLs match' ), '' ) );
				return;
			}
			var list = h( 'div', { class: 'sl-mapcards' } );
			r.urls.forEach( function ( u ) {
				list.appendChild( urlCard( u, f.reload ) );
			} );
			box.appendChild( list );
			if ( r.found > r.urls.length ) {
				box.appendChild( h( 'div', { class: 'sl-mapcards__more' }, App.abtn( t( 'Show more' ), function () {
					f.cards += 30;
					cards( SL.clear( box ), f );
					return Promise.resolve();
				} ) ) );
			}
		} );
	}

	function folders( box, f ) {
		App.load( box, SL.api( 'sitemap/map', { query: { status: f.status, flag: f.flag, q: f.q, folder: f.folder, per_page: 300 } } ), function ( r ) {
			var crumbs = h( 'div', { class: 'sl-map__crumbs' } );
			var parts = r.folder.split( '/' ).filter( Boolean );
			crumbs.appendChild( h( 'button', { type: 'button', class: 'sl-link', onClick: function () {
				f.folder = '/';
				folders( SL.clear( box ), f );
			}, text: t( 'Site root' ) } ) );
			parts.forEach( function ( p, i ) {
				crumbs.appendChild( h( 'span', { text: ' / ' } ) );
				crumbs.appendChild( h( 'button', { type: 'button', class: 'sl-link', dir: 'auto', onClick: function () {
					f.folder = '/' + parts.slice( 0, i + 1 ).join( '/' ) + '/';
					folders( SL.clear( box ), f );
				}, text: p } ) );
			} );
			box.appendChild( crumbs );
			r.folders.forEach( function ( fo ) {
				box.appendChild( h( 'button', { type: 'button', class: 'sl-map__folder', onClick: function () {
					f.folder = fo.folder;
					folders( SL.clear( box ), f );
				} }, h( 'span', { class: 'sl-map__fic', 'aria-hidden': 'true' }, SL.icon( 'folder', 22 ) ), h( 'span', { class: 'sl-map__fname', dir: 'auto', text: fo.name + '/' } ), h( 'span', { class: 'sl-map__fmeta', text: t( '%s URLs', SL.num( fo.total, 0 ) ) + ( fo.clicks ? '، ' + t( '%s clicks', SL.num( fo.clicks, 0 ) ) : '' ) } ), stack( fo.counts, fo.total ), h( 'span', { class: 'sl-map__flegend' }, Object.keys( ST ).filter( function ( k ) {
					return fo.counts[ k ];
				} ).map( function ( k ) {
					return h( 'span', null, dot( k ), h( 'span', { text: ST[ k ][ 1 ] + ': ' + SL.num( fo.counts[ k ], 0 ) } ) );
				} ) ) ) );
			} );
			if ( r.urls.length ) {
				box.appendChild( h( 'h4', { text: t( 'Pages in this folder' ) } ) );
				var fl = h( 'div', { class: 'sl-mapcards' } );
				r.urls.forEach( function ( u ) {
					fl.appendChild( urlCard( u, f.reload ) );
				} );
				box.appendChild( fl );
			}
			if ( ! r.folders.length && ! r.urls.length ) {
				box.appendChild( SL.empty( t( 'No URLs match' ), '' ) );
			}
		} );
	}

	function list( box, f ) {
		App.load( box, SL.api( 'sitemap/map', { query: { status: f.status, flag: f.flag, q: f.q, sort: f.sort, all: 1, per_page: f.per } } ), function ( r ) {
			box.appendChild( h( 'div', { class: 'sl-toolbar' }, App.select( [ [ 'url', t( 'Sort: address' ) ], [ 'clicks', t( 'Sort: clicks' ) ], [ 'impressions', t( 'Sort: impressions' ) ], [ 'inlinks', t( 'Sort: internal links' ) ], [ 'depth', t( 'Sort: click depth' ) ], [ 'words', t( 'Sort: words' ) ], [ 'ms', t( 'Sort: response time' ) ] ], f.sort, function ( x ) {
				f.sort = x;
				list( SL.clear( box ), f );
			} ), h( 'span', { class: 'sl-muted sl-small', text: t( '%s URLs', SL.num( r.found, 0 ) ) } ), App.btn( SL.il( 'download', t( 'Export CSV' ), 14 ), function () {
				SL.api( 'sitemap/map', { query: { status: f.status, flag: f.flag, q: f.q, sort: f.sort, all: 1, per_page: 5000 } } ).then( function ( all ) {
					var head = [ 'url', 'status', 'flags', 'clicks', 'impressions', 'position', 'inlinks', 'depth', 'words', 'response_ms', 'http', 'title' ];
					var csv = '﻿' + head.join( ',' ) + '\n' + all.urls.map( function ( u ) {
						return [ u.url, u.status, u.flags.join( '|' ), u.clicks, u.impressions, u.position || '', u.inlinks, u.depth, u.words, u.ms, u.code, u.title ].map( function ( x ) {
							return '"' + String( x === null || x === undefined ? '' : x ).replace( /"/g, '""' ) + '"';
						} ).join( ',' );
					} ).join( '\n' );
					var a = h( 'a', { href: URL.createObjectURL( new Blob( [ csv ], { type: 'text/csv' } ) ), download: 'site-map.csv' } );
					d.body.appendChild( a );
					a.click();
					a.remove();
				} ).catch( SL.fail );
			}, 'sm' ) ) );
			box.appendChild( App.table( [
				{ label: '', render: function ( u ) {
					return dot( u.status );
				} },
				{ label: t( 'URL' ), render: function ( u ) {
					return h( 'button', { type: 'button', class: 'sl-link', dir: 'ltr', onClick: function () {
						App.mapUrl( u.url, f.reload );
					}, text: path( u.url ) } );
				} },
				{ label: t( 'Flags' ), render: function ( u ) {
					return SL.chips( u.flags.filter( function ( x ) {
						return 'in_sitemap' !== x;
					} ).map( function ( x ) {
						return FL[ x ] || x;
					} ) );
				} },
				{ label: t( 'Clicks' ), render: function ( u ) {
					return SL.num( u.clicks, 0 );
				} },
				{ label: t( 'Impressions' ), render: function ( u ) {
					return SL.num( u.impressions, 0 );
				} },
				{ label: t( 'Links in' ), render: function ( u ) {
					return SL.num( u.inlinks, 0 );
				} },
				{ label: t( 'Depth' ), render: function ( u ) {
					return String( u.depth );
				} },
				{ label: t( 'Words' ), render: function ( u ) {
					return SL.num( u.words, 0 );
				} },
			], r.urls ) );
			if ( r.found > r.urls.length ) {
				box.appendChild( h( 'div', {}, App.abtn( t( 'Show more' ), function () {
					f.per += 500;
					list( SL.clear( box ), f );
					return Promise.resolve();
				} ) ) );
			}
		} );
	}

	function trend( box, history ) {
		var days = Object.keys( history || {} );
		if ( days.length < 1 ) {
			box.appendChild( SL.empty( t( 'The daily history starts with the first refresh' ), t( 'The map is rebuilt every day; each day adds one bar here.' ) ) );
			return;
		}
		var max = Math.max.apply( null, days.map( function ( dd ) {
			return Object.keys( ST ).reduce( function ( s, k ) {
				return s + ( history[ dd ][ k ] || 0 );
			}, 0 );
		} ) );
		var chart = h( 'div', { class: 'sl-map__trend' } );
		days.forEach( function ( dd ) {
			var col = h( 'div', { class: 'sl-map__tcol', title: dd } );
			Object.keys( ST ).forEach( function ( k ) {
				var n = history[ dd ][ k ] || 0;
				if ( n ) {
					col.appendChild( h( 'span', { style: 'background:' + ST[ k ][ 0 ] + ';height:' + ( 100 * n / Math.max( 1, max ) ) + '%', title: dd + ' — ' + ST[ k ][ 1 ] + ': ' + n } ) );
				}
			} );
			chart.appendChild( col );
		} );
		box.appendChild( chart );
		var last = history[ days[ days.length - 1 ] ];
		var first = history[ days[ 0 ] ];
		box.appendChild( h( 'p', { class: 'sl-small sl-muted', text: t( 'Since %1$s: indexable %2$s → %3$s, not indexable / problems %4$s → %5$s.', days[ 0 ], first.indexable || 0, last.indexable || 0, ( first.noindex || 0 ) + ( first.blocked || 0 ) + ( first.error || 0 ), ( last.noindex || 0 ) + ( last.blocked || 0 ) + ( last.error || 0 ) ) } ) );
	}

	App.pages.sitemap = function ( v ) {
		var f = { status: '', flag: '', q: '', folder: '/', sort: 'clicks', per: 300, cards: 30 };
		var body = h( 'div' );
		var timer;
		v.appendChild( App.header( t( 'Site map' ), t( 'Every URL of your site in one picture — from the crawl, the XML sitemap, WordPress and Search Console — coloured by what Google can do with it. Rebuilt every day.' ), [
			App.cap( 'manage' ) ? App.abtn( SL.il( 'refresh', t( 'Rebuild now' ), 14 ), function () {
				return SL.api( 'sitemap/map/refresh', { method: 'POST' } ).then( function () {
					SL.toast( t( 'The site map is being rebuilt in the background.' ) );
					load();
				} );
			}, 'primary' ) : null,
		] ) );
		v.appendChild( body );
		function load() {
			clearTimeout( timer );
			f.reload = load;
			App.load( body, SL.api( 'sitemap/map', { query: { per_page: 20 } } ), function ( r ) {
				if ( r.refreshing ) {
					body.appendChild( h( 'div', { class: 'sl-alert sl-alert--info sl-row' }, SL.spinner(), h( 'span', { text: t( 'Rebuilding the site map…' ) } ) ) );
					timer = setTimeout( function () {
						if ( d.body.contains( body ) ) {
							load();
						}
					}, 5000 );
				}
				body.appendChild( h( 'p', { class: 'sl-muted sl-small', text: t( '%1$s URLs · updated %2$s', SL.num( r.total, 0 ), SL.date( r.at ) ) + ( r.last_refresh ? ' · ' + t( 'last daily refresh %s', SL.date( r.last_refresh.at ) ) : '' ) } ) );
				var tiles = h( 'div', { class: 'sl-map__tiles' } );
				Object.keys( ST ).forEach( function ( k ) {
					tiles.appendChild( h( 'button', { type: 'button', class: 'sl-map__tile' + ( f.status === k ? ' is-on' : '' ), style: '--c:' + ST[ k ][ 0 ], 'aria-pressed': f.status === k ? 'true' : 'false', onClick: function () {
						f.status = f.status === k ? '' : k;
						load();
					} }, h( 'span', { class: 'sl-map__tic', 'aria-hidden': 'true' }, SL.icon( IC[ k ], 22 ) ), h( 'span', { class: 'sl-map__tlabel', text: ST[ k ][ 1 ] } ), h( 'b', { text: SL.num( r.counts[ k ] || 0, 0 ) } ), h( 'span', { class: 'sl-map__tdesc', text: ST[ k ][ 2 ] } ) ) );
				} );
				body.appendChild( h( 'div', { class: 'sl-map__overall' }, h( 'div', { class: 'sl-map__overallhead' }, h( 'b', { text: t( 'Your whole site at a glance' ) } ), h( 'span', { class: 'sl-muted sl-small', text: t( '%s URLs', SL.num( r.total, 0 ) ) } ) ), stack( r.counts, r.total ) ) );
				body.appendChild( tiles );
				var flags = h( 'div', { class: 'sl-chips sl-map__flags' } );
				Object.keys( FL ).forEach( function ( k ) {
					flags.appendChild( h( 'button', { type: 'button', class: 'sl-chip' + ( f.flag === k ? ' is-on' : '' ), onClick: function () {
						f.flag = f.flag === k ? '' : k;
						load();
					} }, FL[ k ] + ' · ' + SL.num( r.counts[ k ] || 0, 0 ) ) );
				} );
				body.appendChild( flags );
				var search = h( 'input', { class: 'sl-input', dir: 'auto', value: f.q, placeholder: t( 'Search address or title…' ) } );
				search.addEventListener( 'keydown', function ( e ) {
					if ( 'Enter' === e.key ) {
						f.q = search.value.trim();
						load();
					}
				} );
				body.appendChild( h( 'div', { class: 'sl-toolbar' }, search, ( f.status || f.flag || f.q ) ? App.btn( t( 'Clear filters' ), function () {
					f.status = '';
					f.flag = '';
					f.q = '';
					load();
				}, 'sm' ) : null ) );
				body.appendChild( SL.tabs( [
					{ label: t( 'Pages' ), render: function () {
						var b = h( 'div' );
						cards( b, f );
						return b;
					} },
					{ label: t( 'Overview picture' ), render: function () {
						var b = h( 'div' );
						pixelMap( b, f );
						return b;
					} },
					{ label: t( 'Folders' ), render: function () {
						var b = h( 'div' );
						folders( b, f );
						return b;
					} },
					{ label: t( 'Table' ), render: function () {
						var b = h( 'div' );
						list( b, f );
						return b;
					} },
					{ label: t( 'Daily trend' ), render: function () {
						var b = h( 'div' );
						trend( b, r.history );
						return b;
					} },
					{ label: t( 'XML sitemap health' ), render: function () {
						var b = h( 'div' );
						App.sitemapHealth( b );
						return b;
					} },
				] ) );
			} );
		}
		load();
	};
}( window, document ) );
