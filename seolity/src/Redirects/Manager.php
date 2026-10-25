<?php
/**
 * Redirect manager (section 25): 301/302/307/410/451, regex, bulk import/export,
 * chain & loop detection, hit logging. Lookup is a single indexed query per
 * request (skipped entirely when no redirects exist); regex rules are cached.
 *
 * @package SEOlity
 */

namespace SEOlity\Redirects;

use SEOlity\Core\Database;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class Manager {

	const TYPES       = array( 301, 302, 307, 308, 410, 451 );
	const COUNT_OPT   = 'seolity_redirects_active';
	const REGEX_CACHE = 'seolity_regex_redirects';

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/** Normalize a source to a site-relative path ("/a/b?x=1"), keeping the query. */
	public static function normalize_source( $source ) {
		$source = trim( (string) $source );
		if ( preg_match( '#^https?://#i', $source ) ) {
			$p      = wp_parse_url( $source );
			$source = ( isset( $p['path'] ) ? $p['path'] : '/' ) . ( isset( $p['query'] ) ? '?' . $p['query'] : '' );
		}
		$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( '/' !== $home_path && 0 === strpos( $source, $home_path ) ) {
			$source = '/' . ltrim( substr( $source, strlen( $home_path ) ), '/' );
		}
		$source = '/' . ltrim( $source, '/' );
		$source = rawurldecode( $source );
		$parts  = explode( '?', $source, 2 );
		$path   = '/' === $parts[0] ? '/' : untrailingslashit( $parts[0] );
		return $path . ( isset( $parts[1] ) && '' !== $parts[1] ? '?' . $parts[1] : '' );
	}

	private function refresh_cache() {
		$db = $this->plugin->db;
		update_option( self::COUNT_OPT, (int) $db->value( "SELECT COUNT(*) FROM {redirects} WHERE project_id = %d AND status = 'active'", array( $db->project_id() ) ), true );
		delete_transient( self::REGEX_CACHE );
	}

	/**
	 * Create a redirect.
	 *
	 * @return int|\WP_Error
	 */
	public function create( $source, $target, $type = 301, $is_regex = false, $origin = 'manual', $notes = '' ) {
		$type = (int) $type;
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return new \WP_Error( 'seolity_redirect_type', __( 'Invalid redirect type.', 'seolity' ), array( 'status' => 400 ) );
		}
		if ( $is_regex ) {
			$source = (string) $source;
			if ( false === @preg_match( '#' . str_replace( '#', '\#', $source ) . '#u', '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				return new \WP_Error( 'seolity_redirect_regex', __( 'Invalid regular expression.', 'seolity' ), array( 'status' => 400 ) );
			}
		} else {
			$source = self::normalize_source( $source );
		}
		$target = in_array( $type, array( 410, 451 ), true ) ? '' : $this->normalize_target( $target );
		if ( ! in_array( $type, array( 410, 451 ), true ) && '' === $target ) {
			return new \WP_Error( 'seolity_redirect_target', __( 'Target URL is required.', 'seolity' ), array( 'status' => 400 ) );
		}
		if ( ! $is_regex && $target && self::normalize_source( $target ) === $source && $this->is_local( $target ) ) {
			return new \WP_Error( 'seolity_redirect_self', __( 'Source and target are the same.', 'seolity' ), array( 'status' => 400 ) );
		}
		if ( ! $is_regex && $target && $this->creates_loop( $source, $target ) ) {
			return new \WP_Error( 'seolity_redirect_loop', __( 'This redirect would create a loop.', 'seolity' ), array( 'status' => 400 ) );
		}
		// Rank Math's Redirections module owns redirects on this site: write into it (visible and
		// manageable under Rank Math → Redirections). SEOlity keeps a reference row for history/undo
		// with status "external" — it never serves those itself, so there is no duplicate rule.
		$external = '';
		if ( $this->plugin->rankmath->owns_redirects() && ! in_array( $type, array( 451 ), true ) ) {
			$rm = $this->plugin->rankmath->save_redirect( $source, $target, $type, $is_regex );
			if ( is_wp_error( $rm ) ) {
				return $rm;
			}
			$external = 'rankmath:' . $rm;
		}
		$db       = $this->plugin->db;
		$hash     = Database::hash( $is_regex ? 'regex:' . $source : $source );
		$existing = $db->value( 'SELECT id FROM {redirects} WHERE project_id = %d AND source_hash = %s', array( $db->project_id(), $hash ) );
		$row      = array(
			'project_id'  => $db->project_id(),
			'source'      => $source,
			'source_hash' => $hash,
			'target'      => $target,
			'type'        => $type,
			'is_regex'    => $is_regex ? 1 : 0,
			'status'      => $external ? 'external' : 'active',
			'origin'      => sanitize_key( $origin ),
			'notes'       => $external ? $external : sanitize_text_field( $notes ),
		);
		if ( $existing ) {
			$db->update( 'redirects', $row, array( 'id' => (int) $existing ) );
			$id = (int) $existing;
		} else {
			$row['created_at'] = Database::now();
			$id                = $db->insert( 'redirects', $row );
		}
		// Flatten chains: anything pointing at the new source now points at the final target.
		if ( ! $is_regex && $target && $this->is_local( $target ) ) {
			$db->query( "UPDATE {redirects} SET target = %s WHERE project_id = %d AND is_regex = 0 AND id <> %d AND target IN (%s, %s)", array( $target, $db->project_id(), $id, home_url( $source ), $source ) );
		}
		$this->refresh_cache();
		$this->plugin->audit_log->log( 'redirect.saved', 'redirect', $id, array( 'source' => $source, 'target' => $target, 'type' => $type, 'origin' => $origin, 'owner' => $external ? 'rankmath' : 'seolity' ) );
		// Resolve matching 404 log entries.
		$db->query( "UPDATE {not_found} SET status = 'redirected' WHERE project_id = %d AND url_hash = %s", array( $db->project_id(), Database::hash( $source ) ) );
		return $id;
	}

	private function normalize_target( $target ) {
		$target = trim( (string) $target );
		if ( '' === $target ) {
			return '';
		}
		if ( '/' === $target[0] ) {
			return esc_url_raw( home_url( $target ) );
		}
		return esc_url_raw( $target );
	}

	private function is_local( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return ! $host || $host === wp_parse_url( home_url(), PHP_URL_HOST );
	}

	private function creates_loop( $source, $target ) {
		$seen = array( $source );
		$cur  = self::normalize_source( $target );
		for ( $i = 0; $i < 10; $i++ ) {
			if ( in_array( $cur, $seen, true ) ) {
				return true;
			}
			$seen[] = $cur;
			$next   = $this->plugin->db->value( "SELECT target FROM {redirects} WHERE project_id = %d AND source_hash = %s AND status IN ('active','external') AND is_regex = 0", array( $this->plugin->db->project_id(), Database::hash( $cur ) ) );
			if ( ! $next || ! $this->is_local( $next ) ) {
				return false;
			}
			$cur = self::normalize_source( $next );
		}
		return true;
	}

	public function update( $id, array $data ) {
		$row = $this->plugin->db->row( 'SELECT * FROM {redirects} WHERE id = %d', array( (int) $id ) );
		if ( ! $row ) {
			return new \WP_Error( 'seolity_redirect', __( 'Redirect not found.', 'seolity' ), array( 'status' => 404 ) );
		}
		if ( isset( $data['status'] ) && count( $data ) === 1 ) {
			$this->plugin->db->update( 'redirects', array( 'status' => 'active' === $data['status'] ? 'active' : 'disabled' ), array( 'id' => (int) $id ) );
			$this->refresh_cache();
			return (int) $id;
		}
		$this->plugin->db->delete( 'redirects', array( 'id' => (int) $id ) );
		return $this->create(
			isset( $data['source'] ) ? $data['source'] : $row['source'],
			isset( $data['target'] ) ? $data['target'] : $row['target'],
			isset( $data['type'] ) ? (int) $data['type'] : (int) $row['type'],
			isset( $data['is_regex'] ) ? (bool) $data['is_regex'] : (bool) $row['is_regex'],
			$row['origin'],
			isset( $data['notes'] ) ? $data['notes'] : (string) $row['notes']
		);
	}

	/** Where a redirect lives: array( 'rankmath', id ) or array( 'seolity', id ). */
	public function owner( array $row ) {
		if ( 'external' === $row['status'] && 0 === strpos( (string) $row['notes'], 'rankmath:' ) ) {
			return array( 'rankmath', (int) substr( $row['notes'], 9 ) );
		}
		return array( 'seolity', (int) $row['id'] );
	}

	public function delete( $id ) {
		$row = $this->plugin->db->row( 'SELECT * FROM {redirects} WHERE id = %d', array( (int) $id ) );
		if ( $row ) {
			$own = $this->owner( $row );
			if ( 'rankmath' === $own[0] ) {
				$this->plugin->rankmath->delete_redirect( $own[1] );
			}
		}
		$ok = $this->plugin->db->delete( 'redirects', array( 'id' => (int) $id ) );
		$this->refresh_cache();
		$this->plugin->audit_log->log( 'redirect.deleted', 'redirect', (int) $id );
		return (bool) $ok;
	}

	public function query( array $f ) {
		$db    = $this->plugin->db;
		$where = array( 'project_id = %d' );
		$args  = array( $db->project_id() );
		if ( ! empty( $f['search'] ) ) {
			$where[] = '(source LIKE %s OR target LIKE %s)';
			$like    = '%' . $db->wpdb->esc_like( $f['search'] ) . '%';
			$args[]  = $like;
			$args[]  = $like;
		}
		if ( ! empty( $f['type'] ) ) {
			$where[] = 'type = %d';
			$args[]  = (int) $f['type'];
		}
		$per  = isset( $f['per_page'] ) ? max( 1, min( 500, (int) $f['per_page'] ) ) : 50;
		$page = isset( $f['page'] ) ? max( 1, (int) $f['page'] ) : 1;
		return array(
			'items'  => $db->rows( 'SELECT * FROM {redirects} WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d', array_merge( $args, array( $per, ( $page - 1 ) * $per ) ) ),
			'total'  => (int) $db->value( 'SELECT COUNT(*) FROM {redirects} WHERE ' . implode( ' AND ', $where ), $args ),
			'chains' => $this->chains(),
		);
	}

	/** Detect chains and loops among stored redirects. */
	public function chains() {
		$map = array();
		foreach ( $this->plugin->db->rows( "SELECT id, source, target FROM {redirects} WHERE project_id = %d AND status = 'active' AND is_regex = 0 AND target <> ''", array( $this->plugin->db->project_id() ) ) as $r ) {
			if ( $this->is_local( $r['target'] ) ) {
				$map[ $r['source'] ] = array( 'id' => (int) $r['id'], 'to' => self::normalize_source( $r['target'] ) );
			}
		}
		$out = array();
		foreach ( $map as $src => $r ) {
			$chain = array( $src );
			$cur   = $r['to'];
			while ( isset( $map[ $cur ] ) && count( $chain ) < 10 && ! in_array( $cur, $chain, true ) ) {
				$chain[] = $cur;
				$cur     = $map[ $cur ]['to'];
			}
			if ( count( $chain ) > 1 || in_array( $cur, $chain, true ) ) {
				$out[] = array( 'id' => $r['id'], 'chain' => array_merge( $chain, array( $cur ) ), 'loop' => in_array( $cur, $chain, true ) );
			}
		}
		return $out;
	}

	/** Point every chain start directly to its final destination. */
	public function fix_chains() {
		$fixed = 0;
		foreach ( $this->chains() as $c ) {
			if ( $c['loop'] ) {
				continue;
			}
			$final = end( $c['chain'] );
			$this->plugin->db->update( 'redirects', array( 'target' => home_url( $final ) ), array( 'id' => $c['id'] ) );
			$fixed++;
		}
		$this->refresh_cache();
		return $fixed;
	}

	/**
	 * Find a redirect for the current request path.
	 *
	 * @return array|null
	 */
	public function match( $request_uri ) {
		if ( ! (int) get_option( self::COUNT_OPT, 0 ) ) {
			return null;
		}
		$db     = $this->plugin->db;
		$source = self::normalize_source( $request_uri );
		$path   = explode( '?', $source, 2 )[0];
		foreach ( array_unique( array( $source, $path ) ) as $candidate ) {
			$row = $db->row( "SELECT id, target, type FROM {redirects} WHERE project_id = %d AND source_hash = %s AND status = 'active' AND is_regex = 0", array( $db->project_id(), Database::hash( $candidate ) ) );
			if ( $row ) {
				return $row;
			}
		}
		$regex = get_transient( self::REGEX_CACHE );
		if ( false === $regex ) {
			$regex = $db->rows( "SELECT id, source, target, type FROM {redirects} WHERE project_id = %d AND is_regex = 1 AND status = 'active' ORDER BY id ASC LIMIT 500", array( $db->project_id() ) );
			set_transient( self::REGEX_CACHE, $regex, DAY_IN_SECONDS );
		}
		foreach ( $regex as $r ) {
			$pattern = '#' . str_replace( '#', '\#', $r['source'] ) . '#u';
			if ( @preg_match( $pattern, $source, $m ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				$target = preg_replace_callback(
					'/\$(\d+)/',
					static function ( $x ) use ( $m ) {
						return isset( $m[ (int) $x[1] ] ) ? rawurlencode( $m[ (int) $x[1] ] ) : '';
					},
					$r['target']
				);
				return array( 'id' => $r['id'], 'target' => str_replace( '%2F', '/', $target ), 'type' => $r['type'] );
			}
		}
		return null;
	}

	/** template_redirect handler. */
	public function handle() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ! $this->plugin->settings->get( 'redirects_enabled' ) ) {
			return;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- normalized below.
		$rule = $this->match( (string) $uri );
		if ( ! $rule ) {
			return;
		}
		$this->plugin->db->query( 'UPDATE {redirects} SET hits = hits + 1, last_hit = %s WHERE id = %d', array( Database::now(), (int) $rule['id'] ) );
		$type = (int) $rule['type'];
		if ( in_array( $type, array( 410, 451 ), true ) ) {
			status_header( $type );
			nocache_headers();
			header( 'X-Robots-Tag: noindex' );
			echo '<!doctype html><meta charset="utf-8"><title>' . esc_html( 410 === $type ? 'Gone' : 'Unavailable For Legal Reasons' ) . '</title><h1>' . esc_html( $type ) . '</h1>';
			exit;
		}
		header( 'X-Redirect-By: SEOlity' );
		wp_redirect( esc_url_raw( $rule['target'] ), $type, 'SEOlity' ); // phpcs:ignore WordPress.Security.SafeRedirect -- external targets are a legitimate admin-configured feature.
		exit;
	}

	/** CSV import: source,target,type[,regex]. */
	public function import( $csv ) {
		$created = 0;
		$errors  = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $csv ) as $i => $line ) {
			if ( '' === trim( $line ) || 0 === $i && false !== stripos( $line, 'source' ) ) {
				continue;
			}
			$cols = str_getcsv( $line );
			if ( count( $cols ) < 2 ) {
				$errors[] = array( 'line' => $i + 1, 'error' => 'columns' );
				continue;
			}
			$res = $this->create( $cols[0], $cols[1], isset( $cols[2] ) && $cols[2] ? (int) $cols[2] : 301, ! empty( $cols[3] ) && '0' !== $cols[3], 'import' );
			if ( is_wp_error( $res ) ) {
				$errors[] = array( 'line' => $i + 1, 'error' => $res->get_error_message() );
			} else {
				$created++;
			}
			if ( $i > 10000 ) {
				break;
			}
		}
		return array( 'created' => $created, 'errors' => array_slice( $errors, 0, 100 ) );
	}

	public function export_csv() {
		$rows = $this->plugin->db->rows( 'SELECT source, target, type, is_regex, hits, last_hit, status FROM {redirects} WHERE project_id = %d ORDER BY id', array( $this->plugin->db->project_id() ) );
		$out  = fopen( 'php://temp', 'r+' );
		fputcsv( $out, array( 'source', 'target', 'type', 'regex', 'hits', 'last_hit', 'status' ) );
		foreach ( $rows as $r ) {
			fputcsv( $out, array_values( $r ) );
		}
		rewind( $out );
		$csv = stream_get_contents( $out );
		fclose( $out );
		return $csv;
	}
}
