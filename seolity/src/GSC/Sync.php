<?php
/**
 * Incremental Search Console sync into local tables (queue-driven).
 *
 * - First run back-fills `gsc_sync_days` (default 90, max ~16 months).
 * - Daily runs re-pull the last few days (GSC finalizes data with a lag).
 * - page×query rows are fetched one day at a time with pagination (25k rows
 *   per call) and bulk-upserted, so even large sites never hit PHP limits.
 *
 * @package SEOlity
 */

namespace SEOlity\GSC;

use SEOlity\Core\Database;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class Sync {

	const BREAKDOWNS = array( 'country', 'device', 'searchAppearance' );

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Queue sync jobs for a window ending at the latest available day (today-2).
	 *
	 * @param int|null $days Days to (re)sync; null = automatic.
	 */
	public function sync( $days = null ) {
		if ( ! $this->plugin->gsc_oauth->is_connected() ) {
			return new \WP_Error( 'seolity_gsc_disconnected', __( 'Search Console is not connected.', 'seolity' ) );
		}
		$has_data = (int) $this->plugin->db->value( 'SELECT COUNT(*) FROM {gsc_daily} WHERE project_id = %d', array( $this->plugin->db->project_id() ) );
		if ( null === $days || ! $has_data ) {
			$days = $has_data ? 5 : (int) $this->plugin->settings->get( 'gsc_sync_days' );
		}
		$end   = gmdate( 'Y-m-d', strtotime( '-2 days' ) );
		$start = gmdate( 'Y-m-d', strtotime( $end . ' -' . ( max( 1, $days ) - 1 ) . ' days' ) );
		$q     = $this->plugin->queue;

		$q->push( 'gsc_sync_range', array( 'start' => $start, 'end' => $end, 'kind' => 'totals' ), array( 'priority' => 3, 'group' => 'gsc_sync' ) );
		foreach ( self::BREAKDOWNS as $dim ) {
			$q->push( 'gsc_sync_range', array( 'start' => $start, 'end' => $end, 'kind' => $dim ), array( 'priority' => 6, 'group' => 'gsc_sync' ) );
		}
		// Newest days first so dashboards fill up quickly during a back-fill.
		for ( $d = strtotime( $end ); $d >= strtotime( $start ); $d -= DAY_IN_SECONDS ) {
			$day = gmdate( 'Y-m-d', $d );
			$q->push( 'gsc_sync_range', array( 'start' => $day, 'end' => $day, 'kind' => 'page_query' ), array( 'priority' => 4, 'group' => 'gsc_sync', 'unique' => 'pq_' . $day ) );
		}
		update_option( 'seolity_gsc_sync_requested', time(), false );
		return true;
	}

	public function sync_range( $start, $end, $kind, $start_row = 0 ) {
		$client = $this->plugin->gsc_client;
		$db     = $this->plugin->db;
		$pid    = $db->project_id();

		if ( 'totals' === $kind ) {
			$res = $client->query( array( 'startDate' => $start, 'endDate' => $end, 'dimensions' => array( 'date' ), 'rowLimit' => 25000 ) );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			foreach ( (array) ( isset( $res['rows'] ) ? $res['rows'] : array() ) as $r ) {
				$db->upsert(
					'gsc_daily',
					array(
						'project_id'  => $pid,
						'date'        => $r['keys'][0],
						'clicks'      => (int) $r['clicks'],
						'impressions' => (int) $r['impressions'],
						'ctr'         => (float) $r['ctr'],
						'position'    => (float) $r['position'],
					),
					array( 'clicks', 'impressions', 'ctr', 'position' )
				);
			}
			update_option( 'seolity_gsc_last_sync', gmdate( 'c' ), false );
			return true;
		}

		if ( in_array( $kind, self::BREAKDOWNS, true ) ) {
			$dims = 'searchAppearance' === $kind ? array( $kind ) : array( 'date', $kind );
			$res  = $client->query( array( 'startDate' => $start, 'endDate' => $end, 'dimensions' => $dims, 'rowLimit' => 25000 ) );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			foreach ( (array) ( isset( $res['rows'] ) ? $res['rows'] : array() ) as $r ) {
				$db->upsert(
					'gsc_breakdown',
					array(
						'project_id'  => $pid,
						'date'        => 'searchAppearance' === $kind ? $end : $r['keys'][0],
						'dimension'   => $kind,
						'dim_value'   => substr( (string) end( $r['keys'] ), 0, 100 ),
						'clicks'      => (int) $r['clicks'],
						'impressions' => (int) $r['impressions'],
						'ctr'         => (float) $r['ctr'],
						'position'    => (float) $r['position'],
					),
					array( 'clicks', 'impressions', 'ctr', 'position' )
				);
			}
			return true;
		}

		if ( 'page_query' === $kind ) {
			$limit = (int) $this->plugin->settings->get( 'gsc_row_limit' );
			$res   = $client->query(
				array(
					'startDate'  => $start,
					'endDate'    => $end,
					'dimensions' => array( 'page', 'query' ),
					'rowLimit'   => $limit,
					'startRow'   => (int) $start_row,
				)
			);
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$rows = (array) ( isset( $res['rows'] ) ? $res['rows'] : array() );
			$this->bulk_insert_page_query( $pid, $start, $rows );
			if ( count( $rows ) >= $limit && $start_row < 200000 ) {
				$this->plugin->queue->push( 'gsc_sync_range', array( 'start' => $start, 'end' => $end, 'kind' => 'page_query', 'start_row' => $start_row + $limit ), array( 'priority' => 4, 'group' => 'gsc_sync' ) );
			}
			if ( 0 === $this->plugin->queue->pending_in_group( 'gsc_sync' ) ) {
				do_action( 'seolity_gsc_synced' );
				$this->plugin->queue->push( 'recommendations_refresh', array(), array( 'unique' => true, 'priority' => 12, 'delay' => 60 ) );
			}
			return true;
		}
		return new \WP_Error( 'seolity_gsc_kind', 'Unknown sync kind ' . $kind );
	}

	/** Multi-row INSERT … ON DUPLICATE KEY UPDATE in chunks of 500. */
	private function bulk_insert_page_query( $pid, $date, array $rows ) {
		$wpdb  = $this->plugin->db->wpdb;
		$table = $this->plugin->db->table( 'gsc_page_query' );
		foreach ( array_chunk( $rows, 500 ) as $chunk ) {
			$values = array();
			$args   = array();
			foreach ( $chunk as $r ) {
				$page     = (string) $r['keys'][0];
				$query    = mb_substr( (string) $r['keys'][1], 0, 500 );
				$values[] = '(%d,%s,%s,%s,%s,%s,%d,%d,%f,%f)';
				array_push( $args, $pid, $date, Database::hash( $page ), Database::hash( $query ), $page, $query, (int) $r['clicks'], (int) $r['impressions'], (float) $r['ctr'], (float) $r['position'] );
			}
			$sql = "INSERT INTO {$table} (project_id,date,page_hash,query_hash,page,query,clicks,impressions,ctr,position) VALUES " . implode( ',', $values )
				. ' ON DUPLICATE KEY UPDATE clicks=VALUES(clicks), impressions=VALUES(impressions), ctr=VALUES(ctr), position=VALUES(position)';
			$wpdb->query( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	}
}
