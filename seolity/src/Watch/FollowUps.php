<?php
/**
 * Follow-ups: "look at this again on date X and tell me what happened".
 *
 * A follow-up stores the pages and search queries to watch, the goal, the check date and a
 * baseline taken from Search Console when it is created (last 28 days). Every day SEOlity checks
 * the due ones, compares the newest 28 days with the baseline and sends the owner a result
 * notification (and e-mail) with the numbers and links. Created from the AI chat, automatically
 * after every applied change (operation), or by hand.
 *
 * @package SEOlity
 */

namespace SEOlity\Watch;

use SEOlity\Core\Database;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class FollowUps {

	const OPTION = 'seolity_follow_ups';
	const MAX    = 300;

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function all() {
		$list = get_option( self::OPTION, array() );
		return is_array( $list ) ? $list : array();
	}

	private function save( array $list ) {
		// Keep every waiting item and the newest finished ones.
		usort(
			$list,
			static function ( $a, $b ) {
				return strcmp( $b['created_at'], $a['created_at'] );
			}
		);
		$waiting = array_values( array_filter( $list, static function ( $f ) {
			return 'waiting' === $f['status'];
		} ) );
		$done    = array_values( array_filter( $list, static function ( $f ) {
			return 'waiting' !== $f['status'];
		} ) );
		update_option( self::OPTION, array_slice( array_merge( $waiting, $done ), 0, self::MAX ), false );
	}

	/**
	 * @param array $f title, goal, urls[], queries[], days (check after), source, ref.
	 */
	public function add( array $f ) {
		$urls    = array_values( array_unique( array_filter( array_map( array( $this, 'abs_url' ), isset( $f['urls'] ) ? (array) $f['urls'] : array() ) ) ) );
		$queries = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', isset( $f['queries'] ) ? (array) $f['queries'] : array() ) ) ) );
		$days    = max( 3, min( 180, isset( $f['days'] ) ? (int) $f['days'] : 28 ) );
		$item    = array(
			'id'         => substr( md5( wp_generate_password( 12, false, false ) . microtime() ), 0, 12 ),
			'title'      => mb_substr( wp_strip_all_tags( isset( $f['title'] ) ? (string) $f['title'] : __( 'Follow-up', 'seolity' ) ), 0, 200 ),
			'goal'       => mb_substr( wp_strip_all_tags( isset( $f['goal'] ) ? (string) $f['goal'] : '' ), 0, 500 ),
			'urls'       => array_slice( $urls, 0, 10 ),
			'queries'    => array_slice( $queries, 0, 10 ),
			'source'     => isset( $f['source'] ) ? sanitize_key( $f['source'] ) : 'manual',
			'ref'        => isset( $f['ref'] ) ? (array) $f['ref'] : array(),
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
			'check_at'   => gmdate( 'Y-m-d', time() + $days * DAY_IN_SECONDS ),
			'baseline'   => $this->metrics( $urls, $queries ),
			'status'     => 'waiting',
			'result'     => null,
		);
		$list   = $this->all();
		$list[] = $item;
		$this->save( $list );
		return $item;
	}

	public function remove( $id ) {
		$this->save( array_values( array_filter( $this->all(), static function ( $f ) use ( $id ) {
			return $f['id'] !== $id;
		} ) ) );
	}

	/** Check every follow-up whose date has come; returns how many were reported. */
	public function check( $force_id = '' ) {
		$list  = $this->all();
		$today = gmdate( 'Y-m-d' );
		$n     = 0;
		foreach ( $list as &$f ) {
			if ( 'waiting' !== $f['status'] || ( $f['check_at'] > $today && $f['id'] !== $force_id ) ) {
				continue;
			}
			$now = $this->metrics( $f['urls'], $f['queries'] );
			if ( ! $now['available'] && empty( $f['postponed'] ) ) {
				$f['check_at']  = gmdate( 'Y-m-d', time() + 7 * DAY_IN_SECONDS );
				$f['postponed'] = 1;
				continue;
			}
			$f['result'] = $this->compare( $f['baseline'], $now );
			$f['status'] = 'done';
			$f['checked_at'] = gmdate( 'Y-m-d H:i:s' );
			$this->report( $f );
			$n++;
		}
		unset( $f );
		$this->save( $list );
		return $n;
	}

	/** Clicks, impressions and position of the pages and queries, newest 28 days of stored data. */
	public function metrics( array $urls, array $queries ) {
		$db  = $this->plugin->db;
		$pid = $db->project_id();
		$max = (string) $db->value( 'SELECT MAX(date) FROM {gsc_page_query} WHERE project_id = %d', array( $pid ) );
		$out = array( 'available' => '' !== $max, 'until' => $max, 'pages' => array(), 'queries' => array() );
		if ( '' === $max ) {
			return $out;
		}
		foreach ( $urls as $u ) {
			$r = $db->row( 'SELECT SUM(clicks) c, SUM(impressions) i, SUM(position * impressions) / NULLIF(SUM(impressions), 0) pos FROM {gsc_page_query} WHERE project_id = %d AND page_hash = %s AND date > DATE_SUB(%s, INTERVAL 28 DAY)', array( $pid, Database::url_hash( $u ), $max ) );
			$out['pages'][ $u ] = array( 'clicks' => (int) $r['c'], 'impressions' => (int) $r['i'], 'position' => null === $r['pos'] ? null : round( (float) $r['pos'], 1 ) );
		}
		foreach ( $queries as $q ) {
			$r = $db->row( 'SELECT SUM(clicks) c, SUM(impressions) i, SUM(position * impressions) / NULLIF(SUM(impressions), 0) pos FROM {gsc_page_query} WHERE project_id = %d AND query_hash = %s AND date > DATE_SUB(%s, INTERVAL 28 DAY)', array( $pid, Database::hash( $q ), $max ) );
			if ( ! (int) $r['i'] ) {
				$r = $db->row( 'SELECT SUM(clicks) c, SUM(impressions) i, SUM(position * impressions) / NULLIF(SUM(impressions), 0) pos FROM {gsc_page_query} WHERE project_id = %d AND query = %s AND date > DATE_SUB(%s, INTERVAL 28 DAY)', array( $pid, $q, $max ) );
			}
			$out['queries'][ $q ] = array( 'clicks' => (int) $r['c'], 'impressions' => (int) $r['i'], 'position' => null === $r['pos'] ? null : round( (float) $r['pos'], 1 ) );
		}
		return $out;
	}

	/** Per item and overall: better / worse / same. Position: lower is better. */
	private function compare( array $before, array $after ) {
		$lines = array();
		$score = 0;
		foreach ( array( 'pages', 'queries' ) as $kind ) {
			foreach ( isset( $after[ $kind ] ) ? $after[ $kind ] : array() as $k => $a ) {
				$b      = isset( $before[ $kind ][ $k ] ) ? $before[ $kind ][ $k ] : array( 'clicks' => 0, 'impressions' => 0, 'position' => null );
				$dc     = $a['clicks'] - $b['clicks'];
				$dp     = null !== $a['position'] && null !== $b['position'] ? round( $b['position'] - $a['position'], 1 ) : null;
				$s      = ( $dc > 0 ? 1 : ( $dc < 0 ? -1 : 0 ) ) + ( null === $dp ? 0 : ( $dp >= 0.5 ? 1 : ( $dp <= -0.5 ? -1 : 0 ) ) );
				$score += $s;
				$lines[] = array( 'kind' => $kind, 'key' => $k, 'before' => $b, 'after' => $a, 'clicks_delta' => $dc, 'position_gain' => $dp, 'trend' => $s > 0 ? 'up' : ( $s < 0 ? 'down' : 'flat' ) );
			}
		}
		return array( 'verdict' => $score > 0 ? 'better' : ( $score < 0 ? 'worse' : 'same' ), 'lines' => $lines, 'until' => $after['until'] );
	}

	private function report( array $f ) {
		$r     = $f['result'];
		$texts = array();
		foreach ( $r['lines'] as $l ) {
			$name = 'pages' === $l['kind'] ? urldecode( (string) wp_parse_url( $l['key'], PHP_URL_PATH ) ) : '«' . $l['key'] . '»';
			/* translators: 1 page or query 2 clicks before 3 after 4 position before 5 after */
			$texts[] = sprintf( __( '%1$s: clicks %2$d → %3$d, position %4$s → %5$s', 'seolity' ), $name, $l['before']['clicks'], $l['after']['clicks'], null === $l['before']['position'] ? '—' : $l['before']['position'], null === $l['after']['position'] ? '—' : $l['after']['position'] );
		}
		$verdict = array( 'better' => __( 'It got better.', 'seolity' ), 'worse' => __( 'It got worse — worth a look.', 'seolity' ), 'same' => __( 'No clear change yet.', 'seolity' ) );
		/* translators: 1 created date 2 title */
		$title = sprintf( __( 'Result of “%2$s” (since %1$s)', 'seolity' ), mysql2date( get_option( 'date_format' ), $f['created_at'] ), $f['title'] );
		$msg   = $verdict[ $r['verdict'] ] . ( $f['goal'] ? "\n" . __( 'Goal:', 'seolity' ) . ' ' . $f['goal'] : '' ) . ( $texts ? "\n\n" . implode( "\n", $texts ) : '' );
		$ask   = rawurlencode( sprintf( 'Explain the result of the follow-up "%s" and what to do next.', $f['title'] ) );
		$this->plugin->alerts->message(
			'better' === $r['verdict'] ? 'success' : ( 'worse' === $r['verdict'] ? 'warning' : 'result' ),
			$title,
			$msg,
			array(
				'kind'      => 'better' === $r['verdict'] ? 'success' : ( 'worse' === $r['verdict'] ? 'warning' : 'result' ),
				'severity'  => 'medium',
				'follow_up' => $f['id'],
				'url'       => $f['urls'] ? $f['urls'][0] : '',
				'links'     => array( array( 'title' => __( 'Search Console', 'seolity' ), 'route' => '#/gsc' ), array( 'title' => __( 'Ask the AI what to do next', 'seolity' ), 'route' => '#/ai?ask=' . $ask ) ),
			),
			'follow_up|' . $f['id']
		);
	}

	/** After every applied change: come back in 28 days and report what it did. */
	public function from_operation( $op ) {
		if ( ! is_array( $op ) || 'done' !== $op['status'] ) {
			return;
		}
		$urls = array();
		foreach ( (array) $op['preview'] as $it ) {
			if ( ! empty( $it['url'] ) ) {
				$urls[] = $it['url'];
			}
		}
		if ( ! $urls ) {
			return;
		}
		$this->add(
			array(
				'title'  => $op['title'] ? $op['title'] : __( 'Applied change', 'seolity' ),
				'goal'   => __( 'More clicks and a better position for the changed pages.', 'seolity' ),
				'urls'   => array_slice( $urls, 0, 5 ),
				'days'   => 28,
				'source' => 'operation',
				'ref'    => array( 'operation_id' => (int) $op['id'] ),
			)
		);
	}

	private function abs_url( $u ) {
		$u = trim( (string) $u );
		if ( '' === $u ) {
			return '';
		}
		return esc_url_raw( preg_match( '#^https?://#', $u ) ? $u : home_url( '/' . ltrim( $u, '/' ) ) );
	}
}
