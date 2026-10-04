<?php
/**
 * AI learning from results (section 14): aggregates evaluated experiments into
 * Site-Specific SEO Intelligence ("on this site, rewriting product titles
 * raised CTR by +18% on average") which is fed back into every AI prompt.
 *
 * @package SEOlity
 */

namespace SEOlity\Experiments;

use SEOlity\Core\Database;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class Learning {

	const METRICS = array( 'clicks', 'ctr', 'impressions', 'position' );

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function recompute() {
		$db   = $this->plugin->db;
		$pid  = $db->project_id();
		$rows = $db->rows( "SELECT change_type, object_type, outcome, metrics_after FROM {experiments} WHERE project_id = %d AND status = 'evaluated' AND outcome <> 'inconclusive'", array( $pid ) );
		$agg  = array();
		foreach ( $rows as $r ) {
			$m = json_decode( (string) $r['metrics_after'], true );
			if ( empty( $m['adjusted'] ) ) {
				continue;
			}
			foreach ( self::METRICS as $metric ) {
				$k = $r['change_type'] . '|' . $r['object_type'] . '|' . $metric;
				if ( ! isset( $agg[ $k ] ) ) {
					$agg[ $k ] = array( 'sum' => 0, 'n' => 0, 'positive' => 0, 'negative' => 0, 'neutral' => 0 );
				}
				$agg[ $k ]['sum'] += (float) $m['adjusted'][ $metric ];
				$agg[ $k ]['n']++;
				$agg[ $k ][ $r['outcome'] ]++;
			}
		}
		$db->query( 'DELETE FROM {learnings} WHERE project_id = %d', array( $pid ) );
		foreach ( $agg as $k => $a ) {
			list( $type, $object, $metric ) = explode( '|', $k );
			$db->insert(
				'learnings',
				array(
					'project_id'  => $pid,
					'change_type' => $type,
					'object_type' => $object,
					'metric'      => $metric,
					'samples'     => $a['n'],
					'avg_delta'   => round( $a['sum'] / max( 1, $a['n'] ), 2 ),
					'positive'    => $a['positive'],
					'negative'    => $a['negative'],
					'neutral'     => $a['neutral'],
					'updated_at'  => Database::now(),
				)
			);
		}
		delete_transient( 'seolity_learning_insights' );
		return count( $agg );
	}

	public function all() {
		return $this->plugin->db->rows( 'SELECT * FROM {learnings} WHERE project_id = %d ORDER BY samples DESC, ABS(avg_delta) DESC', array( $this->plugin->db->project_id() ) );
	}

	/** Human-readable insights (≥3 samples). */
	public function insights() {
		$out = array();
		foreach ( $this->all() as $l ) {
			if ( (int) $l['samples'] < 3 || ! in_array( $l['metric'], array( 'ctr', 'clicks', 'position' ), true ) ) {
				continue;
			}
			$unit  = 'position' === $l['metric'] ? '' : '%';
			$sign  = $l['avg_delta'] > 0 ? '+' : '';
			$out[] = array(
				'change_type' => $l['change_type'],
				'object_type' => $l['object_type'],
				'metric'      => $l['metric'],
				'avg_delta'   => (float) $l['avg_delta'],
				'samples'     => (int) $l['samples'],
				'win_rate'    => (int) round( 100 * $l['positive'] / max( 1, $l['samples'] ) ),
				/* translators: 1 change 2 page type 3 metric 4 delta 5 n 6 wins */
				'text'        => sprintf( __( 'Changing %1$s on %2$s pages moved %3$s by %4$s on average (%5$d experiments, %6$d positive).', 'seolity' ), str_replace( '+', ' + ', $l['change_type'] ), $l['object_type'], $l['metric'], $sign . $l['avg_delta'] . $unit, $l['samples'], $l['positive'] ),
			);
		}
		return $out;
	}

	/** Compact lines injected into AI system prompts. */
	public function prompt_insights() {
		$cached = get_transient( 'seolity_learning_insights' );
		if ( false !== $cached ) {
			return $cached;
		}
		$lines = array_slice( wp_list_pluck( $this->insights(), 'text' ), 0, 8 );
		set_transient( 'seolity_learning_insights', $lines, 12 * HOUR_IN_SECONDS );
		return $lines;
	}

	public function for_change_type( $change_type, $object_type ) {
		return array_values( array_filter( $this->insights(), static function ( $i ) use ( $change_type ) {
			return false !== strpos( $i['change_type'], explode( '+', $change_type )[0] );
		} ) );
	}
}
