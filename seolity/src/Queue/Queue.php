<?php
/**
 * Database-backed job queue.
 *
 * - Jobs are rows in {jobs}; handlers are registered by hook name.
 * - Workers are triggered by WP-Cron every minute (and opportunistically after
 *   admin actions), run under a time + memory budget and an atomic lock.
 * - Reservation is race-safe (UPDATE … WHERE status='pending' AND id=X).
 * - Failed jobs retry with exponential backoff, then land in "failed".
 * - unique_key prevents duplicate pending jobs (e.g. one crawl batch per URL set).
 *
 * @package SEOlity
 */

namespace SEOlity\Queue;

use SEOlity\Core\Database;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class Queue {

	const LOCK_OPTION = 'seolity_queue_lock';

	/** @var Plugin */
	private $plugin;

	/** @var array<string, callable> */
	private $handlers = array();

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
		Jobs::register( $this, $plugin );
		do_action( 'seolity_register_jobs', $this );
	}

	public function handler( $hook, callable $callback ) {
		$this->handlers[ $hook ] = $callback;
	}

	public function has_handler( $hook ) {
		return isset( $this->handlers[ $hook ] );
	}

	/**
	 * Push a job.
	 *
	 * @param string $hook     Handler name.
	 * @param array  $args     JSON-serializable args.
	 * @param array  $options  priority (lower first), delay (s), unique (bool|string), group, max_attempts.
	 * @return int Job id (0 when a duplicate unique job already exists).
	 */
	public function push( $hook, array $args = array(), array $options = array() ) {
		$db     = $this->plugin->db;
		$unique = null;
		if ( ! empty( $options['unique'] ) ) {
			$unique   = sha1( $hook . '|' . ( is_string( $options['unique'] ) ? $options['unique'] : wp_json_encode( $args ) ) );
			$existing = $db->value( "SELECT id FROM {jobs} WHERE unique_key = %s AND status IN ('pending','running') LIMIT 1", array( $unique ) );
			if ( $existing ) {
				return 0;
			}
		}
		$delay = isset( $options['delay'] ) ? max( 0, (int) $options['delay'] ) : 0;
		return $db->insert(
			'jobs',
			array(
				'hook'         => sanitize_key( $hook ),
				'args'         => $args,
				'unique_key'   => $unique,
				'group_key'    => isset( $options['group'] ) ? substr( sanitize_key( $options['group'] ), 0, 60 ) : null,
				'status'       => 'pending',
				'priority'     => isset( $options['priority'] ) ? max( 0, min( 255, (int) $options['priority'] ) ) : 10,
				'max_attempts' => isset( $options['max_attempts'] ) ? max( 1, (int) $options['max_attempts'] ) : 3,
				'available_at' => gmdate( 'Y-m-d H:i:s', time() + $delay ),
				'created_at'   => Database::now(),
			)
		);
	}

	/**
	 * Process jobs until the time budget is used.
	 *
	 * @param int $budget_seconds Max seconds to work.
	 * @return int Jobs processed.
	 */
	public function work( $budget_seconds = 20 ) {
		if ( ! $this->acquire_lock( $budget_seconds + 30 ) ) {
			return 0;
		}
		$processed = 0;
		$deadline  = microtime( true ) + $budget_seconds;
		$memory    = $this->memory_limit_bytes();
		try {
			$this->release_stale();
			while ( microtime( true ) < $deadline ) {
				if ( $memory && memory_get_usage( true ) > $memory * 0.8 ) {
					break;
				}
				$job = $this->reserve();
				if ( ! $job ) {
					break;
				}
				$this->run_job( $job );
				$processed++;
			}
		} finally {
			$this->release_lock();
		}
		return $processed;
	}

	private function reserve() {
		$db  = $this->plugin->db;
		$now = Database::now();
		for ( $i = 0; $i < 5; $i++ ) {
			$job = $db->row( "SELECT * FROM {jobs} WHERE status = 'pending' AND available_at <= %s ORDER BY priority ASC, id ASC", array( $now ) );
			if ( ! $job ) {
				return null;
			}
			$claimed = $db->query(
				"UPDATE {jobs} SET status = 'running', reserved_at = %s, attempts = attempts + 1 WHERE id = %d AND status = 'pending'",
				array( $now, (int) $job['id'] )
			);
			if ( 1 === (int) $claimed ) {
				$job['attempts'] = (int) $job['attempts'] + 1;
				return Database::decode( $job, array( 'args' ) );
			}
		}
		return null;
	}

	private function run_job( array $job ) {
		$db = $this->plugin->db;
		try {
			if ( ! isset( $this->handlers[ $job['hook'] ] ) ) {
				throw new \RuntimeException( 'No handler registered for ' . $job['hook'] );
			}
			$result = call_user_func( $this->handlers[ $job['hook'] ], is_array( $job['args'] ) ? $job['args'] : array(), $job );
			if ( is_wp_error( $result ) ) {
				throw new \RuntimeException( $result->get_error_message() );
			}
			$db->update(
				'jobs',
				array(
					'status'      => 'done',
					'finished_at' => Database::now(),
					'last_error'  => null,
				),
				array( 'id' => (int) $job['id'] )
			);
		} catch ( \Throwable $e ) {
			$attempts = (int) $job['attempts'];
			$final    = $attempts >= (int) $job['max_attempts'];
			$db->update(
				'jobs',
				array(
					'status'       => $final ? 'failed' : 'pending',
					'available_at' => gmdate( 'Y-m-d H:i:s', time() + ( 60 * pow( 2, $attempts ) ) ),
					'last_error'   => substr( $e->getMessage(), 0, 2000 ),
					'finished_at'  => $final ? Database::now() : null,
				),
				array( 'id' => (int) $job['id'] )
			);
			if ( $final ) {
				do_action( 'seolity_job_failed', $job, $e );
			}
		}
	}

	/** Re-queue jobs stuck in "running" (worker died mid-job). */
	private function release_stale() {
		$this->plugin->db->query(
			"UPDATE {jobs} SET status = 'pending' WHERE status = 'running' AND reserved_at < %s",
			array( gmdate( 'Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS ) )
		);
	}

	private function acquire_lock( $ttl ) {
		$now = time();
		if ( add_option( self::LOCK_OPTION, $now + $ttl, '', false ) ) {
			return true;
		}
		$expires = (int) get_option( self::LOCK_OPTION );
		if ( $expires < $now ) {
			// Expired lock: take it over atomically.
			global $wpdb;
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
					(string) ( $now + $ttl ),
					self::LOCK_OPTION,
					(string) $expires
				)
			);
			wp_cache_delete( self::LOCK_OPTION, 'options' );
			return 1 === (int) $updated;
		}
		return false;
	}

	private function release_lock() {
		delete_option( self::LOCK_OPTION );
	}

	private function memory_limit_bytes() {
		$limit = ini_get( 'memory_limit' );
		if ( ! $limit || '-1' === $limit ) {
			return 0;
		}
		return wp_convert_hr_to_bytes( $limit );
	}

	/** Stats for the Automation screen. */
	public function stats() {
		$rows = $this->plugin->db->rows( 'SELECT status, COUNT(*) AS c FROM {jobs} GROUP BY status' );
		$out  = array( 'pending' => 0, 'running' => 0, 'done' => 0, 'failed' => 0 );
		foreach ( $rows as $r ) {
			$out[ $r['status'] ] = (int) $r['c'];
		}
		$out['recent'] = $this->plugin->db->rows( 'SELECT id, hook, status, attempts, last_error, created_at, finished_at FROM {jobs} ORDER BY id DESC LIMIT 25' );
		$out['next_cron'] = wp_next_scheduled( Scheduler::TICK );
		return $out;
	}

	public function retry_failed() {
		return $this->plugin->db->query( "UPDATE {jobs} SET status = 'pending', attempts = 0, available_at = %s WHERE status = 'failed'", array( Database::now() ) );
	}

	public function pending_in_group( $group ) {
		return (int) $this->plugin->db->value( "SELECT COUNT(*) FROM {jobs} WHERE group_key = %s AND status IN ('pending','running')", array( $group ) );
	}

	/** Delete finished jobs older than a few days. */
	public function cleanup() {
		$this->plugin->db->query(
			"DELETE FROM {jobs} WHERE status IN ('done','failed') AND finished_at < %s",
			array( gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ) )
		);
	}
}
