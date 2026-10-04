<?php
/**
 * Any OpenAI-compatible endpoint (vLLM, LM Studio, Azure-style gateways, LiteLLM…).
 *
 * @package SEOlity
 */

namespace SEOlity\AI\Providers;

use SEOlity\Core\Http;

defined( 'ABSPATH' ) || exit;

final class CustomApi extends OpenAI {

	public function id() {
		return 'custom';
	}

	public function label() {
		return __( 'Custom (OpenAI-compatible)', 'seolity' );
	}

	public function is_configured() {
		return '' !== $this->model() && '' !== (string) $this->plugin->settings->get( 'custom_base_url' );
	}

	protected function endpoint() {
		$base = untrailingslashit( (string) $this->plugin->settings->get( 'custom_base_url' ) );
		return preg_match( '#/chat/completions$#', $base ) ? $base : $base . '/chat/completions';
	}

	protected function headers() {
		$key = $this->api_key();
		return $key ? array( 'Authorization' => 'Bearer ' . $key ) : array();
	}

	protected function supports_json_schema() {
		return (bool) apply_filters( 'seolity_custom_api_json_schema', false );
	}

	public function complete( array $request ) {
		if ( ! Http::is_safe_public_url( $this->endpoint(), (bool) apply_filters( 'seolity_custom_api_allow_local', true ) ) ) {
			return new \WP_Error( 'seolity_ai_url', __( 'Custom AI endpoint URL is not allowed.', 'seolity' ) );
		}
		return parent::complete( $request );
	}
}
