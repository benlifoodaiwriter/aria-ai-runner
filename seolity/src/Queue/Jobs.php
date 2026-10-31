<?php
/**
 * Registry of background job handlers.
 *
 * @package SEOlity
 */

namespace SEOlity\Queue;

use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class Jobs {

	public static function register( Queue $q, Plugin $p ) {
		// Crawler / audit.
		$q->handler( 'chat_reply', static function ( $a ) use ( $p ) {
			$p->chat->answer( (int) $a['id'] ); // Errors are stored on the message itself.
			return true;
		} );
		$q->handler( 'indexability_scan', static function () use ( $p ) {
			$p->indexability->scan();
			return true;
		} );
		$q->handler( 'cli_login', static function ( $a ) use ( $p ) {
			return $p->cliauth->run_login( 'codex' );
		} );
		$q->handler( 'cli_install', static function ( $a ) use ( $p ) {
			return $p->clisetup->install( in_array( $a['engine'], array( 'claude', 'codex' ), true ) ? $a['engine'] : 'claude', (int) $a['activity_id'] );
		} );
		$q->handler( 'not_found_triage', static function ( $a ) use ( $p ) {
			if ( ! empty( $a['user_id'] ) ) {
				wp_set_current_user( (int) $a['user_id'] );
			}
			return $p->nf_triage->prepare( (int) $a['id'] );
		} );
		$q->handler( 'experiments_history', static function () use ( $p ) {
			$made = $p->experiments->backfill_history();
			$p->experiments->evaluate_due();
			return true;
		} );
		$q->handler( 'ai_visibility_check', static function ( $a ) use ( $p ) {
			return $p->ai_visibility->check( isset( $a['queries'] ) ? (array) $a['queries'] : array() );
		} );
		$q->handler( 'sitemap_map_refresh', static function () use ( $p ) {
			return $p->sitemap_map->refresh();
		} );
		$q->handler( 'serp_track', static function ( $a ) use ( $p ) {
			return $p->intel->track( isset( $a['queries'] ) ? (array) $a['queries'] : array() );
		} );
		$q->handler( 'competitor_compare', static function ( $a ) use ( $p ) {
			if ( ! empty( $a['user_id'] ) ) {
				wp_set_current_user( (int) $a['user_id'] );
			}
			return $p->intel->compare( (int) $a['id'], isset( $a['activity_id'] ) ? (int) $a['activity_id'] : 0 );
		} );
		$q->handler( 'full_analysis', static function ( $a ) use ( $p ) {
			return $p->analysis->step( (array) $a );
		} );
		$q->handler( 'duplicates_scan', static function () use ( $p ) {
			$p->duplicates->scan();
			return true;
		} );
		$q->handler( 'differentiate_prepare', static function ( $a ) use ( $p ) {
			if ( ! empty( $a['user_id'] ) ) {
				wp_set_current_user( (int) $a['user_id'] );
			}
			return $p->duplicates->prepare_differentiate( (int) $a['id'] );
		} );
		$q->handler( 'consolidate_prepare', static function ( $a ) use ( $p ) {
			if ( ! empty( $a['user_id'] ) ) {
				wp_set_current_user( (int) $a['user_id'] );
			}
			return $p->duplicates->prepare( (int) $a['id'] );
		} );
		$q->handler( 'operation_apply', static function ( $a ) use ( $p ) {
			if ( ! empty( $a['user_id'] ) ) {
				wp_set_current_user( (int) $a['user_id'] );
			}
			return $p->operations->apply( (int) $a['id'] );
		} );
		$q->handler( 'agent_run', static function ( $a ) use ( $p ) {
			return $p->agent->run( (int) $a['id'] );
		} );
		$q->handler( 'ai_job', static function ( $a ) use ( $p ) {
			return $p->aijobs->run( (int) $a['id'] );
		} );
		$q->handler( 'roadmap_build', static function ( $a ) use ( $p ) {
			return $p->roadmap->build( (int) $a['id'] );
		} );
		$q->handler( 'route_probe', static function () use ( $p ) {
			// A plugin update ships a newer worker (e.g. the /proxy route): redeploy it once.
			$cf = $p->cloudflare;
			if ( $cf->is_deployed() && SEOLITY_VERSION !== (string) ( isset( $cf->state()['version'] ) ? $cf->state()['version'] : '' ) ) {
				$cf->deploy();
			}
			$p->gateway->probe();
			return true;
		} );
		$q->handler( 'subscription_collect', static function () use ( $p ) {
			return $p->subscription->collect_job();
		} );
		$q->handler( 'crawl_start', static function ( $a ) use ( $p ) {
			return $p->crawler->start( isset( $a['type'] ) ? $a['type'] : 'full' );
		} );
		$q->handler( 'crawl_seed', static function ( $a ) use ( $p ) {
			return $p->crawler->seed( (int) $a['audit_id'], $a['phase'], (int) $a['offset'] );
		} );
		$q->handler( 'crawl_batch', static function ( $a ) use ( $p ) {
			return $p->crawler->process_batch( (int) $a['audit_id'] );
		} );
		$q->handler( 'crawl_finalize', static function ( $a ) use ( $p ) {
			return $p->crawler->finalize( (int) $a['audit_id'] );
		} );
		$q->handler( 'pagespeed_run', static function () use ( $p ) {
			return $p->pagespeed->run_batch();
		} );

		// Google Search Console.
		$q->handler( 'gsc_sync', static function ( $a ) use ( $p ) {
			return $p->gsc_sync->sync( isset( $a['days'] ) ? (int) $a['days'] : null );
		} );
		$q->handler( 'gsc_sync_range', static function ( $a ) use ( $p ) {
			return $p->gsc_sync->sync_range( $a['start'], $a['end'], $a['kind'], isset( $a['start_row'] ) ? (int) $a['start_row'] : 0 );
		} );

		// Intelligence.
		$q->handler( 'recommendations_refresh', static function () use ( $p ) {
			return $p->recommendations->refresh();
		} );
		$q->handler( 'keywords_sync', static function () use ( $p ) {
			return $p->keywords->sync_from_gsc();
		} );
		$q->handler( 'experiments_evaluate', static function () use ( $p ) {
			$p->experiments->evaluate_due();
			$p->learning->recompute();
		} );
		$q->handler( 'alerts_check', static function () use ( $p ) {
			return $p->alerts->check();
		} );
		$q->handler( 'briefing_generate', static function ( $a ) use ( $p ) {
			return $p->briefing->generate( isset( $a['date'] ) ? $a['date'] : gmdate( 'Y-m-d' ) );
		} );
		$q->handler( 'health_snapshot', static function () use ( $p ) {
			return $p->health->snapshot();
		} );
		$q->handler( 'links_suggest', static function () use ( $p ) {
			return $p->links->build_suggestions();
		} );
		$q->handler( 'clusters_build', static function () use ( $p ) {
			return $p->planner->build_clusters();
		} );

		// Competitors.
		$q->handler( 'competitor_analyze', static function ( $a ) use ( $p ) {
			return $p->competitors->analyze( (int) $a['id'] );
		} );
		$q->handler( 'competitor_crawl_batch', static function ( $a ) use ( $p ) {
			return $p->competitors->crawl_batch( (int) $a['id'] );
		} );
		$q->handler( 'competitor_summarize', static function ( $a ) use ( $p ) {
			return $p->competitors->summarize( (int) $a['id'] );
		} );

		// Images / 404.
		$q->handler( 'images_scan', static function ( $a ) use ( $p ) {
			return $p->images->scan( isset( $a['offset'] ) ? (int) $a['offset'] : 0 );
		} );
		$q->handler( 'not_found_suggest', static function () use ( $p ) {
			return $p->not_found->suggest_pending();
		} );

		// AI bulk work (one object per job keeps memory + timeouts in check).
		$q->handler( 'bulk_item', static function ( $a ) use ( $p ) {
			return $p->optimizer->bulk_item( $a );
		} );
		$q->handler( 'analyze_object', static function ( $a ) use ( $p ) {
			return $p->optimizer->analyze_object( $a['object_type'], (int) $a['object_id'] );
		} );

		// Rules / automation.
		$q->handler( 'rules_event', static function ( $a ) use ( $p ) {
			return $p->rules->run_event( $a['event'], isset( $a['context'] ) ? $a['context'] : array() );
		} );
		$q->handler( 'rules_scheduled', static function () use ( $p ) {
			return $p->rules->run_scheduled();
		} );

		// Reports.
		$q->handler( 'report_generate', static function ( $a ) use ( $p ) {
			$id = $p->reports->generate( $a['type'] );
			if ( ! empty( $a['email'] ) && $id ) {
				$p->reports->email( $id );
			}
			return true;
		} );

		// Knowledge base + GitHub storage.
		$q->handler( 'knowledge_sync', static function ( $a ) use ( $p ) {
			return $p->knowledge->sync( ! empty( $a['bundled_only'] ) );
		} );
		$q->handler( 'knowledge_contribute', static function () use ( $p ) {
			return $p->knowledge->contribute_learnings();
		} );
		$q->handler( 'archive_run', static function () use ( $p ) {
			return $p->archive->run();
		} );

		// Orchestration.
		$q->handler( 'daily_pipeline', static function () use ( $p ) {
			self::daily_pipeline( $p );
		} );
		$q->handler( 'weekly_pipeline', static function () use ( $p ) {
			self::weekly_pipeline( $p );
		} );
		$q->handler( 'cleanup', static function () use ( $p ) {
			self::cleanup( $p );
		} );
	}

	/**
	 * The "Automated Daily SEO Intelligence" pipeline (section 30). Each step is
	 * its own job so one failure never blocks the rest; dependent steps are delayed.
	 */
	public static function daily_pipeline( Plugin $p ) {
		$q = $p->queue;
		$s = $p->settings;

		if ( $p->gsc_oauth->is_connected() ) {
			$q->push( 'gsc_sync', array( 'days' => 5 ), array( 'unique' => 'daily', 'priority' => 2 ) );
			$q->push( 'keywords_sync', array(), array( 'unique' => true, 'priority' => 15, 'delay' => 20 * MINUTE_IN_SECONDS ) );
		}
		$freq = $s->get( 'crawl_frequency' );
		$last = (int) get_option( 'seolity_last_full_crawl', 0 );
		$due  = array( 'daily' => DAY_IN_SECONDS, 'weekly' => WEEK_IN_SECONDS, 'monthly' => 30 * DAY_IN_SECONDS );
		if ( isset( $due[ $freq ] ) && time() - $last >= $due[ $freq ] - HOUR_IN_SECONDS ) {
			$q->push( 'crawl_start', array( 'type' => 'full' ), array( 'unique' => true, 'priority' => 5 ) );
		} else {
			$q->push( 'crawl_start', array( 'type' => 'incremental' ), array( 'unique' => true, 'priority' => 5 ) );
		}
		// The site map explorer is rebuilt every day.
		$q->push( 'sitemap_map_refresh', array(), array( 'unique' => 'daily', 'priority' => 15, 'max_attempts' => 2, 'timeout' => 900 ) );
		// Weekly Google check of the tracked queries (within the monthly SERP budget).
		$lt = get_option( 'seolity_serp_last_track' );
		if ( $p->serp->available() && ( ! is_array( $lt ) || strtotime( $lt['at'] ) < time() - 6 * DAY_IN_SECONDS ) ) {
			$q->push( 'serp_track', array(), array( 'unique' => true, 'priority' => 20, 'max_attempts' => 1, 'timeout' => 1800 ) );
		}
		$lv = get_option( 'seolity_ai_visibility_last' );
		if ( $p->ai_visibility->enabled() && ( ! is_array( $lv ) || strtotime( $lv['at'] ) < time() - 6 * DAY_IN_SECONDS ) ) {
			$q->push( 'ai_visibility_check', array(), array( 'unique' => true, 'priority' => 21, 'max_attempts' => 1, 'timeout' => 1800 ) );
		}
		foreach ( $p->competitors->due_ids() as $cid ) {
			$q->push( 'competitor_analyze', array( 'id' => $cid ), array( 'unique' => true, 'priority' => 25 ) );
		}
		$q->push( 'experiments_evaluate', array(), array( 'unique' => true, 'priority' => 10, 'delay' => 30 * MINUTE_IN_SECONDS ) );
		$q->push( 'recommendations_refresh', array(), array( 'unique' => true, 'priority' => 12, 'delay' => 40 * MINUTE_IN_SECONDS ) );
		$q->push( 'rules_scheduled', array(), array( 'unique' => true, 'priority' => 14, 'delay' => 45 * MINUTE_IN_SECONDS ) );
		$q->push( 'alerts_check', array(), array( 'unique' => true, 'priority' => 5, 'delay' => 50 * MINUTE_IN_SECONDS ) );
		$q->push( 'health_snapshot', array(), array( 'unique' => true, 'priority' => 15, 'delay' => 55 * MINUTE_IN_SECONDS ) );
		$q->push( 'briefing_generate', array( 'date' => gmdate( 'Y-m-d' ) ), array( 'unique' => true, 'priority' => 8, 'delay' => HOUR_IN_SECONDS ) );
		$q->push( 'pagespeed_run', array(), array( 'unique' => true, 'priority' => 40, 'delay' => 70 * MINUTE_IN_SECONDS ) );

		if ( 'daily' === $s->get( 'report_frequency' ) ) {
			$q->push( 'report_generate', array( 'type' => 'daily', 'email' => true ), array( 'unique' => true, 'priority' => 30, 'delay' => 75 * MINUTE_IN_SECONDS ) );
		}
		if ( 'monthly' === $s->get( 'report_frequency' ) && '01' === gmdate( 'd' ) ) {
			$q->push( 'report_generate', array( 'type' => 'monthly', 'email' => true ), array( 'unique' => true, 'priority' => 30, 'delay' => 75 * MINUTE_IN_SECONDS ) );
		}
		if ( $s->get( 'storage_enabled' ) ) {
			$q->push( 'archive_run', array(), array( 'unique' => true, 'priority' => 50, 'delay' => 90 * MINUTE_IN_SECONDS ) );
		}
		if ( $s->get( 'kb_auto_sync' ) ) {
			$q->push( 'knowledge_sync', array(), array( 'unique' => true, 'priority' => 45 ) );
		}
		$q->push( 'cleanup', array(), array( 'unique' => true, 'priority' => 60, 'delay' => 2 * HOUR_IN_SECONDS ) );
		$q->push( 'route_probe', array(), array( 'unique' => true, 'priority' => 6 ) );
	}

	public static function weekly_pipeline( Plugin $p ) {
		$q = $p->queue;
		$q->push( 'links_suggest', array(), array( 'unique' => true, 'priority' => 30 ) );
		$q->push( 'clusters_build', array(), array( 'unique' => true, 'priority' => 35 ) );
		$q->push( 'images_scan', array( 'offset' => 0 ), array( 'unique' => true, 'priority' => 30 ) );
		if ( 'weekly' === $p->settings->get( 'report_frequency' ) ) {
			$q->push( 'report_generate', array( 'type' => 'weekly', 'email' => true ), array( 'unique' => true, 'priority' => 30 ) );
		}
		if ( $p->settings->get( 'kb_contribute' ) ) {
			$q->push( 'knowledge_contribute', array(), array( 'unique' => true, 'priority' => 60 ) );
		}
	}

	public static function cleanup( Plugin $p ) {
		$db        = $p->db;
		$retention = (int) $p->settings->get( 'retention_days' );
		$cutoff    = gmdate( 'Y-m-d H:i:s', time() - $retention * DAY_IN_SECONDS );
		$p->queue->cleanup();
		$p->activity->cleanup( 90 );
		$db->query( 'DELETE FROM {ai_cache} WHERE expires_at < %s', array( gmdate( 'Y-m-d H:i:s' ) ) );
		$db->query( 'DELETE FROM {ai_requests} WHERE created_at < %s', array( $cutoff ) );
		$db->query( "DELETE FROM {alerts} WHERE status <> 'new' AND created_at < %s", array( $cutoff ) );
		$db->query( "DELETE FROM {not_found} WHERE status = 'ignored' AND last_seen < %s", array( $cutoff ) );
		$db->query( 'DELETE FROM {chat} WHERE created_at < %s', array( $cutoff ) );
		$db->query( "DELETE FROM {recommendations} WHERE status IN ('dismissed','done','stale') AND updated_at < %s", array( $cutoff ) );
		// GSC rows beyond the configured window are kept only if not archived to GitHub.
		if ( ! $p->settings->get( 'storage_enabled' ) ) {
			$gsc_cutoff = gmdate( 'Y-m-d', time() - max( 490, $retention ) * DAY_IN_SECONDS );
			$db->query( 'DELETE FROM {gsc_page_query} WHERE date < %s', array( $gsc_cutoff ) );
		}
	}
}
