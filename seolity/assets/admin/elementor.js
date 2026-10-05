/**
 * SEOlity ↔ Elementor bridge: a floating "Optimize with SEOlity AI" button
 * that optimizes the currently edited Text Editor / Heading widget through
 * Elementor's official command API ($e.run('document/elements/settings')).
 */
( function ( w, d ) {
	'use strict';
	var SL = w.SL;
	if ( ! SL ) {
		return;
	}
	var t = SL.t;

	function current() {
		try {
			var page = w.elementor.getPanelView().getCurrentPageView();
			var view = page && page.getOption ? page.getOption( 'editedElementView' ) : null;
			if ( ! view ) {
				return null;
			}
			var container = view.getContainer();
			var settings = container.settings;
			var key = settings.get( 'editor' ) !== undefined ? 'editor' : settings.get( 'title' ) !== undefined ? 'title' : settings.get( 'description_text' ) !== undefined ? 'description_text' : null;
			return key ? { container: container, key: key } : null;
		} catch ( e ) {
			return null;
		}
	}

	function adapter( sel ) {
		return {
			get: function () {
				return sel.container.settings.get( sel.key ) || '';
			},
			set: function ( html ) {
				var s = {};
				s[ sel.key ] = sel.key === 'title' ? SL.stripHtml( html ) : html;
				w.$e.run( 'document/elements/settings', { container: sel.container, settings: s } );
			},
		};
	}

	function mount() {
		if ( d.getElementById( 'seolity-elementor-btn' ) ) {
			return;
		}
		var b = SL.h( 'button', { id: 'seolity-elementor-btn', type: 'button', class: 'seolity-elementor-btn', onClick: function () {
			var sel = current();
			if ( ! sel ) {
				SL.toast( t( 'Select a Text Editor or Heading widget first.' ) );
				return;
			}
			SL.openOptimizer( 'custom', adapter( sel ) );
		} }, SL.icon( 'sparkles', 16 ), SL.h( 'span', { text: t( 'Optimize with SEOlity AI' ) } ) );
		d.body.appendChild( b );
	}

	w.addEventListener( 'elementor:init', function () {
		setTimeout( mount, 1500 );
	} );
	if ( w.elementor && w.elementor.getPanelView ) {
		setTimeout( mount, 1500 );
	}
} )( window, document );
