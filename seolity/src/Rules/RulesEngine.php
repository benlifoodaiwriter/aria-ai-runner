<?php
/**
 * SEO Rules Engine (section 34): IF <conditions> THEN <actions>.
 *
 * Triggers: content_published, content_updated, image_uploaded, daily.
 * Mode "suggest" creates pending changes/recommendations; mode "auto" lets
 * actions auto-apply within the global approval mode's risk limits.
 * A per-run action cap keeps AI spend predictable.
 *
 * @package SEOlity
 */

namespace SEOlity\Rules;

use SEOlity\Analysis\Text;
use SEOlity\Core\Database;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class RulesEngine {

	const TRIGGERS = array( 'content_published', 'content_updated', 'image_uploaded', 'daily' );
	const FIELDS   = array( 'post_type', 'missing_alt', 'meta_description_missing', 'title_length_gt', 'seo_score_lt', 'word_count_lt', 'ctr_lt', 'impressions_gt', 'position_gt', 'position_lt', 'has_faq', 'no_short_description', 'inlinks_lt' );
	const ACTIONS  = array( 'generate_alt', 'optimize_meta', 'generate_faq', 'optimize_content', 'suggest_internal_links', 'add_noindex', 'alert', 'recommend' );
	const MAX_ACTIONS_PER_RUN = 25;

	/** @var Plugin */
	private $plugin;

	/** @var int */
	private $budget = self::MAX_ACTIONS_PER_RUN;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public static function presets() {
		return array(
			array( 'name' => __( 'Product image without ALT → Generate ALT', 'seolity' ), 'trigger_event' => 'daily', 'conditions' => array( array( 'field' => 'post_type', 'op' => '=', 'value' => 'product' ), array( 'field' => 'missing_alt', 'op' => '=', 'value' => '1' ) ), 'actions' => array( array( 'type' => 'generate_alt' ) ), 'mode' => 'auto' ),
			array( 'name' => __( 'CTR < 1% with > 500 impressions → Rewrite title + meta', 'seolity' ), 'trigger_event' => 'daily', 'conditions' => array( array( 'field' => 'ctr_lt', 'op' => '<', 'value' => '1' ), array( 'field' => 'impressions_gt', 'op' => '>', 'value' => '500' ) ), 'actions' => array( array( 'type' => 'optimize_meta' ) ), 'mode' => 'suggest' ),
			array( 'name' => __( 'New post published → Suggest internal links', 'seolity' ), 'trigger_event' => 'content_published', 'conditions' => array( array( 'field' => 'post_type', 'op' => '=', 'value' => 'post' ) ), 'actions' => array( array( 'type' => 'suggest_internal_links' ) ), 'mode' => 'suggest' ),
			array( 'name' => __( 'Published without meta description → Write one', 'seolity' ), 'trigger_event' => 'content_published', 'conditions' => array( array( 'field' => 'meta_description_missing', 'op' => '=', 'value' => '1' ) ), 'actions' => array( array( 'type' => 'optimize_meta' ) ), 'mode' => 'auto' ),
			array( 'name' => __( 'Uploaded image → Generate ALT', 'seolity' ), 'trigger_event' => 'image_uploaded', 'conditions' => array( array( 'field' => 'missing_alt', 'op' => '=', 'value' => '1' ) ), 'actions' => array( array( 'type' => 'generate_alt' ) ), 'mode' => 'auto' ),
			array( 'name' => __( 'Ranking 8–20 → Recommend content refresh', 'seolity' ), 'trigger_event' => 'daily', 'conditions' => array( array( 'field' => 'position_gt', 'op' => '>', 'value' => '8' ), array( 'field' => 'position_lt', 'op' => '<', 'value' => '20' ), array( 'field' => 'impressions_gt', 'op' => '>', 'value' => '300' ) ), 'actions' => array( array( 'type' => 'recommend' ) ), 'mode' => 'suggest' ),
		);
	}

	public function all() {
		return array_map(
			static function ( $r ) {
				return Database::decode( $r, array( 'conditions', 'actions' ) );
			},
			$this->plugin->db->rows( 'SELECT * FROM {rules} WHERE project_id = %d ORDER BY id DESC', array( $this->plugin->db->project_id() ) )
		);
	}

	/** Validate + save. */
	public function save( array $r, $id = 0 ) {
		$name    = sanitize_text_field( isset( $r['name'] ) ? $r['name'] : '' );
		$trigger = isset( $r['trigger_event'] ) && in_array( $r['trigger_event'], self::TRIGGERS, true ) ? $r['trigger_event'] : null;
		if ( '' === $name || ! $trigger ) {
			return new \WP_Error( 'seolity_rule', __( 'Rule needs a name and a valid trigger.', 'seolity' ), array( 'status' => 400 ) );
		}
		$conditions = array();
		foreach ( (array) ( isset( $r['conditions'] ) ? $r['conditions'] : array() ) as $c ) {
			if ( isset( $c['field'] ) && in_array( $c['field'], self::FIELDS, true ) ) {
				$conditions[] = array( 'field' => $c['field'], 'op' => in_array( isset( $c['op'] ) ? $c['op'] : '=', array( '=', '!=', '<', '>' ), true ) ? $c['op'] : '=', 'value' => sanitize_text_field( isset( $c['value'] ) ? (string) $c['value'] : '' ) );
			}
		}
		$actions = array();
		foreach ( (array) ( isset( $r['actions'] ) ? $r['actions'] : array() ) as $a ) {
			if ( isset( $a['type'] ) && in_array( $a['type'], self::ACTIONS, true ) ) {
				$actions[] = array( 'type' => $a['type'] );
			}
		}
		if ( ! $actions ) {
			return new \WP_Error( 'seolity_rule', __( 'Add at least one action.', 'seolity' ), array( 'status' => 400 ) );
		}
		$row = array(
			'name'          => $name,
			'trigger_event' => $trigger,
			'conditions'    => $conditions,
			'actions'       => $actions,
			'mode'          => isset( $r['mode'] ) && 'auto' === $r['mode'] ? 'auto' : 'suggest',
			'enabled'       => ! isset( $r['enabled'] ) || ! empty( $r['enabled'] ),
		);
		if ( $id ) {
			$this->plugin->db->update( 'rules', $row, array( 'id' => (int) $id, 'project_id' => $this->plugin->db->project_id() ) );
		} else {
			$row['project_id'] = $this->plugin->db->project_id();
			$row['created_at'] = Database::now();
			$id                = $this->plugin->db->insert( 'rules', $row );
		}
		$this->plugin->audit_log->log( 'rule.saved', 'rule', (int) $id, array( 'name' => $name ) );
		return (int) $id;
	}

	public function delete( $id ) {
		$this->plugin->audit_log->log( 'rule.deleted', 'rule', (int) $id );
		return (bool) $this->plugin->db->delete( 'rules', array( 'id' => (int) $id, 'project_id' => $this->plugin->db->project_id() ) );
	}

	private function rules_for( $event ) {
		return array_map(
			static function ( $r ) {
				return Database::decode( $r, array( 'conditions', 'actions' ) );
			},
			$this->plugin->db->rows( 'SELECT * FROM {rules} WHERE project_id = %d AND trigger_event = %s AND enabled = 1', array( $this->plugin->db->project_id(), $event ) )
		);
	}

	public function run_event( $event, array $context ) {
		$this->budget = self::MAX_ACTIONS_PER_RUN;
		$matched      = 0;
		foreach ( $this->rules_for( $event ) as $rule ) {
			if ( $this->matches( $rule['conditions'], $context ) ) {
				$this->execute( $rule, $context );
				$matched++;
			}
		}
		return $matched;
	}

	/** Daily rules: evaluate against candidate objects. */
	public function run_scheduled() {
		$this->budget = self::MAX_ACTIONS_PER_RUN;
		$rules        = $this->rules_for( 'daily' );
		if ( ! $rules ) {
			return 0;
		}
		$candidates = array();
		if ( $this->plugin->gsc_analytics->has_data() ) {
			foreach ( $this->plugin->gsc_analytics->top_pages( 28, 300 ) as $p ) {
				list( $type, $id ) = $this->plugin->recommendations->resolve( $p['page'] );
				if ( $type ) {
					$candidates[ $type . ':' . $id ] = array( 'object_type' => $type, 'object_id' => $id, 'gsc' => $p );
				}
			}
		}
		foreach ( $this->plugin->db->column( "SELECT DISTINCT parent_id FROM {images} WHERE project_id = %d AND parent_id > 0 AND issues LIKE %s LIMIT 200", array( $this->plugin->db->project_id(), '%missing_alt%' ) ) as $pid ) {
			if ( ! isset( $candidates[ 'post:' . $pid ] ) ) {
				$candidates[ 'post:' . $pid ] = array( 'object_type' => 'post', 'object_id' => (int) $pid );
			}
		}
		$matched = 0;
		foreach ( $rules as $rule ) {
			foreach ( $candidates as $ctx ) {
				if ( $this->budget <= 0 ) {
					break 2;
				}
				if ( $this->matches( $rule['conditions'], $ctx ) ) {
					$this->execute( $rule, $ctx );
					$matched++;
				}
			}
		}
		return $matched;
	}

	/** Evaluate all conditions (AND). */
	public function matches( array $conditions, array $ctx ) {
		foreach ( $conditions as $c ) {
			$value = $this->field_value( $c['field'], $ctx );
			if ( null === $value ) {
				return false;
			}
			$target = $c['value'];
			switch ( $c['op'] ) {
				case '<':
					$ok = (float) $value < (float) $target;
					break;
				case '>':
					$ok = (float) $value > (float) $target;
					break;
				case '!=':
					$ok = (string) $value !== (string) $target;
					break;
				default:
					$ok = (string) $value === (string) $target;
			}
			if ( ! $ok ) {
				return false;
			}
		}
		return true;
	}

	private function field_value( $field, array $ctx ) {
		$type = isset( $ctx['object_type'] ) ? $ctx['object_type'] : '';
		$id   = isset( $ctx['object_id'] ) ? (int) $ctx['object_id'] : 0;
		$gsc  = isset( $ctx['gsc'] ) ? $ctx['gsc'] : null;
		if ( in_array( $field, array( 'ctr_lt', 'impressions_gt', 'position_gt', 'position_lt' ), true ) && ! $gsc && 'post' === $type && $this->plugin->gsc_analytics->has_data() ) {
			$gsc = $this->plugin->gsc_analytics->page_metrics( get_permalink( $id ), 28 );
		}
		switch ( $field ) {
			case 'post_type':
				return 'attachment' === $type ? 'attachment' : ( 'post' === $type ? get_post_type( $id ) : $type );
			case 'missing_alt':
				if ( 'attachment' === $type ) {
					return '' === trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) ? '1' : '0';
				}
				return (int) $this->plugin->db->value( "SELECT COUNT(*) FROM {images} WHERE project_id = %d AND parent_id = %d AND issues LIKE %s", array( $this->plugin->db->project_id(), $id, '%missing_alt%' ) ) > 0 ? '1' : '0';
			case 'meta_description_missing':
				return 'post' === $type ? ( '' === (string) $this->plugin->meta->get_post( $id, 'meta_description' ) ? '1' : '0' ) : null;
			case 'title_length_gt':
				return 'post' === $type ? mb_strlen( (string) ( $this->plugin->meta->get_post( $id, 'meta_title' ) ? $this->plugin->meta->get_post( $id, 'meta_title' ) : get_the_title( $id ) ) ) : null;
			case 'seo_score_lt':
				$s = $this->plugin->db->value( 'SELECT score FROM {content_analysis} WHERE object_type = %s AND object_id = %d', array( $type, $id ) );
				return null === $s ? null : (int) $s;
			case 'word_count_lt':
				return 'post' === $type ? Text::word_count( Text::strip( get_post_field( 'post_content', $id ) ) ) : null;
			case 'ctr_lt':
				return $gsc ? $gsc['ctr'] : null;
			case 'impressions_gt':
				return $gsc ? $gsc['impressions'] : null;
			case 'position_gt':
			case 'position_lt':
				return $gsc ? $gsc['position'] : null;
			case 'has_faq':
				return 'post' === $type ? ( get_post_meta( $id, '_seolity_faq', true ) ? '1' : '0' ) : null;
			case 'no_short_description':
				return 'post' === $type && 'product' === get_post_type( $id ) ? ( '' === trim( (string) get_post_field( 'post_excerpt', $id ) ) ? '1' : '0' ) : null;
			case 'inlinks_lt':
				$url = 'post' === $type ? get_permalink( $id ) : '';
				$v   = $url ? $this->plugin->db->value( 'SELECT inlinks FROM {urls} WHERE project_id = %d AND url_hash = %s', array( $this->plugin->db->project_id(), Database::url_hash( $url ) ) ) : null;
				return null === $v ? null : (int) $v;
		}
		return null;
	}

	private function execute( array $rule, array $ctx ) {
		$auto = 'auto' === $rule['mode'];
		$type = isset( $ctx['object_type'] ) ? $ctx['object_type'] : '';
		$id   = isset( $ctx['object_id'] ) ? (int) $ctx['object_id'] : 0;
		$log  = array();
		foreach ( $rule['actions'] as $a ) {
			if ( $this->budget-- <= 0 ) {
				break;
			}
			switch ( $a['type'] ) {
				case 'generate_alt':
					$ids = 'attachment' === $type ? array( $id ) : array_map( 'intval', $this->plugin->db->column( "SELECT attachment_id FROM {images} WHERE project_id = %d AND parent_id = %d AND issues LIKE %s LIMIT 20", array( $this->plugin->db->project_id(), $id, '%missing_alt%' ) ) );
					$res = $ids ? $this->plugin->images->generate( $ids, $auto ) : null;
					break;
				case 'optimize_meta':
					$res = in_array( $type, array( 'post', 'term' ), true ) ? $this->plugin->optimizer->meta( $type, $id, $auto ) : null;
					break;
				case 'generate_faq':
					$res = 'post' === $type ? $this->plugin->optimizer->faq( $id ) : null;
					break;
				case 'optimize_content':
					$res = in_array( $type, array( 'post', 'term' ), true ) ? $this->plugin->optimizer->optimize( array( 'object_type' => $type, 'object_id' => $id, 'field' => 'term' === $type ? 'term_description' : 'post_content' ) ) : null;
					break;
				case 'suggest_internal_links':
					$res = 'post' === $type ? $this->plugin->links->suggest_for( $id ) : null;
					break;
				case 'add_noindex':
					$res = in_array( $type, array( 'post', 'term' ), true ) ? $this->plugin->changes->propose( array( 'object_type' => $type, 'object_id' => $id, 'field' => 'noindex', 'after' => '1', 'source' => 'rule', 'reason' => $rule['name'] ) ) : null;
					break;
				case 'alert':
					/* translators: 1 rule 2 object */
					$res = $this->plugin->alerts->raise( 'rule', 'info', sprintf( __( 'Rule matched: %1$s (%2$s)', 'seolity' ), $rule['name'], \SEOlity\Content\Objects::label( $type, $id ) ), '', $ctx, 'rule_' . $rule['id'] . '_' . $type . $id . gmdate( 'Ymd' ) );
					break;
				case 'recommend':
					$res = $this->plugin->recommendations->add(
						array(
							'type'        => 'rule',
							'key'         => $rule['id'] . '|' . $type . $id,
							'object_type' => $type,
							'object_id'   => $id,
							'url'         => \SEOlity\Content\Objects::url( $type, $id ),
							/* translators: 1 rule 2 object */
							'title'       => sprintf( __( '%1$s: %2$s', 'seolity' ), $rule['name'], \SEOlity\Content\Objects::label( $type, $id ) ),
							'reason'      => __( 'Matched your automation rule.', 'seolity' ),
							'evidence'    => array( 'source' => 'rule', 'rule' => $rule['name'], 'context' => isset( $ctx['gsc'] ) ? $ctx['gsc'] : array() ),
							'action'      => array( 'type' => 'optimize_content', 'object_type' => $type, 'object_id' => $id ),
							'impact'      => 50,
							'effort'      => 40,
							'confidence'  => 50,
						)
					);
					break;
				default:
					$res = null;
			}
			$log[] = array( 'action' => $a['type'], 'ok' => ! is_wp_error( $res ), 'error' => is_wp_error( $res ) ? $res->get_error_message() : null );
		}
		$this->plugin->db->query( 'UPDATE {rules} SET run_count = run_count + 1, last_run = %s WHERE id = %d', array( Database::now(), (int) $rule['id'] ) );
		$this->plugin->audit_log->log( 'rule.executed', $type, $id, array( 'rule_id' => (int) $rule['id'], 'actions' => $log ) );
	}
}
