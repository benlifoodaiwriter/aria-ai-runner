<?php
/**
 * The one place SEOlity starts programs (node, npm, the official Claude Code / Codex CLIs).
 *
 * Every call gets an environment built here — never the PHP worker's inherited one, which on
 * cPanel/CloudLinux is often just /usr/local/bin:/bin:/usr/bin and breaks `#!/usr/bin/env node`.
 * Commands are argument arrays handed to proc_open without a shell, so nothing can be injected,
 * and the first argument must be an existing absolute, executable file.
 *
 * @package SEOlity
 */

namespace SEOlity\AI\Remote;

defined( 'ABSPATH' ) || exit;

final class ProcessRunner {

	/** Functions the runner needs; any of them in disable_functions makes execution impossible. */
	const NEEDS = array( 'proc_open', 'proc_get_status', 'proc_close', 'proc_terminate', 'stream_select' );

	/** Standard proxy / CA variables passed through so hosts behind an outbound proxy keep working. */
	const PASS = array( 'HTTPS_PROXY', 'https_proxy', 'HTTP_PROXY', 'http_proxy', 'NO_PROXY', 'no_proxy', 'NODE_EXTRA_CA_CERTS', 'SSL_CERT_FILE', 'npm_config_cafile', 'npm_config_registry', 'npm_config_https_proxy', 'npm_config_proxy' );

	/** Why programs cannot be started here ('' = they can). */
	public static function blocked() {
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		foreach ( self::NEEDS as $fn ) {
			if ( ! function_exists( $fn ) || in_array( $fn, $disabled, true ) ) {
				return $fn;
			}
		}
		return '';
	}

	public static function can_exec() {
		return '' === self::blocked();
	}

	/** Home folder of the PHP user (getenv('HOME') is empty under many PHP-FPM / lsphp pools). */
	public static function user_home() {
		$home = (string) getenv( 'HOME' );
		if ( '' === $home && function_exists( 'posix_getpwuid' ) && function_exists( 'posix_geteuid' ) ) {
			$pw   = @posix_getpwuid( posix_geteuid() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$home = is_array( $pw ) && ! empty( $pw['dir'] ) ? $pw['dir'] : '';
		}
		if ( '' === $home ) {
			// cPanel layout: /home/<user>/public_html/… — the account home is the folder above the web root.
			$abs = wp_normalize_path( ABSPATH );
			if ( preg_match( '#^(/home\d*/[^/]+)/#', $abs, $m ) ) {
				$home = $m[1];
			}
		}
		return untrailingslashit( $home );
	}

	/**
	 * Environment for one program: PATH = the program's folder, the selected Node.js folder, every
	 * other validated Node.js folder, the inherited PATH and the system folders — in that order.
	 *
	 * @param string[] $bins  Executables the process will use (their folders go first).
	 * @param array    $extra Extra variables (HOME, CODEX_HOME, tokens …).
	 */
	public static function env( array $bins, array $extra = array() ) {
		$dirs = array();
		foreach ( $bins as $b ) {
			if ( $b ) {
				$dirs[] = dirname( $b );
				$real   = @realpath( $b ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				if ( $real ) {
					$dirs[] = dirname( $real );
				}
			}
		}
		foreach ( NodeLocator::dirs() as $d ) {
			$dirs[] = $d;
		}
		$dirs = array_merge( $dirs, explode( PATH_SEPARATOR, (string) getenv( 'PATH' ) ), array( '/usr/local/bin', '/usr/bin', '/bin' ) );
		$path = implode( PATH_SEPARATOR, array_values( array_unique( array_filter( array_map( 'trim', $dirs ) ) ) ) );
		$tmp  = get_temp_dir();
		$env  = array(
			'PATH'                => $path,
			'HOME'                => self::user_home() ? self::user_home() : untrailingslashit( $tmp ),
			'LANG'                => 'C.UTF-8',
			'LC_ALL'              => 'C.UTF-8',
			'TMPDIR'              => untrailingslashit( $tmp ),
			'DISABLE_AUTOUPDATER' => '1',
			'NO_COLOR'            => '1',
			'CI'                  => '1',
		);
		foreach ( self::PASS as $k ) {
			$v = getenv( $k );
			if ( false !== $v && '' !== $v ) {
				$env[ $k ] = $v;
			}
		}
		if ( empty( $env['NODE_EXTRA_CA_CERTS'] ) && ! empty( $env['SSL_CERT_FILE'] ) ) {
			$env['NODE_EXTRA_CA_CERTS'] = $env['SSL_CERT_FILE'];
		}
		return array_merge( $env, $extra );
	}

	/**
	 * Run a fixed argument list.
	 *
	 * @param string[]      $cmd       Absolute executable followed by its arguments.
	 * @param string        $input     Written to stdin, then stdin is closed.
	 * @param array         $env       From env().
	 * @param string        $cwd       Working folder (must exist).
	 * @param int           $timeout   Seconds.
	 * @param callable|null $on_output Called with ( $out, $err ) whenever new output arrives.
	 * @return array{out:string,err:string,code:int,signal:int,timed_out:bool,ms:int}|\WP_Error
	 */
	public static function run( array $cmd, $input, array $env, $cwd, $timeout, $on_output = null ) {
		$blocked = self::blocked();
		if ( '' !== $blocked ) {
			return CliErrors::error( 'PROC_OPEN_DISABLED', '', array( 'function' => $blocked ) );
		}
		$cmd = array_values( array_map( 'strval', $cmd ) );
		if ( ! $cmd || '/' !== substr( $cmd[0], 0, 1 ) || ! @is_file( $cmd[0] ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return CliErrors::error( 'CLI_NOT_FOUND' );
		}
		if ( ! @is_executable( $cmd[0] ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return CliErrors::error( 'CLI_PERMISSION_DENIED' );
		}
		if ( ! is_dir( $cwd ) ) {
			$cwd = get_temp_dir();
		}
		$timeout = max( 5, (int) $timeout );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( $timeout + 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$start = microtime( true );
		$proc  = @proc_open( $cmd, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, $cwd, $env ); // phpcs:ignore
		if ( ! is_resource( $proc ) ) {
			return CliErrors::error( 'PROC_OPEN_FAILED' );
		}
		if ( '' !== (string) $input ) {
			@fwrite( $pipes[0], (string) $input ); // phpcs:ignore
		}
		fclose( $pipes[0] ); // phpcs:ignore
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );
		$out  = '';
		$err  = '';
		$code = -1;
		$sig  = 0;
		$late = false;
		while ( true ) {
			$read  = array( $pipes[1], $pipes[2] );
			$write = null;
			$ex    = null;
			$got   = false;
			if ( false !== @stream_select( $read, $write, $ex, 1 ) ) { // phpcs:ignore
				foreach ( $read as $p ) {
					$chunk = (string) fread( $p, 65536 ); // phpcs:ignore
					$got   = $got || '' !== $chunk;
					if ( $p === $pipes[1] ) {
						$out .= $chunk;
					} else {
						$err .= $chunk;
					}
				}
			}
			if ( $got && $on_output ) {
				call_user_func( $on_output, $out, $err );
			}
			$st = proc_get_status( $proc );
			if ( ! $st['running'] ) {
				$out .= (string) stream_get_contents( $pipes[1] );
				$err .= (string) stream_get_contents( $pipes[2] );
				// The exit code is only reported by the first proc_get_status() after the exit.
				$code = (int) $st['exitcode'];
				$sig  = ! empty( $st['signaled'] ) ? (int) $st['termsig'] : 0;
				break;
			}
			if ( microtime( true ) - $start > $timeout ) {
				proc_terminate( $proc, 9 );
				$late = true;
				break;
			}
		}
		fclose( $pipes[1] ); // phpcs:ignore
		fclose( $pipes[2] ); // phpcs:ignore
		proc_close( $proc );
		return array(
			'out'       => $out,
			'err'       => $err,
			'code'      => $code,
			'signal'    => $sig,
			'timed_out' => $late,
			'ms'        => (int) round( ( microtime( true ) - $start ) * 1000 ),
		);
	}

	/** Private folder (0700) outside the web root when the host allows it; deny rules otherwise. */
	public static function private_dir( $name ) {
		$base = CliSetup::prefix();
		$dir  = $base . '/' . $name;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		@chmod( $dir, 0700 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( 0 === strpos( wp_normalize_path( $dir ), wp_normalize_path( ABSPATH ) ) || 0 === strpos( wp_normalize_path( $dir ), wp_normalize_path( WP_CONTENT_DIR ) ) ) {
			foreach ( array( $base, $dir ) as $d ) {
				if ( ! is_file( $d . '/.htaccess' ) ) {
					@file_put_contents( $d . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore
					@file_put_contents( $d . '/index.php', "<?php // Silence.\n" ); // phpcs:ignore
				}
			}
		}
		return $dir;
	}

	/** Write a secret file readable only by the PHP user. */
	public static function write_secret( $file, $data ) {
		$old = umask( 0077 );
		$ok  = false !== @file_put_contents( $file, $data, LOCK_EX ); // phpcs:ignore
		umask( $old );
		@chmod( $file, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return $ok;
	}

	/** Overwrite, then delete (best effort — the file only ever lives in a 0700 folder). */
	public static function shred( $file ) {
		if ( is_link( $file ) ) {
			@unlink( $file ); // phpcs:ignore
			return;
		}
		if ( is_file( $file ) ) {
			$size = (int) @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( $size > 0 ) {
				@file_put_contents( $file, str_repeat( "\0", $size ) ); // phpcs:ignore
			}
			@unlink( $file ); // phpcs:ignore
		}
	}

	/** Delete a folder; $secure overwrites each file first (credential folders only). */
	public static function rrmdir( $dir, $secure = false ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $f ) {
			if ( $f->isDir() && ! $f->isLink() ) {
				@rmdir( $f->getPathname() ); // phpcs:ignore
			} elseif ( $secure ) {
				self::shred( $f->getPathname() );
			} else {
				@unlink( $f->getPathname() ); // phpcs:ignore
			}
		}
		@rmdir( $dir ); // phpcs:ignore
	}
}
