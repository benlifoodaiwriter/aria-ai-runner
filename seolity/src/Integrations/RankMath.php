<?php
/**
 * Rank Math bridge. SEOlity works *inside* Rank Math instead of competing
 * with it: it writes Rank Math's own fields (title, description, the full
 * focus-keyword list, pillar flag, social title/description, robots,
 * canonical), reads its SEO score, imports its redirections, and feeds its
 * editor store so the Rank Math sidebar shows SEOlity's changes live.
 *
 * What Rank Math's Content AI cannot do — and SEOlity adds — is choosing
 * keywords from the queries Google *already* ranks each page for (Search
 * Console), cannibalization-safe across the whole site, with undo.
 *
 * @package SEOlity
 */

namespace SEOlity\Integrations;

use SEOlity\Content\MetaStore;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class RankMath {

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function active() {
		return defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' );
	}

	public function version() {
		return defined( 'RANK_MATH_VERSION' ) ? RANK_MATH_VERSION : '';
	}

	public function is_pro() {
		return defined( 'RANK_MATH_PRO_VERSION' );
	}

	/** Active Rank Math modules (slugs such as redirections, rich-snippet, analytics, content-ai). */
	public function modules() {
		return array_values( array_filter( (array) get_option( 'rank_math_modules', array() ), 'is_string' ) );
	}

	/** Rank Math data for one object (null when Rank Math is not active). */
	public function post_info( $type, $id ) {
		if ( ! $this->active() ) {
			return null;
		}
		$get = 'term' === $type ? 'get_term_meta' : 'get_post_meta';
		$sc  = $get( (int) $id, 'rank_math_seo_score', true );
		return array(
			'score'    => '' === $sc ? null : (int) $sc,
			'pillar'   => 'on' === $get( (int) $id, 'rank_math_pillar_content', true ),
			'keywords' => MetaStore::keyword_list( $get( (int) $id, 'rank_math_focus_keyword', true ) ),
			'robots'   => array_values( array_filter( (array) $get( (int) $id, 'rank_math_robots', true ) ) ),
		);
	}

	/** Rank Math's Redirections module is on: it owns redirects, SEOlity writes into it. */
	public function owns_redirects() {
		return $this->active() && in_array( 'redirections', $this->modules(), true ) && class_exists( '\\RankMath\\Redirections\\Redirection' ) && null !== $this->redirections_table();
	}

	/** Rank Math redirect id whose sources contain this pattern (exact or regex), 0 when none. */
	public function find_redirect( $pattern, $comparison = 'exact' ) {
		global $wpdb;
		$table = $this->redirections_table();
		if ( ! $table ) {
			return 0;
		}
		$needle = 'exact' === $comparison ? ltrim( (string) $pattern, '/' ) : (string) $pattern;
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT id, sources FROM {$table} WHERE sources LIKE %s LIMIT 50", '%' . $wpdb->esc_like( $needle ) . '%' ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $r ) {
			foreach ( (array) maybe_unserialize( $r['sources'] ) as $src ) {
				if ( isset( $src['pattern'], $src['comparison'] ) && $src['comparison'] === $comparison && untrailingslashit( $src['pattern'] ) === untrailingslashit( $needle ) ) {
					return (int) $r['id'];
				}
			}
		}
		return 0;
	}

	/**
	 * Create or update a redirect through Rank Math's own model (so it appears and can be
	 * managed under Rank Math → Redirections, with Rank Math's sanitizing and cache).
	 *
	 * @return int|\WP_Error Rank Math redirect id.
	 */
	public function save_redirect( $source, $target, $type = 301, $is_regex = false ) {
		if ( ! $this->owns_redirects() ) {
			return new \WP_Error( 'seolity_rm_redirect', __( 'Rank Math Redirections is not active.', 'seolity' ) );
		}
		$comparison = $is_regex ? 'regex' : 'exact';
		$data       = array(
			'sources'     => array( array( 'pattern' => (string) $source, 'comparison' => $comparison ) ),
			'url_to'      => (string) $target,
			'header_code' => (int) $type,
			'status'      => 'active',
		);
		$existing = $this->find_redirect( $source, $comparison );
		if ( $existing ) {
			$data['id'] = $existing;
		}
		$obj = \RankMath\Redirections\Redirection::from( $data );
		$id  = $obj->save();
		if ( ! $id ) {
			return new \WP_Error( 'seolity_rm_redirect', __( 'Rank Math did not accept this redirect.', 'seolity' ) );
		}
		return (int) $id;
	}

	public function delete_redirect( $id ) {
		if ( $this->owns_redirects() && class_exists( '\\RankMath\\Redirections\\DB' ) ) {
			return (bool) \RankMath\Redirections\DB::delete( array( (int) $id ) );
		}
		return false;
	}

	private function redirections_table() {
		global $wpdb;
		$table = $wpdb->prefix . 'rank_math_redirections';
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ? $table : null; // phpcs:ignore WordPress.DB
	}

	/** Overview for the "Rank Math & focus keywords" page. */
	public function status() {
		global $wpdb;
		$out = array(
			'active'   => $this->active(),
			'version'  => $this->version(),
			'pro'      => $this->is_pro(),
			'modules'  => $this->active() ? $this->modules() : array(),
			'engine'   => $this->plugin->meta->engine(),
			'coverage' => $this->plugin->focus->coverage_stats(),
		);
		if ( ! $out['active'] ) {
			return $out;
		}
		$types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );
		$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$score = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) n, AVG(CAST(m.meta_value AS UNSIGNED)) avg, SUM(CAST(m.meta_value AS UNSIGNED) < 50) low, SUM(CAST(m.meta_value AS UNSIGNED) >= 80) good FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = 'rank_math_seo_score' AND m.meta_value <> '' AND p.post_status = 'publish' AND p.post_type IN ($in)", $types ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$out['scores'] = array(
			'scored' => (int) $score['n'],
			'avg'    => null === $score['avg'] ? null : round( (float) $score['avg'] ),
			'low'    => (int) $score['low'],
			'good'   => (int) $score['good'],
		);
		$out['pillars'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'rank_math_pillar_content' AND meta_value = 'on'" ); // phpcs:ignore WordPress.DB
		$table          = $this->redirections_table();
		$out['redirections'] = $table ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'active'" ) : null; // phpcs:ignore WordPress.DB
		$out['low_pages']    = $this->low_score_pages( 12 );
		return $out;
	}

	/** Low Rank Math scores, ordered by how much search demand the page has. */
	public function low_score_pages( $limit = 12 ) {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT p.ID, p.post_title, CAST(m.meta_value AS UNSIGNED) score FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = 'rank_math_seo_score' AND m.meta_value <> '' AND CAST(m.meta_value AS UNSIGNED) < 70 AND p.post_status = 'publish' ORDER BY score ASC LIMIT 60", ARRAY_A ); // phpcs:ignore WordPress.DB
		$gsc  = $this->plugin->gsc_analytics->has_data();
		$out  = array();
		foreach ( $rows as $r ) {
			$url   = get_permalink( (int) $r['ID'] );
			$m     = $gsc && $url ? $this->plugin->gsc_analytics->page_metrics( $url, 28 ) : null;
			$out[] = array(
				'object_id'   => (int) $r['ID'],
				'title'       => $r['post_title'],
				'url'         => $url,
				'score'       => (int) $r['score'],
				'keywords'    => MetaStore::keyword_list( get_post_meta( (int) $r['ID'], 'rank_math_focus_keyword', true ) ),
				'impressions' => $m ? $m['impressions'] : null,
				'clicks'      => $m ? $m['clicks'] : null,
				'position'    => $m ? $m['position'] : null,
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return array( (int) $b['impressions'], -$b['score'] ) <=> array( (int) $a['impressions'], -$a['score'] );
			}
		);
		return array_slice( $out, 0, $limit );
	}

	/**
	 * Import Rank Math redirections into SEOlity (keeps Rank Math's untouched).
	 *
	 * @return array|\WP_Error
	 */
	public function import_redirections() {
		$table = $this->redirections_table();
		if ( ! $table ) {
			return new \WP_Error( 'seolity_rm_redirects', __( 'No Rank Math redirections table was found.', 'seolity' ), array( 'status' => 404 ) );
		}
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT id, sources, url_to, header_code FROM {$table} WHERE status = 'active' ORDER BY id ASC LIMIT 5000", ARRAY_A ); // phpcs:ignore WordPress.DB
		$res  = array( 'imported' => 0, 'skipped' => 0, 'errors' => array() );
		foreach ( $rows as $r ) {
			$sources = maybe_unserialize( $r['sources'] );
			$code    = (int) $r['header_code'];
			foreach ( (array) $sources as $s ) {
				if ( empty( $s['pattern'] ) ) {
					continue;
				}
				$pattern = (string) $s['pattern'];
				$cmp     = isset( $s['comparison'] ) ? $s['comparison'] : 'exact';
				$regex   = 'exact' !== $cmp;
				$quoted  = preg_quote( ltrim( $pattern, '/' ), '#' );
				switch ( $cmp ) {
					case 'regex':
						$source = $pattern;
						break;
					case 'contains':
						$source = '.*' . $quoted . '.*';
						break;
					case 'start':
						$source = '^/?' . $quoted;
						break;
					case 'end':
						$source = $quoted . '$';
						break;
					default:
						$source = '/' . ltrim( $pattern, '/' );
				}
				$id = $this->plugin->redirects->create( $source, (string) $r['url_to'], $code ? $code : 301, $regex, 'import', 'Rank Math #' . (int) $r['id'] );
				if ( is_wp_error( $id ) ) {
					++$res['skipped'];
					if ( count( $res['errors'] ) < 10 ) {
						$res['errors'][] = $pattern . ': ' . $id->get_error_message();
					}
				} else {
					++$res['imported'];
				}
			}
		}
		$this->plugin->audit_log->log( 'rankmath.redirects_imported', 'site', 0, $res );
		return $res;
	}

	/**
	 * Mark topic-cluster pillars as Rank Math pillar content (through the
	 * change log, so it is reversible).
	 */
	public function sync_pillars() {
		$n = 0;
		foreach ( $this->plugin->planner->clusters() as $c ) {
			$id = isset( $c['pillar_object_id'] ) ? (int) $c['pillar_object_id'] : 0;
			if ( ! $id || '1' === $this->plugin->meta->get_post( $id, 'pillar_content' ) ) {
				continue;
			}
			$cid = $this->plugin->changes->propose(
				array(
					'object_type'        => 'post',
					'object_id'          => $id,
					'field'              => 'pillar_content',
					'after'              => '1',
					'source'             => 'rule',
					'reason'             => sprintf( __( 'Pillar page of the “%s” topic cluster.', 'seolity' ), isset( $c['name'] ) ? $c['name'] : '' ),
					'auto_apply_allowed' => true,
				)
			);
			if ( ! is_wp_error( $cid ) ) {
				++$n;
			}
		}
		return array( 'proposed' => $n );
	}
}
