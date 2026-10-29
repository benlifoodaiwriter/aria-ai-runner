<?php
/**
 * Plugin Name:       ARIAGH.SEOLITY
 * Plugin URI:        https://github.com/benlifoodaiwriter/aria-ai-runner/tree/main/seolity
 * Description:       AI SEO Intelligence for WordPress — an AI-powered SEO operating system: audits, Google Search Console analytics, AI content & product optimization, experiments, redirects, schema and more.
 * Version:           1.12.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            SEOlity
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       seolity
 * Domain Path:       /languages
 * WC requires at least: 7.0
 * WC tested up to:   9.4
 *
 * @package SEOlity
 */

defined( 'ABSPATH' ) || exit;

define( 'SEOLITY_VERSION', '1.12.0' );
define( 'SEOLITY_DB_VERSION', '1.7.0' );
define( 'SEOLITY_FILE', __FILE__ );
define( 'SEOLITY_DIR', plugin_dir_path( __FILE__ ) );
define( 'SEOLITY_URL', plugin_dir_url( __FILE__ ) );
define( 'SEOLITY_BASENAME', plugin_basename( __FILE__ ) );

if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'SEOlity requires PHP 7.4 or newer.', 'seolity' ) . '</p></div>';
		}
	);
	return;
}

require_once SEOLITY_DIR . 'src/Autoloader.php';
\SEOlity\Autoloader::register();

register_activation_hook( __FILE__, array( '\SEOlity\Core\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\SEOlity\Core\Installer', 'deactivate' ) );

// Declare WooCommerce HPOS compatibility (we only use WC CRUD APIs).
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action( 'plugins_loaded', array( '\SEOlity\Plugin', 'instance' ), 5 );

/**
 * Global accessor for the plugin container.
 *
 * @return \SEOlity\Plugin
 */
function seolity() {
	return \SEOlity\Plugin::instance();
}
