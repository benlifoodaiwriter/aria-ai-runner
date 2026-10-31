<?php
/**
 * Advanced Search Console analytics over the local warehouse (section 6).
 *
 * All periods compare "current N days" with the preceding N days, ending at
 * the latest date with data. Positions are impression-weighted.
 *
 * @package SEOlity
 */

namespace SEOlity\GSC;

use SEOlity\Core\Database;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class Analytics {

	/** Expected organic CTR by rounded position (industry-average curve, blended with site data). */
	const CTR_CURVE = array( 1 => 0.28, 2 => 0.155, 3 => 0.105, 4 => 0.075, 5 => 0.055, 6 => 0.043, 7 => 0.034, 8 => 0.028, 9 => 0.023, 10 => 0.02 );

	/** @var Plugin */
	private $plugin;

	/** @var string|null */
	private $latest = null;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	private function pid() {
		return $this->plugin->db->project_id();
	}

	public function latest_date() {
		if ( null === $this->latest ) {
			$d            = $this->plugin->db->value( 'SELECT MAX(date) FROM {gsc_daily} WHERE project_id = %d', array( $this->pid() ) );
			$this->latest = $d ? $d : gmdate( 'Y-m-d', strtotime( '-2 days' ) );
		}
		return $this->latest;
	}

	/** @return array{0:string,1:string,2:string,3:string} cur_start, cur_end, prev_start, prev_end */
	public function periods( $days ) {
		$days = max( 1, (int) $days );
		$end  = $this->latest_date();
		$cs   = gmdate( 'Y-m-d', strtotime( "$end -" . ( $days - 1 ) . ' days' ) );
		$pe   = gmdate( 'Y-m-d', strtotime( "$cs -1 day" ) );
		$ps   = gmdate( 'Y-m-d', strtotime( "$pe -" . ( $days - 1 ) . ' days' ) );
		return array( $cs, $end, $ps, $pe );
	}

	public function has_data() {
		return (bool) $this->plugin->db->value( 'SELECT 1 FROM {gsc_daily} WHERE project_id = %d LIMIT 1', array( $this->pid() ) );
	}

	public static function expected_ctr( $position ) {
		$p = (int) round( max( 1, $position ) );
		if ( isset( self::CTR_CURVE[ $p ] ) ) {
			return self::CTR_CURVE[ $p ];
		}
		return $p <= 20 ? 0.012 - ( $p - 11 ) * 0.0008 : 0.003;
	}

	private function totals( $start, $end ) {
		$r = $this->plugin->db->row(
			'SELECT COALESCE(SUM(clicks),0) clicks, COALESCE(SUM(impressions),0) impressions, COALESCE(SUM(position*impressions)/NULLIF(SUM(impressions),0),0) position FROM {gsc_daily} WHERE project_id = %d AND date BETWEEN %s AND %s',
			array( $this->pid(), $start, $end )
		);
		$clicks = (int) $r['clicks'];
		$impr   = (int) $r['impressions'];
		return array(
			'clicks'      => $clicks,
			'impressions' => $impr,
			'ctr'         => $impr ? round( $clicks / $impr * 100, 2 ) : 0,
			'position'    => round( (float) $r['position'], 1 ),
		);
	}

	private static function delta( $cur, $prev ) {
		$out = array();
		foreach ( $cur as $k => $v ) {
			$p         = isset( $prev[ $k ] ) ? $prev[ $k ] : 0;
			$out[ $k ] = $p ? round( ( $v - $p ) / $p * 100, 1 ) : null;
		}
		$out['position'] = round( $prev['position'] - $cur['position'], 1 ); // Positive = improved.
		return $out;
	}

	public function overview( $days = 28 ) {
		list( $cs, $ce, $ps, $pe ) = $this->periods( $days );
		$cur                       = $this->totals( $cs, $ce );
		$prev                      = $this->totals( $ps, $pe );
		$series                    = $this->plugin->db->rows( 'SELECT date, clicks, impressions, ROUND(ctr*100,2) ctr, ROUND(position,1) position FROM {gsc_daily} WHERE project_id = %d AND date BETWEEN %s AND %s ORDER BY date', array( $this->pid(), $ps, $ce ) );
		return array(
			'period'   => array( 'start' => $cs, 'end' => $ce, 'prev_start' => $ps, 'prev_end' => $pe, 'days' => (int) $days ),
			'current'  => $cur,
			'previous' => $prev,
			'delta'    => self::delta( $cur, $prev ),
			'series'   => array_map(
				static function ( $r ) {
					return array( 'date' => $r['date'], 'clicks' => (int) $r['clicks'], 'impressions' => (int) $r['impressions'], 'ctr' => (float) $r['ctr'], 'position' => (float) $r['position'] );
				},
				$series
			),
			'has_data' => $this->has_data(),
		);
	}

	/**
	 * Group page×query rows by page or query for a period, with previous-period deltas.
	 *
	 * @param string $by     page|query.
	 * @param int    $days   Window.
	 * @param int    $limit  Rows.
	 * @param string $order  clicks|impressions|delta_clicks|ctr|position.
	 * @param array  $filter search(string), page(string url), min_impressions.
	 */
	public function grouped( $by, $days = 28, $limit = 50, $order = 'clicks', array $filter = array() ) {
		$by                        = 'query' === $by ? 'query' : 'page';
		list( $cs, $ce, $ps, $pe ) = $this->periods( $days );
		$hash                      = $by . '_hash';
		$where                     = 'project_id = %d AND date BETWEEN %s AND %s';
		$extra                     = array();
		if ( ! empty( $filter['search'] ) ) {
			$where  .= " AND {$by} LIKE %s";
			$extra[] = '%' . $this->plugin->db->wpdb->esc_like( $filter['search'] ) . '%';
		}
		if ( ! empty( $filter['page'] ) ) {
			$where  .= ' AND page_hash = %s';
			$extra[] = Database::url_hash( $filter['page'] );
		}
		if ( ! empty( $filter['query'] ) ) {
			$where  .= ' AND query_hash = %s';
			$extra[] = Database::hash( $filter['query'] );
		}
		$sql = "SELECT {$hash} h, MAX({$by}) label, SUM(clicks) clicks, SUM(impressions) impressions, SUM(position*impressions)/NULLIF(SUM(impressions),0) position, COUNT(DISTINCT " . ( 'page' === $by ? 'query_hash' : 'page_hash' ) . ") related
			FROM {gsc_page_query} WHERE {$where} GROUP BY {$hash}";
		$cur  = $this->plugin->db->rows( $sql, array_merge( array( $this->pid(), $cs, $ce ), $extra ) );
		$prev = $this->plugin->db->rows( $sql, array_merge( array( $this->pid(), $ps, $pe ), $extra ) );
		$pmap = array();
		foreach ( $prev as $r ) {
			$pmap[ $r['h'] ] = $r;
		}
		$out = array();
		foreach ( $cur as $r ) {
			$p     = isset( $pmap[ $r['h'] ] ) ? $pmap[ $r['h'] ] : null;
			$impr  = (int) $r['impressions'];
			if ( ! empty( $filter['min_impressions'] ) && $impr < (int) $filter['min_impressions'] ) {
				continue;
			}
			$out[] = array(
				$by                => $r['label'],
				'clicks'           => (int) $r['clicks'],
				'impressions'      => $impr,
				'ctr'              => $impr ? round( $r['clicks'] / $impr * 100, 2 ) : 0,
				'position'         => round( (float) $r['position'], 1 ),
				'related'          => (int) $r['related'],
				'prev_clicks'      => $p ? (int) $p['clicks'] : 0,
				'prev_impressions' => $p ? (int) $p['impressions'] : 0,
				'prev_position'    => $p ? round( (float) $p['position'], 1 ) : null,
				'delta_clicks'     => (int) $r['clicks'] - ( $p ? (int) $p['clicks'] : 0 ),
				'delta_impressions' => $impr - ( $p ? (int) $p['impressions'] : 0 ),
				'is_new'           => null === $p,
			);
		}
		$key = in_array( $order, array( 'clicks', 'impressions', 'delta_clicks', 'ctr', 'position', 'delta_impressions' ), true ) ? $order : 'clicks';
		usort(
			$out,
			static function ( $a, $b ) use ( $key ) {
				return 'position' === $key ? $a[ $key ] <=> $b[ $key ] : $b[ $key ] <=> $a[ $key ];
			}
		);
		return array_slice( $out, 0, max( 1, (int) $limit ) );
	}

	public function top_queries( $days = 28, $limit = 50, array $filter = array() ) {
		return $this->grouped( 'query', $days, $limit, 'clicks', $filter );
	}

	public function top_pages( $days = 28, $limit = 50, array $filter = array() ) {
		return $this->grouped( 'page', $days, $limit, 'clicks', $filter );
	}

	/** Winners & losers by click delta. */
	public function movers( $by = 'page', $days = 28, $limit = 15 ) {
		$all     = $this->grouped( $by, $days, 5000, 'delta_clicks' );
		$winners = array_slice( array_values( array_filter( $all, static function ( $r ) {
			return $r['delta_clicks'] > 0;
		} ) ), 0, $limit );
		usort( $all, static function ( $a, $b ) {
			return $a['delta_clicks'] <=> $b['delta_clicks'];
		} );
		$losers = array_slice( array_values( array_filter( $all, static function ( $r ) {
			return $r['delta_clicks'] < 0;
		} ) ), 0, $limit );
		return array( 'winners' => $winners, 'losers' => $losers );
	}

	/** New & lost keywords between the two periods. */
	public function keyword_changes( $days = 28, $limit = 50 ) {
		list( $cs, $ce, $ps, $pe ) = $this->periods( $days );
		$sql                       = 'SELECT query_hash h, MAX(query) query, SUM(impressions) impressions, SUM(clicks) clicks, SUM(position*impressions)/NULLIF(SUM(impressions),0) position FROM {gsc_page_query} WHERE project_id = %d AND date BETWEEN %s AND %s GROUP BY query_hash HAVING impressions >= 5';
		$cur                       = array();
		foreach ( $this->plugin->db->rows( $sql, array( $this->pid(), $cs, $ce ) ) as $r ) {
			$cur[ $r['h'] ] = $r;
		}
		$prev = array();
		foreach ( $this->plugin->db->rows( $sql, array( $this->pid(), $ps, $pe ) ) as $r ) {
			$prev[ $r['h'] ] = $r;
		}
		$fmt  = static function ( $r ) {
			return array( 'query' => $r['query'], 'impressions' => (int) $r['impressions'], 'clicks' => (int) $r['clicks'], 'position' => round( (float) $r['position'], 1 ) );
		};
		$new  = array_map( $fmt, array_values( array_diff_key( $cur, $prev ) ) );
		$lost = array_map( $fmt, array_values( array_diff_key( $prev, $cur ) ) );
		$sort = static function ( $a, $b ) {
			return $b['impressions'] <=> $a['impressions'];
		};
		usort( $new, $sort );
		usort( $lost, $sort );
		return array(
			'new'        => array_slice( $new, 0, $limit ),
			'lost'       => array_slice( $lost, 0, $limit ),
			'new_count'  => count( $new ),
			'lost_count' => count( $lost ),
		);
	}

	/** Pages that appear in the current window but had no impressions before. */
	public function new_pages( $days = 28, $limit = 30 ) {
		return array_slice( array_values( array_filter( $this->grouped( 'page', $days, 5000, 'impressions' ), static function ( $r ) {
			return $r['is_new'];
		} ) ), 0, $limit );
	}

	/** Rising / declining pages (≥ 20% change with meaningful volume). */
	public function trending_pages( $days = 28, $limit = 20 ) {
		$all    = $this->grouped( 'page', $days, 5000, 'delta_clicks' );
		$rising = array();
		$falls  = array();
		foreach ( $all as $r ) {
			if ( $r['prev_clicks'] < 10 && $r['clicks'] < 10 ) {
				continue;
			}
			$base         = max( 1, $r['prev_clicks'] );
			$r['change']  = round( ( $r['clicks'] - $r['prev_clicks'] ) / $base * 100, 1 );
			if ( $r['change'] >= 20 ) {
				$rising[] = $r;
			} elseif ( $r['change'] <= -20 ) {
				$falls[] = $r;
			}
		}
		usort( $falls, static function ( $a, $b ) {
			return $a['delta_clicks'] <=> $b['delta_clicks'];
		} );
		return array( 'rising' => array_slice( $rising, 0, $limit ), 'declining' => array_slice( $falls, 0, $limit ) );
	}

	/**
	 * CTR opportunities: pages whose CTR is well below the expected CTR for
	 * their position. estimated_gain = impressions × (expected − actual).
	 */
	public function ctr_opportunities( $days = 28, $limit = 30, $min_impressions = 300 ) {
		$out = array();
		foreach ( $this->grouped( 'page', $days, 3000, 'impressions', array( 'min_impressions' => $min_impressions ) ) as $r ) {
			if ( $r['position'] > 15 ) {
				continue;
			}
			$expected = self::expected_ctr( $r['position'] ) * 100;
			$actual   = $r['ctr'];
			if ( $actual >= $expected * 0.7 ) {
				continue;
			}
			$r['expected_ctr']   = round( $expected, 2 );
			$r['estimated_gain'] = (int) round( $r['impressions'] * ( $expected - $actual ) / 100 );
			$out[]               = $r;
		}
		usort( $out, static function ( $a, $b ) {
			return $b['estimated_gain'] <=> $a['estimated_gain'];
		} );
		return array_slice( $out, 0, $limit );
	}

	/** Striking-distance pages: many queries ranking 8–20. */
	public function position_opportunities( $days = 28, $limit = 30 ) {
		list( $cs, $ce ) = $this->periods( $days );
		$rows            = $this->plugin->db->rows(
			'SELECT page_hash, MAX(page) page, COUNT(*) queries, SUM(impressions) impressions, SUM(clicks) clicks, SUM(position*impressions)/NULLIF(SUM(impressions),0) position
			FROM ( SELECT page_hash, MAX(page) page, query_hash, SUM(impressions) impressions, SUM(clicks) clicks, SUM(position*impressions)/NULLIF(SUM(impressions),0) position
				FROM {gsc_page_query} WHERE project_id = %d AND date BETWEEN %s AND %s GROUP BY page_hash, query_hash HAVING position BETWEEN 8 AND 20 AND impressions >= 10 ) t
			GROUP BY page_hash HAVING queries >= 3 ORDER BY impressions DESC LIMIT %d',
			array( $this->pid(), $cs, $ce, (int) $limit )
		);
		foreach ( $rows as &$r ) {
			$r['queries']        = (int) $r['queries'];
			$r['impressions']    = (int) $r['impressions'];
			$r['clicks']         = (int) $r['clicks'];
			$r['position']       = round( (float) $r['position'], 1 );
			$r['estimated_gain'] = (int) round( $r['impressions'] * ( self::expected_ctr( 5 ) - self::expected_ctr( $r['position'] ) ) );
			unset( $r['page_hash'] );
		}
		return $rows;
	}

	/** Query count per position bucket (current window). */
	public function ranking_distribution( $days = 28 ) {
		list( $cs, $ce ) = $this->periods( $days );
		$rows            = $this->plugin->db->rows(
			'SELECT CASE WHEN p <= 3 THEN \'1-3\' WHEN p <= 10 THEN \'4-10\' WHEN p <= 20 THEN \'11-20\' WHEN p <= 50 THEN \'21-50\' ELSE \'51+\' END bucket, COUNT(*) c
			FROM ( SELECT query_hash, SUM(position*impressions)/NULLIF(SUM(impressions),0) p FROM {gsc_page_query} WHERE project_id = %d AND date BETWEEN %s AND %s GROUP BY query_hash ) t GROUP BY bucket',
			array( $this->pid(), $cs, $ce )
		);
		$out = array( '1-3' => 0, '4-10' => 0, '11-20' => 0, '21-50' => 0, '51+' => 0 );
		foreach ( $rows as $r ) {
			$out[ $r['bucket'] ] = (int) $r['c'];
		}
		return $out;
	}

	public function breakdown( $dimension, $days = 28 ) {
		list( $cs, $ce ) = $this->periods( $days );
		return array_map(
			static function ( $r ) {
				$impr = (int) $r['impressions'];
				return array( 'value' => $r['dim_value'], 'clicks' => (int) $r['clicks'], 'impressions' => $impr, 'ctr' => $impr ? round( $r['clicks'] / $impr * 100, 2 ) : 0, 'position' => round( (float) $r['position'], 1 ) );
			},
			$this->plugin->db->rows(
				'SELECT dim_value, SUM(clicks) clicks, SUM(impressions) impressions, SUM(position*impressions)/NULLIF(SUM(impressions),0) position FROM {gsc_breakdown} WHERE project_id = %d AND dimension = %s AND date BETWEEN %s AND %s GROUP BY dim_value ORDER BY clicks DESC LIMIT 50',
				array( $this->pid(), $dimension, $cs, $ce )
			)
		);
	}

	/** Metrics for one URL over the last N days (used by changes & experiments). */
	public function page_metrics( $url, $days = 28, $end = null ) {
		$end   = $end ? $end : $this->latest_date();
		$start = gmdate( 'Y-m-d', strtotime( "$end -" . ( max( 1, (int) $days ) - 1 ) . ' days' ) );
		return $this->page_window( $url, $start, $end );
	}

	public function page_window( $url, $start, $end ) {
		$r    = $this->plugin->db->row(
			'SELECT COALESCE(SUM(clicks),0) clicks, COALESCE(SUM(impressions),0) impressions, COALESCE(SUM(position*impressions)/NULLIF(SUM(impressions),0),0) position, COUNT(DISTINCT query_hash) queries
			FROM {gsc_page_query} WHERE project_id = %d AND page_hash = %s AND date BETWEEN %s AND %s',
			array( $this->pid(), Database::url_hash( $url ), $start, $end )
		);
		$impr = (int) $r['impressions'];
		return array(
			'start'       => $start,
			'end'         => $end,
			'clicks'      => (int) $r['clicks'],
			'impressions' => $impr,
			'ctr'         => $impr ? round( $r['clicks'] / $impr * 100, 2 ) : 0,
			'position'    => round( (float) $r['position'], 1 ),
			'queries'     => (int) $r['queries'],
		);
	}

	/** Site totals for an arbitrary window (experiment control). */
	public function site_window( $start, $end ) {
		return $this->totals( $start, $end );
	}

	public function page_queries( $url, $days = 90, $limit = 25 ) {
		return array_map(
			static function ( $r ) {
				return array( 'query' => $r['query'], 'clicks' => $r['clicks'], 'impressions' => $r['impressions'], 'ctr' => $r['ctr'], 'position' => $r['position'] );
			},
			$this->grouped( 'query', $days, $limit, 'impressions', array( 'page' => $url ) )
		);
	}

	public function query_pages( $query, $days = 90 ) {
		return $this->grouped( 'page', $days, 20, 'impressions', array( 'query' => $query ) );
	}

	/** Page series for charts on a single URL. */
	public function page_series( $url, $days = 90 ) {
		list( $cs, $ce ) = $this->periods( $days );
		return $this->plugin->db->rows(
			'SELECT date, SUM(clicks) clicks, SUM(impressions) impressions, ROUND(SUM(position*impressions)/NULLIF(SUM(impressions),0),1) position FROM {gsc_page_query} WHERE project_id = %d AND page_hash = %s AND date BETWEEN %s AND %s GROUP BY date ORDER BY date',
			array( $this->pid(), Database::url_hash( $url ), $cs, $ce )
		);
	}
}
