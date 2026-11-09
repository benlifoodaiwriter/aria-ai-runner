<?php
/**
 * SEOlity uninstall.
 *
 * Scheduled tasks are always removed. Data (tables, settings, encrypted secrets,
 * post/term/user meta, capabilities) is deleted only when the site owner enabled
 * "Delete all data on uninstall" in SEOlity → Settings → Privacy, so reinstalling
 * never silently loses audit history, change versions or experiments.
 *
 * @package SEOlity
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'SEOLITY_DIR' ) ) {
	define( 'SEOLITY_DIR', plugin_dir_path( __FILE__ ) );
}
require_once SEOLITY_DIR . 'src/Autoloader.php';
\SEOlity\Autoloader::register();

/** Uninstall one site (called per blog on multisite). */
function seolity_uninstall_site() {
	global $wpdb;

	\SEOlity\Queue\Scheduler::unschedule_all();

	$settings = get_option( 'seolity_settings', array() );
	if ( empty( $settings['delete_on_uninstall'] ) ) {
		return;
	}

	\SEOlity\Core\Installer::drop_tables();
	\SEOlity\Core\Capabilities::uninstall();

	// Options and transients (settings, encrypted secrets, caches, OAuth state).
	$like = $wpdb->esc_like( 'seolity_' ) . '%';
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $like, $wpdb->esc_like( '_transient_seolity_' ) . '%', $wpdb->esc_like( '_transient_timeout_seolity_' ) . '%' ) ); // phpcs:ignore WordPress.DB

	// SEO meta written by SEOlity itself (never other SEO plugins' meta).
	$meta = $wpdb->esc_like( '_seolity_' ) . '%';
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $meta ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE %s", $meta ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $meta ) ); // phpcs:ignore WordPress.DB

	wp_cache_flush();
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $seolity_blog_id ) {
		switch_to_blog( $seolity_blog_id );
		seolity_uninstall_site();
		restore_current_blog();
	}
} else {
	seolity_uninstall_site();
}
