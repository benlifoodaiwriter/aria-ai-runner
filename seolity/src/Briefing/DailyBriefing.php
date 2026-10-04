<?php
/**
 * "Today's SEO Briefing" (section 30): daily synthesis of GSC deltas, site
 * health, competitor moves, new errors, experiments and top actions. Uses AI
 * when configured; otherwise produces a deterministic briefing from the same
 * signals so the feature never depends on an API key.
 *
 * @package SEOlity
 */

namespace SEOlity\Briefing;

use SEOlity\Core\Database;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class DailyBriefing {

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function signals() {
		$p     = $this->plugin;
		$db    = $p->db;
		$pid   = $db->project_id();
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$s     = array(
			'date'            => gmdate( 'Y-m-d' ),
			'health'          => $p->health->cached()['score'],
			'new_alerts'      => $db->rows( 'SELECT type, severity, title FROM {alerts} WHERE project_id = %d AND created_at >= %s ORDER BY id DESC LIMIT 10', array( $pid, $since ) ),
			'new_issues'      => $db->rows( "SELECT check_code, severity, COUNT(*) c FROM {issues} WHERE project_id = %d AND status = 'open' AND first_seen >= %s GROUP BY check_code, severity ORDER BY c DESC LIMIT 10", array( $pid, $since ) ),
			'fixed_issues'    => (int) $db->value( "SELECT COUNT(*) FROM {issues} WHERE project_id = %d AND status = 'fixed' AND last_seen >= %s", array( $pid, $since ) ),
			'experiments'     => $db->rows( "SELECT name, outcome, confidence, reason FROM {experiments} WHERE project_id = %d AND status = 'evaluated' AND evaluated_at >= %s LIMIT 5", array( $pid, gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ) ),
			'ai_changes_24h'  => (int) $db->value( "SELECT COUNT(*) FROM {changes} WHERE project_id = %d AND status = 'applied' AND applied_at >= %s", array( $pid, $since ) ),
			'pending_changes' => (int) $db->value( "SELECT COUNT(*) FROM {changes} WHERE project_id = %d AND status = 'pending'", array( $pid ) ),
			'top_actions'     => array_map( static function ( $r ) {
				return array( 'title' => $r['title'], 'priority' => $r['priority'], 'reason' => $r['reason'] );
			}, $p->recommendations->query( array( 'limit' => 6 ) ) ),
			'competitors'     => array_map( static function ( $r ) {
				$d = json_decode( (string) $r['summary'], true );
				return array( 'name' => $r['name'], 'new_pages' => isset( $d['new_pages'] ) ? $d['new_pages'] : 0, 'pages' => isset( $d['pages'] ) ? $d['pages'] : 0 );
			}, $db->rows( "SELECT name, summary FROM {competitors} WHERE project_id = %d AND status = 'active'", array( $pid ) ) ),
		);
		if ( $p->gsc_oauth->is_connected() && $p->gsc_analytics->has_data() ) {
			$o              = $p->gsc_analytics->overview( 7 );
			$s['gsc']       = array( 'current' => $o['current'], 'previous' => $o['previous'], 'delta' => $o['delta'], 'period' => $o['period'] );
			$m              = $p->gsc_analytics->movers( 'page', 7, 5 );
			$s['winners']   = array_map( array( $this, 'mini' ), $m['winners'] );
			$s['losers']    = array_map( array( $this, 'mini' ), $m['losers'] );
			$k              = $p->gsc_analytics->keyword_changes( 7, 5 );
			$s['new_keywords']  = $k['new'];
			$s['lost_keywords'] = $k['lost'];
		}
		return $s;
	}

	public function mini( $r ) {
		return array( 'page' => $r['page'], 'clicks' => $r['clicks'], 'delta_clicks' => $r['delta_clicks'], 'position' => $r['position'] );
	}

	public function generate( $date = null ) {
		$date    = $date ? $date : gmdate( 'Y-m-d' );
		$signals = $this->signals();
		$data    = null;
		$meta    = array( 'mode' => 'rules' );
		if ( $this->plugin->ai->is_ready() ) {
			$res = $this->plugin->ai->run( 'briefing', $signals );
			if ( ! is_wp_error( $res ) ) {
				$data = $res['data'];
				$meta = array_merge( $res['meta'], array( 'mode' => 'ai' ) );
			}
		}
		if ( ! $data ) {
			$data = $this->rule_based( $signals );
		}
		$db = $this->plugin->db;
		$db->query( "DELETE FROM {reports} WHERE project_id = %d AND type = 'briefing' AND period_start = %s", array( $db->project_id(), $date ) );
		$db->insert(
			'reports',
			array(
				'project_id'   => $db->project_id(),
				'type'         => 'briefing',
				'title'        => $data['headline'],
				'period_start' => $date,
				'period_end'   => $date,
				'data'         => array( 'briefing' => $data, 'signals' => $signals, 'meta' => $meta ),
				'created_at'   => Database::now(),
			)
		);
		return true;
	}

	private function rule_based( array $s ) {
		$highlights = array();
		if ( isset( $s['gsc'] ) ) {
			$d    = $s['gsc']['delta'];
			$type = null === $d['clicks'] ? 'info' : ( $d['clicks'] >= 0 ? 'win' : 'loss' );
			/* translators: 1 clicks 2 delta 3 impressions */
			$highlights[] = array( 'type' => $type, 'title' => sprintf( __( '%1$s clicks this week (%2$s%%)', 'seolity' ), number_format_i18n( $s['gsc']['current']['clicks'] ), null === $d['clicks'] ? '—' : ( $d['clicks'] > 0 ? '+' : '' ) . $d['clicks'] ), 'detail' => sprintf( __( 'Impressions %3$s, avg. position %4$s.', 'seolity' ), 0, 0, number_format_i18n( $s['gsc']['current']['impressions'] ), $s['gsc']['current']['position'] ) );
			foreach ( array_slice( $s['winners'], 0, 2 ) as $w ) {
				/* translators: 1 page 2 delta */
				$highlights[] = array( 'type' => 'win', 'title' => sprintf( __( 'Rising: %1$s (+%2$d clicks)', 'seolity' ), urldecode( wp_parse_url( $w['page'], PHP_URL_PATH ) ), $w['delta_clicks'] ), 'detail' => '' );
			}
			foreach ( array_slice( $s['losers'], 0, 2 ) as $l ) {
				/* translators: 1 page 2 delta */
				$highlights[] = array( 'type' => 'loss', 'title' => sprintf( __( 'Falling: %1$s (%2$d clicks)', 'seolity' ), urldecode( wp_parse_url( $l['page'], PHP_URL_PATH ) ), $l['delta_clicks'] ), 'detail' => '' );
			}
		}
		foreach ( array_slice( $s['new_alerts'], 0, 3 ) as $a ) {
			$highlights[] = array( 'type' => 'risk', 'title' => $a['title'], 'detail' => '' );
		}
		if ( $s['new_issues'] ) {
			/* translators: %d */
			$highlights[] = array( 'type' => 'risk', 'title' => sprintf( __( '%d new issue types detected in the last audit', 'seolity' ), count( $s['new_issues'] ) ), 'detail' => implode( ', ', wp_list_pluck( $s['new_issues'], 'check_code' ) ) );
		}
		foreach ( $s['experiments'] as $e ) {
			/* translators: 1 name 2 outcome */
			$highlights[] = array( 'type' => 'positive' === $e['outcome'] ? 'win' : ( 'negative' === $e['outcome'] ? 'loss' : 'info' ), 'title' => sprintf( __( 'Experiment "%1$s": %2$s', 'seolity' ), $e['name'], $e['outcome'] ), 'detail' => (string) $e['reason'] );
		}
		$actions = array();
		foreach ( array_slice( $s['top_actions'], 0, 5 ) as $a ) {
			$actions[] = array( 'title' => $a['title'], 'reason' => $a['reason'], 'priority' => $a['priority'] );
		}
		$headline = isset( $s['gsc'] ) && null !== $s['gsc']['delta']['clicks']
			/* translators: %s delta */
			? sprintf( __( 'Organic clicks %s%% week over week', 'seolity' ), ( $s['gsc']['delta']['clicks'] > 0 ? '+' : '' ) . $s['gsc']['delta']['clicks'] )
			: __( 'Your daily SEO briefing', 'seolity' );
		/* translators: 1 health 2 actions 3 pending */
		$summary = sprintf( __( 'Health score %1$s. %2$d prioritized actions are waiting, %3$d AI changes need your review.', 'seolity' ), null === $s['health'] ? '—' : $s['health'] . '/100', count( $s['top_actions'] ), $s['pending_changes'] );
		return array( 'headline' => $headline, 'summary' => $summary, 'highlights' => $highlights, 'actions' => $actions );
	}

	public function latest() {
		$row = $this->plugin->db->row( "SELECT * FROM {reports} WHERE project_id = %d AND type = 'briefing' ORDER BY period_start DESC, id DESC", array( $this->plugin->db->project_id() ) );
		return $row ? Database::decode( $row, array( 'data' ) ) : null;
	}
}
