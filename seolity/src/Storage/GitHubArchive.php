<?php
/**
 * GitHub data storage: keeps the WordPress database small by moving cold data
 * into a (private) GitHub repository, while keeping it readable/restorable by
 * the plugin.
 *
 * What is archived (as JSON / JSONL, one commit per run):
 *  - GSC page×query rows older than N days   → {path}/gsc/YYYY/MM/DD.jsonl  (pruned locally)
 *  - Audit log entries older than N days     → {path}/audit-log/YYYY-MM.jsonl (pruned locally)
 *  - Reports older than N days               → {path}/reports/{type}/{date}-{id}.json (payload pruned, ref kept)
 *  - Change history (backup, never pruned)   → {path}/changes/YYYY-MM.jsonl
 *  - Weekly crawl snapshot                   → {path}/snapshots/crawl-YYYY-MM-DD.jsonl
 *  - Settings (non-secret) + rules backup    → {path}/config/*.json
 *  - AI request log, AI chat, 404 log (old)  → {path}/ai-log|chat|not-found/YYYY-MM.jsonl (pruned locally)
 *  - Learnings, finished experiments, your knowledge notes → {path}/knowledge/*.json (backup)
 *  - Weekly competitor pages snapshot        → {path}/snapshots/competitors-YYYY-MM-DD.jsonl
 *
 * Works the same for hosts in Iran and abroad (GitHub's API is reachable from both), so the
 * host database only keeps the recent working set. Without a separate data repository the
 * runner repository's "seolity-data" branch is used.
 *
 * Hot data (last N days, current crawl, pending changes) always stays in MySQL
 * so dashboards stay fast. Secrets are never written to GitHub.
 *
 * @package SEOlity
 */

namespace SEOlity\Storage;

use SEOlity\Core\Database;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class GitHubArchive {

	const MAX_FILES_PER_RUN = 40;

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	private function repo() {
		$repo = (string) $this->plugin->settings->get( 'storage_repo' );
		return '' !== $repo ? $repo : (string) $this->plugin->settings->get( 'sub_repo' );
	}

	private function branch() {
		if ( '' === (string) $this->plugin->settings->get( 'storage_repo' ) ) {
			return 'seolity-data'; // Shared with the runner repository: keep data on its own branch.
		}
		return (string) $this->plugin->settings->get( 'storage_branch' );
	}

	/** Per-site folder so one data repo can serve many sites (agency). */
	private function base() {
		$host = preg_replace( '/[^a-z0-9.-]/', '', strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		return trim( (string) $this->plugin->settings->get( 'storage_path' ), '/' ) . '/' . $host;
	}

	public function ready() {
		return $this->plugin->settings->get( 'storage_enabled' ) && $this->plugin->github->has_token() && GitHubClient::valid_repo( $this->repo() );
	}

	private static function jsonl( array $rows ) {
		return implode( "\n", array_map( static function ( $r ) {
			return wp_json_encode( $r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}, $rows ) ) . "\n";
	}

	public function run() {
		if ( ! $this->ready() ) {
			return new \WP_Error( 'seolity_storage', __( 'GitHub storage is not configured.', 'seolity' ) );
		}
		$db     = $this->plugin->db;
		$pid    = $db->project_id();
		$days   = (int) $this->plugin->settings->get( 'storage_archive_days' );
		$prune  = (bool) $this->plugin->settings->get( 'storage_prune_local' );
		$cutoff = gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS );
		// Dashboards read 90 days of Search Console data: keep a longer hot window for it.
		$gsc_cutoff = gmdate( 'Y-m-d', time() - max( $days, (int) $this->plugin->settings->get( 'storage_gsc_days' ) ) * DAY_IN_SECONDS );
		$files  = array();
		if ( '' === (string) $this->plugin->settings->get( 'storage_repo' ) ) {
			$default = $this->plugin->github->repo( $this->repo() );
			if ( is_wp_error( $default ) ) {
				return $default;
			}
			$this->plugin->github->ensure_branch( $this->repo(), $this->branch(), (string) $default['default_branch'] );
		}
		$after  = array(); // Callbacks to prune locally after a successful commit.

		// 1) GSC rows by day.
		$dates = $db->column( 'SELECT DISTINCT date FROM {gsc_page_query} WHERE project_id = %d AND date < %s ORDER BY date ASC LIMIT 25', array( $pid, $gsc_cutoff ) );
		foreach ( $dates as $date ) {
			$rows = $db->rows( 'SELECT date, page, query, clicks, impressions, ctr, position FROM {gsc_page_query} WHERE project_id = %d AND date = %s', array( $pid, $date ) );
			$files[ $this->base() . '/gsc/' . str_replace( '-', '/', $date ) . '.jsonl' ] = self::jsonl( $rows );
			if ( $prune ) {
				$after[] = function () use ( $db, $pid, $date ) {
					$db->query( 'DELETE FROM {gsc_page_query} WHERE project_id = %d AND date = %s', array( $pid, $date ) );
				};
			}
		}
		// Daily totals + breakdowns are small: full backup each run.
		$files[ $this->base() . '/gsc/daily-totals.json' ] = wp_json_encode( $db->rows( 'SELECT date, clicks, impressions, ctr, position FROM {gsc_daily} WHERE project_id = %d ORDER BY date', array( $pid ) ) );

		// 2) Audit log by month.
		$months = $db->column( "SELECT DISTINCT DATE_FORMAT(created_at, '%%Y-%%m') m FROM {audit_log} WHERE project_id = %d AND created_at < %s ORDER BY m LIMIT 6", array( $pid, $cutoff . ' 00:00:00' ) );
		foreach ( $months as $m ) {
			$rows = $db->rows( "SELECT user_id, action, object_type, object_id, details, created_at FROM {audit_log} WHERE project_id = %d AND DATE_FORMAT(created_at, '%%Y-%%m') = %s AND created_at < %s", array( $pid, $m, $cutoff . ' 00:00:00' ) );
			$path = $this->base() . '/audit-log/' . $m . '.jsonl';
			$prev = $this->plugin->github->get_file( $this->repo(), $path, $this->branch() );
			$files[ $path ] = ( is_wp_error( $prev ) ? '' : $prev['content'] ) . self::jsonl( $rows );
			if ( $prune ) {
				$after[] = function () use ( $db, $pid, $m, $cutoff ) {
					$db->query( "DELETE FROM {audit_log} WHERE project_id = %d AND DATE_FORMAT(created_at, '%%Y-%%m') = %s AND created_at < %s", array( $pid, $m, $cutoff . ' 00:00:00' ) );
				};
			}
		}

		// 3) Reports (payload offloaded, metadata kept).
		foreach ( $db->rows( "SELECT id, type, period_end, data FROM {reports} WHERE project_id = %d AND archive_ref IS NULL AND type IN ('daily','weekly','monthly','briefing') AND created_at < %s LIMIT 15", array( $pid, $cutoff . ' 00:00:00' ) ) as $r ) {
			$path           = $this->base() . '/reports/' . $r['type'] . '/' . $r['period_end'] . '-' . $r['id'] . '.json';
			$files[ $path ] = (string) $r['data'];
			$after[]        = function () use ( $db, $r, $path, $prune ) {
				$db->update( 'reports', $prune ? array( 'archive_ref' => $path, 'data' => null ) : array( 'archive_ref' => $path ), array( 'id' => (int) $r['id'] ) );
			};
		}

		// 4) Change history backup (current month, full rewrite).
		$month = gmdate( 'Y-m' );
		$files[ $this->base() . '/changes/' . $month . '.jsonl' ] = self::jsonl( $db->rows( "SELECT id, object_type, object_id, field, url, before_value, after_value, version, status, source, ai_model, prompt_version, score_before, score_after, created_at, applied_at, reverted_at FROM {changes} WHERE project_id = %d AND DATE_FORMAT(created_at, '%%Y-%%m') = %s", array( $pid, $month ) ) );

		// 5) Weekly crawl snapshot.
		if ( '1' === gmdate( 'N' ) ) {
			$files[ $this->base() . '/snapshots/crawl-' . gmdate( 'Y-m-d' ) . '.jsonl' ] = self::jsonl( $db->rows( "SELECT url, status_code, indexable, title, meta_description, h1, word_count, inlinks, depth, response_ms FROM {urls} WHERE project_id = %d AND crawl_status = 'done' LIMIT 50000", array( $pid ) ) );
		}

		// 6) Logs that only grow: AI requests, chat, 404 hits — month files, appended, pruned locally.
		$logs = array(
			'ai-log'    => array( 'ai_requests', 'created_at', 'provider, model, task, prompt_version, tokens_in, tokens_out, cost_usd, duration_ms, status, error, created_at', '' ),
			'chat'      => array( 'chat', 'created_at', 'user_id, conversation_id, role, content, meta, created_at', 'project_id = %d AND ' ),
			'not-found' => array( 'not_found', 'last_seen', 'url, referrer, user_agent, is_bot, hits, suggested_target, status, first_seen, last_seen', 'project_id = %d AND ' ),
		);
		foreach ( $logs as $folder => $l ) {
			list( $table, $col, $fields, $scope ) = $l;
			$args   = '' !== $scope ? array( $pid ) : array();
			$months = $db->column( "SELECT DISTINCT DATE_FORMAT({$col}, '%%Y-%%m') m FROM {{$table}} WHERE {$scope}{$col} < %s ORDER BY m LIMIT 3", array_merge( $args, array( $cutoff . ' 00:00:00' ) ) );
			foreach ( $months as $m ) {
				$where = "{$scope}DATE_FORMAT({$col}, '%%Y-%%m') = %s AND {$col} < %s";
				$wargs = array_merge( $args, array( $m, $cutoff . ' 00:00:00' ) );
				$rows  = $db->rows( "SELECT {$fields} FROM {{$table}} WHERE {$where} LIMIT 20000", $wargs );
				if ( ! $rows ) {
					continue;
				}
				$path           = $this->base() . '/' . $folder . '/' . $m . '.jsonl';
				$prev           = $this->plugin->github->get_file( $this->repo(), $path, $this->branch() );
				$files[ $path ] = ( is_wp_error( $prev ) ? '' : $prev['content'] ) . self::jsonl( $rows );
				if ( $prune ) {
					$after[] = function () use ( $db, $table, $where, $wargs ) {
						$db->query( "DELETE FROM {{$table}} WHERE {$where}", $wargs );
					};
				}
			}
		}

		// 7) What the site learned: experiment learnings, finished experiments, your knowledge notes.
		$files[ $this->base() . '/knowledge/learnings.json' ]   = wp_json_encode( $db->rows( 'SELECT change_type, object_type, metric, samples, avg_delta, positive, negative, neutral, updated_at FROM {learnings} WHERE project_id = %d', array( $pid ) ), JSON_UNESCAPED_UNICODE );
		$files[ $this->base() . '/knowledge/experiments.jsonl' ] = self::jsonl( $db->rows( "SELECT name, object_type, url, change_type, status, window_days, started_at, metrics_before, metrics_after, outcome, confidence, reason, evaluated_at FROM {experiments} WHERE project_id = %d AND status <> 'running' ORDER BY id DESC LIMIT 2000", array( $pid ) ) );
		$notes = $db->rows( "SELECT path, title, lang, topic, tags, chunk_index, content, updated_at FROM {knowledge} WHERE source = 'user' ORDER BY path, chunk_index" );
		if ( $notes ) {
			$files[ $this->base() . '/knowledge/notes.json' ] = wp_json_encode( $notes, JSON_UNESCAPED_UNICODE );
		}

		// 8) Weekly competitor snapshot (what they published, lengths, schema).
		if ( '1' === gmdate( 'N' ) ) {
			$files[ $this->base() . '/snapshots/competitors-' . gmdate( 'Y-m-d' ) . '.jsonl' ] = self::jsonl( $db->rows( 'SELECT c.domain, p.url, p.title, p.h1, p.word_count, p.faq_count, p.schema_types, p.first_seen, p.lastmod FROM {competitor_pages} p JOIN {competitors} c ON c.id = p.competitor_id WHERE c.project_id = %d LIMIT 50000', array( $pid ) ) );
		}

		// 9) Config backup (no secrets).
		// Custom API headers / query parameters may carry credentials: never back them up.
		$files[ $this->base() . '/config/settings.json' ] = wp_json_encode( array_diff_key( $this->plugin->settings->all(), array_flip( array( 'custom_headers', 'custom_query' ) ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		$files[ $this->base() . '/config/rules.json' ]    = wp_json_encode( $this->plugin->rules->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		$files[ $this->base() . '/config/redirects.csv' ] = $this->plugin->redirects->export_csv();

		$files = array_slice( $files, 0, self::MAX_FILES_PER_RUN, true );
		$res   = $this->plugin->github->commit_files( $this->repo(), $this->branch(), $files, sprintf( 'SEOlity archive %s (%d files)', gmdate( 'Y-m-d H:i' ), count( $files ) ) );
		if ( is_wp_error( $res ) ) {
			update_option( 'seolity_archive_status', array( 'time' => time(), 'ok' => false, 'error' => $res->get_error_message() ), false );
			return $res;
		}
		foreach ( $after as $cb ) {
			$cb();
		}
		$status = array( 'time' => time(), 'ok' => true, 'commit' => $res['commit'], 'files' => count( $files ), 'gsc_days' => count( $dates ) );
		update_option( 'seolity_archive_status', $status, false );
		$this->plugin->audit_log->log( 'storage.archived', 'storage', null, $status );
		if ( count( $dates ) >= 25 ) {
			$this->plugin->queue->push( 'archive_run', array(), array( 'unique' => true, 'priority' => 50, 'delay' => 120 ) ); // More backlog.
		}
		return $status;
	}

	/** Read an archived JSON document (read-through for reports). */
	public function fetch_json( $path ) {
		if ( ! GitHubClient::valid_repo( $this->repo() ) || 0 !== strpos( $path, trim( (string) $this->plugin->settings->get( 'storage_path' ), '/' ) . '/' ) ) {
			return null;
		}
		$f = $this->plugin->github->get_file( $this->repo(), $path, $this->branch() );
		return is_wp_error( $f ) ? null : json_decode( $f['content'], true );
	}

	/**
	 * Restore archived GSC rows for a date range back into MySQL (e.g. for a
	 * year-over-year analysis). Rows are re-pruned by the next archive run.
	 */
	public function restore_gsc( $start, $end ) {
		if ( ! $this->ready() ) {
			return new \WP_Error( 'seolity_storage', __( 'GitHub storage is not configured.', 'seolity' ), array( 'status' => 400 ) );
		}
		$days = 0;
		$rows = 0;
		$db   = $this->plugin->db;
		for ( $d = strtotime( $start ); $d <= strtotime( $end ) && $days < 62; $d += DAY_IN_SECONDS ) {
			$f = $this->plugin->github->get_file( $this->repo(), $this->base() . '/gsc/' . gmdate( 'Y/m/d', $d ) . '.jsonl', $this->branch() );
			if ( is_wp_error( $f ) ) {
				continue;
			}
			foreach ( array_filter( explode( "\n", $f['content'] ) ) as $line ) {
				$r = json_decode( $line, true );
				if ( ! $r ) {
					continue;
				}
				$db->upsert(
					'gsc_page_query',
					array(
						'project_id'  => $db->project_id(),
						'date'        => $r['date'],
						'page_hash'   => Database::url_hash( $r['page'] ),
						'query_hash'  => Database::hash( $r['query'] ),
						'page'        => $r['page'],
						'query'       => $r['query'],
						'clicks'      => (int) $r['clicks'],
						'impressions' => (int) $r['impressions'],
						'ctr'         => (float) $r['ctr'],
						'position'    => (float) $r['position'],
					),
					array( 'clicks', 'impressions', 'ctr', 'position' )
				);
				$rows++;
			}
			$days++;
		}
		$this->plugin->audit_log->log( 'storage.restored', 'storage', null, array( 'start' => $start, 'end' => $end, 'rows' => $rows ) );
		return array( 'days' => $days, 'rows' => $rows );
	}

	public function status() {
		$db = $this->plugin->db;
		return array(
			'ready'      => $this->ready(),
			'enabled'    => (bool) $this->plugin->settings->get( 'storage_enabled' ),
			'repo'       => $this->repo(),
			'branch'     => $this->branch(),
			'base'       => $this->base(),
			'keep_days'  => (int) $this->plugin->settings->get( 'storage_archive_days' ),
			'gsc_days'   => (int) $this->plugin->settings->get( 'storage_gsc_days' ),
			'tables'     => $this->table_sizes(),
			'last'       => get_option( 'seolity_archive_status' ),
			'local_rows' => array(
				'gsc_page_query' => (int) $db->value( 'SELECT COUNT(*) FROM {gsc_page_query}' ),
				'audit_log'      => (int) $db->value( 'SELECT COUNT(*) FROM {audit_log}' ),
				'reports'        => (int) $db->value( 'SELECT COUNT(*) FROM {reports} WHERE data IS NOT NULL' ),
				'urls'           => (int) $db->value( 'SELECT COUNT(*) FROM {urls}' ),
			),
			'db_size_mb' => $this->db_size(),
		);
	}

	/** Biggest SEOlity tables on the host (MB, rows). */
	private function table_sizes() {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT table_name AS t, ROUND((data_length + index_length) / 1048576, 2) AS mb, table_rows AS r FROM information_schema.TABLES WHERE table_schema = %s AND table_name LIKE %s ORDER BY (data_length + index_length) DESC LIMIT 8', DB_NAME, $wpdb->esc_like( $wpdb->prefix . 'seolity_' ) . '%' ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$r   = array_change_key_case( $r, CASE_LOWER );
			$out[] = array( 'table' => substr( $r['t'], strlen( $wpdb->prefix . 'seolity_' ) ), 'mb' => (float) $r['mb'], 'rows' => (int) $r['r'] );
		}
		return $out;
	}

	private function db_size() {
		global $wpdb;
		$size = $wpdb->get_var( $wpdb->prepare( 'SELECT ROUND(SUM(data_length + index_length) / 1048576, 2) FROM information_schema.TABLES WHERE table_schema = %s AND table_name LIKE %s', DB_NAME, $wpdb->esc_like( $wpdb->prefix . 'seolity_' ) . '%' ) ); // phpcs:ignore WordPress.DB
		return null === $size ? null : (float) $size;
	}
}
