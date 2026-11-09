<?php
/**
 * 404 monitor (section 26): logs 404s (URL, referrer, hits, first/last seen),
 * filters bots & scanners, and suggests the best redirect target using slug
 * similarity, WordPress search and (optionally) AI.
 *
 * @package SEOlity
 */

namespace SEOlity\Redirects;

use SEOlity\Analysis\Text;
use SEOlity\Core\Database;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class NotFoundMonitor {

	const BOT_RE     = '/bot|crawl|spider|slurp|bing|yandex|baidu|semrush|ahrefs|mj12|dotbot|petal|python|curl|wget|go-http|scrapy|headless/i';
	const SCANNER_RE = '#(wp-login|xmlrpc|\.env|\.git|phpmyadmin|wp-config|\.sql|\.bak|cgi-bin|/vendor/|eval-stdin|\.aspx?$|/admin\.php|/shell)#i';

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/** Called on template_redirect (late) for 404 responses. */
	public function maybe_log() {
		if ( ! is_404() || ! $this->plugin->settings->get( 'log_404' ) ) {
			return;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' === $uri || preg_match( self::SCANNER_RE, $uri ) || mb_strlen( $uri ) > 1500 ) {
			return;
		}
		$ua     = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$is_bot = (bool) preg_match( self::BOT_RE, $ua );
		if ( $is_bot && ! $this->plugin->settings->get( 'log_404_bots' ) && false === stripos( $ua, 'googlebot' ) ) {
			return;
		}
		$ref  = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		$path = Manager::normalize_source( $uri );
		$db   = $this->plugin->db;
		$now  = Database::now();
		$db->query(
			'INSERT INTO {not_found} (project_id,url,url_hash,referrer,user_agent,is_bot,hits,status,first_seen,last_seen) VALUES (%d,%s,%s,%s,%s,%d,1,%s,%s,%s)
			ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = VALUES(last_seen), referrer = IF(VALUES(referrer) <> \'\', VALUES(referrer), referrer), user_agent = VALUES(user_agent)',
			array( $db->project_id(), $path, Database::hash( $path ), substr( $ref, 0, 2000 ), substr( $ua, 0, 255 ), $is_bot ? 1 : 0, 'open', $now, $now )
		);
	}

	public function query( array $f ) {
		$db    = $this->plugin->db;
		$where = array( 'project_id = %d', 'status = %s' );
		$args  = array( $db->project_id(), ! empty( $f['status'] ) ? $f['status'] : 'open' );
		if ( ! empty( $f['search'] ) ) {
			$where[] = 'url LIKE %s';
			$args[]  = '%' . $db->wpdb->esc_like( $f['search'] ) . '%';
		}
		$per  = isset( $f['per_page'] ) ? max( 1, min( 200, (int) $f['per_page'] ) ) : 50;
		$page = isset( $f['page'] ) ? max( 1, (int) $f['page'] ) : 1;
		return array(
			'items' => $db->rows( 'SELECT * FROM {not_found} WHERE ' . implode( ' AND ', $where ) . ' ORDER BY hits DESC, last_seen DESC LIMIT %d OFFSET %d', array_merge( $args, array( $per, ( $page - 1 ) * $per ) ) ),
			'total' => (int) $db->value( 'SELECT COUNT(*) FROM {not_found} WHERE ' . implode( ' AND ', $where ), $args ),
			'stats' => array(
				'open'       => (int) $db->value( "SELECT COUNT(*) FROM {not_found} WHERE project_id = %d AND status = 'open'", array( $db->project_id() ) ),
				'hits_7d'    => (int) $db->value( "SELECT COALESCE(SUM(hits),0) FROM {not_found} WHERE project_id = %d AND status = 'open' AND last_seen >= %s", array( $db->project_id(), gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) ) ),
				'new_24h'    => (int) $db->value( 'SELECT COUNT(*) FROM {not_found} WHERE project_id = %d AND first_seen >= %s', array( $db->project_id(), gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) ),
				'with_referrer' => (int) $db->value( "SELECT COUNT(*) FROM {not_found} WHERE project_id = %d AND status = 'open' AND referrer <> ''", array( $db->project_id() ) ),
			),
		);
	}

	/** Redirect a logged 404 (to its suggestion or a given target). */
	public function redirect( $id, $target = '', $type = 301 ) {
		$row = $this->plugin->db->row( 'SELECT * FROM {not_found} WHERE id = %d', array( (int) $id ) );
		if ( ! $row ) {
			return new \WP_Error( 'seolity_404', __( 'Entry not found.', 'seolity' ), array( 'status' => 404 ) );
		}
		$target = $target ? $target : $row['suggested_target'];
		$res    = $this->plugin->redirects->create( $row['url'], $target, $type, false, '404_monitor' );
		if ( ! is_wp_error( $res ) ) {
			$this->plugin->db->update( 'not_found', array( 'status' => 'redirected' ), array( 'id' => (int) $id ) );
		}
		return $res;
	}

	public function set_status( $id, $status ) {
		return (bool) $this->plugin->db->update( 'not_found', array( 'status' => in_array( $status, array( 'open', 'ignored' ), true ) ? $status : 'ignored' ), array( 'id' => (int) $id ) );
	}

	/** Background: compute suggestions for unresolved 404s. */
	public function suggest_pending() {
		$rows = $this->plugin->db->rows( "SELECT * FROM {not_found} WHERE project_id = %d AND status = 'open' AND suggested_target IS NULL ORDER BY hits DESC LIMIT 25", array( $this->plugin->db->project_id() ) );
		$ai   = 0;
		foreach ( $rows as $r ) {
			$cands = $this->candidates( $r['url'] );
			$best  = $cands ? $cands[0] : null;
			if ( $cands && ( $best['score'] < 75 ) && $ai < 8 && $this->plugin->ai->is_ready() && (int) $r['hits'] >= 3 ) {
				$res = $this->plugin->ai->run( 'redirect_target', array( 'broken_url' => $r['url'], 'referrer' => $r['referrer'], 'candidates' => array_slice( $cands, 0, 8 ) ) );
				$ai++;
				if ( ! is_wp_error( $res ) ) {
					$best = '' !== $res['data']['target_url'] ? array( 'url' => $res['data']['target_url'], 'score' => (int) $res['data']['confidence'] ) : null;
				}
			}
			$this->plugin->db->update(
				'not_found',
				array(
					'suggested_target' => $best ? $best['url'] : '',
					'suggestion_score' => $best ? min( 100, (int) $best['score'] ) : 0,
				),
				array( 'id' => (int) $r['id'] )
			);
		}
		return true;
	}

	/** Candidate targets ranked by slug/title similarity. */
	public function candidates( $broken ) {
		$path  = (string) wp_parse_url( $broken, PHP_URL_PATH );
		$segs  = array_values( array_filter( explode( '/', trim( $path, '/' ) ) ) );
		$slug  = $segs ? end( $segs ) : '';
		$slug  = preg_replace( '/\.(html?|php|aspx?)$/', '', urldecode( $slug ) );
		$words = trim( preg_replace( '/[-_]+/', ' ', $slug ) );
		if ( '' === $words ) {
			return array( array( 'url' => home_url( '/' ), 'title' => get_bloginfo( 'name' ), 'score' => 20 ) );
		}
		$types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );
		$ids   = get_posts( array( 'post_type' => $types, 'post_status' => 'publish', 's' => $words, 'posts_per_page' => 10, 'fields' => 'ids', 'suppress_filters' => true ) );
		$like  = get_posts( array( 'post_type' => $types, 'post_status' => 'publish', 'name' => sanitize_title( $slug ), 'posts_per_page' => 1, 'fields' => 'ids' ) );
		$out   = array();
		foreach ( array_unique( array_merge( $like, $ids ) ) as $id ) {
			$post_slug = urldecode( get_post_field( 'post_name', $id ) );
			similar_text( Text::normalize( $slug ), Text::normalize( $post_slug ), $pct );
			$title_sim = Text::similarity( $words, get_the_title( $id ) ) * 100;
			$out[]     = array( 'url' => get_permalink( $id ), 'title' => get_the_title( $id ), 'score' => (int) round( max( $pct, $title_sim ) ) );
		}
		// Parent path fallback (e.g. category of a deleted product).
		if ( count( $segs ) > 1 ) {
			$parent = '/' . implode( '/', array_slice( $segs, 0, -1 ) ) . '/';
			$pid    = url_to_postid( home_url( $parent ) );
			$term   = get_term_by( 'slug', sanitize_title( urldecode( $segs[ count( $segs ) - 2 ] ) ), 'product_cat' );
			if ( $pid ) {
				$out[] = array( 'url' => get_permalink( $pid ), 'title' => get_the_title( $pid ), 'score' => 40 );
			} elseif ( $term && ! is_wp_error( $term ) ) {
				$out[] = array( 'url' => get_term_link( $term ), 'title' => $term->name, 'score' => 45 );
			}
		}
		usort( $out, static function ( $a, $b ) {
			return $b['score'] <=> $a['score'];
		} );
		return $out;
	}
}
