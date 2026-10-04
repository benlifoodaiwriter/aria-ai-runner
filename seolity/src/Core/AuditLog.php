<?php
/**
 * Append-only audit log of every sensitive action (settings, AI changes,
 * redirects, automation runs, integrations). IPs are stored hashed.
 *
 * @package SEOlity
 */

namespace SEOlity\Core;

use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class AuditLog {

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * @param string   $action      Machine action name (e.g. change.applied).
	 * @param string   $object_type Object type.
	 * @param int|null $object_id   Object id.
	 * @param array    $details     Extra details (no secrets!).
	 */
	public function log( $action, $object_type = null, $object_id = null, array $details = array() ) {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$this->plugin->db->insert(
			'audit_log',
			array(
				'project_id'  => $this->plugin->db->project_id(),
				'user_id'     => get_current_user_id(),
				'action'      => substr( sanitize_key( str_replace( '.', '_', $action ) ), 0, 60 ),
				'object_type' => $object_type ? substr( sanitize_key( $object_type ), 0, 40 ) : null,
				'object_id'   => null === $object_id ? null : (int) $object_id,
				'details'     => $details,
				'ip_hash'     => $ip ? hash_hmac( 'sha1', $ip, wp_salt( 'nonce' ) ) : null,
				'created_at'  => Database::now(),
			)
		);
	}

	public function recent( $limit = 50, $offset = 0, $action = '' ) {
		$db  = $this->plugin->db;
		$sql = 'SELECT l.*, u.display_name FROM {audit_log} l LEFT JOIN ' . $db->wpdb->users . ' u ON u.ID = l.user_id WHERE l.project_id = %d';
		$arg = array( $db->project_id() );
		if ( $action ) {
			$sql  .= ' AND l.action = %s';
			$arg[] = $action;
		}
		$sql  .= ' ORDER BY l.id DESC LIMIT %d OFFSET %d';
		$arg[] = (int) $limit;
		$arg[] = (int) $offset;
		return array_map(
			static function ( $r ) {
				unset( $r['ip_hash'] );
				return Database::decode( $r, array( 'details' ) );
			},
			$db->rows( $sql, $arg )
		);
	}
}
