<?php
/**
 * Smart SEO Recommendations (section 9) + AI Action Center data.
 *
 * Generators turn GSC data, audit issues, competitor gaps, internal-link
 * suggestions and product data into concrete actions ("do this now"), each with
 * Priority, Impact, Effort, Confidence, Reason and the Evidence behind it (the
 * "Why?" button). Recommendations are deduplicated and refreshed daily.
 *
 * @package SEOlity
 */

namespace SEOlity\Recommendations;

use SEOlity\Content\Objects;
use SEOlity\Core\Database;
use SEOlity\Crawler\PageParser;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class Engine {

	/** @var Plugin */
	private $plugin;

	/** @var string */
	private $run_started = '';

	/** @var int */
	private $generated = 0;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function refresh() {
		$this->run_started = Database::now();
		$this->generated   = 0;
		$gsc               = $this->plugin->gsc_oauth->is_connected() && $this->plugin->gsc_analytics->has_data();
		if ( $gsc ) {
			$this->gen_ctr();
			$this->gen_striking_distance();
			$this->gen_declining();
			$this->gen_cannibalization();
			$this->gen_faq();
		}
		$this->gen_from_issues( $gsc );
		$this->gen_internal_links();
		$this->gen_products();
		$this->gen_categories( $gsc );
		$this->gen_competitor_gaps();
		$this->gen_redirect_chains();
		do_action( 'seolity_generate_recommendations', $this );

		// Anything not re-generated in this run is stale.
		$this->plugin->db->query(
			"UPDATE {recommendations} SET status = 'stale' WHERE project_id = %d AND status = 'open' AND updated_at < %s",
			array( $this->plugin->db->project_id(), $this->run_started )
		);
		update_option( 'seolity_recommendations_refreshed', time(), false );
		return $this->generated;
	}

	/**
	 * Upsert a recommendation (public so extensions can add their own generators).
	 *
	 * @param array $r type, title, reason, evidence, action, impact, effort, confidence, url, object_type, object_id, auto_fixable, key.
	 */
	public function add( array $r ) {
		$impact     = max( 1, min( 100, (int) $r['impact'] ) );
		$effort     = max( 1, min( 100, (int) $r['effort'] ) );
		$confidence = max( 1, min( 100, (int) $r['confidence'] ) );
		$score      = round( $impact * $confidence / 100 * ( 110 - $effort ) / 100, 2 );
		$priority   = $score >= 55 ? 'critical' : ( $score >= 35 ? 'high' : ( $score >= 18 ? 'medium' : 'low' ) );
		if ( isset( $r['priority'] ) ) {
			$priority = $r['priority'];
		}
		$key = sha1( $r['type'] . '|' . ( isset( $r['key'] ) ? $r['key'] : ( isset( $r['url'] ) ? $r['url'] : $r['title'] ) ) );
		$db  = $this->plugin->db;
		$existing = $db->row( 'SELECT id, status FROM {recommendations} WHERE project_id = %d AND dedupe_key = %s', array( $db->project_id(), $key ) );
		$row = array(
			'type'         => $r['type'],
			'object_type'  => isset( $r['object_type'] ) ? $r['object_type'] : null,
			'object_id'    => isset( $r['object_id'] ) ? (int) $r['object_id'] : null,
			'url'          => isset( $r['url'] ) ? $r['url'] : null,
			'title'        => wp_strip_all_tags( $r['title'] ),
			'reason'       => isset( $r['reason'] ) ? wp_strip_all_tags( $r['reason'] ) : '',
			'evidence'     => isset( $r['evidence'] ) ? $r['evidence'] : array(),
			'action'       => isset( $r['action'] ) ? $r['action'] : array(),
			'priority'     => $priority,
			'impact'       => $impact,
			'effort'       => $effort,
			'confidence'   => $confidence,
			'score'        => $score,
			'auto_fixable' => ! empty( $r['auto_fixable'] ),
			'updated_at'   => Database::now(),
		);
		if ( $existing ) {
			if ( in_array( $existing['status'], array( 'dismissed', 'done' ), true ) ) {
				return (int) $existing['id']; // Respect user decisions.
			}
			$row['status'] = 'open';
			$db->update( 'recommendations', $row, array( 'id' => (int) $existing['id'] ) );
			$this->generated++;
			return (int) $existing['id'];
		}
		$row['project_id'] = $db->project_id();
		$row['dedupe_key'] = $key;
		$row['status']     = 'open';
		$row['created_at'] = Database::now();
		$this->generated++;
		return $db->insert( 'recommendations', $row );
	}

	/** Map a URL to a WordPress object. */
	public function resolve( $url ) {
		$db  = $this->plugin->db;
		$row = $db->row( 'SELECT object_type, object_id FROM {urls} WHERE project_id = %d AND url_hash = %s AND object_id IS NOT NULL', array( $db->project_id(), Database::hash( PageParser::normalize_url( $url ) ) ) );
		if ( $row ) {
			return array( $row['object_type'], (int) $row['object_id'] );
		}
		$id = url_to_postid( $url );
		return $id ? array( 'post', $id ) : array( null, 0 );
	}

	private function label( $url, $type, $id ) {
		$label = $type ? Objects::label( $type, $id ) : '';
		return $label ? $label : urldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) );
	}

	/* ------------------------------ Generators ------------------------------ */

	private function gen_ctr() {
		foreach ( $this->plugin->gsc_analytics->ctr_opportunities( 28, 40 ) as $p ) {
			list( $type, $id ) = $this->resolve( $p['page'] );
			$this->add(
				array(
					'type'        => 'ctr_opportunity',
					'url'         => $p['page'],
					'object_type' => $type,
					'object_id'   => $id,
					/* translators: %s page */
					'title'       => sprintf( __( 'Rewrite the title & meta description of "%s"', 'seolity' ), $this->label( $p['page'], $type, $id ) ),
					/* translators: 1 impressions 2 ctr 3 expected 4 position 5 gain */
					'reason'      => sprintf( __( 'This page has %1$s impressions but a %2$s%% CTR (expected ≈%3$s%% at position %4$s). Better snippet copy could bring ≈%5$d more clicks per month.', 'seolity' ), number_format_i18n( $p['impressions'] ), $p['ctr'], $p['expected_ctr'], $p['position'], $p['estimated_gain'] ),
					'evidence'    => array( 'source' => 'gsc', 'window_days' => 28, 'metrics' => $p, 'top_queries' => array_slice( $this->plugin->gsc_analytics->page_queries( $p['page'], 28, 5 ), 0, 5 ) ),
					'action'      => array( 'type' => 'optimize_meta', 'object_type' => $type, 'object_id' => $id ),
					'impact'      => min( 95, 30 + $p['estimated_gain'] / 5 ),
					'effort'      => 10,
					'confidence'  => $p['impressions'] > 2000 ? 80 : 65,
					'auto_fixable' => (bool) $type,
				)
			);
		}
	}

	private function gen_striking_distance() {
		foreach ( $this->plugin->gsc_analytics->position_opportunities( 28, 25 ) as $p ) {
			list( $type, $id ) = $this->resolve( $p['page'] );
			$this->add(
				array(
					'type'        => 'striking_distance',
					'url'         => $p['page'],
					'object_type' => $type,
					'object_id'   => $id,
					/* translators: %s page */
					'title'       => sprintf( __( 'Deepen the content of "%s" to reach page 1', 'seolity' ), $this->label( $p['page'], $type, $id ) ),
					/* translators: 1 queries 2 position */
					'reason'      => sprintf( __( 'This URL ranks for %1$d queries at positions 8–20 (avg. %2$s). Improving depth, intent match and internal links usually moves such pages onto page 1.', 'seolity' ), $p['queries'], $p['position'] ),
					'evidence'    => array( 'source' => 'gsc', 'metrics' => $p, 'queries' => array_values( array_filter( $this->plugin->gsc_analytics->page_queries( $p['page'], 28, 30 ), static function ( $q ) {
						return $q['position'] >= 8 && $q['position'] <= 20;
					} ) ) ),
					'action'      => array( 'type' => 'optimize_content', 'object_type' => $type, 'object_id' => $id ),
					'impact'      => min( 90, 30 + $p['estimated_gain'] / 8 ),
					'effort'      => 45,
					'confidence'  => 60,
					'auto_fixable' => false,
				)
			);
		}
	}

	private function gen_declining() {
		foreach ( $this->plugin->gsc_analytics->trending_pages( 28, 15 )['declining'] as $p ) {
			list( $type, $id ) = $this->resolve( $p['page'] );
			$changed = $type ? $this->plugin->db->value( "SELECT MAX(applied_at) FROM {changes} WHERE object_type = %s AND object_id = %d AND status = 'applied'", array( $type, $id ) ) : null;
			$this->add(
				array(
					'type'        => 'declining_page',
					'url'         => $p['page'],
					'object_type' => $type,
					'object_id'   => $id,
					/* translators: %s page */
					'title'       => sprintf( __( 'Investigate the traffic drop on "%s"', 'seolity' ), $this->label( $p['page'], $type, $id ) ),
					/* translators: 1 change 2 prev 3 now */
					'reason'      => sprintf( __( 'Clicks fell %1$s%% (%2$d → %3$d) versus the previous 28 days.', 'seolity' ), $p['change'], $p['prev_clicks'], $p['clicks'] ) . ( $changed ? ' ' . __( 'The page was changed recently — check the experiment results.', 'seolity' ) : ' ' . __( 'Refresh outdated information, compare against current top results and check for cannibalization.', 'seolity' ) ),
					'evidence'    => array( 'source' => 'gsc', 'metrics' => $p, 'last_change' => $changed ),
					'action'      => array( 'type' => 'optimize_content', 'object_type' => $type, 'object_id' => $id ),
					'impact'      => min( 90, 25 + abs( $p['delta_clicks'] ) / 3 ),
					'effort'      => 40,
					'confidence'  => 55,
				)
			);
		}
	}

	private function gen_cannibalization() {
		foreach ( $this->plugin->keywords->cannibalization( 28, 15 ) as $c ) {
			$this->add(
				array(
					'type'       => 'cannibalization',
					'key'        => $c['query'],
					/* translators: %s query */
					'title'      => sprintf( __( 'Resolve cannibalization for "%s"', 'seolity' ), $c['query'] ),
					/* translators: %d pages */
					'reason'     => sprintf( __( '%d of your URLs share the impressions for this query, so none of them ranks as well as it could.', 'seolity' ), count( $c['pages'] ) ),
					'evidence'   => array( 'source' => 'gsc', 'query' => $c['query'], 'pages' => $c['pages'] ),
					'action'     => array( 'type' => 'cannibalization', 'query' => $c['query'] ),
					'impact'     => min( 85, 30 + $c['impressions'] / 100 ),
					'effort'     => 55,
					'confidence' => 60,
				)
			);
		}
	}

	private function gen_faq() {
		$rows = $this->plugin->db->rows(
			"SELECT page, COUNT(DISTINCT query_hash) q, SUM(impressions) impressions FROM {gsc_page_query} WHERE project_id = %d AND date >= %s AND ( query REGEXP '^(how|what|why|when|which|can|is|does|do|where) ' OR query LIKE %s OR query LIKE %s OR query LIKE %s OR query LIKE %s ) GROUP BY page_hash HAVING q >= 3 ORDER BY impressions DESC LIMIT 15",
			array( $this->plugin->db->project_id(), gmdate( 'Y-m-d', strtotime( '-60 days' ) ), '%چگونه%', '%چطور%', '%چیست%', '%آیا%' )
		);
		foreach ( $rows as $r ) {
			list( $type, $id ) = $this->resolve( $r['page'] );
			if ( 'post' !== $type || get_post_meta( $id, '_seolity_faq', true ) ) {
				continue;
			}
			$this->add(
				array(
					'type'        => 'faq_opportunity',
					'url'         => $r['page'],
					'object_type' => $type,
					'object_id'   => $id,
					/* translators: %s page */
					'title'       => sprintf( __( 'Add an FAQ section to "%s"', 'seolity' ), $this->label( $r['page'], $type, $id ) ),
					/* translators: %d questions */
					'reason'      => sprintf( __( 'Searchers reach this page with %d question-style queries. Answering them directly improves relevance and can win FAQ/PAA visibility.', 'seolity' ), $r['q'] ),
					'evidence'    => array( 'source' => 'gsc', 'question_queries' => (int) $r['q'], 'impressions' => (int) $r['impressions'] ),
					'action'      => array( 'type' => 'generate_faq', 'object_type' => $type, 'object_id' => $id ),
					'impact'      => 45,
					'effort'      => 15,
					'confidence'  => 60,
					'auto_fixable' => true,
				)
			);
		}
	}

	private function gen_from_issues( $gsc ) {
		$db  = $this->plugin->db;
		$pid = $db->project_id();
		// Noindex pages that still get impressions → critical.
		foreach ( $db->rows( "SELECT i.context, u.url, u.object_type, u.object_id FROM {issues} i JOIN {urls} u ON u.id = i.url_id WHERE i.project_id = %d AND i.status = 'open' AND i.check_code = 'noindex_with_traffic' LIMIT 20", array( $pid ) ) as $r ) {
			$ctx = json_decode( (string) $r['context'], true );
			$this->add(
				array(
					'type'        => 'remove_noindex',
					'url'         => $r['url'],
					'object_type' => $r['object_type'],
					'object_id'   => $r['object_id'],
					/* translators: %s page */
					'title'       => sprintf( __( 'Remove noindex from "%s"', 'seolity' ), $this->label( $r['url'], $r['object_type'], $r['object_id'] ) ),
					/* translators: %d impressions */
					'reason'      => sprintf( __( 'The page is set to noindex but still received %d impressions in 28 days — it will disappear from Google on the next crawl.', 'seolity' ), isset( $ctx['impressions'] ) ? $ctx['impressions'] : 0 ),
					'evidence'    => array( 'source' => 'audit+gsc', 'metrics' => $ctx ),
					'action'      => array( 'type' => 'set_field', 'object_type' => $r['object_type'], 'object_id' => (int) $r['object_id'], 'field' => 'noindex', 'value' => '' ),
					'impact'      => 90,
					'effort'      => 5,
					'confidence'  => 70,
					'priority'    => 'critical',
					'auto_fixable' => (bool) $r['object_id'],
				)
			);
		}
		// Broken internal link targets → redirect.
		foreach ( $db->rows( "SELECT u.url, COUNT(DISTINCT l.source_id) sources FROM {urls} u JOIN {internal_links} l ON l.target_id = u.id AND l.link_type = 'existing' WHERE u.project_id = %d AND u.status_code BETWEEN 400 AND 499 GROUP BY u.id ORDER BY sources DESC LIMIT 20", array( $pid ) ) as $r ) {
			$cands  = $this->plugin->not_found->candidates( $r['url'] );
			$target = $cands ? $cands[0]['url'] : '';
			$this->add(
				array(
					'type'        => 'create_redirect',
					'url'         => $r['url'],
					/* translators: %s path */
					'title'       => sprintf( __( 'Redirect the broken URL %s', 'seolity' ), urldecode( (string) wp_parse_url( $r['url'], PHP_URL_PATH ) ) ),
					/* translators: %d sources */
					'reason'      => sprintf( __( 'It returns 404 but %d of your pages still link to it.', 'seolity' ), $r['sources'] ) . ( $target ? ' ' . sprintf( /* translators: %s url */ __( 'Best match: %s', 'seolity' ), $target ) : '' ),
					'evidence'    => array( 'source' => 'audit', 'linking_pages' => (int) $r['sources'], 'candidates' => array_slice( $cands, 0, 5 ) ),
					'action'      => array( 'type' => 'create_redirect', 'source' => $r['url'], 'target' => $target ),
					'impact'      => min( 85, 40 + 5 * $r['sources'] ),
					'effort'      => 5,
					'confidence'  => $target && $cands[0]['score'] >= 70 ? 75 : 50,
					'auto_fixable' => (bool) $target,
				)
			);
		}
		// Orphans that matter (have impressions) → internal links.
		foreach ( $db->rows( "SELECT u.url, u.object_type, u.object_id FROM {issues} i JOIN {urls} u ON u.id = i.url_id WHERE i.project_id = %d AND i.status = 'open' AND i.check_code = 'orphan_page' LIMIT 40", array( $pid ) ) as $r ) {
			$m = $gsc ? $this->plugin->gsc_analytics->page_metrics( $r['url'], 28 ) : array( 'impressions' => 0 );
			$this->add(
				array(
					'type'        => 'orphan_page',
					'url'         => $r['url'],
					'object_type' => $r['object_type'],
					'object_id'   => $r['object_id'],
					/* translators: %s page */
					'title'       => sprintf( __( 'Link to the orphan page "%s"', 'seolity' ), $this->label( $r['url'], $r['object_type'], $r['object_id'] ) ),
					'reason'      => __( 'No page on your site links here, so crawlers rarely visit it and it receives no internal authority.', 'seolity' ),
					'evidence'    => array( 'source' => 'audit', 'inlinks' => 0, 'gsc' => $m ),
					'action'      => array( 'type' => 'internal_links_to', 'object_type' => $r['object_type'], 'object_id' => (int) $r['object_id'] ),
					'impact'      => 30 + min( 40, $m['impressions'] / 50 ),
					'effort'      => 15,
					'confidence'  => 70,
					'auto_fixable' => true,
				)
			);
		}
		// Thin pages: expand, merge or noindex depending on traffic.
		foreach ( $db->rows( "SELECT u.url, u.object_type, u.object_id, u.word_count FROM {issues} i JOIN {urls} u ON u.id = i.url_id WHERE i.project_id = %d AND i.status = 'open' AND i.check_code = 'thin_content' ORDER BY u.inlinks DESC LIMIT 30", array( $pid ) ) as $r ) {
			$m      = $gsc ? $this->plugin->gsc_analytics->page_metrics( $r['url'], 90 ) : array( 'impressions' => -1, 'clicks' => 0 );
			$no_traffic = 0 === $m['impressions'];
			$this->add(
				array(
					'type'        => $no_traffic ? 'thin_noindex' : 'thin_expand',
					'url'         => $r['url'],
					'object_type' => $r['object_type'],
					'object_id'   => $r['object_id'],
					'title'       => $no_traffic
						/* translators: %s page */
						? sprintf( __( 'Merge or noindex the thin page "%s"', 'seolity' ), $this->label( $r['url'], $r['object_type'], $r['object_id'] ) )
						/* translators: %s page */
						: sprintf( __( 'Expand the thin page "%s"', 'seolity' ), $this->label( $r['url'], $r['object_type'], $r['object_id'] ) ),
					'reason'      => $no_traffic
						/* translators: %d words */
						? sprintf( __( 'Only %d words and zero search impressions in 90 days. Thin pages can lower overall site quality — merge it into a stronger page (301) or noindex it.', 'seolity' ), $r['word_count'] )
						/* translators: %d words */
						: sprintf( __( 'Only %d words, yet Google already shows it. Adding genuinely useful depth is likely to lift rankings.', 'seolity' ), $r['word_count'] ),
					'evidence'    => array( 'source' => 'audit+gsc', 'words' => (int) $r['word_count'], 'gsc' => $m ),
					'action'      => $no_traffic ? array( 'type' => 'set_field', 'object_type' => $r['object_type'], 'object_id' => (int) $r['object_id'], 'field' => 'noindex', 'value' => '1' ) : array( 'type' => 'optimize_content', 'object_type' => $r['object_type'], 'object_id' => (int) $r['object_id'] ),
					'impact'      => $no_traffic ? 25 : 50,
					'effort'      => $no_traffic ? 10 : 50,
					'confidence'  => $gsc ? 60 : 40,
				)
			);
		}
		// Missing meta descriptions (bulk).
		$missing = (int) $db->value( "SELECT COUNT(*) FROM {issues} WHERE project_id = %d AND status = 'open' AND check_code IN ('meta_desc_missing','title_missing')", array( $pid ) );
		if ( $missing ) {
			$ids = $db->column( "SELECT u.object_id FROM {issues} i JOIN {urls} u ON u.id = i.url_id WHERE i.project_id = %d AND i.status = 'open' AND i.check_code = 'meta_desc_missing' AND u.object_type = 'post' LIMIT 200", array( $pid ) );
			$this->add(
				array(
					'type'        => 'bulk_meta',
					'key'         => 'meta_missing',
					/* translators: %d */
					'title'       => sprintf( __( 'Write meta descriptions for %d pages', 'seolity' ), $missing ),
					'reason'      => __( 'Without a meta description Google improvises a snippet, which usually lowers CTR.', 'seolity' ),
					'evidence'    => array( 'source' => 'audit', 'count' => $missing ),
					'action'      => array( 'type' => 'bulk', 'task' => 'meta', 'object_type' => 'post', 'ids' => array_map( 'intval', $ids ) ),
					'impact'      => min( 70, 20 + $missing ),
					'effort'      => 15,
					'confidence'  => 75,
					'auto_fixable' => true,
				)
			);
		}
		$chains = (int) $db->value( "SELECT COUNT(*) FROM {issues} WHERE project_id = %d AND status = 'open' AND check_code = 'redirect_loop'", array( $pid ) );
		if ( $chains ) {
			$this->add(
				array(
					'type'       => 'redirect_loops',
					'key'        => 'loops',
					/* translators: %d */
					'title'      => sprintf( __( 'Break %d redirect loop(s)', 'seolity' ), $chains ),
					'reason'     => __( 'Redirect loops make URLs completely inaccessible to users and Google.', 'seolity' ),
					'evidence'   => array( 'source' => 'audit' ),
					'action'     => array( 'type' => 'route', 'route' => '#/audit?check=redirect_loop' ),
					'impact'     => 80,
					'effort'     => 20,
					'confidence' => 90,
					'priority'   => 'critical',
				)
			);
		}
	}

	private function gen_internal_links() {
		$rows = $this->plugin->db->rows(
			"SELECT l.id, l.anchor, l.relevance, l.reason, s.url source_url, s.object_id source_id, t.url target_url, t.object_id target_id FROM {internal_links} l JOIN {urls} s ON s.id = l.source_id JOIN {urls} t ON t.id = l.target_id WHERE l.project_id = %d AND l.link_type = 'suggested' AND l.status = 'pending' ORDER BY l.relevance DESC LIMIT 25",
			array( $this->plugin->db->project_id() )
		);
		foreach ( $rows as $r ) {
			$this->add(
				array(
					'type'        => 'internal_link',
					'key'         => $r['id'],
					'url'         => $r['source_url'],
					'object_type' => 'post',
					'object_id'   => $r['source_id'],
					/* translators: 1 anchor 2 source 3 target */
					'title'       => sprintf( __( 'Link "%1$s" from %2$s to %3$s', 'seolity' ), $r['anchor'], get_the_title( $r['source_id'] ), get_the_title( $r['target_id'] ) ),
					'reason'      => (string) $r['reason'],
					'evidence'    => array( 'source' => 'internal_links', 'relevance' => (int) $r['relevance'], 'source_url' => $r['source_url'], 'target_url' => $r['target_url'] ),
					'action'      => array( 'type' => 'apply_link', 'link_id' => (int) $r['id'] ),
					'impact'      => 20 + (int) $r['relevance'] / 4,
					'effort'      => 5,
					'confidence'  => (int) $r['relevance'],
					'auto_fixable' => true,
				)
			);
		}
	}

	private function gen_products() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		$rows = $this->plugin->db->rows(
			"SELECT p.ID, p.post_title, ca.score FROM {$this->plugin->db->wpdb->posts} p LEFT JOIN {content_analysis} ca ON ca.object_type = 'post' AND ca.object_id = p.ID WHERE p.post_type = 'product' AND p.post_status = 'publish' AND ( ca.score IS NULL OR ca.score < 55 OR p.post_excerpt = '' ) ORDER BY ca.score ASC LIMIT 25"
		);
		foreach ( $rows as $r ) {
			$score = null === $r['score'] ? null : (int) $r['score'];
			$this->add(
				array(
					'type'        => 'optimize_product',
					'url'         => get_permalink( $r['ID'] ),
					'object_type' => 'post',
					'object_id'   => (int) $r['ID'],
					/* translators: %s product */
					'title'       => sprintf( __( 'Optimize product "%s"', 'seolity' ), $r['post_title'] ),
					'reason'      => null === $score
						? __( 'Product page has not been analyzed yet and may lack descriptions, meta or ALT text.', 'seolity' )
						/* translators: %d */
						: sprintf( __( 'On-page score is %d/100 — weak descriptions, keyword coverage or meta tags.', 'seolity' ), $score ),
					'evidence'    => array( 'source' => 'content_analysis', 'score' => $score ),
					'action'      => array( 'type' => 'optimize_product', 'object_id' => (int) $r['ID'] ),
					'impact'      => 45,
					'effort'      => 20,
					'confidence'  => 60,
				)
			);
		}
	}

	private function gen_categories( $gsc ) {
		if ( ! $gsc ) {
			return;
		}
		foreach ( $this->plugin->gsc_analytics->top_pages( 28, 200 ) as $p ) {
			list( $type, $id ) = $this->resolve( $p['page'] );
			if ( 'term' !== $type || $p['impressions'] < 200 ) {
				continue;
			}
			$term = get_term( $id );
			if ( ! $term || is_wp_error( $term ) || mb_strlen( wp_strip_all_tags( $term->description ) ) > 300 ) {
				continue;
			}
			$this->add(
				array(
					'type'        => 'strengthen_category',
					'url'         => $p['page'],
					'object_type' => 'term',
					'object_id'   => $id,
					/* translators: %s category */
					'title'       => sprintf( __( 'Strengthen category "%s"', 'seolity' ), $term->name ),
					/* translators: %s impressions */
					'reason'      => sprintf( __( 'This category earns %s impressions but has little or no descriptive content. A helpful intro, buying guidance and FAQs improve relevance.', 'seolity' ), number_format_i18n( $p['impressions'] ) ),
					'evidence'    => array( 'source' => 'gsc', 'metrics' => $p, 'description_length' => mb_strlen( wp_strip_all_tags( $term->description ) ) ),
					'action'      => array( 'type' => 'optimize_content', 'object_type' => 'term', 'object_id' => $id, 'field' => 'term_description' ),
					'impact'      => min( 80, 30 + $p['impressions'] / 200 ),
					'effort'      => 30,
					'confidence'  => 60,
				)
			);
		}
	}

	private function gen_competitor_gaps() {
		foreach ( $this->plugin->db->rows( "SELECT id, name, summary FROM {competitors} WHERE project_id = %d AND status = 'active'", array( $this->plugin->db->project_id() ) ) as $c ) {
			$summary = json_decode( (string) $c['summary'], true );
			foreach ( array_slice( isset( $summary['gap']['opportunities'] ) ? $summary['gap']['opportunities'] : array(), 0, 8 ) as $o ) {
				$weights = array( 'critical' => 85, 'high' => 65, 'medium' => 45, 'low' => 25 );
				$this->add(
					array(
						'type'       => 'content_gap',
						'key'        => $c['id'] . '|' . $o['keyword'],
						/* translators: 1 title 2 competitor */
						'title'      => sprintf( __( 'Create "%1$s" (gap vs. %2$s)', 'seolity' ), $o['suggested_title'], $c['name'] ),
						'reason'     => $o['reason'],
						'evidence'   => array( 'source' => 'competitor', 'competitor' => $c['name'], 'opportunity' => $o ),
						'action'     => array( 'type' => 'plan_content', 'opportunity' => $o ),
						'impact'     => isset( $weights[ $o['priority'] ] ) ? $weights[ $o['priority'] ] : 40,
						'effort'     => 70,
						'confidence' => 50,
					)
				);
			}
		}
	}

	private function gen_redirect_chains() {
		$chains = array_filter( $this->plugin->redirects->chains(), static function ( $c ) {
			return ! $c['loop'];
		} );
		if ( $chains ) {
			$this->add(
				array(
					'type'        => 'fix_redirect_chains',
					'key'         => 'chains',
					/* translators: %d */
					'title'       => sprintf( __( 'Flatten %d redirect chain(s)', 'seolity' ), count( $chains ) ),
					'reason'      => __( 'Each extra hop slows users and crawlers; point old URLs straight to their final destination.', 'seolity' ),
					'evidence'    => array( 'source' => 'redirects', 'chains' => array_slice( array_values( $chains ), 0, 10 ) ),
					'action'      => array( 'type' => 'fix_redirect_chains' ),
					'impact'      => 35,
					'effort'      => 5,
					'confidence'  => 95,
					'auto_fixable' => true,
				)
			);
		}
	}

	/* ------------------------------ Queries & actions ------------------------------ */

	public function query( array $f ) {
		$db    = $this->plugin->db;
		$where = array( 'project_id = %d', 'status = %s' );
		$args  = array( $db->project_id(), ! empty( $f['status'] ) ? $f['status'] : 'open' );
		if ( ! empty( $f['priority'] ) ) {
			$where[] = 'priority = %s';
			$args[]  = $f['priority'];
		}
		if ( ! empty( $f['type'] ) ) {
			$where[] = 'type = %s';
			$args[]  = $f['type'];
		}
		if ( ! empty( $f['object_type'] ) && ! empty( $f['object_id'] ) ) {
			$where[] = 'object_type = %s AND object_id = %d';
			$args[]  = $f['object_type'];
			$args[]  = (int) $f['object_id'];
		}
		$limit = isset( $f['limit'] ) ? max( 1, min( 200, (int) $f['limit'] ) ) : 50;
		$rows  = $db->rows( 'SELECT * FROM {recommendations} WHERE ' . implode( ' AND ', $where ) . ' ORDER BY score DESC LIMIT %d', array_merge( $args, array( $limit ) ) );
		return array_map(
			static function ( $r ) {
				return Database::decode( $r, array( 'evidence', 'action' ) );
			},
			$rows
		);
	}

	/** AI Action Center: "What should I do today?" */
	public function action_center() {
		$db     = $this->plugin->db;
		$counts = array( 'critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0 );
		foreach ( $db->rows( "SELECT priority, COUNT(*) c FROM {recommendations} WHERE project_id = %d AND status = 'open' GROUP BY priority", array( $db->project_id() ) ) as $r ) {
			$counts[ $r['priority'] ] = (int) $r['c'];
		}
		return array(
			'counts'    => $counts,
			'today'     => $this->query( array( 'limit' => 10 ) ),
			'refreshed' => get_option( 'seolity_recommendations_refreshed' ),
			'pending_changes' => (int) $db->value( "SELECT COUNT(*) FROM {changes} WHERE project_id = %d AND status = 'pending'", array( $db->project_id() ) ),
		);
	}

	public function get( $id ) {
		return Database::decode( $this->plugin->db->row( 'SELECT * FROM {recommendations} WHERE id = %d', array( (int) $id ) ), array( 'evidence', 'action' ) );
	}

	public function mark( $id, $status ) {
		if ( ! in_array( $status, array( 'open', 'dismissed', 'done', 'snoozed' ), true ) ) {
			return false;
		}
		$this->plugin->audit_log->log( 'recommendation.' . $status, 'recommendation', (int) $id );
		return (bool) $this->plugin->db->update( 'recommendations', array( 'status' => $status, 'updated_at' => Database::now() ), array( 'id' => (int) $id ) );
	}

	/** "Why?" explanation. */
	public function explain( $id ) {
		$r = $this->get( $id );
		if ( ! $r ) {
			return new \WP_Error( 'seolity_rec', __( 'Recommendation not found.', 'seolity' ), array( 'status' => 404 ) );
		}
		$learn = $this->plugin->learning->for_change_type( $this->change_type_for( $r['type'] ), $r['object_type'] ? $r['object_type'] : 'post' );
		return array(
			'found'      => $r['title'],
			'data'       => $r['evidence'],
			'why'        => $r['reason'],
			'expected'   => $this->expected_outcome( $r ),
			'confidence' => (int) $r['confidence'],
			'scores'     => array( 'impact' => (int) $r['impact'], 'effort' => (int) $r['effort'], 'confidence' => (int) $r['confidence'], 'score' => (float) $r['score'] ),
			'history'    => $learn,
		);
	}

	private function change_type_for( $rec_type ) {
		$map = array( 'ctr_opportunity' => 'meta_title+meta_description', 'striking_distance' => 'post_content', 'thin_expand' => 'post_content', 'faq_opportunity' => 'faq_schema', 'strengthen_category' => 'term_description', 'optimize_product' => 'product' );
		return isset( $map[ $rec_type ] ) ? $map[ $rec_type ] : $rec_type;
	}

	private function expected_outcome( array $r ) {
		$m = array(
			'ctr_opportunity'   => __( 'Higher CTR at the same rankings → more clicks within 2–4 weeks.', 'seolity' ),
			'striking_distance' => __( 'Better rankings for page-2 queries within 4–8 weeks.', 'seolity' ),
			'remove_noindex'    => __( 'The page stays in / returns to Google\'s index.', 'seolity' ),
			'create_redirect'   => __( 'Recovered link equity and fewer dead ends for users.', 'seolity' ),
			'orphan_page'       => __( 'Faster discovery, more internal authority, improved rankings.', 'seolity' ),
			'cannibalization'   => __( 'One stronger URL ranks higher for the contested query.', 'seolity' ),
			'faq_opportunity'   => __( 'Better coverage of question queries; potential PAA visibility.', 'seolity' ),
			'internal_link'     => __( 'More relevance and authority for the target page.', 'seolity' ),
			'content_gap'       => __( 'New rankings for topics competitors already win.', 'seolity' ),
		);
		return isset( $m[ $r['type'] ] ) ? $m[ $r['type'] ] : __( 'Improved SEO quality for the affected URLs.', 'seolity' );
	}

	/**
	 * Execute a recommendation: produces previewable pending changes (never
	 * silently applies unless approval mode allows).
	 */
	public function execute( $id ) {
		$r = $this->get( $id );
		if ( ! $r ) {
			return new \WP_Error( 'seolity_rec', __( 'Recommendation not found.', 'seolity' ), array( 'status' => 404 ) );
		}
		$a   = $r['action'];
		$p   = $this->plugin;
		$res = null;
		switch ( isset( $a['type'] ) ? $a['type'] : '' ) {
			case 'optimize_meta':
				$res = $p->optimizer->meta( $a['object_type'], (int) $a['object_id'] );
				break;
			case 'optimize_content':
				$field = isset( $a['field'] ) ? $a['field'] : ( 'term' === $a['object_type'] ? 'term_description' : 'post_content' );
				$res   = $p->optimizer->optimize( array( 'object_type' => $a['object_type'], 'object_id' => (int) $a['object_id'], 'field' => $field, 'goal' => $r['reason'] ) );
				break;
			case 'optimize_product':
				$res = $p->products->optimize( (int) $a['object_id'] );
				break;
			case 'generate_faq':
				$cid = $p->optimizer->faq( (int) $a['object_id'] );
				$res = is_wp_error( $cid ) ? $cid : array( 'changes' => array( 'faq_schema' => $cid ) );
				break;
			case 'set_field':
				$cid = $p->changes->propose( array( 'object_type' => $a['object_type'], 'object_id' => (int) $a['object_id'], 'field' => $a['field'], 'after' => $a['value'], 'source' => 'recommendation', 'reason' => $r['reason'], 'recommendation_id' => (int) $id ) );
				$res = is_wp_error( $cid ) ? $cid : array( 'changes' => array( $a['field'] => $cid ) );
				break;
			case 'create_redirect':
				$res = array( 'preview' => array( 'type' => 'redirect', 'source' => $a['source'], 'target' => $a['target'], 'candidates' => isset( $r['evidence']['candidates'] ) ? $r['evidence']['candidates'] : array() ) );
				break;
			case 'internal_links_to':
				$res = $p->links->suggest_to( (int) $a['object_id'] );
				break;
			case 'apply_link':
				$res = $p->links->preview( (int) $a['link_id'] );
				break;
			case 'cannibalization':
				$res = $p->keywords->cannibalization_advice( $a['query'] );
				break;
			case 'bulk':
				$res = $p->optimizer->queue_bulk( $a['task'], $a['ids'], $a['object_type'] );
				break;
			case 'fix_redirect_chains':
				$res = array( 'fixed' => $p->redirects->fix_chains() );
				$this->mark( $id, 'done' );
				break;
			case 'plan_content':
				$res = $p->planner->add_from_opportunity( $a['opportunity'] );
				break;
			default:
				$res = array( 'route' => isset( $a['route'] ) ? $a['route'] : '#/audit' );
		}
		if ( ! is_wp_error( $res ) && ! empty( $res['changes'] ) ) {
			foreach ( (array) $res['changes'] as $cid ) {
				$p->db->update( 'changes', array( 'recommendation_id' => (int) $id ), array( 'id' => (int) $cid ) );
			}
		}
		$p->audit_log->log( 'recommendation.executed', 'recommendation', (int) $id, array( 'type' => $r['type'] ) );
		return $res;
	}
}
