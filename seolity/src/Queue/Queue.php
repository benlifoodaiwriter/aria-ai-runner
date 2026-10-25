<?php
/**
 * Database-backed job queue with a complete task lifecycle.
 *
 * - Jobs are rows in {jobs}; handlers are registered by hook name.
 * - Workers are triggered by WP-Cron every minute (and opportunistically after
 *   admin actions), run under a time + memory budget and an atomic lock.
 * - Reservation is race-safe (UPDATE … WHERE status='pending' AND id=X) and stamps an
 *   owner token, a start time and a heartbeat. Handlers (and Activity::step) keep the
 *   heartbeat fresh; a running job whose heartbeat stops (PHP time limit, memory, fatal
 *   error, server restart) is detected by recover(): it is retried, and after repeated
 *   interruptions it ends as "timed_out" instead of looping forever.
 * - States: pending (queued or scheduled), waiting (AI answer from GitHub), running,
 *   done, failed, cancelled, timed_out.
 * - Cancellation is cooperative: a pending job is cancelled at once; a running job gets
 *   cancel_requested and stops at its next checkpoint() (never in the middle of a write).
 * - Failed jobs retry with exponential backoff, then land in "failed".
 * - unique_key prevents duplicate pending/running jobs (e.g. one agent run per task).
 *
 * @package SEOlity
 */

namespace SEOlity\Queue;

use SEOlity\Activity\Activity;
use SEOlity\Core\Database;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class Queue {

	const LOCK_OPTION = 'seolity_queue_lock';

	/** States in which a job is still alive. */
	const ACTIVE = array( 'pending', 'waiting', 'running' );

	/** Final states. */
	const FINAL_STATES = array( 'done', 'failed', 'cancelled', 'timed_out' );

	/** @var Plugin */
	private $plugin;

	/** @var array<string, callable> */
	private $handlers = array();

	/** @var int Job being executed by this PHP process. */
	private static $current = 0;

	/** @var string Owner token of this worker. */
	private $owner = '';

	/** @var float Last heartbeat write (throttle). */
	private $last_beat = 0.0;

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

	public static function current() {
		return self::$current;
	}

	/**
	 * Push a job.
	 *
	 * @param string $hook     Handler name.
	 * @param array  $args     JSON-serializable args.
	 * @param array  $options  priority (lower first), delay (s), unique (bool|string), group, max_attempts, timeout (s).
	 * @return int Job id (0 when a duplicate unique job already exists).
	 */
	public function push( $hook, array $args = array(), array $options = array() ) {
		$db     = $this->plugin->db;
		$unique = null;
		if ( ! empty( $options['unique'] ) ) {
			$unique   = sha1( $hook . '|' . ( is_string( $options['unique'] ) ? $options['unique'] : wp_json_encode( $args ) ) );
			$existing = $db->value( "SELECT id FROM {jobs} WHERE unique_key = %s AND status IN ('pending','waiting','running') LIMIT 1", array( $unique ) );
			if ( $existing ) {
				return 0;
			}
		}
		$delay   = isset( $options['delay'] ) ? max( 0, (int) $options['delay'] ) : 0;
		$timeout = isset( $options['timeout'] ) ? (int) $options['timeout'] : max( 600, (int) $this->plugin->settings->get( 'ai_timeout' ) * 4 );
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
				'timeout_s'    => max( 120, min( 7200, $timeout ) ),
				'available_at' => gmdate( 'Y-m-d H:i:s', time() + $delay ),
				'created_at'   => Database::now(),
			)
		);
	}

	/** The unique job already alive for a unique key (0 when none) — lets callers re-use instead of duplicating. */
	public function find_unique( $hook, $unique ) {
		$key = sha1( $hook . '|' . ( is_string( $unique ) ? $unique : wp_json_encode( $unique ) ) );
		return (int) $this->plugin->db->value( "SELECT id FROM {jobs} WHERE unique_key = %s AND status IN ('pending','waiting','running') ORDER BY id DESC LIMIT 1", array( $key ) );
	}

	/**
	 * Process jobs until the time budget is used.
	 *
	 * @param int $budget_seconds Max seconds to work.
	 * @return int Jobs processed.
	 */
	public function work( $budget_seconds = 20 ) {
		$this->recover();
		if ( ! $this->acquire_lock( $budget_seconds + 30 ) ) {
			return 0;
		}
		$this->owner = substr( wp_generate_password( 16, false, false ), 0, 16 );
		$processed   = 0;
		$deadline    = microtime( true ) + $budget_seconds;
		$memory      = $this->memory_limit_bytes();
		try {
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
			$job = $db->row( "SELECT * FROM {jobs} WHERE status IN ('pending','waiting') AND available_at <= %s ORDER BY priority ASC, id ASC", array( $now ) );
			if ( ! $job ) {
				return null;
			}
			$claimed = $db->query(
				"UPDATE {jobs} SET status = 'running', reserved_at = %s, heartbeat_at = %s, started_at = IFNULL(started_at, %s), owner = %s, attempts = attempts + 1 WHERE id = %d AND status IN ('pending','waiting')",
				array( $now, $now, $now, $this->owner, (int) $job['id'] )
			);
			if ( 1 === (int) $claimed ) {
				$job['attempts'] = (int) $job['attempts'] + 1;
				return Database::decode( $job, array( 'args' ) );
			}
		}
		return null;
	}

	/**
	 * Keep the current job alive (and report progress). Cheap: writes at most every 5 s.
	 *
	 * @param int|null $progress 0–100.
	 */
	public function heartbeat( $progress = null ) {
		if ( ! self::$current ) {
			return;
		}
		$now = microtime( true );
		if ( null === $progress && $now - $this->last_beat < 5 ) {
			return;
		}
		$this->last_beat = $now;
		$data            = array( 'heartbeat_at' => Database::now() );
		if ( null !== $progress ) {
			$data['progress'] = max( 0, min( 100, (int) $progress ) );
		}
		$this->plugin->db->update( 'jobs', $data, array( 'id' => self::$current ) );
	}

	/**
	 * Safe point for long handlers: heartbeat, and stop here when the owner asked to cancel.
	 *
	 * @throws Cancelled When cancellation was requested.
	 */
	private static function job_locale( array $args ) {
		$locale = ! empty( $args['user_id'] ) ? get_user_locale( (int) $args['user_id'] ) : (string) get_option( 'seolity_ui_locale', '' );
		return $locale && function_exists( 'switch_to_locale' ) && determine_locale() !== $locale ? switch_to_locale( $locale ) : false;
	}

	public function checkpoint( $progress = null ) {
		if ( ! self::$current ) {
			return;
		}
		$this->heartbeat( $progress );
		$flag = (int) $this->plugin->db->value( 'SELECT cancel_requested FROM {jobs} WHERE id = %d', array( self::$current ) );
		if ( $flag ) {
			throw new Cancelled( __( 'Cancelled by the user.', 'seolity' ) );
		}
	}

	private function run_job( array $job ) {
		$db    = $this->plugin->db;
		$label = Activity::job_label( $job['hook'] );
		$act   = $this->plugin->activity;
		$aid   = 0;
		$prev  = Activity::current();
		$prevj = self::$current;
		// Activities that wait for this job (agent runs, AI edits, chat answers) start now.
		$act->job_started( (int) $job['id'] );
		if ( $label ) {
			$aid = $act->find_open( 'job:' . $job['id'] );
			if ( ! $aid ) {
				$aid = $act->start( 'job', $label, array( 'ref' => 'job:' . $job['id'], 'job_id' => (int) $job['id'], 'user_id' => isset( $job['args']['user_id'] ) ? (int) $job['args']['user_id'] : 0, 'step' => __( 'Working…', 'seolity' ) ) );
			} else {
				$act->step( $aid, __( 'Continuing…', 'seolity' ), null, array( 'status' => 'running' ) );
			}
			Activity::set_current( $aid );
		}
		self::$current   = (int) $job['id'];
		$this->last_beat = microtime( true );
		try {
			if ( ! isset( $this->handlers[ $job['hook'] ] ) ) {
				throw new \RuntimeException( 'No handler registered for ' . $job['hook'] );
			}
			// Texts written by the job (issue titles, activity steps) use the reader's language,
			// not the site language: the user who started it, else the admin who last opened SEOlity.
			$switched = self::job_locale( is_array( $job['args'] ) ? $job['args'] : array() );
			try {
				$result = call_user_func( $this->handlers[ $job['hook'] ], is_array( $job['args'] ) ? $job['args'] : array(), $job );
			} finally {
				if ( $switched ) {
					restore_previous_locale();
				}
			}
			if ( is_wp_error( $result ) ) {
				throw new \RuntimeException( $result->get_error_message() );
			}
			$db->update(
				'jobs',
				array(
					'status'      => 'done',
					'progress'    => 100,
					'finished_at' => Database::now(),
					'last_error'  => null,
				),
				array( 'id' => (int) $job['id'] )
			);
			if ( $aid ) {
				$act->finish( $aid, 'done', null, array(), array( 'step' => __( 'Finished', 'seolity' ) ) );
			}
			$act->job_finished( (int) $job['id'], 'done' );
		} catch ( Cancelled $c ) {
			$db->update( 'jobs', array( 'status' => 'cancelled', 'finished_at' => Database::now(), 'last_error' => $c->getMessage() ), array( 'id' => (int) $job['id'] ) );
			if ( $aid ) {
				$act->finish( $aid, 'cancelled', $c->getMessage() );
			}
			$act->job_finished( (int) $job['id'], 'cancelled', $c->getMessage() );
		} catch ( \SEOlity\AI\Deferred $d ) {
			if ( $aid ) {
				$act->step( $aid, __( 'Waiting for the AI answer from GitHub…', 'seolity' ), null, array( 'status' => 'waiting', 'route' => 'github' ) );
			}
			// Waiting for the subscription runner: re-schedule without counting an attempt.
			$db->query(
				"UPDATE {jobs} SET status = 'waiting', attempts = IF(attempts > 0, attempts - 1, 0), available_at = %s, last_error = %s WHERE id = %d",
				array( gmdate( 'Y-m-d H:i:s', time() + 45 ), $d->getMessage() . ' (' . $d->job_id() . ')', (int) $job['id'] )
			);
			$act->job_waiting( (int) $job['id'] );
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
			if ( $aid ) {
				if ( $final ) {
					$act->finish( $aid, 'failed', $e->getMessage() );
				} else {
					/* translators: %s: error */
					$act->step( $aid, sprintf( __( 'Error, will retry: %s', 'seolity' ), $e->getMessage() ), null, array( 'status' => 'waiting' ) );
				}
			}
			if ( $final ) {
				$act->job_finished( (int) $job['id'], 'failed', $e->getMessage() );
			} else {
				$act->job_waiting( (int) $job['id'], sprintf( __( 'Error, will retry: %s', 'seolity' ), $e->getMessage() ) );
			}
		} finally {
			self::$current = $prevj;
			Activity::set_current( $prev );
		}
	}

	/**
	 * Find jobs whose worker died (no heartbeat within their timeout) and decide what happens:
	 * retry when attempts remain, otherwise end them as "timed_out". Also ends "waiting" jobs
	 * that have waited longer than the subscription wait limit allows.
	 *
	 * @return int Jobs recovered.
	 */
	public function recover() {
		$db   = $this->plugin->db;
		$rows = $db->rows( "SELECT id, hook, attempts, max_attempts, interruptions, timeout_s, heartbeat_at, reserved_at, created_at FROM {jobs} WHERE status = 'running' AND IFNULL(heartbeat_at, reserved_at) < DATE_SUB(%s, INTERVAL timeout_s SECOND) LIMIT 50", array( Database::now() ) );
		$n    = 0;
		foreach ( $rows as $j ) {
			$interrupts = (int) $j['interruptions'] + 1;
			$mins       = max( 1, (int) round( (int) $j['timeout_s'] / 60 ) );
			if ( (int) $j['attempts'] >= (int) $j['max_attempts'] || $interrupts >= 3 ) {
				/* translators: %d: minutes */
				$msg = sprintf( __( 'Stopped: the worker gave no sign of life for %d minutes (PHP time limit, memory limit or a server restart). Use “Retry” to run it again.', 'seolity' ), $mins );
				$ok  = $db->query( "UPDATE {jobs} SET status = 'timed_out', interruptions = %d, finished_at = %s, last_error = %s WHERE id = %d AND status = 'running'", array( $interrupts, Database::now(), $msg, (int) $j['id'] ) );
				if ( $ok ) {
					$this->plugin->activity->job_finished( (int) $j['id'], 'timed_out', $msg );
					do_action( 'seolity_job_failed', $j, new \RuntimeException( $msg ) );
				}
			} else {
				$msg = __( 'Interrupted (the server stopped the worker). Retrying automatically…', 'seolity' );
				$ok  = $db->query( "UPDATE {jobs} SET status = 'pending', interruptions = %d, available_at = %s, last_error = %s WHERE id = %d AND status = 'running'", array( $interrupts, gmdate( 'Y-m-d H:i:s', time() + 30 ), $msg, (int) $j['id'] ) );
				if ( $ok ) {
					$this->plugin->activity->job_waiting( (int) $j['id'], $msg );
				}
			}
			$n++;
		}
		// Jobs that keep waiting for a GitHub answer beyond the configured limit.
		$limit = max( 15, (int) $this->plugin->settings->get( 'sub_wait_minutes' ) + 15 );
		foreach ( $db->rows( "SELECT id FROM {jobs} WHERE status = 'waiting' AND IFNULL(started_at, created_at) < %s LIMIT 50", array( gmdate( 'Y-m-d H:i:s', time() - $limit * MINUTE_IN_SECONDS ) ) ) as $j ) {
			/* translators: %d: minutes */
			$msg = sprintf( __( 'No AI answer arrived within %d minutes.', 'seolity' ), $limit );
			if ( $db->query( "UPDATE {jobs} SET status = 'timed_out', finished_at = %s, last_error = %s WHERE id = %d AND status = 'waiting'", array( Database::now(), $msg, (int) $j['id'] ) ) ) {
				$this->plugin->activity->job_finished( (int) $j['id'], 'timed_out', $msg );
				$n++;
			}
		}
		return $n;
	}

	/* ------------------------------------------------------------------ management */

	public function get( $id ) {
		$j = $this->plugin->db->row( 'SELECT * FROM {jobs} WHERE id = %d', array( (int) $id ) );
		return $j ? $this->shape( $j ) : null;
	}

	private function shape( array $j ) {
		$j = Database::decode( $j, array( 'args' ) );
		foreach ( array( 'id', 'priority', 'attempts', 'max_attempts', 'interruptions', 'progress', 'cancel_requested', 'timeout_s' ) as $k ) {
			if ( isset( $j[ $k ] ) ) {
				$j[ $k ] = (int) $j[ $k ];
			}
		}
		$j['state'] = $j['status'];
		if ( 'pending' === $j['status'] ) {
			$j['state'] = strtotime( $j['available_at'] . ' UTC' ) > time() ? 'scheduled' : 'queued';
			if ( $j['interruptions'] ) {
				$j['state'] = 'interrupted';
			}
		}
		if ( 'running' === $j['status'] && $j['cancel_requested'] ) {
			$j['state'] = 'cancelling';
		}
		$label        = Activity::job_label( $j['hook'] );
		$j['label']   = $label ? $label : Activity::internal_job_label( $j['hook'] );
		$j['can']     = array(
			'cancel' => in_array( $j['status'], self::ACTIVE, true ) && ! $j['cancel_requested'],
			'retry'  => in_array( $j['status'], array( 'failed', 'cancelled', 'timed_out' ), true ),
			'delete' => in_array( $j['status'], self::FINAL_STATES, true ),
		);
		unset( $j['owner'], $j['unique_key'] );
		return $j;
	}

	/**
	 * Cancel a job. Pending/waiting jobs stop at once; a running job stops at its next safe point.
	 *
	 * @return array|\WP_Error The job.
	 */
	public function cancel( $id ) {
		$db  = $this->plugin->db;
		$job = $db->row( 'SELECT * FROM {jobs} WHERE id = %d', array( (int) $id ) );
		if ( ! $job ) {
			return new \WP_Error( 'seolity_job', __( 'Task not found.', 'seolity' ), array( 'status' => 404 ) );
		}
		$msg = __( 'Cancelled by the user.', 'seolity' );
		if ( in_array( $job['status'], array( 'pending', 'waiting' ), true ) ) {
			$db->query( "UPDATE {jobs} SET status = 'cancelled', finished_at = %s, last_error = %s WHERE id = %d AND status IN ('pending','waiting')", array( Database::now(), $msg, (int) $id ) );
			$this->plugin->activity->job_finished( (int) $id, 'cancelled', $msg );
		} elseif ( 'running' === $job['status'] ) {
			$stale = strtotime( ( $job['heartbeat_at'] ? $job['heartbeat_at'] : $job['reserved_at'] ) . ' UTC' ) < time() - 120;
			if ( $stale ) {
				// Nobody is executing it any more: nothing can be half-applied, end it now.
				$db->query( "UPDATE {jobs} SET status = 'cancelled', finished_at = %s, last_error = %s WHERE id = %d AND status = 'running'", array( Database::now(), $msg, (int) $id ) );
				$this->plugin->activity->job_finished( (int) $id, 'cancelled', $msg );
			} else {
				$db->update( 'jobs', array( 'cancel_requested' => 1 ), array( 'id' => (int) $id ) );
				$this->plugin->activity->job_waiting( (int) $id, __( 'Cancelling — the task stops at its next safe point…', 'seolity' ), 'running' );
			}
		} else {
			return new \WP_Error( 'seolity_job', __( 'This task has already finished.', 'seolity' ), array( 'status' => 409 ) );
		}
		$this->plugin->audit_log->log( 'job.cancelled', 'job', (int) $id, array( 'hook' => $job['hook'] ) );
		return $this->get( $id );
	}

	/** Run a finished (failed / cancelled / timed out) job again. */
	public function retry( $id ) {
		$db  = $this->plugin->db;
		$job = $db->row( 'SELECT * FROM {jobs} WHERE id = %d', array( (int) $id ) );
		if ( ! $job ) {
			return new \WP_Error( 'seolity_job', __( 'Task not found.', 'seolity' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( $job['status'], array( 'failed', 'cancelled', 'timed_out' ), true ) ) {
			return new \WP_Error( 'seolity_job', __( 'Only failed, cancelled or timed-out tasks can be retried.', 'seolity' ), array( 'status' => 409 ) );
		}
		if ( $job['unique_key'] && $db->value( "SELECT id FROM {jobs} WHERE unique_key = %s AND status IN ('pending','waiting','running') AND id <> %d", array( $job['unique_key'], (int) $id ) ) ) {
			return new \WP_Error( 'seolity_job', __( 'The same task is already queued or running.', 'seolity' ), array( 'status' => 409 ) );
		}
		$db->update( 'jobs', array( 'status' => 'pending', 'attempts' => 0, 'interruptions' => 0, 'cancel_requested' => 0, 'progress' => 0, 'available_at' => Database::now(), 'finished_at' => null, 'last_error' => null, 'started_at' => null, 'heartbeat_at' => null ), array( 'id' => (int) $id ) );
		$this->plugin->activity->job_retried( (int) $id );
		$this->plugin->audit_log->log( 'job.retried', 'job', (int) $id, array( 'hook' => $job['hook'] ) );
		return $this->get( $id );
	}

	/** Delete a finished job record (history). Active jobs must be cancelled first. */
	public function delete( $id ) {
		$db  = $this->plugin->db;
		$job = $db->row( 'SELECT status FROM {jobs} WHERE id = %d', array( (int) $id ) );
		if ( ! $job ) {
			return new \WP_Error( 'seolity_job', __( 'Task not found.', 'seolity' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( $job['status'], self::FINAL_STATES, true ) ) {
			return new \WP_Error( 'seolity_job', __( 'This task is still active. Cancel it first.', 'seolity' ), array( 'status' => 409 ) );
		}
		return (bool) $db->delete( 'jobs', array( 'id' => (int) $id ) );
	}

	/** Delete all finished jobs of a state (or all final states). */
	public function purge( $status = '' ) {
		$states = in_array( $status, self::FINAL_STATES, true ) ? array( $status ) : self::FINAL_STATES;
		$in     = implode( ',', array_fill( 0, count( $states ), '%s' ) );
		return (int) $this->plugin->db->query( "DELETE FROM {jobs} WHERE status IN ($in)", $states );
	}

	/**
	 * Paged job list for the task manager.
	 *
	 * @param array $f status (active|final|<state>), page, per_page.
	 */
	public function query( array $f ) {
		$db    = $this->plugin->db;
		$where = array( '1=1' );
		$args  = array();
		$st    = isset( $f['status'] ) ? (string) $f['status'] : 'active';
		if ( 'active' === $st ) {
			$where[] = "status IN ('pending','waiting','running')";
		} elseif ( 'final' === $st ) {
			$where[] = "status IN ('done','failed','cancelled','timed_out')";
		} elseif ( in_array( $st, array_merge( self::ACTIVE, self::FINAL_STATES ), true ) ) {
			$where[] = 'status = %s';
			$args[]  = $st;
		}
		$per   = isset( $f['per_page'] ) ? max( 10, min( 200, (int) $f['per_page'] ) ) : 50;
		$page  = isset( $f['page'] ) ? max( 1, (int) $f['page'] ) : 1;
		$total = (int) $db->value( 'SELECT COUNT(*) FROM {jobs} WHERE ' . implode( ' AND ', $where ), $args );
		$rows  = $db->rows( 'SELECT * FROM {jobs} WHERE ' . implode( ' AND ', $where ) . " ORDER BY FIELD(status,'running','waiting','pending','failed','timed_out','cancelled','done'), id DESC LIMIT %d OFFSET %d", array_merge( $args, array( $per, ( $page - 1 ) * $per ) ) );
		return array(
			'items' => array_map( array( $this, 'shape' ), $rows ),
			'total' => $total,
			'page'  => $page,
			'stats' => $this->counts(),
		);
	}

	/** Counts per real state (after recovery, so a dead job is never counted as running). */
	public function counts() {
		$rows = $this->plugin->db->rows( "SELECT status, (status = 'pending' AND available_at > %s) AS later, COUNT(*) AS c FROM {jobs} GROUP BY status, later", array( Database::now() ) );
		$out  = array( 'queued' => 0, 'scheduled' => 0, 'waiting' => 0, 'running' => 0, 'done' => 0, 'failed' => 0, 'cancelled' => 0, 'timed_out' => 0 );
		foreach ( $rows as $r ) {
			$k = 'pending' === $r['status'] ? ( (int) $r['later'] ? 'scheduled' : 'queued' ) : $r['status'];
			if ( isset( $out[ $k ] ) ) {
				$out[ $k ] += (int) $r['c'];
			}
		}
		return $out;
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

	/** Stats for the Automation screen (kept for compatibility; counts reflect real states). */
	public function stats() {
		$c   = $this->counts();
		$out = array(
			'pending'   => $c['queued'] + $c['scheduled'],
			'running'   => $c['running'],
			'waiting'   => $c['waiting'],
			'done'      => $c['done'],
			'failed'    => $c['failed'] + $c['timed_out'],
			'cancelled' => $c['cancelled'],
		);
		$out['recent']    = $this->plugin->db->rows( 'SELECT id, hook, status, attempts, last_error, created_at, finished_at FROM {jobs} ORDER BY id DESC LIMIT 25' );
		$out['next_cron'] = wp_next_scheduled( Scheduler::TICK );
		return $out;
	}

	public function retry_failed() {
		return $this->plugin->db->query( "UPDATE {jobs} SET status = 'pending', attempts = 0, interruptions = 0, cancel_requested = 0, finished_at = NULL, available_at = %s WHERE status IN ('failed','timed_out')", array( Database::now() ) );
	}

	public function pending_in_group( $group ) {
		return (int) $this->plugin->db->value( "SELECT COUNT(*) FROM {jobs} WHERE group_key = %s AND status IN ('pending','waiting','running')", array( $group ) );
	}

	/** Delete finished jobs older than a few days. */
	public function cleanup() {
		$this->plugin->db->query(
			"DELETE FROM {jobs} WHERE status IN ('done','failed','cancelled','timed_out') AND finished_at < %s",
			array( gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) )
		);
	}
}
