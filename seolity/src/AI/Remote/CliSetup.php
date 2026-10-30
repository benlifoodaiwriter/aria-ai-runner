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
		'claude' => array( 'package' => '@anthropic-ai/claude-code', 'node' => 18, 'mb' => 292, 'need_mb' => 700, 'ram_mb' => 512 ),
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

	/** [ path, major ] of the Node.js used for installs (kept for compatibility). */
	public function node( $min = 16 ) {
		$n = NodeLocator::select( $min );
		return $n ? array( $n['path'], $n['major'] ) : array( '', 0 );
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
		$native = 'codex' === $engine;
		$add( 'node', $native || $node[1] >= $req['node'], sprintf( __( 'Node.js %d or newer', 'seolity' ), $req['node'] ), $node[0] ? 'v' . $node[1] . ' — ' . $node[0] : __( 'Not found', 'seolity' ), __( 'In cPanel open “Setup Node.js App” and create an app with the newest Node.js (this installs it for your account), or install Node.js on the server.', 'seolity' ) );
		$sel = $exec ? NodeLocator::select( $req['node'] ) : null;
		$npm = $sel && $sel['npm'] ? implode( ' ', $sel['npm'] ) : '';
		$add( 'npm', $native || '' !== $npm, 'npm', $native ? __( 'Not needed — Codex is installed as a native program', 'seolity' ) : ( $npm ? $npm : __( 'Not found', 'seolity' ) ), __( 'npm comes with Node.js; reinstall Node.js with npm.', 'seolity' ) );
		$prefix = self::prefix();
		$parent = is_dir( $prefix ) ? $prefix : dirname( $prefix );
		wp_mkdir_p( $parent );
		$free = @disk_free_space( $parent ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$free = false === $free ? null : (int) round( $free / 1048576 );
		/* translators: 1 needed MB 2 package MB */
		$add( 'disk', null === $free || $free >= $req['need_mb'], sprintf( __( 'Free disk space: %1$d MB (package %2$d MB + npm cache)', 'seolity' ), $req['need_mb'], $req['mb'] ), null === $free ? __( 'Unknown (the host does not report it)', 'seolity' ) : $free . ' MB', __( 'Free up space or ask for a larger plan; shared plans with 1–2 GB of total space usually cannot hold it.', 'seolity' ) );
		$ram = self::ram_mb();
		/* translators: %d: MB */
		$vm = ProcessRunner::vmem_mb();
		/* translators: %d: MB */
		$add( 'vmem', null === $vm || -1 === $vm || $vm >= ( $native ? 1024 : 2048 ), sprintf( __( 'Virtual memory per program: %d MB or more', 'seolity' ), $native ? 1024 : 2048 ), null === $vm ? __( 'Unknown', 'seolity' ) : ( -1 === $vm ? __( 'Unlimited', 'seolity' ) : $vm . ' MB' ), CliErrors::action( 'MEMORY_LIMIT' ) );
		$add( 'ram', true, sprintf( __( 'Available memory: %d MB', 'seolity' ), $req['ram_mb'] ), null === $ram ? __( 'Unknown', 'seolity' ) : $ram . ' MB', __( 'The CLI is a Node.js program; hosts that limit processes to a few hundred MB stop it while it answers.', 'seolity' ) );
		$add( 'writable', is_writable( $parent ), __( 'Install folder is writable', 'seolity' ), $prefix . ( self::inside_webroot( $prefix ) ? ' ' . __( '(inside the web root — protected with deny rules)', 'seolity' ) : '' ), __( 'Make the folder writable for the PHP user.', 'seolity' ) );
		$installed = $this->plugin->localcli->binary( $engine );
		$possible  = ! array_filter(
			$checks,
			static function ( $c ) {
				return ! $c['ok'];
			}
		);
		return array( 'engine' => $engine, 'package' => $req['package'], 'possible' => $possible, 'checks' => $checks, 'installed' => $installed, 'prefix' => $prefix, 'log' => $this->install_state( $engine ) );
	}

	/** Start the installation in the background (only when every check passes). */
	public function start_install( $engine ) {
		$bin = $this->plugin->localcli->binary( $engine );
		if ( '' !== $bin && ! @is_executable( $bin ) && $this->repair_permissions( $bin ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			delete_transient( 'seolity_cli_' . $engine );
			return $this->diagnose( $engine ); // Repaired: the file only lacked its execute bit.
		}
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

	/** Queue handler: npm install into the private prefix, then verify `<cli> --version`. */
	public function install( $engine, $aid ) {
		// A fatal error (PHP memory limit, host kill) must never leave the page waiting on "running".
		register_shutdown_function(
			static function () use ( $engine, $aid ) {
				$e   = error_get_last();
				$log = get_option( self::LOG . '_' . $engine );
				if ( is_array( $log ) && 'running' === $log['status'] && $e && in_array( $e['type'], array( E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
					$code = false !== stripos( $e['message'], 'memory' ) ? 'MEMORY_LIMIT' : 'RUNTIME_ERROR';
					update_option( self::LOG . '_' . $engine, array( 'status' => 'failed', 'at' => gmdate( 'c' ), 'code' => $code, 'message' => CliErrors::message( $code ), 'action' => CliErrors::action( $code, $engine ), 'out' => CliErrors::redact( $e['message'] ) ), false );
				}
			}
		);
		if ( 'codex' === $engine ) {
			return $this->install_codex( (int) $aid );
		}
		$req = self::PACKAGES[ $engine ];
		$sel = NodeLocator::select( $req['node'] );
		$pre = self::prefix();
		wp_mkdir_p( $pre );
		if ( self::inside_webroot( $pre ) ) {
			@file_put_contents( $pre . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore
			@file_put_contents( $pre . '/index.php', "<?php // Silence.\n" ); // phpcs:ignore
		}
		$code = '';
		$out  = '';
		$ver  = '';
		$bin  = $pre . '/node_modules/.bin/' . LocalCli::BINARIES[ $engine ];
		if ( ! $sel ) {
			$code = 'NODE_NOT_FOUND';
		} elseif ( ! $sel['npm'] ) {
			$code = 'NPM_NOT_FOUND';
		} else {
			$cache = $pre . '/.npm-cache';
			$env   = ProcessRunner::env( array( $sel['npm'][0], $sel['path'] ), array( 'HOME' => $pre, 'npm_config_cache' => $cache, 'npm_config_update_notifier' => 'false', 'npm_config_fund' => 'false', 'npm_config_audit' => 'false', 'npm_config_tmp' => $pre . '/.npm-tmp' ) );
			wp_mkdir_p( $pre . '/.npm-tmp' );
			$this->plugin->activity->step( (int) $aid, sprintf( /* translators: %s: package */ __( 'npm install %s …', 'seolity' ), $req['package'] ), 20 );
			$r   = ProcessRunner::run( array_merge( $sel['npm'], array( 'install', '--prefix', $pre, '--no-audit', '--no-fund', '--omit=dev', '--loglevel=error', $req['package'] . '@latest' ) ), '', $env, $pre, 1100 );
			$out = is_wp_error( $r ) ? $r->get_error_message() : trim( $r['err'] . "\n" . $r['out'] );
			// npm's download cache and temp files are not needed after the install.
			ProcessRunner::rrmdir( $cache );
			ProcessRunner::rrmdir( $pre . '/.npm-tmp' );
			if ( is_wp_error( $r ) ) {
				$code = (string) $r->get_error_data()['cli_code'];
			} elseif ( 0 !== $r['code'] ) {
				$code = self::npm_error( $r );
			}
		}
		delete_transient( 'seolity_cli_' . $engine );
		if ( '' === $code && is_file( $bin ) ) {
			$this->repair_permissions( $bin );
			$v = $this->plugin->localcli->version( $engine, true );
			if ( is_wp_error( $v ) ) {
				$code = (string) $v->get_error_data()['cli_code'];
			} else {
				$ver = $v;
			}
		} elseif ( '' === $code ) {
			$code = 'CLI_NOT_FOUND';
		}
		$ok = '' === $code;
		if ( $ok ) {
			$this->plugin->settings->update( array( 'sub_cli_' . $engine => $bin ) );
		}
		update_option( self::LOG . '_' . $engine, array( 'status' => $ok ? 'done' : 'failed', 'at' => gmdate( 'c' ), 'version' => $ver, 'code' => $code, 'message' => $ok ? '' : CliErrors::message( $code ), 'action' => $ok ? '' : CliErrors::action( $code, $engine ), 'out' => mb_substr( CliErrors::redact( $out ), -3000 ) ), false );
		/* translators: %s: version */
		$msg = $ok ? sprintf( __( 'Installed and verified: %s', 'seolity' ), $ver ) : CliErrors::message( $code );
		$this->plugin->activity->finish( (int) $aid, $ok ? 'done' : 'failed', $msg, array( 'summary' => $msg, 'route' => '#/engines' ) );
		$this->plugin->audit_log->log( 'cli.install', 'site', null, array( 'engine' => $engine, 'ok' => $ok, 'version' => $ver, 'code' => $code ) );
		return true;
	}

	/** Codex: the native program straight from the npm registry, without npm (see CodexInstaller). */
	private function install_codex( $aid ) {
		$log  = function ( $msg, $pct ) use ( $aid ) {
			update_option( self::LOG . '_codex', array( 'status' => 'running', 'at' => gmdate( 'c' ), 'stage' => $msg, 'pct' => $pct ), false );
			$this->plugin->activity->step( $aid, $msg, $pct );
		};
		$res  = ( new CodexInstaller( $this->plugin ) )->install( $log );
		delete_transient( 'seolity_cli_codex' );
		$ok   = ! is_wp_error( $res );
		$code = $ok ? '' : (string) $res->get_error_data()['cli_code'];
		if ( $ok ) {
			$this->plugin->settings->update( array( 'sub_cli_codex' => $res['bin'] ) );
		}
		update_option( self::LOG . '_codex', array( 'status' => $ok ? 'done' : 'failed', 'at' => gmdate( 'c' ), 'version' => $ok ? $res['version'] : '', 'code' => $code, 'message' => $ok ? '' : CliErrors::message( $code ), 'action' => $ok ? '' : CliErrors::action( $code, 'codex' ), 'out' => $ok ? '' : CliErrors::redact( $res->get_error_message() ) ), false );
		/* translators: %s: version */
		$msg = $ok ? sprintf( __( 'Installed and verified: %s', 'seolity' ), $res['version'] ) : CliErrors::message( $code );
		$this->plugin->activity->finish( $aid, $ok ? 'done' : 'failed', $msg, array( 'summary' => $msg, 'route' => '#/engines' ) );
		$this->plugin->audit_log->log( 'cli.install', 'site', null, array( 'engine' => 'codex', 'ok' => $ok, 'code' => $code, 'method' => 'native' ) );
		return true;
	}

	/** Install state; a "running" install older than the queue's own limit was killed by the host. */
	public function install_state( $engine ) {
		$log = get_option( self::LOG . '_' . $engine );
		if ( is_array( $log ) && 'running' === $log['status'] && strtotime( $log['at'] ) < time() - 25 * MINUTE_IN_SECONDS ) {
			$log = array( 'status' => 'failed', 'at' => gmdate( 'c' ), 'code' => 'PROCESS_KILLED', 'message' => CliErrors::message( 'PROCESS_KILLED' ), 'action' => CliErrors::action( 'PROCESS_KILLED', $engine ), 'out' => '' );
			update_option( self::LOG . '_' . $engine, $log, false );
		}
		return is_array( $log ) ? $log : null;
	}

	/** Cause of a failed `npm install`. */
	private static function npm_error( array $r ) {
		$t = $r['err'] . "\n" . $r['out'];
		if ( preg_match( '/E403|E401|ENOTFOUND|EAI_AGAIN|ECONNREFUSED|ECONNRESET|ETIMEDOUT|ERR_SOCKET|SELF_SIGNED|UNABLE_TO_GET_ISSUER|certificate|network|proxy/i', $t ) ) {
			return 'INSTALL_BLOCKED';
		}
		if ( preg_match( '/ENOSPC|EDQUOT|no space left|quota/i', $t ) ) {
			return 'DISK_FULL';
		}
		if ( preg_match( '/EACCES|EPERM|permission denied/i', $t ) ) {
			return 'CLI_PERMISSION_DENIED';
		}
		return CliErrors::classify( $r );
	}

	/** Make the installed CLI executable again (only files inside the private prefix). */
	public function repair_permissions( $bin ) {
		$real = (string) @realpath( $bin ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( '' !== $real && 0 === strpos( $real, (string) realpath( self::prefix() ) ) && ! is_executable( $real ) ) {
			@chmod( $real, 0755 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		return '' !== $real && is_executable( $real );
	}

	/* ------------------------------------------------------------------ full diagnostics */

	/** Size (bytes) from php.ini notation. */
	private static function bytes( $v ) {
		$v = trim( (string) $v );
		if ( '' === $v || '-1' === $v ) {
			return -1;
		}
		$n = (int) $v;
		switch ( strtolower( substr( $v, -1 ) ) ) {
			case 'g':
				$n *= 1024;
				// Fall through.
			case 'm':
				$n *= 1024;
				// Fall through.
			case 'k':
				$n *= 1024;
		}
		return $n;
	}

	/**
	 * Everything the AI setup wizard shows: environment checks, Node.js candidates and one status
	 * per engine (ready / not_installed / needs_config / auth_required / permission_denied /
	 * unsupported / runtime_error) with the cause, next step and buttons that apply.
	 *
	 * @param bool $fresh Re-scan Node.js and re-run `--version`.
	 */
	public function doctor( $fresh = false ) {
		if ( $fresh ) {
			delete_transient( NodeLocator::CACHE );
			delete_transient( 'seolity_cli_claude' );
			delete_transient( 'seolity_cli_codex' );
		}
		$blocked  = ProcessRunner::blocked();
		$disabled = array_filter( array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) ) );
		$env      = array();
		$add      = static function ( $id, $state, $label, $value, $fix = '' ) use ( &$env ) {
			$env[] = array( 'id' => $id, 'state' => $state, 'label' => $label, 'value' => $value, 'fix' => 'ok' === $state ? '' : $fix );
		};
		$add( 'php', version_compare( PHP_VERSION, '7.4', '>=' ) ? 'ok' : 'bad', __( 'PHP version', 'seolity' ), PHP_VERSION . ' (' . PHP_SAPI . ')', __( 'PHP 7.4 or newer is required.', 'seolity' ) );
		$mem = self::bytes( ini_get( 'memory_limit' ) );
		$add( 'memory_limit', -1 === $mem || $mem >= 128 * 1048576 ? 'ok' : 'warn', __( 'PHP memory limit', 'seolity' ), (string) ini_get( 'memory_limit' ), __( 'Raise memory_limit to 128M or more in cPanel → MultiPHP INI Editor.', 'seolity' ) );
		$met = (int) ini_get( 'max_execution_time' );
		$add( 'max_execution_time', 0 === $met || $met >= 120 || function_exists( 'set_time_limit' ) ? 'ok' : 'warn', __( 'PHP time limit', 'seolity' ), 0 === $met ? __( 'Unlimited', 'seolity' ) : $met . ' s' . ( function_exists( 'set_time_limit' ) ? ' (' . __( 'can be raised per request', 'seolity' ) . ')' : '' ), __( 'Long AI answers need more than 120 seconds; ask the host to allow set_time_limit.', 'seolity' ) );
		$add( 'proc_open', '' === $blocked ? 'ok' : 'bad', __( 'PHP may start programs', 'seolity' ), '' === $blocked ? 'proc_open ✓' : sprintf( /* translators: %s: function */ __( '%s is disabled', 'seolity' ), $blocked ), CliErrors::action( 'PROC_OPEN_DISABLED' ) );
		$others = array_values( array_intersect( array( 'exec', 'shell_exec', 'system', 'passthru', 'popen' ), $disabled ) );
		$add( 'shell', 'ok', __( 'Other shell functions', 'seolity' ), $others ? sprintf( /* translators: %s: functions */ __( 'Disabled: %s (not needed — SEOlity never uses a shell)', 'seolity' ), implode( ', ', $others ) ) : __( 'Not needed — SEOlity never uses a shell', 'seolity' ) );
		$obd = (string) ini_get( 'open_basedir' );
		$add( 'open_basedir', '' === $obd ? 'ok' : 'warn', 'open_basedir', '' === $obd ? __( 'Not set', 'seolity' ) : __( 'Set — some folders may be invisible to PHP', 'seolity' ), __( 'If Node.js is not found, ask the host to add /opt/alt to open_basedir or turn it off for your account.', 'seolity' ) );
		$os = PHP_OS_FAMILY . ' ' . php_uname( 'm' ) . ( self::libc() ? ' (' . self::libc() . ')' : '' );
		$add( 'os', in_array( PHP_OS_FAMILY, array( 'Linux', 'Darwin' ), true ) && in_array( php_uname( 'm' ), array( 'x86_64', 'amd64', 'aarch64', 'arm64' ), true ) ? 'ok' : 'bad', __( 'Operating system', 'seolity' ), $os . ( @is_dir( '/opt/alt' ) || @is_file( '/proc/lve/list' ) ? ' · CloudLinux' : '' ), __( 'The official CLIs ship for Linux and macOS on x64/arm64 only.', 'seolity' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$tmp = get_temp_dir();
		$add( 'tmp', wp_is_writable( $tmp ) ? 'ok' : 'bad', __( 'Temporary folder is writable', 'seolity' ), $tmp, __( 'Set WP_TEMP_DIR in wp-config.php to a writable folder.', 'seolity' ) );
		$pre    = self::prefix();
		$parent = is_dir( $pre ) ? $pre : dirname( $pre );
		$add( 'prefix', is_writable( $parent ) ? 'ok' : 'bad', __( 'Private install folder', 'seolity' ), $pre . ( self::inside_webroot( $pre ) ? ' — ' . __( 'inside the web root, protected with deny rules', 'seolity' ) : ' — ' . __( 'outside the web root', 'seolity' ) ), __( 'Make the folder writable for the PHP user.', 'seolity' ) );
		$free = @disk_free_space( $parent ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$add( 'disk', false === $free || $free >= 1000 * 1048576 ? 'ok' : 'warn', __( 'Free disk space', 'seolity' ), false === $free ? __( 'Unknown (the host does not report it)', 'seolity' ) : (int) round( $free / 1048576 ) . ' MB', __( 'An install needs about 1 GB free (package + npm cache).', 'seolity' ) );
		$vm = ProcessRunner::vmem_mb();
		$lim = ProcessRunner::limits();
		/* translators: 1: soft MB 2: hard MB */
		$add( 'vmem', null === $vm || -1 === $vm || $vm >= 2048 ? 'ok' : ( $vm >= 1024 ? 'warn' : 'bad' ), __( 'Virtual memory per program (inherited from PHP)', 'seolity' ), null === $vm ? __( 'Unknown', 'seolity' ) : ( -1 === $vm ? __( 'Unlimited', 'seolity' ) : $vm . ' MB' . ( null !== $lim['as_hard'] && $lim['as_hard'] !== $lim['as_soft'] ? ' (' . __( 'raised to the hard limit for each run', 'seolity' ) . ')' : '' ) ), __( 'Codex needs about 1 GB and npm installs need about 2 GB of virtual memory per program. Ask the host to raise the LiteSpeed lsphp “Memory Hard Limit” (or the CloudLinux VMEM limit) for your account.', 'seolity' ) );
		$nodes = '' === $blocked ? NodeLocator::scan( $fresh ) : array();
		$sel   = '' === $blocked ? NodeLocator::select( 16 ) : null;
		$npmv  = '';
		if ( $sel && $sel['npm'] ) {
			$r    = ProcessRunner::run( array_merge( $sel['npm'], array( '--version' ) ), '', ProcessRunner::env( array( $sel['npm'][0], $sel['path'] ) ), get_temp_dir(), 30 );
			$npmv = is_wp_error( $r ) ? '' : trim( $r['out'] );
		}
		$add( 'node', $sel ? 'ok' : 'bad', 'Node.js', $sel ? $sel['version'] . ' — ' . $sel['path'] : __( 'Not found', 'seolity' ), CliErrors::action( 'NODE_NOT_FOUND' ) );
		$add( 'npm', $sel && $sel['npm'] ? ( '' !== $npmv ? 'ok' : 'warn' ) : 'bad', 'npm', $sel && $sel['npm'] ? ( $npmv ? $npmv : '?' ) . ' — ' . implode( ' ', $sel['npm'] ) : __( 'Not found', 'seolity' ), CliErrors::action( 'NPM_NOT_FOUND' ) );
		$engines = array();
		foreach ( array_keys( self::PACKAGES ) as $e ) {
			$engines[ $e ] = $this->engine_status( $e, $blocked, $fresh );
		}
		return array(
			'environment' => $env,
			'nodes'       => array_map(
				static function ( $n ) use ( $sel ) {
					return array( 'path' => $n['path'], 'version' => $n['version'], 'ok' => $n['ok'], 'error' => $n['error'], 'npm' => (bool) $n['npm'], 'selected' => $sel && $sel['path'] === $n['path'] );
				},
				$nodes
			),
			'node'        => $sel ? array( 'path' => $sel['path'], 'version' => $sel['version'], 'source' => $sel['source'], 'npm' => $sel['npm'] ? implode( ' ', $sel['npm'] ) : '', 'npm_version' => $npmv ) : null,
			'engines'     => $engines,
			'prefix'      => $pre,
			'at'          => gmdate( 'c' ),
		);
	}

	/** One engine: what is there, what is missing, and the next step. */
	public function engine_status( $engine, $blocked = null, $fresh = false ) {
		$blocked = null === $blocked ? ProcessRunner::blocked() : $blocked;
		$req     = self::PACKAGES[ $engine ];
		$cli     = $this->plugin->localcli;
		$auth    = $this->plugin->cliauth;
		$st      = $auth->state( $engine );
		$out     = array(
			'engine'     => $engine,
			'package'    => $req['package'],
			'path'       => '',
			'version'    => '',
			'node'       => '',
			'auth_mode'  => $auth->mode( $engine ),
			'logged_in'  => $auth->has_login( $engine ),
			'last_ok'    => $st['last_ok'],
			'last_state' => $st['state'],
			'last_code'  => $st['code'],
			'last_at'    => $st['at'],
			'install'    => $this->install_state( $engine ),
			'model'      => (string) $this->plugin->settings->get( 'ai_model_sub_' . $engine ),
			'login'      => 'codex' === $engine ? $auth->login_state( 'codex' ) : null,
			'device'     => false,
			'status'     => 'ready',
			'code'       => '',
			'message'    => '',
			'action'     => '',
		);
		$set = function ( $code ) use ( &$out, $engine ) {
			$out['code']    = $code;
			$out['status']  = CliErrors::group( $code );
			$out['message'] = CliErrors::message( $code );
			$out['action']  = CliErrors::action( $code, $engine );
			return $out;
		};
		if ( '' !== $blocked ) {
			return $set( 'PROC_OPEN_DISABLED' );
		}
		$bin         = $cli->binary( $engine );
		$out['path'] = $bin;
		$out['native'] = '' !== $bin && ! LocalCli::needs_node( $bin );
		$node        = '' === $bin || ! $out['native'] ? $cli->node_for( $engine ) : '';
		$out['node'] = $node;
		if ( 'running' === ( is_array( $out['install'] ) ? $out['install']['status'] : '' ) ) {
			// Never start the CLI while it is being installed (half-written files, double memory).
			$out['status']  = 'needs_config';
			$out['code']    = 'INSTALLING';
			$out['message'] = isset( $out['install']['stage'] ) ? $out['install']['stage'] : __( 'Installing…', 'seolity' );
			return $out;
		}
		if ( '' === $bin && 'codex' !== $engine && '' === $node ) {
			return $set( NodeLocator::select( 1 ) ? 'NODE_TOO_OLD' : 'NODE_NOT_FOUND' );
		}
		if ( '' === $bin ) {
			return $set( 'CLI_NOT_FOUND' );
		}
		if ( ! $out['native'] && '' === $node ) {
			return $set( NodeLocator::select( 1 ) ? 'NODE_TOO_OLD' : 'NODE_NOT_FOUND' );
		}
		if ( ! @is_executable( $bin ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return $set( 'CLI_PERMISSION_DENIED' );
		}
		$v = $cli->version( $engine, $fresh );
		if ( is_wp_error( $v ) ) {
			return $set( (string) $v->get_error_data()['cli_code'] );
		}
		$out['version'] = $v;
		$out['device']  = 'codex' === $engine && $auth->device_supported();
		if ( ! $out['logged_in'] ) {
			return $set( 'AUTH_REQUIRED' );
		}
		if ( 'auth' === $st['state'] && $st['fp'] === $auth->fingerprint( $engine ) ) {
			return $set( $st['code'] );
		}
		if ( 'ok' !== $st['state'] || $st['fp'] !== $auth->fingerprint( $engine ) ) {
			$out['status']  = 'needs_config';
			$out['code']    = 'NOT_TESTED';
			$out['message'] = __( 'Installed and signed in — run “Test connection” once to confirm it answers.', 'seolity' );
			$out['action']  = '';
		}
		return $out;
	}

	/** Plain-text report an administrator can send to support: no tokens, no account names. */
	public function report() {
		$d     = $this->doctor();
		$lines = array( 'SEOlity ' . SEOLITY_VERSION . ' — AI engines diagnostic report — ' . $d['at'], '' );
		foreach ( $d['environment'] as $c ) {
			$lines[] = sprintf( '[%s] %s: %s', strtoupper( $c['state'] ), $c['label'], $c['value'] );
		}
		$lines[] = '';
		$lines[] = 'Node.js candidates:';
		foreach ( $d['nodes'] as $n ) {
			$lines[] = sprintf( ' %s %s %s%s', $n['selected'] ? '*' : '-', $n['path'], $n['ok'] ? $n['version'] : 'ERROR ' . $n['error'], $n['npm'] ? ' +npm' : '' );
		}
		foreach ( $d['engines'] as $e ) {
			$lines[] = '';
			$lines[] = strtoupper( $e['engine'] ) . ': ' . $e['status'] . ( $e['code'] ? ' (' . $e['code'] . ')' : '' );
			$lines[] = ' cli: ' . ( $e['path'] ? $e['path'] : '-' ) . ' ' . $e['version'];
			$lines[] = ' node: ' . ( $e['node'] ? $e['node'] : '-' );
			$lines[] = ' login: ' . ( $e['logged_in'] ? 'saved' : 'none' ) . ', mode ' . $e['auth_mode'] . ', last ok ' . ( $e['last_ok'] ? $e['last_ok'] : '-' ) . ', last result ' . $e['last_state'] . ( $e['last_code'] ? ' ' . $e['last_code'] : '' );
			if ( is_array( $e['install'] ) ) {
				$lines[] = ' install: ' . $e['install']['status'] . ' ' . ( isset( $e['install']['code'] ) ? $e['install']['code'] : '' ) . ' ' . $e['install']['at'];
			}
		}
		$text = implode( "\n", $lines );
		// Hide the hosting account name and anything credential-like.
		$text = CliErrors::redact( $text );
		$user = function_exists( 'posix_getpwuid' ) && function_exists( 'posix_geteuid' ) ? @posix_getpwuid( posix_geteuid() ) : null; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( is_array( $user ) && ! empty( $user['name'] ) && strlen( $user['name'] ) > 2 ) {
			$text = preg_replace( '#(?<=/)' . preg_quote( $user['name'], '#' ) . '(?=/)#', '<user>', $text );
		}
		return preg_replace( '#/home\d*/[^/\s]+#', '/home/<user>', $text );
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
