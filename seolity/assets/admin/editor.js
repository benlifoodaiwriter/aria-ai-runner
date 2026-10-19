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
		var el = d.getElementById( 'seolity_focus_keyword' );
		return el ? el.value.split( ',' )[ 0 ].trim() : '';
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
	SL.openOptimizer = function ( field, adapter, label ) {
		adapter = adapter || contentAdapter( field );
		SL.api( 'ai/warm', { method: 'POST', noWait: true } ).catch( function () {} ); // Warm GitHub runner (if that route is used).
		SL.optimizer( {
			object_type: ctx.object_type || 'post',
			object_id: ctx.object_id || 0,
			field: field,
			focus_keyword: focusKeyword(),
			title: postTitle,
			getContent: adapter.get,
			setContent: adapter.set,
			label: label || adapter.label || '',
			elementId: adapter.elementId || '',
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
		if ( e.target.closest && e.target.closest( '.seolity-kw-btn' ) ) {
			e.preventDefault();
			if ( ! ctx.object_id ) {
				SL.toast( t( 'Save the item first.' ) );
				return;
			}
			SL.keywordInsights( ctx.object_type === 'term' ? 'term' : 'post', ctx.object_id, { editor: true, onApply: live } );
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
		if ( e.target.closest && e.target.closest( '.seolity-eeat-btn' ) ) {
			e.preventDefault();
			if ( ! ctx.object_id ) {
				SL.toast( t( 'Save the item first.' ) );
				return;
			}
			SL.eeatFor( ctx.object_type === 'term' || ctx.object_type === 'user' ? ctx.object_type : 'post', ctx.object_id );
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
		SL.api( 'ai-jobs', { body: { type: 'product', params: { id: id, label: postTitle() } } } ).then( SL.dock.track ).catch( SL.fail );
	};

	/**
	 * Editor adapter for a finished AI job of THIS screen (same object; for Elementor the same
	 * widget), so a card's report can insert straight into the open editor.
	 */
	SL.resolveEditor = function ( params ) {
		if ( ! params || ! ctx.object_id || Number( params.object_id || params.id ) !== Number( ctx.object_id ) ) {
			return null;
		}
		if ( params.field === 'custom' ) {
			return SL.elementorAdapter ? SL.elementorAdapter( params.element_id ) : null;
		}
		return contentAdapter( params.field || 'post_content' );
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

	/* ------------------------------------------------------------------ Media (modal, library, attachment page) */
	function mediaFields( btn ) {
		var box = btn.closest( '.media-sidebar, .attachment-info, .media-frame-content, form#post' ) || d;
		var q = function ( sel ) {
			return box.querySelector( sel ) || d.querySelector( sel );
		};
		return {
			alt: q( '[data-setting="alt"] textarea, [data-setting="alt"] input, #attachment_alt' ),
			title: q( '[data-setting="title"] input, #title' ),
			caption: q( '[data-setting="caption"] textarea, #attachment_caption' ),
			description: q( '[data-setting="description"] textarea, #attachment_content' ),
		};
	}
	d.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest && e.target.closest( '.seolity-media-btn' );
		if ( ! btn ) {
			return;
		}
		e.preventDefault();
		var wrap = btn.closest( '.seolity-media-ai' );
		var out = wrap.querySelector( '.seolity-media-out' );
		var hint = wrap.querySelector( '.seolity-media-hint' );
		var settings = w.wp && w.wp.media && w.wp.media.view && w.wp.media.view.settings;
		var postId = ( settings && settings.post && settings.post.id ) || ( ctx.object_type === 'post' ? ctx.object_id : 0 );
		btn.disabled = true;
		out.textContent = t( 'Writing with AI…' );
		SL.api( 'images/suggest', { body: { id: Number( btn.getAttribute( 'data-id' ) ), post_id: postId || 0, hint: hint ? hint.value : '' } } ).then( function ( r ) {
			var f = mediaFields( btn );
			var filled = [];
			[ 'alt', 'title', 'caption', 'description' ].forEach( function ( k ) {
				if ( f[ k ] && r[ k ] ) {
					Editors.element( f[ k ] ).set( r[ k ] );
					filled.push( k );
				}
			} );
			SL.clear( out );
			out.appendChild( h( 'p', { class: 'seolity-media-ok', text: filled.length ? t( 'Filled: %s. Review and keep or edit — changes save like any edit.', filled.join( ', ' ) ) : t( 'No editable fields found on this screen.' ) } ) );
			if ( r.filename ) {
				out.appendChild( h( 'p', { class: 'description', text: t( 'Suggested file name: %s', r.filename ) } ) );
			}
			if ( r.warnings && r.warnings.length ) {
				out.appendChild( h( 'p', { class: 'description', text: t( 'Check the ALT text: %s', r.warnings.join( ', ' ) ) } ) );
			}
			if ( r.meta && r.meta.knowledge_refs && r.meta.knowledge_refs.length ) {
				out.appendChild( h( 'p', { class: 'description', text: t( 'Knowledge used: %s', r.meta.knowledge_refs.join( ' · ' ) ) } ) );
			}
		} ).catch( function ( err ) {
			out.textContent = ( err && err.message ) || t( 'Something went wrong.' );
		} ).then( function () {
			btn.disabled = false;
		} );
	} );

	/* ------------------------------------------------------------------ Author bio + ACF */
	function addButtonAfter( el, field ) {
		if ( ! el || el._seolity ) {
			return;
		}
		el._seolity = true;
		var b = h( 'button', { type: 'button', class: 'button seolity-optimize-btn seolity-inline-btn', 'data-field': field }, SL.icon( 'sparkles', 16 ), h( 'span', { text: t( 'Optimize with SEOlity AI' ) } ) );
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
