<?php
/**
 * Full-site analysis: one button that refreshes every section from the same data — crawl,
 * Search Console, canonical issues, indexability, duplicates, 404 targets and the AI roadmap.
 * Runs as a chain of short background steps (the crawl is awaited by re-queuing, never by
 * blocking a PHP request), so it survives slow hosts and can be cancelled from Tasks.
 *
 * @package SEOlity
 */

namespace SEOlity\Analysis;

use SEOlity\Activity\Activity;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class FullAnalysis {

	const OPTION = 'seolity_full_analysis';

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/** Event-driven re-checks for changes WordPress does not report through save_post. */
	public function register_hooks() {
		foreach ( array( 'rank-math-options-titles', 'rank-math-options-general', 'rank-math-options-sitemap', 'blog_public' ) as $opt ) {
			add_action( 'update_option_' . $opt, array( $this, 'on_settings_changed' ) );
		}
		add_action( 'transition_post_status', array( $this, 'on_status' ), 10, 3 );
	}

	public function on_settings_changed() {
		$this->plugin->queue->push( 'indexability_scan', array(), array( 'unique' => true, 'priority' => 15, 'delay' => 120 ) );
	}

	/** Publishing or unpublishing changes which articles compete with each other. */
	public function on_status( $new, $old, $post ) {
		if ( $new === $old || ( 'publish' !== $new && 'publish' !== $old ) || wp_is_post_revision( $post ) || ! is_post_type_viewable( $post->post_type ) ) {
			return;
		}
		if ( get_option( \SEOlity\Content\Duplicates::OPTION ) ) {
			$this->plugin->queue->push( 'duplicates_scan', array(), array( 'unique' => true, 'priority' => 20, 'delay' => 600 ) );
		}
	}

	public function state() {
		$s = get_option( self::OPTION );
		return is_array( $s ) ? $s : null;
	}

	private function save( array $s ) {
		update_option( self::OPTION, $s, false );
		return $s;
	}

	private static function stage_names() {
		return array(
			'gsc'             => __( 'Search Console data', 'seolity' ),
			'crawl'           => __( 'Crawl of the whole site', 'seolity' ),
			'recommendations' => __( 'Issues and opportunities', 'seolity' ),
			'indexability'    => __( 'Indexability & crawl budget', 'seolity' ),
			'duplicates'      => __( 'Duplicate & similar articles', 'seolity' ),
			'not_found'       => __( '404 redirect targets', 'seolity' ),
			'roadmap'         => __( 'AI roadmap', 'seolity' ),
		);
	}

	public function start() {
		$s = $this->state();
		if ( $s && 'running' === $s['status'] && time() - strtotime( $s['started_at'] ) < 3 * HOUR_IN_SECONDS ) {
			return $s;
		}
		$stages = array();
		foreach ( self::stage_names() as $k => $label ) {
			$stages[ $k ] = array( 'label' => $label, 'status' => 'pending', 'detail' => '' );
		}
		$aid = $this->plugin->activity->start( 'job', __( 'Full-site analysis', 'seolity' ), array( 'dock' => true, 'status' => 'queued', 'step' => __( 'Queued — starting in a moment…', 'seolity' ) ) );
		$s   = $this->save( array( 'status' => 'running', 'started_at' => gmdate( 'c' ), 'finished_at' => null, 'activity_id' => $aid, 'audit_id' => null, 'stages' => $stages, 'user_id' => get_current_user_id() ) );
		$job = $this->plugin->queue->push( 'full_analysis', array( 'stage' => 'start' ), array( 'unique' => 'full_analysis', 'priority' => 1, 'max_attempts' => 2, 'timeout' => 1200 ) );
		$this->plugin->activity->attach_job( $aid, $job );
		$this->plugin->audit_log->log( 'analysis.started', 'site', null );
		\SEOlity\Activity\AiJobs::run_after_response( $this->plugin );
		return $s;
	}

	private function mark( array &$s, $stage, $status, $detail = '' ) {
		$s['stages'][ $stage ]['status'] = $status;
		$s['stages'][ $stage ]['detail'] = $detail;
		$this->save( $s );
		$done = count(
			array_filter(
				$s['stages'],
				static function ( $x ) {
					return in_array( $x['status'], array( 'done', 'skipped', 'failed' ), true );
				}
			)
		);
		$line = ( 'running' === $status ? '… ' : ( 'done' === $status ? '✓ ' : ( 'failed' === $status ? '✗ ' : '– ' ) ) ) . $s['stages'][ $stage ]['label'] . ( $detail ? ' — ' . $detail : '' );
		$this->plugin->activity->step( (int) $s['activity_id'], $line, (int) ( 100 * $done / count( $s['stages'] ) ) );
	}

	/** Run one stage safely: a failing section never stops the others. */
	private function run_stage( array &$s, $stage, callable $fn ) {
		$this->mark( $s, $stage, 'running' );
		try {
			$res = $fn();
			if ( is_wp_error( $res ) ) {
				$this->mark( $s, $stage, 'failed', $res->get_error_message() );
			} else {
				$this->mark( $s, $stage, false === $res ? 'skipped' : 'done', is_string( $res ) ? $res : '' );
			}
		} catch ( \SEOlity\Queue\Cancelled $c ) {
			throw $c;
		} catch ( \Throwable $e ) {
			$this->mark( $s, $stage, 'failed', $e->getMessage() );
		}
	}

	/** Queue handler. */
	public function step( array $a ) {
		$s = $this->state();
		if ( ! $s || 'running' !== $s['status'] ) {
			return true;
		}
		if ( ! empty( $s['user_id'] ) ) {
			wp_set_current_user( (int) $s['user_id'] );
		}
		$p    = $this->plugin;
		$prev = Activity::set_current( (int) $s['activity_id'] );
		try {
			$stage = isset( $a['stage'] ) ? $a['stage'] : 'start';
			if ( 'start' === $stage ) {
				$this->run_stage(
					$s,
					'gsc',
					function () use ( $p ) {
						if ( ! $p->gsc_oauth->is_connected() ) {
							return __( 'Not connected — skipped.', 'seolity' );
						}
						$p->gsc_sync->sync( 5 );
						return __( 'Last days updated.', 'seolity' );
					}
				);
				$this->mark( $s, 'crawl', 'running', __( 'Crawling…', 'seolity' ) );
				$s['audit_id'] = (int) $p->crawler->start( 'full' );
				$this->save( $s );
				$p->queue->push( 'full_analysis', array( 'stage' => 'wait_crawl' ), array( 'priority' => 1, 'delay' => 10, 'max_attempts' => 2 ) );
			} elseif ( 'wait_crawl' === $stage ) {
				$p->queue->checkpoint();
				$audit = $p->db->row( 'SELECT status, summary, started_at, urls_done FROM {audits} WHERE id = %d', array( (int) $s['audit_id'] ) );
				$age   = $audit ? time() - strtotime( $audit['started_at'] . ' UTC' ) : 0;
				if ( $audit && 'running' === $audit['status'] && $age < 2 * HOUR_IN_SECONDS ) {
					$sum  = json_decode( (string) $audit['summary'], true );
					$done = (int) $p->db->value( "SELECT COUNT(*) FROM {urls} WHERE project_id = %d AND crawl_status = 'done'", array( $p->db->project_id() ) );
					/* translators: %d: pages */
					$this->mark( $s, 'crawl', 'running', sprintf( __( '%d pages checked so far…', 'seolity' ), $done ) . ( ! empty( $sum['phase'] ) ? ' (' . $sum['phase'] . ')' : '' ) );
					$p->queue->push( 'full_analysis', array( 'stage' => 'wait_crawl' ), array( 'priority' => 1, 'delay' => 15, 'max_attempts' => 2 ) );
				} else {
					$ok = $audit && 'completed' === $audit['status'];
					/* translators: %d: pages */
					$this->mark( $s, 'crawl', $ok ? 'done' : 'failed', $ok ? sprintf( __( '%d pages crawled', 'seolity' ), (int) $audit['urls_done'] ) : __( 'The crawl did not finish; the other sections use the latest available data.', 'seolity' ) );
					$p->queue->push( 'full_analysis', array( 'stage' => 'analyze' ), array( 'priority' => 1, 'max_attempts' => 1, 'timeout' => 1800 ) );
				}
			} elseif ( 'analyze' === $stage ) {
				$this->run_stage(
					$s,
					'recommendations',
					function () use ( $p ) {
						$p->recommendations->refresh();
						return '';
					}
				);
				$this->run_stage(
					$s,
					'indexability',
					function () use ( $p ) {
						$r = $p->indexability->scan();
						/* translators: %d: findings */
						return sprintf( __( '%d findings', 'seolity' ), count( $r['findings'] ) );
					}
				);
				$this->run_stage(
					$s,
					'duplicates',
					function () use ( $p ) {
						$r = $p->duplicates->scan();
						/* translators: %d: groups */
						return sprintf( __( '%d groups', 'seolity' ), count( $r['clusters'] ) );
					}
				);
				$ai = $p->ai->is_ready();
				$this->run_stage(
					$s,
					'not_found',
					function () use ( $p, $ai ) {
						if ( ! $ai ) {
							return __( 'No AI engine — slug matching only.', 'seolity' );
						}
						$p->queue->push( 'not_found_suggest', array(), array( 'unique' => true, 'priority' => 2 ) );
						return __( 'Running in the background.', 'seolity' );
					}
				);
				$this->run_stage(
					$s,
					'roadmap',
					function () use ( $p, $ai ) {
						if ( ! $ai ) {
							return false;
						}
						$p->roadmap->start();
						return __( 'The AI is writing the roadmap in the background.', 'seolity' );
					}
				);
				$s['status']      = 'done';
				$s['finished_at'] = gmdate( 'c' );
				$this->save( $s );
				$failed = array_filter(
					$s['stages'],
					static function ( $x ) {
						return 'failed' === $x['status'];
					}
				);
				$open = (int) $p->db->value( "SELECT COUNT(*) FROM {recommendations} WHERE project_id = %d AND status IN ('open','in_progress')", array( $p->db->project_id() ) );
				/* translators: %d: issues */
				$summary = sprintf( __( 'All sections updated. %d open items in the roadmap.', 'seolity' ), $open ) . ( $failed ? ' ' . __( 'Some sections could not be updated — see the details.', 'seolity' ) : '' );
				$lines   = array();
				foreach ( $s['stages'] as $x ) {
					$lines[] = ( 'done' === $x['status'] ? '✓ ' : ( 'failed' === $x['status'] ? '✗ ' : '– ' ) ) . $x['label'] . ( $x['detail'] ? ' — ' . $x['detail'] : '' );
				}
				$p->activity->finish( (int) $s['activity_id'], $failed ? 'failed' : 'done', $summary, array( 'summary' => $summary, 'lines' => $lines, 'route' => '#/actions' ) );
				$p->audit_log->log( 'analysis.finished', 'site', null, array( 'open' => $open, 'failed' => count( $failed ) ) );
			}
		} catch ( \SEOlity\Queue\Cancelled $c ) {
			$s['status'] = 'cancelled';
			$this->save( $s );
			Activity::set_current( $prev );
			throw $c;
		}
		Activity::set_current( $prev );
		return true;
	}
}
