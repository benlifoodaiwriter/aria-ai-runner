<?php
/**
 * Deep Search Console evidence for the AI: finds the queries and pages a question is about and
 * returns their full history — weekly clicks / impressions / position, which page Google showed
 * for the query in which week (page swaps, e.g. "why does page X rank instead of the home page"),
 * the sharpest drop and the edits made to those pages around it.
 *
 * @package SEOlity
 */

namespace SEOlity\GSC;

use SEOlity\Analysis\Text;
use SEOlity\Core\Database;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class Insights {

	/** Words that describe the question, not the search query. */
	const NOISE = array( 'رتبه', 'رتبهٔ', 'جایگاه', 'افت', 'کاهش', 'چرا', 'چطور', 'چگونه', 'کرده', 'کردن', 'است', 'شده', 'سایت', 'صفحه', 'گوگل', 'سرچ', 'کنسول', 'کوئری', 'کلمه', 'کلیدی', 'برای', 'درباره', 'تحلیل', 'بررسی', 'میخوام', 'می‌خواهم', 'بگو', 'چقدر', 'کی', 'از', 'در', 'به', 'با', 'که', 'را', 'و', 'یا', 'این', 'آن', 'ما', 'من', 'rank', 'ranking', 'position', 'drop', 'why', 'query', 'keyword', 'google', 'search', 'console', 'page', 'site', 'the', 'for', 'our', 'my' );

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	private function pid() {
		return $this->plugin->db->project_id();
	}

	/** What data exists, so the AI knows it may ask for and rely on query-level detail. */
	public function coverage() {
		$db  = $this->plugin->db;
		$row = $db->row( 'SELECT MIN(date) first_date, MAX(date) last_date, COUNT(DISTINCT query_hash) queries, COUNT(DISTINCT page_hash) pages FROM {gsc_page_query} WHERE project_id = %d', array( $this->pid() ) );
		return $row ? array( 'first_date' => $row['first_date'], 'last_date' => $row['last_date'], 'queries' => (int) $row['queries'], 'pages' => (int) $row['pages'] ) : null;
	}

	/**
	 * Stored queries a free-text question is about: quoted phrases first, then the queries that
	 * contain the question's meaningful words, ranked by coverage and impressions.
	 */
	public function match_queries( $text, $limit = 5 ) {
		$db    = $this->plugin->db;
		$since = gmdate( 'Y-m-d', time() - 480 * DAY_IN_SECONDS );
		$out   = array();
		if ( preg_match_all( '/[«"“]([^»"”]{2,80})[»"”]/u', (string) $text, $m ) ) {
			foreach ( $m[1] as $phrase ) {
				$rows = $db->rows( 'SELECT query, SUM(impressions) imp FROM {gsc_page_query} WHERE project_id = %d AND date >= %s AND query LIKE %s GROUP BY query_hash, query ORDER BY imp DESC LIMIT 5', array( $this->pid(), $since, '%' . $db->wpdb->esc_like( trim( $phrase ) ) . '%' ) );
				foreach ( $rows as $r ) {
					$out[ $r['query'] ] = 1000000 + (int) $r['imp'];
				}
			}
		}
		if ( $out ) {
			// The question named the query explicitly: that is what it is about.
			arsort( $out );
			return array_slice( array_keys( $out ), 0, $limit );
		}
		$noise = array_flip( self::NOISE );
		$words = array_values(
			array_unique(
				array_filter(
					Text::words( Text::normalize( (string) $text ) ),
					static function ( $w ) use ( $noise ) {
						return mb_strlen( $w ) > 1 && ! isset( $noise[ $w ] ) && ! is_numeric( $w );
					}
				)
			)
		);
		if ( $words ) {
			$words = array_slice( $words, 0, 6 );
			// Candidates contain at least one word; score = share of the question's words they contain.
			$like  = implode( ' OR ', array_fill( 0, count( $words ), 'query LIKE %s' ) );
			$args  = array( $this->pid(), $since );
			foreach ( $words as $w ) {
				$args[] = '%' . $db->wpdb->esc_like( $w ) . '%';
			}
			$rows = $db->rows( "SELECT query, SUM(impressions) imp FROM {gsc_page_query} WHERE project_id = %d AND date >= %s AND ($like) GROUP BY query_hash, query ORDER BY imp DESC LIMIT 300", $args ); // phpcs:ignore WordPress.DB
			foreach ( $rows as $r ) {
				$q   = Text::normalize( $r['query'] );
				$hit = 0;
				foreach ( $words as $w ) {
					$hit += false !== mb_strpos( $q, $w ) ? 1 : 0;
				}
				$cover = $hit / count( $words );
				$qlen  = max( 1, count( Text::words( $q ) ) );
				// Both directions: the question names the query, and the query is mostly made of the question's words.
				if ( $cover > 0.5 || $hit / $qlen >= 0.75 ) {
					$score = (int) ( 100000 * min( 1, $cover + $hit / $qlen ) ) + (int) $r['imp'];
					$out[ $r['query'] ] = max( isset( $out[ $r['query'] ] ) ? $out[ $r['query'] ] : 0, $score );
				}
			}
		}
		arsort( $out );
		return array_slice( array_keys( $out ), 0, $limit );
	}

	private static function pos( $row ) {
		return $row && (float) $row['impressions'] ? round( (float) $row['pw'] / (float) $row['impressions'], 1 ) : null;
	}

	/** Full history of one query: weekly series, pages over time, swaps, the drop and edits around it. */
	public function query_report( $query ) {
		$db   = $this->plugin->db;
		$qh   = Database::hash( $query );
		$pid  = $this->pid();
		$week = $db->rows( 'SELECT YEARWEEK(date, 3) yw, MIN(date) start, SUM(clicks) clicks, SUM(impressions) impressions, SUM(position*impressions) pw FROM {gsc_page_query} WHERE project_id = %d AND query_hash = %s GROUP BY yw ORDER BY yw', array( $pid, $qh ) );
		if ( ! $week ) {
			return null;
		}
		$series = array();
		foreach ( $week as $w ) {
			$series[] = array( 'week' => $w['start'], 'clicks' => (int) $w['clicks'], 'impressions' => (int) $w['impressions'], 'position' => self::pos( $w ) );
		}
		// Which page Google showed each week (most impressions), and its position.
		$pw   = $db->rows( 'SELECT YEARWEEK(date, 3) yw, MIN(date) start, page, SUM(impressions) impressions, SUM(clicks) clicks, SUM(position*impressions) pw FROM {gsc_page_query} WHERE project_id = %d AND query_hash = %s GROUP BY yw, page_hash, page ORDER BY yw, impressions DESC', array( $pid, $qh ) );
		$lead = array();
		foreach ( $pw as $r ) {
			if ( ! isset( $lead[ $r['yw'] ] ) ) {
				$lead[ $r['yw'] ] = array( 'week' => $r['start'], 'page' => $r['page'], 'position' => self::pos( $r ) );
			}
		}
		$swaps = array();
		$prev  = null;
		foreach ( $lead as $l ) {
			if ( $prev && $prev['page'] !== $l['page'] ) {
				$swaps[] = array( 'week' => $l['week'], 'from_page' => $prev['page'], 'from_position' => $prev['position'], 'to_page' => $l['page'], 'to_position' => $l['position'] );
			}
			$prev = $l;
		}
		$pages = $db->rows( 'SELECT page, SUM(clicks) clicks, SUM(impressions) impressions, SUM(position*impressions) pw, MIN(date) first_seen, MAX(date) last_seen FROM {gsc_page_query} WHERE project_id = %d AND query_hash = %s GROUP BY page_hash, page ORDER BY impressions DESC LIMIT 8', array( $pid, $qh ) );
		$pages = array_map(
			static function ( $r ) {
				return array( 'page' => $r['page'], 'clicks' => (int) $r['clicks'], 'impressions' => (int) $r['impressions'], 'avg_position' => self::pos( $r ), 'first_seen' => $r['first_seen'], 'last_seen' => $r['last_seen'] );
			},
			$pages
		);
		// Sharpest 4-week drop in position (higher number = worse).
		$drop = null;
		$n    = count( $series );
		for ( $i = 4; $i + 3 < $n; $i++ ) {
			$before = array_slice( $series, $i - 4, 4 );
			$after  = array_slice( $series, $i, 4 );
			$b      = self::avg_pos( $before );
			$a      = self::avg_pos( $after );
			if ( null !== $b && null !== $a && ( ! $drop || $a - $b > $drop['change'] ) ) {
				$drop = array( 'from_week' => $series[ $i ]['week'], 'position_before' => $b, 'position_after' => $a, 'change' => round( $a - $b, 1 ), 'clicks_before' => array_sum( wp_list_pluck( $before, 'clicks' ) ), 'clicks_after' => array_sum( wp_list_pluck( $after, 'clicks' ) ) );
			}
		}
		if ( $drop && $drop['change'] < 1 ) {
			$drop = null;
		}
		$home      = untrailingslashit( home_url( '/' ) );
		$home_rank = null;
		foreach ( $pages as $pg ) {
			if ( untrailingslashit( $pg['page'] ) === $home ) {
				$home_rank = $pg;
			}
		}
		return array(
			'query'            => $query,
			'weekly'           => array_slice( $series, -70 ),
			'pages_over_time'  => array_values( array_slice( $lead, -70 ) ),
			'page_swaps'       => $swaps,
			'pages'            => $pages,
			'home_page'        => $home_rank ? $home_rank : 'the home page has no impressions for this query',
			'sharpest_drop'    => $drop,
			'edits_near_drop'  => $drop ? $this->edits_near( wp_list_pluck( $pages, 'page' ), $drop['from_week'] ) : array(),
			'edit_outcomes'    => $this->edit_outcomes( wp_list_pluck( $pages, 'page' ), $query ),
			'last_28_days'     => $this->window( $qh, 28, 0 ),
			'previous_28_days' => $this->window( $qh, 56, 28 ),
		);
	}

	private static function avg_pos( array $weeks ) {
		$imp = 0;
		$pw  = 0;
		foreach ( $weeks as $w ) {
			if ( null !== $w['position'] ) {
				$imp += $w['impressions'];
				$pw  += $w['position'] * $w['impressions'];
			}
		}
		return $imp ? round( $pw / $imp, 1 ) : null;
	}

	private function window( $qh, $from_days, $to_days ) {
		$latest = $this->latest();
		if ( ! $latest ) {
			return null;
		}
		$start = gmdate( 'Y-m-d', strtotime( $latest . ' -' . ( $from_days - 1 ) . ' days' ) );
		$end   = gmdate( 'Y-m-d', strtotime( $latest . ' -' . $to_days . ' days' ) );
		$r     = $this->plugin->db->row( 'SELECT SUM(clicks) clicks, SUM(impressions) impressions, SUM(position*impressions) pw FROM {gsc_page_query} WHERE project_id = %d AND query_hash = %s AND date BETWEEN %s AND %s', array( $this->pid(), $qh, $start, $end ) );
		return array( 'from' => $start, 'to' => $end, 'clicks' => (int) $r['clicks'], 'impressions' => (int) $r['impressions'], 'position' => self::pos( $r ) );
	}

	/** Latest day with page × query data (can be newer than the site-total table). */
	private function latest() {
		static $latest = null;
		if ( null === $latest ) {
			$latest = (string) $this->plugin->db->value( 'SELECT MAX(date) FROM {gsc_page_query} WHERE project_id = %d', array( $this->pid() ) );
		}
		return $latest;
	}

	/** Applied edits to these pages within three weeks of a date. */
	public function edits_near( array $urls, $date, $days = 21 ) {
		if ( ! $urls ) {
			return array();
		}
		$hashes = array_map( array( Database::class, 'url_hash' ), $urls );
		$in     = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
		$ids    = $this->plugin->db->rows( "SELECT object_type, object_id, url FROM {urls} WHERE project_id = %d AND url_hash IN ($in) AND object_id IS NOT NULL", array_merge( array( $this->pid() ), $hashes ) ); // phpcs:ignore WordPress.DB
		$out    = array();
		$from   = gmdate( 'Y-m-d H:i:s', strtotime( $date ) - $days * DAY_IN_SECONDS );
		$to     = gmdate( 'Y-m-d H:i:s', strtotime( $date ) + $days * DAY_IN_SECONDS );
		foreach ( $ids as $o ) {
			foreach ( $this->plugin->db->rows( "SELECT field, applied_at, source, LEFT(before_value, 160) before_value, LEFT(after_value, 160) after_value FROM {changes} WHERE object_type = %s AND object_id = %d AND status = 'applied' AND applied_at BETWEEN %s AND %s ORDER BY applied_at", array( $o['object_type'], (int) $o['object_id'], $from, $to ) ) as $c ) {
				$out[] = array( 'page' => $o['url'] ) + $c;
			}
			// Edits made directly in WordPress (revisions), which SEOlity did not make.
			if ( 'post' === $o['object_type'] ) {
				foreach ( wp_get_post_revisions( (int) $o['object_id'], array( 'posts_per_page' => 20 ) ) as $rev ) {
					if ( $rev->post_modified_gmt >= $from && $rev->post_modified_gmt <= $to ) {
						$out[] = array( 'page' => $o['url'], 'field' => 'content (WordPress revision)', 'applied_at' => $rev->post_modified_gmt, 'source' => 'editor' );
					}
				}
			}
		}
		return $out;
	}

	/** Measured effect of past edits on these pages (and on this query when it was among the page's main queries). */
	public function edit_outcomes( array $urls, $query = '' ) {
		if ( ! $urls ) {
			return array();
		}
		$hashes = array_map( array( Database::class, 'url_hash' ), $urls );
		$in     = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
		$rows   = $this->plugin->db->rows( "SELECT name, url, change_type, started_at, outcome, confidence, reason, metrics_after FROM {experiments} WHERE project_id = %d AND status = 'evaluated' AND page_hash IN ($in) ORDER BY started_at DESC LIMIT 15", array_merge( array( $this->pid() ), $hashes ) ); // phpcs:ignore WordPress.DB
		$out    = array();
		foreach ( $rows as $r ) {
			$m    = json_decode( (string) $r['metrics_after'], true );
			$this_query = null;
			foreach ( isset( $m['queries'] ) ? (array) $m['queries'] : array() as $q ) {
				if ( $query && $q['query'] === $query ) {
					$this_query = $q;
				}
			}
			$out[] = array( 'edit' => $r['name'], 'page' => $r['url'], 'what' => $r['change_type'], 'date' => $r['started_at'], 'outcome' => $r['outcome'], 'confidence' => (int) $r['confidence'], 'why' => $r['reason'], 'this_query' => $this_query );
		}
		return $out;
	}

	/** Full history of one page: weekly series and its queries now vs. before. */
	public function page_report( $url ) {
		$db  = $this->plugin->db;
		$ph  = Database::url_hash( $url );
		$pid = $this->pid();
		$wk  = $db->rows( 'SELECT YEARWEEK(date, 3) yw, MIN(date) start, SUM(clicks) clicks, SUM(impressions) impressions, SUM(position*impressions) pw FROM {gsc_page_query} WHERE project_id = %d AND page_hash = %s GROUP BY yw ORDER BY yw', array( $pid, $ph ) );
		if ( ! $wk ) {
			return null;
		}
		$latest = $this->latest();
		$mid    = gmdate( 'Y-m-d', strtotime( $latest . ' -27 days' ) );
		$prev   = gmdate( 'Y-m-d', strtotime( $latest . ' -55 days' ) );
		$q      = $db->rows( 'SELECT query, SUM(IF(date >= %s, impressions, 0)) imp_now, SUM(IF(date >= %s, clicks, 0)) clicks_now, SUM(IF(date >= %s, position*impressions, 0)) pw_now, SUM(IF(date < %s AND date >= %s, impressions, 0)) imp_prev, SUM(IF(date < %s AND date >= %s, position*impressions, 0)) pw_prev FROM {gsc_page_query} WHERE project_id = %d AND page_hash = %s AND date >= %s GROUP BY query_hash, query ORDER BY imp_now DESC LIMIT 30', array( $mid, $mid, $mid, $mid, $prev, $mid, $prev, $pid, $ph, $prev ) );
		return array(
			'page'    => $url,
			'weekly'  => array_map(
				static function ( $w ) {
					return array( 'week' => $w['start'], 'clicks' => (int) $w['clicks'], 'impressions' => (int) $w['impressions'], 'position' => self::pos( $w ) );
				},
				array_slice( $wk, -70 )
			),
			'queries' => array_map(
				static function ( $r ) {
					return array( 'query' => $r['query'], 'impressions_28d' => (int) $r['imp_now'], 'clicks_28d' => (int) $r['clicks_now'], 'position_28d' => (int) $r['imp_now'] ? round( $r['pw_now'] / $r['imp_now'], 1 ) : null, 'position_prev_28d' => (int) $r['imp_prev'] ? round( $r['pw_prev'] / $r['imp_prev'], 1 ) : null );
				},
				$q
			),
		);
	}

	/** Everything the AI needs about a question: coverage, matched queries, pages, home page. */
	public function for_question( $text, $page = '' ) {
		$out = array( 'data_available' => $this->coverage() );
		$qs  = $this->match_queries( $text, 5 );
		if ( $qs ) {
			$out['queries'] = array_values( array_filter( array_map( array( $this, 'query_report' ), $qs ) ) );
		}
		if ( $page ) {
			$out['page'] = $this->page_report( $page );
		}
		if ( preg_match( '/صفحه اصلی|صفحهٔ اصلی|هوم ?پیج|home ?page|homepage/u', (string) $text ) ) {
			$out['home_page'] = $this->page_report( home_url( '/' ) );
		}
		return $out;
	}
}
