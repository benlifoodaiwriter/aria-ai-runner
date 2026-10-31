<?php
/**
 * Installs the official Codex CLI without npm.
 *
 * Why: the npm package is a small Node.js launcher plus a 430+ MB platform package. `npm install`
 * holds that tarball in Node's heap and needs well over 1 GB of virtual memory; on LiteSpeed /
 * CloudLinux hosting every program PHP starts inherits PHP's per-process memory limit, so npm is
 * killed half-way ("JavaScript heap out of memory" / "memory allocation … failed") after it has
 * already written hundreds of MB. The launcher only `execve`s the native binary anyway.
 *
 * How: read the official npm registry metadata for the platform build
 * (`@openai/codex@<version>-<platform>`), stream the tarball to disk (constant memory), verify the
 * registry's sha512 integrity, unpack with the system `tar` (or PharData), check that the binary
 * answers `--version`, then switch to it atomically and remove older versions. Node.js is not
 * needed to run the native binary.
 *
 * @package SEOlity
 */

namespace SEOlity\AI\Remote;

use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class CodexInstaller {

	const REGISTRY = 'https://registry.npmjs.org/@openai/codex/';

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/** [ npm platform suffix, Rust target triple ] for this server, or null. */
	public static function target() {
		$m = strtolower( php_uname( 'm' ) );
		$a = in_array( $m, array( 'x86_64', 'amd64' ), true ) ? 'x64' : ( in_array( $m, array( 'aarch64', 'arm64' ), true ) ? 'arm64' : '' );
		if ( '' === $a ) {
			return null;
		}
		if ( 'Linux' === PHP_OS_FAMILY ) {
			return array( 'linux-' . $a, ( 'x64' === $a ? 'x86_64' : 'aarch64' ) . '-unknown-linux-musl' );
		}
		if ( 'Darwin' === PHP_OS_FAMILY ) {
			return array( 'darwin-' . $a, ( 'x64' === $a ? 'x86_64' : 'aarch64' ) . '-apple-darwin' );
		}
		return null;
	}

	/** Folder holding the native versions. */
	public static function root() {
		return CliSetup::prefix() . '/codex-native';
	}

	/** Newest installed native binary, or ''. */
	public static function installed() {
		$t = self::target();
		if ( ! $t ) {
			return '';
		}
		$found = array();
		foreach ( (array) @glob( self::root() . '/*/package/vendor/' . $t[1] . '/{bin/codex,codex/codex}', GLOB_BRACE ) as $f ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( @is_file( $f ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				$found[ basename( dirname( dirname( dirname( dirname( dirname( $f ) ) ) ) ) ) ] = $f;
			}
		}
		if ( ! $found ) {
			return '';
		}
		uksort( $found, 'version_compare' );
		return (string) end( $found );
	}

	/** Extra tools shipped next to the binary (ripgrep) that Codex expects on PATH. */
	public static function tool_path( $bin ) {
		$dir = dirname( dirname( $bin ) ) . '/codex-path';
		return is_dir( $dir ) ? $dir . '/rg' : '';
	}

	/**
	 * GET a small JSON document. WordPress HTTP first; the system curl as a fallback (it honours
	 * HTTPS_PROXY on hosts behind an outbound proxy).
	 */
	private function json( $url ) {
		$r = wp_remote_get( $url, array( 'timeout' => 30, 'headers' => array( 'Accept' => 'application/json' ) ) );
		if ( ! is_wp_error( $r ) && 200 === (int) wp_remote_retrieve_response_code( $r ) ) {
			$j = json_decode( wp_remote_retrieve_body( $r ), true );
			if ( is_array( $j ) ) {
				return $j;
			}
		}
		$curl = self::curl();
		if ( $curl ) {
			$c = ProcessRunner::run( array( $curl, '-fsSL', '--max-time', '60', '-H', 'Accept: application/json', $url ), '', ProcessRunner::env( array( $curl ) ), get_temp_dir(), 90 );
			if ( ! is_wp_error( $c ) && 0 === $c['code'] ) {
				$j = json_decode( $c['out'], true );
				if ( is_array( $j ) ) {
					return $j;
				}
			}
		}
		return CliErrors::error( 'INSTALL_BLOCKED', is_wp_error( $r ) ? $r->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $r ), array(), 'codex' );
	}

	private static function curl() {
		foreach ( array( '/usr/bin/curl', '/bin/curl', '/usr/local/bin/curl' ) as $c ) {
			if ( @is_file( $c ) && @is_executable( $c ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				return $c;
			}
		}
		return '';
	}

	private static function tar() {
		foreach ( array( '/bin/tar', '/usr/bin/tar', '/usr/local/bin/tar' ) as $c ) {
			if ( @is_file( $c ) && @is_executable( $c ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				return $c;
			}
		}
		return '';
	}

	/** What would be installed (version, size) — no download. */
	public function plan() {
		$t = self::target();
		if ( ! $t ) {
			return CliErrors::error( 'INSTALL_BLOCKED', php_uname( 'm' ) . ' ' . PHP_OS_FAMILY, array(), 'codex' );
		}
		$latest = $this->json( self::REGISTRY . 'latest' );
		if ( is_wp_error( $latest ) ) {
			return $latest;
		}
		$ver = isset( $latest['version'] ) ? (string) $latest['version'] : '';
		if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $ver ) ) {
			return CliErrors::error( 'INSTALL_BLOCKED', 'registry: no version', array(), 'codex' );
		}
		$meta = $this->json( self::REGISTRY . rawurlencode( $ver . '-' . $t[0] ) );
		if ( is_wp_error( $meta ) ) {
			return $meta;
		}
		$dist = isset( $meta['dist'] ) ? (array) $meta['dist'] : array();
		$url  = isset( $dist['tarball'] ) ? (string) $dist['tarball'] : '';
		// Only the official registry is accepted as the download source.
		if ( 0 !== strpos( $url, 'https://registry.npmjs.org/@openai/codex/-/' ) || empty( $dist['integrity'] ) || 0 !== strpos( (string) $dist['integrity'], 'sha512-' ) ) {
			return CliErrors::error( 'INSTALL_BLOCKED', 'registry: unexpected tarball', array(), 'codex' );
		}
		return array(
			'version'   => $ver,
			'platform'  => $t[0],
			'triple'    => $t[1],
			'url'       => $url,
			'integrity' => (string) $dist['integrity'],
			'unpacked'  => isset( $dist['unpackedSize'] ) ? (int) $dist['unpackedSize'] : 0,
		);
	}

	/**
	 * Download, verify, unpack, test, switch.
	 *
	 * @param callable $step Progress callback ( string $message, int $percent ).
	 * @return array{version:string,bin:string}|\WP_Error
	 */
	public function install( $step ) {
		$plan = $this->plan();
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		$root = self::root();
		wp_mkdir_p( $root );
		$free = @disk_free_space( $root ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$need = (int) ( $plan['unpacked'] * 1.4 ) + 50 * 1048576;
		if ( false !== $free && $free < $need ) {
			return CliErrors::error( 'DISK_FULL', sprintf( '%d MB free, %d MB needed', (int) ( $free / 1048576 ), (int) ( $need / 1048576 ) ), array(), 'codex' );
		}
		$work = $root . '/.tmp-' . wp_generate_password( 8, false, false );
		wp_mkdir_p( $work );
		$tgz = $work . '/codex.tgz';
		try {
			/* translators: 1: version 2: MB */
			call_user_func( $step, sprintf( __( 'Downloading Codex %1$s (%2$d MB, straight to disk)…', 'seolity' ), $plan['version'], (int) ( $plan['unpacked'] / 1048576 ) ), 15 );
			$r = wp_remote_get( $plan['url'], array( 'timeout' => 900, 'stream' => true, 'filename' => $tgz ) );
			if ( is_wp_error( $r ) || 200 !== (int) wp_remote_retrieve_response_code( $r ) || ! is_file( $tgz ) || filesize( $tgz ) < 1048576 ) {
				@unlink( $tgz ); // phpcs:ignore
				$curl = self::curl();
				$c    = $curl ? ProcessRunner::run( array( $curl, '-fsSL', '--max-time', '900', '-o', $tgz, $plan['url'] ), '', ProcessRunner::env( array( $curl ) ), $work, 960 ) : null;
				if ( ! $curl || is_wp_error( $c ) || 0 !== $c['code'] || ! is_file( $tgz ) ) {
					return CliErrors::error( 'INSTALL_BLOCKED', is_wp_error( $r ) ? $r->get_error_message() : 'download failed', array(), 'codex' );
				}
			}
			call_user_func( $step, __( 'Checking the file signature (sha512 from the npm registry)…', 'seolity' ), 55 );
			$want = substr( $plan['integrity'], 7 );
			$got  = base64_encode( (string) hash_file( 'sha512', $tgz, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			if ( ! hash_equals( $want, $got ) ) {
				return CliErrors::error( 'RUNTIME_ERROR', 'integrity mismatch', array(), 'codex' );
			}
			call_user_func( $step, __( 'Unpacking…', 'seolity' ), 65 );
			$dest = $work . '/' . $plan['version'];
			wp_mkdir_p( $dest );
			$tar = self::tar();
			$x   = null;
			if ( $tar ) {
				$x = ProcessRunner::run( array( $tar, '-xzf', $tgz, '-C', $dest, '--no-same-owner' ), '', ProcessRunner::env( array( $tar ) ), $work, 600 );
				$ok = ! is_wp_error( $x ) && 0 === $x['code'];
			} else {
				try {
					$phar = new \PharData( $tgz );
					$phar->extractTo( $dest, null, true );
					$ok = true;
				} catch ( \Exception $e ) {
					$ok = false;
				}
			}
			@unlink( $tgz ); // phpcs:ignore
			$bin = '';
			foreach ( array( '/package/vendor/' . $plan['triple'] . '/bin/codex', '/package/vendor/' . $plan['triple'] . '/codex/codex' ) as $rel ) {
				if ( is_file( $dest . $rel ) ) {
					$bin = $dest . $rel;
					break;
				}
			}
			if ( ! $ok || '' === $bin ) {
				$why = isset( $x ) && is_array( $x ) ? $x['err'] : '';
				return CliErrors::error( preg_match( '/No space|ENOSPC|quota/i', $why ) ? 'DISK_FULL' : 'RUNTIME_ERROR', 'unpack failed ' . $why, array(), 'codex' );
			}
			foreach ( (array) glob( dirname( $bin ) . '/*' ) as $f ) {
				@chmod( $f, 0755 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
			foreach ( (array) glob( dirname( dirname( $bin ) ) . '/codex-path/*' ) as $f ) {
				@chmod( $f, 0755 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
			call_user_func( $step, __( 'Testing the installed program…', 'seolity' ), 85 );
			$v = ProcessRunner::run( array( $bin, '--version' ), '', ProcessRunner::env( array( $bin ) ), $work, 60 );
			if ( is_wp_error( $v ) ) {
				return $v;
			}
			if ( 0 !== $v['code'] || false === stripos( $v['out'], 'codex' ) ) {
				return CliErrors::error( CliErrors::classify( $v ), $v['err'], array( 'vmem_mb' => ProcessRunner::vmem_mb() ), 'codex' );
			}
			// Switch: move the tested version into place, then remove the older ones.
			$final = $root . '/' . $plan['version'];
			if ( is_dir( $final ) ) {
				ProcessRunner::rrmdir( $final );
			}
			if ( ! @rename( $dest, $final ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				return CliErrors::error( 'CLI_PERMISSION_DENIED', 'rename', array(), 'codex' );
			}
			foreach ( (array) glob( $root . '/*', GLOB_ONLYDIR ) as $old ) {
				if ( $old !== $final && preg_match( '/^\d+\.\d+\.\d+/', basename( $old ) ) ) {
					ProcessRunner::rrmdir( $old );
				}
			}
			return array( 'version' => trim( strtok( $v['out'], "\n" ) ), 'bin' => str_replace( $dest, $final, $bin ) );
		} finally {
			ProcessRunner::rrmdir( $work );
		}
	}
}
