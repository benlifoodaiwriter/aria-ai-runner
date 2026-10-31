/**
 * SEOlity charts — tiny dependency-free SVG charts (line, bar, donut, sparkline).
 * Colors come from CSS variables so light/dark themes work automatically.
 */
( function ( w, d ) {
	'use strict';
	var NS = 'http://www.w3.org/2000/svg';
	var SL = ( w.SL = w.SL || {} );

	function el( tag, attrs, parent ) {
		var e = d.createElementNS( NS, tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			e.setAttribute( k, attrs[ k ] );
		} );
		if ( parent ) {
			parent.appendChild( e );
		}
		return e;
	}
	function fmt( n ) {
		if ( n === null || n === undefined || isNaN( n ) ) {
			return '—';
		}
		var a = Math.abs( n );
		if ( a >= 1e6 ) {
			return ( n / 1e6 ).toFixed( 1 ) + 'M';
		}
		if ( a >= 1e4 ) {
			return Math.round( n / 1e3 ) + 'k';
		}
		return SL.num ? SL.num( n ) : String( n );
	}
	function niceMax( v ) {
		if ( v <= 0 ) {
			return 1;
		}
		var p = Math.pow( 10, Math.floor( Math.log10( v ) ) );
		var n = v / p;
		return ( n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10 ) * p;
	}
	var PALETTE = [ 'var(--sl-c1)', 'var(--sl-c2)', 'var(--sl-c3)', 'var(--sl-c4)', 'var(--sl-c5)' ];

	/**
	 * Line chart.
	 * @param {Array} rows   objects with x key and series keys
	 * @param {Object} opts  { x: 'date', series: [{key, label, color, invert, axis:'right'}], height }
	 */
	function line( rows, opts ) {
		var W = 720;
		var H = opts.height || 240;
		var pad = { t: 16, r: 44, b: 28, l: 44 };
		var wrap = d.createElement( 'div' );
		wrap.className = 'sl-chart';
		var svg = el( 'svg', { viewBox: '0 0 ' + W + ' ' + H, role: 'img', 'aria-label': opts.label || 'chart', preserveAspectRatio: 'none' } );
		wrap.appendChild( svg );
		if ( ! rows || ! rows.length ) {
			var tx = el( 'text', { x: W / 2, y: H / 2, 'text-anchor': 'middle', class: 'sl-chart__empty' }, svg );
			tx.textContent = opts.emptyText || '—';
			return wrap;
		}
		var iw = W - pad.l - pad.r;
		var ih = H - pad.t - pad.b;
		var rtl = SL.config && SL.config.rtl;
		var xAt = function ( i ) {
			var p = rows.length === 1 ? 0.5 : i / ( rows.length - 1 );
			return pad.l + ( rtl ? 1 - p : p ) * iw;
		};
		var axes = { left: 0, right: 0 };
		opts.series.forEach( function ( s ) {
			var max = Math.max.apply( null, rows.map( function ( r ) {
				return +r[ s.key ] || 0;
			} ) );
			var ax = s.axis === 'right' ? 'right' : 'left';
			axes[ ax ] = Math.max( axes[ ax ], max );
		} );
		axes.left = niceMax( axes.left );
		axes.right = niceMax( axes.right );
		for ( var g = 0; g <= 4; g++ ) {
			var gy = pad.t + ( ih * g ) / 4;
			el( 'line', { x1: pad.l, x2: W - pad.r, y1: gy, y2: gy, class: 'sl-chart__grid' }, svg );
			var lab = el( 'text', { x: pad.l - 6, y: gy + 4, 'text-anchor': 'end', class: 'sl-chart__axis' }, svg );
			lab.textContent = fmt( axes.left * ( 1 - g / 4 ) );
			if ( opts.series.some( function ( s ) {
				return s.axis === 'right';
			} ) ) {
				var rl = el( 'text', { x: W - pad.r + 6, y: gy + 4, 'text-anchor': 'start', class: 'sl-chart__axis' }, svg );
				var rs = opts.series.filter( function ( s ) {
					return s.axis === 'right';
				} )[ 0 ];
				rl.textContent = rs.invert ? fmt( axes.right * ( g / 4 ) ) : fmt( axes.right * ( 1 - g / 4 ) );
			}
		}
		var step = Math.max( 1, Math.ceil( rows.length / 7 ) );
		rows.forEach( function ( r, i ) {
			if ( i % step === 0 || i === rows.length - 1 ) {
				var xl = el( 'text', { x: xAt( i ), y: H - 8, 'text-anchor': 'middle', class: 'sl-chart__axis' }, svg );
				xl.textContent = String( r[ opts.x ] ).slice( 5 );
			}
		} );
		opts.series.forEach( function ( s, si ) {
			var max = s.axis === 'right' ? axes.right : axes.left;
			var yAt = function ( v ) {
				var p = ( +v || 0 ) / max;
				return pad.t + ( s.invert ? p : 1 - p ) * ih;
			};
			var pts = rows.map( function ( r, i ) {
				return xAt( i ).toFixed( 1 ) + ',' + yAt( r[ s.key ] ).toFixed( 1 );
			} );
			var color = s.color || PALETTE[ si % PALETTE.length ];
			if ( si === 0 && ! s.invert ) {
				el( 'path', { d: 'M' + pts[ 0 ].split( ',' )[ 0 ] + ',' + ( pad.t + ih ) + ' L' + pts.join( ' L' ) + ' L' + pts[ pts.length - 1 ].split( ',' )[ 0 ] + ',' + ( pad.t + ih ) + ' Z', fill: color, class: 'sl-chart__area' }, svg );
			}
			el( 'polyline', { points: pts.join( ' ' ), fill: 'none', stroke: color, class: 'sl-chart__line' + ( s.dashed ? ' is-dashed' : '' ) }, svg );
		} );
		// Hover layer.
		var tip = d.createElement( 'div' );
		tip.className = 'sl-chart__tip';
		wrap.appendChild( tip );
		var guide = el( 'line', { y1: pad.t, y2: pad.t + ih, class: 'sl-chart__guide', x1: -10, x2: -10 }, svg );
		var hit = el( 'rect', { x: pad.l, y: pad.t, width: iw, height: ih, fill: 'transparent' }, svg );
		hit.addEventListener( 'mousemove', function ( e ) {
			var box = svg.getBoundingClientRect();
			var px = ( ( e.clientX - box.left ) / box.width ) * W;
			var p = ( px - pad.l ) / iw;
			p = rtl ? 1 - p : p;
			var i = Math.max( 0, Math.min( rows.length - 1, Math.round( p * ( rows.length - 1 ) ) ) );
			guide.setAttribute( 'x1', xAt( i ) );
			guide.setAttribute( 'x2', xAt( i ) );
			while ( tip.firstChild ) {
				tip.removeChild( tip.firstChild );
			}
			var head = d.createElement( 'strong' );
			head.textContent = rows[ i ][ opts.x ];
			tip.appendChild( head );
			opts.series.forEach( function ( s, si ) {
				var row = d.createElement( 'div' );
				var dot = d.createElement( 'span' );
				dot.className = 'sl-chart__dot';
				dot.style.background = s.color || PALETTE[ si % PALETTE.length ];
				row.appendChild( dot );
				row.appendChild( d.createTextNode( ' ' + s.label + ': ' + fmt( rows[ i ][ s.key ] ) ) );
				tip.appendChild( row );
			} );
			tip.style.display = 'block';
			tip.style.left = Math.min( box.width - 160, Math.max( 0, ( xAt( i ) / W ) * box.width + 10 ) ) + 'px';
		} );
		hit.addEventListener( 'mouseleave', function () {
			tip.style.display = 'none';
			guide.setAttribute( 'x1', -10 );
			guide.setAttribute( 'x2', -10 );
		} );
		var legend = d.createElement( 'div' );
		legend.className = 'sl-chart__legend';
		opts.series.forEach( function ( s, si ) {
			var item = d.createElement( 'span' );
			var dot = d.createElement( 'span' );
			dot.className = 'sl-chart__dot';
			dot.style.background = s.color || PALETTE[ si % PALETTE.length ];
			item.appendChild( dot );
			item.appendChild( d.createTextNode( ' ' + s.label ) );
			legend.appendChild( item );
		} );
		wrap.appendChild( legend );
		return wrap;
	}

	/** Horizontal bar list: [{label, value, sub}] */
	function bars( items, opts ) {
		opts = opts || {};
		var wrap = d.createElement( 'div' );
		wrap.className = 'sl-bars';
		var max = Math.max.apply( null, items.map( function ( i ) {
			return +i.value || 0;
		} ).concat( [ 1 ] ) );
		items.forEach( function ( it, idx ) {
			var row = d.createElement( 'div' );
			row.className = 'sl-bars__row';
			var label = d.createElement( 'span' );
			label.className = 'sl-bars__label';
			label.textContent = it.label;
			label.title = it.label;
			var track = d.createElement( 'span' );
			track.className = 'sl-bars__track';
			var fill = d.createElement( 'span' );
			fill.className = 'sl-bars__fill';
			fill.style.width = ( ( +it.value || 0 ) / max ) * 100 + '%';
			fill.style.background = it.color || PALETTE[ ( opts.colorBy ? idx : 0 ) % PALETTE.length ];
			track.appendChild( fill );
			var val = d.createElement( 'span' );
			val.className = 'sl-bars__val';
			val.textContent = fmt( it.value ) + ( it.sub ? ' ' + it.sub : '' );
			row.appendChild( label );
			row.appendChild( track );
			row.appendChild( val );
			wrap.appendChild( row );
		} );
		return wrap;
	}

	/** Vertical columns: {labels:[], values:[]} */
	function columns( labels, values, opts ) {
		opts = opts || {};
		var W = 360;
		var H = opts.height || 180;
		var pad = 24;
		var wrap = d.createElement( 'div' );
		wrap.className = 'sl-chart';
		var svg = el( 'svg', { viewBox: '0 0 ' + W + ' ' + H, role: 'img', 'aria-label': opts.label || 'chart' } );
		wrap.appendChild( svg );
		var max = niceMax( Math.max.apply( null, values.concat( [ 1 ] ) ) );
		var bw = ( W - pad * 2 ) / labels.length;
		labels.forEach( function ( l, i ) {
			var hgt = ( ( values[ i ] || 0 ) / max ) * ( H - pad * 2 );
			var x = pad + i * bw + bw * 0.15;
			el( 'rect', { x: x, y: H - pad - hgt, width: bw * 0.7, height: Math.max( 1, hgt ), rx: 4, fill: opts.colors ? opts.colors[ i ] : PALETTE[ i % PALETTE.length ] }, svg );
			var vt = el( 'text', { x: x + bw * 0.35, y: H - pad - hgt - 4, 'text-anchor': 'middle', class: 'sl-chart__axis' }, svg );
			vt.textContent = fmt( values[ i ] );
			var lt = el( 'text', { x: x + bw * 0.35, y: H - 6, 'text-anchor': 'middle', class: 'sl-chart__axis' }, svg );
			lt.textContent = l;
		} );
		return wrap;
	}

	/** Donut: [{label, value, color}] */
	function donut( items, opts ) {
		opts = opts || {};
		var size = opts.size || 160;
		var r = size / 2 - 14;
		var c = 2 * Math.PI * r;
		var total = items.reduce( function ( s, i ) {
			return s + ( +i.value || 0 );
		}, 0 ) || 1;
		var wrap = d.createElement( 'div' );
		wrap.className = 'sl-donut';
		var svg = el( 'svg', { width: size, height: size, viewBox: '0 0 ' + size + ' ' + size, role: 'img', 'aria-label': opts.label || 'chart' } );
		var off = 0;
		items.forEach( function ( it, i ) {
			var frac = ( +it.value || 0 ) / total;
			el( 'circle', { cx: size / 2, cy: size / 2, r: r, fill: 'none', stroke: it.color || PALETTE[ i % PALETTE.length ], 'stroke-width': 18, 'stroke-dasharray': frac * c + ' ' + c, 'stroke-dashoffset': -off * c, transform: 'rotate(-90 ' + size / 2 + ' ' + size / 2 + ')' }, svg );
			off += frac;
		} );
		var tt = el( 'text', { x: '50%', y: '50%', 'text-anchor': 'middle', 'dominant-baseline': 'central', class: 'sl-donut__total' }, svg );
		tt.textContent = opts.center !== undefined ? opts.center : fmt( total );
		wrap.appendChild( svg );
		var legend = d.createElement( 'div' );
		legend.className = 'sl-chart__legend sl-chart__legend--col';
		items.forEach( function ( it, i ) {
			var item = d.createElement( 'span' );
			var dot = d.createElement( 'span' );
			dot.className = 'sl-chart__dot';
			dot.style.background = it.color || PALETTE[ i % PALETTE.length ];
			item.appendChild( dot );
			item.appendChild( d.createTextNode( ' ' + it.label + ' — ' + fmt( it.value ) ) );
			legend.appendChild( item );
		} );
		wrap.appendChild( legend );
		return wrap;
	}

	function sparkline( values, opts ) {
		opts = opts || {};
		var W = opts.width || 120;
		var H = opts.height || 32;
		var svg = el( 'svg', { width: W, height: H, viewBox: '0 0 ' + W + ' ' + H, class: 'sl-spark', 'aria-hidden': 'true' } );
		if ( ! values || values.length < 2 ) {
			return svg;
		}
		var max = Math.max.apply( null, values );
		var min = Math.min.apply( null, values );
		var rng = max - min || 1;
		var pts = values.map( function ( v, i ) {
			var y = opts.invert ? ( ( v - min ) / rng ) * ( H - 4 ) + 2 : H - 2 - ( ( v - min ) / rng ) * ( H - 4 );
			return ( ( i / ( values.length - 1 ) ) * W ).toFixed( 1 ) + ',' + y.toFixed( 1 );
		} );
		el( 'polyline', { points: pts.join( ' ' ), fill: 'none', stroke: opts.color || 'var(--sl-brand)', 'stroke-width': 2 }, svg );
		return svg;
	}

	SL.charts = { line: line, bars: bars, columns: columns, donut: donut, sparkline: sparkline, fmt: fmt };
} )( window, document );
