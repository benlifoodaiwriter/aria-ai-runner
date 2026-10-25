<?php
/**
 * Diagnose and (where the host really allows it) install the official Claude Code / Codex CLI
 * on this server, so subscription accounts can run "Direct" without GitHub.
 *
 * Safety: nothing here accepts a command from the browser. The only programs started are
 * node/npm (found on the server, version-checked) with a fixed argument list and one of two
 * constant package names, never through a shell. Shared hosting that blocks proc_open, has no
 * Node.js or too little disk/memory gets an honest "not possible here" with the reason.
 *
 * @package SEOlity
 */

namespace SEOlity\AI\Remote;

use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class CliSetup {

	/**
	 * Official packages and their measured requirements (npm registry, linux x64, latest at
	 * release time: claude-code 2.1.310 = 291.6 MB unpacked with its platform binary; codex
	 * 0.164.0 = 444.3 MB). Free space must cover the package plus npm's cache and temp files.
	 */
	const PACKAGES = array(
		'claude' => array( 'package' => '@anthropic-ai/claude-code', 'node' => 22, 'mb' => 292, 'need_mb' => 700, 'ram_mb' => 512 ),
		'codex'  => array( 'package' => '@openai/codex', 'node' => 16, 'mb' => 445, 'need_mb' => 1000, 'ram_mb' => 512 ),
	);

	const LOG = 'seolity_cli_install';

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/** Install folder: outside the web root when possible (one level above WordPress). */
	public static function prefix() {
		$above = dirname( untrailingslashit( ABSPATH ) ) . '/seolity-cli';
		if ( is_dir( $above ) || ( is_writable( dirname( $above ) ) && ! ini_get( 'open_basedir' ) ) ) {
			return $above;
		}
		$up = wp_upload_dir();
		return trailingslashit( $up['basedir'] ) . 'seolity-cli/npm';
	}

	private static function inside_webroot( $dir ) {
		return 0 === strpos( wp_normalize_path( $dir ), wp_normalize_path( ABSPATH ) );
	}

	/** Node.js binary: system PATH, common folders, CloudLinux "Setup Node.js App" and nodevenv. */
	public function node() {
		$dirs = array_merge( explode( PATH_SEPARATOR, (string) getenv( 'PATH' ) ), array( '/usr/local/bin', '/usr/bin', '/opt/homebrew/bin' ) );
		foreach ( array_merge( (array) glob( '/opt/alt/alt-nodejs*/root/usr/bin', GLOB_ONLYDIR ), (array) glob( ( getenv( 'HOME' ) ? getenv( 'HOME' ) : '/nonexistent' ) . '/nodevenv/*/*/bin', GLOB_ONLYDIR ) ) as $d ) {
			$dirs[] = $d;
		}
		$best = array( '', 0 );
		foreach ( array_filter( array_unique( $dirs ) ) as $dir ) {
			$f = rtrim( $dir, '/' ) . '/node';
			if ( @is_file( $f ) && @is_executable( $f ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				$v = $this->version( $f );
				if ( $v > $best[1] ) {
					$best = array( $f, $v );
				}
			}
		}
		return $best;
	}

	private function version( $node ) {
		$r = $this->plugin->localcli->exec( array( $node, '--version' ), array( 'PATH' => dirname( $node ) . ':/usr/bin:/bin' ), sys_get_temp_dir(), 20 );
		return ! is_wp_error( $r ) && preg_match( '/v(\d+)\./', $r['out'], $m ) ? (int) $m[1] : 0;
	}

	private static function ram_mb() {
		$m = @file_get_contents( '/proc/meminfo' ); // phpcs:ignore
		if ( $m && preg_match( '/MemAvailable:\s+(\d+)/', $m, $x ) ) {
			return (int) round( $x[1] / 1024 );
		}
		return null;
	}

	private static function libc() {
		if ( 'Linux' !== PHP_OS_FAMILY ) {
			return '';
		}
		return @file_exists( '/etc/alpine-release' ) || glob( '/lib/ld-musl-*' ) ? 'musl' : 'glibc'; // phpcs:ignore
	}

	/**
	 * Every requirement with its state and, when it fails, what to do.
	 *
	 * @return array{engine:string,possible:bool,checks:array,installed:string,prefix:string}
	 */
	public function diagnose( $engine ) {
		$req    = self::PACKAGES[ $engine ];
		$checks = array();
		$add    = static function ( $id, $ok, $label, $value, $fix = '' ) use ( &$checks ) {
			$checks[] = array( 'id' => $id, 'ok' => $ok, 'label' => $label, 'value' => $value, 'fix' => $ok ? '' : $fix );
		};
		$exec = LocalCli::can_exec();
		$add( 'proc_open', $exec, __( 'PHP may start programs (proc_open)', 'seolity' ), $exec ? __( 'Allowed', 'seolity' ) : __( 'Disabled by the host', 'seolity' ), __( 'Most shared hosts disable this on purpose. Ask the host to allow proc_open, or use a VPS — otherwise use the GitHub or Cloudflare route.', 'seolity' ) );
		$os = PHP_OS_FAMILY . ' ' . php_uname( 'm' ) . ( self::libc() ? ' (' . self::libc() . ')' : '' );
		$add( 'os', in_array( PHP_OS_FAMILY, array( 'Linux', 'Darwin' ), true ) && in_array( php_uname( 'm' ), array( 'x86_64', 'amd64', 'aarch64', 'arm64' ), true ), __( 'Operating system', 'seolity' ), $os, __( 'The official CLIs ship for Linux and macOS on x64/arm64 only.', 'seolity' ) );
		$node = $exec ? $this->node() : array( '', 0 );
		/* translators: %d: version */
		$add( 'node', $node[1] >= $req['node'], sprintf( __( 'Node.js %d or newer', 'seolity' ), $req['node'] ), $node[0] ? 'v' . $node[1] . ' — ' . $node[0] : __( 'Not found', 'seolity' ), __( 'In cPanel open “Setup Node.js App” and create an app with the newest Node.js (this installs it for your account), or install Node.js on the server.', 'seolity' ) );
		$npm = $node[0] && is_file( dirname( $node[0] ) . '/npm' ) ? dirname( $node[0] ) . '/npm' : '';
		$add( 'npm', '' !== $npm, 'npm', $npm ? $npm : __( 'Not found', 'seolity' ), __( 'npm comes with Node.js; reinstall Node.js with npm.', 'seolity' ) );
		$prefix = self::prefix();
		$parent = is_dir( $prefix ) ? $prefix : dirname( $prefix );
		wp_mkdir_p( $parent );
		$free = @disk_free_space( $parent ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$free = false === $free ? null : (int) round( $free / 1048576 );
		/* translators: 1 needed MB 2 package MB */
		$add( 'disk', null === $free || $free >= $req['need_mb'], sprintf( __( 'Free disk space: %1$d MB (package %2$d MB + npm cache)', 'seolity' ), $req['need_mb'], $req['mb'] ), null === $free ? __( 'Unknown (the host does not report it)', 'seolity' ) : $free . ' MB', __( 'Free up space or ask for a larger plan; shared plans with 1–2 GB of total space usually cannot hold it.', 'seolity' ) );
		$ram = self::ram_mb();
		/* translators: %d: MB */
		$add( 'ram', null === $ram || $ram >= $req['ram_mb'], sprintf( __( 'Available memory: %d MB', 'seolity' ), $req['ram_mb'] ), null === $ram ? __( 'Unknown', 'seolity' ) : $ram . ' MB', __( 'The CLI is a Node.js program; hosts that limit processes to a few hundred MB stop it while it answers.', 'seolity' ) );
		$add( 'writable', is_writable( $parent ), __( 'Install folder is writable', 'seolity' ), $prefix . ( self::inside_webroot( $prefix ) ? ' ' . __( '(inside the web root — protected with deny rules)', 'seolity' ) : '' ), __( 'Make the folder writable for the PHP user.', 'seolity' ) );
		$installed = $this->plugin->localcli->binary( $engine );
		$possible  = ! array_filter(
			$checks,
			static function ( $c ) {
				return ! $c['ok'];
			}
		);
		return array( 'engine' => $engine, 'package' => $req['package'], 'possible' => $possible, 'checks' => $checks, 'installed' => $installed, 'prefix' => $prefix, 'log' => get_option( self::LOG . '_' . $engine ) );
	}

	/** Start the installation in the background (only when every check passes). */
	public function start_install( $engine ) {
		$d = $this->diagnose( $engine );
		if ( ! $d['possible'] ) {
			return new \WP_Error( 'seolity_cli', __( 'This server does not meet the requirements — see the checks.', 'seolity' ), array( 'status' => 400, 'diagnose' => $d ) );
		}
		update_option( self::LOG . '_' . $engine, array( 'status' => 'running', 'at' => gmdate( 'c' ), 'out' => '' ), false );
		$aid = $this->plugin->activity->start( 'job', __( 'Installing the official CLI', 'seolity' ), array( 'detail' => $d['package'], 'dock' => true, 'status' => 'queued' ) );
		$job = $this->plugin->queue->push( 'cli_install', array( 'engine' => $engine, 'activity_id' => $aid ), array( 'unique' => 'cli_' . $engine, 'priority' => 0, 'max_attempts' => 1, 'timeout' => 1200 ) );
		$this->plugin->activity->attach_job( $aid, $job );
		\SEOlity\Activity\AiJobs::run_after_response( $this->plugin );
		return $this->diagnose( $engine );
	}

	/** Queue handler: npm install into the prefix, then verify `<cli> --version`. */
	public function install( $engine, $aid ) {
		$req  = self::PACKAGES[ $engine ];
		$node = $this->node();
		$npm  = dirname( $node[0] ) . '/npm';
		$pre  = self::prefix();
		wp_mkdir_p( $pre );
		if ( self::inside_webroot( $pre ) ) {
			@file_put_contents( $pre . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore
			@file_put_contents( $pre . '/index.php', "<?php // Silence.\n" ); // phpcs:ignore
		}
		$env = array( 'PATH' => dirname( $node[0] ) . ':/usr/bin:/bin', 'HOME' => $pre, 'npm_config_cache' => $pre . '/.npm-cache', 'npm_config_update_notifier' => 'false', 'LANG' => 'C.UTF-8' );
		// Servers behind an outbound proxy or with their own CA keep working (standard variables only).
		foreach ( array( 'HTTPS_PROXY', 'https_proxy', 'HTTP_PROXY', 'http_proxy', 'NO_PROXY', 'no_proxy', 'NODE_EXTRA_CA_CERTS', 'SSL_CERT_FILE', 'npm_config_cafile', 'npm_config_registry', 'npm_config_https_proxy', 'npm_config_proxy' ) as $k ) {
			if ( false !== getenv( $k ) && '' !== getenv( $k ) ) {
				$env[ $k ] = getenv( $k );
			}
		}
		if ( empty( $env['NODE_EXTRA_CA_CERTS'] ) && ! empty( $env['SSL_CERT_FILE'] ) ) {
			$env['NODE_EXTRA_CA_CERTS'] = $env['SSL_CERT_FILE'];
		}
		$this->plugin->activity->step( (int) $aid, sprintf( /* translators: %s: package */ __( 'npm install %s …', 'seolity' ), $req['package'] ), 20 );
		$r   = $this->plugin->localcli->exec( array( $npm, 'install', '--prefix', $pre, '--no-audit', '--no-fund', '--omit=dev', '--loglevel=error', $req['package'] . '@latest' ), $env, $pre, 1100 );
		$out = is_wp_error( $r ) ? $r->get_error_message() : trim( $r['err'] . "\n" . $r['out'] );
		// Remove npm's download cache: the installed files are all that is needed.
		$this->rrmdir( $pre . '/.npm-cache' );
		delete_transient( 'seolity_cli_' . $engine );
		$bin = $pre . '/node_modules/.bin/' . LocalCli::BINARIES[ $engine ];
		$ver = '';
		if ( is_file( $bin ) ) {
			$v   = $this->plugin->localcli->exec( array( $bin, '--version' ), $env, $pre, 60 );
			$ver = is_wp_error( $v ) ? '' : trim( $v['out'] );
		}
		$ok = '' !== $ver;
		if ( $ok ) {
			$this->plugin->settings->update( array( 'sub_cli_' . $engine => $bin ) );
		}
		update_option( self::LOG . '_' . $engine, array( 'status' => $ok ? 'done' : 'failed', 'at' => gmdate( 'c' ), 'version' => $ver, 'out' => mb_substr( $out, -3000 ) ), false );
		/* translators: %s: version */
		$msg = $ok ? sprintf( __( 'Installed and verified: %s', 'seolity' ), $ver ) : __( 'The installation did not finish — see the log.', 'seolity' );
		$this->plugin->activity->finish( (int) $aid, $ok ? 'done' : 'failed', $msg, array( 'summary' => $msg, 'route' => '#/network' ) );
		$this->plugin->audit_log->log( 'cli.install', 'site', null, array( 'engine' => $engine, 'ok' => $ok, 'version' => $ver ) );
		return true;
	}

	private function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $f ) {
			$f->isDir() && ! $f->isLink() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() ); // phpcs:ignore
		}
		@rmdir( $dir ); // phpcs:ignore
	}
}
