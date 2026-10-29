<?php
/**
 * Live activity & timeline: everything SEOlity does on the site, as it happens.
 *
 * Each row is one piece of work — an AI request (with the route it took: direct, Cloudflare,
 * GitHub or this server, and the error if it failed), a background job (Search Console sync,
 * audit, archive…), an AI edit or agent run (with its steps), a chat answer, or an event
 * (a change applied, a redirect created, settings saved).
 *
 * "Live activity" shows rows that are running or waiting; "Done" is the timeline of the rest.
 * Rows flagged `dock` belong to a user's small progress cards (they survive page loads until
 * the user closes them).
 *
 * @package SEOlity
 */

namespace SEOlity\Activity;

use SEOlity\Core\Database;
use SEOlity\Plugin;
use SEOlity\Queue\Queue;

defined( 'ABSPATH' ) || exit;

final class Activity {

	const KINDS = array( 'ai', 'job', 'agent', 'edit', 'chat', 'change', 'event' );

	/** Work that is not finished yet. */
	const ACTIVE = array( 'queued', 'running', 'waiting' );

	/** Final states (interrupted = the server stopped the work; timed_out = no answer in time). */
	const FINAL_STATES = array( 'done', 'failed', 'cancelled', 'timed_out', 'interrupted', 'info' );

	/** @var Plugin */
	private $plugin;

	/** @var int Activity that nested work reports its steps to (agent / job in progress). */
	private static $current = 0;

	/** @var bool Disabled while the table does not exist yet (fresh install mid-upgrade). */
	private $ok = null;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	private function ready() {
		if ( null === $this->ok ) {
			$this->ok = (bool) $this->plugin->db->wpdb->get_var( $this->plugin->db->wpdb->prepare( 'SHOW TABLES LIKE %s', $this->plugin->db->table( 'activity' ) ) ); // phpcs:ignore WordPress.DB
		}
		return $this->ok;
	}

	public static function set_current( $id ) {
		$prev          = self::$current;
		self::$current = (int) $id;
		return $prev;
	}

	public static function current() {
		return self::$current;
	}

	/* ------------------------------------------------------------------ writing */

	/**
	 * @param string $kind  ai|job|agent|edit|chat|change|event.
	 * @param string $title Short human title.
	 * @param array  $o     detail, ref, dock, user_id, status, step, route, meta.
	 * @return int Activity id (0 when logging is unavailable).
	 */
	public function start( $kind, $title, array $o = array() ) {
		if ( ! $this->ready() ) {
			return 0;
		}
		$now = Database::now();
		return (int) $this->plugin->db->insert(
			'activity',
			array(
				'project_id' => $this->plugin->db->project_id(),
				'user_id'    => isset( $o['user_id'] ) ? (int) $o['user_id'] : get_current_user_id(),
				'kind'       => in_array( $kind, self::KINDS, true ) ? $kind : 'event',
				'title'      => mb_substr( wp_strip_all_tags( (string) $title ), 0, 250 ),
				'detail'     => isset( $o['detail'] ) ? mb_substr( (string) $o['detail'], 0, 2000 ) : '',
				'status'     => isset( $o['status'] ) ? $o['status'] : 'running',
				'step'       => isset( $o['step'] ) ? mb_substr( (string) $o['step'], 0, 250 ) : '',
				'progress'   => isset( $o['progress'] ) ? (int) $o['progress'] : 0,
				'route'      => isset( $o['route'] ) ? (string) $o['route'] : '',
				'ref'        => isset( $o['ref'] ) ? substr( (string) $o['ref'], 0, 80 ) : '',
				'meta'       => isset( $o['meta'] ) ? $o['meta'] : array(),
				'dock'       => ! empty( $o['dock'] ) ? 1 : 0,
				'dismissed'  => 0,
				'job_id'     => ! empty( $o['job_id'] ) ? (int) $o['job_id'] : null,
				'started_at' => $now,
				'updated_at' => $now,
				'finished_at' => in_array( isset( $o['status'] ) ? $o['status'] : 'running', self::FINAL_STATES, true ) ? $now : null,
			)
		);
	}

	/** One-off entry for the timeline (already finished). */
	public function event( $kind, $title, $detail = '', $status = 'done', array $meta = array(), $ref = '' ) {
		return $this->start( $kind, $title, array( 'detail' => $detail, 'status' => $status, 'meta' => $meta, 'ref' => $ref ) );
	}

	/** Report progress: a new step line (kept in the row's step log) and optional percentage. */
	public function step( $id, $text, $progress = null, array $extra = array() ) {
		$id = (int) ( $id ? $id : self::$current );
		if ( ! $id || ! $this->ready() ) {
			return;
		}
		$row  = $this->plugin->db->row( 'SELECT meta FROM {activity} WHERE id = %d', array( $id ) );
		$meta = $row ? (array) json_decode( (string) $row['meta'], true ) : array();
		$meta['steps']   = isset( $meta['steps'] ) ? (array) $meta['steps'] : array();
		$meta['steps'][] = array( 't' => gmdate( 'c' ), 'text' => mb_substr( (string) $text, 0, 300 ), 'ok' => isset( $extra['ok'] ) ? (bool) $extra['ok'] : null );
		$meta['steps']   = array_slice( $meta['steps'], -60 );
		$data            = array( 'step' => mb_substr( (string) $text, 0, 250 ), 'meta' => wp_json_encode( $meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), 'updated_at' => Database::now() );
		if ( null !== $progress ) {
			$data['progress'] = max( 0, min( 100, (int) $progress ) );
		}
		foreach ( array( 'status', 'route' ) as $k ) {
			if ( isset( $extra[ $k ] ) ) {
				$data[ $k ] = $extra[ $k ];
			}
		}
		$this->plugin->db->update( 'activity', $data, array( 'id' => $id ) );
		// Progress from inside a background job also proves the job is alive.
		$this->plugin->queue->heartbeat( null === $progress ? null : (int) $progress );
	}

	/**
	 * Finish an activity.
	 *
	 * @param string $status done|failed|waiting|cancelled.
	 * @param array  $meta   Merged into the stored meta (e.g. result, error, tokens).
	 */
	public function finish( $id, $status, $detail = null, array $meta = array(), array $extra = array() ) {
		$id = (int) $id;
		if ( ! $id || ! $this->ready() ) {
			return;
		}
		$row  = $this->plugin->db->row( 'SELECT meta, status FROM {activity} WHERE id = %d', array( $id ) );
		$old  = $row ? (array) json_decode( (string) $row['meta'], true ) : array();
		if ( $row && 'cancelled' === $row['status'] && 'cancelled' !== $status ) {
			// The owner cancelled it: a late result is kept for reference but the state stays "cancelled".
			$status = 'cancelled';
			$detail = null;
		}
		$data = array(
			'status'      => $status,
			'meta'        => wp_json_encode( array_merge( $old, $meta ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'updated_at'  => Database::now(),
			'finished_at' => in_array( $status, self::FINAL_STATES, true ) ? Database::now() : null,
		);
		if ( 'done' === $status ) {
			$data['progress'] = 100;
		}
		if ( null !== $detail ) {
			$data['detail'] = mb_substr( (string) $detail, 0, 2000 );
		}
		foreach ( array( 'route', 'step', 'title' ) as $k ) {
			if ( isset( $extra[ $k ] ) ) {
				$data[ $k ] = $extra[ $k ];
			}
		}
		$this->plugin->db->update( 'activity', $data, array( 'id' => $id ) );
	}

	/** Re-open a waiting row with the same reference (e.g. an AI request answered after a GitHub wait). */
	public function find_open( $ref ) {
		if ( ! $this->ready() || '' === (string) $ref ) {
			return 0;
		}
		return (int) $this->plugin->db->value( "SELECT id FROM {activity} WHERE ref = %s AND status IN ('queued','running','waiting') ORDER BY id DESC LIMIT 1", array( substr( (string) $ref, 0, 80 ) ) );
	}

	/** Merge keys into an activity's meta. */
	public function merge_meta( $id, array $meta ) {
		if ( ! $id || ! $this->ready() ) {
			return;
		}
		$row = $this->plugin->db->row( 'SELECT meta FROM {activity} WHERE id = %d', array( (int) $id ) );
		$old = $row ? (array) json_decode( (string) $row['meta'], true ) : array();
		$this->plugin->db->update( 'activity', array( 'meta' => wp_json_encode( array_merge( $old, $meta ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ), array( 'id' => (int) $id ) );
	}

	/* ------------------------------------------------------------------ job ↔ activity */

	/** Link an activity to the queue job that will do the work (it shows "queued" until the job starts). */
	public function attach_job( $id, $job_id ) {
		if ( ! $id || ! $job_id || ! $this->ready() ) {
			return;
		}
		$this->plugin->db->update( 'activity', array( 'job_id' => (int) $job_id, 'status' => 'queued', 'updated_at' => Database::now() ), array( 'id' => (int) $id ) );
	}

	public function job_started( $job_id ) {
		if ( ! $this->ready() ) {
			return;
		}
		$this->plugin->db->query( "UPDATE {activity} SET status = 'running', updated_at = %s WHERE job_id = %d AND status IN ('queued','waiting')", array( Database::now(), (int) $job_id ) );
	}

	/** The job waits (GitHub answer, retry after an error or an interruption). */
	public function job_waiting( $job_id, $text = '', $status = 'waiting' ) {
		if ( ! $this->ready() ) {
			return;
		}
		foreach ( $this->plugin->db->column( "SELECT id FROM {activity} WHERE job_id = %d AND status IN ('queued','running','waiting')", array( (int) $job_id ) ) as $aid ) {
			if ( '' !== $text ) {
				$this->step( (int) $aid, $text, null, array( 'status' => $status ) );
			} else {
				$this->plugin->db->update( 'activity', array( 'status' => $status, 'updated_at' => Database::now() ), array( 'id' => (int) $aid ) );
			}
		}
	}

	/** The job reached a final state: close linked activities the handler did not finish itself. */
	public function job_finished( $job_id, $status, $text = '' ) {
		if ( ! $this->ready() ) {
			return;
		}
		$map = array( 'done' => 'done', 'failed' => 'failed', 'cancelled' => 'cancelled', 'timed_out' => 'timed_out' );
		$st  = isset( $map[ $status ] ) ? $map[ $status ] : 'failed';
		foreach ( $this->plugin->db->column( "SELECT id FROM {activity} WHERE job_id = %d AND status IN ('queued','running','waiting')", array( (int) $job_id ) ) as $aid ) {
			$this->finish( (int) $aid, $st, '' !== $text ? $text : null, array(), array( 'step' => 'done' === $st ? __( 'Finished', 'seolity' ) : ( '' !== $text ? mb_substr( $text, 0, 250 ) : '' ) ) );
		}
		do_action( 'seolity_job_closed', (int) $job_id, $st, $text );
	}

	/** A finished job was queued again: its cards go back to "queued". */
	public function job_retried( $job_id ) {
		if ( ! $this->ready() ) {
			return;
		}
		$this->plugin->db->query( "UPDATE {activity} SET status = 'queued', finished_at = NULL, dismissed = 0, step = %s, updated_at = %s WHERE job_id = %d AND status IN ('failed','cancelled','timed_out','interrupted')", array( __( 'Queued again', 'seolity' ), Database::now(), (int) $job_id ) );
	}

	/**
	 * Bring every unfinished row in line with reality (throttled to once per 30 s unless forced):
	 * job-backed rows follow their job, request-bound rows that stopped reporting are closed as
	 * "interrupted", and waits longer than the limit end as "timed_out". Counts of running work are
	 * therefore always based on work that is really alive.
	 */
	public function reconcile( $force = false ) {
		if ( ! $this->ready() ) {
			return 0;
		}
		if ( ! $force && get_transient( 'seolity_activity_reconciled' ) ) {
			return 0;
		}
		set_transient( 'seolity_activity_reconciled', 1, 30 );
		$db = $this->plugin->db;
		$n  = $this->plugin->queue->recover();
		// 1. Rows backed by a queue job.
		$rows = $db->rows( "SELECT a.id, a.status, a.job_id, j.status AS job_status, j.last_error, j.interruptions FROM {activity} a LEFT JOIN {jobs} j ON j.id = a.job_id WHERE a.job_id IS NOT NULL AND a.status IN ('queued','running','waiting')" );
		foreach ( $rows as $r ) {
			if ( null === $r['job_status'] ) {
				$this->finish( (int) $r['id'], 'interrupted', __( 'The background task no longer exists (it was removed or cleaned up).', 'seolity' ) );
				$n++;
			} elseif ( in_array( $r['job_status'], Queue::FINAL_STATES, true ) ) {
				$this->job_finished( (int) $r['job_id'], $r['job_status'], (string) $r['last_error'] );
				$n++;
			} elseif ( 'running' !== $r['job_status'] && 'running' === $r['status'] ) {
				$db->update( 'activity', array( 'status' => 'pending' === $r['job_status'] && ! (int) $r['interruptions'] ? 'queued' : 'waiting' ), array( 'id' => (int) $r['id'] ) );
				$n++;
			}
		}
		// 2. Work bound to a web request (AI calls, chat answers, edits) that stopped reporting.
		$idle = max( 15 * MINUTE_IN_SECONDS, (int) $this->plugin->settings->get( 'ai_timeout' ) * 4 );
		foreach ( $db->column( "SELECT id FROM {activity} WHERE job_id IS NULL AND status IN ('running','queued') AND updated_at < %s LIMIT 200", array( gmdate( 'Y-m-d H:i:s', time() - $idle ) ) ) as $id ) {
			$this->finish( (int) $id, 'interrupted', __( 'Stopped without finishing: the server ended the request (PHP time limit or closed connection).', 'seolity' ) );
			$n++;
		}
		// 3. Waits (GitHub answers) beyond the limit.
		$limit = max( 15, (int) $this->plugin->settings->get( 'sub_wait_minutes' ) + 15 );
		foreach ( $db->column( "SELECT id FROM {activity} WHERE job_id IS NULL AND status = 'waiting' AND updated_at < %s LIMIT 200", array( gmdate( 'Y-m-d H:i:s', time() - $limit * MINUTE_IN_SECONDS ) ) ) as $id ) {
			/* translators: %d: minutes */
			$this->finish( (int) $id, 'timed_out', sprintf( __( 'No AI answer arrived within %d minutes.', 'seolity' ), $limit ) );
			$n++;
		}
		return $n;
	}

	/* ------------------------------------------------------------------ management */

	/** Cancel unfinished work. Job-backed work stops at its next safe point. */
	public function cancel( $id ) {
		$a = $this->get( $id, false );
		if ( ! $a ) {
			return new \WP_Error( 'seolity_activity', __( 'Not found.', 'seolity' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( $a['status'], self::ACTIVE, true ) ) {
			return new \WP_Error( 'seolity_activity', __( 'This task has already finished.', 'seolity' ), array( 'status' => 409 ) );
		}
		if ( $a['job_id'] ) {
			$res = $this->plugin->queue->cancel( (int) $a['job_id'] );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		} else {
			$this->finish( (int) $id, 'cancelled', __( 'Cancelled by the user.', 'seolity' ) );
		}
		return $this->get( $id, false );
	}

	/** Run failed / cancelled / interrupted work again. */
	public function retry( $id ) {
		$a = $this->get( $id );
		if ( ! $a ) {
			return new \WP_Error( 'seolity_activity', __( 'Not found.', 'seolity' ), array( 'status' => 404 ) );
		}
		if ( in_array( $a['status'], self::ACTIVE, true ) || 'done' === $a['status'] || 'info' === $a['status'] ) {
			return new \WP_Error( 'seolity_activity', __( 'Only failed, cancelled, interrupted or timed-out work can be retried.', 'seolity' ), array( 'status' => 409 ) );
		}
		$meta = (array) $a['meta'];
		if ( $a['job_id'] && $this->plugin->queue->get( (int) $a['job_id'] ) ) {
			$res = $this->plugin->queue->retry( (int) $a['job_id'] );
			return is_wp_error( $res ) ? $res : $this->get( $id, false );
		}
		if ( 'agent' === $a['kind'] && ! empty( $meta['rec_id'] ) ) {
			return $this->plugin->agent->start( (int) $meta['rec_id'] );
		}
		if ( ! empty( $meta['type'] ) && in_array( $meta['type'], array( 'optimize', 'meta', 'product' ), true ) && ! empty( $meta['params'] ) ) {
			return $this->plugin->aijobs->start( $meta['type'], (array) $meta['params'] );
		}
		if ( 'chat' === $a['kind'] && ! empty( $meta['message_id'] ) ) {
			return $this->plugin->chat->retry( (int) $meta['message_id'] );
		}
		return new \WP_Error( 'seolity_activity', __( 'This entry cannot be repeated automatically; start it again from its page.', 'seolity' ), array( 'status' => 409 ) );
	}

	/** Delete finished entries (history). Unfinished work must be cancelled first. */
	public function delete( array $ids ) {
		if ( ! $this->ready() ) {
			return 0;
		}
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return 0;
		}
		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		return (int) $this->plugin->db->query( "DELETE FROM {activity} WHERE project_id = %d AND id IN ($in) AND status NOT IN ('queued','running','waiting')", array_merge( array( $this->plugin->db->project_id() ), $ids ) );
	}

	/** Delete finished history by filter (kind / status / older than N days). */
	public function purge( array $f ) {
		$db    = $this->plugin->db;
		$where = array( 'project_id = %d', "status NOT IN ('queued','running','waiting')" );
		$args  = array( $db->project_id() );
		if ( ! empty( $f['kind'] ) && in_array( $f['kind'], self::KINDS, true ) ) {
			$where[] = 'kind = %s';
			$args[]  = $f['kind'];
		}
		if ( ! empty( $f['status'] ) && in_array( $f['status'], self::FINAL_STATES, true ) ) {
			$where[] = 'status = %s';
			$args[]  = $f['status'];
		}
		if ( ! empty( $f['older_than'] ) ) {
			$where[] = 'started_at < %s';
			$args[]  = gmdate( 'Y-m-d H:i:s', time() - (int) $f['older_than'] * DAY_IN_SECONDS );
		}
		return (int) $db->query( 'DELETE FROM {activity} WHERE ' . implode( ' AND ', $where ), $args );
	}

	public function dismiss( $id ) {
		return (bool) $this->plugin->db->update( 'activity', array( 'dismissed' => 1 ), array( 'id' => (int) $id, 'user_id' => get_current_user_id() ) );
	}

	/* ------------------------------------------------------------------ reading */

	private static function shape( $r ) {
		$r = Database::decode( $r, array( 'meta' ) );
		foreach ( array( 'id', 'user_id', 'progress', 'dock', 'dismissed', 'job_id' ) as $k ) {
			if ( isset( $r[ $k ] ) ) {
				$r[ $k ] = (int) $r[ $k ];
			}
		}
		$end         = $r['finished_at'] ? strtotime( $r['finished_at'] . ' UTC' ) : time();
		$r['ms']     = max( 0, ( $end - strtotime( $r['started_at'] . ' UTC' ) ) * 1000 );
		return $r;
	}

	public function get( $id, $with_result = true ) {
		$r = $this->plugin->db->row( 'SELECT * FROM {activity} WHERE id = %d AND project_id = %d', array( (int) $id, $this->plugin->db->project_id() ) );
		if ( ! $r ) {
			return null;
		}
		$r = self::shape( $r );
		if ( ! $with_result && isset( $r['meta']['result'] ) ) {
			unset( $r['meta']['result'] );
		}
		return $r;
	}

	/** Unfinished work plus what finished in the last minutes (failures: last hour). */
	public function live() {
		if ( ! $this->ready() ) {
			return array( 'items' => array(), 'counts' => array() );
		}
		$this->reconcile();
		$db   = $this->plugin->db;
		$rows = $db->rows(
			"SELECT id, user_id, kind, title, detail, status, step, progress, route, ref, dock, job_id, started_at, updated_at, finished_at FROM {activity} WHERE project_id = %d AND ( status IN ('queued','running','waiting') OR ( status IN ('failed','timed_out','interrupted') AND finished_at >= %s ) OR finished_at >= %s ) ORDER BY FIELD(status,'running','waiting','queued','failed','timed_out','interrupted','cancelled','done','info'), id DESC LIMIT 80",
			array( $db->project_id(), gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ), gmdate( 'Y-m-d H:i:s', time() - 5 * MINUTE_IN_SECONDS ) )
		);
		$out    = array();
		$counts = array( 'running' => 0, 'waiting' => 0, 'queued' => 0, 'failed' => 0 );
		foreach ( $rows as $r ) {
			$r     = self::shape( $r + array( 'meta' => null ) );
			$out[] = $r;
			if ( isset( $counts[ $r['status'] ] ) ) {
				$counts[ $r['status'] ]++;
			} elseif ( in_array( $r['status'], array( 'timed_out', 'interrupted' ), true ) ) {
				$counts['failed']++;
			}
		}
		$q = $this->plugin->queue->counts();
		return array(
			'items'  => $out,
			'counts' => $counts,
			'queue'  => array( 'pending' => $q['queued'] + $q['scheduled'], 'queued' => $q['queued'], 'scheduled' => $q['scheduled'], 'running' => $q['running'], 'waiting' => $q['waiting'], 'failed' => $q['failed'] + $q['timed_out'] ),
			'time'   => gmdate( 'c' ),
		);
	}

	/** Timeline of finished work. Filters: kind, status, q, page, per_page, day. */
	public function history( array $f ) {
		$db    = $this->plugin->db;
		$where = array( 'project_id = %d', "status NOT IN ('queued','running','waiting')" );
		$args  = array( $db->project_id() );
		if ( ! empty( $f['kind'] ) && in_array( $f['kind'], self::KINDS, true ) ) {
			$where[] = 'kind = %s';
			$args[]  = $f['kind'];
		}
		if ( ! empty( $f['status'] ) && in_array( $f['status'], self::FINAL_STATES, true ) ) {
			$where[] = 'status = %s';
			$args[]  = $f['status'];
		}
		if ( ! empty( $f['q'] ) ) {
			$where[] = '(title LIKE %s OR detail LIKE %s)';
			$like    = '%' . $db->wpdb->esc_like( (string) $f['q'] ) . '%';
			$args[]  = $like;
			$args[]  = $like;
		}
		$per   = isset( $f['per_page'] ) ? max( 10, min( 200, (int) $f['per_page'] ) ) : 60;
		$page  = isset( $f['page'] ) ? max( 1, (int) $f['page'] ) : 1;
		$total = (int) $db->value( 'SELECT COUNT(*) FROM {activity} WHERE ' . implode( ' AND ', $where ), $args );
		$rows  = $db->rows( 'SELECT id, user_id, kind, title, detail, status, step, progress, route, ref, meta, job_id, started_at, updated_at, finished_at FROM {activity} WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d', array_merge( $args, array( $per, ( $page - 1 ) * $per ) ) );
		$names = array();
		$items = array_map(
			static function ( $r ) use ( &$names ) {
				$r = self::shape( $r );
				unset( $r['meta']['result'] );
				$uid = (int) $r['user_id'];
				if ( $uid && ! isset( $names[ $uid ] ) ) {
					$u             = get_userdata( $uid );
					$names[ $uid ] = $u ? $u->display_name : '';
				}
				$r['user'] = $uid ? $names[ $uid ] : '';
				return $r;
			},
			$rows
		);
		$stats = array();
		foreach ( $db->rows( 'SELECT kind, status, COUNT(*) c FROM {activity} WHERE project_id = %d AND started_at >= %s GROUP BY kind, status', array( $db->project_id(), gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) ) ) as $s ) {
			$stats[ $s['kind'] ][ $s['status'] ] = (int) $s['c'];
		}
		return array( 'items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $per, 'stats_7d' => $stats );
	}

	/** The current user's progress cards (running or finished, not yet closed). */
	public function dock_items() {
		if ( ! $this->ready() ) {
			return array();
		}
		$this->reconcile();
		return array_map(
			static function ( $r ) {
				return self::shape( $r );
			},
			$this->plugin->db->rows( "SELECT * FROM {activity} WHERE user_id = %d AND dock = 1 AND dismissed = 0 AND started_at >= %s ORDER BY id DESC LIMIT 8", array( get_current_user_id(), gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ) ) )
		);
	}

	public function cleanup( $days = 90 ) {
		if ( $this->ready() ) {
			$this->plugin->db->query( 'DELETE FROM {activity} WHERE started_at < %s', array( gmdate( 'Y-m-d H:i:s', time() - (int) $days * DAY_IN_SECONDS ) ) );
		}
	}

	/* ------------------------------------------------------------------ labels */

	/** Human label for an AI task name. */
	public static function task_label( $task ) {
		$l = array(
			'optimize_content'   => __( 'Content optimization', 'seolity' ),
			'meta_tags'          => __( 'SEO title & description', 'seolity' ),
			'ctr_rewrite'        => __( 'CTR title & description rewrite', 'seolity' ),
			'product_seo'        => __( 'Product SEO', 'seolity' ),
			'image_alt'          => __( 'Image ALT text', 'seolity' ),
			'media_seo'          => __( 'Media SEO (ALT, title, caption)', 'seolity' ),
			'keyword_research'   => __( 'Keyword research', 'seolity' ),
			'search_intent'      => __( 'Search intent analysis', 'seolity' ),
			'content_gap'        => __( 'Content gap analysis', 'seolity' ),
			'competitor_summary' => __( 'Competitor analysis', 'seolity' ),
			'cannibalization'    => __( 'Cannibalization advice', 'seolity' ),
			'consolidate'        => __( 'Merge duplicate articles', 'seolity' ),
			'not_found_triage'   => __( '404 review (301 / 410 / keep)', 'seolity' ),
			'keyword_plan'       => __( 'Focus keyword plan', 'seolity' ),
			'internal_links'     => __( 'Internal link suggestions', 'seolity' ),
			'faq_generate'       => __( 'FAQ generation', 'seolity' ),
			'content_brief'      => __( 'Content brief', 'seolity' ),
			'content_plan'       => __( 'Content plan', 'seolity' ),
			'topic_clusters'     => __( 'Topic clusters', 'seolity' ),
			'redirect_target'    => __( 'Redirect target', 'seolity' ),
			'experiment_verdict' => __( 'Experiment verdict', 'seolity' ),
			'briefing'           => __( 'Daily briefing', 'seolity' ),
			'chat'               => __( 'Chat answer', 'seolity' ),
			'eeat_analysis'      => __( 'E-E-A-T analysis', 'seolity' ),
			'schema_suggest'     => __( 'Schema suggestion', 'seolity' ),
			'serp_analysis'      => __( 'SERP analysis', 'seolity' ),
			'seo_roadmap'        => __( 'SEO roadmap analysis', 'seolity' ),
			'agent_edit'         => __( 'AI edit', 'seolity' ),
		);
		return isset( $l[ $task ] ) ? $l[ $task ] : ucfirst( str_replace( '_', ' ', (string) $task ) );
	}

	/** Background jobs that appear in the activity (noisy internal jobs stay hidden). */
	public static function job_label( $hook ) {
		$l = array(
			'daily_pipeline'          => __( 'Daily SEO pipeline', 'seolity' ),
			'weekly_pipeline'         => __( 'Weekly SEO pipeline', 'seolity' ),
			'gsc_sync'                => __( 'Search Console sync', 'seolity' ),
			'gsc_sync_range'          => __( 'Search Console history import', 'seolity' ),
			'crawl_start'             => __( 'Site audit started', 'seolity' ),
			'crawl_finalize'          => __( 'Site audit finished', 'seolity' ),
			'recommendations_refresh' => __( 'Recommendations refresh', 'seolity' ),
			'keywords_sync'           => __( 'Keyword tracking sync', 'seolity' ),
			'experiments_evaluate'    => __( 'Experiment evaluation', 'seolity' ),
			'alerts_check'            => __( 'Alert check', 'seolity' ),
			'briefing_generate'       => __( 'Daily briefing', 'seolity' ),
			'links_suggest'           => __( 'Internal link suggestions', 'seolity' ),
			'clusters_build'          => __( 'Topic clusters', 'seolity' ),
			'competitor_analyze'      => __( 'Competitor analysis', 'seolity' ),
			'competitor_summarize'    => __( 'Competitor summary', 'seolity' ),
			'images_scan'             => __( 'Image scan', 'seolity' ),
			'bulk_item'               => __( 'Bulk AI optimization item', 'seolity' ),
			'report_generate'         => __( 'Report', 'seolity' ),
			'knowledge_sync'          => __( 'Knowledge sync from GitHub', 'seolity' ),
			'archive_run'             => __( 'Archive data to GitHub', 'seolity' ),
			'pagespeed_run'           => __( 'PageSpeed check', 'seolity' ),
			'roadmap_build'           => __( 'SEO roadmap analysis', 'seolity' ),
			'route_probe'             => __( 'Route speed test', 'seolity' ),
			'indexability_scan'       => __( 'Indexability & crawl budget audit', 'seolity' ),
			'consolidate_prepare'     => __( 'Merging duplicate articles', 'seolity' ),
			'full_analysis'           => __( 'Full-site analysis', 'seolity' ),
			'cli_install'             => __( 'Installing the official CLI', 'seolity' ),
			'experiments_history'     => __( 'Measuring past edits', 'seolity' ),
			'not_found_triage'        => __( 'AI review of 404 errors', 'seolity' ),
			'duplicates_scan'         => __( 'Duplicate & similar articles analysis', 'seolity' ),
			'cleanup'                 => __( 'Clean-up', 'seolity' ),
		);
		$l = apply_filters( 'seolity_activity_jobs', $l );
		return isset( $l[ $hook ] ) ? $l[ $hook ] : null;
	}

	/** Label for internal jobs that do not get their own timeline row (shown in the task manager). */
	public static function internal_job_label( $hook ) {
		$l = array(
			'agent_run'     => __( 'AI agent run', 'seolity' ),
			'ai_job'        => __( 'AI edit', 'seolity' ),
			'chat_reply'    => __( 'Chat answer', 'seolity' ),
			'crawl_batch'   => __( 'Site audit: crawling a batch of pages', 'seolity' ),
			'remote_flush'  => __( 'Send AI requests to GitHub', 'seolity' ),
			'remote_collect' => __( 'Collect AI answers from GitHub', 'seolity' ),
			'operation_run' => __( 'SEO operation', 'seolity' ),
			'full_analysis' => __( 'Full-site analysis', 'seolity' ),
		);
		$l = apply_filters( 'seolity_internal_job_labels', $l );
		return isset( $l[ $hook ] ) ? $l[ $hook ] : ucfirst( str_replace( '_', ' ', (string) $hook ) );
	}

	public static function field_label( $f ) {
		$l = array(
			'post_content'     => __( 'Content', 'seolity' ),
			'post_title'       => __( 'Title', 'seolity' ),
			'post_excerpt'     => __( 'Short description', 'seolity' ),
			'meta_title'       => __( 'SEO title', 'seolity' ),
			'meta_description' => __( 'Meta description', 'seolity' ),
			'focus_keyword'    => __( 'Focus keyword', 'seolity' ),
			'alt'              => __( 'Image ALT', 'seolity' ),
			'faq_schema'       => __( 'FAQ', 'seolity' ),
			'noindex'          => __( 'Robots (noindex)', 'seolity' ),
			'term_description' => __( 'Category description', 'seolity' ),
		);
		return isset( $l[ $f ] ) ? $l[ $f ] : $f;
	}

	/** Timeline text for audit-log actions. */
	public static function event_label( $action, $object_type, $object_id, array $d ) {
		$obj = '';
		if ( $object_id && in_array( $object_type, array( 'post', 'term', 'attachment' ), true ) ) {
			$obj = \SEOlity\Content\Objects::label( $object_type, (int) $object_id );
		}
		$field = isset( $d['field'] ) ? self::field_label( (string) $d['field'] ) : '';
		switch ( $action ) {
			case 'change.applied':
			case 'change.auto_applied':
				/* translators: 1 field 2 object */
				return array( 'change', sprintf( __( 'Applied: %1$s — %2$s', 'seolity' ), $field, $obj ) );
			case 'change.reverted':
				/* translators: 1 field 2 object */
				return array( 'change', sprintf( __( 'Undone: %1$s — %2$s', 'seolity' ), $field, $obj ) );
			case 'change.rejected':
				/* translators: 1 field 2 object */
				return array( 'change', sprintf( __( 'Suggestion rejected: %1$s — %2$s', 'seolity' ), $field, $obj ) );
			case 'change.proposed':
				/* translators: 1 field 2 object */
				return array( 'change', sprintf( __( 'Suggestion prepared: %1$s — %2$s', 'seolity' ), $field, $obj ) );
			case 'redirect.saved':
				/* translators: 1 source 2 target */
				return array( 'change', sprintf( __( 'Redirect saved: %1$s → %2$s', 'seolity' ), isset( $d['source'] ) ? $d['source'] : '', isset( $d['target'] ) ? $d['target'] : '' ) );
			case 'redirect.deleted':
				return array( 'change', __( 'Redirect deleted', 'seolity' ) );
			case 'settings.updated':
				return array( 'event', __( 'Settings saved', 'seolity' ) );
			case 'audit.started':
				return array( 'event', __( 'Site audit started', 'seolity' ) );
			case 'audit.completed':
				return array( 'event', __( 'Site audit completed', 'seolity' ) );
			case 'roadmap.built':
				return array( 'event', __( 'SEO roadmap built', 'seolity' ) );
			case 'storage.archived':
				return array( 'event', __( 'Old data archived to GitHub', 'seolity' ) );
			case 'recommendation.executed':
				return array( 'event', __( 'Recommendation executed', 'seolity' ) );
			case 'gsc.connected':
				return array( 'event', __( 'Search Console connected', 'seolity' ) );
			case 'cloudflare.deployed':
				return array( 'event', __( 'Cloudflare worker deployed', 'seolity' ) );
			case 'subscription.deployed':
			case 'subscription.setup':
				return array( 'event', __( 'Subscription AI runner set up on GitHub', 'seolity' ) );
			case 'knowledge.collect':
				return array( 'event', __( 'Knowledge collection started on GitHub', 'seolity' ) );
			case 'bulk.queued':
				return array( 'event', __( 'Bulk AI optimization queued', 'seolity' ) );
			case 'rule.executed':
				return array( 'event', __( 'Automation rule ran', 'seolity' ) );
			case 'report.generated':
				return array( 'event', __( 'Report generated', 'seolity' ) );
			case 'network.saved':
				return array( 'event', __( 'Network route saved', 'seolity' ) );
		}
		return array( 'event', ucfirst( str_replace( array( '.', '_' ), ' ', $action ) ) . ( $obj ? ' — ' . $obj : '' ) );
	}
}
