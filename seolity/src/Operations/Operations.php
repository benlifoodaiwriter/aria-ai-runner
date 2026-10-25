<?php
/**
 * SEO operations: the one execution path for every correction, whoever starts it
 * (the AI agent, the roadmap, a bulk action, a consolidation workflow).
 *
 * Lifecycle (row in {operations}):
 *   preparing → awaiting_approval → approved → applying → done | partial | failed
 *   (or cancelled / rolled_back)
 *
 * - Preparation never touches the site: AI output becomes *pending* changes (versioned in
 *   {changes}), redirects / setting changes become proposals. The owner sees every item
 *   (before → after, target checks) and approves all or some of them.
 * - Applying writes through the authoritative system (WordPress / Rank Math meta, Rank Math
 *   Redirections when active), then every item is verified (persisted + live page) by Verifier.
 * - The canonical issue is completed only when verification passes; a failed item never
 *   reports success. Every applied item can be rolled back.
 * - The owner's instructions are stored with the operation and passed to the AI on every
 *   (re)generation.
 *
 * @package SEOlity
 */

namespace SEOlity\Operations;

use SEOlity\Activity\Activity;
use SEOlity\Content\Objects;
use SEOlity\Core\Database;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class Operations {

	const JSON = array( 'input', 'plan', 'preview', 'result', 'verification', 'rollback' );

	/** @var Plugin */
	private $plugin;

	/** @var Verifier */
	private $verifier;

	public function __construct( Plugin $plugin ) {
		$this->plugin   = $plugin;
		$this->verifier = new Verifier( $plugin );
	}

	public function verifier() {
		return $this->verifier;
	}

	/* ------------------------------------------------------------------ storage */

	public function create( $type, array $o = array() ) {
		$now = Database::now();
		return (int) $this->plugin->db->insert(
			'operations',
			array(
				'project_id'   => $this->plugin->db->project_id(),
				'type'         => substr( sanitize_key( $type ), 0, 40 ),
				'issue_id'     => ! empty( $o['issue_id'] ) ? (int) $o['issue_id'] : null,
				'status'       => isset( $o['status'] ) ? $o['status'] : 'preparing',
				'title'        => isset( $o['title'] ) ? mb_substr( wp_strip_all_tags( (string) $o['title'] ), 0, 250 ) : '',
				'input'        => isset( $o['input'] ) ? $o['input'] : array(),
				'instructions' => isset( $o['instructions'] ) ? sanitize_textarea_field( (string) $o['instructions'] ) : '',
				'plan'         => isset( $o['plan'] ) ? $o['plan'] : array(),
				'preview'      => array(),
				'user_id'      => get_current_user_id(),
				'activity_id'  => ! empty( $o['activity_id'] ) ? (int) $o['activity_id'] : null,
				'created_at'   => $now,
				'updated_at'   => $now,
			)
		);
	}

	public function get( $id ) {
		$row = $this->plugin->db->row( 'SELECT * FROM {operations} WHERE id = %d AND project_id = %d', array( (int) $id, $this->plugin->db->project_id() ) );
		if ( ! $row ) {
			return null;
		}
		$row = Database::decode( $row, self::JSON );
		foreach ( array( 'id', 'issue_id', 'user_id', 'activity_id' ) as $k ) {
			$row[ $k ] = null === $row[ $k ] ? null : (int) $row[ $k ];
		}
		foreach ( self::JSON as $k ) {
			$row[ $k ] = is_array( $row[ $k ] ) ? $row[ $k ] : array();
		}
		$row['preview'] = $this->hydrate( $row['preview'] );
		return $row;
	}

	public function update( $id, array $data ) {
		$data['updated_at'] = Database::now();
		if ( isset( $data['status'] ) && in_array( $data['status'], array( 'done', 'partial', 'failed', 'cancelled', 'rolled_back' ), true ) ) {
			$data['finished_at'] = Database::now();
		}
		return $this->plugin->db->update( 'operations', $data, array( 'id' => (int) $id ) );
	}

	/** Latest operation of an issue (for the roadmap and the Do-it popup). */
	public function for_issue( $issue_id ) {
		$id = (int) $this->plugin->db->value( 'SELECT id FROM {operations} WHERE project_id = %d AND issue_id = %d ORDER BY id DESC LIMIT 1', array( $this->plugin->db->project_id(), (int) $issue_id ) );
		return $id ? $this->get( $id ) : null;
	}

	public function recent( array $f = array() ) {
		$where = array( 'project_id = %d' );
		$args  = array( $this->plugin->db->project_id() );
		if ( ! empty( $f['status'] ) ) {
			$where[] = 'status = %s';
			$args[]  = (string) $f['status'];
		}
		if ( ! empty( $f['type'] ) ) {
			$where[] = 'type = %s';
			$args[]  = (string) $f['type'];
		}
		$rows = $this->plugin->db->rows( 'SELECT id, type, issue_id, status, title, error, user_id, created_at, updated_at, finished_at FROM {operations} WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT 100', $args );
		return $rows;
	}

	/* ------------------------------------------------------------------ preview */

	/**
	 * Store what preparation produced. Items: change (id), redirect (source, target, type),
	 * fix_chains, bulk (task, ids, object_type), plan (opportunity), setting (option, path, after, label).
	 */
	public function staged( $id, array $items, array $plan_lines = array() ) {
		$clean = array();
		foreach ( $items as $i => $it ) {
			$it['key'] = isset( $it['key'] ) ? $it['key'] : 'i' . $i;
			if ( 'redirect' === $it['kind'] && empty( $it['check'] ) ) {
				$it['check'] = $this->check_redirect( $it['source'], $it['target'] );
			}
			$clean[] = $it;
		}
		$this->update( $id, array( 'preview' => wp_json_encode( $clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), 'plan' => wp_json_encode( $plan_lines, JSON_UNESCAPED_UNICODE ), 'status' => $clean ? 'awaiting_approval' : 'failed', 'error' => $clean ? null : __( 'Nothing to change was found.', 'seolity' ) ) );
		$op = $this->get( $id );
		if ( $op && $op['issue_id'] && $clean ) {
			$this->plugin->recommendations->set_state( (int) $op['issue_id'], 'in_progress', array( 'operation_id' => (int) $id ) );
		}
		return $op;
	}

	/** Live details for preview items (before/after of pending changes). */
	private function hydrate( array $items ) {
		foreach ( $items as &$it ) {
			if ( 'change' === $it['kind'] && ! empty( $it['id'] ) ) {
				$c = $this->plugin->changes->get( (int) $it['id'] );
				if ( $c ) {
					$it['object_type'] = $c['object_type'];
					$it['object_id']   = (int) $c['object_id'];
					$it['field']       = $c['field'];
					$it['label']       = Activity::field_label( $c['field'] );
					$it['object']      = Objects::label( $c['object_type'], (int) $c['object_id'] );
					$it['url']         = $c['url'];
					$it['before']      = (string) $c['before_value'];
					$it['after']       = (string) $c['after_value'];
					$it['risk']        = $c['risk'];
					$it['state']       = $c['status'];
					$it['edit']        = 'post' === $c['object_type'] ? admin_url( 'post.php?post=' . (int) $c['object_id'] . '&action=edit' ) : '';
				}
			}
		}
		return $items;
	}

	/** Validate a proposed redirect before anyone approves it. */
	public function check_redirect( $source, $target ) {
		$src  = \SEOlity\Redirects\Manager::normalize_source( $source );
		$out  = array( 'ok' => true, 'problems' => array() );
		if ( '' === trim( (string) $target ) ) {
			return array( 'ok' => false, 'problems' => array( __( 'No target URL.', 'seolity' ) ) );
		}
		if ( \SEOlity\Redirects\Manager::normalize_source( $target ) === $src ) {
			return array( 'ok' => false, 'problems' => array( __( 'Source and target are the same.', 'seolity' ) ) );
		}
		$t = $this->verifier->target_ok( $target );
		if ( ! $t['ok'] ) {
			$out['ok']         = false;
			$out['problems'][] = $t['detail'];
		} elseif ( ! empty( $t['noindex'] ) ) {
			$out['problems'][] = $t['detail'];
		}
		$existing = $this->plugin->db->row( "SELECT target, status FROM {redirects} WHERE project_id = %d AND source_hash = %s AND status IN ('active','external') AND is_regex = 0", array( $this->plugin->db->project_id(), Database::hash( $src ) ) );
		if ( $existing && untrailingslashit( $existing['target'] ) !== untrailingslashit( $target ) ) {
			/* translators: %s: url */
			$out['problems'][] = sprintf( __( 'Replaces the existing redirect to %s.', 'seolity' ), urldecode( $existing['target'] ) );
		}
		$live = $this->verifier->fetch( home_url( $src ), false );
		if ( ! is_wp_error( $live ) && 200 === (int) $live['status'] ) {
			$out['ok']         = false;
			$out['problems'][] = __( 'The old URL is a live page (HTTP 200): redirecting it would hide existing content.', 'seolity' );
		}
		return $out;
	}

	/* ------------------------------------------------------------------ approval */

	/**
	 * Approve all (or the given) items and apply them in the background.
	 *
	 * @param int        $id   Operation.
	 * @param array|null $keys Item keys to apply (null = all applicable).
	 */
	public function approve( $id, $keys = null ) {
		$op = $this->get( $id );
		if ( ! $op ) {
			return new \WP_Error( 'seolity_op', __( 'Operation not found.', 'seolity' ), array( 'status' => 404 ) );
		}
		if ( 'awaiting_approval' !== $op['status'] ) {
			return new \WP_Error( 'seolity_op', __( 'This operation is not waiting for approval.', 'seolity' ), array( 'status' => 409 ) );
		}
		$items = $op['preview'];
		foreach ( $items as &$it ) {
			$it['approved'] = null === $keys || in_array( $it['key'], (array) $keys, true );
		}
		unset( $it );
		if ( ! array_filter( wp_list_pluck( $items, 'approved' ) ) ) {
			return new \WP_Error( 'seolity_op', __( 'Select at least one item.', 'seolity' ), array( 'status' => 400 ) );
		}
		$aid = $this->plugin->activity->start( 'agent', __( 'Applying approved changes', 'seolity' ), array( 'detail' => $op['title'], 'dock' => true, 'status' => 'queued', 'step' => __( 'Queued — starting in a moment…', 'seolity' ), 'meta' => array( 'type' => 'operation', 'operation_id' => (int) $id ) ) );
		$this->update( $id, array( 'status' => 'approved', 'preview' => wp_json_encode( $this->strip( $items ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), 'activity_id' => $aid ) );
		$job = $this->plugin->queue->push( 'operation_apply', array( 'id' => (int) $id, 'user_id' => get_current_user_id() ), array( 'unique' => 'op_' . (int) $id, 'priority' => 0, 'max_attempts' => 1 ) );
		$this->plugin->activity->attach_job( $aid, $job );
		$this->plugin->audit_log->log( 'operation.approved', 'operation', (int) $id, array( 'type' => $op['type'], 'items' => count( array_filter( wp_list_pluck( $items, 'approved' ) ) ) ) );
		\SEOlity\Activity\AiJobs::run_after_response( $this->plugin );
		return $this->get( $id );
	}

	/** Remove display-only fields before storing. */
	private function strip( array $items ) {
		foreach ( $items as &$it ) {
			unset( $it['before'], $it['after'], $it['label'], $it['object'], $it['state'], $it['edit'] );
		}
		return $items;
	}

	public function reject( $id ) {
		$op = $this->get( $id );
		if ( ! $op || ! in_array( $op['status'], array( 'awaiting_approval', 'preparing' ), true ) ) {
			return new \WP_Error( 'seolity_op', __( 'This operation cannot be rejected any more.', 'seolity' ), array( 'status' => 409 ) );
		}
		foreach ( $op['preview'] as $it ) {
			if ( 'change' === $it['kind'] && ! empty( $it['id'] ) ) {
				$this->plugin->changes->reject( (int) $it['id'], 'operation rejected' );
			}
		}
		$this->update( $id, array( 'status' => 'cancelled', 'error' => __( 'Rejected by the user — nothing was changed.', 'seolity' ) ) );
		if ( $op['issue_id'] ) {
			$this->plugin->recommendations->set_state( (int) $op['issue_id'], 'open' );
		}
		$this->plugin->audit_log->log( 'operation.rejected', 'operation', (int) $id, array( 'type' => $op['type'] ) );
		return $this->get( $id );
	}

	/** Queue handler: apply approved items, then verify each one. */
	public function apply( $id ) {
		$op = $this->get( $id );
		if ( ! $op || ! in_array( $op['status'], array( 'approved', 'applying' ), true ) ) {
			return true;
		}
		if ( $op['user_id'] ) {
			wp_set_current_user( (int) $op['user_id'] );
		}
		$act   = $this->plugin->activity;
		$aid   = (int) $op['activity_id'];
		$prev  = Activity::set_current( $aid );
		$items = $op['preview'];
		$roll  = $op['rollback'];
		$this->update( $id, array( 'status' => 'applying' ) );
		$n     = max( 1, count( $items ) );
		$ok    = 0;
		$fail  = 0;
		$lines = array();
		try {
			foreach ( $items as $i => &$it ) {
				if ( empty( $it['approved'] ) || ! empty( $it['result'] ) ) {
					continue;
				}
				$this->plugin->queue->checkpoint( (int) ( 90 * $i / $n ) );
				$act->step( $aid, $this->describe( $it ), (int) ( 90 * $i / $n ) );
				$res          = $this->apply_item( $it, $roll );
				$it['result'] = $res;
				if ( $res['ok'] ) {
					$ok++;
				} else {
					$fail++;
				}
				$lines[] = ( $res['ok'] ? '✓ ' : '✗ ' ) . $this->describe( $it ) . ' — ' . $res['detail'];
				$act->step( $aid, end( $lines ), null, array( 'ok' => $res['ok'] ) );
				// Persist after every item: a cancelled or interrupted run never repeats a write.
				$this->update( $id, array( 'preview' => wp_json_encode( $items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), 'rollback' => wp_json_encode( $roll, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) );
			}
			unset( $it );
		} catch ( \SEOlity\Queue\Cancelled $c ) {
			$this->update( $id, array( 'status' => $ok ? 'partial' : 'cancelled', 'error' => __( 'Cancelled by the user. Items already applied stay applied (use Undo to roll them back).', 'seolity' ) ) );
			Activity::set_current( $prev );
			throw $c;
		}
		$status = $fail ? ( $ok ? 'partial' : 'failed' ) : 'done';
		$verify = array( 'ok' => $ok, 'failed' => $fail, 'at' => gmdate( 'c' ), 'lines' => $lines );
		$this->update( $id, array( 'status' => $status, 'verification' => wp_json_encode( $verify, JSON_UNESCAPED_UNICODE ), 'error' => $fail ? __( 'Some items failed — see the details.', 'seolity' ) : null ) );
		if ( $op['issue_id'] ) {
			if ( 'done' === $status ) {
				$this->plugin->recommendations->set_state( (int) $op['issue_id'], 'done', array( 'verified_at' => Database::now(), 'verification' => $verify, 'operation_id' => (int) $id ) );
			} else {
				$this->plugin->recommendations->set_state( (int) $op['issue_id'], 'open', array( 'verification' => $verify, 'operation_id' => (int) $id ) );
			}
		}
		$summary = 'done' === $status ? __( 'Applied and verified on the site.', 'seolity' ) : __( 'Finished with errors — some items were not applied or could not be confirmed.', 'seolity' );
		$act->finish( $aid, 'done' === $status ? 'done' : 'failed', $summary, array( 'summary' => $summary, 'lines' => $lines, 'result' => array( 'operation_id' => (int) $id ) ), array( 'step' => __( 'Finished', 'seolity' ) ) );
		$this->plugin->audit_log->log( 'operation.applied', 'operation', (int) $id, array( 'type' => $op['type'], 'status' => $status, 'ok' => $ok, 'failed' => $fail ) );
		do_action( 'seolity_operation_finished', $this->get( $id ) );
		Activity::set_current( $prev );
		return true;
	}

	/** Human line for one item. */
	public function describe( array $it ) {
		switch ( $it['kind'] ) {
			case 'change':
				$c = $this->plugin->changes->get( (int) $it['id'] );
				/* translators: 1 field 2 page */
				return $c ? sprintf( __( '%1$s of “%2$s”', 'seolity' ), Activity::field_label( $c['field'] ), Objects::label( $c['object_type'], (int) $c['object_id'] ) ) : __( 'Change', 'seolity' );
			case 'redirect':
				/* translators: 1 source 2 target */
				return sprintf( __( '301 redirect %1$s → %2$s', 'seolity' ), urldecode( (string) $it['source'] ), urldecode( (string) $it['target'] ) );
			case 'fix_chains':
				return __( 'Point redirect chains straight to their final URL', 'seolity' );
			case 'bulk':
				/* translators: %d: items */
				return sprintf( __( 'Queue AI optimization for %d items', 'seolity' ), count( (array) $it['ids'] ) );
			case 'plan':
				return __( 'Add the topic to the content planner', 'seolity' );
			case 'setting':
				return (string) $it['label'];
		}
		return (string) $it['kind'];
	}

	/**
	 * Apply one item and verify it.
	 *
	 * @param array $it   Item.
	 * @param array $roll Rollback log (by reference).
	 * @return array{ok:bool,detail:string,verify?:array}
	 */
	private function apply_item( array $it, array &$roll ) {
		switch ( $it['kind'] ) {
			case 'change':
				$c = $this->plugin->changes->get( (int) $it['id'] );
				if ( ! $c ) {
					return array( 'ok' => false, 'detail' => __( 'Change not found.', 'seolity' ) );
				}
				if ( 'applied' !== $c['status'] ) {
					$res = $this->plugin->changes->apply( (int) $it['id'] );
					if ( is_wp_error( $res ) ) {
						return array( 'ok' => false, 'detail' => $res->get_error_message() );
					}
				}
				$roll[] = array( 'kind' => 'change', 'id' => (int) $it['id'] );
				$v      = $this->verifier->change( $this->plugin->changes->get( (int) $it['id'] ) );
				return array( 'ok' => $v['ok'] && false !== $v['effective'], 'detail' => $v['detail'], 'verify' => $v );
			case 'redirect':
				$rid = $this->plugin->redirects->create( $it['source'], $it['target'], isset( $it['type'] ) ? (int) $it['type'] : 301, false, isset( $it['origin'] ) ? $it['origin'] : 'ai' );
				if ( is_wp_error( $rid ) ) {
					return array( 'ok' => false, 'detail' => $rid->get_error_message() );
				}
				$roll[] = array( 'kind' => 'redirect', 'id' => (int) $rid );
				if ( ! empty( $it['not_found_id'] ) ) {
					$this->plugin->db->update( 'not_found', array( 'status' => 'redirected' ), array( 'id' => (int) $it['not_found_id'] ) );
				}
				$v = $this->verifier->redirect( $it['source'], $it['target'], isset( $it['type'] ) ? (int) $it['type'] : 301 );
				if ( $v['ok'] ) {
					// Every canonical issue about this broken URL is now resolved (404 section, audit, roadmap).
					$this->plugin->recommendations->resolve_url( array( 'create_redirect', 'not_found' ), $it['source'], array( 'redirect' => $v, 'at' => gmdate( 'c' ) ) );
				}
				return array( 'ok' => $v['ok'], 'detail' => $v['detail'], 'verify' => $v, 'redirect_id' => (int) $rid );
			case 'fix_chains':
				$n    = (int) $this->plugin->redirects->fix_chains();
				$left = count(
					array_filter(
						$this->plugin->redirects->chains(),
						static function ( $c ) {
							return empty( $c['loop'] );
						}
					)
				);
				/* translators: 1 fixed 2 left */
				return array( 'ok' => 0 === $left, 'detail' => sprintf( __( '%1$d redirects updated; %2$d chains left.', 'seolity' ), $n, $left ) );
			case 'bulk':
				$res = $this->plugin->optimizer->queue_bulk( $it['task'], (array) $it['ids'], $it['object_type'] );
				return is_wp_error( $res ) ? array( 'ok' => false, 'detail' => $res->get_error_message() ) : array( 'ok' => true, 'detail' => sprintf( /* translators: %d: items */ __( '%d items queued (each one is a separate task with its own result).', 'seolity' ), (int) $res['queued'] ) );
			case 'plan':
				$res = $this->plugin->planner->add_from_opportunity( (array) $it['opportunity'] );
				return is_wp_error( $res ) ? array( 'ok' => false, 'detail' => $res->get_error_message() ) : array( 'ok' => true, 'detail' => __( 'Added to the content planner.', 'seolity' ) );
			case 'setting':
				return $this->apply_setting( $it, $roll );
		}
		return array( 'ok' => false, 'detail' => __( 'Unknown item.', 'seolity' ) );
	}

	/**
	 * Change one value inside an option array (e.g. Rank Math "rank-math-options-titles" →
	 * "author_archive_robots"), keeping the old value for rollback, then verify.
	 */
	private function apply_setting( array $it, array &$roll ) {
		$allowed = (array) apply_filters( 'seolity_operation_options', array( 'rank-math-options-titles', 'rank-math-options-general', 'rank-math-options-sitemap', 'blog_public' ) );
		if ( ! in_array( $it['option'], $allowed, true ) ) {
			return array( 'ok' => false, 'detail' => __( 'This setting is not on the list of settings SEOlity may change.', 'seolity' ) );
		}
		$opt    = get_option( $it['option'] );
		$before = null;
		if ( '' === (string) $it['path'] ) {
			$before = $opt;
			update_option( $it['option'], $it['after'] );
		} else {
			$opt    = is_array( $opt ) ? $opt : array();
			$before = array_key_exists( $it['path'], $opt ) ? $opt[ $it['path'] ] : null;
			$opt[ $it['path'] ] = $it['after'];
			update_option( $it['option'], $opt );
		}
		$roll[] = array( 'kind' => 'setting', 'option' => $it['option'], 'path' => $it['path'], 'before' => $before );
		if ( class_exists( '\\RankMath\\Sitemap\\Cache' ) ) {
			\RankMath\Sitemap\Cache::invalidate_storage();
		}
		wp_cache_delete( $it['option'], 'options' );
		$now = get_option( $it['option'] );
		$val = '' === (string) $it['path'] ? $now : ( is_array( $now ) && isset( $now[ $it['path'] ] ) ? $now[ $it['path'] ] : null );
		$ok  = wp_json_encode( $val ) === wp_json_encode( $it['after'] );
		$out = array( 'ok' => $ok, 'detail' => $ok ? __( 'Setting saved.', 'seolity' ) : __( 'The setting could not be saved.', 'seolity' ) );
		// Effective check on a sample URL (e.g. an archive that must now be noindex).
		if ( $ok && ! empty( $it['check_url'] ) && ! empty( $it['expect'] ) ) {
			$res = $this->verifier->fetch( $it['check_url'] );
			if ( ! is_wp_error( $res ) ) {
				$robots = $res['page'] ? $res['page']['robots'] . ',' . $res['page']['x_robots'] : '';
				$eff    = 'noindex' === $it['expect'] ? false !== strpos( $robots, 'noindex' ) : ( 'redirect' === $it['expect'] ? in_array( (int) $res['status'], array( 301, 302 ), true ) : ( 'gone' === $it['expect'] ? in_array( (int) $res['status'], array( 404, 410 ), true ) : null ) );
				$out['ok']     = false !== $eff;
				/* translators: 1 url 2 status */
				$out['detail'] = $eff ? sprintf( __( 'Confirmed on %1$s (%2$s).', 'seolity' ), urldecode( $it['check_url'] ), $it['expect'] ) : sprintf( __( 'Saved, but %s does not show the effect yet.', 'seolity' ), urldecode( $it['check_url'] ) );
			}
		}
		return $out;
	}

	/** Undo everything an operation applied (newest first). */
	public function rollback( $id ) {
		$op = $this->get( $id );
		if ( ! $op || ! in_array( $op['status'], array( 'done', 'partial', 'failed' ), true ) || ! $op['rollback'] ) {
			return new \WP_Error( 'seolity_op', __( 'Nothing to roll back.', 'seolity' ), array( 'status' => 409 ) );
		}
		$errors = array();
		foreach ( array_reverse( $op['rollback'] ) as $r ) {
			if ( 'change' === $r['kind'] ) {
				$res = $this->plugin->changes->revert( (int) $r['id'] );
			} elseif ( 'redirect' === $r['kind'] ) {
				$res = $this->plugin->redirects->delete( (int) $r['id'] );
			} elseif ( 'setting' === $r['kind'] ) {
				$opt = get_option( $r['option'] );
				if ( '' === (string) $r['path'] ) {
					update_option( $r['option'], $r['before'] );
				} else {
					$opt = is_array( $opt ) ? $opt : array();
					if ( null === $r['before'] ) {
						unset( $opt[ $r['path'] ] );
					} else {
						$opt[ $r['path'] ] = $r['before'];
					}
					update_option( $r['option'], $opt );
				}
				$res = true;
			} else {
				$res = true;
			}
			if ( is_wp_error( $res ) ) {
				$errors[] = $res->get_error_message();
			}
		}
		$this->update( $id, array( 'status' => 'rolled_back', 'error' => $errors ? implode( ' · ', $errors ) : null ) );
		if ( $op['issue_id'] ) {
			$this->plugin->recommendations->set_state( (int) $op['issue_id'], 'open' );
		}
		$this->plugin->audit_log->log( 'operation.rolled_back', 'operation', (int) $id, array( 'type' => $op['type'], 'errors' => count( $errors ) ) );
		return $errors ? new \WP_Error( 'seolity_op', implode( ' · ', $errors ) ) : $this->get( $id );
	}
}
