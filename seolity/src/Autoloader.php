<?php
/**
 * PSR-4 autoloader for the SEOlity namespace (no Composer required).
 *
 * @package SEOlity
 */

namespace SEOlity;

defined( 'ABSPATH' ) || exit;

final class Autoloader {

	public static function register() {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * @param string $class Fully-qualified class name.
	 */
	public static function load( $class ) {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$file     = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
