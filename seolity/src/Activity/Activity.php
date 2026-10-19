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

defined( 'ABSPATH' ) || exit;

final class Activity {

	const KINDS = array( 'ai', 'job', 'agent', 'edit', 'chat', 'change', 'event' );

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
				'started_at' => $now,
				'updated_at' => $now,
				'finished_at' => in_array( isset( $o['status'] ) ? $o['status'] : 'running', array( 'done', 'failed', 'info' ), true ) ? $now : null,
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
		$row  = $this->plugin->db->row( 'SELECT meta FROM {activity} WHERE id = %d', array( $id ) );
		$old  = $row ? (array) json_decode( (string) $row['meta'], true ) : array();
		$data = array(
			'status'      => $status,
			'meta'        => wp_json_encode( array_merge( $old, $meta ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'updated_at'  => Database::now(),
			'finished_at' => in_array( $status, array( 'done', 'failed', 'cancelled' ), true ) ? Database::now() : null,
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
		return (int) $this->plugin->db->value( "SELECT id FROM {activity} WHERE ref = %s AND status IN ('running','waiting') ORDER BY id DESC LIMIT 1", array( substr( (string) $ref, 0, 80 ) ) );
	}

	public function dismiss( $id ) {
		return (bool) $this->plugin->db->update( 'activity', array( 'dismissed' => 1 ), array( 'id' => (int) $id, 'user_id' => get_current_user_id() ) );
	}

	/* ------------------------------------------------------------------ reading */

	private static function shape( $r ) {
		$r = Database::decode( $r, array( 'meta' ) );
		foreach ( array( 'id', 'user_id', 'progress', 'dock', 'dismissed' ) as $k ) {
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

	/** Running / waiting work plus failures of the last hour. */
	public function live() {
		if ( ! $this->ready() ) {
			return array( 'items' => array(), 'counts' => array() );
		}
		$db   = $this->plugin->db;
		$rows = $db->rows(
			"SELECT id, user_id, kind, title, detail, status, step, progress, route, ref, dock, started_at, updated_at, finished_at FROM {activity} WHERE project_id = %d AND ( status IN ('running','waiting') OR ( status = 'failed' AND finished_at >= %s ) OR finished_at >= %s ) ORDER BY FIELD(status,'running','waiting','failed','done','info'), id DESC LIMIT 80",
			array( $db->project_id(), gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ), gmdate( 'Y-m-d H:i:s', time() - 5 * MINUTE_IN_SECONDS ) )
		);
		// Work that stopped reporting for 20 minutes is not "live" any more.
		$out = array();
		foreach ( $rows as $r ) {
			if ( in_array( $r['status'], array( 'running' ), true ) && strtotime( $r['updated_at'] . ' UTC' ) < time() - 20 * MINUTE_IN_SECONDS ) {
				$db->update( 'activity', array( 'status' => 'failed', 'detail' => __( 'Stopped without finishing (the server ended the request).', 'seolity' ), 'finished_at' => Database::now() ), array( 'id' => (int) $r['id'] ) );
				$r['status'] = 'failed';
			}
			$out[] = self::shape( $r + array( 'meta' => null ) );
		}
		$counts = array( 'running' => 0, 'waiting' => 0, 'failed' => 0 );
		foreach ( $out as $r ) {
			if ( isset( $counts[ $r['status'] ] ) ) {
				$counts[ $r['status'] ]++;
			}
		}
		return array( 'items' => $out, 'counts' => $counts, 'queue' => array_intersect_key( $this->plugin->queue->stats(), array_flip( array( 'pending', 'running', 'failed' ) ) ), 'time' => gmdate( 'c' ) );
	}

	/** Timeline of finished work. Filters: kind, status, q, page, per_page, day. */
	public function history( array $f ) {
		$db    = $this->plugin->db;
		$where = array( 'project_id = %d', "status NOT IN ('running','waiting')" );
		$args  = array( $db->project_id() );
		if ( ! empty( $f['kind'] ) && in_array( $f['kind'], self::KINDS, true ) ) {
			$where[] = 'kind = %s';
			$args[]  = $f['kind'];
		}
		if ( ! empty( $f['status'] ) && in_array( $f['status'], array( 'done', 'failed', 'info', 'cancelled' ), true ) ) {
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
		$rows  = $db->rows( 'SELECT id, user_id, kind, title, detail, status, step, progress, route, ref, meta, started_at, updated_at, finished_at FROM {activity} WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d', array_merge( $args, array( $per, ( $page - 1 ) * $per ) ) );
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
			'cleanup'                 => __( 'Clean-up', 'seolity' ),
		);
		$l = apply_filters( 'seolity_activity_jobs', $l );
		return isset( $l[ $hook ] ) ? $l[ $hook ] : null;
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
