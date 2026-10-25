<?php
/**
 * Thrown at a safe point (Queue::checkpoint) when the owner cancelled the running task.
 *
 * @package SEOlity
 */

namespace SEOlity\Queue;

defined( 'ABSPATH' ) || exit;

final class Cancelled extends \RuntimeException {
}
