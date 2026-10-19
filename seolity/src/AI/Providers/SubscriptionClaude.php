<?php
/**
 * Claude Pro/Max subscription via Claude Code on GitHub Actions.
 *
 * @package SEOlity
 */

namespace SEOlity\AI\Providers;

defined( 'ABSPATH' ) || exit;

final class SubscriptionClaude extends Subscription {

	public function id() {
		return 'sub_claude';
	}

	public function label() {
		return __( 'Claude subscription (Pro/Max)', 'seolity' );
	}

	protected function engine() {
		return 'claude';
	}

	public function model() {
		$m = (string) $this->plugin->settings->get( 'ai_model_sub_claude', 'sonnet' );
		return '' !== $m ? $m : 'sonnet';
	}
}
