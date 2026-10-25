<?php
/**
 * Shared provider plumbing (settings, HTTP, error normalization).
 *
 * @package SEOlity
 */

namespace SEOlity\AI;

use SEOlity\Core\Http;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

abstract class AbstractProvider implements ProviderInterface {

	/** @var Plugin */
	protected $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/** @var array<string,string> Model used instead of the saved one (connection tests before activation). */
	private static $override = array();

	public function model() {
		if ( isset( self::$override[ $this->id() ] ) ) {
			return self::$override[ $this->id() ];
		}
		return (string) $this->plugin->settings->get( 'ai_model_' . $this->id(), '' );
	}

	/** Use another model for the next requests of this provider (null restores the saved one). */
	public function override_model( $model ) {
		if ( null === $model ) {
			unset( self::$override[ $this->id() ] );
		} else {
			self::$override[ $this->id() ] = (string) $model;
		}
	}

	/** Secret that holds this provider's key (empty when it needs none). */
	public function key_name() {
		return $this->id() . '_api_key';
	}

	public function has_key() {
		return '' !== $this->api_key();
	}

	/**
	 * Models this connection can use, from the provider's own API.
	 *
	 * @return array|\WP_Error list of {id, label, created?, context?, chat (bool), note?}
	 */
	public function list_models() {
		return new \WP_Error( 'seolity_ai_models', __( 'This provider does not offer a model list.', 'seolity' ) );
	}

	/** GET a provider API (same network route as the AI requests). */
	protected function get( $url, array $headers, $timeout = 30 ) {
		$res = $this->plugin->gateway->request( 'GET', $url, null, $headers, $timeout );
		if ( is_wp_error( $res ) ) {
			return new \WP_Error( 'seolity_ai_http', sprintf( /* translators: 1 provider 2 error */ __( '%1$s could not be reached: %2$s', 'seolity' ), $this->label(), $res->get_error_message() ) );
		}
		if ( $res['status'] < 200 || $res['status'] >= 300 ) {
			$message = $this->extract_error( $res['body'], $res['raw'] );
			$code    = in_array( (int) $res['status'], array( 401, 403 ), true ) ? 'seolity_ai_auth' : ( 429 === (int) $res['status'] ? 'seolity_ai_rate_limited' : 'seolity_ai_api' );
			return new \WP_Error( $code, sprintf( /* translators: 1 provider 2 status 3 message */ __( '%1$s API error (%2$d): %3$s', 'seolity' ), $this->label(), $res['status'], $message ), array( 'status' => (int) $res['status'] ) );
		}
		return is_array( $res['body'] ) ? $res['body'] : new \WP_Error( 'seolity_ai_parse', __( 'AI provider returned a non-JSON response.', 'seolity' ) );
	}

	protected function api_key() {
		return (string) $this->plugin->settings->secret( $this->id() . '_api_key' );
	}

	public function is_configured() {
		return '' !== $this->api_key() && '' !== $this->model();
	}

	protected function timeout() {
		return (int) $this->plugin->settings->get( 'ai_timeout', 120 );
	}

	/**
	 * POST JSON and normalize transport/API errors.
	 *
	 * @return array|\WP_Error Decoded body.
	 */
	protected function post( $url, array $body, array $headers ) {
		// Direct, or through the owner's Cloudflare worker / GitHub runner (Connections → Server & speed).
		$res = $this->plugin->gateway->request( 'POST', $url, $body, $headers, $this->timeout() );
		if ( is_wp_error( $res ) && 'seolity_route_failed' === $res->get_error_code() ) {
			return $res;
		}
		if ( is_wp_error( $res ) ) {
			return new \WP_Error( 'seolity_ai_http', sprintf( /* translators: 1 provider 2 error */ __( '%1$s request failed: %2$s', 'seolity' ), $this->label(), $res->get_error_message() ) );
		}
		if ( $res['status'] < 200 || $res['status'] >= 300 ) {
			$message = $this->extract_error( $res['body'], $res['raw'] );
			$code    = 429 === $res['status'] ? 'seolity_ai_rate_limited' : ( in_array( $res['status'], array( 401, 403 ), true ) ? 'seolity_ai_auth' : 'seolity_ai_api' );
			return new \WP_Error( $code, sprintf( /* translators: 1 provider 2 status 3 message */ __( '%1$s API error (%2$d): %3$s', 'seolity' ), $this->label(), $res['status'], $message ), array( 'status' => $res['status'] ) );
		}
		if ( ! is_array( $res['body'] ) ) {
			return new \WP_Error( 'seolity_ai_parse', __( 'AI provider returned a non-JSON response.', 'seolity' ) );
		}
		return $res['body'];
	}

	protected function extract_error( $body, $raw ) {
		if ( is_array( $body ) ) {
			if ( isset( $body['error']['message'] ) ) {
				return (string) $body['error']['message'];
			}
			if ( isset( $body['error'] ) && is_string( $body['error'] ) ) {
				return $body['error'];
			}
			if ( isset( $body['message'] ) ) {
				return (string) $body['message'];
			}
		}
		return mb_substr( wp_strip_all_tags( (string) $raw ), 0, 300 );
	}

	/** Instruction appended when the provider lacks native schema enforcement. */
	protected function schema_instruction( $schema ) {
		return "\n\nRespond ONLY with a single JSON object (no markdown fences, no commentary) that validates against this JSON Schema:\n" . wp_json_encode( $schema );
	}
}
