<?php
/**
 * AI triage of logged 404s: for every URL the evidence that decides its value (Search Console
 * history, internal links, external referrers, human vs. Googlebot requests, deleted content,
 * equivalent candidates) goes to the AI, which decides 301 / 410 / keep 404 / fix the link and
 * says whether the site has a crawl-budget problem at all. The result is an operation: nothing
 * changes until the owner approves, and every 301/410 is verified on the live site.
 *
 * @package SEOlity
 */

namespace SEOlity\Redirects;

use SEOlity\Activity\Activity;
use SEOlity\Core\Database;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class NotFoundTriage {

	const MAX_URLS = 100;
	const BATCH    = 25;
	const JUNK_RE  = '#(wp-login|xmlrpc|\.env|\.git|wp-config|phpmyadmin|/cgi-bin|\.(?:php|asp|aspx|jsp|bak|sql|zip|tar|gz)$|/wp-content/(?:plugins|themes)/|/(?:admin|administrator|login|user)/?$)#i';

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function start( $instructions = '' ) {
		if ( ! $this->plugin->ai->is_ready() ) {
			return new \WP_Error( 'seolity_ai', __( 'Connect an AI engine first (Settings → AI).', 'seolity' ), array( 'status' => 400 ) );
		}
		$open = (int) $this->plugin->db->value( "SELECT COUNT(*) FROM {not_found} WHERE project_id = %d AND status = 'open'", array( $this->plugin->db->project_id() ) );
		if ( ! $open ) {
			return new \WP_Error( 'seolity_404', __( 'There are no open 404 URLs to review.', 'seolity' ), array( 'status' => 400 ) );
		}
		$title = __( 'AI review of 404 errors: 301, 410 or leave as 404', 'seolity' );
		$id    = $this->plugin->operations->create( '404_triage', array( 'title' => $title, 'instructions' => sanitize_textarea_field( (string) $instructions ), 'input' => array( 'open' => $open ) ) );
		$aid   = $this->plugin->activity->start( 'agent', __( 'Reviewing 404 errors', 'seolity' ), array( 'detail' => $title, 'dock' => true, 'status' => 'queued', 'step' => __( 'Queued — starting in a moment…', 'seolity' ), 'meta' => array( 'type' => 'operation', 'operation_id' => (int) $id ) ) );
		$this->plugin->operations->update( $id, array( 'activity_id' => $aid ) );
		$job = $this->plugin->queue->push( 'not_found_triage', array( 'id' => (int) $id, 'user_id' => get_current_user_id() ), array( 'unique' => 'nft_' . (int) $id, 'priority' => 0, 'max_attempts' => 1, 'timeout' => 1800 ) );
		$this->plugin->activity->attach_job( $aid, $job );
		\SEOlity\Activity\AiJobs::run_after_response( $this->plugin );
		return $this->plugin->operations->get( $id );
	}

	/** Site-wide numbers the crawl-budget verdict is based on. */
	private function site_context() {
		$db    = $this->plugin->db;
		$pid   = $db->project_id();
		$types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment', 'elementor_library' ) ) );
		$pub   = 0;
		foreach ( $types as $t ) {
			$pub += (int) wp_count_posts( $t )->publish;
		}
		$since = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		return array(
			'site'                    => home_url( '/' ),
			'language'                => get_locale(),
			'published_urls'          => $pub,
			'known_urls_in_crawl'     => (int) $db->value( 'SELECT COUNT(*) FROM {urls} WHERE project_id = %d', array( $pid ) ),
			'open_404_urls'           => (int) $db->value( "SELECT COUNT(*) FROM {not_found} WHERE project_id = %d AND status = 'open'", array( $pid ) ),
			'open_404_hits_total'     => (int) $db->value( "SELECT COALESCE(SUM(hits),0) FROM {not_found} WHERE project_id = %d AND status = 'open'", array( $pid ) ),
			'open_404_seen_30_days'   => (int) $db->value( "SELECT COUNT(*) FROM {not_found} WHERE project_id = %d AND status = 'open' AND last_seen >= %s", array( $pid, $since ) ),
			'open_404_last_googlebot' => (int) $db->value( "SELECT COUNT(*) FROM {not_found} WHERE project_id = %d AND status = 'open' AND user_agent LIKE %s", array( $pid, '%Googlebot%' ) ),
			'search_console'          => $this->plugin->gsc_oauth->is_connected(),
		);
	}

	/** Everything that tells whether one 404 has value. */
	private function evidence( array $r ) {
		global $wpdb;
		$db   = $this->plugin->db;
		$pid  = $db->project_id();
		$path = (string) $r['url'];
		$full = home_url( $path );
		// Search Console history of this exact URL (with and without the trailing slash).
		$hashes = array_values( array_unique( array( Database::url_hash( $full ), Database::url_hash( trailingslashit( $full ) ), Database::url_hash( untrailingslashit( $full ) ) ) ) );
		$in     = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
		$gsc    = $db->row( "SELECT COALESCE(SUM(clicks),0) clicks, COALESCE(SUM(impressions),0) impressions, MIN(position) best_position, MAX(date) last_date FROM {gsc_page_query} WHERE project_id = %d AND page_hash IN ($in)", array_merge( array( $pid ), $hashes ) );
		// Internal links that still point here.
		$enc   = implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) );
		$likes = array_unique( array( '%' . $wpdb->esc_like( untrailingslashit( $path ) ) . '%', '%' . $wpdb->esc_like( strtolower( untrailingslashit( $enc ) ) ) . '%' ) );
		$where = implode( ' OR ', array_fill( 0, count( $likes ), 'post_content LIKE %s' ) );
		$links = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type NOT IN ('revision','nav_menu_item','attachment') AND ($where) LIMIT 5", $likes ), ARRAY_A ); // phpcs:ignore WordPress.DB
		// Deleted content at this address.
		$segs    = array_values( array_filter( explode( '/', trim( $path, '/' ) ) ) );
		$slug    = $segs ? sanitize_title( end( $segs ) ) : '';
		$trashed = $slug ? $wpdb->get_var( $wpdb->prepare( "SELECT post_title FROM {$wpdb->posts} WHERE post_name IN (%s,%s) AND post_status = 'trash' LIMIT 1", $slug, $slug . '__trashed' ) ) : null; // phpcs:ignore WordPress.DB
		$ref     = (string) $r['referrer'];
		$ref_host = $ref ? (string) wp_parse_url( $ref, PHP_URL_HOST ) : '';
		$own      = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$cands    = array();
		foreach ( array_slice( $this->plugin->not_found->candidates( $path ), 0, 5 ) as $c ) {
			if ( ! is_string( $c['url'] ) ) {
				continue;
			}
			$cands[] = array( 'url' => $c['url'], 'title' => $c['title'], 'similarity' => (int) $c['score'] );
		}
		return array(
			'id'                     => (int) $r['id'],
			'url'                    => $path,
			'hits'                   => (int) $r['hits'],
			'first_seen'             => $r['first_seen'],
			'last_seen'              => $r['last_seen'],
			'last_request_by'        => false !== stripos( (string) $r['user_agent'], 'googlebot' ) ? 'Googlebot' : ( (int) $r['is_bot'] ? 'other bot' : 'human browser' ),
			'referrer'               => $ref ? ( $ref_host && $ref_host !== $own ? 'external: ' . $ref_host : 'internal: ' . urldecode( (string) wp_parse_url( $ref, PHP_URL_PATH ) ) ) : 'none',
			'search_console'         => $gsc && ( (int) $gsc['impressions'] || (int) $gsc['clicks'] ) ? array( 'clicks' => (int) $gsc['clicks'], 'impressions' => (int) $gsc['impressions'], 'best_position' => round( (float) $gsc['best_position'], 1 ), 'last_seen_in_search' => $gsc['last_date'] ) : 'no data',
			'internal_links_from'    => $links ? array_map(
				static function ( $l ) {
					return $l['post_title'];
				},
				$links
			) : array(),
			'deleted_post_with_slug' => $trashed ? $trashed : null,
			'looks_like_probe_or_junk' => (bool) preg_match( self::JUNK_RE, $path ),
			'candidates'             => $cands,
		);
	}

	/** Queue handler. */
	public function prepare( $op_id ) {
		$ops = $this->plugin->operations;
		$op  = $ops->get( $op_id );
		if ( ! $op || 'preparing' !== $op['status'] ) {
			return true;
		}
		$act  = $this->plugin->activity;
		$aid  = (int) $op['activity_id'];
		$prev = Activity::set_current( $aid );
		$db   = $this->plugin->db;
		$rows = $db->rows( "SELECT * FROM {not_found} WHERE project_id = %d AND status = 'open' ORDER BY (referrer <> '' AND referrer IS NOT NULL) DESC, hits DESC, last_seen DESC LIMIT %d", array( $db->project_id(), self::MAX_URLS ) );
		$site = $this->site_context();
		/* translators: %d: URLs */
		$act->step( $aid, sprintf( __( 'Collecting evidence for %d URLs (Search Console, links, referrers, candidates)…', 'seolity' ), count( $rows ) ), 10 );
		$ev = array();
		foreach ( $rows as $r ) {
			$ev[ (int) $r['id'] ] = $this->evidence( $r );
		}
		$decisions = array();
		$verdict   = array( 'matters' => null, 'reason' => '', 'summary' => '' );
		$prev_i    = \SEOlity\AI\Engine::with_instructions( (string) $op['instructions'] );
		$batches   = array_chunk( array_values( $ev ), self::BATCH );
		foreach ( $batches as $bi => $batch ) {
			$this->plugin->queue->checkpoint( 15 + (int) ( 60 * $bi / max( 1, count( $batches ) ) ) );
			/* translators: 1 batch 2 batches */
			$act->step( $aid, sprintf( __( 'AI is judging the 404s (part %1$d of %2$d)…', 'seolity' ), $bi + 1, count( $batches ) ), 15 + (int) ( 60 * $bi / max( 1, count( $batches ) ) ) );
			$res = $this->plugin->ai->run( 'not_found_triage', array( 'site' => $site, 'urls' => $batch ) );
			if ( is_wp_error( $res ) ) {
				\SEOlity\AI\Engine::with_instructions( $prev_i );
				$ops->update( $op_id, array( 'status' => 'failed', 'error' => $res->get_error_message() ) );
				$act->finish( $aid, 'failed', $res->get_error_message() );
				Activity::set_current( $prev );
				return true;
			}
			$d = $res['data'];
			if ( 0 === $bi ) {
				$verdict = array( 'matters' => (bool) $d['crawl_budget_matters'], 'reason' => (string) $d['crawl_budget_reason'], 'summary' => (string) $d['summary'] );
			}
			foreach ( (array) $d['decisions'] as $x ) {
				if ( isset( $ev[ (int) $x['id'] ] ) ) {
					$decisions[ (int) $x['id'] ] = $x;
				}
			}
		}
		\SEOlity\AI\Engine::with_instructions( $prev_i );
		$act->step( $aid, __( 'Checking every proposed target…', 'seolity' ), 80 );

		$items = array();
		$count = array( 'redirect_301' => 0, 'gone_410' => 0, 'keep_404' => 0, 'fix_internal_link' => 0 );
		foreach ( $decisions as $id => $x ) {
			$e      = $ev[ $id ];
			$dec    = $x['decision'];
			$reason = (string) $x['reason'];
			$conf   = max( 0, min( 100, (int) $x['confidence'] ) );
			if ( 'redirect_301' === $dec ) {
				// Only a target the evidence offered: the AI may not invent destinations.
				$allowed = wp_list_pluck( $e['candidates'], 'url' );
				if ( ! in_array( (string) $x['target_url'], $allowed, true ) ) {
					$dec     = 'keep_404';
					$reason .= ' ' . __( '(The proposed target was not one of the checked candidates, so the URL is left as 404.)', 'seolity' );
				}
			}
			$count[ $dec ]++;
			$base = array( 'key' => 'nf' . $id, 'not_found_id' => $id, 'reason' => $reason, 'confidence' => $conf, 'decision' => $dec, 'evidence' => array( 'hits' => $e['hits'], 'search_console' => $e['search_console'], 'internal_links' => count( $e['internal_links_from'] ), 'referrer' => $e['referrer'], 'last_request_by' => $e['last_request_by'] ) );
			if ( 'redirect_301' === $dec ) {
				$items[] = $base + array( 'kind' => 'redirect', 'source' => $e['url'], 'target' => (string) $x['target_url'], 'type' => 301, 'origin' => '404_ai' );
			} elseif ( 'gone_410' === $dec ) {
				$items[] = $base + array( 'kind' => 'gone', 'source' => $e['url'] );
			} elseif ( 'fix_internal_link' === $dec ) {
				$items[] = $base + array( 'kind' => 'note', 'source' => $e['url'], 'posts' => $e['internal_links_from'] );
			} else {
				$items[] = $base + array( 'kind' => 'keep_404', 'source' => $e['url'] );
			}
		}
		// Most valuable first: 301s, then 410s, then the rest.
		$order = array( 'redirect' => 0, 'gone' => 1, 'note' => 2, 'keep_404' => 3 );
		usort(
			$items,
			static function ( $a, $b ) use ( $order ) {
				return array( $order[ $a['kind'] ], -$a['confidence'] ) <=> array( $order[ $b['kind'] ], -$b['confidence'] );
			}
		);
		foreach ( $items as &$it ) {
			if ( 'gone' === $it['kind'] ) {
				$it['check'] = $ops->check_gone( $it['source'] );
			}
		}
		unset( $it );
		$plan = array(
			( $verdict['matters'] ? '⚠ ' . __( 'Crawl budget: these 404s do matter for this site.', 'seolity' ) : '✓ ' . __( 'Crawl budget: these 404s are not a crawl-budget problem for this site.', 'seolity' ) ) . ' ' . $verdict['reason'],
			$verdict['summary'],
			/* translators: 1..4 counts */
			sprintf( __( 'Decisions: %1$d × 301 to an equivalent page, %2$d × 410 gone, %3$d × leave as 404, %4$d × fix the internal link.', 'seolity' ), $count['redirect_301'], $count['gone_410'], $count['keep_404'], $count['fix_internal_link'] ),
			__( 'Nothing changes until you approve. Untick anything you disagree with; every 301 and 410 is checked on the live site afterwards.', 'seolity' ),
		);
		$ops->staged( $op_id, $items, array_values( array_filter( $plan ) ) );
		$act->finish( $aid, 'done', __( 'Ready for your review', 'seolity' ), array( 'summary' => __( 'Ready for your review', 'seolity' ), 'result' => array( 'operation_id' => (int) $op_id ) ) );
		Activity::set_current( $prev );
		return true;
	}
}
