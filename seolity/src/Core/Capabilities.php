<?php
/**
 * Granular capabilities + the "SEOlity Client" role for agency client access.
 *
 * seolity_view    – read dashboards, reports, data.
 * seolity_ai      – run AI analysis / generate suggestions (no write to site).
 * seolity_apply   – apply / revert changes to content.
 * seolity_manage  – settings, integrations, automation, redirects, robots.
 *
 * @package SEOlity
 */

namespace SEOlity\Core;

defined( 'ABSPATH' ) || exit;

final class Capabilities {

	const VIEW   = 'seolity_view';
	const AI     = 'seolity_ai';
	const APPLY  = 'seolity_apply';
	const MANAGE = 'seolity_manage';

	public static function all() {
		return array( self::VIEW, self::AI, self::APPLY, self::MANAGE );
	}

	public static function install() {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::all() as $cap ) {
				$admin->add_cap( $cap );
			}
		}
		$editor = get_role( 'editor' );
		if ( $editor ) {
			$editor->add_cap( self::VIEW );
			$editor->add_cap( self::AI );
			$editor->add_cap( self::APPLY );
		}
		$shop_manager = get_role( 'shop_manager' );
		if ( $shop_manager ) {
			$shop_manager->add_cap( self::VIEW );
			$shop_manager->add_cap( self::AI );
			$shop_manager->add_cap( self::APPLY );
		}
		if ( ! get_role( 'seolity_client' ) ) {
			add_role(
				'seolity_client',
				__( 'SEOlity Client', 'seolity' ),
				array(
					'read'     => true,
					self::VIEW => true,
				)
			);
		}
	}

	public static function uninstall() {
		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( self::all() as $cap ) {
				$role->remove_cap( $cap );
			}
		}
		remove_role( 'seolity_client' );
	}

	public static function register_hooks() {
		// Super admins on multisite always pass.
		add_filter(
			'map_meta_cap',
			static function ( $caps, $cap ) {
				if ( in_array( $cap, self::all(), true ) && is_multisite() && is_super_admin() ) {
					return array( 'exist' );
				}
				return $caps;
			},
			10,
			2
		);
	}

	/**
	 * Applying a change to a specific object additionally requires the native edit capability.
	 *
	 * @param string $object_type post|product|term:{taxonomy}|user|attachment|option.
	 * @param int    $object_id   Object id.
	 */
	public static function can_edit_object( $object_type, $object_id ) {
		if ( ! current_user_can( self::APPLY ) ) {
			return false;
		}
		if ( in_array( $object_type, array( 'post', 'product', 'attachment', 'page' ), true ) ) {
			return current_user_can( 'edit_post', (int) $object_id );
		}
		if ( 0 === strpos( $object_type, 'term' ) ) {
			return current_user_can( 'edit_term', (int) $object_id );
		}
		if ( 'user' === $object_type ) {
			return current_user_can( 'edit_user', (int) $object_id );
		}
		return current_user_can( self::MANAGE );
	}
}
