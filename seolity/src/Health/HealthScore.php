<?php
/**
 * Explainable SEO Health Score (section 27).
 *
 * Ten weighted categories, each scored 0–100 from measurable factors with a
 * plain-language explanation of every point lost. Categories without data
 * (e.g. GSC not connected) are excluded and reported as such — never guessed.
 *
 * @package SEOlity
 */

namespace SEOlity\Health;

use SEOlity\Core\Database;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class HealthScore {

	const WEIGHTS = array(
		'technical'   => 18,
		'content'     => 15,
		'indexation'  => 12,
		'gsc'         => 14,
		'links'       => 10,
		'images'      => 5,
		'schema'      => 5,
		'performance' => 10,
		'keywords'    => 6,
		'competitors' => 5,
	);

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	private function issues_count( array $codes = array(), array $severity = array(), $category = '' ) {
		$db    = $this->plugin->db;
		$where = array( 'project_id = %d', "status = 'open'" );
		$args  = array( $db->project_id() );
		if ( $codes ) {
			$where[] = 'check_code IN (' . implode( ',', array_fill( 0, count( $codes ), '%s' ) ) . ')';
			$args    = array_merge( $args, $codes );
		}
		if ( $severity ) {
			$where[] = 'severity IN (' . implode( ',', array_fill( 0, count( $severity ), '%s' ) ) . ')';
			$args    = array_merge( $args, $severity );
		}
		if ( $category ) {
			$where[] = 'category = %s';
			$args[]  = $category;
		}
		return (int) $db->value( 'SELECT COUNT(DISTINCT url_id) FROM {issues} WHERE ' . implode( ' AND ', $where ), $args );
	}

	private static function ratio_score( $bad, $total, $harshness = 1.0 ) {
		if ( $total <= 0 ) {
			return 100;
		}
		return (int) max( 0, round( 100 - min( 100, ( $bad / $total ) * 100 * $harshness ) ) );
	}

	public function compute() {
		$db    = $this->plugin->db;
		$pid   = $db->project_id();
		$pages = (int) $db->value( "SELECT COUNT(*) FROM {urls} WHERE project_id = %d AND crawl_status = 'done'", array( $pid ) );
		$cats  = array();

		if ( $pages ) {
			// Technical.
			$crit = $this->issues_count( array(), array( 'critical' ) );
			$high = $this->issues_count( array(), array( 'high' ), 'security' ) + $this->issues_count( array(), array( 'high' ), 'mobile' ) + $this->issues_count( array( 'http_5xx', 'redirect_loop', 'canonical_multiple', 'schema_invalid' ) );
			$score = max( 0, min( self::ratio_score( $crit, $pages, 3 ), self::ratio_score( $crit + $high, $pages, 1.5 ) ) );
			$cats['technical'] = $this->cat(
				$score,
				array(
					array( __( 'Pages with critical issues', 'seolity' ), $crit, -min( 60, $crit * 5 ) ),
					array( __( 'Pages with high security/mobile/server issues', 'seolity' ), $high, -min( 40, $high * 2 ) ),
				),
				__( 'Status codes, redirects, HTTPS, mixed content, mobile viewport, canonical conflicts.', 'seolity' )
			);
			// Content.
			$avg   = $db->value( 'SELECT AVG(score) FROM {content_analysis} WHERE project_id = %d', array( $pid ) );
			$thin  = $this->issues_count( array( 'thin_content' ) );
			$dupe  = $this->issues_count( array( 'duplicate_content', 'near_duplicate' ) );
			$meta  = $this->issues_count( array( 'title_missing', 'meta_desc_missing', 'duplicate_title', 'duplicate_meta' ) );
			$base  = null !== $avg ? (float) $avg : 70;
			$score = (int) round( 0.5 * $base + 0.5 * self::ratio_score( $thin + $dupe + $meta / 2, $pages, 1.2 ) );
			$cats['content'] = $this->cat(
				$score,
				array(
					array( __( 'Average on-page score', 'seolity' ), null !== $avg ? round( $avg ) : '—', 0 ),
					array( __( 'Thin pages', 'seolity' ), $thin, -$thin ),
					array( __( 'Duplicate / near-duplicate pages', 'seolity' ), $dupe, -$dupe ),
					array( __( 'Pages with missing/duplicate title or description', 'seolity' ), $meta, -$meta ),
				),
				__( 'On-page quality, depth, uniqueness and metadata.', 'seolity' )
			);
			// Indexation.
			$wp_pages = (int) $db->value( "SELECT COUNT(*) FROM {urls} WHERE project_id = %d AND object_id IS NOT NULL AND crawl_status = 'done'", array( $pid ) );
			$indexable = (int) $db->value( "SELECT COUNT(*) FROM {urls} WHERE project_id = %d AND object_id IS NOT NULL AND crawl_status = 'done' AND status_code = 200 AND indexable = 1", array( $pid ) );
			$conflicts = $this->issues_count( array( 'noindex_with_traffic', 'noindex_in_sitemap', 'robots_blocked', 'canonical_to_error' ) );
			$sitemap   = $this->issues_count( array( 'sitemap_missing', 'sitemap_error_urls' ) );
			$score     = max( 0, ( $wp_pages ? (int) round( 100 * $indexable / $wp_pages ) : 100 ) - min( 40, $conflicts * 5 ) - ( $sitemap ? 15 : 0 ) );
			$cats['indexation'] = $this->cat(
				$score,
				array(
					array( __( 'Published pages that are indexable', 'seolity' ), $wp_pages ? round( 100 * $indexable / $wp_pages ) . '%' : '—', 0 ),
					array( __( 'Indexing conflicts (noindex w/ traffic, robots-blocked, bad canonicals)', 'seolity' ), $conflicts, -min( 40, $conflicts * 5 ) ),
					array( __( 'Sitemap problems', 'seolity' ), $sitemap ? __( 'yes', 'seolity' ) : __( 'no', 'seolity' ), $sitemap ? -15 : 0 ),
				),
				__( 'Can Google crawl and index the pages you want indexed?', 'seolity' )
			);
			// Internal links.
			$orphans = $this->issues_count( array( 'orphan_page' ) );
			$broken  = $this->issues_count( array( 'broken_internal_link' ) );
			$deep    = $this->issues_count( array( 'deep_page' ) );
			$score   = (int) round( ( self::ratio_score( $orphans, $pages, 2 ) + self::ratio_score( $broken, $pages, 2 ) + self::ratio_score( $deep, $pages ) ) / 3 );
			$cats['links'] = $this->cat(
				$score,
				array(
					array( __( 'Orphan pages', 'seolity' ), $orphans, -$orphans ),
					array( __( 'Pages with broken internal links', 'seolity' ), $broken, -$broken ),
					array( __( 'Pages deeper than 4 clicks', 'seolity' ), $deep, -$deep ),
				),
				__( 'Link graph health: discoverability, depth and broken links.', 'seolity' )
			);
			// Schema.
			$no_schema = $this->issues_count( array( 'schema_missing', 'product_schema_missing' ) );
			$invalid   = $this->issues_count( array( 'schema_invalid' ) );
			$score     = max( 0, self::ratio_score( $no_schema, $pages ) - min( 40, $invalid * 10 ) );
			$cats['schema'] = $this->cat( $score, array( array( __( 'Pages without (required) structured data', 'seolity' ), $no_schema, -$no_schema ), array( __( 'Pages with invalid JSON-LD', 'seolity' ), $invalid, -$invalid * 10 ) ), __( 'Structured data coverage and validity.', 'seolity' ) );
			// Performance.
			$avg_ms = (int) $db->value( "SELECT AVG(response_ms) FROM {urls} WHERE project_id = %d AND crawl_status = 'done' AND status_code = 200", array( $pid ) );
			$cwv    = $this->issues_count( array( 'cwv_poor' ) );
			$slow   = $this->issues_count( array( 'slow_response' ) );
			$score  = max( 0, 100 - max( 0, (int) ( ( $avg_ms - 400 ) / 20 ) ) - min( 40, $cwv * 8 ) - min( 20, $slow ) );
			$cats['performance'] = $this->cat( $score, array( array( __( 'Average server response (ms)', 'seolity' ), $avg_ms, -max( 0, (int) ( ( $avg_ms - 400 ) / 20 ) ) ), array( __( 'Pages with poor Core Web Vitals', 'seolity' ), $cwv, -min( 40, $cwv * 8 ) ), array( __( 'Slow pages (>1.5s TTFB)', 'seolity' ), $slow, -min( 20, $slow ) ) ), __( 'Server speed and Core Web Vitals (PageSpeed Insights).', 'seolity' ) );
		}

		// Images.
		$img_total = (int) $db->value( 'SELECT COUNT(*) FROM {images} WHERE project_id = %d', array( $pid ) );
		if ( $img_total ) {
			$missing = (int) $db->value( 'SELECT COUNT(*) FROM {images} WHERE project_id = %d AND issues LIKE %s', array( $pid, '%missing_alt%' ) );
			$bad     = (int) $db->value( "SELECT COUNT(*) FROM {images} WHERE project_id = %d AND ( issues LIKE %s OR issues LIKE %s OR issues LIKE %s )", array( $pid, '%generic_alt%', '%stuffed_alt%', '%duplicate_alt%' ) );
			$score   = self::ratio_score( $missing + $bad / 2, $img_total );
			$cats['images'] = $this->cat( $score, array( array( __( 'Images without ALT', 'seolity' ), $missing, -$missing ), array( __( 'Generic / stuffed / duplicate ALT', 'seolity' ), $bad, -$bad / 2 ) ), __( 'Accessibility and image-search readiness of the media library.', 'seolity' ) );
		}

		// GSC.
		if ( $this->plugin->gsc_oauth->is_connected() && $this->plugin->gsc_analytics->has_data() ) {
			$o      = $this->plugin->gsc_analytics->overview( 28 );
			$trend  = null === $o['delta']['clicks'] ? 0 : $o['delta']['clicks'];
			$ctr_op = count( $this->plugin->gsc_analytics->ctr_opportunities( 28, 200 ) );
			$score  = (int) max( 0, min( 100, 70 + $trend * 0.6 + min( 15, $o['delta']['position'] * 3 ) - min( 25, $ctr_op ) ) );
			$cats['gsc'] = $this->cat( $score, array( array( __( 'Clicks vs previous 28 days', 'seolity' ), self::pct( $trend ), (int) round( $trend * 0.6 ) ), array( __( 'Average position change', 'seolity' ), $o['delta']['position'], (int) round( min( 15, $o['delta']['position'] * 3 ) ) ), array( __( 'Pages with CTR below expectation', 'seolity' ), $ctr_op, -min( 25, $ctr_op ) ) ), __( 'Real Google performance trend (Search Console).', 'seolity' ) );
		}

		// Keywords.
		$tracked = (int) $db->value( 'SELECT COUNT(*) FROM {keywords} WHERE project_id = %d', array( $pid ) );
		if ( $tracked && $this->plugin->gsc_analytics->has_data() ) {
			$dist  = $this->plugin->gsc_analytics->ranking_distribution( 28 );
			$total = max( 1, array_sum( $dist ) );
			$top10 = ( $dist['1-3'] + $dist['4-10'] ) / $total;
			$cann  = count( $this->plugin->keywords->cannibalization( 28, 100 ) );
			$score = (int) max( 0, min( 100, 40 + $top10 * 120 - min( 30, $cann * 2 ) ) );
			$cats['keywords'] = $this->cat( $score, array( array( __( 'Queries in the top 10', 'seolity' ), round( $top10 * 100 ) . '%', 0 ), array( __( 'Cannibalized queries', 'seolity' ), $cann, -min( 30, $cann * 2 ) ) ), __( 'Ranking distribution and keyword conflicts.', 'seolity' ) );
		}

		// Competitors.
		$comps = (int) $db->value( "SELECT COUNT(*) FROM {competitors} WHERE project_id = %d AND status = 'active'", array( $pid ) );
		if ( $comps ) {
			$gaps  = 0;
			foreach ( $db->column( "SELECT summary FROM {competitors} WHERE project_id = %d AND status = 'active'", array( $pid ) ) as $s ) {
				$d     = json_decode( (string) $s, true );
				$gaps += isset( $d['gap']['opportunities'] ) ? count( $d['gap']['opportunities'] ) : 0;
			}
			$score = max( 20, 100 - $gaps * 3 );
			$cats['competitors'] = $this->cat( $score, array( array( __( 'Competitors monitored', 'seolity' ), $comps, 0 ), array( __( 'Open content gaps', 'seolity' ), $gaps, -$gaps * 3 ) ), __( 'How much ground competitors cover that you don\'t.', 'seolity' ) );
		}

		$sum = 0;
		$w   = 0;
		$missing = array();
		foreach ( self::WEIGHTS as $k => $weight ) {
			if ( isset( $cats[ $k ] ) ) {
				$sum += $cats[ $k ]['score'] * $weight;
				$w   += $weight;
				$cats[ $k ]['weight'] = $weight;
			} else {
				$missing[] = $k;
			}
		}
		$overall = $w ? (int) round( $sum / $w ) : null;
		return array(
			'score'      => $overall,
			'grade'      => null === $overall ? null : ( $overall >= 85 ? 'A' : ( $overall >= 70 ? 'B' : ( $overall >= 55 ? 'C' : ( $overall >= 40 ? 'D' : 'F' ) ) ) ),
			'categories' => $cats,
			'excluded'   => $missing,
			'note'       => $missing ? __( 'Some categories have no data yet and are excluded rather than guessed (connect Search Console, run an audit, add competitors).', 'seolity' ) : '',
			'history'    => $this->history(),
			'computed_at' => gmdate( 'c' ),
		);
	}

	private function cat( $score, array $factors, $about ) {
		return array(
			'score'   => (int) max( 0, min( 100, $score ) ),
			'about'   => $about,
			'factors' => array_map(
				static function ( $f ) {
					return array( 'label' => $f[0], 'value' => $f[1], 'impact' => (int) round( $f[2] ) );
				},
				$factors
			),
		);
	}

	private static function pct( $v ) {
		return ( $v > 0 ? '+' : '' ) . $v . '%';
	}

	/** Daily snapshot stored as a report row (keeps wp_options clean). */
	public function snapshot() {
		$h = $this->compute();
		$this->plugin->db->query( "DELETE FROM {reports} WHERE project_id = %d AND type = 'health' AND period_start = %s", array( $this->plugin->db->project_id(), gmdate( 'Y-m-d' ) ) );
		$this->plugin->db->insert(
			'reports',
			array(
				'project_id'   => $this->plugin->db->project_id(),
				'type'         => 'health',
				'title'        => 'Health ' . gmdate( 'Y-m-d' ),
				'period_start' => gmdate( 'Y-m-d' ),
				'period_end'   => gmdate( 'Y-m-d' ),
				'data'         => array( 'score' => $h['score'], 'categories' => array_map( static function ( $c ) {
					return $c['score'];
				}, $h['categories'] ) ),
				'created_at'   => Database::now(),
			)
		);
		set_transient( 'seolity_health', $h, 6 * HOUR_IN_SECONDS );
		return $h['score'];
	}

	public function cached() {
		$h = get_transient( 'seolity_health' );
		if ( false === $h ) {
			$h = $this->compute();
			set_transient( 'seolity_health', $h, HOUR_IN_SECONDS );
		}
		return $h;
	}

	public function history( $days = 90 ) {
		$rows = $this->plugin->db->rows( "SELECT period_start date, data FROM {reports} WHERE project_id = %d AND type = 'health' AND period_start >= %s ORDER BY period_start", array( $this->plugin->db->project_id(), gmdate( 'Y-m-d', strtotime( "-$days days" ) ) ) );
		return array_map(
			static function ( $r ) {
				$d = json_decode( (string) $r['data'], true );
				return array( 'date' => $r['date'], 'score' => isset( $d['score'] ) ? $d['score'] : null );
			},
			$rows
		);
	}
}
