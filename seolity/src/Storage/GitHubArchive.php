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
		return (string) $this->plugin->settings->get( 'storage_repo' );
	}

	private function branch() {
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
		$files  = array();
		$after  = array(); // Callbacks to prune locally after a successful commit.

		// 1) GSC rows by day.
		$dates = $db->column( 'SELECT DISTINCT date FROM {gsc_page_query} WHERE project_id = %d AND date < %s ORDER BY date ASC LIMIT 25', array( $pid, $cutoff ) );
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

		// 6) Config backup (no secrets).
		$files[ $this->base() . '/config/settings.json' ] = wp_json_encode( $this->plugin->settings->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
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
			'repo'       => $this->repo(),
			'branch'     => $this->branch(),
			'base'       => $this->base(),
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

	private function db_size() {
		global $wpdb;
		$size = $wpdb->get_var( $wpdb->prepare( 'SELECT ROUND(SUM(data_length + index_length) / 1048576, 2) FROM information_schema.TABLES WHERE table_schema = %s AND table_name LIKE %s', DB_NAME, $wpdb->esc_like( $wpdb->prefix . 'seolity_' ) . '%' ) ); // phpcs:ignore WordPress.DB
		return null === $size ? null : (float) $size;
	}
}
