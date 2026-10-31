<?php
/**
 * Where the site's SEO problems are: technical / indexing, on-page, internal links, content,
 * speed, products and off-site — each with a weighted score from the crawler's open issues and
 * the roadmap's canonical issues, the share of all problems, affected URLs and the top problems.
 *
 * @package SEOlity
 */

namespace SEOlity\Analysis;

use SEOlity\Crawler\Checks;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class ProblemAreas {

	/** Area => [crawler check categories, roadmap categories, route]. */
	const MAP = array(
		'technical'   => array( array( 'indexation', 'technical', 'security', 'mobile', 'international' ), array( 'indexability', 'technical', '404' ), '#/indexability' ),
		'onpage'      => array( array( 'meta', 'schema', 'images' ), array( 'onpage' ), '#/audit' ),
		'links'       => array( array( 'links' ), array( 'links' ), '#/links' ),
		'content'     => array( array( 'content' ), array( 'content', 'duplicates' ), '#/duplicates' ),
		'performance' => array( array( 'performance' ), array(), '#/audit' ),
		'products'    => array( array(), array( 'products' ), '#/products' ),
		'offpage'     => array( array(), array( 'competitors' ), '#/competitors' ),
	);

	const WEIGHT = array( 'critical' => 10, 'high' => 5, 'medium' => 2, 'low' => 1, 'info' => 0 );

	public static function labels() {
		return array(
			'technical'   => __( 'Technical SEO & indexing', 'seolity' ),
			'onpage'      => __( 'On-page SEO (titles, descriptions, headings, schema, images)', 'seolity' ),
			'links'       => __( 'Internal links', 'seolity' ),
			'content'     => __( 'Content & cannibalization', 'seolity' ),
			'performance' => __( 'Speed & Core Web Vitals', 'seolity' ),
			'products'    => __( 'Product pages', 'seolity' ),
			'offpage'     => __( 'Off-site & competition (backlinks, brand)', 'seolity' ),
		);
	}

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function compute() {
		$db      = $this->plugin->db;
		$pid     = $db->project_id();
		$catalog = Checks::catalog();
		$total_urls = max( 1, (int) $db->value( 'SELECT COUNT(*) FROM {urls} WHERE project_id = %d', array( $pid ) ) );
		$crawl   = $db->rows( "SELECT check_code, severity, COUNT(*) n, COUNT(DISTINCT url_id) urls FROM {issues} WHERE project_id = %d AND status = 'open' GROUP BY check_code, severity", array( $pid ) );
		$recs    = $db->rows( "SELECT category, priority, COUNT(*) n, MAX(title) sample FROM {recommendations} WHERE project_id = %d AND status IN ('open','in_progress') GROUP BY category, priority", array( $pid ) );
		$labels  = self::labels();
		$areas   = array();
		foreach ( self::MAP as $area => $m ) {
			$areas[ $area ] = array( 'id' => $area, 'label' => $labels[ $area ], 'route' => $m[2], 'score' => 0, 'issues' => 0, 'critical' => 0, 'affected_urls' => 0, 'top' => array() );
		}
		$top = array();
		foreach ( $crawl as $r ) {
			if ( ! isset( $catalog[ $r['check_code'] ] ) ) {
				continue;
			}
			$cat  = $catalog[ $r['check_code'] ][0];
			$area = null;
			foreach ( self::MAP as $a => $m ) {
				if ( in_array( $cat, $m[0], true ) ) {
					$area = $a;
				}
			}
			if ( ! $area ) {
				continue;
			}
			$w = isset( self::WEIGHT[ $r['severity'] ] ) ? self::WEIGHT[ $r['severity'] ] : 1;
			$areas[ $area ]['score']         += $w * (int) $r['n'];
			$areas[ $area ]['issues']        += (int) $r['n'];
			$areas[ $area ]['affected_urls'] += (int) $r['urls'];
			$areas[ $area ]['critical']      += 'critical' === $r['severity'] ? (int) $r['n'] : 0;
			$top[ $area ][]                   = array( 'title' => $catalog[ $r['check_code'] ][3], 'count' => (int) $r['n'], 'weight' => $w * (int) $r['n'] );
		}
		foreach ( $recs as $r ) {
			foreach ( self::MAP as $a => $m ) {
				if ( in_array( $r['category'], $m[1], true ) ) {
					$w = isset( self::WEIGHT[ $r['priority'] ] ) ? self::WEIGHT[ $r['priority'] ] : 1;
					$areas[ $a ]['score']    += $w * (int) $r['n'];
					$areas[ $a ]['issues']   += (int) $r['n'];
					$areas[ $a ]['critical'] += 'critical' === $r['priority'] ? (int) $r['n'] : 0;
					$top[ $a ][]              = array( 'title' => $r['sample'], 'count' => (int) $r['n'], 'weight' => $w * (int) $r['n'] );
				}
			}
		}
		$sum = max( 1, array_sum( wp_list_pluck( $areas, 'score' ) ) );
		foreach ( $areas as $a => &$x ) {
			$x['share']         = (int) round( 100 * $x['score'] / $sum );
			$x['affected_share'] = (int) min( 100, round( 100 * $x['affected_urls'] / $total_urls ) );
			$t = isset( $top[ $a ] ) ? $top[ $a ] : array();
			usort(
				$t,
				static function ( $p, $q ) {
					return $q['weight'] <=> $p['weight'];
				}
			);
			$x['top'] = array_slice( $t, 0, 4 );
		}
		unset( $x );
		if ( ! class_exists( 'WooCommerce' ) ) {
			unset( $areas['products'] );
		}
		// Backlinks and brand demand are not in any data SEOlity has: say so instead of guessing.
		$areas['offpage']['note'] = __( 'Backlinks and brand demand cannot be measured from your site; this area only counts competitor gaps. Judge it with the competitor analyses.', 'seolity' );
		uasort(
			$areas,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);
		return array_values( $areas );
	}
}
