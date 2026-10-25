<?php
/**
 * Subscription AI on THIS server: runs the official Claude Code / OpenAI Codex CLI directly from
 * WordPress when the host allows it (a VPS or dedicated server with Node.js and the CLI installed).
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
		if ( ! function_exists( 'proc_open' ) ) {
			return false;
		}
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		return ! in_array( 'proc_open', $disabled, true );
	}

	/** Absolute path of the CLI (setting, or found on the PATH), or ''. */
	public function binary( $engine ) {
		if ( ! isset( self::BINARIES[ $engine ] ) ) {
			return '';
		}
		$set = trim( (string) $this->plugin->settings->get( 'sub_cli_' . $engine ) );
		if ( '' !== $set ) {
			// Only the official CLIs may be started from here, never an arbitrary program.
			$name = strtolower( basename( $set ) );
			$ok   = 0 === strpos( $name, self::BINARIES[ $engine ] );
			return $ok && is_file( $set ) && is_executable( $set ) ? $set : '';
		}
		$cached = get_transient( 'seolity_cli_' . $engine );
		if ( false !== $cached ) {
			return (string) $cached;
		}
		$found = '';
		if ( self::can_exec() && isset( self::BINARIES[ $engine ] ) ) {
			$dirs = array_merge( array( CliSetup::prefix() . '/node_modules/.bin' ), explode( PATH_SEPARATOR, (string) getenv( 'PATH' ) ), array( '/usr/local/bin', '/usr/bin', '/opt/homebrew/bin', getenv( 'HOME' ) ? getenv( 'HOME' ) . '/.npm-global/bin' : '' ) );
			foreach ( array_filter( array_unique( $dirs ) ) as $dir ) {
				$f = rtrim( $dir, '/' ) . '/' . self::BINARIES[ $engine ];
				if ( @is_file( $f ) && @is_executable( $f ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
					$found = $f;
					break;
				}
			}
		}
		set_transient( 'seolity_cli_' . $engine, $found, HOUR_IN_SECONDS );
		return $found;
	}

	public function available( $engine ) {
		return self::can_exec() && '' !== $this->binary( $engine );
	}

	/** Status for the "Server & speed" screen. */
	public function status() {
		return array(
			'can_exec' => self::can_exec(),
			'claude'   => $this->binary( 'claude' ),
			'codex'    => $this->binary( 'codex' ),
		);
	}

	private function workdir() {
		$up  = wp_upload_dir();
		$dir = trailingslashit( $up['basedir'] ) . 'seolity-cli';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			@file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore
			@file_put_contents( $dir . '/index.php', "<?php // Silence.\n" ); // phpcs:ignore
		}
		return $dir;
	}

	/**
	 * Run one request.
	 *
	 * @return array|\WP_Error Provider result ({text, usage, model, stop_reason}).
	 */
	public function complete( $engine, $model, $system, $prompt, $credential ) {
		$bin = $this->binary( $engine );
		if ( '' === $bin ) {
			return new \WP_Error( 'seolity_local_cli', __( 'The official CLI is not installed on this server.', 'seolity' ) );
		}
		$dir  = $this->workdir();
		$tmp  = $dir . '/run-' . wp_generate_password( 12, false, false );
		wp_mkdir_p( $tmp );
		$path = implode( PATH_SEPARATOR, array_filter( array( dirname( $bin ), getenv( 'PATH' ), '/usr/local/bin', '/usr/bin', '/bin' ) ) );
		$env  = array( 'PATH' => $path, 'HOME' => $dir, 'DISABLE_AUTOUPDATER' => '1', 'LANG' => 'C.UTF-8' );
		try {
			if ( 'claude' === $engine ) {
				$sys = $tmp . '/system.txt';
				file_put_contents( $sys, $system ? $system : 'You are a helpful assistant.' ); // phpcs:ignore
				$env['CLAUDE_CODE_OAUTH_TOKEN'] = $credential;
				$cmd = array( $bin, '-p', '--output-format', 'json', '--model', $model ? $model : 'sonnet', '--tools', '', '--max-turns', '2', '--no-session-persistence', '--system-prompt-file', $sys );
				$r   = $this->run( $cmd, $prompt, $env, $tmp );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
				$lines = array_values( array_filter( explode( "\n", trim( $r['out'] ) ) ) );
				$data  = $lines ? json_decode( end( $lines ), true ) : null;
				if ( ! is_array( $data ) ) {
					return new \WP_Error( 'seolity_local_cli', 'Claude Code: ' . mb_substr( $r['err'] ? $r['err'] : $r['out'], 0, 400 ) );
				}
				if ( ! empty( $data['is_error'] ) || ( isset( $data['subtype'] ) && 'success' !== $data['subtype'] ) ) {
					return new \WP_Error( 'seolity_local_cli', 'Claude Code: ' . mb_substr( (string) ( isset( $data['result'] ) ? $data['result'] : $data['subtype'] ), 0, 400 ) );
				}
				$u = isset( $data['usage'] ) ? (array) $data['usage'] : array();
				return array(
					'text'        => (string) $data['result'],
					'usage'       => array( 'input' => (int) ( isset( $u['input_tokens'] ) ? $u['input_tokens'] : 0 ), 'output' => (int) ( isset( $u['output_tokens'] ) ? $u['output_tokens'] : 0 ) ),
					'model'       => $model ? $model : 'sonnet',
					'stop_reason' => 'end_turn',
				);
			}
			// Codex: its own home with the ChatGPT login.
			$home = $tmp . '/codex';
			wp_mkdir_p( $home );
			file_put_contents( $home . '/auth.json', $credential ); // phpcs:ignore
			file_put_contents( $home . '/config.toml', "cli_auth_credentials_store = \"file\"\n" ); // phpcs:ignore
			$env['CODEX_HOME'] = $home;
			$out               = $tmp . '/out.txt';
			$cmd               = array( $bin, 'exec', '--skip-git-repo-check', '--sandbox', 'read-only', '--ephemeral', '-o', $out );
			if ( $model ) {
				$cmd[] = '-m';
				$cmd[] = $model;
			}
			$cmd[] = '-';
			$input = ( $system ? "# Instructions\n" . $system . "\n\n# Task\n" : '' ) . $prompt . "\n\nReturn only the requested output. Do not run commands or edit files.";
			$r     = $this->run( $cmd, $input, $env, $tmp );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$text = is_file( $out ) ? trim( (string) file_get_contents( $out ) ) : ''; // phpcs:ignore
			$auth = is_file( $home . '/auth.json' ) ? (string) file_get_contents( $home . '/auth.json' ) : ''; // phpcs:ignore
			if ( $auth && $auth !== $credential && null !== json_decode( $auth ) ) {
				$this->plugin->settings->set_secret( 'codex_auth_json', $auth ); // Codex refreshed the login.
			}
			if ( '' === $text ) {
				return new \WP_Error( 'seolity_local_cli', 'Codex: ' . mb_substr( $r['err'] ? $r['err'] : $r['out'], 0, 400 ) );
			}
			return array( 'text' => $text, 'usage' => array( 'input' => 0, 'output' => 0 ), 'model' => $model ? $model : 'codex', 'stop_reason' => 'end_turn' );
		} finally {
			$this->rmdir( $tmp );
		}
	}

	/**
	 * Run a fixed argument list (never through a shell) — used by the CLI setup for
	 * `node --version`, `npm install <official package>` and `<cli> --version` only.
	 */
	public function exec( array $cmd, array $env, $cwd, $timeout ) {
		return $this->run( $cmd, '', $env, $cwd, $timeout );
	}

	private function run( array $cmd, $input, array $env, $cwd, $timeout = null ) {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( $timeout ? $timeout + 60 : 600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$timeout = $timeout ? (int) $timeout : max( 60, (int) $this->plugin->settings->get( 'ai_timeout' ) * 3 );
		$proc    = proc_open( $cmd, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, $cwd, $env ); // phpcs:ignore
		if ( ! is_resource( $proc ) ) {
			return new \WP_Error( 'seolity_local_cli', __( 'This server does not allow starting the CLI.', 'seolity' ) );
		}
		fwrite( $pipes[0], (string) $input ); // phpcs:ignore
		fclose( $pipes[0] ); // phpcs:ignore
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );
		$out   = '';
		$err   = '';
		$start = time();
		while ( true ) {
			$read  = array( $pipes[1], $pipes[2] );
			$write = null;
			$ex    = null;
			if ( false === @stream_select( $read, $write, $ex, 1 ) ) { // phpcs:ignore
				break;
			}
			foreach ( $read as $p ) {
				$chunk = fread( $p, 65536 ); // phpcs:ignore
				if ( $p === $pipes[1] ) {
					$out .= $chunk;
				} else {
					$err .= $chunk;
				}
			}
			$st = proc_get_status( $proc );
			if ( ! $st['running'] ) {
				$out .= stream_get_contents( $pipes[1] );
				$err .= stream_get_contents( $pipes[2] );
				break;
			}
			if ( time() - $start > $timeout ) {
				proc_terminate( $proc, 9 );
				return new \WP_Error( 'seolity_local_cli', __( 'The CLI on this server did not answer in time.', 'seolity' ) );
			}
		}
		fclose( $pipes[1] ); // phpcs:ignore
		fclose( $pipes[2] ); // phpcs:ignore
		proc_close( $proc );
		return array( 'out' => $out, 'err' => $err );
	}

	private function rmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $f ) {
			if ( '.' === $f || '..' === $f ) {
				continue;
			}
			$p = $dir . '/' . $f;
			is_dir( $p ) ? $this->rmdir( $p ) : @unlink( $p ); // phpcs:ignore
		}
		@rmdir( $dir ); // phpcs:ignore
	}
}
