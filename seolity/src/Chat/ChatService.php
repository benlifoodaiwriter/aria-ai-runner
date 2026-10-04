<?php
/**
 * AI SEO Chat (section 31): answers grounded in the site's real data.
 *
 * A lightweight retrieval step detects what the question is about (a URL,
 * product, page title, traffic, competitors, errors…) and attaches only the
 * relevant data to the prompt, so answers cite real numbers.
 *
 * @package SEOlity
 */

namespace SEOlity\Chat;

use SEOlity\Analysis\Text;
use SEOlity\Core\Database;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class ChatService {

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function ask( $message, $conversation_id = '' ) {
		$message = trim( sanitize_textarea_field( $message ) );
		if ( '' === $message ) {
			return new \WP_Error( 'seolity_chat', __( 'Please type a question.', 'seolity' ), array( 'status' => 400 ) );
		}
		$conversation_id = preg_match( '/^[a-f0-9]{32}$/', (string) $conversation_id ) ? $conversation_id : md5( wp_generate_password( 20, false ) . microtime() );
		$history         = array_reverse(
			$this->plugin->db->rows(
				'SELECT role, content FROM {chat} WHERE user_id = %d AND conversation_id = %s ORDER BY id DESC LIMIT 8',
				array( get_current_user_id(), $conversation_id )
			)
		);
		$context = $this->context( $message );
		$this->store( $conversation_id, 'user', $message );
		$res = $this->plugin->ai->run(
			'chat',
			array( 'question' => $message, 'site_data' => $context ),
			array(
				'history'         => array_map( static function ( $h ) {
					return array( 'role' => $h['role'], 'content' => mb_substr( $h['content'], 0, 4000 ) );
				}, $history ),
				'knowledge_query' => $message,
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$this->store( $conversation_id, 'assistant', $res['data']['answer'], array( 'data_used' => $res['data']['data_used'], 'actions' => $res['data']['suggested_actions'], 'meta' => $res['meta'] ) );
		return array(
			'conversation_id' => $conversation_id,
			'answer'          => $res['data']['answer'],
			'data_used'       => $res['data']['data_used'],
			'actions'         => array_values( array_filter( $res['data']['suggested_actions'], static function ( $a ) {
				return 0 === strpos( (string) $a['route'], '#/' );
			} ) ),
			'context_keys'    => array_keys( $context ),
			'meta'            => $res['meta'],
		);
	}

	private function store( $cid, $role, $content, array $meta = array() ) {
		$this->plugin->db->insert(
			'chat',
			array(
				'project_id'      => $this->plugin->db->project_id(),
				'user_id'         => get_current_user_id(),
				'conversation_id' => $cid,
				'role'            => $role,
				'content'         => $content,
				'meta'            => $meta,
				'created_at'      => Database::now(),
			)
		);
	}

	/** Retrieve data relevant to the question. */
	private function context( $q ) {
		$p   = $this->plugin;
		$n   = Text::normalize( $q );
		$ctx = array(
			'site'          => array( 'name' => get_bloginfo( 'name' ), 'url' => home_url( '/' ), 'woocommerce' => class_exists( 'WooCommerce' ) ),
			'health'        => array_intersect_key( $p->health->cached(), array_flip( array( 'score', 'grade', 'excluded' ) ) ),
			'gsc_connected' => $p->gsc_oauth->is_connected(),
		);
		$has_gsc = $p->gsc_oauth->is_connected() && $p->gsc_analytics->has_data();
		if ( $has_gsc ) {
			$o                 = $p->gsc_analytics->overview( 28 );
			$ctx['gsc_28d']    = array( 'current' => $o['current'], 'previous' => $o['previous'], 'delta' => $o['delta'], 'period' => $o['period'] );
		}

		// A URL or a page/product name in the question?
		$object = null;
		if ( preg_match( '#https?://[^\s]+#', $q, $m ) ) {
			$object = $m[0];
		} else {
			$found = get_posts( array( 'post_type' => array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) ), 'post_status' => 'publish', 's' => mb_substr( $q, 0, 80 ), 'posts_per_page' => 1, 'fields' => 'ids' ) );
			if ( $found ) {
				$object = get_permalink( $found[0] );
			}
		}
		if ( $object ) {
			$row = $p->db->row( 'SELECT id, object_type, object_id, status_code, indexable, title, meta_description, word_count, inlinks, depth FROM {urls} WHERE project_id = %d AND url_hash = %s', array( $p->db->project_id(), Database::hash( \SEOlity\Crawler\PageParser::normalize_url( $object ) ) ) );
			$ctx['page'] = array(
				'url'     => $object,
				'crawl'   => $row,
				'issues'  => $row ? $p->db->rows( "SELECT check_code, severity FROM {issues} WHERE url_id = %d AND status = 'open'", array( (int) $row['id'] ) ) : array(),
				'gsc_28d' => $has_gsc ? $p->gsc_analytics->page_metrics( $object, 28 ) : null,
				'gsc_prev_28d' => $has_gsc ? $p->gsc_analytics->page_window( $object, gmdate( 'Y-m-d', strtotime( $p->gsc_analytics->latest_date() . ' -55 days' ) ), gmdate( 'Y-m-d', strtotime( $p->gsc_analytics->latest_date() . ' -28 days' ) ) ) : null,
				'queries' => $has_gsc ? $p->gsc_analytics->page_queries( $object, 90, 15 ) : array(),
				'changes' => $row && $row['object_id'] ? $p->db->rows( "SELECT field, status, applied_at, experiment_id FROM {changes} WHERE object_type = %s AND object_id = %d ORDER BY id DESC LIMIT 10", array( $row['object_type'], (int) $row['object_id'] ) ) : array(),
				'recommendations' => $row && $row['object_id'] ? array_map( static function ( $r ) {
					return array( 'title' => $r['title'], 'reason' => $r['reason'] );
				}, $p->recommendations->query( array( 'object_type' => $row['object_type'], 'object_id' => (int) $row['object_id'], 'limit' => 5 ) ) ) : array(),
			);
		}
		if ( $has_gsc && preg_match( '/traffic|drop|decline|sales|فروش|ترافیک|افت|کاهش|کم شده|ریزش|بازدید|رشد|organic|ارگانیک/u', $n ) ) {
			$ctx['losers']        = array_slice( $p->gsc_analytics->movers( 'page', 28, 10 )['losers'], 0, 10 );
			$ctx['lost_keywords'] = $p->gsc_analytics->keyword_changes( 28, 15 )['lost'];
			$ctx['recent_changes'] = $p->db->rows( "SELECT object_type, object_id, field, applied_at, url FROM {changes} WHERE project_id = %d AND status = 'applied' ORDER BY applied_at DESC LIMIT 10", array( $p->db->project_id() ) );
			$ctx['alerts']        = $p->db->rows( 'SELECT type, title, created_at FROM {alerts} WHERE project_id = %d ORDER BY id DESC LIMIT 10', array( $p->db->project_id() ) );
		}
		if ( preg_match( '/competitor|rival|رقیب|رقبا/u', $n ) ) {
			$ctx['competitors'] = array_map(
				static function ( $c ) {
					$s = json_decode( (string) $c['summary'], true );
					return array( 'name' => $c['name'], 'domain' => $c['domain'], 'summary' => isset( $s['ai'] ) ? $s['ai'] : null, 'pages' => isset( $s['pages'] ) ? $s['pages'] : null, 'new_pages' => isset( $s['new_pages'] ) ? $s['new_pages'] : null );
				},
				$p->db->rows( "SELECT name, domain, summary FROM {competitors} WHERE project_id = %d AND status = 'active'", array( $p->db->project_id() ) )
			);
		}
		if ( preg_match( '/today|priorit|should i|what to do|امروز|اولویت|چه کار|کدام صفحات|بهینه کنم/u', $n ) || empty( $ctx['page'] ) ) {
			$ctx['top_recommendations'] = array_map( static function ( $r ) {
				return array( 'title' => $r['title'], 'priority' => $r['priority'], 'reason' => $r['reason'], 'url' => $r['url'] );
			}, $p->recommendations->query( array( 'limit' => 10 ) ) );
			$ctx['doctor'] = array_slice( array_map( static function ( $d ) {
				return array( 'title' => $d['title'], 'priority' => $d['priority'] );
			}, $p->doctor->diagnose()['diagnoses'] ), 0, 10 );
		}
		if ( preg_match( '/ctr|click.?through|کلیک|نرخ کلیک/u', $n ) && $has_gsc ) {
			$ctx['ctr_opportunities'] = array_slice( $p->gsc_analytics->ctr_opportunities( 28, 10 ), 0, 10 );
		}
		if ( preg_match( '/redirect|404|ریدایرکت|خطا/u', $n ) ) {
			$ctx['not_found'] = $p->db->rows( "SELECT url, hits, suggested_target FROM {not_found} WHERE project_id = %d AND status = 'open' ORDER BY hits DESC LIMIT 10", array( $p->db->project_id() ) );
		}
		$ctx['learnings'] = $p->learning->prompt_insights();
		return $ctx;
	}

	public function history( $conversation_id ) {
		return array_map(
			static function ( $r ) {
				return Database::decode( $r, array( 'meta' ) );
			},
			$this->plugin->db->rows( 'SELECT role, content, meta, created_at FROM {chat} WHERE user_id = %d AND conversation_id = %s ORDER BY id ASC LIMIT 100', array( get_current_user_id(), (string) $conversation_id ) )
		);
	}

	public function conversations() {
		return $this->plugin->db->rows(
			"SELECT conversation_id, MIN(created_at) started, MAX(created_at) updated, SUBSTRING_INDEX(GROUP_CONCAT(IF(role = 'user', content, NULL) ORDER BY id SEPARATOR '\n'), '\n', 1) first_question FROM {chat} WHERE user_id = %d GROUP BY conversation_id ORDER BY updated DESC LIMIT 30",
			array( get_current_user_id() )
		);
	}
}
