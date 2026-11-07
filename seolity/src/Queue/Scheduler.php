<?php
/**
 * WP-Cron integration: a 1-minute tick that drains the queue, plus the daily
 * "SEO intelligence" pipeline and periodic maintenance.
 *
 * Real cron is recommended (define DISABLE_WP_CRON + system cron hitting
 * wp-cron.php every minute); the plugin also kicks the worker with a
 * non-blocking, signed loopback right after the user triggers work.
 *
 * @package SEOlity
 */

namespace SEOlity\Queue;

use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class Scheduler {

	const TICK   = 'seolity_queue_tick';
	const DAILY  = 'seolity_daily';
	const HOURLY = 'seolity_hourly';
	const WEEKLY = 'seolity_weekly';

	public static function register_hooks( Plugin $plugin ) {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );

		add_action(
			self::TICK,
			static function () use ( $plugin ) {
				$plugin->queue->work( (int) apply_filters( 'seolity_queue_budget', 20 ) );
			}
		);
		add_action(
			self::HOURLY,
			static function () use ( $plugin ) {
				$plugin->queue->push( 'alerts_check', array(), array( 'unique' => true, 'priority' => 5 ) );
				$plugin->queue->push( 'not_found_suggest', array(), array( 'unique' => true, 'priority' => 30 ) );
			}
		);
		add_action(
			self::DAILY,
			static function () use ( $plugin ) {
				$plugin->queue->push( 'daily_pipeline', array( 'date' => gmdate( 'Y-m-d' ) ), array( 'unique' => true, 'priority' => 1 ) );
			}
		);
		add_action(
			self::WEEKLY,
			static function () use ( $plugin ) {
				$plugin->queue->push( 'weekly_pipeline', array(), array( 'unique' => true, 'priority' => 2 ) );
			}
		);
		add_action(
			'seolity_post_activation',
			static function () use ( $plugin ) {
				$plugin->queue->push( 'knowledge_sync', array( 'bundled_only' => true ), array( 'unique' => true, 'priority' => 1 ) );
				$plugin->queue->push( 'crawl_start', array( 'type' => 'full' ), array( 'unique' => true, 'priority' => 5 ) );
				$plugin->queue->push( 'images_scan', array( 'offset' => 0 ), array( 'unique' => true, 'priority' => 20 ) );
			}
		);

		// Signed loopback endpoint to kick the worker without waiting for cron.
		add_action( 'wp_ajax_nopriv_seolity_kick', array( __CLASS__, 'handle_kick' ) );
		add_action( 'wp_ajax_seolity_kick', array( __CLASS__, 'handle_kick' ) );

		// Self-heal if events were removed (e.g. by a cron manager plugin).
		add_action(
			'admin_init',
			static function () {
				if ( ! wp_next_scheduled( self::TICK ) ) {
					self::schedule_recurring();
				}
			}
		);
	}

	public static function schedules( $schedules ) {
		$schedules['seolity_minute'] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Every minute (SEOlity queue)', 'seolity' ),
		);
		return $schedules;
	}

	public static function schedule_recurring() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		if ( ! wp_next_scheduled( self::TICK ) ) {
			wp_schedule_event( time() + 60, 'seolity_minute', self::TICK );
		}
		if ( ! wp_next_scheduled( self::HOURLY ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::HOURLY );
		}
		if ( ! wp_next_scheduled( self::DAILY ) ) {
			// Run the daily pipeline at ~04:15 site time (low traffic, GSC data for D-2 available).
			$tz   = wp_timezone();
			$next = new \DateTime( 'tomorrow 04:15', $tz );
			wp_schedule_event( $next->getTimestamp(), 'daily', self::DAILY );
		}
		if ( ! wp_next_scheduled( self::WEEKLY ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', self::WEEKLY );
		}
	}

	public static function unschedule_all() {
		foreach ( array( self::TICK, self::HOURLY, self::DAILY, self::WEEKLY ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	private static function kick_token() {
		return hash_hmac( 'sha256', 'seolity-kick|' . gmdate( 'YmdH' ), wp_salt( 'nonce' ) );
	}

	/** Fire a non-blocking loopback request that drains the queue now. */
	public static function kick() {
		if ( get_transient( 'seolity_kick_throttle' ) ) {
			return;
		}
		set_transient( 'seolity_kick_throttle', 1, 15 );
		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'body'      => array(
					'action' => 'seolity_kick',
					'token'  => self::kick_token(),
				),
			)
		);
	}

	/**
	 * Start the worker when a job is due and none is running. Called on SEOlity's own REST
	 * requests, so delayed steps (a crawl being awaited, a retry) continue while the admin
	 * page is open even on sites without real cron or visitors.
	 */
	public static function kick_if_due() {
		if ( get_transient( 'seolity_kick_throttle' ) ) {
			return;
		}
		$db  = Plugin::instance()->db;
		$now = \SEOlity\Core\Database::now();
		$due = $db->value( "SELECT id FROM {jobs} WHERE status IN ('pending','waiting') AND available_at <= %s LIMIT 1", array( $now ) );
		if ( ! $due ) {
			return;
		}
		$busy = $db->value( "SELECT id FROM {jobs} WHERE status = 'running' AND IFNULL(heartbeat_at, reserved_at) > DATE_SUB(%s, INTERVAL 60 SECOND) LIMIT 1", array( $now ) );
		if ( ! $busy ) {
			self::kick();
		}
	}

	public static function handle_kick() {
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- HMAC token instead of nonce for loopback.
		if ( ! hash_equals( self::kick_token(), $token ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}
		ignore_user_abort( true );
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
		Plugin::instance()->queue->work( 25 );
		wp_die( '', '', array( 'response' => 204 ) );
	}
}
