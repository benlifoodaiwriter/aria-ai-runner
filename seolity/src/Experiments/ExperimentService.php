<?php
/**
 * SEO Experiment System (section 13).
 *
 * Every applied change on an indexable URL opens an experiment. After the
 * window (default 14 days + 3 days GSC lag) it compares equal-length
 * before/after windows for the page, adjusts by the site-wide trend over the
 * same windows (control), and classifies the outcome with a confidence score.
 *
 * @package SEOlity
 */

namespace SEOlity\Experiments;

use SEOlity\Content\Objects;
use SEOlity\Core\Database;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class ExperimentService {

	const GSC_LAG_DAYS = 3;

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/** @return int|null Experiment id. */
	public function start_for_change( array $change ) {
		if ( empty( $change['url'] ) || ! in_array( $change['object_type'], array( 'post', 'term' ), true ) || 'applied' !== $change['status'] ) {
			return null;
		}
		if ( in_array( $change['source'], array( 'revert', 'restore' ), true ) ) {
			return null;
		}
		$db   = $this->plugin->db;
		$hash = Database::url_hash( $change['url'] );
		$kind = 'post' === $change['object_type'] ? get_post_type( (int) $change['object_id'] ) : 'term';
		// Group changes applied to the same page within 2 hours into one experiment.
		$open = $db->row( "SELECT * FROM {experiments} WHERE project_id = %d AND page_hash = %s AND status = 'running' AND started_at >= %s ORDER BY id DESC", array( $db->project_id(), $hash, gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ) ) );
		if ( $open ) {
			$ids    = array_filter( array_map( 'intval', explode( ',', (string) $open['change_ids'] ) ) );
			$ids[]  = (int) $change['id'];
			$fields = array_unique( array_merge( explode( '+', $open['change_type'] ), array( $change['field'] ) ) );
			sort( $fields );
			$db->update( 'experiments', array( 'change_ids' => implode( ',', array_unique( $ids ) ), 'change_type' => implode( '+', $fields ), 'name' => $this->name( $change, $fields ) ), array( 'id' => (int) $open['id'] ) );
			return (int) $open['id'];
		}
		// Running experiments on the same page are superseded (the new change contaminates them).
		$db->query( "UPDATE {experiments} SET status = 'superseded', reason = 'Another change was applied to this page before the window ended.' WHERE project_id = %d AND page_hash = %s AND status = 'running'", array( $db->project_id(), $hash ) );
		return $db->insert(
			'experiments',
			array(
				'project_id'  => $db->project_id(),
				'name'        => $this->name( $change, array( $change['field'] ) ),
				'object_type' => $kind ? $kind : $change['object_type'],
				'object_id'   => (int) $change['object_id'],
				'url'         => $change['url'],
				'page_hash'   => $hash,
				'change_type' => $change['field'],
				'change_ids'  => (string) (int) $change['id'],
				'status'      => 'running',
				'window_days' => (int) $this->plugin->settings->get( 'experiment_window' ),
				'started_at'  => Database::now(),
			)
		);
	}

	/**
	 * An edit made outside SEOlity (WordPress / page-builder editor) becomes an experiment too, so
	 * every edit's effect is measured — not only SEOlity's own.
	 *
	 * @param int    $post_id Post.
	 * @param string $what    What changed (title, content, …).
	 * @param string $when    GMT datetime (default now; past dates for the history backfill).
	 */
	public function start_for_editor( $post_id, $what, $when = null ) {
		$url = get_permalink( (int) $post_id );
		if ( ! $url ) {
			return null;
		}
		$db    = $this->plugin->db;
		$hash  = Database::url_hash( $url );
		$when  = $when ? $when : Database::now();
		$day   = substr( $when, 0, 10 );
		$exist = $db->value( 'SELECT id FROM {experiments} WHERE project_id = %d AND page_hash = %s AND DATE(started_at) = %s', array( $db->project_id(), $hash, $day ) );
		if ( $exist ) {
			return (int) $exist;
		}
		if ( $when >= gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) {
			$db->query( "UPDATE {experiments} SET status = 'superseded', reason = 'Another change was applied to this page before the window ended.' WHERE project_id = %d AND page_hash = %s AND status = 'running'", array( $db->project_id(), $hash ) );
		}
		return $db->insert(
			'experiments',
			array(
				'project_id'  => $db->project_id(),
				/* translators: 1 page 2 what changed */
				'name'        => sprintf( __( '%1$s — edited in WordPress (%2$s)', 'seolity' ), get_the_title( (int) $post_id ), $what ),
				'object_type' => get_post_type( (int) $post_id ),
				'object_id'   => (int) $post_id,
				'url'         => $url,
				'page_hash'   => $hash,
				'change_type' => 'editor:' . sanitize_key( $what ),
				'change_ids'  => '',
				'status'      => 'running',
				'window_days' => (int) $this->plugin->settings->get( 'experiment_window' ),
				'started_at'  => $when,
			)
		);
	}

	/**
	 * Edit history: every past edit (WordPress revisions) inside the Search Console period becomes
	 * an experiment and is evaluated with the same before/after method — so the effect of edits
	 * made before SEOlity was installed is known too.
	 */
	public function backfill_history( $limit = 400 ) {
		global $wpdb;
		$cov = $this->plugin->gsc_insights->coverage();
		if ( ! $cov || ! $cov['first_date'] ) {
			return 0;
		}
		$w     = (int) $this->plugin->settings->get( 'experiment_window' );
		$from  = gmdate( 'Y-m-d', strtotime( $cov['first_date'] . " +$w days" ) );
		$to    = gmdate( 'Y-m-d', strtotime( $cov['last_date'] . " -$w days" ) );
		$types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment', 'elementor_library' ) ) );
		$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		// One entry per page and day: the last revision of that day.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT r.post_parent pid, DATE(r.post_modified_gmt) d, MAX(r.post_modified_gmt) t, COUNT(*) n FROM {$wpdb->posts} r JOIN {$wpdb->posts} p ON p.ID = r.post_parent WHERE r.post_type = 'revision' AND p.post_status = 'publish' AND p.post_type IN ($in) AND r.post_modified_gmt BETWEEN %s AND %s GROUP BY r.post_parent, DATE(r.post_modified_gmt) ORDER BY t DESC LIMIT %d", array_merge( $types, array( $from . ' 00:00:00', $to . ' 23:59:59', (int) $limit ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$made = 0;
		foreach ( $rows as $r ) {
			if ( $this->start_for_editor( (int) $r['pid'], 'history', $r['t'] ) ) {
				$made++;
			}
		}
		return $made;
	}

	private function name( array $change, array $fields ) {
		return Objects::label( $change['object_type'], $change['object_id'] ) . ' — ' . implode( ' + ', $fields );
	}

	/** Evaluate experiments whose after-window has complete GSC data. */
	public function evaluate_due() {
		// Stored Search Console data is what the evaluation needs (also after a disconnect).
		if ( ! $this->plugin->gsc_analytics->has_data() ) {
			return 0;
		}
		$latest = $this->plugin->gsc_analytics->latest_date();
		$rows   = $this->plugin->db->rows( "SELECT * FROM {experiments} WHERE project_id = %d AND status = 'running' ORDER BY id ASC LIMIT 50", array( $this->plugin->db->project_id() ) );
		$done   = 0;
		foreach ( $rows as $e ) {
			$start     = gmdate( 'Y-m-d', strtotime( $e['started_at'] . ' UTC' ) );
			$w         = (int) $e['window_days'];
			$after_end = gmdate( 'Y-m-d', strtotime( "$start +$w days" ) );
			if ( $after_end > $latest || strtotime( $after_end ) > time() - self::GSC_LAG_DAYS * DAY_IN_SECONDS ) {
				continue;
			}
			$this->evaluate( $e );
			$done++;
		}
		return $done;
	}

	public function evaluate( array $e ) {
		$a       = $this->plugin->gsc_analytics;
		$start   = gmdate( 'Y-m-d', strtotime( $e['started_at'] . ' UTC' ) );
		$w       = (int) $e['window_days'];
		$b_start = gmdate( 'Y-m-d', strtotime( "$start -$w days" ) );
		$b_end   = gmdate( 'Y-m-d', strtotime( "$start -1 day" ) );
		$a_start = gmdate( 'Y-m-d', strtotime( "$start +1 day" ) );
		$a_end   = gmdate( 'Y-m-d', strtotime( "$start +$w days" ) );
		$before  = $a->page_window( $e['url'], $b_start, $b_end );
		$after   = $a->page_window( $e['url'], $a_start, $a_end );
		$site_b  = $a->site_window( $b_start, $b_end );
		$site_a  = $a->site_window( $a_start, $a_end );

		$pct = static function ( $new, $old ) {
			return $old ? round( ( $new - $old ) / $old * 100, 1 ) : ( $new ? 100.0 : 0.0 );
		};
		$deltas = array(
			'clicks'      => $pct( $after['clicks'], $before['clicks'] ),
			'impressions' => $pct( $after['impressions'], $before['impressions'] ),
			'ctr'         => $pct( $after['ctr'], $before['ctr'] ),
			'position'    => round( $before['position'] - $after['position'], 1 ), // + = improved.
		);
		$control = array(
			'clicks'      => $pct( $site_a['clicks'], $site_b['clicks'] ),
			'impressions' => $pct( $site_a['impressions'], $site_b['impressions'] ),
		);
		$adjusted = array(
			'clicks'      => round( $deltas['clicks'] - $control['clicks'], 1 ),
			'impressions' => round( $deltas['impressions'] - $control['impressions'], 1 ),
			'ctr'         => $deltas['ctr'],
			'position'    => $deltas['position'],
		);
		$volume = $before['impressions'] + $after['impressions'];
		if ( $volume < 100 ) {
			$outcome    = 'inconclusive';
			$confidence = 20;
			$reason     = __( 'Not enough search impressions to judge the effect.', 'seolity' );
		} else {
			$signals = 0;
			$signals += $adjusted['clicks'] > 10 ? 1 : ( $adjusted['clicks'] < -10 ? -1 : 0 );
			$signals += $adjusted['ctr'] > 10 ? 1 : ( $adjusted['ctr'] < -10 ? -1 : 0 );
			$signals += $adjusted['position'] > 1 ? 1 : ( $adjusted['position'] < -1.5 ? -1 : 0 );
			$signals += $adjusted['impressions'] > 15 ? 1 : ( $adjusted['impressions'] < -15 ? -1 : 0 );
			$outcome    = $signals >= 2 ? 'positive' : ( $signals <= -2 ? 'negative' : ( 0 === $signals ? 'neutral' : ( $signals > 0 ? 'positive' : 'negative' ) ) );
			if ( 1 === abs( $signals ) ) {
				$outcome = 'neutral' === $outcome ? 'neutral' : $outcome;
			}
			$confidence = (int) min( 95, 30 + 15 * abs( $signals ) + min( 30, log10( $volume ) * 8 ) );
			if ( 1 === abs( $signals ) ) {
				$confidence = min( $confidence, 55 );
			}
			/* translators: 1 clicks 2 ctr 3 position 4 site */
			$reason = sprintf( __( 'Clicks %1$s%%, CTR %2$s%%, position %3$s vs. before; site-wide clicks changed %4$s%% in the same period (control).', 'seolity' ), self::signed( $deltas['clicks'] ), self::signed( $deltas['ctr'] ), self::signed( $deltas['position'] ), self::signed( $control['clicks'] ) );
		}
		$queries  = $this->query_deltas( $e['url'], $b_start, $b_end, $a_start, $a_end );
		$learning = '';
		if ( 'inconclusive' !== $outcome && $this->plugin->ai->is_ready() && 'editor:history' !== $e['change_type'] ) {
			$res = $this->plugin->ai->run(
				'experiment_verdict',
				array( 'change' => $e['change_type'], 'page_type' => $e['object_type'], 'before' => $before, 'after' => $after, 'site_before' => $site_b, 'site_after' => $site_a, 'queries' => $queries, 'rule_based_outcome' => $outcome ),
				array( 'max_tokens' => 1500 )
			);
			if ( ! is_wp_error( $res ) ) {
				$outcome    = $res['data']['outcome'];
				$confidence = (int) round( ( $confidence + (int) $res['data']['confidence'] ) / 2 );
				$reason     = $res['data']['reason'] . ' ' . $reason;
				$learning   = $res['data']['learning'];
			}
		}
		$this->plugin->db->update(
			'experiments',
			array(
				'status'         => 'evaluated',
				'metrics_before' => array( 'page' => $before, 'site' => $site_b ),
				'metrics_after'  => array( 'page' => $after, 'site' => $site_a, 'deltas' => $deltas, 'control' => $control, 'adjusted' => $adjusted, 'learning' => $learning, 'queries' => $queries ),
				'outcome'        => $outcome,
				'confidence'     => $confidence,
				'reason'         => $reason,
				'evaluated_at'   => Database::now(),
			),
			array( 'id' => (int) $e['id'] )
		);
		foreach ( array_filter( array_map( 'intval', explode( ',', (string) $e['change_ids'] ) ) ) as $cid ) {
			$this->plugin->db->update( 'changes', array( 'gsc_after' => $after ), array( 'id' => $cid ) );
		}
		if ( 'negative' === $outcome && $confidence >= 60 ) {
			$this->plugin->alerts->raise(
				'experiment_negative',
				'medium',
				/* translators: %s name */
				sprintf( __( 'Experiment had a negative effect: %s', 'seolity' ), $e['name'] ),
				$reason . ' ' . __( 'You can revert the change from Experiments.', 'seolity' ),
				array( 'experiment_id' => (int) $e['id'], 'url' => $e['url'] ),
				'exp_neg_' . $e['id']
			);
		}
		do_action( 'seolity_experiment_evaluated', (int) $e['id'], $outcome );
		return $outcome;
	}

	/** Position of the page's main queries before vs. after (+ = improved). */
	private function query_deltas( $url, $b_start, $b_end, $a_start, $a_end ) {
		$rows = $this->plugin->db->rows(
			'SELECT query, SUM(IF(date BETWEEN %s AND %s, impressions, 0)) ib, SUM(IF(date BETWEEN %s AND %s, position*impressions, 0)) pb, SUM(IF(date BETWEEN %s AND %s, impressions, 0)) ia, SUM(IF(date BETWEEN %s AND %s, position*impressions, 0)) pa, SUM(IF(date BETWEEN %s AND %s, clicks, 0)) cb, SUM(IF(date BETWEEN %s AND %s, clicks, 0)) ca FROM {gsc_page_query} WHERE project_id = %d AND page_hash = %s AND date BETWEEN %s AND %s GROUP BY query_hash, query HAVING ib > 0 ORDER BY ib DESC LIMIT 12',
			array( $b_start, $b_end, $b_start, $b_end, $a_start, $a_end, $a_start, $a_end, $b_start, $b_end, $a_start, $a_end, $this->plugin->db->project_id(), Database::url_hash( $url ), $b_start, $a_end )
		);
		return array_map(
			static function ( $r ) {
				$pb = round( $r['pb'] / $r['ib'], 1 );
				$pa = (int) $r['ia'] ? round( $r['pa'] / $r['ia'], 1 ) : null;
				return array( 'query' => $r['query'], 'position_before' => $pb, 'position_after' => $pa, 'change' => null === $pa ? null : round( $pb - $pa, 1 ), 'clicks_before' => (int) $r['cb'], 'clicks_after' => (int) $r['ca'] );
			},
			$rows
		);
	}

	private static function signed( $v ) {
		return ( $v > 0 ? '+' : '' ) . $v;
	}

	public function query( array $f ) {
		$db    = $this->plugin->db;
		$where = array( 'project_id = %d' );
		$args  = array( $db->project_id() );
		if ( ! empty( $f['status'] ) ) {
			$where[] = 'status = %s';
			$args[]  = $f['status'];
		}
		if ( ! empty( $f['outcome'] ) ) {
			$where[] = 'outcome = %s';
			$args[]  = $f['outcome'];
		}
		$rows = $db->rows( 'SELECT * FROM {experiments} WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT 200', $args );
		$stats = array();
		foreach ( $db->rows( "SELECT outcome, COUNT(*) c FROM {experiments} WHERE project_id = %d AND status = 'evaluated' GROUP BY outcome", array( $db->project_id() ) ) as $r ) {
			$stats[ $r['outcome'] ] = (int) $r['c'];
		}
		$stats['running'] = (int) $db->value( "SELECT COUNT(*) FROM {experiments} WHERE project_id = %d AND status = 'running'", array( $db->project_id() ) );
		return array(
			'items' => array_map( static function ( $r ) {
				return Database::decode( $r, array( 'metrics_before', 'metrics_after' ) );
			}, $rows ),
			'stats' => $stats,
		);
	}

	/** Revert all changes of an experiment. */
	public function revert( $id ) {
		$e = $this->plugin->db->row( 'SELECT change_ids FROM {experiments} WHERE id = %d', array( (int) $id ) );
		if ( ! $e ) {
			return new \WP_Error( 'seolity_exp', __( 'Experiment not found.', 'seolity' ), array( 'status' => 404 ) );
		}
		$out = array();
		foreach ( array_filter( array_map( 'intval', explode( ',', (string) $e['change_ids'] ) ) ) as $cid ) {
			$res   = $this->plugin->changes->revert( $cid );
			$out[] = is_wp_error( $res ) ? $res->get_error_message() : $cid;
		}
		return array( 'reverted' => $out );
	}
}
