<?php
/**
 * Activation, deactivation and schema upgrades.
 *
 * @package SEOlity
 */

namespace SEOlity\Core;

use SEOlity\Queue\Scheduler;

defined( 'ABSPATH' ) || exit;

final class Installer {

	const DB_OPTION = 'seolity_db_version';

	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites( array( 'fields' => 'ids', 'number' => 500 ) ) as $blog_id ) {
				switch_to_blog( $blog_id );
				self::install_site();
				restore_current_blog();
			}
			return;
		}
		self::install_site();
	}

	public static function install_site() {
		self::create_tables();
		Capabilities::install();
		Settings::install_defaults();
		self::ensure_default_project();
		Scheduler::schedule_recurring();
		update_option( self::DB_OPTION, SEOLITY_DB_VERSION, false );
		// Seed bundled knowledge + first crawl shortly after activation (in the background).
		set_transient( 'seolity_activation_redirect', 1, 60 );
		wp_schedule_single_event( time() + 30, 'seolity_post_activation' );
	}

	public static function deactivate() {
		Scheduler::unschedule_all();
	}

	public static function maybe_upgrade() {
		if ( get_option( self::DB_OPTION ) !== SEOLITY_DB_VERSION ) {
			self::create_tables();
			Capabilities::install();
			Settings::install_defaults();
			self::migrate_settings();
			self::ensure_default_project();
			update_option( self::DB_OPTION, SEOLITY_DB_VERSION, false );
		}
	}

	/** One-off settings migrations between versions. */
	private static function migrate_settings() {
		$s = get_option( Settings::OPTION, array() );
		if ( is_array( $s ) && isset( $s['brand_color'] ) && '#6d28d9' === strtolower( (string) $s['brand_color'] ) ) {
			$s['brand_color'] = '#1D4ED8'; // 1.2: new blue default (ARIAGH visual language).
			update_option( Settings::OPTION, $s, false );
		}
	}

	public static function create_tables() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$db = new Database();
		foreach ( $db->schema() as $sql ) {
			dbDelta( $sql );
		}
	}

	private static function ensure_default_project() {
		$db = new Database();
		$id = (int) $db->value( 'SELECT id FROM {projects} WHERE id = 1' );
		if ( ! $id ) {
			$db->insert(
				'projects',
				array(
					'id'         => 1,
					'name'       => get_bloginfo( 'name' ) ? get_bloginfo( 'name' ) : 'Site',
					'domain'     => wp_parse_url( home_url(), PHP_URL_HOST ),
					'type'       => 'local',
					'created_at' => Database::now(),
				)
			);
		}
	}

	/** Drop every SEOlity table (used by uninstall.php when the user opts in). */
	public static function drop_tables() {
		global $wpdb;
		foreach ( Database::TABLES as $table ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'seolity_' . $table ); // phpcs:ignore WordPress.DB
		}
	}
}
