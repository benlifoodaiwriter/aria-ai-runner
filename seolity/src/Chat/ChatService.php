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

	/**
	 * Ask a question. The question and an answer placeholder are stored first, so the answer
	 * also appears after a reload; it is then answered right away (direct / Cloudflare) or, when
	 * the AI answers through GitHub, by a queue job that fills the placeholder later.
	 *
	 * @param array $opts page (URL or post id to discuss), regenerate (message id).
	 */
	public function ask( $message, $conversation_id = '', array $opts = array() ) {
		$message = trim( sanitize_textarea_field( $message ) );
		if ( '' === $message ) {
			return new \WP_Error( 'seolity_chat', __( 'Please type a question.', 'seolity' ), array( 'status' => 400 ) );
		}
		$conversation_id = preg_match( '/^[a-f0-9]{32}$/', (string) $conversation_id ) ? $conversation_id : md5( wp_generate_password( 20, false ) . microtime() );
		$page            = isset( $opts['page'] ) ? $this->page_url( $opts['page'] ) : '';
		$this->store( $conversation_id, 'user', $message, $page ? array( 'page' => $page ) : array() );
		$aid = $this->plugin->activity->start( 'chat', __( 'Chat answer', 'seolity' ) . ': ' . mb_substr( $message, 0, 70 ), array( 'ref' => 'chat:' . $conversation_id, 'step' => __( 'Reading your site data…', 'seolity' ) ) );
		$id  = $this->store( $conversation_id, 'assistant', '', array( 'status' => 'pending', 'activity' => $aid, 'question' => $message, 'page' => $page, 'started' => gmdate( 'c' ) ) );
		$this->plugin->activity->merge_meta( $aid, array( 'message_id' => (int) $id ) );
		ignore_user_abort( true ); // A reload must not lose the answer.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		try {
			$this->answer( $id );
		} catch ( \SEOlity\AI\Deferred $d ) {
			$this->defer( $id, $aid, $d );
		}
		return array( 'conversation_id' => $conversation_id, 'message' => $this->message( $id ) );
	}

	/** Answer a stored placeholder (also the queue job). May throw Deferred. */
	public function answer( $id ) {
		$db  = $this->plugin->db;
		$row = $db->row( 'SELECT * FROM {chat} WHERE id = %d', array( (int) $id ) );
		if ( ! $row || 'assistant' !== $row['role'] ) {
			return false;
		}
		$meta = (array) json_decode( (string) $row['meta'], true );
		if ( isset( $meta['status'] ) && 'done' === $meta['status'] ) {
			return true;
		}
		if ( $row['user_id'] && ! get_current_user_id() ) {
			wp_set_current_user( (int) $row['user_id'] );
		}
		$history = array_reverse(
			$db->rows(
				"SELECT role, content FROM {chat} WHERE user_id = %d AND conversation_id = %s AND id < %d AND content <> '' ORDER BY id DESC LIMIT 9",
				array( (int) $row['user_id'], $row['conversation_id'], (int) $id )
			)
		);
		array_pop( $history ); // The question itself goes into the task input.
		$question = isset( $meta['question'] ) ? (string) $meta['question'] : '';
		$context  = $this->context( $question, isset( $meta['page'] ) ? (string) $meta['page'] : '' );
		$t0       = microtime( true );
		$res      = $this->plugin->ai->run(
			'chat',
			array( 'question' => $question, 'site_data' => $context ),
			array(
				'history'         => array_map( static function ( $h ) {
					return array( 'role' => $h['role'], 'content' => mb_substr( $h['content'], 0, 4000 ) );
				}, $history ),
				'knowledge_query' => $question,
				'activity_id'     => isset( $meta['activity'] ) ? (int) $meta['activity'] : 0,
			)
		);
		if ( is_wp_error( $res ) ) {
			$this->update_meta( $id, array( 'status' => 'failed', 'error' => $res->get_error_message(), 'code' => $res->get_error_code() ) );
			return $res;
		}
		$route = isset( $meta['activity'] ) ? (string) $db->value( 'SELECT route FROM {activity} WHERE id = %d', array( (int) $meta['activity'] ) ) : '';
		$db->update(
			'chat',
			array(
				'content' => (string) $res['data']['answer'],
				'meta'    => wp_json_encode(
					array_merge(
						$meta,
						array(
							'status'       => 'done',
							'data_used'    => $res['data']['data_used'],
							'actions'      => array_values( array_filter( (array) $res['data']['suggested_actions'], static function ( $a ) {
								return 0 === strpos( (string) $a['route'], '#/' );
							} ) ),
							'context_keys' => array_keys( $context ),
							'meta'         => $res['meta'],
							'route'        => $route,
							'ms'           => (int) round( ( microtime( true ) - $t0 ) * 1000 ),
							'error'        => null,
						)
					),
					JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
				),
			),
			array( 'id' => (int) $id )
		);
		return true;
	}

	/** Try a failed or unwanted answer again. */
	public function retry( $id ) {
		$row = $this->plugin->db->row( 'SELECT id, user_id, role, meta FROM {chat} WHERE id = %d', array( (int) $id ) );
		if ( ! $row || 'assistant' !== $row['role'] || (int) $row['user_id'] !== get_current_user_id() ) {
			return new \WP_Error( 'seolity_chat', __( 'Message not found.', 'seolity' ), array( 'status' => 404 ) );
		}
		$meta = (array) json_decode( (string) $row['meta'], true );
		$aid  = $this->plugin->activity->start( 'chat', __( 'Chat answer (again)', 'seolity' ) . ': ' . mb_substr( isset( $meta['question'] ) ? $meta['question'] : '', 0, 70 ), array( 'step' => __( 'Reading your site data…', 'seolity' ), 'meta' => array( 'message_id' => (int) $id ) ) );
		$this->plugin->db->update( 'chat', array( 'content' => '', 'meta' => wp_json_encode( array_merge( $meta, array( 'status' => 'pending', 'error' => null, 'activity' => $aid, 'started' => gmdate( 'c' ) ) ), JSON_UNESCAPED_UNICODE ) ), array( 'id' => (int) $id ) );
		ignore_user_abort( true );
		try {
			$this->answer( $id );
		} catch ( \SEOlity\AI\Deferred $d ) {
			$this->defer( $id, $aid, $d );
		}
		return array( 'message' => $this->message( $id ) );
	}

	/** The AI answers through GitHub: a queue job fills the placeholder later (linked to the activity). */
	private function defer( $id, $aid, \SEOlity\AI\Deferred $d ) {
		$this->update_meta( $id, array( 'status' => 'waiting', 'job' => $d->job_id() ) );
		$job = $this->plugin->queue->push( 'chat_reply', array( 'id' => $id, 'user_id' => get_current_user_id() ), array( 'unique' => 'chat_reply_' . $id, 'priority' => 0, 'delay' => 5, 'max_attempts' => 3 ) );
		if ( $job && $aid ) {
			$this->plugin->activity->attach_job( $aid, $job );
			$this->plugin->db->update( 'activity', array( 'status' => 'waiting' ), array( 'id' => (int) $aid ) );
		}
	}

	/**
	 * Answers whose work ended without an answer (cancelled, timed out, interrupted) are shown as
	 * failed with the reason, so the chat never waits forever.
	 */
	private function settle( $conversation_id ) {
		$this->plugin->activity->reconcile();
		$db   = $this->plugin->db;
		$rows = $db->rows( "SELECT id, meta FROM {chat} WHERE user_id = %d AND conversation_id = %s AND role = 'assistant' AND content = '' ORDER BY id DESC LIMIT 20", array( get_current_user_id(), (string) $conversation_id ) );
		foreach ( $rows as $r ) {
			$meta = (array) json_decode( (string) $r['meta'], true );
			if ( empty( $meta['activity'] ) || ! isset( $meta['status'] ) || ! in_array( $meta['status'], array( 'pending', 'waiting' ), true ) ) {
				continue;
			}
			$act = $db->row( 'SELECT status, detail FROM {activity} WHERE id = %d', array( (int) $meta['activity'] ) );
			if ( $act && in_array( $act['status'], array( 'failed', 'cancelled', 'timed_out', 'interrupted' ), true ) ) {
				$this->update_meta( (int) $r['id'], array( 'status' => 'failed', 'error' => (string) $act['detail'], 'code' => 'seolity_' . $act['status'] ) );
			}
		}
	}

	private function update_meta( $id, array $changes ) {
		$row  = $this->plugin->db->row( 'SELECT meta FROM {chat} WHERE id = %d', array( (int) $id ) );
		$meta = $row ? (array) json_decode( (string) $row['meta'], true ) : array();
		$this->plugin->db->update( 'chat', array( 'meta' => wp_json_encode( array_merge( $meta, $changes ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ), array( 'id' => (int) $id ) );
	}

	public function message( $id ) {
		$r = $this->plugin->db->row( 'SELECT id, role, content, meta, created_at FROM {chat} WHERE id = %d AND user_id = %d', array( (int) $id, get_current_user_id() ) );
		return $r ? Database::decode( $r, array( 'meta' ) ) : null;
	}

	private function page_url( $page ) {
		if ( is_numeric( $page ) && (int) $page > 0 ) {
			return (string) get_permalink( (int) $page );
		}
		return 0 === strpos( (string) $page, home_url() ) ? esc_url_raw( (string) $page ) : '';
	}

	private function store( $cid, $role, $content, array $meta = array() ) {
		return $this->plugin->db->insert(
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
	private function context( $q, $page = '' ) {
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
		$object = $page ? $page : null;
		if ( $object ) {
			// The page chosen in the chat ("about this page").
		} elseif ( preg_match( '#https?://[^\s]+#', $q, $m ) ) {
			$object = $m[0];
		} else {
			$found = get_posts( array( 'post_type' => array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) ), 'post_status' => 'publish', 's' => mb_substr( $q, 0, 80 ), 'posts_per_page' => 1, 'fields' => 'ids' ) );
			if ( $found ) {
				$object = get_permalink( $found[0] );
			}
		}
		if ( $object ) {
			$row = $p->db->row( 'SELECT id, object_type, object_id, status_code, indexable, title, meta_description, word_count, inlinks, depth FROM {urls} WHERE project_id = %d AND url_hash = %s', array( $p->db->project_id(), Database::url_hash( $object ) ) );
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
		if ( $has_gsc ) {
			// Query- and page-level Search Console history for whatever the question is about.
			$ctx['search_console_detail'] = $p->gsc_insights->for_question( $q, $object ? $object : '' );
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
		$this->settle( $conversation_id );
		$rows = $this->plugin->db->rows( 'SELECT id, role, content, meta, created_at FROM {chat} WHERE user_id = %d AND conversation_id = %s ORDER BY id ASC LIMIT 200', array( get_current_user_id(), (string) $conversation_id ) );
		return array_map(
			static function ( $r ) {
				return Database::decode( $r, array( 'meta' ) );
			},
			$rows
		);
	}

	/** Answers still pending in a conversation (the page polls this after a reload). */
	public function pending( $conversation_id ) {
		return (int) $this->plugin->db->value( "SELECT COUNT(*) FROM {chat} WHERE user_id = %d AND conversation_id = %s AND role = 'assistant' AND content = '' AND meta LIKE %s", array( get_current_user_id(), (string) $conversation_id, '%"status":"waiting"%' ) )
			+ (int) $this->plugin->db->value( "SELECT COUNT(*) FROM {chat} WHERE user_id = %d AND conversation_id = %s AND role = 'assistant' AND content = '' AND meta LIKE %s", array( get_current_user_id(), (string) $conversation_id, '%"status":"pending"%' ) );
	}

	public function conversations() {
		return $this->plugin->db->rows(
			"SELECT conversation_id, MIN(created_at) started, MAX(created_at) updated, COUNT(*) messages, SUBSTRING_INDEX(GROUP_CONCAT(IF(role = 'user', content, NULL) ORDER BY id SEPARATOR '\n'), '\n', 1) first_question FROM {chat} WHERE user_id = %d GROUP BY conversation_id ORDER BY updated DESC LIMIT 50",
			array( get_current_user_id() )
		);
	}

	public function delete( $conversation_id ) {
		return (bool) $this->plugin->db->query( 'DELETE FROM {chat} WHERE user_id = %d AND conversation_id = %s', array( get_current_user_id(), (string) $conversation_id ) );
	}
}
