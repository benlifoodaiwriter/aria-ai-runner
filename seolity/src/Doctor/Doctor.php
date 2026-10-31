<?php
/**
 * SEO Doctor (section 8): turns raw findings into diagnoses —
 * what is wrong, why it matters, where, priority, likely impact, how to fix,
 * and whether SEOlity can fix it automatically.
 *
 * @package SEOlity
 */

namespace SEOlity\Doctor;

use SEOlity\Crawler\Checks;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class Doctor {

	const SEVERITY_WEIGHT = array( 'critical' => 100, 'high' => 60, 'medium' => 30, 'low' => 10, 'info' => 0 );

	/** check_code => admin route + bulk task that fixes it. */
	const FIX_ROUTES = array(
		'title_missing'        => array( '#/content?filter=title', 'titles' ),
		'title_too_long'       => array( '#/content?filter=title', 'titles' ),
		'title_too_short'      => array( '#/content?filter=title', 'titles' ),
		'duplicate_title'      => array( '#/content?filter=title', 'titles' ),
		'meta_desc_missing'    => array( '#/content?filter=meta', 'meta' ),
		'meta_desc_length'     => array( '#/content?filter=meta', 'meta' ),
		'duplicate_meta'       => array( '#/content?filter=meta', 'meta' ),
		'img_alt_missing'      => array( '#/images', 'alt' ),
		'thin_content'         => array( '#/content?filter=thin', 'posts' ),
		'orphan_page'          => array( '#/links', 'internal_links' ),
		'http_4xx'             => array( '#/404', null ),
		'broken_internal_link' => array( '#/audit?check=broken_internal_link', null ),
		'redirect_chain'       => array( '#/redirects', null ),
		'cannibalization'      => array( '#/keywords?tab=cannibalization', null ),
		'noindex_with_traffic' => array( '#/audit?check=noindex_with_traffic', null ),
		'sitemap_error_urls'   => array( '#/sitemap', null ),
		'robots_no_sitemap'    => array( '#/robots', null ),
		'product_schema_missing' => array( '#/schema', null ),
		'schema_missing'       => array( '#/schema', null ),
		'cwv_poor'             => array( '#/audit?category=performance', null ),
	);

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/** @return array{diagnoses:array, counts:array, generated_at:string} */
	public function diagnose() {
		$db   = $this->plugin->db;
		$pid  = $db->project_id();
		$rows = $db->rows(
			"SELECT i.check_code, i.severity, COUNT(*) c, GROUP_CONCAT(u.url ORDER BY u.inlinks DESC SEPARATOR '\n') urls
			FROM {issues} i LEFT JOIN {urls} u ON u.id = i.url_id WHERE i.project_id = %d AND i.status = 'open' AND i.severity <> 'info'
			GROUP BY i.check_code, i.severity",
			array( $pid )
		);
		$gsc = $this->plugin->gsc_oauth->is_connected();
		$out = array();
		foreach ( $rows as $r ) {
			$m = Checks::meta( $r['check_code'] );
			if ( ! $m ) {
				continue;
			}
			$urls   = array_values( array_filter( array_slice( explode( "\n", (string) $r['urls'] ), 0, 8 ) ) );
			$count  = (int) $r['c'];
			$traffic = 0;
			if ( $gsc ) {
				foreach ( array_slice( $urls, 0, 5 ) as $u ) {
					$traffic += $this->plugin->gsc_analytics->page_metrics( $u, 28 )['clicks'];
				}
			}
			$out[] = $this->diagnosis(
				$r['check_code'],
				$m['title'],
				$m['why'],
				$m['fix'],
				$r['severity'],
				$count,
				$urls,
				in_array( $m['fixable'], array( 'ai', 'auto' ), true ),
				$traffic
			);
		}

		// Diagnoses that come from other modules.
		$img = (int) $db->value( "SELECT COUNT(*) FROM {images} WHERE project_id = %d AND issues LIKE %s", array( $pid, '%missing_alt%' ) );
		if ( $img ) {
			$prod = (int) $db->value( "SELECT COUNT(DISTINCT i.parent_id) FROM {images} i JOIN {$db->wpdb->posts} p ON p.ID = i.parent_id WHERE i.project_id = %d AND p.post_type = 'product' AND i.issues LIKE %s", array( $pid, '%missing_alt%' ) );
			/* translators: 1 images 2 products */
			$out[] = $this->diagnosis( 'media_alt_missing', sprintf( __( '%1$d media library images have no ALT text (%2$d products affected)', 'seolity' ), $img, $prod ), __( 'Images without ALT text are invisible to screen readers and Google Images.', 'seolity' ), __( 'Use Bulk Optimize ALT — SEOlity writes context-aware ALT text from product/page data.', 'seolity' ), $prod ? 'high' : 'medium', $img, array(), true, 0, array( '#/images', 'alt' ) );
		}
		$referred = (int) $db->value( "SELECT COUNT(*) FROM {not_found} WHERE project_id = %d AND status = 'open' AND referrer LIKE %s", array( $pid, '%' . $db->wpdb->esc_like( wp_parse_url( home_url(), PHP_URL_HOST ) ) . '%' ) );
		if ( $referred ) {
			/* translators: %d */
			$out[] = $this->diagnosis( 'internal_404', sprintf( __( '%d 404 URLs are linked from your own pages', 'seolity' ), $referred ), __( 'Visitors and crawlers keep hitting dead ends from inside your site.', 'seolity' ), __( 'Redirect them to the suggested targets (SEOlity → 404) and fix the source links.', 'seolity' ), 'high', $referred, array(), true, 0, array( '#/404', null ) );
		}
		$cann = $this->plugin->keywords->cannibalization( 28, 50 );
		if ( $cann ) {
			/* translators: %d */
			$out[] = $this->diagnosis( 'cannibalization', sprintf( __( '%d queries have several of your pages competing for them', 'seolity' ), count( $cann ) ), __( 'Competing URLs split clicks and links, so none of them ranks as well as one strong page would.', 'seolity' ), __( 'Merge, redirect, differentiate or re-target (AI advice per query in Keywords → Cannibalization).', 'seolity' ), 'medium', count( $cann ), array_slice( array_map( static function ( $c ) {
				return $c['query'];
			}, $cann ), 0, 8 ), true, 0, array( '#/keywords?tab=cannibalization', null ) );
		}
		if ( $gsc ) {
			$ctr = $this->plugin->gsc_analytics->ctr_opportunities( 28, 100 );
			if ( $ctr ) {
				$gain = array_sum( wp_list_pluck( $ctr, 'estimated_gain' ) );
				/* translators: 1 pages 2 clicks */
				$out[] = $this->diagnosis( 'low_ctr', sprintf( __( '%1$d pages have CTR far below expected for their position (≈%2$d extra clicks/month possible)', 'seolity' ), count( $ctr ), $gain ), __( 'Google already shows these pages, but searchers do not click — titles/descriptions don\'t match the intent or don\'t stand out.', 'seolity' ), __( 'Rewrite title + meta description with SEOlity (based on the real queries).', 'seolity' ), $gain > 500 ? 'high' : 'medium', count( $ctr ), wp_list_pluck( array_slice( $ctr, 0, 8 ), 'page' ), true, $gain, array( '#/gsc?tab=opportunities', 'meta' ) );
			}
		}
		if ( class_exists( 'WooCommerce' ) ) {
			$no_short = (int) $db->value( "SELECT COUNT(*) FROM {$db->wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' AND post_excerpt = ''" );
			if ( $no_short ) {
				/* translators: %d */
				$out[] = $this->diagnosis( 'product_no_short_desc', sprintf( __( '%d products have no short description', 'seolity' ), $no_short ), __( 'The short description is the most visible sales copy on product pages and is often used as snippet text.', 'seolity' ), __( 'Generate benefit-focused short descriptions with AI Product Optimizer.', 'seolity' ), 'medium', $no_short, array(), true, 0, array( '#/products?filter=no_short', 'products' ) );
			}
		}

		usort( $out, static function ( $a, $b ) {
			return $b['score'] <=> $a['score'];
		} );
		$counts = array( 'critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0 );
		foreach ( $out as $d ) {
			if ( isset( $counts[ $d['priority'] ] ) ) {
				$counts[ $d['priority'] ]++;
			}
		}
		return array( 'diagnoses' => $out, 'counts' => $counts, 'generated_at' => gmdate( 'c' ) );
	}

	private function diagnosis( $code, $title, $why, $fix, $severity, $count, array $urls, $auto, $traffic, $route = null ) {
		$route  = $route ? $route : ( isset( self::FIX_ROUTES[ $code ] ) ? self::FIX_ROUTES[ $code ] : array( '#/audit?check=' . $code, null ) );
		$weight = isset( self::SEVERITY_WEIGHT[ $severity ] ) ? self::SEVERITY_WEIGHT[ $severity ] : 10;
		$score  = $weight * ( 1 + log( 1 + $count ) ) + min( 200, $traffic / 5 );
		$impact = $traffic > 0
			/* translators: %d clicks */
			? sprintf( __( 'Affected pages earn ~%d clicks / 28 days — fixing protects and grows that traffic.', 'seolity' ), $traffic )
			: ( 'critical' === $severity ? __( 'Blocks indexing or user access — fix first.', 'seolity' ) : ( 'high' === $severity ? __( 'Likely ranking/CTR impact.', 'seolity' ) : __( 'Incremental quality improvement.', 'seolity' ) ) );
		return array(
			'code'          => $code,
			'title'         => is_numeric( $count ) && false === strpos( $title, (string) $count ) ? sprintf( '%s (%d)', $title, $count ) : $title,
			'why'           => $why,
			'fix'           => $fix,
			'priority'      => $severity,
			'count'         => (int) $count,
			'sample_urls'   => $urls,
			'impact'        => $impact,
			'auto_fixable'  => (bool) $auto,
			'route'         => $route[0],
			'bulk_task'     => $route[1],
			'score'         => round( $score, 1 ),
		);
	}
}
