<?php
/**
 * WP-CLI commands (ops-friendly; ideal with a real system cron).
 *
 *   wp seolity queue run [--seconds=50]
 *   wp seolity audit [--type=incremental]
 *   wp seolity gsc-sync [--days=90]
 *   wp seolity daily
 *   wp seolity health
 *   wp seolity knowledge-sync
 *   wp seolity archive
 *
 * @package SEOlity
 */

namespace SEOlity\Cli;

use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class Command {

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Queue operations.
	 *
	 * ## OPTIONS
	 * <action>
	 * : run|stats|retry
	 * [--seconds=<seconds>]
	 * : Time budget for run.
	 */
	public function queue( $args, $assoc ) {
		$action = isset( $args[0] ) ? $args[0] : 'run';
		if ( 'stats' === $action ) {
			$s = $this->plugin->queue->stats();
			unset( $s['recent'] );
			\WP_CLI::print_value( $s, array( 'format' => 'json' ) );
			return;
		}
		if ( 'retry' === $action ) {
			\WP_CLI::success( 'Retried ' . (int) $this->plugin->queue->retry_failed() . ' jobs.' );
			return;
		}
		$n = $this->plugin->queue->work( isset( $assoc['seconds'] ) ? (int) $assoc['seconds'] : 50 );
		\WP_CLI::success( "Processed {$n} jobs." );
	}

	/** Start a site audit. [--type=<full|incremental>] */
	public function audit( $args, $assoc ) {
		$id = $this->plugin->crawler->start( isset( $assoc['type'] ) ? $assoc['type'] : 'full' );
		\WP_CLI::success( "Audit #{$id} started. Run `wp seolity queue run` to process it." );
	}

	/** Queue a Search Console sync. [--days=<days>] */
	public function gsc_sync( $args, $assoc ) {
		$res = $this->plugin->gsc_sync->sync( isset( $assoc['days'] ) ? (int) $assoc['days'] : null );
		is_wp_error( $res ) ? \WP_CLI::error( $res->get_error_message() ) : \WP_CLI::success( 'GSC sync queued.' );
	}

	/** Run the daily intelligence pipeline now. */
	public function daily() {
		\SEOlity\Queue\Jobs::daily_pipeline( $this->plugin );
		\WP_CLI::success( 'Daily pipeline queued.' );
	}

	/** Print the explainable health score. */
	public function health() {
		$h = $this->plugin->health->compute();
		\WP_CLI::line( 'Health: ' . ( null === $h['score'] ? 'n/a' : $h['score'] . '/100 (' . $h['grade'] . ')' ) );
		foreach ( $h['categories'] as $k => $c ) {
			\WP_CLI::line( sprintf( '  %-12s %3d  %s', $k, $c['score'], $c['about'] ) );
		}
	}

	/** Sync the SEO knowledge base (bundled + GitHub). */
	public function knowledge_sync() {
		\WP_CLI::print_value( $this->plugin->knowledge->sync( false ), array( 'format' => 'json' ) );
	}

	/** Archive cold data to GitHub storage. */
	public function archive() {
		$res = $this->plugin->archive->run();
		is_wp_error( $res ) ? \WP_CLI::error( $res->get_error_message() ) : \WP_CLI::print_value( $res, array( 'format' => 'json' ) );
	}
}
