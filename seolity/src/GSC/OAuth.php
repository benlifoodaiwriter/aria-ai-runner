<?php
/**
 * Google OAuth 2.0 for Search Console (Authorization Code + PKCE, offline access).
 *
 * - Least privilege: only the read-only Search Console scope is requested.
 * - The site owner uses their OWN Google Cloud OAuth client (no third-party
 *   proxy sees tokens); redirect URI = wp-admin/admin-post.php.
 * - state is single-use, user-bound and expires after 10 minutes.
 * - Tokens are encrypted at rest (Settings::set_secret) and never sent to JS.
 *
 * @package SEOlity
 */

namespace SEOlity\GSC;

use SEOlity\Core\Capabilities;
use SEOlity\Core\Crypto;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class OAuth {

	const AUTH_URL   = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_URL  = 'https://oauth2.googleapis.com/token';
	const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';
	const SCOPE      = 'https://www.googleapis.com/auth/webmasters.readonly';

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public static function redirect_uri() {
		return admin_url( 'admin-post.php' );
	}

	public function client_id() {
		return trim( (string) $this->plugin->settings->get( 'gsc_client_id' ) );
	}

	public function is_configured() {
		return '' !== $this->client_id() && $this->plugin->settings->has_secret( 'gsc_client_secret' );
	}

	public function tokens() {
		$t = $this->plugin->settings->secret( 'gsc_tokens' );
		return is_array( $t ) ? $t : null;
	}

	public function is_connected() {
		$t = $this->tokens();
		return $t && ! empty( $t['refresh_token'] ) && '' !== (string) $this->plugin->settings->get( 'gsc_property' );
	}

	public function has_account() {
		$t = $this->tokens();
		return $t && ! empty( $t['refresh_token'] );
	}

	public function status() {
		$t = $this->tokens();
		return array(
			'configured'   => $this->is_configured(),
			'account'      => $this->has_account(),
			'connected'    => $this->is_connected(),
			'property'     => (string) $this->plugin->settings->get( 'gsc_property' ),
			'connected_at' => $t && isset( $t['connected_at'] ) ? $t['connected_at'] : null,
			'scope'        => self::SCOPE,
			'redirect_uri' => self::redirect_uri(),
			'last_sync'    => get_option( 'seolity_gsc_last_sync' ),
			'rows'         => (int) $this->plugin->db->value( 'SELECT COUNT(*) FROM {gsc_page_query} WHERE project_id = %d', array( $this->plugin->db->project_id() ) ),
		);
	}

	/** Build the consent URL (called via REST by a user with seolity_manage). */
	public function auth_url() {
		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'seolity_gsc_config', __( 'Enter your Google OAuth Client ID and Client Secret first.', 'seolity' ), array( 'status' => 400 ) );
		}
		$state    = 'seolity_' . Crypto::token( 24 );
		$verifier = Crypto::token( 48 );
		set_transient(
			'seolity_oauth_' . md5( $state ),
			array(
				'user'     => get_current_user_id(),
				'verifier' => $verifier,
			),
			10 * MINUTE_IN_SECONDS
		);
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ); // phpcs:ignore
		return add_query_arg(
			array(
				'client_id'             => rawurlencode( $this->client_id() ),
				'redirect_uri'          => rawurlencode( self::redirect_uri() ),
				'response_type'         => 'code',
				'scope'                 => rawurlencode( self::SCOPE ),
				'access_type'           => 'offline',
				'prompt'                => 'consent',
				'include_granted_scopes' => 'true',
				'state'                 => rawurlencode( $state ),
				'code_challenge'        => $challenge,
				'code_challenge_method' => 'S256',
			),
			self::AUTH_URL
		);
	}

	/** admin-post.php callback (fires for logged-in users without "action"). */
	public function handle_callback() {
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- OAuth state is the CSRF token.
		if ( 0 !== strpos( $state, 'seolity_' ) ) {
			return;
		}
		$back = admin_url( 'admin.php?page=seolity#/gsc' );
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You are not allowed to connect Search Console.', 'seolity' ), 403 );
		}
		$key  = 'seolity_oauth_' . md5( $state );
		$data = get_transient( $key );
		delete_transient( $key ); // Single use.
		if ( ! $data || (int) $data['user'] !== get_current_user_id() ) {
			wp_safe_redirect( add_query_arg( 'seolity_gsc', 'state_error', $back ) );
			exit;
		}
		if ( ! empty( $_GET['error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			wp_safe_redirect( add_query_arg( 'seolity_gsc', 'denied', $back ) );
			exit;
		}
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$res  = $this->token_request(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'redirect_uri'  => self::redirect_uri(),
				'code_verifier' => $data['verifier'],
			)
		);
		if ( is_wp_error( $res ) || empty( $res['refresh_token'] ) ) {
			$this->plugin->audit_log->log( 'gsc.connect_failed', 'integration', null, array( 'error' => is_wp_error( $res ) ? $res->get_error_message() : 'no refresh token' ) );
			wp_safe_redirect( add_query_arg( 'seolity_gsc', 'token_error', $back ) );
			exit;
		}
		$this->store_tokens( $res, true );
		$this->plugin->audit_log->log( 'gsc.connected', 'integration', null, array( 'scope' => isset( $res['scope'] ) ? $res['scope'] : '' ) );
		wp_safe_redirect( add_query_arg( 'seolity_gsc', 'connected', $back ) );
		exit;
	}

	private function token_request( array $params ) {
		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 20,
				'body'    => array_merge(
					array(
						'client_id'     => $this->client_id(),
						'client_secret' => (string) $this->plugin->settings->secret( 'gsc_client_secret' ),
					),
					$params
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) || ! is_array( $body ) || empty( $body['access_token'] ) ) {
			$msg = is_array( $body ) && isset( $body['error_description'] ) ? $body['error_description'] : ( is_array( $body ) && isset( $body['error'] ) ? $body['error'] : 'token error' );
			return new \WP_Error( 'seolity_gsc_token', $msg, array( 'oauth_error' => is_array( $body ) && isset( $body['error'] ) ? $body['error'] : '' ) );
		}
		return $body;
	}

	private function store_tokens( array $res, $initial = false ) {
		$current = $this->tokens();
		$tokens  = array(
			'access_token'  => $res['access_token'],
			'refresh_token' => ! empty( $res['refresh_token'] ) ? $res['refresh_token'] : ( $current ? $current['refresh_token'] : '' ),
			'expires_at'    => time() + ( isset( $res['expires_in'] ) ? (int) $res['expires_in'] : 3600 ) - 60,
			'scope'         => isset( $res['scope'] ) ? $res['scope'] : self::SCOPE,
			'connected_at'  => $initial || ! $current ? gmdate( 'c' ) : ( isset( $current['connected_at'] ) ? $current['connected_at'] : gmdate( 'c' ) ),
		);
		$this->plugin->settings->set_secret( 'gsc_tokens', $tokens );
		return $tokens;
	}

	/** @return string|\WP_Error Valid access token (auto-refresh). */
	public function access_token() {
		$t = $this->tokens();
		if ( ! $t || empty( $t['refresh_token'] ) ) {
			return new \WP_Error( 'seolity_gsc_disconnected', __( 'Google Search Console is not connected.', 'seolity' ) );
		}
		if ( ! empty( $t['access_token'] ) && $t['expires_at'] > time() ) {
			return $t['access_token'];
		}
		$res = $this->token_request(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $t['refresh_token'],
			)
		);
		if ( is_wp_error( $res ) ) {
			$data = $res->get_error_data();
			if ( isset( $data['oauth_error'] ) && 'invalid_grant' === $data['oauth_error'] ) {
				// Token revoked / expired: mark disconnected and alert.
				$this->plugin->settings->set_secret( 'gsc_tokens', null );
				$this->plugin->alerts->raise( 'gsc_disconnected', 'high', __( 'Search Console connection expired', 'seolity' ), __( 'Google revoked access. Reconnect Search Console to keep data flowing.', 'seolity' ), array(), 'gsc_invalid_grant_' . gmdate( 'Ymd' ) );
			}
			return $res;
		}
		$tokens = $this->store_tokens( $res );
		return $tokens['access_token'];
	}

	public function disconnect() {
		$t = $this->tokens();
		if ( $t && ! empty( $t['refresh_token'] ) ) {
			wp_remote_post( self::REVOKE_URL, array( 'timeout' => 10, 'body' => array( 'token' => $t['refresh_token'] ) ) );
		}
		$this->plugin->settings->set_secret( 'gsc_tokens', null );
		$this->plugin->settings->update( array( 'gsc_property' => '' ) );
		$this->plugin->audit_log->log( 'gsc.disconnected', 'integration' );
		return true;
	}

	/** Used by Http helper consumers. */
	public function auth_headers() {
		$token = $this->access_token();
		return is_wp_error( $token ) ? $token : array( 'Authorization' => 'Bearer ' . $token );
	}
}
