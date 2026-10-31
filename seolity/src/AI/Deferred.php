<?php
/**
 * Thrown when an AI request was handed to an asynchronous runner (subscription
 * AI through GitHub Actions) and the answer is not available yet.
 *
 * Callers never need to handle it: the REST router turns it into HTTP 202 (the
 * admin UI waits and re-sends the same request), and the queue re-schedules the
 * job without counting an attempt. Re-running the identical request returns the
 * stored answer once the runner has delivered it.
 *
 * @package SEOlity
 */

namespace SEOlity\AI;

defined( 'ABSPATH' ) || exit;

class Deferred extends \RuntimeException {

	/** @var string */
	private $job_id;

	/** @var int */
	private $retry_after;

	public function __construct( $job_id, $message = '', $retry_after = 15 ) {
		parent::__construct( $message ? $message : 'AI request deferred to the subscription runner.' );
		$this->job_id      = (string) $job_id;
		$this->retry_after = (int) $retry_after;
	}

	public function job_id() {
		return $this->job_id;
	}

	public function retry_after() {
		return $this->retry_after;
	}
}
