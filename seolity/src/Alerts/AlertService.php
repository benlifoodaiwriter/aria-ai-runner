<?php
/**
 * AI Alerts (section 29): traffic/CTR/ranking drops, lost pages & keywords,
 * new 404s, indexing problems, sitemap & CWV problems, competitor growth.
 * Deduplicated; high-severity alerts can be emailed. Integrations can hook
 * `seolity_alert_raised` (Slack, Telegram, webhooks…).
 *
 * @package SEOlity
 */

namespace SEOlity\Alerts;

use SEOlity\Core\Database;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class AlertService {

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function raise( $type, $severity, $title, $message = '', array $data = array(), $dedupe = null ) {
		$db  = $this->plugin->db;
		$key = sha1( $type . '|' . ( $dedupe ? $dedupe : $title ) );
		if ( $db->value( 'SELECT id FROM {alerts} WHERE project_id = %d AND dedupe_key = %s', array( $db->project_id(), $key ) ) ) {
			return 0;
		}
		$id = $db->insert(
			'alerts',
			array(
				'project_id' => $db->project_id(),
				'type'       => sanitize_key( $type ),
				'severity'   => in_array( $severity, array( 'critical', 'high', 'medium', 'low', 'info' ), true ) ? $severity : 'info',
				'title'      => wp_strip_all_tags( $title ),
				'message'    => wp_strip_all_tags( $message ),
				'data'       => $data,
				'dedupe_key' => $key,
				'status'     => 'new',
				'created_at' => Database::now(),
			)
		);
		if ( $id && in_array( $severity, array( 'critical', 'high' ), true ) ) {
			$this->notify( $id, $title, $message, $severity );
		}
		do_action( 'seolity_alert_raised', $id, $type, $severity, $title, $message, $data );
		return $id;
	}

	private function notify( $id, $title, $message, $severity ) {
		$s  = $this->plugin->settings;
		$to = $s->get( 'alert_email' ) ? $s->get( 'alert_email' ) : get_option( 'admin_email' );
		if ( ! $s->get( 'alert_email_enabled' ) || ! $to ) {
			return;
		}
		$brand = $s->get( 'brand_name' ) ? $s->get( 'brand_name' ) : 'SEOlity';
		$sent  = wp_mail(
			$to,
			sprintf( '[%s] %s: %s', $brand, strtoupper( $severity ), $title ),
			$message . "\n\n" . admin_url( 'admin.php?page=seolity#/alerts' )
		);
		if ( $sent ) {
			$this->plugin->db->update( 'alerts', array( 'notified' => 1 ), array( 'id' => (int) $id ) );
		}
	}

	public function check() {
		$a      = $this->plugin->gsc_analytics;
		$db     = $this->plugin->db;
		$pid    = $db->project_id();
		$drop   = (int) $this->plugin->settings->get( 'alert_drop_percent' );
		$raised = 0;
		$since  = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		if ( $this->plugin->gsc_oauth->is_connected() && $a->has_data() ) {
			$o   = $a->overview( 7 );
			$tag = $o['period']['end'];
			if ( null !== $o['delta']['clicks'] && $o['delta']['clicks'] <= -$drop && $o['previous']['clicks'] >= 50 ) {
				/* translators: 1 percent 2 from 3 to */
				$raised += (int) (bool) $this->raise( 'traffic_drop', 'high', sprintf( __( 'Organic clicks dropped %1$s%% week over week', 'seolity' ), abs( $o['delta']['clicks'] ) ), sprintf( __( 'Clicks went from %2$d to %3$d (last 7 days vs previous 7). Check the Losers report and recent changes.', 'seolity' ), 0, $o['previous']['clicks'], $o['current']['clicks'] ), $o['delta'], 'traffic_' . $tag );
			}
			if ( null !== $o['delta']['ctr'] && $o['delta']['ctr'] <= -$drop && $o['previous']['impressions'] >= 1000 ) {
				/* translators: %s percent */
				$raised += (int) (bool) $this->raise( 'ctr_drop', 'medium', sprintf( __( 'Site CTR dropped %s%% week over week', 'seolity' ), abs( $o['delta']['ctr'] ) ), __( 'Impressions held but fewer people click — SERP features, competitor snippets or title changes may be the cause.', 'seolity' ), $o['delta'], 'ctr_' . $tag );
			}
			if ( $o['delta']['position'] <= -2 && $o['previous']['impressions'] >= 500 ) {
				/* translators: %s positions */
				$raised += (int) (bool) $this->raise( 'ranking_drop', 'high', sprintf( __( 'Average position worsened by %s', 'seolity' ), abs( $o['delta']['position'] ) ), __( 'Rankings fell across the site — check for algorithm updates, technical problems or lost pages.', 'seolity' ), $o['delta'], 'rank_' . $tag );
			}
			foreach ( $a->trending_pages( 7, 5 )['declining'] as $p ) {
				if ( $p['clicks'] === 0 && $p['prev_clicks'] >= 20 ) {
					/* translators: %s url */
					$raised += (int) (bool) $this->raise( 'page_lost', 'critical', sprintf( __( 'Page lost all organic clicks: %s', 'seolity' ), urldecode( $p['page'] ) ), __( 'This page went from steady traffic to zero — it may be deindexed, broken, redirected or noindexed.', 'seolity' ), $p, 'lost_' . $p['page'] . $tag );
				}
			}
			$kw = $a->keyword_changes( 7, 5 );
			foreach ( $kw['lost'] as $k ) {
				if ( $k['impressions'] >= 200 ) {
					/* translators: %s query */
					$raised += (int) (bool) $this->raise( 'lost_keyword', 'medium', sprintf( __( 'Lost keyword: "%s"', 'seolity' ), $k['query'] ), sprintf( /* translators: 1 impr 2 pos */ __( 'It had %1$d impressions at position %2$s and no longer appears.', 'seolity' ), $k['impressions'], $k['position'] ), $k, 'lostkw_' . $k['query'] . $tag );
				}
			}
			foreach ( $kw['new'] as $k ) {
				if ( $k['impressions'] >= 300 ) {
					/* translators: %s query */
					$raised += (int) (bool) $this->raise( 'new_keyword', 'info', sprintf( __( 'New keyword gaining visibility: "%s"', 'seolity' ), $k['query'] ), sprintf( /* translators: 1 impr 2 pos */ __( '%1$d impressions at position %2$s — consider strengthening the ranking page.', 'seolity' ), $k['impressions'], $k['position'] ), $k, 'newkw_' . $k['query'] . $tag );
				}
			}
		}

		$new_404 = $db->rows( "SELECT url, hits FROM {not_found} WHERE project_id = %d AND status = 'open' AND first_seen >= %s AND hits >= 5 ORDER BY hits DESC LIMIT 5", array( $pid, $since ) );
		foreach ( $new_404 as $r ) {
			/* translators: %s url */
			$raised += (int) (bool) $this->raise( 'new_404', 'medium', sprintf( __( 'New 404 getting hits: %s', 'seolity' ), $r['url'] ), sprintf( /* translators: %d */ __( '%d hits since it first appeared. Redirect it to the suggested target.', 'seolity' ), $r['hits'] ), $r, '404_' . $r['url'] );
		}
		$noindex_new = (int) $db->value( "SELECT COUNT(*) FROM {issues} i JOIN {urls} u ON u.id = i.url_id WHERE i.project_id = %d AND i.check_code = 'noindex' AND i.status = 'open' AND i.first_seen >= %s AND u.object_id IS NOT NULL", array( $pid, $since ) );
		if ( $noindex_new >= 3 ) {
			/* translators: %d */
			$raised += (int) (bool) $this->raise( 'indexing_drop', 'high', sprintf( __( '%d published pages became noindex in the last 24h', 'seolity' ), $noindex_new ), __( 'A setting, plugin or theme change may be hiding pages from Google.', 'seolity' ), array( 'count' => $noindex_new ), 'noindex_' . gmdate( 'Ymd' ) );
		}
		foreach ( array( 'sitemap_missing' => 'sitemap_problem', 'sitemap_error_urls' => 'sitemap_problem', 'cwv_poor' => 'cwv_problem', 'redirect_loop' => 'technical', 'http_5xx' => 'technical' ) as $code => $type ) {
			$n = (int) $db->value( "SELECT COUNT(*) FROM {issues} WHERE project_id = %d AND check_code = %s AND status = 'open' AND first_seen >= %s", array( $pid, $code, $since ) );
			if ( $n ) {
				$m = \SEOlity\Crawler\Checks::meta( $code );
				$raised += (int) (bool) $this->raise( $type, 'cwv_poor' === $code ? 'medium' : 'high', $m['title'] . ' (' . $n . ')', $m['why'] . ' ' . $m['fix'], array( 'check' => $code, 'count' => $n ), $code . '_' . gmdate( 'Ymd' ) );
			}
		}
		foreach ( $db->rows( "SELECT id, name, summary FROM {competitors} WHERE project_id = %d AND status = 'active' AND last_analyzed >= %s", array( $pid, $since ) ) as $c ) {
			$s = json_decode( (string) $c['summary'], true );
			if ( ! empty( $s['new_pages'] ) && $s['new_pages'] >= 5 ) {
				/* translators: 1 name 2 count */
				$raised += (int) (bool) $this->raise( 'competitor_growth', 'medium', sprintf( __( '%1$s published %2$d new pages', 'seolity' ), $c['name'], $s['new_pages'] ), __( 'Review their new content and the content-gap report.', 'seolity' ), array( 'competitor_id' => (int) $c['id'] ), 'comp_' . $c['id'] . gmdate( 'Ymd' ) );
			}
		}
		return $raised;
	}

	public function query( array $f ) {
		$db    = $this->plugin->db;
		$where = array( 'project_id = %d' );
		$args  = array( $db->project_id() );
		if ( ! empty( $f['status'] ) ) {
			$where[] = 'status = %s';
			$args[]  = $f['status'];
		} else {
			$where[] = "status <> 'dismissed'";
		}
		return array(
			'items'  => array_map( static function ( $r ) {
				return Database::decode( $r, array( 'data' ) );
			}, $db->rows( 'SELECT * FROM {alerts} WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT 200', $args ) ),
			'unread' => $this->unread(),
		);
	}

	public function unread() {
		return (int) $this->plugin->db->value( "SELECT COUNT(*) FROM {alerts} WHERE project_id = %d AND status = 'new'", array( $this->plugin->db->project_id() ) );
	}

	public function set_status( $id, $status ) {
		return (bool) $this->plugin->db->update( 'alerts', array( 'status' => in_array( $status, array( 'read', 'dismissed', 'new' ), true ) ? $status : 'read' ), array( 'id' => (int) $id ) );
	}

	public function mark_all_read() {
		return $this->plugin->db->query( "UPDATE {alerts} SET status = 'read' WHERE project_id = %d AND status = 'new'", array( $this->plugin->db->project_id() ) );
	}
}
