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
				return $set;
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
		set_transient( 'seolity_cli_' . $engine, $found, HOUR_IN_SECONDS );
		return $found;
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
		$node = $this->node_for( $engine );
		$r    = ProcessRunner::run( array( $bin, '--version' ), '', ProcessRunner::env( array( $bin, $node ) ), get_temp_dir(), 60 );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( 0 !== $r['code'] || '' === trim( $r['out'] ) ) {
			$code = CliErrors::classify( $r );
			return CliErrors::error( '' === $node && 'NODE_NOT_FOUND' !== $code ? 'NODE_NOT_FOUND' : $code, $r['err'], array(), $engine );
		}
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
		$node = $this->node_for( $engine );
		if ( '' === $node ) {
			$any = NodeLocator::select( 1 );
			return CliErrors::error( $any ? 'NODE_TOO_OLD' : 'NODE_NOT_FOUND', '', array(), $engine );
		}
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
				$env = ProcessRunner::env( array( $bin, $node ), array_merge( $extra, \SEOlity\AI\Effort::cli( 'claude' )['env'] ) );
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
			$home = $auth->codex_before();
			if ( is_wp_error( $home ) ) {
				return $home;
			}
			$out = $tmp . '/out.txt';
			$env = ProcessRunner::env( array( $bin, $node ), array( 'CODEX_HOME' => $home ) );
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

	private function timeout() {
		return max( 60, (int) $this->plugin->settings->get( 'ai_timeout' ) * 3 );
	}

	/** Classify a failed run, remember auth failures, return a clear error. */
	private function fail( $engine, array $r ) {
		$code = CliErrors::classify( $r );
		$this->plugin->cliauth->record( $engine, $code );
		$detail = trim( '' !== trim( $r['err'] ) ? $r['err'] : $r['out'] );
		return CliErrors::error( $code, mb_substr( $detail, -400 ), array( 'exit' => $r['code'] ), $engine );
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
