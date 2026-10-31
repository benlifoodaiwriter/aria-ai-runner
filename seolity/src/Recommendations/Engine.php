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

	/**
	 * Every issue type → [category (roadmap section), owning module route].
	 * One canonical record per (type, page); other screens only reference it.
	 */
	const CATEGORIES = array(
		'create_redirect'     => array( '404', '#/404' ),
		'not_found'           => array( '404', '#/404' ),
		'fix_redirect_chains' => array( 'technical', '#/redirects' ),
		'redirect_loops'      => array( 'technical', '#/redirects' ),
		'http_5xx'            => array( 'technical', '#/audit' ),
		'remove_noindex'      => array( 'indexability', '#/indexability' ),
		'canonical_fix'       => array( 'indexability', '#/indexability' ),
		'index_waste'         => array( 'indexability', '#/indexability' ),
		'noindex_in_sitemap'  => array( 'indexability', '#/indexability' ),
		'thin_noindex'        => array( 'duplicates', '#/duplicates' ),
		'cannibalization'     => array( 'duplicates', '#/duplicates' ),
		'duplicate_cluster'   => array( 'duplicates', '#/duplicates' ),
		'meta_fix'            => array( 'onpage', '#/audit?category=meta' ),
		'headings_fix'        => array( 'onpage', '#/audit?category=content' ),
		'bulk_meta'           => array( 'onpage', '#/audit?category=meta' ),
		'ctr_opportunity'     => array( 'content', '#/content' ),
		'striking_distance'   => array( 'content', '#/content' ),
		'declining_page'      => array( 'content', '#/content' ),
		'thin_expand'         => array( 'content', '#/content' ),
		'faq_opportunity'     => array( 'content', '#/content' ),
		'orphan_page'         => array( 'links', '#/links' ),
		'internal_link'       => array( 'links', '#/links' ),
		'optimize_product'    => array( 'products', '#/products' ),
		'strengthen_category' => array( 'products', '#/products' ),
		'content_gap'         => array( 'competitors', '#/competitors' ),
		'competitor_query_gap' => array( 'competitors', '#/competitors' ),
	);

	/** Types measured with search data: a fixed one only re-opens after a full new measuring window. */
	const METRIC_TYPES = array( 'ctr_opportunity', 'striking_distance', 'declining_page', 'faq_opportunity', 'content_gap', 'strengthen_category', 'thin_expand', 'cannibalization' );

	/** @var array<string,bool> Types whose generator ran in this refresh. */
	private $owned = array();

	public static function category_of( $type ) {
		return isset( self::CATEGORIES[ $type ] ) ? self::CATEGORIES[ $type ] : array( 'other', '#/actions' );
	}

	/** A generator declares the types it is responsible for (so missing ones count as resolved). */
	public function own( array $types ) {
		foreach ( $types as $t ) {
			$this->owned[ $t ] = true;
		}
	}

	public function refresh() {
		$this->run_started = Database::now();
		$this->generated   = 0;
		$this->owned       = array();
		$gsc               = $this->plugin->gsc_oauth->is_connected() && $this->plugin->gsc_analytics->has_data();
		if ( $gsc ) {
			$this->own( array( 'ctr_opportunity', 'striking_distance', 'declining_page', 'cannibalization', 'faq_opportunity' ) );
			$this->gen_ctr();
			$this->gen_striking_distance();
			$this->gen_declining();
			$this->gen_cannibalization();
			$this->gen_faq();
		}
		$this->own( array( 'remove_noindex', 'create_redirect', 'orphan_page', 'thin_noindex', 'thin_expand', 'bulk_meta', 'redirect_loops', 'internal_link', 'optimize_product', 'strengthen_category', 'content_gap', 'fix_redirect_chains', 'meta_fix', 'headings_fix', 'canonical_fix' ) );
		$this->own( array( 'not_found' ) );
		$this->gen_from_issues( $gsc );
		$this->gen_audit_checks();
		$this->gen_not_found();
		$this->gen_internal_links();
		$this->gen_products();
		$this->gen_categories( $gsc );
		$this->gen_competitor_gaps();
		$this->gen_redirect_chains();
		do_action( 'seolity_generate_recommendations', $this );

		// Issues of a checked type that were not detected again are resolved (the problem is gone);
		// types whose detector did not run (e.g. no Search Console) keep their state.
		if ( $this->owned ) {
			$types = array_keys( $this->owned );
			$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
			$this->plugin->db->query(
				"UPDATE {recommendations} SET status = 'resolved', resolved_at = %s WHERE project_id = %d AND status IN ('open','stale') AND updated_at < %s AND type IN ($in)",
				array_merge( array( Database::now(), $this->plugin->db->project_id(), $this->run_started ), $types )
			);
		}
		update_option( 'seolity_recommendations_refreshed', time(), false );
		do_action( 'seolity_issues_refreshed', $this->generated );
		return $this->generated;
	}

	/**
	 * Change the state of a canonical issue (operations, verification, the owner).
	 *
	 * @param string $status open | in_progress | done | resolved | dismissed | snoozed.
	 * @param array  $extra  verified_at, verification, operation_id, instructions.
	 */
	public function set_state( $id, $status, array $extra = array() ) {
		$data = array( 'status' => $status, 'updated_at' => Database::now() );
		if ( in_array( $status, array( 'done', 'resolved' ), true ) ) {
			$data['resolved_at'] = Database::now();
		}
		foreach ( array( 'verified_at', 'operation_id', 'instructions' ) as $k ) {
			if ( array_key_exists( $k, $extra ) ) {
				$data[ $k ] = $extra[ $k ];
			}
		}
		if ( isset( $extra['verification'] ) ) {
			$data['verification'] = wp_json_encode( $extra['verification'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		$ok = (bool) $this->plugin->db->update( 'recommendations', $data, array( 'id' => (int) $id ) );
		do_action( 'seolity_issue_state', (int) $id, $status );
		return $ok;
	}

	/** Mark the issues of some types about one URL as done + verified (event-driven sync). */
	public function resolve_url( array $types, $url, array $verification ) {
		$db   = $this->plugin->db;
		$in   = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$path = \SEOlity\Redirects\Manager::normalize_source( $url );
		$rows = $db->rows( "SELECT id, url FROM {recommendations} WHERE project_id = %d AND type IN ($in) AND status IN ('open','in_progress','stale')", array_merge( array( $db->project_id() ), $types ) );
		foreach ( $rows as $r ) {
			if ( \SEOlity\Redirects\Manager::normalize_source( (string) $r['url'] ) === $path ) {
				$this->set_state( (int) $r['id'], 'done', array( 'verified_at' => Database::now(), 'verification' => $verification ) );
			}
		}
	}

	/** Logged 404s that matter (repeated hits or linked from somewhere) → one issue per URL. */
	private function gen_not_found() {
		$db   = $this->plugin->db;
		$rows = $db->rows( "SELECT id, url, hits, referrer, suggested_target, suggestion_score FROM {not_found} WHERE project_id = %d AND status = 'open' AND is_bot = 0 AND ( hits >= 2 OR ( referrer IS NOT NULL AND referrer <> '' ) ) ORDER BY hits DESC LIMIT 300", array( $db->project_id() ) );
		foreach ( $rows as $r ) {
			$target = (string) $r['suggested_target'];
			$path   = urldecode( (string) wp_parse_url( $r['url'], PHP_URL_PATH ) );
			$this->add(
				array(
					'type'         => 'not_found',
					'url'          => $r['url'],
					/* translators: %s: path */
					'title'        => sprintf( __( 'Redirect the 404 URL %s', 'seolity' ), $path ),
					/* translators: %d: hits */
					'reason'       => sprintf( __( 'Visitors or bots requested it %d times and got “page not found”.', 'seolity' ), (int) $r['hits'] ) . ( $target ? ' ' . sprintf( /* translators: %s url */ __( 'Suggested target: %s', 'seolity' ), urldecode( $target ) ) : ' ' . __( 'No good target was found yet — choose one or mark it as gone (410).', 'seolity' ) ),
					'evidence'     => array( 'source' => '404', 'hits' => (int) $r['hits'], 'referrer' => $r['referrer'], 'not_found_id' => (int) $r['id'], 'score' => (int) $r['suggestion_score'] ),
					'action'       => array( 'type' => 'create_redirect', 'source' => $r['url'], 'target' => $target ),
					'impact'       => min( 80, 30 + 2 * (int) $r['hits'] + ( $r['referrer'] ? 15 : 0 ) ),
					'effort'       => 5,
					'confidence'   => $target ? min( 90, 40 + (int) $r['suggestion_score'] / 2 ) : 35,
					'auto_fixable' => '' !== $target,
				)
			);
		}
	}

	/** Give older records their category / owning section (migration). */
	public function backfill_categories() {
		foreach ( self::CATEGORIES as $type => $c ) {
			$this->plugin->db->query( "UPDATE {recommendations} SET category = %s, module = %s WHERE type = %s AND category = ''", array( $c[0], $c[1], $type ) );
		}
		$this->plugin->db->query( "UPDATE {recommendations} SET status = 'resolved', resolved_at = updated_at WHERE status = 'stale'" );
	}

	/**
	 * Crawler findings that need a per-page fix become one canonical issue per page
	 * (title + description problems of a page = one "meta" fix; heading problems = one fix).
	 */
	private function gen_audit_checks() {
		$db     = $this->plugin->db;
		$groups = array(
			'meta_fix'      => array( 'title_missing', 'title_too_long', 'title_too_short', 'title_multiple', 'duplicate_title', 'meta_desc_missing', 'meta_desc_length', 'duplicate_meta' ),
			'headings_fix'  => array( 'h1_missing', 'h1_multiple', 'heading_skip' ),
			'canonical_fix' => array( 'canonical_multiple', 'canonical_invalid', 'canonical_to_error' ),
		);
		$names  = array(
			'title_missing'      => __( 'no SEO title', 'seolity' ),
			'title_too_long'     => __( 'title too long', 'seolity' ),
			'title_too_short'    => __( 'title too short', 'seolity' ),
			'title_multiple'     => __( 'several <title> tags', 'seolity' ),
			'duplicate_title'    => __( 'title used on other pages too', 'seolity' ),
			'meta_desc_missing'  => __( 'no meta description', 'seolity' ),
			'meta_desc_length'   => __( 'description length', 'seolity' ),
			'duplicate_meta'     => __( 'description used on other pages too', 'seolity' ),
			'h1_missing'         => __( 'no H1 heading', 'seolity' ),
			'h1_multiple'        => __( 'several H1 headings', 'seolity' ),
			'heading_skip'       => __( 'heading levels skipped', 'seolity' ),
			'canonical_multiple' => __( 'several canonical tags', 'seolity' ),
			'canonical_invalid'  => __( 'invalid canonical URL', 'seolity' ),
			'canonical_to_error' => __( 'canonical points to an error page', 'seolity' ),
		);
		foreach ( $groups as $type => $codes ) {
			$in   = implode( ',', array_fill( 0, count( $codes ), '%s' ) );
			$rows = $db->rows( "SELECT u.url, u.object_type, u.object_id, GROUP_CONCAT(i.check_code) codes, MAX(FIELD(i.severity,'low','medium','high','critical')) sev FROM {issues} i JOIN {urls} u ON u.id = i.url_id WHERE i.project_id = %d AND i.status = 'open' AND i.check_code IN ($in) AND u.status_code = 200 GROUP BY u.id ORDER BY sev DESC, u.inlinks DESC LIMIT 400", array_merge( array( $db->project_id() ), $codes ) );
			foreach ( $rows as $r ) {
				$found = array_values( array_unique( explode( ',', (string) $r['codes'] ) ) );
				$list  = implode( '، ', array_map( static function ( $c ) use ( $names ) {
					return isset( $names[ $c ] ) ? $names[ $c ] : $c;
				}, $found ) );
				$obj   = $r['object_type'] && $r['object_id'];
				$label = $this->label( $r['url'], $r['object_type'], $r['object_id'] );
				if ( 'meta_fix' === $type ) {
					/* translators: %s: page */
					$title  = sprintf( __( 'Fix the SEO title / description of “%s”', 'seolity' ), $label );
					$action = $obj ? array( 'type' => 'optimize_meta', 'object_type' => $r['object_type'], 'object_id' => (int) $r['object_id'] ) : array( 'type' => 'route', 'route' => '#/audit?category=meta' );
				} elseif ( 'headings_fix' === $type ) {
					/* translators: %s: page */
					$title  = sprintf( __( 'Fix the heading structure of “%s”', 'seolity' ), $label );
					$action = $obj ? array( 'type' => 'optimize_content', 'object_type' => $r['object_type'], 'object_id' => (int) $r['object_id'], 'goal' => __( 'Fix the heading structure: exactly one H1 that states the topic, logical H2/H3 levels without skipped levels. Do not change the meaning of the text.', 'seolity' ) ) : array( 'type' => 'route', 'route' => '#/audit?category=content' );
				} else {
					/* translators: %s: page */
					$title  = sprintf( __( 'Fix the canonical tag of “%s”', 'seolity' ), $label );
					$action = $obj ? array( 'type' => 'set_field', 'object_type' => $r['object_type'], 'object_id' => (int) $r['object_id'], 'field' => 'canonical', 'value' => '' ) : array( 'type' => 'route', 'route' => '#/indexability' );
				}
				$sev = (int) $r['sev'];
				$this->add(
					array(
						'type'         => $type,
						'url'          => $r['url'],
						'object_type'  => $r['object_type'],
						'object_id'    => $r['object_id'],
						'title'        => $title,
						/* translators: %s: list of problems */
						'reason'       => sprintf( __( 'Found by the site audit: %s.', 'seolity' ), $list ),
						'evidence'     => array( 'source' => 'audit', 'checks' => $found ),
						'action'       => $action,
						'impact'       => 'canonical_fix' === $type ? 70 : 25 + 12 * $sev,
						'effort'       => 'headings_fix' === $type ? 30 : 8,
						'confidence'   => 80,
						'auto_fixable' => $obj,
					)
				);
			}
		}
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
		$cat = self::category_of( $r['type'] );
		$existing = $db->row( 'SELECT id, status, resolved_at FROM {recommendations} WHERE project_id = %d AND dedupe_key = %s', array( $db->project_id(), $key ) );
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
			'category'     => $cat[0],
			'module'       => $cat[1],
			'source'       => isset( $r['evidence']['source'] ) ? substr( (string) $r['evidence']['source'], 0, 30 ) : '',
			'detected_at'  => Database::now(),
			'updated_at'   => Database::now(),
		);
		if ( $existing ) {
			if ( in_array( $existing['status'], array( 'dismissed', 'snoozed' ), true ) ) {
				return (int) $existing['id']; // Respect user decisions.
			}
			if ( 'in_progress' === $existing['status'] ) {
				unset( $row['status'] );
				$db->update( 'recommendations', $row, array( 'id' => (int) $existing['id'] ) );
				return (int) $existing['id']; // A fix is being prepared / applied: keep its state.
			}
			if ( in_array( $existing['status'], array( 'done', 'resolved' ), true ) ) {
				// Search-data findings lag behind fixes: only a full new window can re-open them.
				$age = $existing['resolved_at'] ? time() - strtotime( $existing['resolved_at'] . ' UTC' ) : PHP_INT_MAX;
				if ( in_array( $r['type'], self::METRIC_TYPES, true ) && $age < 28 * DAY_IN_SECONDS ) {
					return (int) $existing['id'];
				}
				// Detected again on the live site → it really came back.
				$row['evidence'] = array_merge( (array) $row['evidence'], array( 'regressed' => true, 'previously_resolved' => $existing['resolved_at'] ) );
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
		$row = $db->row( 'SELECT object_type, object_id FROM {urls} WHERE project_id = %d AND url_hash = %s AND object_id IS NOT NULL', array( $db->project_id(), Database::url_hash( $url ) ) );
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
		// Missing titles / descriptions are per-page issues now (gen_audit_checks); a bulk run is a
		// roadmap action over those records, never a second copy of them.
		$missing = 0;
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
		return Database::decode( $this->plugin->db->row( 'SELECT * FROM {recommendations} WHERE id = %d', array( (int) $id ) ), array( 'evidence', 'action', 'verification' ) );
	}

	/**
	 * Why a category comes in this position of the recommended order (dependency first:
	 * pages Google cannot reach or should not index are fixed before pages are polished).
	 */
	const ORDER = array(
		'indexability' => 100,
		'technical'    => 95,
		'404'          => 90,
		'duplicates'   => 85,
		'onpage'       => 75,
		'links'        => 70,
		'content'      => 65,
		'products'     => 60,
		'competitors'  => 40,
		'other'        => 30,
	);

	public static function category_names() {
		return array(
			'404'          => __( '404 errors', 'seolity' ),
			'duplicates'   => __( 'Duplicate & similar articles', 'seolity' ),
			'indexability' => __( 'Indexability & crawl budget', 'seolity' ),
			'technical'    => __( 'Technical SEO', 'seolity' ),
			'onpage'       => __( 'Site audit: titles, descriptions, headings', 'seolity' ),
			'links'        => __( 'Internal links', 'seolity' ),
			'content'      => __( 'Content & CTR', 'seolity' ),
			'products'     => __( 'Products & categories', 'seolity' ),
			'competitors'  => __( 'Competitor gaps', 'seolity' ),
			'other'        => __( 'Other', 'seolity' ),
		);
	}

	private static function order_reason( $cat ) {
		$r = array(
			'indexability' => __( 'First: pages that should not be in Google waste crawl budget, and important pages that cannot be indexed earn nothing — everything else depends on this.', 'seolity' ),
			'technical'    => __( 'Redirect loops/chains and server errors block crawlers and visitors; fix them before working on content.', 'seolity' ),
			'404'          => __( 'Broken URLs lose links and visitors; a correct 301 recovers them quickly and is low effort.', 'seolity' ),
			'duplicates'   => __( 'Merge competing articles before polishing them — otherwise you optimize pages that will be redirected.', 'seolity' ),
			'onpage'       => __( 'Missing or duplicate titles, descriptions and headings: quick fixes with direct CTR impact.', 'seolity' ),
			'links'        => __( 'Internal links spread authority to the pages you just fixed.', 'seolity' ),
			'content'      => __( 'Content improvements based on Search Console data: highest upside once the basics are clean.', 'seolity' ),
			'products'     => __( 'Product and category pages: important for shops after the site-wide basics.', 'seolity' ),
			'competitors'  => __( 'New content for topics competitors win: a longer-term growth step.', 'seolity' ),
			'other'        => '',
		);
		return isset( $r[ $cat ] ) ? $r[ $cat ] : '';
	}

	/**
	 * Roadmap board: every category with its counts, its recommended position and the reason,
	 * computed from the canonical issue records (severity, impact, number of URLs, dependency).
	 */
	public function board() {
		$db   = $this->plugin->db;
		$rows = $db->rows( "SELECT category, status, COUNT(*) c, MAX(score) top, AVG(impact) imp, SUM(priority = 'critical') crit FROM {recommendations} WHERE project_id = %d AND status IN ('open','in_progress','done','resolved') GROUP BY category, status", array( $db->project_id() ) );
		$cats = array();
		foreach ( $rows as $r ) {
			$k = '' === $r['category'] ? 'other' : $r['category'];
			if ( ! isset( $cats[ $k ] ) ) {
				$cats[ $k ] = array( 'id' => $k, 'open' => 0, 'in_progress' => 0, 'done' => 0, 'top' => 0, 'impact' => 0, 'critical' => 0 );
			}
			$key                 = 'resolved' === $r['status'] ? 'done' : $r['status'];
			$cats[ $k ][ $key ] += (int) $r['c'];
			if ( 'open' === $r['status'] ) {
				$cats[ $k ]['top']      = (float) $r['top'];
				$cats[ $k ]['impact']   = (float) $r['imp'];
				$cats[ $k ]['critical'] = (int) $r['crit'];
			}
		}
		$names  = self::category_names();
		$routes = array();
		foreach ( self::CATEGORIES as $c ) {
			$routes[ $c[0] ] = isset( $routes[ $c[0] ] ) ? $routes[ $c[0] ] : $c[1];
		}
		foreach ( $cats as $k => &$c ) {
			$c['label']  = isset( $names[ $k ] ) ? $names[ $k ] : $k;
			$c['route']  = isset( $routes[ $k ] ) ? $routes[ $k ] : '#/actions';
			$c['reason'] = self::order_reason( $k );
			// Dependency order first; within close positions, critical findings and impact move a category up.
			$c['rank'] = ( isset( self::ORDER[ $k ] ) ? self::ORDER[ $k ] : 30 ) + min( 8, 2 * $c['critical'] ) + $c['impact'] / 25 - ( $c['open'] ? 0 : 200 );
		}
		unset( $c );
		uasort(
			$cats,
			static function ( $a, $b ) {
				return $b['rank'] <=> $a['rank'];
			}
		);
		$pos = 0;
		foreach ( $cats as &$c ) {
			$c['position'] = $c['open'] ? ++$pos : null;
		}
		unset( $c );
		return array_values( $cats );
	}

	/**
	 * Canonical issues for the roadmap list.
	 *
	 * @param array $f category, status (open|in_progress|done|all), q, page, per_page.
	 */
	public function items( array $f ) {
		$db    = $this->plugin->db;
		$where = array( 'project_id = %d' );
		$args  = array( $db->project_id() );
		$st    = isset( $f['status'] ) ? (string) $f['status'] : 'active';
		if ( 'active' === $st ) {
			$where[] = "status IN ('open','in_progress')";
		} elseif ( 'done' === $st ) {
			$where[] = "status IN ('done','resolved')";
		} elseif ( in_array( $st, array( 'open', 'in_progress', 'dismissed' ), true ) ) {
			$where[] = 'status = %s';
			$args[]  = $st;
		}
		if ( ! empty( $f['category'] ) ) {
			$where[] = 'category = %s';
			$args[]  = (string) $f['category'];
		}
		if ( ! empty( $f['q'] ) ) {
			$where[] = '(title LIKE %s OR url LIKE %s)';
			$like    = '%' . $db->wpdb->esc_like( (string) $f['q'] ) . '%';
			$args[]  = $like;
			$args[]  = $like;
		}
		$per   = isset( $f['per_page'] ) ? max( 10, min( 200, (int) $f['per_page'] ) ) : 30;
		$page  = isset( $f['page'] ) ? max( 1, (int) $f['page'] ) : 1;
		$total = (int) $db->value( 'SELECT COUNT(*) FROM {recommendations} WHERE ' . implode( ' AND ', $where ), $args );
		$rows  = $db->rows( 'SELECT * FROM {recommendations} WHERE ' . implode( ' AND ', $where ) . " ORDER BY FIELD(status,'in_progress','open','done','resolved','dismissed'), FIELD(priority,'critical','high','medium','low'), score DESC, id ASC LIMIT %d OFFSET %d", array_merge( $args, array( $per, ( $page - 1 ) * $per ) ) );
		$ops   = $this->plugin->operations;
		$items = array_map(
			function ( $r ) use ( $ops ) {
				$r = Database::decode( $r, array( 'evidence', 'action', 'verification' ) );
				foreach ( array( 'id', 'object_id', 'impact', 'effort', 'confidence', 'operation_id' ) as $k ) {
					$r[ $k ] = null === $r[ $k ] ? null : (int) $r[ $k ];
				}
				$r['edit'] = 'post' === $r['object_type'] && $r['object_id'] ? admin_url( 'post.php?post=' . $r['object_id'] . '&action=edit' ) : '';
				if ( $r['operation_id'] ) {
					$op           = $this->plugin->db->row( 'SELECT id, status, error, updated_at FROM {operations} WHERE id = %d', array( (int) $r['operation_id'] ) );
					$r['operation'] = $op ? $op : null;
				}
				return $r;
			},
			$rows
		);
		return array( 'items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $per );
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
