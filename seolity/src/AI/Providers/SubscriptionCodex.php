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
		return __( 'ChatGPT subscription (Plus/Pro)', 'seolity' );
	}

	protected function engine() {
		return 'codex';
	}

	public function model() {
		return parent::model();
	}

	/**
	 * Codex picks the model your ChatGPT plan includes when no model is given; a specific model id
	 * can be entered and is confirmed by a real test run (there is no official model-list API).
	 */
	public function list_models() {
		return array(
			array( 'id' => '', 'label' => __( 'Default model of your ChatGPT plan', 'seolity' ), 'chat' => true, 'note' => __( 'recommended', 'seolity' ) ),
		);
	}
}
