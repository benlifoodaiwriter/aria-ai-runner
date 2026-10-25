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
		$from_db = (string) get_option( self::DB_OPTION );
		if ( $from_db !== SEOLITY_DB_VERSION ) {
			self::create_tables();
			Capabilities::install();
			Settings::install_defaults();
			self::migrate_settings();
			self::ensure_default_project();
			update_option( self::DB_OPTION, SEOLITY_DB_VERSION, false );
			if ( '' !== $from_db && version_compare( $from_db, '1.5.0', '<' ) ) {
				self::migrate_150();
			}
		}
		$prev = (string) get_option( 'seolity_plugin_version', '' );
		if ( SEOLITY_VERSION !== $prev ) {
			if ( '' !== $prev && version_compare( $prev, '1.6.0', '<' ) ) {
				// 1.6: GitHub storage is on for every host (the archive needs a repository to run).
				$s = get_option( Settings::OPTION, array() );
				if ( is_array( $s ) ) {
					$s['storage_enabled'] = true;
					update_option( Settings::OPTION, $s, false );
				}
			}
			update_option( 'seolity_plugin_version', SEOLITY_VERSION, false );
			// New worker / runner code ships with updates: redeploy the worker and re-measure routes.
			if ( function_exists( 'seolity' ) ) {
				seolity()->queue->push( 'route_probe', array(), array( 'unique' => true, 'priority' => 6, 'delay' => 60 ) );
			}
		}
	}

	/** One-off settings migrations between versions. */
	/**
	 * DB 1.5.0 (plugin 1.9): link existing activity rows to their jobs and close work that is no
	 * longer alive (the "permanently running" tasks of earlier versions), and give existing
	 * recommendations their category so they appear in the right section of the roadmap.
	 */
	private static function migrate_150() {
		$db = new Database();
		$db->query( "UPDATE {activity} SET job_id = CAST(SUBSTRING(ref, 5) AS UNSIGNED) WHERE job_id IS NULL AND ref LIKE 'job:%'" );
		// Legacy rows created as "running" before their job started (agent / AI edit cards): close the ones idle for 15+ minutes.
		$db->query(
			"UPDATE {activity} SET status = 'interrupted', finished_at = %s, detail = %s WHERE status IN ('running','waiting') AND job_id IS NULL AND updated_at < %s",
			array( Database::now(), __( 'Stopped without finishing (closed by the 1.9 update: this work was no longer running).', 'seolity' ), gmdate( 'Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS ) )
		);
		if ( function_exists( 'seolity' ) ) {
			seolity()->activity->reconcile( true );
			if ( method_exists( seolity()->recommendations, 'backfill_categories' ) ) {
				seolity()->recommendations->backfill_categories();
			}
		}
	}

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
