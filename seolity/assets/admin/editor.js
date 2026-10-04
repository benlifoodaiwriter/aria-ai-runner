/**
 * SEOlity editor integrations: Classic editor, WooCommerce short description,
 * taxonomy terms, author bio, ACF/custom fields, the SEO meta box (live score,
 * SERP preview) and the shared editor API used by Gutenberg & Elementor.
 */
( function ( w, d ) {
	'use strict';
	var SL = w.SL;
	var C = SL.config || {};
	var t = SL.t;
	var h = SL.h;
	var ctx = C.context || {};

	/* ------------------------------------------------------------------ Editor adapters */
	var Editors = ( SL.editors = {
		/** TinyMCE / textarea by id. */
		classic: function ( id ) {
			return {
				get: function () {
					var ed = w.tinymce && w.tinymce.get( id );
					if ( ed && ! ed.isHidden() ) {
						return ed.getContent();
					}
					var ta = d.getElementById( id );
					return ta ? ta.value : '';
				},
				set: function ( html ) {
					var ed = w.tinymce && w.tinymce.get( id );
					if ( ed ) {
						ed.setContent( html );
						ed.save();
						ed.fire( 'change' );
					}
					var ta = d.getElementById( id );
					if ( ta ) {
						ta.value = html;
						ta.dispatchEvent( new Event( 'input', { bubbles: true } ) );
						ta.dispatchEvent( new Event( 'change', { bubbles: true } ) );
					}
				},
			};
		},
		/** Plain element (textarea / input). */
		element: function ( el ) {
			return {
				get: function () {
					return el.value;
				},
				set: function ( v ) {
					el.value = v;
					el.dispatchEvent( new Event( 'input', { bubbles: true } ) );
					el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				},
			};
		},
		/** Gutenberg (block editor) post content. */
		gutenberg: function () {
			var data = w.wp && w.wp.data;
			return {
				get: function () {
					return data ? data.select( 'core/editor' ).getEditedPostContent() : '';
				},
				set: function ( html ) {
					if ( ! data ) {
						return;
					}
					var blocks = w.wp.blocks.parse( html );
					if ( ! blocks.length || ( blocks.length === 1 && blocks[ 0 ].name === 'core/freeform' ) ) {
						blocks = w.wp.blocks.rawHandler ? w.wp.blocks.rawHandler( { HTML: html } ) : blocks;
					}
					data.dispatch( 'core/block-editor' ).resetBlocks( blocks );
				},
				title: function () {
					return data ? data.select( 'core/editor' ).getEditedPostAttribute( 'title' ) : '';
				},
			};
		},
	} );

	function postTitle() {
		var gt = ctx.block_editor && w.wp && w.wp.data ? w.wp.data.select( 'core/editor' ).getEditedPostAttribute( 'title' ) : null;
		if ( gt !== null && gt !== undefined ) {
			return gt;
		}
		var el = d.getElementById( 'title' ) || d.getElementById( 'name' );
		return el ? el.value : '';
	}
	function focusKeyword() {
		var el = d.getElementById( 'seolity_focus_keyword' ) || d.querySelector( '[name="seolity_focus_keyword"]' );
		return el ? el.value : '';
	}
	function contentAdapter( field ) {
		if ( field === 'post_excerpt' ) {
			if ( d.getElementById( 'excerpt' ) ) {
				return Editors.classic( 'excerpt' );
			}
			if ( ctx.block_editor && w.wp && w.wp.data ) {
				return { get: function () {
					return w.wp.data.select( 'core/editor' ).getEditedPostAttribute( 'excerpt' );
				}, set: function ( v ) {
					w.wp.data.dispatch( 'core/editor' ).editPost( { excerpt: v } );
				} };
			}
		}
		if ( field === 'post_title' ) {
			if ( ctx.block_editor && w.wp && w.wp.data ) {
				return { get: postTitle, set: function ( v ) {
					w.wp.data.dispatch( 'core/editor' ).editPost( { title: v } );
				} };
			}
			var ti = d.getElementById( 'title' );
			return ti ? Editors.element( ti ) : null;
		}
		if ( ctx.block_editor && w.wp && w.wp.data ) {
			return Editors.gutenberg();
		}
		return Editors.classic( 'content' );
	}
	SL.contentAdapter = contentAdapter;

	/** Open the optimizer for an editor field. */
	SL.openOptimizer = function ( field, adapter ) {
		adapter = adapter || contentAdapter( field );
		SL.optimizer( {
			object_type: ctx.object_type || 'post',
			object_id: ctx.object_id || 0,
			field: field,
			focus_keyword: focusKeyword(),
			title: postTitle,
			getContent: adapter.get,
			setContent: adapter.set,
		} );
	};

	/* ------------------------------------------------------------------ Buttons */
	d.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest && e.target.closest( '.seolity-optimize-btn' );
		if ( btn ) {
			e.preventDefault();
			var field = btn.getAttribute( 'data-field' ) || 'post_content';
			var target = btn.getAttribute( 'data-target' );
			var editorId = btn.getAttribute( 'data-editor' );
			var adapter;
			if ( target ) {
				var el = d.querySelector( target );
				adapter = el && w.tinymce && w.tinymce.get( el.id ) ? Editors.classic( el.id ) : el ? Editors.element( el ) : null;
			} else if ( editorId ) {
				adapter = Editors.classic( editorId );
			} else {
				adapter = contentAdapter( field );
			}
			if ( btn._seolityAdapter ) {
				adapter = btn._seolityAdapter;
			}
			if ( adapter ) {
				SL.openOptimizer( field === 'custom' ? 'post_content' : field, adapter );
			}
			return;
		}
		if ( e.target.closest && e.target.closest( '.seolity-meta-btn' ) ) {
			e.preventDefault();
			if ( ! ctx.object_id ) {
				SL.toast( t( 'Save the item first.' ) );
				return;
			}
			SL.metaModal( ctx.object_type, ctx.object_id, function ( title, desc ) {
				var ti = d.getElementById( 'seolity_meta_title' ) || d.querySelector( '[name="seolity_meta_title"]' );
				var de = d.getElementById( 'seolity_meta_description' ) || d.querySelector( '[name="seolity_meta_description"]' );
				if ( ti ) {
					ti.value = title;
				}
				if ( de ) {
					de.value = desc;
				}
				if ( w.SEOLITY_GUTENBERG_REFRESH ) {
					w.SEOLITY_GUTENBERG_REFRESH();
				}
				live();
			} );
			return;
		}
		if ( e.target.closest && e.target.closest( '.seolity-faq-btn' ) ) {
			e.preventDefault();
			SL.faqFor( ctx.object_id );
			return;
		}
		if ( e.target.closest && e.target.closest( '.seolity-links-btn' ) ) {
			e.preventDefault();
			SL.linksFor( ctx.object_id );
			return;
		}
		if ( e.target.closest && e.target.closest( '.seolity-product-btn' ) ) {
			e.preventDefault();
			SL.productFor( ctx.object_id );
			return;
		}
		if ( e.target.closest && e.target.closest( '.seolity-history-btn' ) ) {
			e.preventDefault();
			SL.history( ctx.object_type || 'post', ctx.object_id );
		}
	} );

	SL.faqFor = function ( id ) {
		var m = SL.modal( { title: t( 'Generate FAQ' ) } );
		m.body.appendChild( SL.spinner( t( 'Writing FAQs from your content and real question queries…' ) ) );
		SL.api( 'faq', { body: { object_id: id } } ).then( function ( r ) {
			m.close();
			SL.preview( r.change );
		} ).catch( function ( e ) {
			m.close();
			SL.fail( e );
		} );
	};

	SL.linksFor = function ( id ) {
		var m = SL.modal( { title: t( 'Internal link suggestions' ), wide: true } );
		m.body.appendChild( SL.spinner() );
		SL.api( 'links/suggest', { body: { post_id: id } } ).then( function ( r ) {
			SL.clear( m.body );
			if ( ! r.suggestions.length ) {
				m.body.appendChild( SL.empty( t( 'No good link opportunities found' ), t( 'Run a site audit so SEOlity knows your pages, then try again.' ) ) );
				return;
			}
			var adapter = contentAdapter( 'post_content' );
			r.suggestions.forEach( function ( s ) {
				m.body.appendChild( h( 'div', { class: 'sl-rec' }, h( 'div', { class: 'sl-rec__main' }, h( 'div', { class: 'sl-rec__title', dir: 'auto', text: '“' + s.anchor + '”' } ), h( 'div', { class: 'sl-rec__reason', text: s.reason } ), h( 'div', { class: 'sl-small sl-muted', text: t( 'Relevance' ) + ' ' + s.relevance + '%' } ) ), h( 'div', { class: 'sl-rec__actions' }, h( 'button', { type: 'button', class: 'sl-btn sl-btn--primary', onClick: function ( ev ) {
					SL.api( 'links/' + s.id + '/preview' ).then( function ( p ) {
						var html = adapter.get();
						var esc = s.anchor.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
						var re = new RegExp( '(^|[^\\p{L}\\p{N}])(' + esc + ')(?![\\p{L}\\p{N}])', 'iu' );
						var parts = html.split( /(<a\s[\s\S]*?<\/a>|<h[1-6][^>]*>[\s\S]*?<\/h[1-6]>|<[^>]+>)/i );
						var done = false;
						for ( var i = 0; i < parts.length && ! done; i++ ) {
							if ( parts[ i ] && parts[ i ].charAt( 0 ) !== '<' && re.test( parts[ i ] ) ) {
								parts[ i ] = parts[ i ].replace( re, function ( all, pre, word ) {
									return pre + '<a href="' + p.target_url.replace( /"/g, '&quot;' ) + '">' + word + '</a>';
								} );
								done = true;
							}
						}
						if ( ! done ) {
							SL.toast( t( 'The anchor text was not found in the current editor content.' ), 'error' );
							return;
						}
						adapter.set( parts.join( '' ) );
						ev.target.disabled = true;
						ev.target.textContent = t( 'Inserted' );
						SL.api( 'links/' + s.id + '/dismiss', { method: 'POST' } );
						SL.toast( t( 'Link inserted. Save the post to keep it.' ), 'success' );
					} ).catch( SL.fail );
				} }, t( 'Insert link' ) ) ) ) );
			} );
		} ).catch( function ( e ) {
			m.close();
			SL.fail( e );
		} );
	};

	SL.productFor = function ( id ) {
		var m = SL.modal( { title: t( 'AI Product Optimizer' ), wide: true } );
		m.body.appendChild( SL.spinner( t( 'Analyzing product data, queries and competitors…' ) ) );
		SL.api( 'products/' + id + '/optimize', { method: 'POST' } ).then( function ( r ) {
			SL.clear( m.body );
			var ai = r.ai;
			m.body.appendChild( h( 'div', { class: 'sl-opt__scores' }, h( 'div', null, h( 'div', { class: 'sl-small sl-muted', text: t( 'SEO Score Before' ) } ), SL.scoreRing( r.before.score, 60 ) ), h( 'div', { class: 'sl-opt__arrow' }, C.rtl ? '←' : '→' ), h( 'div', null, h( 'div', { class: 'sl-small sl-muted', text: t( 'SEO Score After' ) } ), SL.scoreRing( r.after.score, 60 ) ) ) );
			if ( r.warnings.length ) {
				m.body.appendChild( h( 'div', { class: 'sl-alert sl-alert--warn' }, h( 'ul', null, r.warnings.map( function ( x ) {
					return h( 'li', { text: x } );
				} ) ) ) );
			}
			var editorFields = { post_content: 1, post_excerpt: 1, post_title: 1 };
			Object.keys( r.changes ).forEach( function ( k ) {
				var cid = r.changes[ k ];
				var label = k.indexOf( 'alt_' ) === 0 ? t( 'Image ALT' ) + ' #' + k.slice( 4 ) : SL.fieldLabel( k );
				var row = h( 'div', { class: 'sl-row sl-row--between sl-propitem' }, h( 'strong', { text: label } ) );
				var actions = h( 'div', { class: 'sl-row' } );
				actions.appendChild( h( 'button', { type: 'button', class: 'sl-btn sl-btn--sm', onClick: function () {
					SL.preview( cid );
				} }, t( 'Preview' ) ) );
				if ( editorFields[ k ] ) {
					actions.appendChild( h( 'button', { type: 'button', class: 'sl-btn sl-btn--primary sl-btn--sm', onClick: function ( ev ) {
						SL.api( 'changes/' + cid ).then( function ( c ) {
							var ad = contentAdapter( k );
							if ( ad ) {
								ad.set( c.after_value );
							}
							return SL.api( 'changes/' + cid + '/editor-applied', { method: 'POST' } );
						} ).then( function () {
							ev.target.disabled = true;
							ev.target.textContent = t( 'Inserted' );
							SL.toast( t( 'Inserted into the editor. Save the product to keep it.' ), 'success' );
						} ).catch( SL.fail );
					} }, t( 'Insert into editor' ) ) );
				} else {
					actions.appendChild( h( 'button', { type: 'button', class: 'sl-btn sl-btn--primary sl-btn--sm', onClick: function ( ev ) {
						SL.api( 'changes/' + cid + '/apply', { method: 'POST' } ).then( function () {
							ev.target.disabled = true;
							ev.target.textContent = t( 'Applied' );
							if ( k === 'meta_title' || k === 'meta_description' || k === 'focus_keyword' ) {
								var el = d.getElementById( 'seolity_' + k );
								if ( el ) {
									el.value = k === 'meta_title' ? ai.meta_title : k === 'meta_description' ? ai.meta_description : ai.focus_keyword;
								}
							}
						} ).catch( SL.fail );
					} }, t( 'Apply' ) ) );
				}
				row.appendChild( actions );
				m.body.appendChild( row );
			} );
			m.body.appendChild( h( 'div', { class: 'sl-grid-2' },
				h( 'div', null, h( 'h4', { text: t( 'Keywords' ) } ), h( 'p', null, h( 'strong', { text: t( 'Focus' ) + ': ' } ), ai.focus_keyword ), SL.chips( ai.secondary_keywords, 'kw' ), SL.chips( ai.long_tail_keywords ) ),
				h( 'div', null, h( 'h4', { text: t( 'Missing attributes' ) } ), SL.chips( ai.missing_attributes, 'warn' ), h( 'h4', { text: t( 'Content weaknesses' ) } ), h( 'ul', null, ai.content_weaknesses.map( function ( x ) {
					return h( 'li', { text: x } );
				} ) ) )
			) );
		} ).catch( function ( e ) {
			m.close();
			SL.fail( e );
		} );
	};

	/* ------------------------------------------------------------------ Meta box: live score + SERP */
	var box = d.querySelector( '.seolity-box' );
	var timer;
	function live() {
		if ( ! box ) {
			return;
		}
		clearTimeout( timer );
		timer = setTimeout( function () {
			var adapter = contentAdapter( 'post_content' );
			var ti = d.getElementById( 'seolity_meta_title' );
			var de = d.getElementById( 'seolity_meta_description' );
			SL.api( 'analyze', { body: {
				object_type: 'post',
				object_id: ctx.object_id || 0,
				content: adapter ? adapter.get() : '',
				title: postTitle(),
				focus_keyword: focusKeyword(),
				meta_title: ti ? ti.value : undefined,
				meta_description: de ? de.value : undefined,
			} } ).then( function ( a ) {
				var ring = box.querySelector( '.seolity-box__score' );
				var old = ring.querySelector( '.sl-ring, .seolity-score-ring' );
				if ( old ) {
					old.remove();
				}
				ring.insertBefore( SL.scoreRing( a.score, 52 ), ring.firstChild );
				var list = box.querySelector( '.seolity-box__checks' );
				SL.clear( list );
				a.checks.filter( function ( c ) {
					return c.status !== 'pass';
				} ).slice( 0, 8 ).forEach( function ( c ) {
					list.appendChild( h( 'li', { class: 'is-' + c.status }, c.message ) );
				} );
				if ( w.SEOLITY_GUTENBERG_SCORE ) {
					w.SEOLITY_GUTENBERG_SCORE( a );
				}
			} ).catch( function () {} );
		}, 1200 );
		serp();
	}
	SL.liveAnalyze = live;
	function serp() {
		var s = d.querySelector( '.seolity-serp' );
		if ( ! s ) {
			return;
		}
		var ti = d.getElementById( 'seolity_meta_title' );
		var de = d.getElementById( 'seolity_meta_description' );
		s.querySelector( '.seolity-serp__url' ).textContent = C.homeUrl || '';
		s.querySelector( '.seolity-serp__title' ).textContent = ( ti && ti.value ) || postTitle();
		s.querySelector( '.seolity-serp__desc' ).textContent = ( de && de.value ) || t( 'Google will generate a snippet from your content. Write a description to control it.' );
		[ ti, de ].forEach( function ( el ) {
			if ( el ) {
				var c = d.querySelector( '.seolity-count[data-for="' + el.id + '"]' );
				if ( c ) {
					var max = el.id === 'seolity_meta_title' ? 60 : 158;
					c.textContent = el.value.length + ' / ' + max;
					c.className = 'seolity-count' + ( el.value.length > max ? ' is-over' : '' );
				}
			}
		} );
	}
	if ( box ) {
		[ 'seolity_focus_keyword', 'seolity_meta_title', 'seolity_meta_description', 'title' ].forEach( function ( id ) {
			var el = d.getElementById( id );
			if ( el ) {
				el.addEventListener( 'input', live );
			}
		} );
		w.addEventListener( 'load', function () {
			if ( w.tinymce ) {
				var ed = w.tinymce.get( 'content' );
				if ( ed ) {
					ed.on( 'keyup change', live );
				}
			}
			if ( ctx.block_editor && w.wp && w.wp.data ) {
				var last = '';
				w.wp.data.subscribe( function () {
					var c = w.wp.data.select( 'core/editor' ).getEditedPostContent();
					if ( c !== last ) {
						last = c;
						live();
					}
				} );
			}
			live();
		} );
	}

	/* ------------------------------------------------------------------ Author bio + ACF */
	function addButtonAfter( el, field ) {
		if ( ! el || el._seolity ) {
			return;
		}
		el._seolity = true;
		var b = h( 'button', { type: 'button', class: 'button seolity-optimize-btn seolity-inline-btn', 'data-field': field }, '✨ ' + t( 'Optimize with SEOlity AI' ) );
		b._seolityAdapter = el.id && w.tinymce && w.tinymce.get( el.id ) ? Editors.classic( el.id ) : Editors.element( el );
		el.parentNode.insertBefore( b, el.nextSibling );
	}
	d.addEventListener( 'DOMContentLoaded', function () {
		if ( ctx.object_type === 'user' ) {
			addButtonAfter( d.getElementById( 'description' ), 'user_description' );
		}
		if ( ctx.object_type === 'term' && ! d.querySelector( '.seolity-term' ) ) {
			addButtonAfter( d.getElementById( 'tag-description' ), 'term_description' );
		}
		// ACF textarea / WYSIWYG fields (no direct save: content is inserted, saved with the post).
		Array.prototype.forEach.call( d.querySelectorAll( '.acf-field-textarea textarea, .acf-field-wysiwyg textarea' ), function ( ta ) {
			addButtonAfter( ta, 'custom' );
		} );
	} );

	SL.editorContext = ctx;
} )( window, document );
