<?php
/**
 * OpenRouter (OpenAI-compatible, hundreds of models behind one key).
 *
 * @package SEOlity
 */

namespace SEOlity\AI\Providers;

defined( 'ABSPATH' ) || exit;

final class OpenRouter extends OpenAI {

	public function id() {
		return 'openrouter';
	}

	public function label() {
		return 'OpenRouter';
	}

	protected function endpoint() {
		return 'https://openrouter.ai/api/v1/chat/completions';
	}

	protected function headers() {
		return array(
			'Authorization' => 'Bearer ' . $this->api_key(),
			'HTTP-Referer'  => home_url( '/' ),
			'X-Title'       => 'SEOlity',
		);
	}
}
