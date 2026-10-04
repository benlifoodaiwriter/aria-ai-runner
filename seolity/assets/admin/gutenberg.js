/**
 * SEOlity Gutenberg sidebar (no JSX / no build step: wp.element.createElement).
 * Adds "✨ Optimize with SEOlity AI" + live score + quick AI actions to the
 * block editor's "more" menu and a dedicated sidebar.
 */
( function ( wp, w ) {
	'use strict';
	if ( ! wp || ! wp.plugins || ! wp.element || ! w.SL ) {
		return;
	}
	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var editPost = wp.editPost || wp.editor || {};
	var PluginSidebar = editPost.PluginSidebar;
	var PluginSidebarMoreMenuItem = editPost.PluginSidebarMoreMenuItem;
	var PanelBody = wp.components.PanelBody;
	var Button = wp.components.Button;
	var SL = w.SL;
	var t = SL.t;
	if ( ! PluginSidebar ) {
		return;
	}

	function Score() {
		var st = useState( null );
		var analysis = st[ 0 ];
		var setAnalysis = st[ 1 ];
		useEffect( function () {
			w.SEOLITY_GUTENBERG_SCORE = setAnalysis;
			if ( SL.liveAnalyze ) {
				SL.liveAnalyze();
			}
			return function () {
				w.SEOLITY_GUTENBERG_SCORE = null;
			};
		}, [] );
		if ( ! analysis ) {
			return el( 'p', { className: 'seolity-g-muted' }, t( 'Analyzing…' ) );
		}
		var m = analysis.metrics;
		var row = function ( label, v ) {
			return el( 'div', { className: 'seolity-g-metric' }, el( 'span', null, label ), el( 'strong', null, v === null || v === undefined ? '—' : String( v ) ) );
		};
		return el( 'div', null,
			el( 'div', { className: 'seolity-g-score seolity-g-score--' + analysis.grade }, el( 'span', { className: 'seolity-g-score__n' }, String( analysis.score ) ), el( 'span', null, t( 'SEO score (live)' ) ) ),
			row( t( 'Keyword coverage' ), m.keyword_coverage ),
			row( t( 'Search intent match' ), m.intent_match ),
			row( t( 'Readability' ), m.readability ),
			row( t( 'Content depth' ), m.content_depth ),
			row( t( 'Words' ), analysis.stats.words ),
			el( 'ul', { className: 'seolity-g-checks' }, analysis.checks.filter( function ( c ) {
				return c.status !== 'pass';
			} ).slice( 0, 6 ).map( function ( c, i ) {
				return el( 'li', { key: i, className: 'is-' + c.status }, c.message );
			} ) )
		);
	}

	function Sidebar() {
		var id = wp.data.select( 'core/editor' ).getCurrentPostId();
		var type = wp.data.select( 'core/editor' ).getCurrentPostType();
		var saved = ! wp.data.select( 'core/editor' ).isEditedPostNew();
		return el( wp.element.Fragment, null,
			el( PluginSidebarMoreMenuItem, { target: 'seolity-sidebar' }, 'SEOlity' ),
			el( PluginSidebar, { name: 'seolity-sidebar', title: 'SEOlity', icon: 'superhero-alt' },
				el( PanelBody, { title: '✨ ' + t( 'Optimize with SEOlity AI' ), initialOpen: true },
					el( Button, { variant: 'primary', className: 'seolity-g-btn', onClick: function () {
						SL.openOptimizer( 'post_content' );
					} }, t( 'Optimize content' ) ),
					el( Button, { variant: 'secondary', className: 'seolity-g-btn', disabled: ! saved, onClick: function () {
						SL.metaModal( 'post', id );
					} }, t( 'AI title & description' ) ),
					el( Button, { variant: 'secondary', className: 'seolity-g-btn', onClick: function () {
						SL.openOptimizer( 'post_excerpt' );
					} }, type === 'product' ? t( 'Optimize short description' ) : t( 'Optimize excerpt' ) ),
					el( Button, { variant: 'secondary', className: 'seolity-g-btn', disabled: ! saved, onClick: function () {
						SL.faqFor( id );
					} }, t( 'Generate FAQ' ) ),
					el( Button, { variant: 'secondary', className: 'seolity-g-btn', disabled: ! saved, onClick: function () {
						SL.linksFor( id );
					} }, t( 'Internal links' ) ),
					el( Button, { variant: 'secondary', className: 'seolity-g-btn', disabled: ! saved, onClick: function () {
						SL.eeatFor( 'post', id );
					} }, t( 'E-E-A-T analysis' ) ),
					type === 'product' ? el( Button, { variant: 'secondary', className: 'seolity-g-btn', disabled: ! saved, onClick: function () {
						SL.productFor( id );
					} }, t( 'AI Product Optimizer' ) ) : null,
					el( Button, { variant: 'link', onClick: function () {
						SL.history( 'post', id );
					} }, t( 'Version history' ) )
				),
				el( PanelBody, { title: t( 'Live SEO analysis' ), initialOpen: true }, el( Score ) )
			)
		);
	}

	wp.plugins.registerPlugin( 'seolity', { render: Sidebar, icon: 'superhero-alt' } );
} )( window.wp, window );
