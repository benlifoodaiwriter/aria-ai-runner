<?php
/**
 * Competitor intelligence built on real Google results: every tracked query is searched through
 * the SERP API (location-specific and non-personalized — the "incognito" view), stored as a
 * snapshot, and kept as history. From that database SEOlity knows for every competitor where it
 * ranks above or below us and with which page, estimates its Search Console (queries, pages,
 * clicks), and lets the AI explain per query why they rank where they do and what to do.
 *
 * @package SEOlity
 */

namespace SEOlity\Competitors;

use SEOlity\Core\Database;
use SEOlity\Core\Http;
use SEOlity\Crawler\PageParser;
use SEOlity\GSC\Analytics;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class Intel {

	const QUERIES = 'seolity_tracked_queries';

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	private function pid() {
		return $this->plugin->db->project_id();
	}

	private static function host( $url_or_domain ) {
		$h = false !== strpos( (string) $url_or_domain, '/' ) ? (string) wp_parse_url( $url_or_domain, PHP_URL_HOST ) : (string) $url_or_domain;
		return preg_replace( '/^www\./', '', strtolower( $h ) );
	}

	/** Known competitors by domain. */
	private function competitor_map() {
		$out = array();
		foreach ( $this->plugin->db->rows( "SELECT id, domain FROM {competitors} WHERE project_id = %d AND status = 'active'", array( $this->pid() ) ) as $c ) {
			$out[ self::host( $c['domain'] ) ] = (int) $c['id'];
		}
		return $out;
	}

	/** A competitor added later: link the stored Google results of its domain to it. */
	public function link_domain( $cid, $domain ) {
		$this->plugin->db->query( 'UPDATE {serp_results} SET competitor_id = %d WHERE domain = %s', array( (int) $cid, self::host( $domain ) ) );
	}

	/** SERP API searches used this calendar month (each snapshot = one search). */
	public function usage_month() {
		return (int) $this->plugin->db->value( 'SELECT COUNT(*) FROM {serp_snapshots} WHERE project_id = %d AND checked_at >= %s', array( $this->pid(), gmdate( 'Y-m-01 00:00:00' ) ) );
	}

	public function budget() {
		$limit = (int) $this->plugin->settings->get( 'serp_monthly_limit' );
		$used  = $this->usage_month();
		return array( 'limit' => $limit, 'used' => $used, 'left' => max( 0, $limit - $used ), 'available' => $this->plugin->serp->available() );
	}

	/** Queries to track: the owner's list first, then our most important Search Console queries. */
	public function tracked_queries() {
		$own   = array_values( array_filter( array_map( 'trim', (array) get_option( self::QUERIES, array() ) ) ) );
		$limit = (int) $this->plugin->settings->get( 'serp_track_queries' );
		$gsc   = array();
		if ( $this->plugin->gsc_analytics->has_data() && count( $own ) < $limit ) {
			$gsc = wp_list_pluck( $this->plugin->gsc_analytics->top_queries( 90, $limit * 2, array() ), 'query' );
		}
		return array_slice( array_values( array_unique( array_merge( $own, $gsc ) ) ), 0, max( $limit, count( $own ) ) );
	}

	public function add_queries( array $queries ) {
		$list = (array) get_option( self::QUERIES, array() );
		foreach ( $queries as $q ) {
			$q = trim( sanitize_text_field( (string) $q ) );
			if ( '' !== $q && ! in_array( $q, $list, true ) ) {
				$list[] = $q;
			}
		}
		update_option( self::QUERIES, array_slice( $list, 0, 1000 ), false );
		return $list;
	}

	public function remove_query( $query ) {
		update_option( self::QUERIES, array_values( array_diff( (array) get_option( self::QUERIES, array() ), array( (string) $query ) ) ), false );
	}

	/** Search one query now and store the result page. */
	public function snapshot( $query ) {
		$serp = $this->plugin->serp->fetch( $query, '', 20, true );
		if ( is_wp_error( $serp ) ) {
			return $serp;
		}
		$db    = $this->plugin->db;
		$own   = self::host( home_url() );
		$comps = $this->competitor_map();
		list( $gl, $hl ) = $this->plugin->serp->locale( '' );
		$own_pos = null;
		$own_url = null;
		foreach ( $serp['results'] as $r ) {
			if ( self::host( $r['domain'] ) === $own && null === $own_pos ) {
				$own_pos = (int) $r['position'];
				$own_url = $r['url'];
			}
		}
		$sid = $db->insert(
			'serp_snapshots',
			array(
				'project_id'   => $this->pid(),
				'query'        => mb_substr( $query, 0, 500 ),
				'query_hash'   => Database::hash( $query ),
				'gl'           => $gl,
				'hl'           => $hl,
				'features'     => wp_json_encode( $serp['features'] ),
				'questions'    => wp_json_encode( $serp['questions'], JSON_UNESCAPED_UNICODE ),
				'related'      => wp_json_encode( $serp['related'], JSON_UNESCAPED_UNICODE ),
				'own_position' => $own_pos,
				'own_url'      => $own_url,
				'checked_at'   => Database::now(),
			)
		);
		foreach ( $serp['results'] as $r ) {
			$h = self::host( $r['domain'] );
			$db->insert(
				'serp_results',
				array(
					'snapshot_id'   => (int) $sid,
					'position'      => (int) $r['position'],
					'url'           => $r['url'],
					'url_hash'      => Database::url_hash( $r['url'] ),
					'domain'        => mb_substr( $h, 0, 190 ),
					'title'         => $r['title'],
					'snippet'       => $r['snippet'],
					'competitor_id' => isset( $comps[ $h ] ) ? $comps[ $h ] : null,
					'is_own'        => $h === $own ? 1 : 0,
				)
			);
		}
		return (int) $sid;
	}

	/** Background job: search the tracked queries within the monthly budget. */
	public function track( array $queries = array() ) {
		$queries = $queries ? $queries : $this->tracked_queries();
		$b       = $this->budget();
		$done    = 0;
		$errors  = array();
		foreach ( $queries as $q ) {
			if ( $b['limit'] && $done >= $b['left'] ) {
				break;
			}
			$this->plugin->queue->checkpoint();
			// Re-search a query at most once a day.
			$recent = $this->plugin->db->value( 'SELECT id FROM {serp_snapshots} WHERE project_id = %d AND query_hash = %s AND checked_at >= %s', array( $this->pid(), Database::hash( $q ), gmdate( 'Y-m-d H:i:s', time() - 20 * HOUR_IN_SECONDS ) ) );
			if ( $recent ) {
				continue;
			}
			$sid = $this->snapshot( $q );
			if ( is_wp_error( $sid ) ) {
				$errors[] = $sid->get_error_message();
				if ( count( $errors ) >= 3 ) {
					break;
				}
				continue;
			}
			$done++;
		}
		update_option( 'seolity_serp_last_track', array( 'at' => gmdate( 'c' ), 'searched' => $done, 'errors' => array_slice( array_unique( $errors ), 0, 3 ) ), false );
		return true;
	}

	/** Latest snapshot id per query (optionally only queries checked since a date). */
	private function latest_snapshots() {
		return $this->plugin->db->rows( 'SELECT s.* FROM {serp_snapshots} s JOIN (SELECT query_hash, MAX(id) id FROM {serp_snapshots} WHERE project_id = %d GROUP BY query_hash) l ON l.id = s.id ORDER BY s.query', array( $this->pid() ) );
	}

	/** Domains that appear most often in our tracked results — candidates to add as competitors. */
	public function suggestions( $limit = 15 ) {
		$snaps = $this->latest_snapshots();
		if ( ! $snaps ) {
			return array();
		}
		$ids   = implode( ',', array_map( 'intval', wp_list_pluck( $snaps, 'id' ) ) );
		$own   = self::host( home_url() );
		$known = array_keys( $this->competitor_map() );
		$rows  = $this->plugin->db->rows( "SELECT domain, COUNT(*) n, AVG(position) avg_pos, SUM(position <= 3) top3 FROM {serp_results} WHERE snapshot_id IN ($ids) GROUP BY domain ORDER BY n DESC, avg_pos ASC LIMIT %d", array( 60 ) ); // phpcs:ignore WordPress.DB
		$out   = array();
		foreach ( $rows as $r ) {
			if ( $r['domain'] === $own || in_array( $r['domain'], $known, true ) ) {
				continue;
			}
			$out[] = array( 'domain' => $r['domain'], 'queries' => (int) $r['n'], 'avg_position' => round( (float) $r['avg_pos'], 1 ), 'top3' => (int) $r['top3'], 'of' => count( $snaps ) );
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/** Our Search Console numbers for a query (28 days): position, impressions, page. */
	private function our_gsc( $query ) {
		$latest = (string) $this->plugin->db->value( 'SELECT MAX(date) FROM {gsc_page_query} WHERE project_id = %d', array( $this->pid() ) );
		if ( ! $latest ) {
			return null;
		}
		$from = gmdate( 'Y-m-d', strtotime( $latest . ' -27 days' ) );
		$r    = $this->plugin->db->row( 'SELECT SUM(clicks) clicks, SUM(impressions) impressions, SUM(position*impressions) pw FROM {gsc_page_query} WHERE project_id = %d AND query_hash = %s AND date >= %s', array( $this->pid(), Database::hash( $query ), $from ) );
		if ( ! $r || ! (int) $r['impressions'] ) {
			return null;
		}
		$page = $this->plugin->db->value( 'SELECT page FROM {gsc_page_query} WHERE project_id = %d AND query_hash = %s AND date >= %s GROUP BY page_hash, page ORDER BY SUM(impressions) DESC LIMIT 1', array( $this->pid(), Database::hash( $query ), $from ) );
		return array( 'clicks' => (int) $r['clicks'], 'impressions' => (int) $r['impressions'], 'position' => round( $r['pw'] / $r['impressions'], 1 ), 'page' => $page );
	}

	/**
	 * Monthly searches of a query, from our own impressions: when we rank on page 1 almost every
	 * search shows us, so impressions ≈ searches; otherwise it is only a lower bound.
	 */
	private function volume( $gsc ) {
		if ( ! $gsc ) {
			return array( 'value' => null, 'kind' => 'unknown' );
		}
		return $gsc['position'] <= 10 ? array( 'value' => $gsc['impressions'], 'kind' => 'measured' ) : array( 'value' => $gsc['impressions'], 'kind' => 'minimum' );
	}

	/** Every tracked query where the competitor appears: their page and position vs ours, trend. */
	public function overview( $cid ) {
		$db    = $this->plugin->db;
		$snaps = $this->latest_snapshots();
		$rows  = array();
		$stats = array( 'queries' => 0, 'above_us' => 0, 'below_us' => 0, 'only_them' => 0, 'est_clicks' => 0 );
		foreach ( $snaps as $s ) {
			$them = $db->row( 'SELECT position, url, title FROM {serp_results} WHERE snapshot_id = %d AND competitor_id = %d ORDER BY position ASC LIMIT 1', array( (int) $s['id'], (int) $cid ) );
			if ( ! $them ) {
				continue;
			}
			$prev     = $db->row( 'SELECT r.position FROM {serp_snapshots} s JOIN {serp_results} r ON r.snapshot_id = s.id AND r.competitor_id = %d WHERE s.project_id = %d AND s.query_hash = %s AND s.id < %d ORDER BY s.id DESC, r.position ASC LIMIT 1', array( (int) $cid, $this->pid(), $s['query_hash'], (int) $s['id'] ) );
			$gsc      = $this->our_gsc( $s['query'] );
			$our_pos  = null !== $s['own_position'] ? (int) $s['own_position'] : null;
			$vol      = $this->volume( $gsc );
			$est      = null !== $vol['value'] ? (int) round( $vol['value'] * Analytics::expected_ctr( (int) $them['position'] ) ) : null;
			$status   = null === $our_pos ? 'only_them' : ( (int) $them['position'] < $our_pos ? 'above_us' : 'below_us' );
			$stats['queries']++;
			$stats[ $status ]++;
			$stats['est_clicks'] += (int) $est;
			$last = $db->row( 'SELECT id, status, created_at FROM {serp_analyses} WHERE competitor_id = %d AND query_hash = %s ORDER BY id DESC LIMIT 1', array( (int) $cid, $s['query_hash'] ) );
			$rows[] = array(
				'query'          => $s['query'],
				'checked_at'     => $s['checked_at'],
				'their_position' => (int) $them['position'],
				'their_previous' => $prev ? (int) $prev['position'] : null,
				'their_url'      => $them['url'],
				'their_title'    => $them['title'],
				'our_position'   => $our_pos,
				'our_url'        => $s['own_url'] ? $s['own_url'] : ( $gsc ? $gsc['page'] : null ),
				'our_gsc'        => $gsc,
				'volume'         => $vol,
				'their_est_clicks' => $est,
				'status'         => $status,
				'analysis'       => $last,
			);
		}
		usort(
			$rows,
			static function ( $a, $b ) {
				return array( 'above_us' === $b['status'], (int) $b['their_est_clicks'] ) <=> array( 'above_us' === $a['status'], (int) $a['their_est_clicks'] );
			}
		);
		return array( 'stats' => $stats, 'queries' => $rows );
	}

	/** Estimated Search Console of the competitor: queries and pages with estimated clicks. */
	public function estimate( $cid ) {
		$ov    = $this->overview( $cid );
		$pages = array();
		foreach ( $ov['queries'] as $q ) {
			$u = $q['their_url'];
			if ( ! isset( $pages[ $u ] ) ) {
				$pages[ $u ] = array( 'url' => $u, 'title' => $q['their_title'], 'queries' => array(), 'est_clicks' => 0, 'est_impressions' => 0, 'best_position' => 100 );
			}
			$pages[ $u ]['queries'][]        = array( 'query' => $q['query'], 'position' => $q['their_position'] );
			$pages[ $u ]['est_clicks']      += (int) $q['their_est_clicks'];
			$pages[ $u ]['est_impressions'] += null !== $q['volume']['value'] && $q['their_position'] <= 10 ? (int) $q['volume']['value'] : 0;
			$pages[ $u ]['best_position']    = min( $pages[ $u ]['best_position'], $q['their_position'] );
		}
		usort(
			$pages,
			static function ( $a, $b ) {
				return $b['est_clicks'] <=> $a['est_clicks'];
			}
		);
		$measured = count(
			array_filter(
				$ov['queries'],
				static function ( $q ) {
					return 'measured' === $q['volume']['kind'];
				}
			)
		);
		return array(
			'queries'  => array_map(
				static function ( $q ) {
					return array( 'query' => $q['query'], 'position' => $q['their_position'], 'url' => $q['their_url'], 'est_impressions' => $q['their_position'] <= 10 ? $q['volume']['value'] : null, 'est_clicks' => $q['their_est_clicks'], 'volume_kind' => $q['volume']['kind'] );
				},
				$ov['queries']
			),
			'pages'    => array_values( $pages ),
			'totals'   => array( 'est_clicks' => $ov['stats']['est_clicks'], 'queries' => count( $ov['queries'] ), 'with_volume' => $measured ),
			'method'   => __( 'Estimate, not their real data: their position in live Google results × the click-through rate of that position × the monthly searches of the query (measured from your own impressions when you rank on page 1; otherwise a lower bound). Track more queries to widen the picture.', 'seolity' ),
		);
	}

	/** Compact on-page facts of a page (fetched live). */
	private static function page_facts( $url, array $res = null ) {
		if ( ! $res || 200 !== (int) $res['status'] ) {
			return array( 'url' => $url, 'status' => $res ? (int) $res['status'] : 0 );
		}
		$p = PageParser::parse( $res['body'], $url, $res['headers'] );
		$internal = count(
			array_filter(
				$p['links'],
				static function ( $l ) {
					return $l['internal'];
				}
			)
		);
		$faq = count(
			array_filter(
				array_merge( $p['h2'], $p['h3'] ),
				static function ( $h ) {
					return (bool) preg_match( '/[?؟]\s*$/u', $h );
				}
			)
		);
		return array(
			'url'              => $url,
			'status'           => 200,
			'title'            => $p['title'],
			'meta_description' => $p['meta_description'],
			'h1'               => $p['h1'],
			'h2'               => array_slice( $p['h2'], 0, 30 ),
			'h3'               => array_slice( $p['h3'], 0, 30 ),
			'word_count'       => (int) $p['word_count'],
			'schema_types'     => $p['schema_types'],
			'faq_headings'     => $faq,
			'internal_links_out' => $internal,
			'images'           => isset( $p['images'] ) ? count( (array) $p['images'] ) : null,
			'canonical'        => ! empty( $p['canonicals'] ) ? $p['canonicals'][0] : '',
			'robots'           => $p['robots'],
		);
	}

	public function start_compare( $cid, $query ) {
		if ( ! $this->plugin->ai->is_ready() ) {
			return new \WP_Error( 'seolity_ai', __( 'Connect an AI engine first (Settings → AI).', 'seolity' ), array( 'status' => 400 ) );
		}
		$id = $this->plugin->db->insert( 'serp_analyses', array( 'project_id' => $this->pid(), 'competitor_id' => (int) $cid, 'query' => mb_substr( $query, 0, 500 ), 'query_hash' => Database::hash( $query ), 'status' => 'running', 'created_at' => Database::now() ) );
		$aid = $this->plugin->activity->start( 'agent', __( 'Competitor analysis for a query', 'seolity' ), array( 'detail' => $query, 'dock' => true, 'status' => 'queued' ) );
		$job = $this->plugin->queue->push( 'competitor_compare', array( 'id' => (int) $id, 'activity_id' => $aid, 'user_id' => get_current_user_id() ), array( 'unique' => 'cmp_' . (int) $id, 'priority' => 0, 'max_attempts' => 1, 'timeout' => 900 ) );
		$this->plugin->activity->attach_job( $aid, $job );
		\SEOlity\Activity\AiJobs::run_after_response( $this->plugin );
		return $this->analysis( $id );
	}

	public function analysis( $id ) {
		$r = $this->plugin->db->row( 'SELECT * FROM {serp_analyses} WHERE id = %d', array( (int) $id ) );
		return $r ? Database::decode( $r, array( 'evidence', 'result' ) ) : null;
	}

	/** Queue handler: collect the evidence, ask the AI, store the result and put the steps on the roadmap. */
	public function compare( $id, $aid = 0 ) {
		$an = $this->analysis( $id );
		if ( ! $an || 'running' !== $an['status'] ) {
			return true;
		}
		$db    = $this->plugin->db;
		$act   = $this->plugin->activity;
		$query = $an['query'];
		$cid   = (int) $an['competitor_id'];
		$comp  = $db->row( 'SELECT name, domain FROM {competitors} WHERE id = %d', array( $cid ) );
		$act->step( (int) $aid, __( 'Reading the stored Google results and history…', 'seolity' ), 10 );
		$snaps = $db->rows( 'SELECT * FROM {serp_snapshots} WHERE project_id = %d AND query_hash = %s ORDER BY id DESC LIMIT 6', array( $this->pid(), Database::hash( $query ) ) );
		if ( ! $snaps ) {
			$sid = $this->snapshot( $query );
			if ( is_wp_error( $sid ) ) {
				return $this->fail( $id, $aid, $sid->get_error_message() );
			}
			$snaps = $db->rows( 'SELECT * FROM {serp_snapshots} WHERE id = %d', array( (int) $sid ) );
		}
		$latest  = $snaps[0];
		$results = $db->rows( 'SELECT position, url, domain, title, snippet, competitor_id, is_own FROM {serp_results} WHERE snapshot_id = %d ORDER BY position', array( (int) $latest['id'] ) );
		$them    = null;
		foreach ( $results as $r ) {
			if ( (int) $r['competitor_id'] === $cid && ! $them ) {
				$them = $r;
			}
		}
		$history = array();
		foreach ( $snaps as $s ) {
			$t         = $db->row( 'SELECT position, url FROM {serp_results} WHERE snapshot_id = %d AND competitor_id = %d ORDER BY position LIMIT 1', array( (int) $s['id'], $cid ) );
			$history[] = array( 'date' => $s['checked_at'], 'them' => $t ? (int) $t['position'] : null, 'their_url' => $t ? $t['url'] : null, 'us' => null !== $s['own_position'] ? (int) $s['own_position'] : null );
		}
		$gsc      = $this->our_gsc( $query );
		$our_url  = $latest['own_url'] ? $latest['own_url'] : ( $gsc ? $gsc['page'] : home_url( '/' ) );
		$act->step( (int) $aid, __( 'Fetching both pages live and comparing them…', 'seolity' ), 35 );
		$fetch    = array_filter( array( $them ? $them['url'] : null, $our_url, untrailingslashit( $our_url ) !== untrailingslashit( home_url( '/' ) ) ? home_url( '/' ) : null ) );
		$got      = Http::multi( array_values( $fetch ), 20 );
		$evidence = array(
			'query'              => $query,
			'competitor'         => $comp,
			'google_results'     => array_map(
				static function ( $r ) {
					return array( 'position' => (int) $r['position'], 'domain' => $r['domain'], 'url' => $r['url'], 'title' => $r['title'], 'snippet' => $r['snippet'], 'is_us' => (bool) $r['is_own'] );
				},
				$results
			),
			'serp_features'      => json_decode( (string) $latest['features'], true ),
			'people_also_ask'    => json_decode( (string) $latest['questions'], true ),
			'related_searches'   => json_decode( (string) $latest['related'], true ),
			'position_history'   => $history,
			'their_page'         => $them ? self::page_facts( $them['url'], isset( $got[ $them['url'] ] ) ? $got[ $them['url'] ] : null ) : 'the competitor is not in the top 20 for this query',
			'our_page'           => self::page_facts( $our_url, isset( $got[ $our_url ] ) ? $got[ $our_url ] : null ),
			'our_home_page'      => isset( $got[ home_url( '/' ) ] ) ? self::page_facts( home_url( '/' ), $got[ home_url( '/' ) ] ) : null,
			'our_search_console' => $this->plugin->gsc_insights->query_report( $query ),
		);
		$crawl = $db->row( 'SELECT inlinks, depth FROM {urls} WHERE project_id = %d AND url_hash = %s', array( $this->pid(), Database::url_hash( $our_url ) ) );
		if ( $crawl ) {
			$evidence['our_page']['internal_links_in'] = (int) $crawl['inlinks'];
			$evidence['our_page']['click_depth']       = (int) $crawl['depth'];
		}
		$db->update( 'serp_analyses', array( 'snapshot_id' => (int) $latest['id'], 'their_url' => $them ? $them['url'] : null, 'our_url' => $our_url, 'evidence' => wp_json_encode( $evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ), array( 'id' => (int) $id ) );
		$act->step( (int) $aid, __( 'AI is analyzing why they rank where they do…', 'seolity' ), 60 );
		$res = $this->plugin->ai->run( 'competitor_query_compare', $evidence, array( 'knowledge_query' => $query ) );
		if ( is_wp_error( $res ) ) {
			return $this->fail( $id, $aid, $res->get_error_message() );
		}
		$d = $res['data'];
		$db->update( 'serp_analyses', array( 'status' => 'done', 'result' => wp_json_encode( $d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ), array( 'id' => (int) $id ) );
		// The steps become one roadmap item (category "competitors"), linked to this analysis.
		$steps = array();
		foreach ( (array) $d['steps'] as $i => $s ) {
			$steps[] = ( $i + 1 ) . '. ' . $s['step'] . ( $s['page'] ? ' (' . $s['page'] . ')' : '' );
		}
		$top = $d['steps'] ? $d['steps'][0]['priority'] : 'medium';
		$this->plugin->recommendations->add(
			array(
				'type'       => 'competitor_query_gap',
				'key'        => 'cq:' . $cid . ':' . Database::hash( $query ),
				'url'        => (string) $d['target_page'],
				/* translators: 1 competitor 2 query */
				'title'      => sprintf( __( 'Beat %1$s for “%2$s”', 'seolity' ), $comp ? $comp['name'] : '', $query ),
				'reason'     => $d['verdict'] . "\n" . implode( "\n", $steps ),
				'evidence'   => array( 'source' => 'competitor_compare', 'analysis_id' => (int) $id, 'competitor_id' => $cid, 'query' => $query ),
				'action'     => array( 'type' => 'route', 'route' => '#/competitor?id=' . $cid . '&q=' . rawurlencode( $query ) ),
				'impact'     => 'critical' === $top ? 80 : ( 'high' === $top ? 65 : 45 ),
				'effort'     => 40,
				'confidence' => max( 20, min( 95, (int) $d['confidence'] ) ),
			)
		);
		/* translators: %s: query */
		$act->finish( (int) $aid, 'done', sprintf( __( 'Analysis ready for “%s”', 'seolity' ), $query ), array( 'summary' => $d['verdict'], 'route' => '#/competitor?id=' . $cid . '&q=' . rawurlencode( $query ) ) );
		return true;
	}

	private function fail( $id, $aid, $msg ) {
		$this->plugin->db->update( 'serp_analyses', array( 'status' => 'failed', 'error' => $msg ), array( 'id' => (int) $id ) );
		$this->plugin->activity->finish( (int) $aid, 'failed', $msg );
		return true;
	}

	/** AI: which queries does each of the competitor's pages target? (Candidates to track.) */
	public function discover( $cid ) {
		$pages = $this->plugin->db->rows( "SELECT url, title, h1, headings FROM {competitor_pages} WHERE competitor_id = %d AND status_code = 200 AND title IS NOT NULL ORDER BY lastmod DESC, id DESC LIMIT 40", array( (int) $cid ) );
		if ( ! $pages ) {
			return new \WP_Error( 'seolity_comp', __( 'Crawl the competitor first (Analyze), then discover its queries.', 'seolity' ), array( 'status' => 400 ) );
		}
		$in = array_map(
			static function ( $p ) {
				$h = json_decode( (string) $p['headings'], true );
				return array( 'url' => $p['url'], 'title' => $p['title'], 'h1' => $p['h1'], 'h2' => isset( $h['h2'] ) ? array_slice( (array) $h['h2'], 0, 8 ) : array() );
			},
			$pages
		);
		$res = $this->plugin->ai->run( 'competitor_query_discovery', array( 'pages' => $in ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$tracked = $this->tracked_queries();
		$out     = array();
		foreach ( (array) $res['data']['pages'] as $p ) {
			foreach ( (array) $p['queries'] as $q ) {
				$out[] = array( 'query' => $q, 'page' => $p['url'], 'tracked' => in_array( $q, $tracked, true ) );
			}
		}
		update_option( 'seolity_comp_candidates_' . (int) $cid, $out, false );
		return $out;
	}

	public function analyses( $cid ) {
		return array_map(
			static function ( $r ) {
				return Database::decode( $r, array( 'result' ) );
			},
			$this->plugin->db->rows( 'SELECT a.id, a.query, a.status, a.error, a.their_url, a.our_url, a.result, a.created_at FROM {serp_analyses} a JOIN (SELECT MAX(id) id FROM {serp_analyses} WHERE competitor_id = %d GROUP BY query_hash) l ON l.id = a.id ORDER BY a.id DESC', array( (int) $cid ) )
		);
	}
}
