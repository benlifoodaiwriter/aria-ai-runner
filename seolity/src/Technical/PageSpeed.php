<?php
/**
 * Core Web Vitals via PageSpeed Insights API (lab + CrUX field data).
 * One URL per job (PSI calls take 10–30s) for the most important pages.
 *
 * @package SEOlity
 */

namespace SEOlity\Technical;

use SEOlity\Core\Database;
use SEOlity\Crawler\Checks;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class PageSpeed {

	const ENDPOINT  = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
	const PER_DAY   = 10;

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function run_batch() {
		$done_today = (int) get_transient( 'seolity_psi_count_' . gmdate( 'Ymd' ) );
		if ( $done_today >= self::PER_DAY ) {
			return true;
		}
		$db  = $this->plugin->db;
		$row = $db->row(
			"SELECT id, url, data FROM {urls} WHERE project_id = %d AND crawl_status = 'done' AND status_code = 200 AND indexable = 1
			AND ( data NOT LIKE %s OR modified_at IS NULL OR modified_at < %s ) ORDER BY ( object_type IS NULL ), inlinks DESC",
			array( $db->project_id(), '%"psi_at"%', gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) )
		);
		if ( ! $row ) {
			return true;
		}
		$result = $this->test( $row['url'] );
		$data   = (array) json_decode( (string) $row['data'], true );
		$data['psi_at'] = gmdate( 'c' );
		if ( ! is_wp_error( $result ) ) {
			$data['psi'] = $result;
			$poor        = $this->is_poor( $result );
			Checks::record( $this->plugin, (int) $row['id'], $poor ? array( 'cwv_poor' => $result ) : array(), array( 'cwv_poor' ) );
		}
		$db->update( 'urls', array( 'data' => $data, 'modified_at' => Database::now() ), array( 'id' => (int) $row['id'] ) );
		set_transient( 'seolity_psi_count_' . gmdate( 'Ymd' ), $done_today + 1, DAY_IN_SECONDS );
		if ( $done_today + 1 < self::PER_DAY ) {
			$this->plugin->queue->push( 'pagespeed_run', array(), array( 'unique' => true, 'priority' => 40, 'delay' => 60 ) );
		}
		return true;
	}

	/** Run PSI for a URL (mobile). */
	public function test( $url, $strategy = 'mobile' ) {
		$args = array(
			'url'      => $url,
			'strategy' => $strategy,
			'category' => 'performance',
		);
		$key = $this->plugin->settings->secret( 'pagespeed_api_key' );
		if ( $key ) {
			$args['key'] = $key;
		}
		$res = wp_remote_get( add_query_arg( array_map( 'rawurlencode', $args ), self::ENDPOINT ), array( 'timeout' => 90 ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $res ) || ! is_array( $body ) ) {
			return new \WP_Error( 'seolity_psi', isset( $body['error']['message'] ) ? $body['error']['message'] : 'PageSpeed error' );
		}
		$audits = isset( $body['lighthouseResult']['audits'] ) ? $body['lighthouseResult']['audits'] : array();
		$field  = isset( $body['loadingExperience']['metrics'] ) ? $body['loadingExperience']['metrics'] : array();
		$num    = static function ( $key ) use ( $audits ) {
			return isset( $audits[ $key ]['numericValue'] ) ? round( (float) $audits[ $key ]['numericValue'] ) : null;
		};
		$fp     = static function ( $key ) use ( $field ) {
			return isset( $field[ $key ]['percentile'] ) ? (float) $field[ $key ]['percentile'] : null;
		};
		$opps = array();
		foreach ( $audits as $id => $a ) {
			if ( isset( $a['details']['type'] ) && 'opportunity' === $a['details']['type'] && isset( $a['score'] ) && $a['score'] < 0.9 && ! empty( $a['details']['overallSavingsMs'] ) ) {
				$opps[] = array( 'id' => $id, 'title' => $a['title'], 'savings_ms' => (int) $a['details']['overallSavingsMs'] );
			}
		}
		usort( $opps, static function ( $a, $b ) {
			return $b['savings_ms'] <=> $a['savings_ms'];
		} );
		return array(
			'strategy'      => $strategy,
			'score'         => isset( $body['lighthouseResult']['categories']['performance']['score'] ) ? (int) round( $body['lighthouseResult']['categories']['performance']['score'] * 100 ) : null,
			'lab'           => array( 'lcp' => $num( 'largest-contentful-paint' ), 'cls' => isset( $audits['cumulative-layout-shift']['numericValue'] ) ? round( $audits['cumulative-layout-shift']['numericValue'], 3 ) : null, 'tbt' => $num( 'total-blocking-time' ), 'fcp' => $num( 'first-contentful-paint' ), 'ttfb' => $num( 'server-response-time' ), 'si' => $num( 'speed-index' ) ),
			'field'         => array( 'lcp' => $fp( 'LARGEST_CONTENTFUL_PAINT_MS' ), 'inp' => $fp( 'INTERACTION_TO_NEXT_PAINT' ), 'cls' => null !== $fp( 'CUMULATIVE_LAYOUT_SHIFT_SCORE' ) ? $fp( 'CUMULATIVE_LAYOUT_SHIFT_SCORE' ) / 100 : null, 'category' => isset( $body['loadingExperience']['overall_category'] ) ? $body['loadingExperience']['overall_category'] : null ),
			'opportunities' => array_slice( $opps, 0, 6 ),
		);
	}

	private function is_poor( array $r ) {
		$f = $r['field'];
		if ( null !== $f['lcp'] || null !== $f['inp'] ) {
			return ( $f['lcp'] && $f['lcp'] > 4000 ) || ( $f['inp'] && $f['inp'] > 500 ) || ( null !== $f['cls'] && $f['cls'] > 0.25 );
		}
		return null !== $r['score'] && $r['score'] < 50;
	}

	/** Summary for dashboards. */
	public function summary() {
		$rows = $this->plugin->db->rows( "SELECT url, data FROM {urls} WHERE project_id = %d AND data LIKE %s ORDER BY inlinks DESC LIMIT 50", array( $this->plugin->db->project_id(), '%"psi":%' ) );
		$out  = array();
		foreach ( $rows as $r ) {
			$d = json_decode( (string) $r['data'], true );
			if ( ! empty( $d['psi'] ) ) {
				$out[] = array( 'url' => $r['url'], 'tested_at' => isset( $d['psi_at'] ) ? $d['psi_at'] : null ) + $d['psi'];
			}
		}
		return $out;
	}
}
