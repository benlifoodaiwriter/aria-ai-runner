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

	public function model() {
		return (string) $this->plugin->settings->get( 'ai_model_' . $this->id(), '' );
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
