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
		$m = parent::model();
		return '' !== $m ? $m : 'sonnet';
	}

	/**
	 * Claude Code accepts the model aliases below (each resolves to the newest model of that family
	 * that your plan includes) or a full model id. There is no official model-list API for
	 * subscriptions, so availability is confirmed by a real test run.
	 */
	public function list_models() {
		return array(
			array( 'id' => 'sonnet', 'label' => __( 'Sonnet (latest)', 'seolity' ), 'chat' => true, 'note' => __( 'alias — included in Pro and Max', 'seolity' ) ),
			array( 'id' => 'opus', 'label' => __( 'Opus (latest)', 'seolity' ), 'chat' => true, 'note' => __( 'alias — availability depends on your plan', 'seolity' ) ),
			array( 'id' => 'haiku', 'label' => __( 'Haiku (latest)', 'seolity' ), 'chat' => true, 'note' => __( 'alias', 'seolity' ) ),
		);
	}
}
