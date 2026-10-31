<?php
/**
 * Everything the AI chat can look at: a compact snapshot of the whole site and plugin, a map of
 * every admin section (so answers can link straight to the right page and tab), and data tools —
 * Search Console per query/page, audits, roadmap, competitors and Google / AI search results,
 * duplicates, indexability, 404s, experiments, sitemap, settings and connections.
 *
 * @package SEOlity
 */

namespace SEOlity\Chat;

use SEOlity\Core\Database;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class Toolbox {

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/** Tools the planner may call: name => [argument, description]. */
	public static function catalog() {
		return array(
			'gsc_query'     => array( 'query', 'Search Console history of one search query: weekly clicks/impressions/position, which of our pages ranked when, swaps, drops, edits around them.' ),
			'gsc_page'      => array( 'url', 'Search Console history of one page: weekly series and its queries now vs. before.' ),
			'gsc_overview'  => array( '', 'Site-wide Search Console: last 28 days vs previous, top queries and pages, winners and losers.' ),
			'find_pages'    => array( 'text', 'Find pages / posts / products of the site by words in their title.' ),
			'page_audit'    => array( 'url', 'Everything about one page: crawl data, open issues, SEO title/description/focus keyword, edits and their measured effect.' ),
			'roadmap'       => array( '', 'The SEO roadmap: problem categories in recommended order and the most important open items.' ),
			'issues'        => array( 'category', 'Open issues of one category (indexability, technical, 404, duplicates, onpage, links, content, products, competitors).' ),
			'audit'         => array( '', 'Site audit summary: health score and the most frequent problems found by the crawler.' ),
			'indexability'  => array( '', 'Indexability & crawl-budget findings (templates, attachments, archives, search, tags, parameters).' ),
			'duplicates'    => array( '', 'Duplicate / cannibalizing article groups.' ),
			'not_found'     => array( '', '404 errors and redirects.' ),
			'competitors'   => array( '', 'Competitors with where they rank above / below us.' ),
			'competitor'    => array( 'name', 'One competitor in detail: queries, positions, pages, estimated traffic, analyses.' ),
			'serp'          => array( 'query', 'Stored live Google results and AI-search answers for one query.' ),
			'ai_visibility' => array( '', 'Whether AI answer engines (Gemini, Claude, ChatGPT, Perplexity) cite the site for the tracked queries.' ),
			'experiments'   => array( '', 'Measured effect of recent edits (positive / negative / neutral), per query.' ),
			'sitemap'       => array( '', 'XML sitemap: URLs listed, indexable vs not, problems.' ),
			'settings'      => array( '', 'All plugin settings (no secrets) and the state of every connection (AI, Search Console, Google results, GitHub, Cloudflare, Rank Math).' ),
			'activity'      => array( '', 'Recent background tasks and failures.' ),
		);
	}

	/** Every admin section with what it contains — the AI links to these. */
	public static function navigation() {
		$routes = array(
			'#/dashboard'            => 'Dashboard: health score, traffic, alerts',
			'#/actions'              => 'SEO roadmap: every problem once, in fix order; full-site analysis; AI do-it',
			'#/ai'                   => 'AI SEO chat',
			'#/activity'             => 'Live tasks, completed work, task queue (cancel / retry)',
			'#/changes'              => 'AI changes with before/after, approve, undo',
			'#/experiments'          => 'Measured effect of every edit; edit-history analysis',
			'#/audit'                => 'Site audit (crawler) and SEO doctor',
			'#/gsc'                  => 'Search Console data: queries, pages, countries, devices',
			'#/competitors'          => 'Competitors, Google results database, AI-search visibility, results sources',
			'#/competitor?id=ID'     => 'One competitor panel (queries above/below us, estimated Search Console, per-query analysis)',
			'#/knowledge'            => 'SEO knowledge base',
			'#/content'              => 'Content optimizer and content analysis',
			'#/duplicates'           => 'Duplicate & cannibalizing articles, merge workflow',
			'#/products'             => 'WooCommerce product SEO',
			'#/images'               => 'Image ALT texts and image SEO',
			'#/keywords'             => 'Keywords and keyword research',
			'#/rankmath'             => 'Rank Math & focus keywords',
			'#/links'                => 'Internal links, orphan pages, link suggestions',
			'#/redirects'            => 'Redirects (Rank Math or SEOlity), chains, loops',
			'#/404'                  => '404 monitor, bulk redirects, AI review (301 / 410 / keep)',
			'#/indexability'         => 'Indexability & crawl-budget audit with fixes',
			'#/schema'               => 'Schema / structured data',
			'#/sitemap'              => 'Site map explorer: every URL, indexable or not, sitemap health',
			'#/robots'               => 'robots.txt',
			'#/reports'              => 'Reports',
			'#/alerts'               => 'Alerts',
			'#/network'              => 'Server & speed: AI route, official CLI install',
			'#/subscription'         => 'Subscription AI setup (Claude / ChatGPT plans)',
			'#/github'               => 'GitHub & data archive',
			'#/cloudflare'           => 'Cloudflare relay',
			'#/guide'                => 'Connection guide (how to get every key)',
			'#/settings?tab=ai'      => 'Settings → AI: connections, models, AI power level',
			'#/settings?tab=general' => 'Settings → General',
			'#/settings?tab=crawler' => 'Settings → Crawler & audits (Google results source keys, SERP budget)',
			'#/settings?tab=technical' => 'Settings → Technical SEO (sitemap, redirects, 404 logging)',
			'#/settings?tab=schema'  => 'Settings → Schema & entity',
			'#/settings?tab=reports' => 'Settings → Alerts & reports',
			'#/settings?tab=privacy' => 'Settings → Privacy & data',
			'#/automation'           => 'Automation rules',
		);
		$out = array();
		foreach ( $routes as $r => $what ) {
			$out[] = array( 'link' => $r, 'contains' => $what );
		}
		return $out;
	}

	/** Compact state of everything — always in the chat context. */
	public function snapshot() {
		$p   = $this->plugin;
		$db  = $p->db;
		$pid = $db->project_id();
		$types = array();
		foreach ( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) as $t ) {
			$n = (int) wp_count_posts( $t )->publish;
			if ( $n ) {
				$types[ $t ] = $n;
			}
		}
		$board = array();
		foreach ( $p->recommendations->board() as $c ) {
			$board[ $c['id'] ] = array( 'open' => $c['open'], 'done' => $c['done'], 'step' => $c['position'] );
		}
		$health = $p->health->cached();
		$prov   = (string) $p->settings->get( 'ai_provider' );
		return array(
			'site'               => array( 'name' => get_bloginfo( 'name' ), 'url' => home_url( '/' ), 'language' => get_locale(), 'published' => $types, 'woocommerce' => class_exists( 'WooCommerce' ), 'seo_plugin' => $p->meta->engine() ),
			'health'             => array( 'score' => isset( $health['score'] ) ? $health['score'] : null, 'grade' => isset( $health['grade'] ) ? $health['grade'] : null ),
			'crawl'              => array( 'urls' => (int) $db->value( 'SELECT COUNT(*) FROM {urls} WHERE project_id = %d', array( $pid ) ), 'last_audit' => $db->value( "SELECT finished_at FROM {audits} WHERE project_id = %d AND status = 'completed' ORDER BY id DESC LIMIT 1", array( $pid ) ) ),
			'roadmap'            => $board,
			'search_console'     => array( 'connected' => $p->gsc_oauth->is_connected(), 'data' => $p->gsc_insights->coverage() ),
			'not_found_open'     => (int) $db->value( "SELECT COUNT(*) FROM {not_found} WHERE project_id = %d AND status = 'open'", array( $pid ) ),
			'redirects_active'   => (int) $db->value( "SELECT COUNT(*) FROM {redirects} WHERE project_id = %d AND status IN ('active','external')", array( $pid ) ),
			'competitors'        => (int) $db->value( "SELECT COUNT(*) FROM {competitors} WHERE project_id = %d AND status = 'active'", array( $pid ) ),
			'google_results'     => array( 'source' => $p->serp->source(), 'automatic' => $p->serp->available(), 'tracked_queries' => count( $p->intel->tracked_queries() ), 'budget' => $p->intel->budget() ),
			'ai_search_engines'  => $p->ai_visibility->enabled(),
			'experiments'        => $db->rows( "SELECT outcome, COUNT(*) n FROM {experiments} WHERE project_id = %d AND status = 'evaluated' GROUP BY outcome", array( $pid ) ),
			'ai'                 => array( 'provider' => $prov, 'model' => (string) $p->settings->get( 'ai_model_' . $prov ), 'power' => \SEOlity\AI\Effort::get( $prov ), 'route' => $p->gateway->current() ),
			'rank_math'          => $p->rankmath->active(),
		);
	}

	/** Planner gets this list; execute() runs the chosen ones. */
	public function execute( $name, $arg = '' ) {
		$p   = $this->plugin;
		$db  = $p->db;
		$pid = $db->project_id();
		$arg = trim( (string) $arg );
		switch ( $name ) {
			case 'gsc_query':
				return $p->gsc_insights->query_report( $arg ) ? $p->gsc_insights->query_report( $arg ) : array( 'matched' => $p->gsc_insights->for_question( $arg ) );
			case 'gsc_page':
				return $p->gsc_insights->page_report( $this->resolve_url( $arg ) );
			case 'gsc_overview':
				if ( ! $p->gsc_analytics->has_data() ) {
					return array( 'note' => 'Search Console is not connected or has no data yet — connect it at #/gsc.' );
				}
				$o = $p->gsc_analytics->overview( 28 );
				return array( 'last_28_days' => $o['current'], 'previous' => $o['previous'], 'delta' => $o['delta'], 'top_queries' => array_slice( $p->gsc_analytics->top_queries( 28, 25 ), 0, 25 ), 'top_pages' => array_slice( $p->gsc_analytics->top_pages( 28, 20 ), 0, 20 ), 'movers' => $p->gsc_analytics->movers( 'page', 28, 10 ) );
			case 'find_pages':
				return array_map(
					static function ( $id ) {
						return array( 'id' => $id, 'title' => get_the_title( $id ), 'url' => get_permalink( $id ), 'type' => get_post_type( $id ), 'edit' => admin_url( 'post.php?post=' . $id . '&action=edit' ) );
					},
					get_posts( array( 'post_type' => array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) ), 'post_status' => 'publish', 's' => $arg, 'posts_per_page' => 10, 'fields' => 'ids' ) )
				);
			case 'page_audit':
				$url = $this->resolve_url( $arg );
				$row = $db->row( 'SELECT id, object_type, object_id, status_code, indexable, title, meta_description, h1, word_count, inlinks, depth, canonical FROM {urls} WHERE project_id = %d AND url_hash = %s', array( $pid, Database::url_hash( $url ) ) );
				$out = array( 'url' => $url, 'crawl' => $row );
				if ( $row ) {
					$out['open_issues'] = $db->rows( "SELECT check_code, severity FROM {issues} WHERE url_id = %d AND status = 'open'", array( (int) $row['id'] ) );
					if ( 'post' === $row['object_type'] && $row['object_id'] ) {
						$out['seo'] = array( 'title' => $p->meta->get_post( (int) $row['object_id'], 'meta_title' ), 'description' => $p->meta->get_post( (int) $row['object_id'], 'meta_description' ), 'focus_keyword' => $p->meta->get_post( (int) $row['object_id'], 'focus_keyword' ), 'noindex' => $p->meta->get_post( (int) $row['object_id'], 'noindex' ) );
						$out['edit'] = admin_url( 'post.php?post=' . (int) $row['object_id'] . '&action=edit' );
					}
				}
				$out['edit_outcomes'] = $p->gsc_insights->edit_outcomes( array( $url ) );
				return $out;
			case 'roadmap':
				return array( 'categories' => $p->recommendations->board(), 'top_items' => array_map( array( $this, 'issue_brief' ), $p->recommendations->items( array( 'status' => 'active', 'per_page' => 25 ) )['items'] ) );
			case 'issues':
				return array_map( array( $this, 'issue_brief' ), $p->recommendations->items( array( 'category' => sanitize_key( $arg ), 'status' => 'active', 'per_page' => 40 ) )['items'] );
			case 'audit':
				return array( 'health' => $p->health->cached(), 'by_check' => $db->rows( "SELECT i.check_code, i.severity, COUNT(*) n FROM {issues} i WHERE i.project_id = %d AND i.status = 'open' GROUP BY i.check_code, i.severity ORDER BY n DESC LIMIT 30", array( $pid ) ) );
			case 'indexability':
				return $p->indexability->last();
			case 'duplicates':
				$d = $p->duplicates->last();
				return $d ? array( 'at' => $d['at'], 'clusters' => array_map( static function ( $c ) {
					return array( 'kind' => $c['kind'], 'severity' => isset( $c['severity'] ) ? $c['severity'] : null, 'strategy' => isset( $c['strategy'] ) ? $c['strategy'] : null, 'posts' => array_map( static function ( $x ) {
						return array( 'title' => $x['title'], 'url' => $x['url'], 'clicks' => $x['clicks'] );
					}, $c['posts'] ) );
				}, array_slice( $d['clusters'], 0, 15 ) ) ) : array( 'note' => 'No scan yet — run it at #/duplicates.' );
			case 'not_found':
				return array( 'open' => $db->rows( "SELECT url, hits, referrer, suggested_target, last_seen FROM {not_found} WHERE project_id = %d AND status = 'open' ORDER BY hits DESC LIMIT 25", array( $pid ) ), 'redirects' => $db->rows( "SELECT source, target, type, hits, status FROM {redirects} WHERE project_id = %d ORDER BY id DESC LIMIT 15", array( $pid ) ) );
			case 'competitors':
				$out = array();
				foreach ( $db->rows( "SELECT id, name, domain, last_analyzed FROM {competitors} WHERE project_id = %d AND status = 'active'", array( $pid ) ) as $c ) {
					$ov    = $p->intel->overview( (int) $c['id'] );
					$out[] = $c + array( 'panel' => '#/competitor?id=' . $c['id'], 'stats' => $ov['stats'], 'top_queries_above_us' => array_slice( array_values( array_filter( $ov['queries'], static function ( $q ) {
						return 'above_us' === $q['status'];
					} ) ), 0, 8 ) );
				}
				return $out;
			case 'competitor':
				$c = $db->row( "SELECT id, name, domain FROM {competitors} WHERE project_id = %d AND (name LIKE %s OR domain LIKE %s) LIMIT 1", array( $pid, '%' . $db->wpdb->esc_like( $arg ) . '%', '%' . $db->wpdb->esc_like( $arg ) . '%' ) );
				if ( ! $c ) {
					return array( 'note' => 'No competitor with that name — add it at #/competitors.' );
				}
				return array( 'competitor' => $c, 'panel' => '#/competitor?id=' . $c['id'], 'overview' => $p->intel->overview( (int) $c['id'] ), 'estimate' => $p->intel->estimate( (int) $c['id'] )['totals'], 'analyses' => $p->intel->analyses( (int) $c['id'] ) );
			case 'serp':
				$snaps = $db->rows( 'SELECT s.id, s.engine, s.checked_at, s.own_position, s.answer, s.questions FROM {serp_snapshots} s JOIN (SELECT engine, MAX(id) id FROM {serp_snapshots} WHERE project_id = %d AND query_hash = %s GROUP BY engine) l ON l.id = s.id', array( $pid, Database::hash( $arg ) ) );
				foreach ( $snaps as &$s ) {
					$s['results'] = $db->rows( 'SELECT position, domain, url, title, is_own FROM {serp_results} WHERE snapshot_id = %d ORDER BY position LIMIT 20', array( (int) $s['id'] ) );
					$s['answer']  = mb_substr( (string) $s['answer'], 0, 800 );
				}
				return $snaps ? $snaps : array( 'note' => 'This query has not been checked in Google yet — add it at #/competitors (Google results database).' );
			case 'ai_visibility':
				return $p->ai_visibility->overview();
			case 'experiments':
				return $db->rows( "SELECT name, url, change_type, started_at, outcome, confidence, reason FROM {experiments} WHERE project_id = %d AND status = 'evaluated' ORDER BY started_at DESC LIMIT 20", array( $pid ) );
			case 'sitemap':
				try {
					return $p->sitemap_map->summary();
				} catch ( \Throwable $e ) {
					return array( 'note' => 'Open #/sitemap to build the site map.' );
				}
			case 'settings':
				return $this->settings();
			case 'activity':
				return $db->rows( 'SELECT kind, title, status, detail, started_at, finished_at FROM {activity} WHERE project_id = %d ORDER BY id DESC LIMIT 20', array( $pid ) );
		}
		return array( 'error' => 'unknown tool' );
	}

	private function issue_brief( $r ) {
		$a = is_array( $r['action'] ) ? $r['action'] : array();
		return array( 'title' => $r['title'], 'category' => $r['category'], 'priority' => $r['priority'], 'status' => $r['status'], 'url' => $r['url'], 'reason' => mb_substr( (string) $r['reason'], 0, 300 ), 'open_in' => isset( $a['route'] ) ? $a['route'] : '#/actions' );
	}

	private function resolve_url( $arg ) {
		if ( preg_match( '#^https?://#', $arg ) ) {
			return $arg;
		}
		if ( '' === $arg || '/' === $arg || preg_match( '/^(home|صفحه اصلی)$/u', $arg ) ) {
			return home_url( '/' );
		}
		if ( 0 === strpos( $arg, '/' ) ) {
			return home_url( $arg );
		}
		$found = get_posts( array( 'post_type' => 'any', 'post_status' => 'publish', 's' => $arg, 'posts_per_page' => 1, 'fields' => 'ids' ) );
		return $found ? (string) get_permalink( $found[0] ) : home_url( '/' );
	}

	/** All settings without secrets, plus which connections are set up. */
	public function settings() {
		$p    = $this->plugin;
		$all  = (array) get_option( \SEOlity\Core\Settings::OPTION, array() );
		$safe = array();
		foreach ( $all as $k => $v ) {
			if ( ! preg_match( '/key|secret|token|password|auth/i', $k ) ) {
				$safe[ $k ] = is_string( $v ) ? mb_substr( $v, 0, 200 ) : $v;
			}
		}
		$secrets = array();
		foreach ( \SEOlity\Core\Settings::SECRET_FIELDS as $f ) {
			$secrets[ $f ] = (bool) $p->settings->secret( $f );
		}
		return array( 'settings' => $safe, 'keys_saved' => $secrets, 'ai_connections' => array_map( static function ( $c ) {
			return array_intersect_key( $c, array_flip( array( 'id', 'label', 'active', 'status', 'model', 'error' ) ) );
		}, array_merge( $p->aiproviders->all()['api'], $p->aiproviders->all()['subscription'] ) ), 'search_console' => $p->gsc_oauth->is_connected(), 'google_results_source' => $p->serp->source() );
	}

	/** Words in a question → the tools to run when no AI planner is used. */
	public function by_keywords( $q ) {
		$map = array(
			'gsc_overview'  => '/ترافیک|کلیک|نمایش|traffic|clicks|impressions|افت|رشد|drop|growth/u',
			'roadmap'       => '/اولویت|مسیر راه|چه کار کنم|roadmap|priorit|what should/u',
			'audit'         => '/ممیزی|خطا|مشکل|audit|error|problem|سلامت|health/u',
			'indexability'  => '/ایندکس|بودجه خزش|index|crawl/u',
			'duplicates'    => '/تکراری|هم ?نوع ?خواری|cannibal|duplicate/u',
			'not_found'     => '/404|ریدایرکت|redirect|۴۰۴/u',
			'competitors'   => '/رقیب|رقبا|competitor/u',
			'ai_visibility' => '/هوش مصنوعی.*(جستجو|سرچ)|ai search|chatgpt|perplexity|gemini|overview/u',
			'experiments'   => '/ویرایش|آزمایش|تغییر|experiment|edit/u',
			'sitemap'       => '/نقشه سایت|سایت ?مپ|sitemap/u',
			'settings'      => '/تنظیم|اتصال|کلید|setting|connect|key|api|مدل|model/u',
			'activity'      => '/وظیف|تسک|task|در حال اجرا|running|failed|ناموفق/u',
		);
		$out = array();
		foreach ( $map as $tool => $re ) {
			if ( preg_match( $re, mb_strtolower( (string) $q ) ) ) {
				$out[] = array( 'tool' => $tool, 'arg' => '' );
			}
		}
		return $out;
	}
}
