<?php
/**
 * GitHub Models — AI models served by GitHub (models.github.ai), called directly from WordPress
 * with a GitHub personal access token that has the "models: read" permission.
 *
 * Every GitHub account has free, rate-limited access; GitHub Copilot plans raise the limits and
 * unlock more models. This is GitHub's official REST inference API — no CLI, no runner.
 * Docs: https://docs.github.com/en/rest/models
 *
 * @package SEOlity
 */

namespace SEOlity\AI\Providers;

defined( 'ABSPATH' ) || exit;

final class GitHubModels extends OpenAI {

	public function id() {
		return 'github_models';
	}

	public function label() {
		return __( 'GitHub Models (GitHub / Copilot account)', 'seolity' );
	}

	protected function endpoint() {
		return 'https://models.github.ai/inference/chat/completions';
	}

	protected function headers() {
		return array(
			'Authorization'        => 'Bearer ' . $this->api_key(),
			'Accept'               => 'application/vnd.github+json',
			'X-GitHub-Api-Version' => '2022-11-28',
		);
	}

	protected static function is_chat_model( $id ) {
		return true;
	}

	/** Catalog API: https://docs.github.com/en/rest/models/catalog — models this token may call. */
	public function list_models() {
		$body = $this->get( 'https://models.github.ai/catalog/models', $this->headers() );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		$out = array();
		foreach ( (array) $body as $m ) {
			if ( ! is_array( $m ) || empty( $m['id'] ) ) {
				continue;
			}
			$outputs = isset( $m['supported_output_modalities'] ) ? (array) $m['supported_output_modalities'] : array( 'text' );
			$out[]   = array(
				'id'      => (string) $m['id'],
				'label'   => isset( $m['name'] ) ? (string) $m['name'] : (string) $m['id'],
				'context' => isset( $m['limits']['max_input_tokens'] ) ? (int) $m['limits']['max_input_tokens'] : 0,
				'chat'    => in_array( 'text', $outputs, true ) && ! in_array( 'embeddings', $outputs, true ),
				'note'    => isset( $m['rate_limit_tier'] ) ? sprintf( /* translators: %s: tier */ __( 'rate limit: %s', 'seolity' ), $m['rate_limit_tier'] ) : '',
			);
		}
		return $out;
	}
}
