<?php
/**
 * Front-end integration. Deliberately tiny: no queries unless needed, no
 * external calls, no heavy work — anything expensive happens in the queue.
 *
 * @package SEOlity
 */

namespace SEOlity\Frontend;

use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class Frontend {

	public static function register_hooks( Plugin $plugin ) {
		add_filter(
			'robots_txt',
			static function ( $output, $public ) use ( $plugin ) {
				return $plugin->robots->filter_output( $output, $public );
			},
			20,
			2
		);
		add_action(
			'init',
			static function () use ( $plugin ) {
				if ( $plugin->sitemap->enhances_core() ) {
					$plugin->sitemap->register_core_filters();
				}
			},
			20
		);
		if ( is_admin() ) {
			return;
		}
		add_action(
			'template_redirect',
			static function () use ( $plugin ) {
				$plugin->redirects->handle();
			},
			1
		);
		add_action(
			'template_redirect',
			static function () use ( $plugin ) {
				$plugin->not_found->maybe_log();
			},
			999
		);
		add_action(
			'wp',
			static function () use ( $plugin ) {
				( new Head( $plugin ) )->register();
			}
		);
		// FAQ schema must describe content visible on the page (Google policy): render the FAQ.
		add_shortcode( 'seolity_faq', array( __CLASS__, 'faq_html' ) );
		add_filter(
			'the_content',
			static function ( $content ) {
				if ( ! is_singular() || ! in_the_loop() || ! is_main_query() || has_shortcode( $content, 'seolity_faq' ) ) {
					return $content;
				}
				return $content . self::faq_html();
			},
			20
		);
	}

	/** Accessible FAQ markup from the _seolity_faq meta. */
	public static function faq_html() {
		$faq = get_post_meta( get_the_ID(), '_seolity_faq', true );
		if ( ! is_array( $faq ) || ! $faq ) {
			return '';
		}
		$html = '<section class="seolity-faq" aria-labelledby="seolity-faq-title"><h2 id="seolity-faq-title">' . esc_html( apply_filters( 'seolity_faq_heading', __( 'Frequently asked questions', 'seolity' ) ) ) . '</h2>';
		foreach ( $faq as $row ) {
			$html .= '<details class="seolity-faq__item"><summary>' . esc_html( $row['question'] ) . '</summary><div class="seolity-faq__answer">' . wp_kses_post( wpautop( $row['answer'] ) ) . '</div></details>';
		}
		return $html . '</section>';
	}
}
