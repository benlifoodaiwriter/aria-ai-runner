<?php
/**
 * ChatGPT Plus/Pro subscription via OpenAI Codex CLI on GitHub Actions.
 *
 * @package SEOlity
 */

namespace SEOlity\AI\Providers;

defined( 'ABSPATH' ) || exit;

final class SubscriptionCodex extends Subscription {

	public function id() {
		return 'sub_codex';
	}

	public function label() {
		return __( 'ChatGPT subscription (Plus/Pro via GitHub)', 'seolity' );
	}

	protected function engine() {
		return 'codex';
	}

	public function model() {
		return (string) $this->plugin->settings->get( 'ai_model_sub_codex', '' );
	}
}
