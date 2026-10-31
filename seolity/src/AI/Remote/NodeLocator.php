<?php
/**
 * Finds every Node.js on this server that PHP can really run — not just what the PHP worker's
 * PATH happens to contain. cPanel/CloudLinux installs Node.js under /opt/alt/alt-nodejsNN, the
 * "Setup Node.js App" feature under ~/nodevenv, and nvm/Volta/Homebrew under other folders.
 *
 * Every candidate is checked (file exists, is executable, `node --version` answers) and recorded
 * with its absolute path and matching npm.
 *
 * Choice (documented preference): 1) the path an administrator set in settings, when it is valid;
 * 2) otherwise the newest even-numbered (LTS) major that meets the CLI's minimum; 3) otherwise the
 * newest version that meets the minimum; ties go to the first found in search order.
 *
 * @package SEOlity
 */

namespace SEOlity\AI\Remote;

defined( 'ABSPATH' ) || exit;

final class NodeLocator {

	const CACHE = 'seolity_node_scan';

	/** Folders to search for a `node` binary (globs allowed). Filter: seolity_node_search_paths. */
	public static function search_paths() {
		$home  = ProcessRunner::user_home();
		$paths = array();
		foreach ( array( 'NODE', 'NODEJS_HOME', 'NVM_BIN', 'NODE_HOME' ) as $k ) {
			$v = (string) getenv( $k );
			if ( '' !== $v ) {
				$paths[] = 'node' === basename( $v ) ? dirname( $v ) : ( is_dir( $v . '/bin' ) ? $v . '/bin' : $v );
			}
		}
		$paths = array_merge( $paths, explode( PATH_SEPARATOR, (string) getenv( 'PATH' ) ) );
		$paths = array_merge(
			$paths,
			array(
				'/opt/alt/alt-nodejs*/root/usr/bin', // CloudLinux (cPanel "Setup Node.js App").
				'/opt/cpanel/ea-nodejs*/bin',        // cPanel EasyApache Node.js.
				'/usr/local/bin',
				'/usr/bin',
				'/opt/homebrew/bin',
				'/opt/node*/bin',
				'/usr/local/node*/bin',
				'/usr/local/lib/nodejs/*/bin',
			)
		);
		if ( $home ) {
			$paths = array_merge(
				$paths,
				array(
					$home . '/nodevenv/*/*/bin',           // CloudLinux per-application virtual environments.
					$home . '/.nvm/versions/node/*/bin',
					$home . '/.volta/bin',
					$home . '/.local/bin',
					$home . '/bin',
					$home . '/node*/bin',
				)
			);
		}
		return (array) apply_filters( 'seolity_node_search_paths', array_values( array_unique( array_filter( $paths ) ) ) );
	}

	/**
	 * Every node binary found, validated.
	 *
	 * @return array[] {path, real, version, major, ok, error, npm(string[]|null)}
	 */
	public static function scan( $fresh = false ) {
		if ( ! ProcessRunner::can_exec() ) {
			return array(); // Nothing can be validated; never cache that as "no Node.js".
		}
		$cached = $fresh ? false : get_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$seen = array();
		$list = array();
		foreach ( self::search_paths() as $pattern ) {
			$dirs = false !== strpbrk( $pattern, '*?[' ) ? (array) @glob( $pattern, GLOB_ONLYDIR ) : array( $pattern ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			sort( $dirs, SORT_NATURAL );
			foreach ( array_reverse( $dirs ) as $dir ) {
				$f = rtrim( $dir, '/' ) . '/node';
				if ( ! @is_file( $f ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
					continue;
				}
				$real = (string) @realpath( $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				$key  = $real ? $real : $f;
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$list[]       = self::probe( $f, $real );
			}
		}
		set_transient( self::CACHE, $list, 12 * HOUR_IN_SECONDS );
		return $list;
	}

	/** Check one candidate. */
	public static function probe( $f, $real = '' ) {
		$item = array( 'path' => $f, 'real' => $real, 'version' => '', 'major' => 0, 'ok' => false, 'error' => '', 'npm' => null );
		if ( ! @is_executable( $f ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$item['error'] = 'NODE_PERMISSION_DENIED';
			return $item;
		}
		$env = array( 'PATH' => dirname( $f ) . ':/usr/bin:/bin', 'HOME' => untrailingslashit( get_temp_dir() ), 'LANG' => 'C.UTF-8' );
		$r   = ProcessRunner::run( array( $f, '--version' ), '', $env, get_temp_dir(), 20 );
		if ( is_wp_error( $r ) ) {
			$item['error'] = (string) $r->get_error_data()['cli_code'];
			return $item;
		}
		if ( ! preg_match( '/^v(\d+)\.(\d+)\.(\d+)/', trim( $r['out'] ), $m ) ) {
			$item['error'] = 126 === $r['code'] ? 'NODE_PERMISSION_DENIED' : 'NODE_BROKEN';
			return $item;
		}
		$item['version'] = 'v' . $m[1] . '.' . $m[2] . '.' . $m[3];
		$item['major']   = (int) $m[1];
		$item['ok']      = true;
		$item['npm']     = self::npm_for( $f );
		return $item;
	}

	/** npm next to node, or npm-cli.js started by that node. */
	private static function npm_for( $node ) {
		$dir = dirname( $node );
		if ( @is_file( $dir . '/npm' ) && @is_executable( $dir . '/npm' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return array( $dir . '/npm' );
		}
		foreach ( array( $dir . '/../lib/node_modules/npm/bin/npm-cli.js', $dir . '/node_modules/npm/bin/npm-cli.js' ) as $js ) {
			if ( @is_file( $js ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				return array( $node, (string) realpath( $js ) );
			}
		}
		return null;
	}

	/** The Node.js to use for an engine needing at least $min (see the class comment). */
	public static function select( $min = 18 ) {
		$set = trim( (string) seolity()->settings->get( 'cli_node_path' ) );
		if ( '' !== $set ) {
			if ( 'node' === basename( $set ) && @is_file( $set ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				$p = self::probe( $set, (string) @realpath( $set ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				if ( $p['ok'] ) {
					$p['source'] = 'setting';
					return $p;
				}
			}
		}
		$ok = array_values(
			array_filter(
				self::scan(),
				static function ( $n ) use ( $min ) {
					return $n['ok'] && $n['major'] >= $min;
				}
			)
		);
		if ( ! $ok ) {
			return null;
		}
		foreach ( $ok as $i => $n ) {
			$ok[ $i ]['order'] = $i;
		}
		usort(
			$ok,
			static function ( $a, $b ) {
				$lts = ( 0 === $b['major'] % 2 ) <=> ( 0 === $a['major'] % 2 );
				if ( 0 !== $lts ) {
					return $lts;
				}
				$v = version_compare( ltrim( $b['version'], 'v' ), ltrim( $a['version'], 'v' ) );
				return 0 !== $v ? $v : $a['order'] <=> $b['order'];
			}
		);
		$ok[0]['source'] = 'scan';
		return $ok[0];
	}

	/** Folders of every validated node (selected first) — for PATH. Uses the cache only. */
	public static function dirs() {
		$cached = get_transient( self::CACHE );
		$dirs   = array();
		$set    = trim( (string) seolity()->settings->get( 'cli_node_path' ) );
		if ( '' !== $set && 'node' === basename( $set ) ) {
			$dirs[] = dirname( $set );
		}
		foreach ( is_array( $cached ) ? $cached : array() as $n ) {
			if ( $n['ok'] ) {
				$dirs[] = dirname( $n['path'] );
			}
		}
		// Even before the first scan, CloudLinux folders are always worth having on PATH.
		foreach ( (array) @glob( '/opt/alt/alt-nodejs*/root/usr/bin', GLOB_ONLYDIR ) as $d ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$dirs[] = $d;
		}
		return array_values( array_unique( $dirs ) );
	}
}
