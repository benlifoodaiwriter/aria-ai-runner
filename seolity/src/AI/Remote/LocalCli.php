<?php
/**
 * Subscription AI on THIS server: runs the official Claude Code / OpenAI Codex CLI directly from
 * WordPress when the host allows it (VPS, or shared cPanel/CloudLinux hosting with Node.js).
 *
 * Programs are started only through ProcessRunner (explicit PATH with the selected Node.js, no
 * shell), logins come from CliAuth, failures are classified by CliErrors.
 *
 * This is how "Direct" works for Claude Pro/Max and ChatGPT Plus/Pro accounts: the request never
 * goes through GitHub. Shared hosting usually cannot run programs — then SEOlity says so instead of
 * silently using GitHub.
 *
 * @package SEOlity
 */

namespace SEOlity\AI\Remote;

use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class LocalCli {

	const BINARIES = array( 'claude' => 'claude', 'codex' => 'codex' );

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/** Can this PHP start programs at all? */
	public static function can_exec() {
		return ProcessRunner::can_exec();
	}

	/** Folders where an installed CLI may live (install prefix first). */
	private function cli_dirs() {
		$home = ProcessRunner::user_home();
		$dirs = array( CliSetup::prefix() . '/node_modules/.bin' );
		if ( $home ) {
			$dirs = array_merge( $dirs, array( $home . '/seolity-cli/node_modules/.bin', $home . '/.npm-global/bin', $home . '/.local/bin', $home . '/bin', $home . '/node_modules/.bin' ) );
		}
		return array_merge( $dirs, NodeLocator::dirs(), explode( PATH_SEPARATOR, (string) getenv( 'PATH' ) ), array( '/usr/local/bin', '/usr/bin', '/opt/homebrew/bin' ) );
	}

	/** Absolute path of the CLI (setting, or found in the usual folders), or ''. */
	public function binary( $engine ) {
		if ( ! isset( self::BINARIES[ $engine ] ) ) {
			return '';
		}
		$set = trim( (string) $this->plugin->settings->get( 'sub_cli_' . $engine ) );
		if ( '' !== $set ) {
			// Only the official CLIs may be started from here, never an arbitrary program.
			$name = strtolower( basename( $set ) );
			$ok   = 0 === strpos( $name, self::BINARIES[ $engine ] ) && '/' === substr( $set, 0, 1 );
			if ( $ok && @is_file( $set ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				return 'codex' === $engine && '' === CodexInstaller::installed() ? self::native_of( $set ) : ( 'codex' === $engine ? CodexInstaller::installed() : $set );
			}
		}
		if ( 'codex' === $engine ) {
			// The native binary needs no Node.js and far less memory than the npm launcher.
			$native = CodexInstaller::installed();
			if ( '' !== $native ) {
				return $native;
			}
		}
		$cached = get_transient( 'seolity_cli_' . $engine );
		if ( false !== $cached && ( '' === $cached || @is_file( $cached ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return (string) $cached;
		}
		$found = '';
		foreach ( array_filter( array_unique( $this->cli_dirs() ) ) as $dir ) {
			$f = rtrim( $dir, '/' ) . '/' . self::BINARIES[ $engine ];
			if ( @is_file( $f ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				$found = $f;
				break;
			}
		}
		$found = 'codex' === $engine && '' !== $found ? self::native_of( $found ) : $found;
		set_transient( 'seolity_cli_' . $engine, $found, HOUR_IN_SECONDS );
		return $found;
	}

	/** For an npm-installed Codex launcher, the native binary it would execve (same files, no Node.js). */
	public static function native_of( $bin ) {
		$t    = CodexInstaller::target();
		$real = (string) @realpath( $bin ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $t || '' === $real || '.js' !== substr( $real, -3 ) ) {
			return $bin;
		}
		$mods = dirname( dirname( dirname( $real ) ) ); // …/node_modules/@openai
		foreach ( array( $mods . '/codex-' . $t[0] . '/vendor/' . $t[1] . '/bin/codex', $mods . '/codex-' . $t[0] . '/vendor/' . $t[1] . '/codex/codex', dirname( dirname( $real ) ) . '/vendor/' . $t[1] . '/codex/codex' ) as $f ) {
			if ( @is_file( $f ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				return $f;
			}
		}
		return $bin;
	}

	/** Does this CLI file need Node.js (a `#!…node` script) or is it a native program? */
	public static function needs_node( $bin ) {
		$fh = '' !== $bin ? @fopen( $bin, 'rb' ) : false; // phpcs:ignore
		if ( ! $fh ) {
			return true;
		}
		$head = (string) fread( $fh, 64 ); // phpcs:ignore
		fclose( $fh ); // phpcs:ignore
		return 0 === strpos( $head, '#!' );
	}

	/** Executables whose folders a run needs on PATH (the CLI, its Node.js, bundled tools). */
	public function path_bins( $engine, $bin ) {
		$bins = array( $bin );
		if ( self::needs_node( $bin ) ) {
			$bins[] = $this->node_for( $engine );
		}
		if ( 'codex' === $engine ) {
			$bins[] = CodexInstaller::tool_path( $bin );
		}
		return array_filter( $bins );
	}

	public function available( $engine ) {
		$bin = $this->binary( $engine );
		return self::can_exec() && '' !== $bin && @is_executable( $bin ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/** Node.js for an engine (null = none new enough). */
	public function node_for( $engine ) {
		$n = NodeLocator::select( isset( CliSetup::PACKAGES[ $engine ] ) ? CliSetup::PACKAGES[ $engine ]['node'] : 18 );
		return $n ? $n['path'] : '';
	}

	/** `<cli> --version`, cached per file; '' when it does not start. */
	public function version( $engine, $fresh = false ) {
		$bin = $this->binary( $engine );
		if ( '' === $bin ) {
			return CliErrors::error( 'CLI_NOT_FOUND', '', array(), $engine );
		}
		$key = 'seolity_cliv_' . substr( md5( $bin . '|' . (string) @filemtime( $bin ) ), 0, 12 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$got = $fresh ? false : get_transient( $key );
		if ( false !== $got ) {
			return $got;
		}
		if ( ! $fresh ) {
			// A failed check is remembered for 10 minutes: pages that poll must never start the CLI again and again.
			$bad = get_transient( $key . '_e' );
			if ( is_array( $bad ) ) {
				return CliErrors::error( $bad[0], $bad[1], array( 'cached' => true ), $engine );
			}
		}
		$need = self::needs_node( $bin );
		$node = $need ? $this->node_for( $engine ) : '';
		if ( $need && '' === $node ) {
			return CliErrors::error( NodeLocator::select( 1 ) ? 'NODE_TOO_OLD' : 'NODE_NOT_FOUND', '', array(), $engine );
		}
		$r = ProcessRunner::run( array( $bin, '--version' ), '', ProcessRunner::env( $this->path_bins( $engine, $bin ) ), get_temp_dir(), 60 );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( 0 !== $r['code'] || '' === trim( $r['out'] ) ) {
			$code = CliErrors::classify( $r );
			set_transient( $key . '_e', array( $code, CliErrors::redact( mb_substr( $r['err'], -300 ) ) ), 10 * MINUTE_IN_SECONDS );
			return CliErrors::error( $code, $r['err'], array( 'vmem_mb' => ProcessRunner::vmem_mb() ), $engine );
		}
		delete_transient( $key . '_e' );
		$v = trim( strtok( trim( $r['out'] ), "\n" ) );
		set_transient( $key, $v, DAY_IN_SECONDS );
		return $v;
	}

	/** Status for the "Server & speed" screen. */
	public function status() {
		return array(
			'can_exec' => self::can_exec(),
			'claude'   => $this->binary( 'claude' ),
			'codex'    => $this->binary( 'codex' ),
		);
	}

	/** Per-run scratch folder (0700, deleted securely afterwards). */
	private function workdir() {
		return ProcessRunner::private_dir( 'runs' );
	}

	/** Remove run folders whose PHP process was killed before its own cleanup. */
	private function sweep() {
		$dir = ProcessRunner::private_dir( 'runs' );
		// Runs whose PHP process was killed (host limit, timeout) never reached their own cleanup.
		foreach ( (array) glob( $dir . '/run-*', GLOB_ONLYDIR ) as $old ) {
			if ( (int) @filemtime( $old ) < time() - 30 * MINUTE_IN_SECONDS ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				ProcessRunner::rrmdir( $old, true );
			}
		}
	}

	/**
	 * Run one request.
	 *
	 * @param string $credential Kept for compatibility; the login now comes from CliAuth.
	 * @param bool   $force      Run even when the saved login is known to be dead (connection test).
	 * @return array|\WP_Error Provider result ({text, usage, model, stop_reason}).
	 */
	public function complete( $engine, $model, $system, $prompt, $credential = '', $force = false ) {
		unset( $credential );
		if ( ! self::can_exec() ) {
			return CliErrors::error( 'PROC_OPEN_DISABLED', '', array( 'function' => ProcessRunner::blocked() ), $engine );
		}
		$this->sweep();
		$auth = $this->plugin->cliauth;
		$bin  = $this->binary( $engine );
		if ( '' === $bin ) {
			return CliErrors::error( 'CLI_NOT_FOUND', '', array(), $engine );
		}
		if ( ! $force ) {
			$dead = $auth->blocked( $engine );
			if ( $dead ) {
				return $dead;
			}
		} elseif ( ! $auth->has_login( $engine ) ) {
			return CliErrors::error( 'AUTH_REQUIRED', '', array(), $engine );
		}
		if ( self::needs_node( $bin ) && '' === $this->node_for( $engine ) ) {
			return CliErrors::error( NodeLocator::select( 1 ) ? 'NODE_TOO_OLD' : 'NODE_NOT_FOUND', '', array(), $engine );
		}
		$bins = $this->path_bins( $engine, $bin );
		$tmp = $this->workdir() . '/run-' . wp_generate_password( 12, false, false );
		wp_mkdir_p( $tmp );
		@chmod( $tmp, 0700 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$lock = null;
		try {
			if ( 'claude' === $engine ) {
				$sys = $tmp . '/system.txt';
				ProcessRunner::write_secret( $sys, $system ? $system : 'You are a helpful assistant.' );
				$extra = array( 'HOME' => $auth->claude_home() );
				if ( 'plugin' === $auth->mode( 'claude' ) ) {
					$v = $this->plugin->settings->secret( 'claude_oauth_token' );
					$extra['CLAUDE_CODE_OAUTH_TOKEN'] = is_string( $v ) ? $v : '';
				}
				$env = ProcessRunner::env( $bins, array_merge( $extra, \SEOlity\AI\Effort::cli( 'claude' )['env'] ) );
				$cmd = array( $bin, '-p', '--output-format', 'json', '--model', $model ? $model : 'sonnet', '--tools', '', '--max-turns', '2', '--no-session-persistence', '--system-prompt-file', $sys );
				$r   = ProcessRunner::run( $cmd, $prompt, $env, $tmp, $this->timeout() );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
				$lines = array_values( array_filter( explode( "\n", trim( $r['out'] ) ) ) );
				$data  = $lines ? json_decode( end( $lines ), true ) : null;
				if ( ! is_array( $data ) || ! empty( $data['is_error'] ) || ( isset( $data['subtype'] ) && 'success' !== $data['subtype'] ) ) {
					$why = is_array( $data ) ? (string) ( isset( $data['result'] ) ? $data['result'] : $data['subtype'] ) : '';
					return $this->fail( $engine, array_merge( $r, array( 'err' => $r['err'] . "\n" . $why ) ) );
				}
				$auth->record( 'claude' );
				$u = isset( $data['usage'] ) ? (array) $data['usage'] : array();
				return array(
					'text'        => (string) $data['result'],
					'usage'       => array( 'input' => (int) ( isset( $u['input_tokens'] ) ? $u['input_tokens'] : 0 ), 'output' => (int) ( isset( $u['output_tokens'] ) ? $u['output_tokens'] : 0 ) ),
					'model'       => $model ? $model : 'sonnet',
					'stop_reason' => 'end_turn',
				);
			}
			// Codex: one login folder shared by all runs, so refreshed tokens are never lost.
			$lock = $auth->lock( 'codex' );
			if ( false === $lock ) {
				return CliErrors::error( 'CLI_BUSY', '', array(), 'codex' );
			}
			$home = $auth->codex_before();
			if ( is_wp_error( $home ) ) {
				return $home;
			}
			$out = $tmp . '/out.txt';
			$env = ProcessRunner::env( $bins, array( 'CODEX_HOME' => $home ) );
			$cmd = array_merge( array( $bin, 'exec', '--skip-git-repo-check', '--sandbox', 'read-only', '--ephemeral', '-o', $out ), \SEOlity\AI\Effort::cli( 'codex' )['args'] );
			if ( $model ) {
				$cmd[] = '-m';
				$cmd[] = $model;
			}
			$cmd[] = '-';
			$input = ( $system ? "# Instructions\n" . $system . "\n\n# Task\n" : '' ) . $prompt . "\n\nReturn only the requested output. Do not run commands or edit files.";
			$r     = ProcessRunner::run( $cmd, $input, $env, $tmp, $this->timeout() );
			$auth->codex_after( $home );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$text = is_file( $out ) ? trim( (string) file_get_contents( $out ) ) : ''; // phpcs:ignore
			if ( '' === $text || 0 !== $r['code'] ) {
				return $this->fail( $engine, $r );
			}
			$auth->record( 'codex' );
			return array( 'text' => $text, 'usage' => array( 'input' => 0, 'output' => 0 ), 'model' => $model ? $model : 'codex', 'stop_reason' => 'end_turn' );
		} finally {
			$auth->unlock( $lock );
			ProcessRunner::rrmdir( $tmp, true );
		}
	}

	/**
	 * Models offered for an engine: Claude aliases; for Codex the catalog bundled in the installed
	 * binary (`codex debug models --bundled`, offline — no login, no tokens).
	 */
	public function models( $engine ) {
		if ( 'claude' === $engine ) {
			return array(
				array( 'id' => 'sonnet', 'label' => 'Sonnet' ),
				array( 'id' => 'opus', 'label' => 'Opus' ),
				array( 'id' => 'haiku', 'label' => 'Haiku' ),
			);
		}
		$bin  = $this->binary( 'codex' );
		$list = array();
		if ( '' !== $bin && self::can_exec() ) {
			$key  = 'seolity_codexm_' . substr( md5( $bin . '|' . (string) @filemtime( $bin ) ), 0, 12 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$list = get_transient( $key );
			if ( ! is_array( $list ) ) {
				$list = array();
				$home = ProcessRunner::private_dir( 'runs' );
				$r    = ProcessRunner::run( array( $bin, 'debug', 'models', '--bundled' ), '', ProcessRunner::env( $this->path_bins( 'codex', $bin ), array( 'CODEX_HOME' => $home ) ), $home, 60 );
				$j    = ! is_wp_error( $r ) && 0 === $r['code'] ? json_decode( $r['out'], true ) : null;
				foreach ( is_array( $j ) && isset( $j['models'] ) ? (array) $j['models'] : array() as $m ) {
					if ( ! empty( $m['slug'] ) && ( ! isset( $m['visibility'] ) || 'list' === $m['visibility'] ) ) {
						$list[] = array( 'id' => (string) $m['slug'], 'label' => (string) ( isset( $m['display_name'] ) ? $m['display_name'] : $m['slug'] ), 'p' => isset( $m['priority'] ) ? (int) $m['priority'] : 99 );
					}
				}
				usort(
					$list,
					static function ( $a, $b ) {
						return $a['p'] <=> $b['p'];
					}
				);
				set_transient( $key, $list, $list ? DAY_IN_SECONDS : 10 * MINUTE_IN_SECONDS );
			}
		}
		return array_merge( array( array( 'id' => '', 'label' => __( 'Default model of your ChatGPT plan', 'seolity' ) ) ), $list );
	}

	private function timeout() {
		return max( 60, (int) $this->plugin->settings->get( 'ai_timeout' ) * 3 );
	}

	/** Classify a failed run, remember auth failures, return a clear error. */
	private function fail( $engine, array $r ) {
		$code = CliErrors::classify( $r );
		$this->plugin->cliauth->record( $engine, $code );
		$detail = trim( '' !== trim( $r['err'] ) ? $r['err'] : $r['out'] );
		$extra  = array( 'exit' => $r['code'], 'vmem_mb' => ProcessRunner::vmem_mb() );
		if ( 'MEMORY_LIMIT' === $code && $extra['vmem_mb'] > 0 ) {
			/* translators: %d: MB */
			$detail = sprintf( __( 'PHP passes a virtual-memory limit of %d MB to every program; Codex needs about 1 GB.', 'seolity' ), $extra['vmem_mb'] ) . ' ' . $detail;
		}
		return CliErrors::error( $code, mb_substr( $detail, -400 ), $extra, $engine );
	}

	/**
	 * Run a fixed argument list (never through a shell). Kept for compatibility — new code calls
	 * ProcessRunner directly.
	 */
	public function exec( array $cmd, array $env, $cwd, $timeout ) {
		$bins = array( $cmd[0] );
		return ProcessRunner::run( $cmd, '', ProcessRunner::env( $bins, $env ), $cwd, $timeout );
	}
}
