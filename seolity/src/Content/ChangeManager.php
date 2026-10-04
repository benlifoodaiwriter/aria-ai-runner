<?php
/**
 * Change tracking, approval workflow, version history and undo (sections 12, 33).
 *
 * Every modification SEOlity makes — AI, rules, bulk, manual edits from the
 * SEOlity UI — is a row in {changes} with before/after values, provenance
 * (provider, model, prompt version), scores and GSC snapshots. Applying a
 * change also opens an SEO experiment so the result is measured.
 *
 * @package SEOlity
 */

namespace SEOlity\Content;

use SEOlity\Core\Capabilities;
use SEOlity\Core\Database;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class ChangeManager {

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Create a pending change. The "before" value is read live.
	 *
	 * @param array $c object_type, object_id, field, after, source, reason, ai(meta), keywords,
	 *                 score_before, score_after, batch_id, recommendation_id.
	 * @return int|\WP_Error Change id.
	 */
	public function propose( array $c ) {
		$type  = sanitize_key( $c['object_type'] );
		$id    = (int) $c['object_id'];
		$field = sanitize_key( $c['field'] );
		if ( ! Objects::supports( $type, $field ) ) {
			return new \WP_Error( 'seolity_field', __( 'This field cannot be changed by SEOlity.', 'seolity' ) );
		}
		$before = Objects::read( $this->plugin, $type, $id, $field );
		if ( null === $before ) {
			return new \WP_Error( 'seolity_object', __( 'Object not found.', 'seolity' ) );
		}
		$after = (string) $c['after'];
		if ( trim( $after ) === trim( $before ) ) {
			return new \WP_Error( 'seolity_nochange', __( 'Suggested value is identical to the current value.', 'seolity' ) );
		}
		$ai = isset( $c['ai'] ) ? (array) $c['ai'] : array();
		// Supersede older pending suggestions for the same field.
		$this->plugin->db->query(
			"UPDATE {changes} SET status = 'superseded' WHERE object_type = %s AND object_id = %d AND field = %s AND status = 'pending'",
			array( $type, $id, $field )
		);
		$change_id = $this->plugin->db->insert(
			'changes',
			array(
				'project_id'        => $this->plugin->db->project_id(),
				'batch_id'          => isset( $c['batch_id'] ) ? substr( sanitize_key( $c['batch_id'] ), 0, 40 ) : null,
				'object_type'       => $type,
				'object_id'         => $id,
				'field'             => $field,
				'url'               => Objects::url( $type, $id ),
				'before_value'      => $before,
				'after_value'       => $after,
				'status'            => 'pending',
				'source'            => isset( $c['source'] ) ? sanitize_key( $c['source'] ) : 'ai',
				'risk'              => Objects::risk( $field ),
				'ai_provider'       => isset( $ai['provider'] ) ? $ai['provider'] : null,
				'ai_model'          => isset( $ai['model'] ) ? $ai['model'] : null,
				'prompt_version'    => isset( $ai['prompt_version'] ) ? $ai['prompt_version'] : null,
				'keywords'          => isset( $c['keywords'] ) ? implode( ', ', array_slice( (array) $c['keywords'], 0, 15 ) ) : null,
				'score_before'      => isset( $c['score_before'] ) ? (int) $c['score_before'] : null,
				'score_after'       => isset( $c['score_after'] ) ? (int) $c['score_after'] : null,
				'reason'            => isset( $c['reason'] ) ? wp_strip_all_tags( (string) $c['reason'] ) : null,
				'recommendation_id' => isset( $c['recommendation_id'] ) ? (int) $c['recommendation_id'] : null,
				'user_id'           => get_current_user_id(),
				'created_at'        => Database::now(),
			)
		);
		if ( ! $change_id ) {
			return new \WP_Error( 'seolity_db', __( 'Could not store the change.', 'seolity' ) );
		}
		$this->plugin->audit_log->log( 'change.proposed', $type, $id, array( 'change_id' => $change_id, 'field' => $field ) );

		if ( ! empty( $c['auto_apply_allowed'] ) && $this->should_auto_apply( Objects::risk( $field ) ) ) {
			$this->apply( $change_id, null, true );
		}
		return $change_id;
	}

	/** Approval workflow: Manual / Semi-automatic / Autonomous. */
	public function should_auto_apply( $risk ) {
		$mode = $this->plugin->settings->get( 'approval_mode' );
		if ( 'semi' === $mode ) {
			return 'low' === $risk;
		}
		if ( 'autonomous' === $mode ) {
			return in_array( $risk, (array) apply_filters( 'seolity_autonomous_risks', array( 'low', 'medium' ) ), true );
		}
		return false;
	}

	public function get( $id ) {
		return Database::decode( $this->plugin->db->row( 'SELECT * FROM {changes} WHERE id = %d', array( (int) $id ) ), array( 'gsc_before', 'gsc_after' ) );
	}

	/**
	 * Apply a change (optionally with a user-edited value).
	 *
	 * @param int         $id      Change id.
	 * @param string|null $edited  Value edited by the user in the preview.
	 * @param bool        $system  True when applied by automation (skips user caps; logged as system).
	 * @return array|\WP_Error Updated change.
	 */
	public function apply( $id, $edited = null, $system = false ) {
		$change = $this->get( $id );
		if ( ! $change ) {
			return new \WP_Error( 'seolity_change', __( 'Change not found.', 'seolity' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( $change['status'], array( 'pending', 'applied_editor' ), true ) ) {
			return new \WP_Error( 'seolity_change_state', __( 'This change is no longer pending.', 'seolity' ), array( 'status' => 409 ) );
		}
		if ( ! $system && ! Capabilities::can_edit_object( $this->cap_type( $change ), (int) $change['object_id'] ) ) {
			return new \WP_Error( 'seolity_forbidden', __( 'You are not allowed to edit this item.', 'seolity' ), array( 'status' => 403 ) );
		}
		$current = Objects::read( $this->plugin, $change['object_type'], $change['object_id'], $change['field'] );
		$after   = null !== $edited ? (string) $edited : $change['after_value'];

		if ( 'applied_editor' !== $change['status'] ) {
			$res = Objects::write( $this->plugin, $change['object_type'], $change['object_id'], $change['field'], $after );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}
		$version = 1 + (int) $this->plugin->db->value(
			"SELECT MAX(version) FROM {changes} WHERE object_type = %s AND object_id = %d AND field = %s AND status IN ('applied','reverted')",
			array( $change['object_type'], $change['object_id'], $change['field'] )
		);
		$gsc_before = null;
		if ( $change['url'] && $this->plugin->gsc_oauth->is_connected() ) {
			$gsc_before = $this->plugin->gsc_analytics->page_metrics( $change['url'], 28 );
		}
		$this->plugin->db->update(
			'changes',
			array(
				'before_value' => null === $current ? $change['before_value'] : $current, // Real value at apply-time = accurate undo.
				'after_value'  => $after,
				'status'       => 'applied',
				'version'      => $version,
				'gsc_before'   => $gsc_before,
				'applied_at'   => Database::now(),
				'user_id'      => $system ? 0 : get_current_user_id(),
			),
			array( 'id' => (int) $id )
		);
		$change = $this->get( $id );
		$exp_id = $this->plugin->experiments->start_for_change( $change );
		if ( $exp_id ) {
			$this->plugin->db->update( 'changes', array( 'experiment_id' => $exp_id ), array( 'id' => (int) $id ) );
		}
		if ( $change['recommendation_id'] ) {
			$this->plugin->recommendations->mark( (int) $change['recommendation_id'], 'done' );
		}
		$this->plugin->audit_log->log(
			$system ? 'change.auto_applied' : 'change.applied',
			$change['object_type'],
			(int) $change['object_id'],
			array( 'change_id' => (int) $id, 'field' => $change['field'], 'edited' => null !== $edited )
		);
		do_action( 'seolity_change_applied', $change );
		return $this->get( $id );
	}

	/** Editor flows (Gutenberg/Classic/Elementor) apply client-side; we mark & finalize on save. */
	public function mark_applied_in_editor( $id ) {
		$change = $this->get( $id );
		if ( ! $change || 'pending' !== $change['status'] ) {
			return new \WP_Error( 'seolity_change_state', __( 'This change is no longer pending.', 'seolity' ), array( 'status' => 409 ) );
		}
		if ( ! Capabilities::can_edit_object( $this->cap_type( $change ), (int) $change['object_id'] ) ) {
			return new \WP_Error( 'seolity_forbidden', __( 'You are not allowed to edit this item.', 'seolity' ), array( 'status' => 403 ) );
		}
		$this->plugin->db->update( 'changes', array( 'status' => 'applied_editor' ), array( 'id' => (int) $id ) );
		if ( 'post' === $change['object_type'] ) {
			$pending   = (array) get_post_meta( (int) $change['object_id'], '_seolity_editor_changes', true );
			$pending[] = (int) $id;
			update_post_meta( (int) $change['object_id'], '_seolity_editor_changes', array_values( array_unique( array_filter( $pending ) ) ) );
		}
		return $this->get( $id );
	}

	/** Called on save_post: finalize editor-applied changes into versioned history. */
	public function finalize_editor_changes( $post_id ) {
		$ids = (array) get_post_meta( $post_id, '_seolity_editor_changes', true );
		if ( ! $ids ) {
			return;
		}
		delete_post_meta( $post_id, '_seolity_editor_changes' );
		foreach ( array_filter( $ids ) as $id ) {
			$change = $this->get( $id );
			if ( $change && 'applied_editor' === $change['status'] ) {
				$this->apply( (int) $id, Objects::read( $this->plugin, 'post', $post_id, $change['field'] ), true );
			}
		}
	}

	public function reject( $id, $note = '' ) {
		$change = $this->get( $id );
		if ( ! $change || ! in_array( $change['status'], array( 'pending', 'applied_editor' ), true ) ) {
			return new \WP_Error( 'seolity_change_state', __( 'This change is no longer pending.', 'seolity' ), array( 'status' => 409 ) );
		}
		$this->plugin->db->update( 'changes', array( 'status' => 'rejected' ), array( 'id' => (int) $id ) );
		$this->plugin->audit_log->log( 'change.rejected', $change['object_type'], (int) $change['object_id'], array( 'change_id' => (int) $id, 'note' => sanitize_text_field( $note ) ) );
		return $this->get( $id );
	}

	/**
	 * Undo an applied change: restores before_value and records the revert as a new version.
	 */
	public function revert( $id ) {
		$change = $this->get( $id );
		if ( ! $change || 'applied' !== $change['status'] ) {
			return new \WP_Error( 'seolity_change_state', __( 'Only applied changes can be reverted.', 'seolity' ), array( 'status' => 409 ) );
		}
		if ( ! Capabilities::can_edit_object( $this->cap_type( $change ), (int) $change['object_id'] ) ) {
			return new \WP_Error( 'seolity_forbidden', __( 'You are not allowed to edit this item.', 'seolity' ), array( 'status' => 403 ) );
		}
		$current = Objects::read( $this->plugin, $change['object_type'], $change['object_id'], $change['field'] );
		$res     = Objects::write( $this->plugin, $change['object_type'], $change['object_id'], $change['field'], (string) $change['before_value'] );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$this->plugin->db->update( 'changes', array( 'status' => 'reverted', 'reverted_at' => Database::now() ), array( 'id' => (int) $id ) );
		$version = 1 + (int) $this->plugin->db->value(
			'SELECT MAX(version) FROM {changes} WHERE object_type = %s AND object_id = %d AND field = %s',
			array( $change['object_type'], $change['object_id'], $change['field'] )
		);
		$this->plugin->db->insert(
			'changes',
			array(
				'project_id'   => $change['project_id'],
				'object_type'  => $change['object_type'],
				'object_id'    => $change['object_id'],
				'field'        => $change['field'],
				'url'          => $change['url'],
				'before_value' => $current,
				'after_value'  => $change['before_value'],
				'version'      => $version,
				'status'       => 'applied',
				'source'       => 'revert',
				'risk'         => $change['risk'],
				'reason'       => sprintf( 'Revert of change #%d', (int) $id ),
				'user_id'      => get_current_user_id(),
				'created_at'   => Database::now(),
				'applied_at'   => Database::now(),
			)
		);
		if ( $change['experiment_id'] ) {
			$this->plugin->db->update( 'experiments', array( 'status' => 'cancelled', 'reason' => 'Change reverted' ), array( 'id' => (int) $change['experiment_id'] ) );
		}
		$this->plugin->audit_log->log( 'change.reverted', $change['object_type'], (int) $change['object_id'], array( 'change_id' => (int) $id ) );
		return $this->get( $id );
	}

	/** Restore any historical version (its after_value) as a new applied version. */
	public function restore_version( $id ) {
		$change = $this->get( $id );
		if ( ! $change ) {
			return new \WP_Error( 'seolity_change', __( 'Change not found.', 'seolity' ), array( 'status' => 404 ) );
		}
		$new = $this->propose(
			array(
				'object_type' => $change['object_type'],
				'object_id'   => $change['object_id'],
				'field'       => $change['field'],
				'after'       => $change['after_value'],
				'source'      => 'restore',
				'reason'      => sprintf( 'Restore version %d (change #%d)', (int) $change['version'], (int) $id ),
			)
		);
		return is_wp_error( $new ) ? $new : $this->apply( $new );
	}

	/** Version history for one object (all fields or one). */
	public function history( $type, $id, $field = '' ) {
		$sql  = "SELECT * FROM {changes} WHERE object_type = %s AND object_id = %d AND status IN ('applied','reverted','pending','rejected')";
		$args = array( $type, (int) $id );
		if ( $field ) {
			$sql   .= ' AND field = %s';
			$args[] = $field;
		}
		$sql .= ' ORDER BY id DESC LIMIT 200';
		return array_map(
			static function ( $r ) {
				return Database::decode( $r, array( 'gsc_before', 'gsc_after' ) );
			},
			$this->plugin->db->rows( $sql, $args )
		);
	}

	public function query( array $f ) {
		$where = array( 'project_id = %d' );
		$args  = array( $this->plugin->db->project_id() );
		foreach ( array( 'status', 'object_type', 'field', 'source', 'risk', 'batch_id' ) as $k ) {
			if ( ! empty( $f[ $k ] ) ) {
				$where[] = "$k = %s";
				$args[]  = $f[ $k ];
			}
		}
		$per   = isset( $f['per_page'] ) ? max( 1, min( 200, (int) $f['per_page'] ) ) : 50;
		$page  = isset( $f['page'] ) ? max( 1, (int) $f['page'] ) : 1;
		$total = (int) $this->plugin->db->value( 'SELECT COUNT(*) FROM {changes} WHERE ' . implode( ' AND ', $where ), $args );
		$rows  = $this->plugin->db->rows(
			'SELECT id, batch_id, object_type, object_id, field, url, version, status, source, risk, ai_model, prompt_version, keywords, score_before, score_after, reason, experiment_id, created_at, applied_at, LEFT(before_value, 400) AS before_value, LEFT(after_value, 400) AS after_value FROM {changes} WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d',
			array_merge( $args, array( $per, ( $page - 1 ) * $per ) )
		);
		foreach ( $rows as &$r ) {
			$r['label'] = Objects::label( $r['object_type'], $r['object_id'] );
		}
		return array( 'items' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per );
	}

	private function cap_type( array $change ) {
		return 'term' === $change['object_type'] ? 'term' : $change['object_type'];
	}
}
