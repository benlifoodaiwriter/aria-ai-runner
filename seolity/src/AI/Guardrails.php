<?php
/**
 * Quality & safety guardrails around every AI call.
 *
 * - A non-negotiable SEO ethics policy is prepended to every system prompt.
 * - Generated content is post-checked for keyword stuffing, hidden text,
 *   invented links, removed links/shortcodes and suspicious claims.
 *
 * @package SEOlity
 */

namespace SEOlity\AI;

use SEOlity\Analysis\Text;

defined( 'ABSPATH' ) || exit;

final class Guardrails {

	public static function policy() {
		return implode(
			"\n",
			array(
				'You are the AI engine of SEOlity, an SEO intelligence suite running inside WordPress.',
				'Non-negotiable principles (Google Search Essentials & helpful-content guidelines):',
				'- Optimize for people first: search intent, usefulness, accuracy, information gain, E-E-A-T, accessibility.',
				'- NEVER produce keyword stuffing, hidden text, cloaking, doorway pages, scaled low-value pages, fake reviews, fabricated statistics, fake credentials or misleading claims.',
				'- Never invent facts about products, prices, medical/legal/financial claims or the business. If information is missing, flag it.',
				'- Preserve existing links, shortcodes and brand/product names unless explicitly asked to change them.',
				'- Base analysis on the data provided; clearly label estimates as estimates.',
				'- Output must follow the requested JSON schema exactly.',
			)
		);
	}

	/**
	 * Inspect generated HTML/text against the original.
	 *
	 * @return string[] Warnings for the UI.
	 */
	public static function inspect_content( $generated, $original = '', $keyword = '' ) {
		$warnings = array();
		$text     = Text::strip( $generated );
		$words    = max( 1, Text::word_count( $text ) );

		if ( '' !== $keyword ) {
			$count   = Text::count_phrase( $text, $keyword );
			$density = $count * max( 1, count( Text::words( $keyword ) ) ) / $words * 100;
			if ( $density > 3.0 ) {
				$warnings[] = sprintf( /* translators: %s percent */ __( 'Possible keyword stuffing: focus keyword density %s%%.', 'seolity' ), round( $density, 1 ) );
			}
		}
		if ( preg_match( '/display\s*:\s*none|visibility\s*:\s*hidden|font-size\s*:\s*0|text-indent\s*:\s*-\d{3,}/i', $generated ) ) {
			$warnings[] = __( 'Generated content contains hidden-text styling — removed is recommended.', 'seolity' );
		}
		if ( '' !== $original ) {
			preg_match_all( '/href=["\']([^"\']+)["\']/i', $original, $orig_links );
			preg_match_all( '/href=["\']([^"\']+)["\']/i', $generated, $new_links );
			$removed = array_diff( array_unique( $orig_links[1] ), $new_links[1] );
			$added   = array_diff( array_unique( $new_links[1] ), $orig_links[1] );
			if ( $removed ) {
				$warnings[] = sprintf( /* translators: %d */ __( '%d original link(s) were removed — review before applying.', 'seolity' ), count( $removed ) );
			}
			$home = wp_parse_url( home_url(), PHP_URL_HOST );
			foreach ( $added as $href ) {
				$host = wp_parse_url( $href, PHP_URL_HOST );
				if ( $host && $host !== $home ) {
					$warnings[] = sprintf( /* translators: %s url */ __( 'New external link added: %s — verify it exists and is appropriate.', 'seolity' ), esc_url_raw( $href ) );
				}
			}
			preg_match_all( '/\[([a-z0-9_-]+)[^\]]*\]/i', $original, $orig_sc );
			foreach ( array_unique( $orig_sc[1] ) as $sc ) {
				if ( false === strpos( $generated, '[' . $sc ) ) {
					$warnings[] = sprintf( /* translators: %s shortcode */ __( 'Shortcode [%s] is missing from the optimized version.', 'seolity' ), $sc );
				}
			}
			preg_match_all( '/\d[\d,.]*\s?(%|٪|تومان|ریال|\$|€|USD)/u', $generated, $new_numbers );
			foreach ( array_unique( $new_numbers[0] ) as $num ) {
				if ( false === strpos( $original, trim( $num ) ) ) {
					$warnings[] = sprintf( /* translators: %s number */ __( 'New figure "%s" not found in original — verify accuracy.', 'seolity' ), $num );
					break;
				}
			}
		}
		return array_values( array_unique( $warnings ) );
	}

	/** Strip disallowed markup from AI HTML before it can be applied. */
	public static function clean_html( $html ) {
		$allowed = wp_kses_allowed_html( 'post' );
		$html    = preg_replace( '/\sstyle=("|\')[^"\']*(display\s*:\s*none|visibility\s*:\s*hidden)[^"\']*\1/i', '', (string) $html );
		return wp_kses( $html, $allowed );
	}
}
