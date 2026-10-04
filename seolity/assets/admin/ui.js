/**
 * SEOlity UI toolkit (no build step, no framework).
 * Shared by the admin SPA and all editor integrations.
 *
 * Security: all dynamic text goes through text nodes (never innerHTML);
 * AI HTML previews render inside a sandboxed iframe without scripts.
 */
( function ( w, d ) {
	'use strict';

	var C = w.SEOLITY || {};
	var SL = ( w.SL = w.SL || {} );
	SL.config = C;

	/* ------------------------------------------------------------------ i18n */
	SL.t = function ( s ) {
		var dict = C.i18n || {};
		var out = Object.prototype.hasOwnProperty.call( dict, s ) && dict[ s ] ? dict[ s ] : s;
		for ( var i = 1; i < arguments.length; i++ ) {
			out = out.replace( '%s', arguments[ i ] ).replace( '%' + i + '$s', arguments[ i ] ).replace( '%d', arguments[ i ] );
		}
		return out;
	};
	var t = SL.t;
	var locale = ( C.locale || 'en' ).replace( '_', '-' );
	SL.num = function ( n, digits ) {
		if ( n === null || n === undefined || n === '' || isNaN( n ) ) {
			return '—';
		}
		try {
			return Number( n ).toLocaleString( locale, { maximumFractionDigits: digits === undefined ? 1 : digits } );
		} catch ( e ) {
			return String( n );
		}
	};
	SL.pct = function ( n ) {
		return n === null || n === undefined ? '—' : ( n > 0 ? '+' : '' ) + SL.num( n ) + '%';
	};
	SL.date = function ( s ) {
		if ( ! s ) {
			return '—';
		}
		var dt = new Date( String( s ).replace( ' ', 'T' ) + ( /Z|\+/.test( s ) || s.length <= 10 ? '' : 'Z' ) );
		try {
			return dt.toLocaleDateString( locale, { year: 'numeric', month: 'short', day: 'numeric' } );
		} catch ( e ) {
			return s;
		}
	};
	SL.path = function ( url ) {
		try {
			var u = new URL( url );
			return decodeURIComponent( u.pathname + u.search ) || '/';
		} catch ( e ) {
			return url || '';
		}
	};

	/* ------------------------------------------------------------------ DOM */
	SL.h = function h( tag, attrs ) {
		var el = d.createElement( tag );
		attrs = attrs || {};
		Object.keys( attrs ).forEach( function ( k ) {
			var v = attrs[ k ];
			if ( v === null || v === undefined || v === false ) {
				return;
			}
			if ( k === 'class' ) {
				el.className = v;
			} else if ( k === 'style' && typeof v === 'object' ) {
				Object.keys( v ).forEach( function ( p ) {
					el.style.setProperty( p, v[ p ] );
				} );
			} else if ( k.indexOf( 'on' ) === 0 && typeof v === 'function' ) {
				el.addEventListener( k.slice( 2 ).toLowerCase(), v );
			} else if ( k === 'text' ) {
				el.textContent = v;
			} else if ( k === 'value' ) {
				el.value = v;
			} else if ( v === true ) {
				el.setAttribute( k, '' );
			} else {
				el.setAttribute( k, v );
			}
		} );
		for ( var i = 2; i < arguments.length; i++ ) {
			SL.append( el, arguments[ i ] );
		}
		return el;
	};
	SL.append = function ( el, child ) {
		if ( child === null || child === undefined || child === false ) {
			return el;
		}
		if ( Array.isArray( child ) ) {
			child.forEach( function ( c ) {
				SL.append( el, c );
			} );
		} else if ( child instanceof Node ) {
			el.appendChild( child );
		} else {
			el.appendChild( d.createTextNode( String( child ) ) );
		}
		return el;
	};
	SL.clear = function ( el ) {
		while ( el && el.firstChild ) {
			el.removeChild( el.firstChild );
		}
		return el;
	};
	var h = SL.h;

	/* ------------------------------------------------------------------ API */
	SL.api = function ( path, opts ) {
		opts = opts || {};
		var method = opts.method || ( opts.body ? 'POST' : 'GET' );
		var url = ( C.root || '/wp-json/seolity/v1/' ) + path.replace( /^\//, '' );
		if ( opts.query ) {
			var q = Object.keys( opts.query ).filter( function ( k ) {
				return opts.query[ k ] !== '' && opts.query[ k ] !== null && opts.query[ k ] !== undefined;
			} ).map( function ( k ) {
				return encodeURIComponent( k ) + '=' + encodeURIComponent( opts.query[ k ] );
			} ).join( '&' );
			if ( q ) {
				url += ( url.indexOf( '?' ) > -1 ? '&' : '?' ) + q;
			}
		}
		return fetch( url, {
			method: method,
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': C.nonce },
			body: opts.body ? JSON.stringify( opts.body ) : undefined,
		} ).then( function ( res ) {
			return res.text().then( function ( txt ) {
				var data = null;
				try {
					data = txt ? JSON.parse( txt ) : null;
				} catch ( e ) {
					data = { message: txt.slice( 0, 300 ) };
				}
				if ( ! res.ok ) {
					var err = new Error( ( data && data.message ) || res.statusText );
					err.status = res.status;
					err.data = data;
					throw err;
				}
				return data;
			} );
		} );
	};

	/* ------------------------------------------------------------------ Toasts */
	var toastBox;
	SL.toast = function ( msg, type ) {
		if ( ! toastBox ) {
			toastBox = h( 'div', { class: 'sl-toasts', role: 'status', 'aria-live': 'polite' } );
			d.body.appendChild( toastBox );
		}
		var el = h( 'div', { class: 'sl-toast sl-toast--' + ( type || 'info' ) }, msg );
		toastBox.appendChild( el );
		setTimeout( function () {
			el.classList.add( 'is-out' );
			setTimeout( function () {
				el.remove();
			}, 300 );
		}, type === 'error' ? 7000 : 3500 );
	};
	SL.fail = function ( e ) {
		SL.toast( ( e && e.message ) || t( 'Something went wrong.' ), 'error' );
	};

	/* ------------------------------------------------------------------ Modal */
	SL.modal = function ( opts ) {
		var prev = d.activeElement;
		var close;
		var overlay = h( 'div', { class: 'sl-modal-overlay sl-root' + ( C.rtl ? ' is-rtl' : '' ), role: 'presentation' } );
		var box = h( 'div', { class: 'sl-modal' + ( opts.wide ? ' sl-modal--wide' : '' ), role: 'dialog', 'aria-modal': 'true', 'aria-label': opts.title || '' } );
		var body = h( 'div', { class: 'sl-modal__body' } );
		var foot = h( 'div', { class: 'sl-modal__foot' } );
		box.appendChild( h( 'div', { class: 'sl-modal__head' }, h( 'h2', { text: opts.title || '' } ), h( 'button', { class: 'sl-icon-btn', 'aria-label': t( 'Close' ), onClick: function () {
			close();
		} }, '✕' ) ) );
		box.appendChild( body );
		box.appendChild( foot );
		overlay.appendChild( box );
		applyTheme( overlay );
		close = function () {
			overlay.remove();
			d.removeEventListener( 'keydown', onKey );
			if ( prev && prev.focus ) {
				prev.focus();
			}
			if ( opts.onClose ) {
				opts.onClose();
			}
		};
		function onKey( e ) {
			if ( e.key === 'Escape' ) {
				close();
			}
		}
		d.addEventListener( 'keydown', onKey );
		overlay.addEventListener( 'click', function ( e ) {
			if ( e.target === overlay ) {
				close();
			}
		} );
		d.body.appendChild( overlay );
		setTimeout( function () {
			var f = box.querySelector( 'button, input, textarea, select' );
			if ( f ) {
				f.focus();
			}
		}, 30 );
		return { body: body, foot: foot, close: close, box: box };
	};

	SL.confirm = function ( message, onYes, danger ) {
		var m = SL.modal( { title: t( 'Please confirm' ) } );
		m.body.appendChild( h( 'p', { text: message } ) );
		m.foot.appendChild( h( 'button', { class: 'sl-btn', onClick: m.close }, t( 'Cancel' ) ) );
		m.foot.appendChild( h( 'button', { class: 'sl-btn ' + ( danger ? 'sl-btn--danger' : 'sl-btn--primary' ), onClick: function () {
			m.close();
			onYes();
		} }, t( 'Confirm' ) ) );
	};

	/* ------------------------------------------------------------------ Theme */
	function applyTheme( el ) {
		var pref = 'auto';
		try {
			pref = w.localStorage.getItem( 'seolity-theme' ) || 'auto';
		} catch ( e ) {}
		var dark = pref === 'dark' || ( pref === 'auto' && w.matchMedia && w.matchMedia( '(prefers-color-scheme: dark)' ).matches );
		el.setAttribute( 'data-theme', dark ? 'dark' : 'light' );
		if ( C.brand && /^#[0-9a-f]{3,8}$/i.test( C.brand.color || '' ) ) {
			el.style.setProperty( '--sl-brand', C.brand.color );
		}
	}
	SL.applyTheme = applyTheme;

	/* ------------------------------------------------------------------ Small components */
	SL.spinner = function ( label ) {
		return h( 'div', { class: 'sl-loading', role: 'status' }, h( 'span', { class: 'sl-spinner' } ), h( 'span', { text: label || t( 'Loading…' ) } ) );
	};
	SL.empty = function ( title, text, action ) {
		return h( 'div', { class: 'sl-empty' }, h( 'div', { class: 'sl-empty__icon', 'aria-hidden': 'true' }, '◎' ), h( 'h3', { text: title } ), text ? h( 'p', { text: text } ) : null, action || null );
	};
	SL.badge = function ( text, kind ) {
		return h( 'span', { class: 'sl-badge sl-badge--' + ( kind || 'default' ) }, text );
	};
	SL.priorityBadge = function ( p ) {
		var map = { critical: [ '🔴', t( 'Critical' ) ], high: [ '🟠', t( 'High' ) ], medium: [ '🟡', t( 'Medium' ) ], low: [ '🟢', t( 'Low' ) ], info: [ '🔵', t( 'Info' ) ] };
		var m = map[ p ] || [ '', p ];
		return h( 'span', { class: 'sl-badge sl-badge--' + p }, m[ 0 ] + ' ' + m[ 1 ] );
	};
	SL.scoreRing = function ( score, size, label ) {
		size = size || 64;
		var r = size / 2 - 5;
		var c = 2 * Math.PI * r;
		var v = score === null || score === undefined ? 0 : Math.max( 0, Math.min( 100, score ) );
		var color = score === null || score === undefined ? 'var(--sl-muted)' : v >= 80 ? 'var(--sl-good)' : v >= 55 ? 'var(--sl-warn)' : 'var(--sl-bad)';
		var ns = 'http://www.w3.org/2000/svg';
		var svg = d.createElementNS( ns, 'svg' );
		svg.setAttribute( 'width', size );
		svg.setAttribute( 'height', size );
		svg.setAttribute( 'viewBox', '0 0 ' + size + ' ' + size );
		svg.setAttribute( 'role', 'img' );
		svg.setAttribute( 'aria-label', ( label || t( 'Score' ) ) + ': ' + ( score === null || score === undefined ? '—' : score ) );
		var bg = d.createElementNS( ns, 'circle' );
		bg.setAttribute( 'cx', size / 2 );
		bg.setAttribute( 'cy', size / 2 );
		bg.setAttribute( 'r', r );
		bg.setAttribute( 'class', 'sl-ring-bg' );
		var fg = d.createElementNS( ns, 'circle' );
		fg.setAttribute( 'cx', size / 2 );
		fg.setAttribute( 'cy', size / 2 );
		fg.setAttribute( 'r', r );
		fg.setAttribute( 'stroke', color );
		fg.setAttribute( 'class', 'sl-ring-fg' );
		fg.setAttribute( 'stroke-dasharray', c );
		fg.setAttribute( 'stroke-dashoffset', c * ( 1 - v / 100 ) );
		fg.setAttribute( 'transform', 'rotate(-90 ' + size / 2 + ' ' + size / 2 + ')' );
		var tx = d.createElementNS( ns, 'text' );
		tx.setAttribute( 'x', '50%' );
		tx.setAttribute( 'y', '50%' );
		tx.setAttribute( 'dominant-baseline', 'central' );
		tx.setAttribute( 'text-anchor', 'middle' );
		tx.setAttribute( 'class', 'sl-ring-text' );
		tx.setAttribute( 'font-size', Math.round( size / 3.6 ) );
		tx.textContent = score === null || score === undefined ? '—' : SL.num( score, 0 );
		svg.appendChild( bg );
		svg.appendChild( fg );
		svg.appendChild( tx );
		return h( 'span', { class: 'sl-ring' }, svg );
	};
	SL.meter = function ( label, before, after ) {
		function bar( v, cls ) {
			return h( 'span', { class: 'sl-meter__bar ' + cls, style: { width: ( v === null || v === undefined ? 0 : v ) + '%' } } );
		}
		return h( 'div', { class: 'sl-meter' },
			h( 'div', { class: 'sl-meter__label' }, h( 'span', { text: label } ), h( 'span', { class: 'sl-meter__vals' }, ( before === null || before === undefined ? '—' : SL.num( before, 0 ) ) + ( after !== undefined ? ' → ' + ( after === null ? '—' : SL.num( after, 0 ) ) : '' ) ) ),
			h( 'div', { class: 'sl-meter__track' }, bar( before, 'is-before' ), after !== undefined ? bar( after, 'is-after' ) : null )
		);
	};
	SL.chips = function ( items, kind ) {
		return h( 'div', { class: 'sl-chips' }, ( items || [] ).filter( Boolean ).map( function ( x ) {
			return h( 'span', { class: 'sl-chip' + ( kind ? ' sl-chip--' + kind : '' ) }, x );
		} ) );
	};
	SL.tabs = function ( tabs, onChange ) {
		var wrap = h( 'div', { class: 'sl-tabs', role: 'tablist' } );
		var panel = h( 'div', { class: 'sl-tabpanel', role: 'tabpanel' } );
		function select( i ) {
			Array.prototype.forEach.call( wrap.children, function ( b, j ) {
				b.setAttribute( 'aria-selected', i === j ? 'true' : 'false' );
				b.classList.toggle( 'is-active', i === j );
			} );
			SL.clear( panel );
			var c = tabs[ i ].render();
			SL.append( panel, c );
			if ( onChange ) {
				onChange( i );
			}
		}
		tabs.forEach( function ( tab, i ) {
			wrap.appendChild( h( 'button', { class: 'sl-tab', role: 'tab', type: 'button', onClick: function () {
				select( i );
			} }, tab.label ) );
		} );
		setTimeout( function () {
			select( 0 );
		}, 0 );
		return h( 'div', null, wrap, panel );
	};
	SL.iframePreview = function ( html ) {
		var css = 'body{font-family:Vazirmatn,Tahoma,system-ui,sans-serif;line-height:1.8;padding:12px;color:#1f2937;' + ( C.rtl ? 'direction:rtl;' : '' ) + '}img{max-width:100%}table{border-collapse:collapse}td,th{border:1px solid #ddd;padding:4px 8px}';
		return h( 'iframe', { class: 'sl-preview', sandbox: '', title: t( 'Preview' ), srcdoc: '<!doctype html><meta charset="utf-8"><style>' + css + '</style>' + ( html || '' ) } );
	};

	/* ------------------------------------------------------------------ Safe mini-markdown */
	SL.markdown = function ( md ) {
		var root = h( 'div', { class: 'sl-md' } );
		var lines = String( md || '' ).split( /\n/ );
		var list = null;
		function inline( text, parent ) {
			var re = /(\*\*[^*]+\*\*|`[^`]+`|\[[^\]]+\]\((https?:\/\/[^\s)]+|#\/[^\s)]*)\))/g;
			var last = 0;
			var m;
			while ( ( m = re.exec( text ) ) ) {
				parent.appendChild( d.createTextNode( text.slice( last, m.index ) ) );
				var tok = m[ 0 ];
				if ( tok.indexOf( '**' ) === 0 ) {
					parent.appendChild( h( 'strong', { text: tok.slice( 2, -2 ) } ) );
				} else if ( tok.indexOf( '`' ) === 0 ) {
					parent.appendChild( h( 'code', { text: tok.slice( 1, -1 ) } ) );
				} else {
					var lm = /\[([^\]]+)\]\(([^)]+)\)/.exec( tok );
					parent.appendChild( h( 'a', { href: lm[ 2 ], target: lm[ 2 ].indexOf( '#' ) === 0 ? null : '_blank', rel: 'noopener noreferrer', text: lm[ 1 ] } ) );
				}
				last = m.index + tok.length;
			}
			parent.appendChild( d.createTextNode( text.slice( last ) ) );
			return parent;
		}
		lines.forEach( function ( line ) {
			var lm = /^\s*(?:[-*]|\d+[.)])\s+(.*)$/.exec( line );
			if ( lm ) {
				if ( ! list ) {
					list = h( /^\s*\d/.test( line ) ? 'ol' : 'ul' );
					root.appendChild( list );
				}
				list.appendChild( inline( lm[ 1 ], h( 'li' ) ) );
				return;
			}
			list = null;
			var hm = /^(#{1,4})\s+(.*)$/.exec( line );
			if ( hm ) {
				root.appendChild( inline( hm[ 2 ], h( 'h' + Math.min( 6, hm[ 1 ].length + 2 ) ) ) );
			} else if ( line.trim() ) {
				root.appendChild( inline( line, h( 'p' ) ) );
			}
		} );
		return root;
	};

	/* ------------------------------------------------------------------ Diff (Myers, word level) */
	SL.tokens = function ( text ) {
		return String( text || '' ).match( /\s+|[^\s]+/g ) || [];
	};
	SL.stripHtml = function ( html ) {
		var doc = new DOMParser().parseFromString( '<body>' + ( html || '' ).replace( /<\/(p|h[1-6]|li|div|tr)>/gi, '\n$&' ) + '</body>', 'text/html' );
		return ( doc.body.textContent || '' ).replace( /\n{3,}/g, '\n\n' ).trim();
	};
	function myers( a, b, cap ) {
		var n = a.length;
		var m = b.length;
		var v = { 1: 0 };
		var trace = [];
		var found = false;
		for ( var D = 0; D <= n + m && ! found; D++ ) {
			if ( D > cap ) {
				return null;
			}
			trace.push( Object.assign( {}, v ) );
			for ( var k = -D; k <= D; k += 2 ) {
				var x = k === -D || ( k !== D && v[ k - 1 ] < v[ k + 1 ] ) ? v[ k + 1 ] : v[ k - 1 ] + 1;
				var y = x - k;
				while ( x < n && y < m && a[ x ] === b[ y ] ) {
					x++;
					y++;
				}
				v[ k ] = x;
				if ( x >= n && y >= m ) {
					found = true;
					break;
				}
			}
		}
		var ops = [];
		var x2 = n;
		var y2 = m;
		for ( var D2 = trace.length - 1; D2 >= 0; D2-- ) {
			var vv = trace[ D2 ];
			var k2 = x2 - y2;
			var prevK = k2 === -D2 || ( k2 !== D2 && vv[ k2 - 1 ] < vv[ k2 + 1 ] ) ? k2 + 1 : k2 - 1;
			var px = vv[ prevK ] || 0;
			var py = px - prevK;
			while ( x2 > px && y2 > py ) {
				ops.push( { op: 'eq', text: a[ x2 - 1 ] } );
				x2--;
				y2--;
			}
			if ( D2 > 0 ) {
				if ( x2 === px ) {
					ops.push( { op: 'ins', text: b[ y2 - 1 ] } );
				} else {
					ops.push( { op: 'del', text: a[ x2 - 1 ] } );
				}
			}
			x2 = px;
			y2 = py;
		}
		return ops.reverse();
	}
	SL.diff = function ( a, b ) {
		// Word-level first; for heavy rewrites fall back to line-level to bound memory.
		var ops = myers( SL.tokens( a ), SL.tokens( b ), 1500 );
		if ( ! ops ) {
			var la = String( a || '' ).split( /(\n)/ );
			var lb = String( b || '' ).split( /(\n)/ );
			ops = myers( la, lb, 3000 ) || [ { op: 'del', text: a }, { op: 'ins', text: b } ];
		}
		var merged = [];
		ops.forEach( function ( o ) {
			var last = merged[ merged.length - 1 ];
			if ( last && last.op === o.op ) {
				last.text += o.text;
			} else {
				merged.push( { op: o.op, text: o.text } );
			}
		} );
		return merged;
	};
	SL.diffView = function ( before, after, isHtml ) {
		var a = isHtml ? SL.stripHtml( before ) : String( before || '' );
		var b = isHtml ? SL.stripHtml( after ) : String( after || '' );
		var box = h( 'div', { class: 'sl-diff', dir: 'auto' } );
		SL.diff( a, b ).forEach( function ( o ) {
			box.appendChild( o.op === 'eq' ? d.createTextNode( o.text ) : h( o.op === 'ins' ? 'ins' : 'del', { text: o.text } ) );
		} );
		return box;
	};

	/* ------------------------------------------------------------------ Change preview (Safe Mode) */
	var FIELD_LABELS = {
		post_content: 'Content', post_excerpt: 'Short description', post_title: 'Title', post_name: 'URL slug', meta_title: 'SEO title',
		meta_description: 'Meta description', focus_keyword: 'Focus keyword', faq_schema: 'FAQ', alt: 'Image ALT', image_title: 'Image title',
		caption: 'Caption', term_description: 'Category description', term_name: 'Name', user_description: 'Author bio', noindex: 'Noindex',
		nofollow: 'Nofollow', canonical: 'Canonical URL', product_tags: 'Product tags', robots_txt: 'robots.txt',
	};
	SL.fieldLabel = function ( f ) {
		return t( FIELD_LABELS[ f ] || f );
	};
	SL.preview = function ( changeOrId, onDone ) {
		var load = typeof changeOrId === 'object' ? Promise.resolve( changeOrId ) : SL.api( 'changes/' + changeOrId );
		var m = SL.modal( { title: t( 'Preview change' ), wide: true } );
		m.body.appendChild( SL.spinner() );
		load.then( function ( c ) {
			SL.clear( m.body );
			var isHtml = /content|description|excerpt/.test( c.field ) && /<[a-z]/i.test( ( c.after_value || '' ) + ( c.before_value || '' ) );
			var editor = h( 'textarea', { class: 'sl-input sl-textarea', rows: 10, dir: 'auto' } );
			editor.value = c.after_value || '';
			m.body.appendChild( h( 'div', { class: 'sl-row sl-row--between' },
				h( 'div', null, h( 'strong', { text: SL.fieldLabel( c.field ) } ), ' · ', h( 'span', { class: 'sl-muted', text: c.url ? SL.path( c.url ) : '' } ) ),
				h( 'div', null, SL.badge( t( 'Risk' ) + ': ' + t( c.risk ), c.risk === 'high' ? 'bad' : c.risk === 'medium' ? 'warn' : 'good' ), ' ', SL.badge( t( c.status ) ) )
			) );
			if ( c.reason ) {
				m.body.appendChild( h( 'p', { class: 'sl-reason', text: c.reason } ) );
			}
			m.body.appendChild( SL.tabs( [
				{ label: t( 'Before / After' ), render: function () {
					return h( 'div', { class: 'sl-grid-2' },
						h( 'div', { class: 'sl-panel' }, h( 'div', { class: 'sl-panel__title', text: t( 'BEFORE' ) } ), isHtml ? SL.iframePreview( c.before_value ) : h( 'div', { class: 'sl-pre', dir: 'auto', text: c.before_value || '—' } ) ),
						h( 'div', { class: 'sl-panel sl-panel--after' }, h( 'div', { class: 'sl-panel__title', text: t( 'AFTER' ) } ), isHtml ? SL.iframePreview( editor.value ) : h( 'div', { class: 'sl-pre', dir: 'auto', text: editor.value || '—' } ) )
					);
				} },
				{ label: t( 'Compare' ), render: function () {
					return SL.diffView( c.before_value, editor.value, isHtml );
				} },
				{ label: t( 'Edit' ), render: function () {
					return editor;
				} },
			] ) );
			if ( c.score_before !== null && c.score_after !== null && c.score_before !== undefined ) {
				m.body.appendChild( h( 'div', { class: 'sl-row' }, t( 'SEO score' ), ' ', SL.scoreRing( +c.score_before, 44 ), ' → ', SL.scoreRing( +c.score_after, 44 ) ) );
			}
			if ( c.ai_model ) {
				m.body.appendChild( h( 'p', { class: 'sl-muted sl-small', text: t( 'Generated by' ) + ' ' + c.ai_model + ' · ' + ( c.prompt_version || '' ) } ) );
			}
			var busy = function ( on ) {
				Array.prototype.forEach.call( m.foot.querySelectorAll( 'button' ), function ( b ) {
					b.disabled = on;
				} );
			};
			if ( c.status === 'pending' ) {
				m.foot.appendChild( h( 'button', { class: 'sl-btn', onClick: function () {
					busy( true );
					SL.api( 'changes/' + c.id + '/reject', { method: 'POST' } ).then( function () {
						SL.toast( t( 'Change rejected.' ) );
						m.close();
						if ( onDone ) {
							onDone( 'rejected' );
						}
					} ).catch( function ( e ) {
						busy( false );
						SL.fail( e );
					} );
				} }, t( 'Reject' ) ) );
				m.foot.appendChild( h( 'button', { class: 'sl-btn sl-btn--primary', onClick: function () {
					busy( true );
					var edited = editor.value !== c.after_value ? editor.value : null;
					SL.api( 'changes/' + c.id + '/apply', { method: 'POST', body: edited === null ? {} : { value: edited } } ).then( function () {
						SL.toast( t( 'Change applied. You can undo it anytime from version history.' ), 'success' );
						m.close();
						if ( onDone ) {
							onDone( 'applied' );
						}
					} ).catch( function ( e ) {
						busy( false );
						SL.fail( e );
					} );
				} }, t( 'Apply' ) ) );
			} else if ( c.status === 'applied' ) {
				m.foot.appendChild( h( 'button', { class: 'sl-btn sl-btn--danger', onClick: function () {
					busy( true );
					SL.api( 'changes/' + c.id + '/revert', { method: 'POST' } ).then( function () {
						SL.toast( t( 'Change reverted.' ), 'success' );
						m.close();
						if ( onDone ) {
							onDone( 'reverted' );
						}
					} ).catch( function ( e ) {
						busy( false );
						SL.fail( e );
					} );
				} }, t( 'Undo' ) ) );
			}
		} ).catch( function ( e ) {
			m.close();
			SL.fail( e );
		} );
	};

	SL.history = function ( type, id ) {
		var m = SL.modal( { title: t( 'Version history' ), wide: true } );
		m.body.appendChild( SL.spinner() );
		SL.api( 'history', { query: { object_type: type, object_id: id } } ).then( function ( rows ) {
			SL.clear( m.body );
			if ( ! rows.length ) {
				m.body.appendChild( SL.empty( t( 'No changes yet' ), t( 'Every change SEOlity makes is versioned here and can be restored.' ) ) );
				return;
			}
			var tbl = h( 'table', { class: 'sl-table' }, h( 'thead', null, h( 'tr', null, [ t( 'Version' ), t( 'Field' ), t( 'Status' ), t( 'Source' ), t( 'Date' ), '' ].map( function ( x ) {
				return h( 'th', { text: x } );
			} ) ) ) );
			var tb = h( 'tbody' );
			rows.forEach( function ( r ) {
				tb.appendChild( h( 'tr', null,
					h( 'td', { text: r.status === 'applied' || r.status === 'reverted' ? 'v' + r.version : '—' } ),
					h( 'td', { text: SL.fieldLabel( r.field ) } ),
					h( 'td', null, SL.badge( t( r.status ), r.status === 'applied' ? 'good' : r.status === 'pending' ? 'warn' : 'default' ) ),
					h( 'td', { text: t( r.source ) } ),
					h( 'td', { text: SL.date( r.applied_at || r.created_at ) } ),
					h( 'td', null,
						h( 'button', { class: 'sl-btn sl-btn--sm', onClick: function () {
							SL.preview( r.id, function () {
								m.close();
							} );
						} }, t( 'View' ) ),
						r.status === 'applied' || r.status === 'reverted' ? h( 'button', { class: 'sl-btn sl-btn--sm', onClick: function () {
							SL.confirm( t( 'Restore this version as the current value?' ), function () {
								SL.api( 'changes/' + r.id + '/restore', { method: 'POST' } ).then( function () {
									SL.toast( t( 'Version restored.' ), 'success' );
									m.close();
								} ).catch( SL.fail );
							} );
						} }, t( 'Restore' ) ) : null
					)
				) );
			} );
			tbl.appendChild( tb );
			m.body.appendChild( tbl );
		} ).catch( SL.fail );
	};

	/* ------------------------------------------------------------------ Why? */
	SL.why = function ( title, data ) {
		var m = SL.modal( { title: t( 'Why?' ) + ' — ' + title, wide: true } );
		function section( label, content ) {
			return h( 'div', { class: 'sl-why__sec' }, h( 'h4', { text: label } ), content );
		}
		var p = data.then ? data : Promise.resolve( data );
		m.body.appendChild( SL.spinner() );
		p.then( function ( x ) {
			SL.clear( m.body );
			m.body.appendChild( section( t( 'What did SEOlity find?' ), h( 'p', { text: x.found || '' } ) ) );
			m.body.appendChild( section( t( 'Why is this suggested?' ), h( 'p', { text: x.why || '' } ) ) );
			m.body.appendChild( section( t( 'Expected result' ), h( 'p', { text: x.expected || '' } ) ) );
			m.body.appendChild( section( t( 'Confidence' ), h( 'div', null, SL.meter( t( 'Confidence' ), x.confidence ) ) ) );
			if ( x.scores ) {
				m.body.appendChild( section( t( 'Scores' ), SL.chips( [ t( 'Impact' ) + ': ' + x.scores.impact, t( 'Effort' ) + ': ' + x.scores.effort, t( 'Confidence' ) + ': ' + x.scores.confidence ] ) ) );
			}
			if ( x.history && x.history.length ) {
				m.body.appendChild( section( t( 'What worked on this site before' ), h( 'ul', null, x.history.map( function ( i ) {
					return h( 'li', { text: i.text } );
				} ) ) ) );
			}
			m.body.appendChild( section( t( 'Data used' ), h( 'pre', { class: 'sl-json', dir: 'ltr', text: JSON.stringify( x.data || {}, null, 2 ) } ) ) );
		} ).catch( function ( e ) {
			m.close();
			SL.fail( e );
		} );
	};

	/* ------------------------------------------------------------------ AI Optimizer modal */
	SL.optimizer = function ( opts ) {
		// opts: object_type, object_id, field, getContent(), setContent(html) [optional, editor mode], title, focus_keyword
		var m = SL.modal( { title: '✨ ' + t( 'Optimize with SEOlity AI' ), wide: true } );
		var kw = h( 'input', { class: 'sl-input', type: 'text', placeholder: t( 'Focus keyword (optional — SEOlity can pick it from Search Console)' ), value: opts.focus_keyword || '' } );
		var goal = h( 'input', { class: 'sl-input', type: 'text', placeholder: t( 'Goal (optional), e.g. rank for “buy espresso machine”, improve conversions…' ) } );
		var start = h( 'button', { class: 'sl-btn sl-btn--primary sl-btn--lg', onClick: run }, '✨ ' + t( 'Analyze & optimize' ) );
		m.body.appendChild( h( 'div', { class: 'sl-stack' }, h( 'p', { class: 'sl-muted', text: t( 'SEOlity analyzes your text, the real queries you rank for and SEO best practices, then proposes an improved version. Nothing changes until you apply it.' ) } ), kw, goal, start ) );

		function run() {
			var content = opts.getContent ? opts.getContent() : null;
			SL.clear( m.body );
			var steps = h( 'ol', { class: 'sl-steps' }, [ t( 'Analyzing current content…' ), t( 'Reading Search Console queries…' ), t( 'Consulting the SEO knowledge base…' ), t( 'Writing the optimized version…' ), t( 'Scoring before vs. after…' ) ].map( function ( s ) {
				return h( 'li', { text: s } );
			} ) );
			m.body.appendChild( h( 'div', { class: 'sl-progress' }, SL.spinner( t( 'Working… this can take up to a minute.' ) ), steps ) );
			var i = 0;
			var timer = setInterval( function () {
				if ( steps.children[ i ] ) {
					steps.children[ i ].classList.add( 'is-done' );
				}
				i++;
			}, 2500 );
			SL.api( 'optimize', { body: { object_type: opts.object_type, object_id: opts.object_id || 0, field: opts.field, content: content, title: opts.title ? opts.title() : '', focus_keyword: kw.value, goal: goal.value } } ).then( function ( res ) {
				clearInterval( timer );
				show( res );
			} ).catch( function ( e ) {
				clearInterval( timer );
				SL.clear( m.body );
				m.body.appendChild( h( 'div', { class: 'sl-alert sl-alert--error', text: e.message } ) );
				if ( e.data && e.data.code === 'seolity_ai_not_configured' ) {
					m.body.appendChild( h( 'a', { class: 'sl-btn sl-btn--primary', href: ( C.adminUrl || '' ) + 'admin.php?page=seolity-settings' }, t( 'Open settings' ) ) );
				}
			} );
		}

		function show( res ) {
			SL.clear( m.body );
			SL.clear( m.foot );
			var ai = res.ai;
			var editor = h( 'textarea', { class: 'sl-input sl-textarea', rows: 16, dir: 'auto' } );
			editor.value = res.optimized;
			var b = res.before;
			var a = res.after;
			var metrics = [
				[ t( 'Keyword coverage' ), 'keyword_coverage' ], [ t( 'Search intent match' ), 'intent_match' ], [ t( 'Readability' ), 'readability' ],
				[ t( 'Semantic coverage' ), 'semantic_coverage' ], [ t( 'Entity coverage' ), 'entity_coverage' ], [ t( 'Content depth' ), 'content_depth' ],
			];
			var side = h( 'aside', { class: 'sl-opt__side' },
				h( 'div', { class: 'sl-opt__scores' },
					h( 'div', null, h( 'div', { class: 'sl-muted sl-small', text: t( 'SEO Score Before' ) } ), SL.scoreRing( b.score, 72 ) ),
					h( 'div', { class: 'sl-opt__arrow', 'aria-hidden': 'true' }, C.rtl ? '←' : '→' ),
					h( 'div', null, h( 'div', { class: 'sl-muted sl-small', text: t( 'SEO Score After' ) } ), SL.scoreRing( a.score, 72 ) )
				),
				metrics.map( function ( x ) {
					return SL.meter( x[ 0 ], b.metrics[ x[ 1 ] ], a.metrics[ x[ 1 ] ] );
				} ),
				h( 'h4', { text: t( 'Keywords' ) } ),
				h( 'div', { class: 'sl-small' }, h( 'strong', { text: t( 'Focus' ) + ': ' } ), ai.focus_keyword ),
				SL.chips( ai.secondary_keywords, 'kw' ),
				h( 'h4', { text: t( 'Semantic terms' ) } ), SL.chips( ai.semantic_terms ),
				h( 'h4', { text: t( 'Entities' ) } ), SL.chips( ai.entities, 'entity' ),
				h( 'div', { class: 'sl-small sl-muted' }, t( 'Intent' ) + ': ' + t( ai.search_intent ) )
			);
			var changes = h( 'div', { class: 'sl-opt__changes' }, h( 'h4', { text: t( 'What changed and why' ) } ), h( 'p', { text: ai.summary } ), h( 'ul', null, ( ai.changes || [] ).map( function ( c ) {
				return h( 'li', null, h( 'strong', { text: c.area + ': ' } ), c.description, h( 'div', { class: 'sl-muted sl-small', text: c.reason } ) );
			} ) ), ai.information_gain && ai.information_gain.length ? h( 'div', null, h( 'h4', { text: t( 'Information gain' ) } ), SL.chips( ai.information_gain ) ) : null );
			var warn = ( res.warnings || [] ).length ? h( 'div', { class: 'sl-alert sl-alert--warn' }, h( 'strong', { text: t( 'Please review:' ) } ), h( 'ul', null, res.warnings.map( function ( x ) {
				return h( 'li', { text: x } );
			} ) ) ) : null;
			var metaBox = null;
			var metaChecks = {};
			if ( res.changes && ( res.changes.meta_title || res.changes.meta_description ) ) {
				metaBox = h( 'div', { class: 'sl-panel' }, h( 'div', { class: 'sl-panel__title', text: t( 'Also suggested' ) } ) );
				[ [ 'meta_title', ai.suggested_title, t( 'SEO title' ) ], [ 'meta_description', ai.suggested_meta_description, t( 'Meta description' ) ], [ 'focus_keyword', ai.focus_keyword, t( 'Focus keyword' ) ] ].forEach( function ( x ) {
					if ( res.changes[ x[ 0 ] ] ) {
						var cb = h( 'input', { type: 'checkbox', checked: true } );
						metaChecks[ x[ 0 ] ] = cb;
						metaBox.appendChild( h( 'label', { class: 'sl-check' }, cb, h( 'span', null, h( 'strong', { text: x[ 2 ] + ': ' } ), x[ 1 ] ) ) );
					}
				} );
			}
			var main = h( 'div', { class: 'sl-opt__main' }, warn, SL.tabs( [
				{ label: t( 'AI Suggested' ), render: function () {
					return SL.iframePreview( editor.value );
				} },
				{ label: t( 'Original' ), render: function () {
					return SL.iframePreview( res.original );
				} },
				{ label: t( 'Compare' ), render: function () {
					return SL.diffView( res.original, editor.value, true );
				} },
				{ label: t( 'Edit HTML' ), render: function () {
					return editor;
				} },
			] ), changes, metaBox );
			m.body.appendChild( h( 'div', { class: 'sl-opt' }, main, side ) );
			m.body.appendChild( h( 'p', { class: 'sl-muted sl-small', text: t( 'Model' ) + ': ' + res.meta.model + ' · ' + res.meta.prompt_version + ( res.meta.knowledge_refs && res.meta.knowledge_refs.length ? ' · ' + t( 'Knowledge' ) + ': ' + res.meta.knowledge_refs.join( ', ' ) : '' ) } ) );

			var cid = res.changes && res.changes.content;
			function applyMeta() {
				return Promise.all( Object.keys( metaChecks ).filter( function ( k ) {
					return metaChecks[ k ].checked;
				} ).map( function ( k ) {
					return SL.api( 'changes/' + res.changes[ k ] + '/apply', { method: 'POST' } );
				} ) );
			}
			m.foot.appendChild( h( 'button', { class: 'sl-btn', onClick: function () {
				var p = cid ? SL.api( 'changes/' + cid + '/reject', { method: 'POST' } ) : Promise.resolve();
				p.then( function () {
					SL.toast( t( 'Suggestion rejected.' ) );
					m.close();
				} ).catch( SL.fail );
			} }, t( 'Reject' ) ) );
			m.foot.appendChild( h( 'button', { class: 'sl-btn', onClick: function () {
				if ( navigator.clipboard ) {
					navigator.clipboard.writeText( editor.value ).then( function () {
						SL.toast( t( 'Copied.' ) );
					} );
				}
			} }, t( 'Copy' ) ) );
			m.foot.appendChild( h( 'button', { class: 'sl-btn sl-btn--primary', onClick: function ( e ) {
				e.target.disabled = true;
				var value = editor.value;
				var done = function () {
					SL.toast( t( 'Applied. Undo anytime from version history.' ), 'success' );
					m.close();
					if ( opts.onApplied ) {
						opts.onApplied( value );
					}
				};
				if ( opts.setContent ) {
					// Editor mode: put the text into the editor; it is versioned when the post is saved.
					opts.setContent( value );
					var mark = cid ? SL.api( 'changes/' + cid + '/editor-applied', { method: 'POST' } ) : Promise.resolve();
					mark.then( applyMeta ).then( done ).catch( function ( err ) {
						e.target.disabled = false;
						SL.fail( err );
					} );
				} else if ( cid ) {
					SL.api( 'changes/' + cid + '/apply', { method: 'POST', body: value !== res.optimized ? { value: value } : {} } ).then( applyMeta ).then( done ).catch( function ( err ) {
						e.target.disabled = false;
						SL.fail( err );
					} );
				}
			} }, opts.setContent ? t( 'Apply to editor' ) : t( 'Apply changes' ) ) );
		}
	};

	/* ------------------------------------------------------------------ Meta suggestions modal */
	SL.metaModal = function ( type, id, onDone ) {
		var m = SL.modal( { title: t( 'AI title & description' ), wide: true } );
		m.body.appendChild( SL.spinner( t( 'Analyzing real search queries…' ) ) );
		SL.api( 'meta', { body: { object_type: type, object_id: id } } ).then( function ( r ) {
			SL.clear( m.body );
			var d2 = r.ai;
			var titles = r.task === 'ctr_rewrite' ? [ { title: d2.title, rationale: d2.diagnosis } ].concat( ( d2.alternatives || [] ).map( function ( x ) {
				return { title: x.title, rationale: '' };
			} ) ) : d2.title_options;
			var descs = r.task === 'ctr_rewrite' ? [ { description: d2.meta_description, rationale: d2.expected_impact } ].concat( ( d2.alternatives || [] ).map( function ( x ) {
				return { description: x.meta_description, rationale: '' };
			} ) ) : d2.description_options;
			var tIn = h( 'input', { class: 'sl-input', value: titles[ 0 ] ? titles[ 0 ].title : '' } );
			var dIn = h( 'textarea', { class: 'sl-input', rows: 3 } );
			dIn.value = descs[ 0 ] ? descs[ 0 ].description : '';
			var serp = h( 'div', { class: 'sl-serp' } );
			function renderSerp() {
				SL.clear( serp );
				serp.appendChild( h( 'div', { class: 'sl-serp__url', text: C.homeUrl || '' } ) );
				serp.appendChild( h( 'div', { class: 'sl-serp__title', text: tIn.value } ) );
				serp.appendChild( h( 'div', { class: 'sl-serp__desc', text: dIn.value } ) );
				serp.appendChild( h( 'div', { class: 'sl-muted sl-small', text: tIn.value.length + ' / 60 · ' + dIn.value.length + ' / 158' } ) );
			}
			tIn.addEventListener( 'input', renderSerp );
			dIn.addEventListener( 'input', renderSerp );
			renderSerp();
			m.body.appendChild( h( 'div', { class: 'sl-grid-2' },
				h( 'div', { class: 'sl-stack' },
					h( 'div', { class: 'sl-panel' }, h( 'div', { class: 'sl-panel__title', text: t( 'BEFORE' ) } ), h( 'div', { text: r.current.title } ), h( 'div', { class: 'sl-muted', text: r.current.description || '—' } ) ),
					h( 'label', { class: 'sl-label', text: t( 'SEO title' ) } ), tIn,
					h( 'div', { class: 'sl-options' }, titles.map( function ( o ) {
						return h( 'button', { class: 'sl-option', type: 'button', title: o.rationale || '', onClick: function () {
							tIn.value = o.title;
							renderSerp();
						} }, o.title );
					} ) ),
					h( 'label', { class: 'sl-label', text: t( 'Meta description' ) } ), dIn,
					h( 'div', { class: 'sl-options' }, descs.map( function ( o ) {
						return h( 'button', { class: 'sl-option', type: 'button', onClick: function () {
							dIn.value = o.description;
							renderSerp();
						} }, o.description );
					} ) )
				),
				h( 'div', null, h( 'div', { class: 'sl-panel__title', text: t( 'Google preview' ) } ), serp, h( 'p', { class: 'sl-reason', text: d2.expected_impact || '' } ) )
			) );
			m.foot.appendChild( h( 'button', { class: 'sl-btn', onClick: function () {
				Object.keys( r.changes ).forEach( function ( k ) {
					SL.api( 'changes/' + r.changes[ k ] + '/reject', { method: 'POST' } );
				} );
				m.close();
			} }, t( 'Reject' ) ) );
			m.foot.appendChild( h( 'button', { class: 'sl-btn sl-btn--primary', onClick: function () {
				var jobs = [];
				if ( r.changes.meta_title ) {
					jobs.push( SL.api( 'changes/' + r.changes.meta_title + '/apply', { method: 'POST', body: { value: tIn.value } } ) );
				}
				if ( r.changes.meta_description ) {
					jobs.push( SL.api( 'changes/' + r.changes.meta_description + '/apply', { method: 'POST', body: { value: dIn.value } } ) );
				}
				Promise.all( jobs ).then( function () {
					SL.toast( t( 'Title & description applied.' ), 'success' );
					m.close();
					if ( onDone ) {
						onDone( tIn.value, dIn.value );
					}
				} ).catch( SL.fail );
			} }, t( 'Apply' ) ) );
		} ).catch( function ( e ) {
			m.close();
			SL.fail( e );
		} );
	};
} )( window, document );
