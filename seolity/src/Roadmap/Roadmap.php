<?php
/**
 * SEO roadmap ("What should I do today?").
 *
 * Runs ONLY when the owner presses "Analyze": collects a fresh snapshot (Search Console,
 * site audit, recommendations, new content, competitors), studies the SEO knowledge base
 * and lets the AI write a prioritized roadmap with site-specific steps. Every version is
 * stored in the database; the next analysis receives the previous roadmap and keeps
 * priorities stable unless the data changed materially. Each task carries real targets
 * (pages with edit links and one-click AI actions) and an exact dashboard guide.
 *
 * Without an AI provider the roadmap is still built from the data with the built-in guides.
 *
 * @package SEOlity
 */

namespace SEOlity\Roadmap;

use SEOlity\AI\Deferred;
use SEOlity\Core\Database;
use SEOlity\Plugin;
use SEOlity\Queue\Scheduler;

defined( 'ABSPATH' ) || exit;

final class Roadmap {

	const STATUS_OPTION = 'seolity_roadmap_status';
	const PRIORITY      = array( 'critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1 );
	const PHASES        = array( 'now' => 1, 'week' => 2, 'month' => 3, 'later' => 4 );

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	private function pid() {
		return $this->plugin->db->project_id();
	}

	private static function decode( $row ) {
		return $row ? Database::decode( $row, array( 'summary', 'items', 'changes', 'meta' ) ) : null;
	}

	/* ------------------------------------------------------------------ reading */

	/** Latest finished roadmap + the analysis in progress (if any). */
	public function current() {
		$db      = $this->plugin->db;
		$done    = self::decode( $db->row( "SELECT id, version, status, summary, items, changes, meta, started_at, finished_at FROM {roadmaps} WHERE project_id = %d AND status = 'done' ORDER BY id DESC LIMIT 1", array( $this->pid() ) ) );
		$running = $db->row( "SELECT id, status, stage, error, started_at FROM {roadmaps} WHERE project_id = %d AND status IN ('running','failed') ORDER BY id DESC LIMIT 1", array( $this->pid() ) );
		if ( $running && $done && (int) $running['id'] < (int) $done['id'] ) {
			$running = null;
		}
		if ( $running && 'running' === $running['status'] && time() - strtotime( $running['started_at'] . ' UTC' ) > 45 * MINUTE_IN_SECONDS ) {
			$db->update( 'roadmaps', array( 'status' => 'failed', 'error' => __( 'The analysis took too long and was stopped. Press “Analyze” again.', 'seolity' ), 'finished_at' => Database::now() ), array( 'id' => (int) $running['id'] ) );
			$running['status'] = 'failed';
		}
		if ( $done ) {
			$done['items'] = $this->with_status( (array) $done['items'] );
		}
		return array(
			'roadmap'  => $done,
			'running'  => $running,
			'ai_ready' => $this->plugin->ai->is_ready(),
			'gsc'      => $this->plugin->gsc_oauth->is_connected(),
			'audit'    => (bool) $this->plugin->crawler->current_audit(),
		);
	}

	public function history( $limit = 12 ) {
		return array_map(
			static function ( $r ) {
				$r          = Database::decode( $r, array( 'summary' ) );
				$r['tasks'] = isset( $r['summary']['counts']['total'] ) ? (int) $r['summary']['counts']['total'] : null;
				unset( $r['summary'] );
				return $r;
			},
			$this->plugin->db->rows( "SELECT id, version, summary, started_at, finished_at FROM {roadmaps} WHERE project_id = %d AND status = 'done' ORDER BY id DESC LIMIT %d", array( $this->pid(), (int) $limit ) )
		);
	}

	private function statuses() {
		$s = get_option( self::STATUS_OPTION, array() );
		return is_array( $s ) ? $s : array();
	}

	private function with_status( array $items ) {
		$st = $this->statuses();
		foreach ( $items as &$it ) {
			$k            = $it['key'];
			$it['status'] = isset( $st[ $k ]['status'] ) ? $st[ $k ]['status'] : 'todo';
			$it['status_at'] = isset( $st[ $k ]['at'] ) ? $st[ $k ]['at'] : null;
			// A task marked done comes back when the data shows more affected pages than then.
			if ( 'done' === $it['status'] && isset( $st[ $k ]['count'] ) && count( (array) $it['targets'] ) > (int) $st[ $k ]['count'] ) {
				$it['status']     = 'todo';
				$it['reappeared'] = true;
			}
		}
		return $items;
	}

	public function set_status( $key, $status ) {
		if ( ! in_array( $status, array( 'todo', 'doing', 'done', 'dismissed' ), true ) || '' === (string) $key ) {
			return new \WP_Error( 'seolity_roadmap', __( 'Unknown status.', 'seolity' ), array( 'status' => 400 ) );
		}
		$count = 0;
		$cur   = $this->current();
		foreach ( $cur['roadmap'] ? (array) $cur['roadmap']['items'] : array() as $it ) {
			if ( $it['key'] === $key ) {
				$count = count( (array) $it['targets'] );
			}
		}
		$st         = $this->statuses();
		$st[ $key ] = array( 'status' => $status, 'at' => gmdate( 'c' ), 'count' => $count, 'user' => get_current_user_id() );
		update_option( self::STATUS_OPTION, $st, false );
		$this->plugin->audit_log->log( 'roadmap.task_' . $status, 'roadmap', null, array( 'key' => $key ) );
		return array( 'key' => $key, 'status' => $status );
	}

	/* ------------------------------------------------------------------ analysis */

	/** "Analyze" button: start a new analysis in the background. */
	public function start() {
		$db  = $this->plugin->db;
		$run = $db->row( "SELECT id, started_at FROM {roadmaps} WHERE project_id = %d AND status = 'running' ORDER BY id DESC LIMIT 1", array( $this->pid() ) );
		if ( $run && time() - strtotime( $run['started_at'] . ' UTC' ) < 45 * MINUTE_IN_SECONDS ) {
			return array( 'id' => (int) $run['id'], 'already' => true );
		}
		$id = $db->insert( 'roadmaps', array( 'project_id' => $this->pid(), 'status' => 'running', 'stage' => 'queued', 'user_id' => get_current_user_id(), 'started_at' => Database::now() ) );
		$this->plugin->queue->push( 'roadmap_build', array( 'id' => (int) $id ), array( 'priority' => 0, 'max_attempts' => 3 ) );
		Scheduler::kick();
		// The AI step runs on GitHub for subscription AI / the GitHub route: start a runner now.
		$provider = (string) $this->plugin->settings->get( 'ai_provider' );
		if ( 0 === strpos( $provider, 'sub_' ) || 'github' === $this->plugin->gateway->current() ) {
			$this->plugin->subscription->warm( 'sub_codex' === $provider ? 'codex' : ( 'sub_claude' === $provider ? 'claude' : 'http' ) );
		}
		return array( 'id' => (int) $id, 'already' => false );
	}

	private function stage( $id, $stage ) {
		$this->plugin->db->update( 'roadmaps', array( 'stage' => $stage ), array( 'id' => (int) $id ) );
	}

	/**
	 * Queue job. May throw Deferred (subscription AI on GitHub): the queue retries and the
	 * stored snapshot is reused, so the identical AI request then returns the answer.
	 */
	public function build( $id ) {
		$db  = $this->plugin->db;
		$row = $db->row( 'SELECT * FROM {roadmaps} WHERE id = %d', array( (int) $id ) );
		if ( ! $row || 'running' !== $row['status'] ) {
			return true;
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		try {
			$snap = $row['facts'] ? json_decode( $row['facts'], true ) : null;
			if ( ! is_array( $snap ) ) {
				$snap = ( new Collector( $this->plugin ) )->collect(
					function ( $s ) use ( $id ) {
						$this->stage( $id, $s );
					}
				);
				$db->update( 'roadmaps', array( 'facts' => wp_json_encode( $snap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ), array( 'id' => (int) $id ) );
			}
			$prev  = self::decode( $db->row( "SELECT id, version, summary, items FROM {roadmaps} WHERE project_id = %d AND status = 'done' AND id < %d ORDER BY id DESC LIMIT 1", array( $this->pid(), (int) $id ) ) );
			$base  = $this->base_items( (array) $snap['candidates'] );
			$this->stage( $id, 'ai' );
			$ai    = $this->ai( $snap, $base, $prev );
			$items = $this->merge( $base, is_wp_error( $ai ) ? null : $ai['data'], $prev );
			$summary = $this->summary( $snap, $items, is_wp_error( $ai ) ? null : $ai, $prev );
			$changes = $this->diff( $items, $prev, is_wp_error( $ai ) ? array() : (array) $ai['data']['items'] );
			$db->update(
				'roadmaps',
				array(
					'status'      => 'done',
					'stage'       => 'done',
					'version'     => $prev ? (int) $prev['version'] + 1 : 1,
					'summary'     => wp_json_encode( $summary, JSON_UNESCAPED_UNICODE ),
					'items'       => wp_json_encode( $items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
					'changes'     => wp_json_encode( $changes, JSON_UNESCAPED_UNICODE ),
					'meta'        => wp_json_encode( is_wp_error( $ai ) ? array( 'ai_error' => $ai->get_error_message() ) : $ai['meta'], JSON_UNESCAPED_UNICODE ),
					'finished_at' => Database::now(),
				),
				array( 'id' => (int) $id )
			);
			$this->plugin->audit_log->log( 'roadmap.built', 'roadmap', (int) $id, array( 'tasks' => count( $items ), 'ai' => ! is_wp_error( $ai ) ) );
			return true;
		} catch ( Deferred $d ) {
			$this->stage( $id, 'waiting_ai' );
			throw $d;
		} catch ( \Throwable $e ) {
			$db->update( 'roadmaps', array( 'status' => 'failed', 'error' => substr( $e->getMessage(), 0, 1000 ), 'finished_at' => Database::now() ), array( 'id' => (int) $id ) );
			return new \WP_Error( 'seolity_roadmap', $e->getMessage() );
		}
	}

	/** Candidates → full items with category, phase, guide and done criteria. */
	private function base_items( array $candidates ) {
		$out = array();
		foreach ( $candidates as $c ) {
			$type = $c['type'];
			$imp  = (int) $c['impact'];
			$c['category']  = isset( Collector::CATEGORY[ $type ] ) ? Collector::CATEGORY[ $type ] : 'technical';
			$c['phase']     = self::default_phase( $c['priority'], $imp );
			$c['guide']     = Guides::steps( $type, $c );
			$c['done_when'] = Guides::done_when( $type );
			$c['source']    = 'data';
			$c['plan']      = array();
			$c['count']     = count( (array) $c['targets'] );
			$out[ $c['key'] ] = $c;
		}
		return $out;
	}

	private static function default_phase( $priority, $impact ) {
		if ( 'critical' === $priority ) {
			return 'now';
		}
		if ( 'high' === $priority ) {
			return $impact >= 60 ? 'now' : 'week';
		}
		return 'medium' === $priority ? 'month' : 'later';
	}

	/** The AI analysis (knowledge-grounded). */
	private function ai( array $snap, array $base, $prev ) {
		if ( ! $this->plugin->ai->is_ready() ) {
			return new \WP_Error( 'seolity_ai_not_configured', __( 'No AI provider is configured — the roadmap was built from your data and the built-in guides only.', 'seolity' ) );
		}
		$cands = array();
		foreach ( $base as $k => $it ) {
			$cands[] = array(
				'key'      => $k,
				'title'    => $it['title'],
				'why'      => mb_substr( (string) $it['why'], 0, 400 ),
				'priority' => $it['priority'],
				'affected' => $it['count'],
				'examples' => array_slice( array_map( static function ( $t ) {
					return trim( $t['label'] . ( ! empty( $t['metric'] ) ? ' — ' . mb_substr( (string) $t['metric'], 0, 140 ) : '' ) );
				}, (array) $it['targets'] ), 0, 6 ),
			);
		}
		$previous = null;
		if ( $prev ) {
			$st       = $this->statuses();
			$previous = array(
				'version'   => (int) $prev['version'],
				'diagnosis' => isset( $prev['summary']['diagnosis'] ) ? mb_substr( (string) $prev['summary']['diagnosis'], 0, 1200 ) : '',
				'items'     => array_map( static function ( $it ) use ( $st ) {
					return array( 'key' => $it['key'], 'title' => $it['title'], 'priority' => $it['priority'], 'phase' => $it['phase'], 'affected' => isset( $it['count'] ) ? $it['count'] : 0, 'owner_status' => isset( $st[ $it['key'] ]['status'] ) ? $st[ $it['key'] ]['status'] : 'todo' );
				}, (array) $prev['items'] ),
			);
		}
		$query = implode( ' ', array_slice( array_map( static function ( $c ) {
			return $c['title'];
		}, $cands ), 0, 8 ) );
		$res   = $this->plugin->ai->run(
			'seo_roadmap',
			array(
				'facts'            => $snap['facts'],
				'candidates'       => $cands,
				'previous_roadmap' => $previous,
				'dashboard_paths'  => $this->paths(),
			),
			array( 'knowledge_query' => $query, 'knowledge_limit' => 10, 'max_tokens' => 16000, 'no_cache' => true )
		);
		return $res;
	}

	/** Real menu paths the AI may reference in steps (so it never invents a location). */
	private function paths() {
		$engine = \SEOlity\Admin\EditorIntegration::engine_name( $this->plugin->meta->engine() );
		return array(
			'edit_post'        => __( 'Posts → All Posts → Edit', 'seolity' ),
			'edit_page'        => __( 'Pages → All Pages → Edit', 'seolity' ),
			'edit_product'     => __( 'Products → All Products → Edit', 'seolity' ),
			'product_category' => __( 'Products → Categories → Edit', 'seolity' ),
			'seo_box'          => sprintf( /* translators: %s: SEO plugin */ __( 'Edit page → %s box', 'seolity' ), $engine ),
			'ai_optimize'      => __( 'Edit page → Optimize with SEOlity AI', 'seolity' ),
			'internal_links'   => __( 'SEOLITY → Keywords & links → Internal Links', 'seolity' ),
			'keywords'         => __( 'SEOLITY → Keywords & links → Keywords', 'seolity' ),
			'focus_keywords'   => __( 'SEOLITY → Keywords & links → Focus keywords', 'seolity' ),
			'search_console'   => __( 'SEOLITY → Search Console', 'seolity' ),
			'audit'            => __( 'SEOLITY → Analysis → Site Audit', 'seolity' ),
			'competitors'      => __( 'SEOLITY → Analysis → Competitors', 'seolity' ),
			'redirects'        => __( 'SEOLITY → Technical SEO → Redirects', 'seolity' ),
			'not_found'        => __( 'SEOLITY → Technical SEO → 404 Monitor', 'seolity' ),
			'schema'           => __( 'SEOLITY → Technical SEO → Schema', 'seolity' ),
			'sitemap'          => __( 'SEOLITY → Technical SEO → Sitemap', 'seolity' ),
			'content_list'     => __( 'SEOLITY → Content → Content', 'seolity' ),
			'images'           => __( 'SEOLITY → Content → Images', 'seolity' ),
			'media'            => __( 'Media → Library', 'seolity' ),
			'gsc_inspect'      => __( 'Search Console → URL inspection → Request indexing', 'seolity' ),
			'reading'          => __( 'Settings → Reading', 'seolity' ),
			'new_post'         => __( 'Posts → Add New', 'seolity' ),
		);
	}

	/**
	 * Data items + AI overlay, kept stable against the previous roadmap.
	 *
	 * @param array      $base Data items keyed by key.
	 * @param array|null $ai   AI output.
	 * @param array|null $prev Previous roadmap.
	 */
	private function merge( array $base, $ai, $prev ) {
		$prev_items = array();
		foreach ( $prev ? (array) $prev['items'] : array() as $it ) {
			$prev_items[ $it['key'] ] = $it;
		}
		$ai_items = array();
		foreach ( $ai ? (array) $ai['items'] : array() as $it ) {
			if ( ! empty( $it['key'] ) ) {
				$ai_items[ (string) $it['key'] ] = $it;
			}
		}
		$items = array();
		$extra = 0;
		foreach ( $base as $k => $it ) {
			if ( isset( $ai_items[ $k ] ) ) {
				$a                     = $ai_items[ $k ];
				$it['title']           = $this->stable_title( $k, $a, $prev_items, $it['title'] );
				$it['analysis']        = (string) $a['why'];
				$it['expected_result'] = (string) $a['expected_result'];
				$it['plan']            = self::clean_steps( $a['steps'] );
				$it['done_when']       = '' !== trim( (string) $a['done_when'] ) ? (string) $a['done_when'] : $it['done_when'];
				$it['impact']          = max( 1, min( 100, (int) $a['impact'] ) );
				$it['effort']          = max( 1, min( 100, (int) $a['effort'] ) );
				$it['priority']        = $a['priority'];
				$it['phase']           = $a['phase'];
				$it['change_reason']   = ! empty( $a['changed'] ) ? (string) $a['change_reason'] : '';
				$changed               = ! empty( $a['changed'] );
			} else {
				$changed = false;
			}
			$items[ $k ] = $this->stabilize( $it, isset( $prev_items[ $k ] ) ? $prev_items[ $k ] : null, $changed );
		}
		foreach ( $ai_items as $k => $a ) {
			if ( isset( $items[ $k ] ) || 0 !== strpos( $k, 'ai:' ) || $extra >= 6 ) {
				continue;
			}
			$extra++;
			$it = array(
				'key'             => $k,
				'type'            => 'ai',
				'title'           => $this->stable_title( $k, $a, $prev_items, (string) $a['title'] ),
				'why'             => (string) $a['why'],
				'analysis'        => '',
				'expected_result' => (string) $a['expected_result'],
				'priority'        => $a['priority'],
				'phase'           => $a['phase'],
				'impact'          => max( 1, min( 100, (int) $a['impact'] ) ),
				'effort'          => max( 1, min( 100, (int) $a['effort'] ) ),
				'category'        => sanitize_key( (string) $a['category'] ) ? sanitize_key( (string) $a['category'] ) : 'strategy',
				'targets'         => array(),
				'count'           => 0,
				'guide'           => array(),
				'plan'            => self::clean_steps( $a['steps'] ),
				'done_when'       => (string) $a['done_when'],
				'source'          => 'ai',
				'change_reason'   => ! empty( $a['changed'] ) ? (string) $a['change_reason'] : '',
			);
			$items[ $k ] = $this->stabilize( $it, isset( $prev_items[ $k ] ) ? $prev_items[ $k ] : null, ! empty( $a['changed'] ) );
		}
		$items = array_values( $items );
		usort(
			$items,
			static function ( $a, $b ) {
				$pa = self::PHASES[ $a['phase'] ] <=> self::PHASES[ $b['phase'] ];
				if ( 0 !== $pa ) {
					return $pa;
				}
				$pr = self::PRIORITY[ $b['priority'] ] <=> self::PRIORITY[ $a['priority'] ];
				return 0 !== $pr ? $pr : $b['impact'] <=> $a['impact'];
			}
		);
		return $items;
	}

	private function stable_title( $key, array $a, array $prev_items, $fallback ) {
		if ( isset( $prev_items[ $key ] ) && empty( $a['changed'] ) && 0 === strpos( $key, 'ai:' ) ) {
			return $prev_items[ $key ]['title'];
		}
		$t = trim( (string) $a['title'] );
		return '' !== $t ? $t : $fallback;
	}

	/**
	 * Keep the previous priority/phase unless the AI flagged a material change, the number of
	 * affected pages moved by 30 % or more, or the priority jumped two levels.
	 */
	private function stabilize( array $it, $prev, $changed ) {
		if ( ! isset( self::PRIORITY[ $it['priority'] ] ) ) {
			$it['priority'] = 'medium';
		}
		if ( ! isset( self::PHASES[ $it['phase'] ] ) ) {
			$it['phase'] = self::default_phase( $it['priority'], (int) $it['impact'] );
		}
		$it['previous'] = null;
		if ( ! $prev ) {
			$it['is_new'] = true;
			return $it;
		}
		$it['is_new'] = false;
		$before       = isset( $prev['count'] ) ? (int) $prev['count'] : 0;
		$moved        = $before ? abs( $it['count'] - $before ) / $before >= 0.3 : $it['count'] > 0;
		$jump         = abs( self::PRIORITY[ $it['priority'] ] - self::PRIORITY[ $prev['priority'] ] ) >= 2;
		if ( ! $changed && ! $moved && ! $jump ) {
			$it['priority'] = $prev['priority'];
			$it['phase']    = $prev['phase'];
		}
		$it['previous'] = array( 'priority' => $prev['priority'], 'phase' => $prev['phase'], 'count' => $before );
		return $it;
	}

	private static function clean_steps( $steps ) {
		$out = array();
		foreach ( array_slice( (array) $steps, 0, 8 ) as $s ) {
			if ( ! empty( $s['title'] ) ) {
				$out[] = array( 'title' => (string) $s['title'], 'detail' => (string) $s['detail'], 'where' => (string) $s['where'] );
			}
		}
		return $out;
	}

	private function summary( array $snap, array $items, $ai, $prev ) {
		$counts = array( 'total' => count( $items ), 'critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'now' => 0, 'week' => 0, 'month' => 0, 'later' => 0, 'targets' => 0 );
		foreach ( $items as $it ) {
			$counts[ $it['priority'] ]++;
			$counts[ $it['phase'] ]++;
			$counts['targets'] += (int) $it['count'];
		}
		$f   = $snap['facts'];
		$out = array(
			'counts'       => $counts,
			'health'       => isset( $f['health']['score'] ) ? $f['health']['score'] : null,
			'gsc'          => isset( $f['search_console']['last_28_days'] ) ? array( 'current' => $f['search_console']['last_28_days'], 'previous' => $f['search_console']['previous_28_days'] ) : null,
			'ai'           => (bool) $ai,
			'diagnosis'    => $ai ? (string) $ai['data']['diagnosis'] : $this->fallback_diagnosis( $f, $counts ),
			'strengths'    => $ai ? array_values( (array) $ai['data']['strengths'] ) : array(),
			'risks'        => $ai ? array_values( (array) $ai['data']['risks'] ) : array(),
			'focus'        => $ai ? array_values( (array) $ai['data']['focus'] ) : array_slice( wp_list_pluck( $items, 'title' ), 0, 3 ),
			'problem_areas' => $ai && isset( $ai['data']['problem_areas'] ) ? array_values( (array) $ai['data']['problem_areas'] ) : array(),
			'changes_note' => $ai ? (string) $ai['data']['changes_note'] : ( $prev ? '' : __( 'First roadmap.', 'seolity' ) ),
			'model'        => $ai ? $ai['meta']['model'] : null,
			'knowledge'    => $ai ? $ai['meta']['knowledge_refs'] : array(),
		);
		return $out;
	}

	private function fallback_diagnosis( array $f, array $counts ) {
		$parts = array();
		if ( isset( $f['health']['score'] ) && null !== $f['health']['score'] ) {
			/* translators: %d: health score */
			$parts[] = sprintf( __( 'SEO health score: %d/100.', 'seolity' ), $f['health']['score'] );
		}
		if ( isset( $f['search_console']['last_28_days'] ) ) {
			$c = $f['search_console']['last_28_days'];
			$p = $f['search_console']['previous_28_days'];
			/* translators: 1 clicks 2 previous clicks 3 impressions */
			$parts[] = sprintf( __( 'Last 28 days: %1$s clicks (previous period %2$s) from %3$s impressions.', 'seolity' ), number_format_i18n( (int) $c['clicks'] ), number_format_i18n( (int) $p['clicks'] ), number_format_i18n( (int) $c['impressions'] ) );
		} else {
			$parts[] = __( 'Search Console is not connected yet, so traffic-based tasks are missing — connect it for a complete roadmap.', 'seolity' );
		}
		/* translators: 1 tasks 2 critical 3 high */
		$parts[] = sprintf( __( '%1$d tasks found: %2$d critical and %3$d high priority. Start with the “This week” column.', 'seolity' ), $counts['total'], $counts['critical'], $counts['high'] );
		return implode( ' ', $parts );
	}

	private function diff( array $items, $prev, array $ai_items ) {
		if ( ! $prev ) {
			return array( 'first' => true, 'added' => array(), 'resolved' => array(), 'priority' => array() );
		}
		$now = array();
		foreach ( $items as $it ) {
			$now[ $it['key'] ] = $it;
		}
		$old = array();
		foreach ( (array) $prev['items'] as $it ) {
			$old[ $it['key'] ] = $it;
		}
		$out = array( 'first' => false, 'added' => array(), 'resolved' => array(), 'priority' => array() );
		foreach ( $now as $k => $it ) {
			if ( ! isset( $old[ $k ] ) ) {
				$out['added'][] = array( 'key' => $k, 'title' => $it['title'] );
			} elseif ( $old[ $k ]['priority'] !== $it['priority'] ) {
				$out['priority'][] = array( 'key' => $k, 'title' => $it['title'], 'from' => $old[ $k ]['priority'], 'to' => $it['priority'], 'reason' => isset( $it['change_reason'] ) ? $it['change_reason'] : '' );
			}
		}
		foreach ( $old as $k => $it ) {
			if ( ! isset( $now[ $k ] ) ) {
				$out['resolved'][] = array( 'key' => $k, 'title' => $it['title'] );
			}
		}
		return $out;
	}
}
