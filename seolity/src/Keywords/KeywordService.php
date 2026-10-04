<?php
/**
 * Keyword intelligence (sections 15–16): GSC-driven keyword universe, AI
 * keyword research, intent classification, opportunity scoring, keyword→URL
 * mapping and cannibalization detection with AI resolution advice.
 *
 * @package SEOlity
 */

namespace SEOlity\Keywords;

use SEOlity\Analysis\Text;
use SEOlity\Core\Database;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class KeywordService {

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	private function pid() {
		return $this->plugin->db->project_id();
	}

	/**
	 * Opportunity 0–100: demand (impressions, log-scaled) × upside (distance to
	 * top 3) × current traction. Pure function — explainable in the UI.
	 */
	public static function opportunity( $impressions, $position, $ctr = 0 ) {
		if ( $impressions <= 0 ) {
			return 0;
		}
		$demand = min( 1, log10( 1 + $impressions ) / 4.5 );
		$upside = $position <= 3 ? 0.2 : ( $position <= 10 ? 0.75 : ( $position <= 20 ? 1 : ( $position <= 40 ? 0.6 : 0.3 ) ) );
		return (int) round( 100 * $demand * $upside );
	}

	public function sync_from_gsc() {
		if ( ! $this->plugin->gsc_analytics->has_data() ) {
			return true;
		}
		$queries = $this->plugin->gsc_analytics->grouped( 'query', 90, 3000, 'impressions', array( 'min_impressions' => 10 ) );
		$db      = $this->plugin->db;
		foreach ( $queries as $q ) {
			$pages = $this->plugin->gsc_analytics->query_pages( $q['query'], 90 );
			$best  = $pages ? $pages[0]['page'] : null;
			$db->upsert(
				'keywords',
				array(
					'project_id'   => $this->pid(),
					'keyword'      => mb_substr( $q['query'], 0, 250 ),
					'keyword_hash' => Database::hash( Text::normalize( $q['query'] ) ),
					'intent'       => $this->plugin->analyzer->guess_intent( $q['query'] ),
					'kw_type'      => $this->type_of( $q['query'] ),
					'difficulty'   => $this->difficulty_estimate( $q['position'] ),
					'opportunity'  => self::opportunity( $q['impressions'], $q['position'], $q['ctr'] ),
					'target_url'   => $best,
					'source'       => 'gsc',
					'data'         => array(
						'clicks'      => $q['clicks'],
						'impressions' => $q['impressions'],
						'ctr'         => $q['ctr'],
						'position'    => $q['position'],
						'pages'       => count( $pages ),
						'prev_position' => $q['prev_position'],
						'synced'      => gmdate( 'c' ),
					),
					'created_at'   => Database::now(),
				),
				array( 'intent', 'opportunity', 'difficulty', 'target_url', 'data' )
			);
		}
		update_option( 'seolity_keywords_synced', time(), false );
		return true;
	}

	private function type_of( $kw ) {
		if ( preg_match( '/^(how|what|why|when|which|who|where|can|is|does|do)\b|[?؟]|چگونه|چطور|چیست|چرا|آیا|کدام/u', $kw ) ) {
			return 'question';
		}
		return count( Text::words( $kw ) ) >= 4 ? 'long_tail' : 'head';
	}

	/** Rough, clearly-labelled estimate from our own ranking (no paid data source). */
	private function difficulty_estimate( $position ) {
		return (int) max( 5, min( 95, round( 15 + $position * 1.6 ) ) );
	}

	public function query( array $f ) {
		$db    = $this->plugin->db;
		$where = array( 'project_id = %d' );
		$args  = array( $this->pid() );
		if ( ! empty( $f['search'] ) ) {
			$where[] = 'keyword LIKE %s';
			$args[]  = '%' . $db->wpdb->esc_like( $f['search'] ) . '%';
		}
		foreach ( array( 'intent', 'kw_type', 'source' ) as $k ) {
			if ( ! empty( $f[ $k ] ) ) {
				$where[] = "$k = %s";
				$args[]  = $f[ $k ];
			}
		}
		if ( ! empty( $f['tracked'] ) ) {
			$where[] = 'tracked = 1';
		}
		if ( ! empty( $f['unmapped'] ) ) {
			$where[] = "(target_url IS NULL OR target_url = '')";
		}
		$order = isset( $f['orderby'] ) && in_array( $f['orderby'], array( 'opportunity', 'difficulty', 'keyword', 'created_at' ), true ) ? $f['orderby'] : 'opportunity';
		$per   = isset( $f['per_page'] ) ? max( 1, min( 500, (int) $f['per_page'] ) ) : 100;
		$page  = isset( $f['page'] ) ? max( 1, (int) $f['page'] ) : 1;
		$rows  = $db->rows( 'SELECT * FROM {keywords} WHERE ' . implode( ' AND ', $where ) . " ORDER BY {$order} " . ( 'keyword' === $order ? 'ASC' : 'DESC' ) . ' LIMIT %d OFFSET %d', array_merge( $args, array( $per, ( $page - 1 ) * $per ) ) );
		return array(
			'items'  => array_map( static function ( $r ) {
				return Database::decode( $r, array( 'data' ) );
			}, $rows ),
			'total'  => (int) $db->value( 'SELECT COUNT(*) FROM {keywords} WHERE ' . implode( ' AND ', $where ), $args ),
			'intents' => $db->rows( 'SELECT intent, COUNT(*) c FROM {keywords} WHERE project_id = %d GROUP BY intent', array( $this->pid() ) ),
			'synced' => get_option( 'seolity_keywords_synced' ),
		);
	}

	public function add( array $keywords, $source = 'manual', $parent = 0, $track = true ) {
		$n = 0;
		foreach ( $keywords as $kw ) {
			$data = is_array( $kw ) ? $kw : array( 'keyword' => $kw );
			$word = trim( sanitize_text_field( $data['keyword'] ) );
			if ( '' === $word ) {
				continue;
			}
			$this->plugin->db->upsert(
				'keywords',
				array(
					'project_id'   => $this->pid(),
					'keyword'      => mb_substr( $word, 0, 250 ),
					'keyword_hash' => Database::hash( Text::normalize( $word ) ),
					'intent'       => isset( $data['intent'] ) ? sanitize_key( $data['intent'] ) : $this->plugin->analyzer->guess_intent( $word ),
					'kw_type'      => isset( $data['type'] ) ? sanitize_key( $data['type'] ) : $this->type_of( $word ),
					'difficulty'   => isset( $data['difficulty'] ) ? (int) $data['difficulty'] : null,
					'opportunity'  => isset( $data['opportunity'] ) ? (int) $data['opportunity'] : null,
					'target_url'   => isset( $data['url'] ) ? esc_url_raw( $data['url'] ) : null,
					'source'       => $source,
					'parent_id'    => (int) $parent,
					'tracked'      => $track ? 1 : 0,
					'data'         => isset( $data['rationale'] ) ? array( 'rationale' => $data['rationale'] ) : array(),
					'created_at'   => Database::now(),
				),
				array( 'tracked' )
			);
			$n++;
		}
		return $n;
	}

	public function update( $id, array $data ) {
		$allowed = array_intersect_key( $data, array_flip( array( 'tracked', 'target_url', 'intent' ) ) );
		if ( isset( $allowed['target_url'] ) ) {
			$allowed['target_url'] = esc_url_raw( $allowed['target_url'] );
		}
		if ( isset( $allowed['intent'] ) ) {
			$allowed['intent'] = sanitize_key( $allowed['intent'] );
		}
		return (bool) $this->plugin->db->update( 'keywords', $allowed, array( 'id' => (int) $id, 'project_id' => $this->pid() ) );
	}

	public function delete( $id ) {
		return (bool) $this->plugin->db->delete( 'keywords', array( 'id' => (int) $id, 'project_id' => $this->pid() ) );
	}

	/** AI keyword research from seeds (stores results). */
	public function research( array $seeds, $market = '' ) {
		$seeds = array_slice( array_filter( array_map( 'sanitize_text_field', $seeds ) ), 0, 10 );
		if ( ! $seeds ) {
			return new \WP_Error( 'seolity_kw', __( 'Enter at least one seed keyword.', 'seolity' ), array( 'status' => 400 ) );
		}
		$related = array();
		if ( $this->plugin->gsc_analytics->has_data() ) {
			foreach ( $seeds as $seed ) {
				foreach ( $this->plugin->gsc_analytics->top_queries( 90, 15, array( 'search' => $seed ) ) as $q ) {
					$related[] = array( 'query' => $q['query'], 'impressions' => $q['impressions'], 'position' => $q['position'] );
				}
			}
		}
		$pages = $this->plugin->db->rows( "SELECT url, title FROM {urls} WHERE project_id = %d AND status_code = 200 AND indexable = 1 AND crawl_status = 'done' ORDER BY inlinks DESC LIMIT 60", array( $this->pid() ) );
		$res   = $this->plugin->ai->run(
			'keyword_research',
			array(
				'seeds'          => $seeds,
				'market'         => $market ? $market : get_locale(),
				'site_pages'     => $pages,
				'gsc_related'    => array_slice( $related, 0, 60 ),
			),
			array( 'knowledge_query' => implode( ' ', $seeds ) )
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$map = array();
		foreach ( $res['data']['mapping'] as $m ) {
			$map[ Text::normalize( $m['keyword'] ) ] = $m['url'];
		}
		$rows = array();
		foreach ( $res['data']['keywords'] as $k ) {
			$k['url'] = isset( $map[ Text::normalize( $k['keyword'] ) ] ) && 0 === strpos( $map[ Text::normalize( $k['keyword'] ) ], 'http' ) ? $map[ Text::normalize( $k['keyword'] ) ] : null;
			$rows[]   = $k;
		}
		$this->add( $rows, 'ai', 0, false );
		return array_merge( $res['data'], array( 'meta' => $res['meta'] ) );
	}

	/**
	 * Queries where several URLs split impressions meaningfully.
	 *
	 * @return array[] query, impressions, pages[]
	 */
	public function cannibalization( $days = 28, $limit = 50 ) {
		if ( ! $this->plugin->gsc_analytics->has_data() ) {
			return array();
		}
		list( $cs, $ce ) = $this->plugin->gsc_analytics->periods( $days );
		$rows            = $this->plugin->db->rows(
			'SELECT query_hash, MAX(query) query, page_hash, MAX(page) page, SUM(clicks) clicks, SUM(impressions) impressions, SUM(position*impressions)/NULLIF(SUM(impressions),0) position
			FROM {gsc_page_query} WHERE project_id = %d AND date BETWEEN %s AND %s AND query_hash IN (
				SELECT query_hash FROM ( SELECT query_hash FROM {gsc_page_query} WHERE project_id = %d AND date BETWEEN %s AND %s GROUP BY query_hash HAVING COUNT(DISTINCT page_hash) > 1 AND SUM(impressions) >= 50 ORDER BY SUM(impressions) DESC LIMIT 400 ) x
			) GROUP BY query_hash, page_hash',
			array( $this->pid(), $cs, $ce, $this->pid(), $cs, $ce )
		);
		$by = array();
		foreach ( $rows as $r ) {
			$by[ $r['query_hash'] ]['query']   = $r['query'];
			$by[ $r['query_hash'] ]['pages'][] = array( 'page' => $r['page'], 'clicks' => (int) $r['clicks'], 'impressions' => (int) $r['impressions'], 'position' => round( (float) $r['position'], 1 ) );
		}
		$out = array();
		foreach ( $by as $q ) {
			$total = array_sum( wp_list_pluck( $q['pages'], 'impressions' ) );
			$sig   = array_values( array_filter( $q['pages'], static function ( $p ) use ( $total ) {
				return $p['impressions'] >= max( 10, $total * 0.1 ) && $p['position'] <= 30;
			} ) );
			if ( count( $sig ) < 2 ) {
				continue;
			}
			// Ignore homepage + one page pairs for brand queries.
			usort( $sig, static function ( $a, $b ) {
				return $b['impressions'] <=> $a['impressions'];
			} );
			foreach ( $sig as &$p ) {
				$p['share'] = round( $p['impressions'] / max( 1, $total ) * 100, 1 );
			}
			$out[] = array( 'query' => $q['query'], 'impressions' => $total, 'pages' => $sig );
		}
		usort( $out, static function ( $a, $b ) {
			return $b['impressions'] <=> $a['impressions'];
		} );
		return array_slice( $out, 0, $limit );
	}

	/** AI advice for one cannibalized query. */
	public function cannibalization_advice( $query ) {
		$c = null;
		foreach ( $this->cannibalization( 28, 400 ) as $row ) {
			if ( Text::normalize( $row['query'] ) === Text::normalize( $query ) ) {
				$c = $row;
				break;
			}
		}
		if ( ! $c ) {
			return new \WP_Error( 'seolity_cann', __( 'No current cannibalization found for this query.', 'seolity' ), array( 'status' => 404 ) );
		}
		$pages = array();
		foreach ( $c['pages'] as $p ) {
			$row     = $this->plugin->db->row( 'SELECT title, h1, word_count, inlinks, data FROM {urls} WHERE project_id = %d AND url_hash = %s', array( $this->pid(), Database::hash( $p['page'] ) ) );
			$d       = $row ? json_decode( (string) $row['data'], true ) : array();
			$pages[] = array_merge(
				$p,
				array(
					'title'      => $row ? $row['title'] : '',
					'h1'         => $row ? $row['h1'] : '',
					'word_count' => $row ? (int) $row['word_count'] : null,
					'inlinks'    => $row ? (int) $row['inlinks'] : null,
					'summary'    => isset( $d['text_sample'] ) ? mb_substr( $d['text_sample'], 0, 400 ) : '',
					'other_queries' => array_slice( wp_list_pluck( $this->plugin->gsc_analytics->page_queries( $p['page'], 28, 6 ), 'query' ), 0, 6 ),
				)
			);
		}
		$res = $this->plugin->ai->run( 'cannibalization', array( 'query' => $c['query'], 'total_impressions' => $c['impressions'], 'pages' => $pages ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array( 'query' => $c['query'], 'pages' => $pages, 'advice' => $res['data'], 'meta' => $res['meta'] );
	}

	/** Keyword → URL map with conflicts (several keywords targeting one URL is fine; one keyword → many URLs is not). */
	public function mapping() {
		$rows = $this->plugin->db->rows( "SELECT target_url, COUNT(*) c, GROUP_CONCAT(keyword ORDER BY opportunity DESC SEPARATOR '|') kws FROM {keywords} WHERE project_id = %d AND target_url IS NOT NULL AND target_url <> '' GROUP BY target_url ORDER BY c DESC LIMIT 200", array( $this->pid() ) );
		foreach ( $rows as &$r ) {
			$r['keywords'] = array_slice( explode( '|', $r['kws'] ), 0, 15 );
			unset( $r['kws'] );
		}
		return array(
			'by_url'   => $rows,
			'unmapped' => $this->plugin->db->rows( "SELECT keyword, intent, opportunity FROM {keywords} WHERE project_id = %d AND (target_url IS NULL OR target_url = '') ORDER BY opportunity DESC LIMIT 100", array( $this->pid() ) ),
		);
	}
}
