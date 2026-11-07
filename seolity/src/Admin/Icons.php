<?php
/**
 * Server-rendered line icons (same 24px / 1.75 stroke set as SL.ICONS in
 * assets/admin/ui.js) for markup printed by PHP, so the admin never relies
 * on emoji glyphs that render differently per OS.
 *
 * @package SEOlity
 */

namespace SEOlity\Admin;

defined( 'ABSPATH' ) || exit;

final class Icons {

	const PATHS = array(
		'sparkles' => '<path d="M12 3l1.8 4.9L18.7 9.7l-4.9 1.8L12 16.4l-1.8-4.9L5.3 9.7l4.9-1.8z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/><path d="M5 2.5l.6 1.4L7 4.5l-1.4.6L5 6.5l-.6-1.4L3 4.5l1.4-.6z"/>',
		'check'    => '<path d="M20 6 9 17l-5-5"/>',
		'key'      => '<circle cx="7.5" cy="15.5" r="4.5"/><path d="m10.7 12.3 9.8-9.8M17 6l3 3M14.5 8.5l2 2"/>',
		'book'     => '<path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"/>',
	);

	/** Inline SVG markup (static, trusted strings only). */
	public static function svg( $name, $size = 16 ) {
		$paths = isset( self::PATHS[ $name ] ) ? self::PATHS[ $name ] : self::PATHS['sparkles'];
		$size  = (int) $size;
		return '<svg class="sl-ic seolity-ic" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths . '</svg>';
	}

	/** Echo an icon. */
	public static function e( $name, $size = 16 ) {
		echo self::svg( $name, $size ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup from a constant.
	}
}
