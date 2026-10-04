<?php
/**
 * SEO Reports (section 36) with white-label branding: daily/weekly/monthly,
 * export to CSV / JSON / printable HTML (save as PDF from the browser), and
 * scheduled email delivery.
 *
 * @package SEOlity
 */

namespace SEOlity\Reports;

use SEOlity\Core\Database;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class ReportService {

	const TYPES = array( 'daily' => 1, 'weekly' => 7, 'monthly' => 30 );

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function generate( $type ) {
		$type  = isset( self::TYPES[ $type ] ) ? $type : 'weekly';
		$days  = self::TYPES[ $type ];
		$p     = $this->plugin;
		$db    = $p->db;
		$pid   = $db->project_id();
		$end   = gmdate( 'Y-m-d' );
		$start = gmdate( 'Y-m-d', strtotime( "-$days days" ) );
		$s_dt  = $start . ' 00:00:00';
		$data  = array(
			'brand'   => array( 'name' => $p->settings->get( 'brand_name' ), 'logo' => $p->settings->get( 'brand_logo' ), 'color' => $p->settings->get( 'brand_color' ) ),
			'site'    => array( 'name' => get_bloginfo( 'name' ), 'url' => home_url( '/' ) ),
			'period'  => array( 'type' => $type, 'start' => $start, 'end' => $end ),
			'health'  => array_intersect_key( $p->health->compute(), array_flip( array( 'score', 'grade', 'categories', 'excluded' ) ) ),
			'issues'  => array(
				'open_by_severity' => $db->rows( "SELECT severity, COUNT(*) c FROM {issues} WHERE project_id = %d AND status = 'open' GROUP BY severity", array( $pid ) ),
				'new'              => (int) $db->value( "SELECT COUNT(*) FROM {issues} WHERE project_id = %d AND status = 'open' AND first_seen >= %s", array( $pid, $s_dt ) ),
				'fixed'            => (int) $db->value( "SELECT COUNT(*) FROM {issues} WHERE project_id = %d AND status = 'fixed' AND last_seen >= %s", array( $pid, $s_dt ) ),
				'top'              => array_slice( $p->doctor->diagnose()['diagnoses'], 0, 10 ),
			),
			'ai_changes'   => array(
				'applied'  => (int) $db->value( "SELECT COUNT(*) FROM {changes} WHERE project_id = %d AND status = 'applied' AND applied_at >= %s", array( $pid, $s_dt ) ),
				'reverted' => (int) $db->value( "SELECT COUNT(*) FROM {changes} WHERE project_id = %d AND status = 'reverted' AND reverted_at >= %s", array( $pid, $s_dt ) ),
				'items'    => $db->rows( "SELECT object_type, object_id, field, url, applied_at, source FROM {changes} WHERE project_id = %d AND status = 'applied' AND applied_at >= %s ORDER BY applied_at DESC LIMIT 50", array( $pid, $s_dt ) ),
			),
			'experiments'  => $db->rows( "SELECT name, outcome, confidence, reason, evaluated_at FROM {experiments} WHERE project_id = %d AND status = 'evaluated' AND evaluated_at >= %s ORDER BY evaluated_at DESC LIMIT 20", array( $pid, $s_dt ) ),
			'learnings'    => $p->learning->insights(),
			'recommendations' => array_map( static function ( $r ) {
				return array( 'title' => $r['title'], 'priority' => $r['priority'], 'reason' => $r['reason'] );
			}, $p->recommendations->query( array( 'limit' => 10 ) ) ),
			'competitors'  => array_map( static function ( $c ) {
				$s = json_decode( (string) $c['summary'], true );
				return array( 'name' => $c['name'], 'domain' => $c['domain'], 'pages' => isset( $s['pages'] ) ? $s['pages'] : null, 'new_pages' => isset( $s['new_pages'] ) ? $s['new_pages'] : null, 'summary' => isset( $s['ai']['summary'] ) ? $s['ai']['summary'] : '' );
			}, $db->rows( "SELECT name, domain, summary FROM {competitors} WHERE project_id = %d AND status = 'active'", array( $pid ) ) ),
		);
		if ( $p->gsc_oauth->is_connected() && $p->gsc_analytics->has_data() ) {
			$o                    = $p->gsc_analytics->overview( $days );
			$data['traffic']      = array( 'current' => $o['current'], 'previous' => $o['previous'], 'delta' => $o['delta'], 'series' => $o['series'] );
			$data['rankings']     = $p->gsc_analytics->ranking_distribution( $days );
			$data['top_queries']  = $p->gsc_analytics->top_queries( $days, 20 );
			$data['top_pages']    = $p->gsc_analytics->top_pages( $days, 20 );
			$data['movers']       = $p->gsc_analytics->movers( 'page', $days, 10 );
			$data['keywords']     = $p->gsc_analytics->keyword_changes( $days, 15 );
		}
		$brand = $p->settings->get( 'brand_name' ) ? $p->settings->get( 'brand_name' ) : 'SEOlity';
		$id    = $db->insert(
			'reports',
			array(
				'project_id'   => $pid,
				'type'         => $type,
				/* translators: 1 brand 2 type 3 start 4 end */
				'title'        => sprintf( __( '%1$s %2$s SEO report %3$s – %4$s', 'seolity' ), $brand, $type, $start, $end ),
				'period_start' => $start,
				'period_end'   => $end,
				'data'         => $data,
				'created_at'   => Database::now(),
			)
		);
		$p->audit_log->log( 'report.generated', 'report', $id, array( 'type' => $type ) );
		return $id;
	}

	public function get( $id ) {
		$r = Database::decode( $this->plugin->db->row( 'SELECT * FROM {reports} WHERE id = %d AND project_id = %d', array( (int) $id, $this->plugin->db->project_id() ) ), array( 'data' ) );
		if ( $r && empty( $r['data'] ) && ! empty( $r['archive_ref'] ) ) {
			$r['data'] = $this->plugin->archive->fetch_json( $r['archive_ref'] ); // Read-through from GitHub storage.
		}
		return $r && is_array( $r['data'] ) ? $r : null;
	}

	public function query() {
		return $this->plugin->db->rows( "SELECT id, type, title, period_start, period_end, archive_ref, created_at FROM {reports} WHERE project_id = %d AND type IN ('daily','weekly','monthly') ORDER BY id DESC LIMIT 100", array( $this->plugin->db->project_id() ) );
	}

	/**
	 * Export.
	 *
	 * @return array{mime:string, filename:string, body:string}|\WP_Error
	 */
	public function export( $id, $format ) {
		$r = $this->get( $id );
		if ( ! $r ) {
			return new \WP_Error( 'seolity_report', __( 'Report not found.', 'seolity' ), array( 'status' => 404 ) );
		}
		$base = sanitize_file_name( 'seolity-' . $r['type'] . '-' . $r['period_end'] );
		if ( 'json' === $format ) {
			return array( 'mime' => 'application/json', 'filename' => $base . '.json', 'body' => wp_json_encode( $r['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		}
		if ( 'csv' === $format ) {
			$out = fopen( 'php://temp', 'r+' );
			fwrite( $out, "\xEF\xBB\xBF" ); // UTF-8 BOM for Excel (Persian text).
			$d = $r['data'];
			fputcsv( $out, array( 'section', 'metric', 'value' ) );
			fputcsv( $out, array( 'health', 'score', isset( $d['health']['score'] ) ? $d['health']['score'] : '' ) );
			if ( isset( $d['traffic'] ) ) {
				foreach ( $d['traffic']['current'] as $k => $v ) {
					fputcsv( $out, array( 'traffic', $k, $v ) );
					fputcsv( $out, array( 'traffic_change', $k, isset( $d['traffic']['delta'][ $k ] ) ? $d['traffic']['delta'][ $k ] : '' ) );
				}
				fputcsv( $out, array() );
				fputcsv( $out, array( 'top_pages', 'page', 'clicks', 'impressions', 'ctr', 'position' ) );
				foreach ( $d['top_pages'] as $row ) {
					fputcsv( $out, array( 'top_pages', $row['page'], $row['clicks'], $row['impressions'], $row['ctr'], $row['position'] ) );
				}
				fputcsv( $out, array() );
				fputcsv( $out, array( 'top_queries', 'query', 'clicks', 'impressions', 'ctr', 'position' ) );
				foreach ( $d['top_queries'] as $row ) {
					fputcsv( $out, array( 'top_queries', $row['query'], $row['clicks'], $row['impressions'], $row['ctr'], $row['position'] ) );
				}
			}
			fputcsv( $out, array() );
			fputcsv( $out, array( 'issues', 'title', 'priority', 'count' ) );
			foreach ( $d['issues']['top'] as $i ) {
				fputcsv( $out, array( 'issues', $i['title'], $i['priority'], $i['count'] ) );
			}
			fputcsv( $out, array() );
			fputcsv( $out, array( 'ai_changes', 'url', 'field', 'applied_at' ) );
			foreach ( $d['ai_changes']['items'] as $c ) {
				fputcsv( $out, array( 'ai_changes', $c['url'], $c['field'], $c['applied_at'] ) );
			}
			fputcsv( $out, array() );
			fputcsv( $out, array( 'recommendations', 'title', 'priority', 'reason' ) );
			foreach ( $d['recommendations'] as $rec ) {
				fputcsv( $out, array( 'recommendations', $rec['title'], $rec['priority'], $rec['reason'] ) );
			}
			rewind( $out );
			$csv = stream_get_contents( $out );
			fclose( $out );
			return array( 'mime' => 'text/csv; charset=utf-8', 'filename' => $base . '.csv', 'body' => $csv );
		}
		return array( 'mime' => 'text/html; charset=utf-8', 'filename' => $base . '.html', 'body' => $this->html( $r ) );
	}

	/** Branded, print-optimized HTML (Print → Save as PDF). */
	public function html( array $r ) {
		$d     = $r['data'];
		$rtl   = is_rtl();
		$color = preg_match( '/^#[0-9a-f]{3,8}$/i', (string) $d['brand']['color'] ) ? $d['brand']['color'] : '#6d28d9';
		ob_start();
		?>
<!doctype html>
<html <?php echo $rtl ? 'dir="rtl"' : ''; ?> lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo esc_html( $r['title'] ); ?></title>
<style>
body{font-family:Vazirmatn,Tahoma,system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#111827;margin:0;background:#f8fafc}
.page{max-width:900px;margin:0 auto;padding:32px;background:#fff}
header{display:flex;justify-content:space-between;align-items:center;border-bottom:4px solid <?php echo esc_attr( $color ); ?>;padding-bottom:16px;margin-bottom:24px}
h1{font-size:22px;margin:0}h2{font-size:17px;color:<?php echo esc_attr( $color ); ?>;margin:28px 0 10px}
.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.kpi{border:1px solid #e5e7eb;border-radius:10px;padding:12px}.kpi b{display:block;font-size:22px}
.up{color:#047857}.down{color:#b91c1c}table{width:100%;border-collapse:collapse;font-size:13px}th,td{padding:6px 8px;border-bottom:1px solid #eef2f7;text-align:start}th{background:#f1f5f9}
.muted{color:#6b7280;font-size:12px}.btn{display:inline-block;background:<?php echo esc_attr( $color ); ?>;color:#fff;padding:8px 14px;border-radius:8px;text-decoration:none;border:0;cursor:pointer}
@media print{.no-print{display:none}.page{padding:0}body{background:#fff}}
</style>
</head>
<body><div class="page">
<p class="no-print" style="text-align:end"><button class="btn" onclick="window.print()"><?php esc_html_e( 'Print / Save as PDF', 'seolity' ); ?></button></p>
<header>
	<div><h1><?php echo esc_html( $r['title'] ); ?></h1><div class="muted"><?php echo esc_html( $d['site']['name'] . ' — ' . $d['site']['url'] ); ?></div></div>
	<?php if ( ! empty( $d['brand']['logo'] ) ) : ?><img src="<?php echo esc_url( $d['brand']['logo'] ); ?>" alt="<?php echo esc_attr( $d['brand']['name'] ); ?>" style="max-height:48px"><?php endif; ?>
</header>
<div class="kpis">
	<div class="kpi"><span class="muted"><?php esc_html_e( 'Health score', 'seolity' ); ?></span><b><?php echo esc_html( null === $d['health']['score'] ? '—' : $d['health']['score'] . '/100' ); ?></b></div>
	<?php if ( isset( $d['traffic'] ) ) : foreach ( array( 'clicks' => __( 'Clicks', 'seolity' ), 'impressions' => __( 'Impressions', 'seolity' ), 'position' => __( 'Avg. position', 'seolity' ) ) as $k => $label ) : $delta = $d['traffic']['delta'][ $k ]; ?>
	<div class="kpi"><span class="muted"><?php echo esc_html( $label ); ?></span><b><?php echo esc_html( number_format_i18n( $d['traffic']['current'][ $k ], 'position' === $k ? 1 : 0 ) ); ?></b><span class="<?php echo esc_attr( $delta >= 0 ? 'up' : 'down' ); ?>"><?php echo esc_html( null === $delta ? '' : ( $delta > 0 ? '+' : '' ) . $delta . ( 'position' === $k ? '' : '%' ) ); ?></span></div>
	<?php endforeach; endif; ?>
</div>
<?php if ( isset( $d['top_pages'] ) ) : ?>
<h2><?php esc_html_e( 'Top pages', 'seolity' ); ?></h2>
<table><tr><th><?php esc_html_e( 'Page', 'seolity' ); ?></th><th><?php esc_html_e( 'Clicks', 'seolity' ); ?></th><th><?php esc_html_e( 'Impressions', 'seolity' ); ?></th><th>CTR</th><th><?php esc_html_e( 'Position', 'seolity' ); ?></th></tr>
<?php foreach ( array_slice( $d['top_pages'], 0, 15 ) as $row ) : ?><tr><td><?php echo esc_html( urldecode( wp_parse_url( $row['page'], PHP_URL_PATH ) ) ); ?></td><td><?php echo esc_html( $row['clicks'] ); ?></td><td><?php echo esc_html( $row['impressions'] ); ?></td><td><?php echo esc_html( $row['ctr'] ); ?>%</td><td><?php echo esc_html( $row['position'] ); ?></td></tr><?php endforeach; ?>
</table>
<h2><?php esc_html_e( 'Top queries', 'seolity' ); ?></h2>
<table><tr><th><?php esc_html_e( 'Query', 'seolity' ); ?></th><th><?php esc_html_e( 'Clicks', 'seolity' ); ?></th><th><?php esc_html_e( 'Impressions', 'seolity' ); ?></th><th><?php esc_html_e( 'Position', 'seolity' ); ?></th></tr>
<?php foreach ( array_slice( $d['top_queries'], 0, 15 ) as $row ) : ?><tr><td><?php echo esc_html( $row['query'] ); ?></td><td><?php echo esc_html( $row['clicks'] ); ?></td><td><?php echo esc_html( $row['impressions'] ); ?></td><td><?php echo esc_html( $row['position'] ); ?></td></tr><?php endforeach; ?>
</table>
<?php endif; ?>
<h2><?php esc_html_e( 'Issues', 'seolity' ); ?></h2>
<p class="muted"><?php /* translators: 1 new 2 fixed */ echo esc_html( sprintf( __( '%1$d new issues, %2$d fixed in this period.', 'seolity' ), $d['issues']['new'], $d['issues']['fixed'] ) ); ?></p>
<table><tr><th><?php esc_html_e( 'Diagnosis', 'seolity' ); ?></th><th><?php esc_html_e( 'Priority', 'seolity' ); ?></th></tr>
<?php foreach ( $d['issues']['top'] as $i ) : ?><tr><td><?php echo esc_html( $i['title'] ); ?></td><td><?php echo esc_html( $i['priority'] ); ?></td></tr><?php endforeach; ?>
</table>
<h2><?php esc_html_e( 'Improvements & AI changes', 'seolity' ); ?></h2>
<p><?php /* translators: 1 applied 2 reverted */ echo esc_html( sprintf( __( '%1$d changes applied, %2$d reverted.', 'seolity' ), $d['ai_changes']['applied'], $d['ai_changes']['reverted'] ) ); ?></p>
<?php if ( $d['experiments'] ) : ?><table><tr><th><?php esc_html_e( 'Experiment', 'seolity' ); ?></th><th><?php esc_html_e( 'Outcome', 'seolity' ); ?></th><th><?php esc_html_e( 'Why', 'seolity' ); ?></th></tr>
<?php foreach ( $d['experiments'] as $e ) : ?><tr><td><?php echo esc_html( $e['name'] ); ?></td><td><?php echo esc_html( $e['outcome'] ); ?></td><td><?php echo esc_html( $e['reason'] ); ?></td></tr><?php endforeach; ?></table><?php endif; ?>
<?php if ( $d['competitors'] ) : ?><h2><?php esc_html_e( 'Competitors', 'seolity' ); ?></h2><table><tr><th><?php esc_html_e( 'Competitor', 'seolity' ); ?></th><th><?php esc_html_e( 'Pages', 'seolity' ); ?></th><th><?php esc_html_e( 'New', 'seolity' ); ?></th><th><?php esc_html_e( 'Summary', 'seolity' ); ?></th></tr>
<?php foreach ( $d['competitors'] as $c ) : ?><tr><td><?php echo esc_html( $c['name'] ); ?></td><td><?php echo esc_html( $c['pages'] ); ?></td><td><?php echo esc_html( $c['new_pages'] ); ?></td><td><?php echo esc_html( $c['summary'] ); ?></td></tr><?php endforeach; ?></table><?php endif; ?>
<h2><?php esc_html_e( 'Recommended next actions', 'seolity' ); ?></h2>
<ol><?php foreach ( $d['recommendations'] as $rec ) : ?><li><b><?php echo esc_html( $rec['title'] ); ?></b> — <span class="muted"><?php echo esc_html( $rec['reason'] ); ?></span></li><?php endforeach; ?></ol>
<p class="muted"><?php echo esc_html( sprintf( '%s · %s', $d['brand']['name'], wp_date( 'Y-m-d H:i' ) ) ); ?></p>
</div></body></html>
		<?php
		return (string) ob_get_clean();
	}

	public function email( $id ) {
		$r          = $this->get( $id );
		$recipients = array_filter( array_map( 'sanitize_email', (array) $this->plugin->settings->get( 'report_recipients' ) ), 'is_email' );
		if ( ! $r || ! $recipients ) {
			return false;
		}
		$sent = wp_mail( $recipients, $r['title'], $this->html( $r ), array( 'Content-Type: text/html; charset=UTF-8' ) );
		$this->plugin->audit_log->log( 'report.emailed', 'report', (int) $id, array( 'recipients' => count( $recipients ), 'sent' => $sent ) );
		return $sent;
	}
}
