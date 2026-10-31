<?php
/**
 * AI edits that run on the server, not in the browser tab.
 *
 * "Optimize with SEOlity AI", "AI title & description" and the "AI Product Optimizer" create an
 * activity (shown as a small progress card for the user who started it) and a queue job. The
 * response returns at once; the work continues even if the page is reloaded or closed. The card
 * — on any SEOlity or editor screen — follows the progress and, when finished, opens the stored
 * report so the result can be reviewed and applied later.
 *
 * On PHP-FPM the job starts right after the response is sent; elsewhere a loop-back request or
 * the polling of the card moves the queue forward.
 *
 * @package SEOlity
 */

namespace SEOlity\Activity;

use SEOlity\Content\Objects;
use SEOlity\Core\Capabilities;
use SEOlity\Plugin;
use SEOlity\Queue\Scheduler;

defined( 'ABSPATH' ) || exit;

final class AiJobs {

	const TYPES = array( 'optimize', 'meta', 'product' );

	/** @var Plugin */
	private $plugin;

	/** @var bool */
	private static $runner_hooked = false;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * @param string $type   optimize | meta | product.
	 * @param array  $params Type-specific parameters (see run()).
	 * @return array|\WP_Error The new activity.
	 */
	public function start( $type, array $params ) {
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return new \WP_Error( 'seolity_job', __( 'Unknown AI job.', 'seolity' ), array( 'status' => 400 ) );
		}
		$otype = 'product' === $type ? 'post' : sanitize_key( isset( $params['object_type'] ) ? $params['object_type'] : 'post' );
		$oid   = (int) ( 'product' === $type ? ( isset( $params['id'] ) ? $params['id'] : 0 ) : ( isset( $params['object_id'] ) ? $params['object_id'] : 0 ) );
		if ( $oid && ! Capabilities::can_edit_object( 'term' === $otype ? 'term' : 'post', $oid ) ) {
			return new \WP_Error( 'seolity_forbidden', __( 'You are not allowed to edit this item.', 'seolity' ), array( 'status' => 403 ) );
		}
		if ( ! $this->plugin->ai->is_ready() ) {
			return new \WP_Error( 'seolity_ai_not_configured', __( 'No AI provider is configured. Add an API key in SEOlity → Settings → AI.', 'seolity' ), array( 'status' => 400 ) );
		}
		$titles = array(
			'optimize' => __( 'Optimizing with SEOlity AI', 'seolity' ),
			'meta'     => __( 'AI title & description', 'seolity' ),
			'product'  => __( 'AI Product Optimizer', 'seolity' ),
		);
		$subject = isset( $params['label'] ) ? sanitize_text_field( (string) $params['label'] ) : '';
		$name    = $oid ? Objects::label( $otype, $oid ) : ( isset( $params['title'] ) ? sanitize_text_field( (string) $params['title'] ) : '' );
		$subject = trim( $subject . ( $subject && $name ? ' — ' : '' ) . $name );
		// The same edit of the same item is never queued twice.
		$unique = $type . '|' . $otype . '|' . $oid . '|' . ( isset( $params['field'] ) ? sanitize_key( (string) $params['field'] ) : '' ) . '|' . ( isset( $params['elementId'] ) ? sanitize_key( (string) $params['elementId'] ) : '' );
		$alive  = $oid ? $this->plugin->queue->find_unique( 'ai_job', $unique ) : 0;
		if ( $alive ) {
			$aid = (int) $this->plugin->db->value( 'SELECT id FROM {activity} WHERE job_id = %d ORDER BY id DESC LIMIT 1', array( $alive ) );
			if ( $aid ) {
				return $this->plugin->activity->get( $aid, false );
			}
		}
		$id      = $this->plugin->activity->start(
			'edit',
			$titles[ $type ],
			array(
				'detail' => $subject,
				'dock'   => true,
				'status' => 'queued',
				'step'   => __( 'Queued — starting in a moment…', 'seolity' ),
				'meta'   => array( 'type' => $type, 'params' => $params, 'subject' => $subject, 'object' => array( 'type' => $otype, 'id' => $oid ), 'edit_url' => $oid && 'post' === $otype ? admin_url( 'post.php?post=' . $oid . '&action=edit' ) : '' ),
			)
		);
		if ( ! $id ) {
			return new \WP_Error( 'seolity_job', __( 'Could not start the job (database not ready).', 'seolity' ), array( 'status' => 500 ) );
		}
		$job = $this->plugin->queue->push( 'ai_job', array( 'id' => $id, 'user_id' => get_current_user_id() ), array( 'unique' => $oid ? $unique : false, 'priority' => 0, 'max_attempts' => 2 ) );
		$this->plugin->activity->attach_job( $id, $job );
		self::run_after_response( $this->plugin );
		return $this->plugin->activity->get( $id, false );
	}

	/** Start the queue right after this response is delivered (PHP-FPM), else via loop-back. */
	public static function run_after_response( Plugin $plugin ) {
		if ( self::$runner_hooked ) {
			return;
		}
		self::$runner_hooked = true;
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			add_action(
				'shutdown',
				static function () use ( $plugin ) {
					@fastcgi_finish_request(); // phpcs:ignore WordPress.PHP.NoSilencedErrors
					ignore_user_abort( true );
					if ( function_exists( 'set_time_limit' ) ) {
						@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
					}
					$plugin->queue->work( 120 );
				},
				99
			);
		} else {
			Scheduler::kick();
		}
	}

	/** Queue handler. May throw Deferred (GitHub route) — the queue retries the same request. */
	public function run( $id ) {
		$act = $this->plugin->activity;
		$a   = $act->get( (int) $id );
		if ( ! $a || ! in_array( $a['status'], array( 'running', 'waiting' ), true ) ) {
			return true;
		}
		if ( $a['user_id'] ) {
			wp_set_current_user( (int) $a['user_id'] );
		}
		$prev = Activity::set_current( (int) $id );
		try {
			$type = $a['meta']['type'];
			$p    = (array) $a['meta']['params'];
			$act->step( $id, __( 'Reading the content, Search Console queries and the SEO knowledge base…', 'seolity' ), 15 );
			if ( 'optimize' === $type ) {
				$res = $this->plugin->optimizer->optimize(
					array(
						'object_type'   => isset( $p['object_type'] ) ? $p['object_type'] : 'post',
						'object_id'     => isset( $p['object_id'] ) ? (int) $p['object_id'] : 0,
						'field'         => isset( $p['field'] ) ? $p['field'] : 'post_content',
						'content'       => isset( $p['content'] ) && null !== $p['content'] ? (string) $p['content'] : null,
						'title'         => isset( $p['title'] ) ? (string) $p['title'] : '',
						'focus_keyword' => isset( $p['focus_keyword'] ) ? (string) $p['focus_keyword'] : '',
						'goal'          => isset( $p['goal'] ) ? (string) $p['goal'] : '',
					)
				);
			} elseif ( 'meta' === $type ) {
				$res = $this->plugin->optimizer->meta( sanitize_key( $p['object_type'] ), (int) $p['object_id'] );
			} else {
				$res = $this->plugin->products->optimize( (int) $p['id'] );
			}
			if ( is_wp_error( $res ) ) {
				$act->finish( $id, 'failed', $res->get_error_message(), array( 'error' => $res->get_error_code() ), array( 'step' => __( 'Failed', 'seolity' ) ) );
				return true;
			}
			list( $summary, $lines ) = $this->summarize( $type, $res );
			$act->finish( $id, 'done', null, array( 'result' => $res, 'summary' => $summary, 'lines' => $lines ), array( 'step' => __( 'Finished — review the result', 'seolity' ) ) );
			return true;
		} finally {
			Activity::set_current( $prev );
		}
	}

	private function summarize( $type, array $res ) {
		if ( 'optimize' === $type ) {
			$lines = array();
			foreach ( array_slice( isset( $res['ai']['changes'] ) ? (array) $res['ai']['changes'] : array(), 0, 6 ) as $c ) {
				$lines[] = $c['area'] . ': ' . $c['description'];
			}
			/* translators: 1 score before 2 score after */
			return array( sprintf( __( 'SEO score %1$s → %2$s', 'seolity' ), $res['before']['score'], $res['after']['score'] ), $lines );
		}
		if ( 'meta' === $type ) {
			$first = 'ctr_rewrite' === $res['task'] ? $res['ai']['title'] : ( isset( $res['ai']['title_options'][0]['title'] ) ? $res['ai']['title_options'][0]['title'] : '' );
			return array( __( 'New title and description options are ready.', 'seolity' ), $first ? array( $first ) : array() );
		}
		$lines = array();
		foreach ( array_keys( (array) $res['changes'] ) as $k ) {
			$lines[] = 0 === strpos( $k, 'alt_' ) ? __( 'Image ALT', 'seolity' ) . ' #' . substr( $k, 4 ) : Activity::field_label( $k );
		}
		/* translators: 1 score before 2 score after */
		return array( sprintf( __( 'SEO score %1$s → %2$s', 'seolity' ), $res['before']['score'], $res['after']['score'] ), $lines );
	}

	/** After the user applied / rejected the result from a card. */
	public function note( $id, $text ) {
		$a = $this->plugin->activity->get( (int) $id, false );
		if ( ! $a || (int) $a['user_id'] !== get_current_user_id() ) {
			return new \WP_Error( 'seolity_activity', __( 'Not found.', 'seolity' ), array( 'status' => 404 ) );
		}
		$this->plugin->activity->finish( (int) $id, 'done', null, array( 'outcome' => sanitize_text_field( (string) $text ) ), array( 'step' => sanitize_text_field( (string) $text ) ) );
		return array( 'ok' => true );
	}
}
