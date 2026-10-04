<?php
/**
 * Base for subscription providers: requests run on GitHub Actions with the
 * official CLI of an AI plan the site owner already pays for.
 *
 * @package SEOlity
 */

namespace SEOlity\AI\Providers;

use SEOlity\AI\AbstractProvider;

defined( 'ABSPATH' ) || exit;

abstract class Subscription extends AbstractProvider {

	/** Engine name understood by runner/runner.mjs. */
	abstract protected function engine();

	public function is_configured() {
		return $this->plugin->subscription->is_ready( $this->engine() );
	}

	/** Answers asynchronously: may throw \SEOlity\AI\Deferred. */
	public function complete( array $request ) {
		return $this->plugin->subscription->complete( $this->engine(), $this->model(), $request );
	}
}
