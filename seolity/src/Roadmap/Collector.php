<?php
/**
 * Roadmap facts: one snapshot of everything SEOlity knows about the site, gathered
 * only when the owner presses "Analyze" (never in the background).
 *
 * Produces (1) compact facts for the AI analysis and (2) candidate roadmap items
 * with real targets (URLs, edit links, recommendation ids) built from the site
 * doctor, the recommendation engine, Search Console and the content inventory.
 *
 * @package SEOlity
 */

namespace SEOlity\Roadmap;

use SEOlity\Content\Objects;
use SEOlity\Core\Database;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class Collector {

	/** Doctor checks that a recommendation type already covers (merged into one item). */
	const COVERED = array(
		'low_ctr'              => 'ctr_opportunity',
		'cannibalization'      => 'cannibalization',
		'orphan_page'          => 'orphan_page',
		'noindex_with_traffic' => 'remove_noindex',
		'internal_404'         => 'create_redirect',
		'http_4xx'             => 'create_redirect',
		'redirect_chain'       => 'fix_redirect_chains',
		'thin_content'         => array( 'thin_expand', 'thin_noindex' ),
		'meta_desc_missing'    => 'bulk_meta',
		'title_missing'        => 'bulk_meta',
	);

	/** Category of each item key (for filters and icons). */
	const CATEGORY = array(
		'index_new'            => 'indexing',
		'remove_noindex'       => 'indexing',
		'http_4xx'             => 'technical',
		'http_5xx'             => 'technical',
		'create_redirect'      => 'technical',
		'fix_redirect_chains'  => 'technical',
		'redirect_loops'       => 'technical',
		'redirect_chain'       => 'technical',
		'redirect_loop'        => 'technical',
		'sitemap_error_urls'   => 'technical',
		'robots_no_sitemap'    => 'technical',
		'cwv_poor'             => 'technical',
		'ctr_opportunity'      => 'ctr',
		'bulk_meta'            => 'on-page',
		'striking_distance'    => 'content',
		'declining_page'       => 'content',
		'thin_content'         => 'content',
		'thin_expand'          => 'content',
		'thin_noindex'         => 'content',
		'content_gap'          => 'competitors',
		'faq_opportunity'      => 'content',
		'cannibalization'      => 'content',
		'internal_link'        => 'links',
		'orphan_page'          => 'links',
		'broken_internal_link' => 'links',
		'optimize_product'     => 'products',
		'product_no_short_desc' => 'products',
		'strengthen_category'  => 'products',
		'media_alt_missing'    => 'images',
		'img_alt_missing'      => 'images',
		'schema_missing'       => 'schema',
		'product_schema_missing' => 'schema',
	);

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * @param callable|null $stage Progress callback ( string $stage ).
	 * @return array { facts, candidates }
	 */
	public function collect( $stage = null ) {
		$p    = $this->plugin;
		$note = static function ( $s ) use ( $stage ) {
			if ( $stage ) {
				call_user_func( $stage, $s );
			}
		};
		$note( 'recommendations' );
		$p->recommendations->refresh();
		$note( 'diagnose' );
		$doctor = $p->doctor->diagnose();
		$gsc    = $p->gsc_oauth->is_connected() && $p->gsc_analytics->has_data();
		$note( 'facts' );
		$facts      = $this->facts( $gsc, $doctor );
		$candidates = array_merge( $this->index_items( $gsc ), $this->rec_items(), $this->doctor_items( $doctor ) );
		return array( 'facts' => $facts, 'candidates' => $candidates );
	}

	/* ------------------------------------------------------------------ facts for the AI */

	private function facts( $gsc, array $doctor ) {
		$p   = $this->plugin;
		$db  = $p->db;
		$pid = $db->project_id();
		$wp  = $db->wpdb;

		$count = static function ( $type ) use ( $wp ) {
			return (int) $wp->get_var( $wp->prepare( "SELECT COUNT(*) FROM {$wp->posts} WHERE post_type = %s AND post_status = 'publish'", $type ) ); // phpcs:ignore WordPress.DB
		};
		$recent = static function ( $days ) use ( $wp ) {
			return (int) $wp->get_var( $wp->prepare( "SELECT COUNT(*) FROM {$wp->posts} WHERE post_type IN ('post','page','product') AND post_status = 'publish' AND post_date_gmt >= %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB
		};
		$health = $p->health->cached();
		$out    = array(
			'site'    => array(
				'name'       => get_bloginfo( 'name' ),
				'url'        => home_url( '/' ),
				'language'   => get_locale(),
				'woocommerce' => class_exists( 'WooCommerce' ),
				'seo_plugin' => $p->meta->engine(),
				'posts'      => $count( 'post' ),
				'pages'      => $count( 'page' ),
				'products'   => class_exists( 'WooCommerce' ) ? $count( 'product' ) : 0,
				'published_last_30_days' => $recent( 30 ),
				'published_last_90_days' => $recent( 90 ),
				'host_region' => $p->gateway->region(),
			),
			'health'  => array(
				'score'      => isset( $health['score'] ) ? $health['score'] : null,
				'grade'      => isset( $health['grade'] ) ? $health['grade'] : null,
				'categories' => isset( $health['categories'] ) ? array_map( static function ( $c ) {
					return isset( $c['score'] ) ? $c['score'] : null;
				}, (array) $health['categories'] ) : array(),
			),
			'crawl'   => array(
				'crawled'   => (int) $db->value( "SELECT COUNT(*) FROM {urls} WHERE project_id = %d AND crawl_status = 'done'", array( $pid ) ),
				'indexable' => (int) $db->value( "SELECT COUNT(*) FROM {urls} WHERE project_id = %d AND crawl_status = 'done' AND status_code = 200 AND indexable = 1", array( $pid ) ),
				'last_audit' => $db->value( "SELECT finished_at FROM {audits} WHERE project_id = %d AND status = 'done' ORDER BY id DESC LIMIT 1", array( $pid ) ),
			),
			'issues'  => array_map( static function ( $d ) {
				return array( 'code' => $d['code'], 'title' => $d['title'], 'priority' => $d['priority'], 'count' => $d['count'] );
			}, array_slice( isset( $doctor['diagnoses'] ) ? $doctor['diagnoses'] : array(), 0, 25 ) ),
			'search_console' => array( 'connected' => $p->gsc_oauth->is_connected(), 'has_data' => $gsc ),
		);
		if ( $gsc ) {
			$a  = $p->gsc_analytics;
			$ov = $a->overview( 28 );
			$out['search_console']['last_28_days'] = $ov['current'];
			$out['search_console']['previous_28_days'] = $ov['previous'];
			$out['search_console']['top_queries'] = array_map( array( __CLASS__, 'slim' ), $a->top_queries( 28, 12 ) );
			$out['search_console']['top_pages']   = array_map( array( __CLASS__, 'slim' ), $a->top_pages( 28, 10 ) );
			$movers = $a->movers( 'page', 28, 6 );
			$out['search_console']['losing_pages']  = array_map( array( __CLASS__, 'slim' ), $movers['losers'] );
			$out['search_console']['winning_pages'] = array_map( array( __CLASS__, 'slim' ), $movers['winners'] );
			$out['search_console']['ranking_distribution'] = $a->ranking_distribution( 28 );
		}
		$out['competitors'] = array_map( static function ( $c ) {
			return array( 'domain' => $c['domain'], 'pages' => isset( $c['summary']['pages'] ) ? $c['summary']['pages'] : null, 'new_pages' => isset( $c['summary']['new_pages'] ) ? $c['summary']['new_pages'] : null );
		}, array_slice( $p->competitors->query(), 0, 5 ) );
		$out['work'] = array(
			'ai_changes_applied_30d' => (int) $db->value( "SELECT COUNT(*) FROM {changes} WHERE project_id = %d AND status = 'applied' AND applied_at >= %s", array( $pid, gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) ),
			'ai_changes_pending'     => (int) $db->value( "SELECT COUNT(*) FROM {changes} WHERE project_id = %d AND status = 'pending'", array( $pid ) ),
			'learnings'              => array_slice( (array) $p->learning->prompt_insights(), 0, 6 ),
		);
		return $out;
	}

	public static function slim( $r ) {
		$keep = array( 'page', 'query', 'clicks', 'impressions', 'ctr', 'position', 'delta_clicks' );
		return array_intersect_key( (array) $r, array_flip( $keep ) );
	}

	/* ------------------------------------------------------------------ candidate items */

	private function target( $url, $object_type = null, $object_id = 0, array $extra = array() ) {
		if ( ! $object_type && $url ) {
			list( $object_type, $object_id ) = $this->plugin->recommendations->resolve( $url );
		}
		$label = $object_type && $object_id ? Objects::label( $object_type, (int) $object_id ) : '';
		$edit  = '';
		if ( 'post' === $object_type && $object_id ) {
			// Built directly: the analysis runs in the background without a logged-in user.
			$edit = admin_url( 'post.php?post=' . (int) $object_id . '&action=edit' );
		} elseif ( 'term' === $object_type && $object_id ) {
			$term = get_term( (int) $object_id );
			$edit = $term && ! is_wp_error( $term ) ? admin_url( 'term.php?taxonomy=' . rawurlencode( $term->taxonomy ) . '&tag_ID=' . (int) $term->term_id ) : '';
		}
		return array_merge(
			array(
				'label'       => $label ? $label : ( $url ? urldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) ) : '' ),
				'url'         => $url ? (string) $url : '',
				'edit'        => $edit,
				'object_type' => $object_type,
				'object_id'   => (int) $object_id,
			),
			$extra
		);
	}

	/** New content Google has not shown yet (no impressions) → index it. */
	private function index_items( $gsc ) {
		$wp    = $this->plugin->db->wpdb;
		$types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );
		$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$rows  = $wp->get_results( $wp->prepare( "SELECT ID, post_type, post_date_gmt FROM {$wp->posts} WHERE post_status = 'publish' AND post_type IN ($in) AND post_date_gmt >= %s AND post_date_gmt <= %s ORDER BY post_date_gmt DESC LIMIT 60", array_merge( $types, array( gmdate( 'Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS ), gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$targets  = array();
		$property = (string) $this->plugin->settings->get( 'gsc_property' );
		foreach ( (array) $rows as $r ) {
			$url  = (string) get_permalink( (int) $r['ID'] );
			$impr = $gsc ? (int) $this->plugin->gsc_analytics->page_metrics( $url, 28 )['impressions'] : null;
			if ( $gsc && $impr > 0 ) {
				continue; // Google already shows it.
			}
			$u    = $this->plugin->db->row( 'SELECT indexable, inlinks, in_sitemap, status_code FROM {urls} WHERE project_id = %d AND url_hash = %s', array( $this->plugin->db->project_id(), Database::url_hash( $url ) ) );
			$days = (int) floor( ( time() - strtotime( $r['post_date_gmt'] . ' UTC' ) ) / DAY_IN_SECONDS );
			$problems = array();
			if ( $u && ! (int) $u['indexable'] ) {
				$problems[] = __( 'set to noindex or canonicalized', 'seolity' );
			}
			if ( $u && (int) $u['inlinks'] < 1 ) {
				$problems[] = __( 'no internal links point to it', 'seolity' );
			}
			if ( $u && null !== $u['in_sitemap'] && ! (int) $u['in_sitemap'] ) {
				$problems[] = __( 'missing from the sitemap', 'seolity' );
			}
			$targets[] = $this->target(
				$url,
				'post',
				(int) $r['ID'],
				array(
					/* translators: %d: days since publishing */
					'metric'   => sprintf( _n( 'published %d day ago', 'published %d days ago', $days, 'seolity' ), $days ) . ( $gsc ? ' · ' . __( '0 impressions', 'seolity' ) : '' ),
					'problems' => $problems,
					'inspect'  => $property ? 'https://search.google.com/search-console/inspect?resource_id=' . rawurlencode( $property ) . '&id=' . rawurlencode( $url ) : 'https://search.google.com/search-console',
				)
			);
		}
		if ( ! $targets ) {
			return array();
		}
		$blocked = count( array_filter( $targets, static function ( $t ) {
			return ! empty( $t['problems'] );
		} ) );
		return array(
			array(
				'key'      => 'index_new',
				'type'     => 'index_new',
				/* translators: %d: number of posts */
				'title'    => sprintf( _n( 'Get %d new page indexed by Google', 'Get %d new pages indexed by Google', count( $targets ), 'seolity' ), count( $targets ) ),
				'why'      => $gsc ? __( 'These pages were published recently but have not received a single impression in Search Console yet — Google has probably not indexed them. New content only starts earning traffic after it is indexed.', 'seolity' ) : __( 'These pages were published recently. Connect Search Console so SEOlity can tell which ones Google has not indexed yet; until then, request indexing for each new page.', 'seolity' ),
				'priority' => $blocked ? 'critical' : 'high',
				'impact'   => 70,
				'effort'   => 10,
				'targets'  => $targets,
				'evidence' => array( 'count' => count( $targets ), 'with_problems' => $blocked ),
			),
		);
	}

	/** Recommendation engine output, grouped per type. */
	private function rec_items() {
		$recs   = $this->plugin->recommendations->query( array( 'limit' => 200 ) );
		$groups = array();
		foreach ( $recs as $r ) {
			$groups[ $r['type'] ][] = $r;
		}
		$order = array( 'critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1 );
		$items = array();
		foreach ( $groups as $type => $list ) {
			$best = 'low';
			$imp  = 0;
			$eff  = 0;
			foreach ( $list as $r ) {
				if ( $order[ $r['priority'] ] > $order[ $best ] ) {
					$best = $r['priority'];
				}
				$imp = max( $imp, (int) $r['impact'] );
				$eff += (int) $r['effort'];
			}
			$targets = array_map(
				function ( $r ) {
					return $this->target( $r['url'], $r['object_type'], (int) $r['object_id'], array( 'rec_id' => (int) $r['id'], 'metric' => $r['reason'], 'title' => $r['title'], 'auto' => (bool) $r['auto_fixable'] ) );
				},
				array_slice( $list, 0, 40 )
			);
			$items[] = array(
				'key'      => 'rec:' . $type,
				'type'     => $type,
				'title'    => 1 === count( $list ) ? $list[0]['title'] : Guides::group_title( $type, count( $list ) ),
				'why'      => 1 === count( $list ) ? $list[0]['reason'] : Guides::group_why( $type, $list[0]['reason'] ),
				'priority' => $best,
				'impact'   => $imp,
				'effort'   => min( 100, (int) round( $eff / max( 1, count( $list ) ) ) + ( count( $list ) > 5 ? 10 : 0 ) ),
				'targets'  => $targets,
				'evidence' => array( 'count' => count( $list ) ),
			);
		}
		return $items;
	}

	/** Site doctor issue groups not already covered by a recommendation type. */
	private function doctor_items( array $doctor ) {
		$items = array();
		$recs  = array_flip( array_map( static function ( $t ) {
			return $t;
		}, (array) $this->plugin->db->column( "SELECT DISTINCT type FROM {recommendations} WHERE project_id = %d AND status = 'open'", array( $this->plugin->db->project_id() ) ) ) );
		foreach ( isset( $doctor['diagnoses'] ) ? $doctor['diagnoses'] : array() as $d ) {
			if ( isset( self::COVERED[ $d['code'] ] ) && array_intersect_key( $recs, array_flip( (array) self::COVERED[ $d['code'] ] ) ) ) {
				continue;
			}
			$targets = array();
			foreach ( array_slice( (array) $d['sample_urls'], 0, 25 ) as $u ) {
				$targets[] = 0 === strpos( (string) $u, 'http' ) ? $this->target( $u ) : array( 'label' => (string) $u, 'url' => '', 'edit' => '' );
			}
			$items[] = array(
				'key'      => 'doctor:' . $d['code'],
				'type'     => $d['code'],
				'title'    => $d['title'],
				'why'      => trim( $d['why'] . ' ' . $d['fix'] ),
				'priority' => in_array( $d['priority'], array( 'critical', 'high', 'medium', 'low' ), true ) ? $d['priority'] : 'medium',
				'impact'   => min( 100, (int) round( $d['score'] / 3 ) ),
				'effort'   => ! empty( $d['auto_fixable'] ) ? 15 : 40,
				'targets'  => $targets,
				'route'    => (string) $d['route'],
				'bulk'     => $d['bulk_task'],
				'evidence' => array( 'count' => (int) $d['count'] ),
			);
		}
		return $items;
	}
}
