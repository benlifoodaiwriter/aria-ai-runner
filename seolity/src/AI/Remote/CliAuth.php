<?php
/**
 * Logins of the local CLIs, kept apart per engine (Claude working never depends on Codex and the
 * other way round).
 *
 * Strategy (explicit and testable):
 *
 * - Plugin-managed (default). The login is stored encrypted in the plugin settings (secret
 *   `claude_oauth_token` / `codex_auth_json`). Codex runs with CODEX_HOME = a persistent private
 *   folder (0700, outside the web root when possible) whose auth.json (0600) is kept in sync both
 *   ways: a login the administrator saved is written to the folder, and tokens Codex refreshed
 *   during a run are copied back to the settings. A credential is never overwritten by an older
 *   one — the newer `last_refresh` of the same account wins.
 * - CLI-managed. The administrator signed in with the CLI over SSH (`codex login` /
 *   `claude` → /login). SEOlity then points CODEX_HOME / HOME at that login and never writes to it.
 *   Nothing assumes that ~/.codex/auth.json applies to another CODEX_HOME: the folder is explicit.
 *
 * When a run fails because the login is revoked, expired or invalid, the state is recorded with a
 * fingerprint of the credential; further runs stop immediately (no retry loop, no repeated calls
 * with a dead token) until the administrator saves a new login or presses "Test connection".
 * Tokens are never logged, returned to the browser or put in diagnostic reports.
 *
 * @package SEOlity
 */

namespace SEOlity\AI\Remote;

use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class CliAuth {

	const STATE  = 'seolity_cli_auth';
	const SECRET = array( 'claude' => 'claude_oauth_token', 'codex' => 'codex_auth_json' );
	const LOGIN  = 'seolity_cli_login_';

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function mode( $engine ) {
		return 'cli' === $this->plugin->settings->get( 'cli_auth_' . $engine ) ? 'cli' : 'plugin';
	}

	/** Folder of a CLI-managed login (SSH). */
	public function cli_home( $engine ) {
		$home = ProcessRunner::user_home();
		if ( 'codex' === $engine ) {
			$set = trim( (string) $this->plugin->settings->get( 'cli_codex_home' ) );
			return '' !== $set ? untrailingslashit( $set ) : ( $home ? $home . '/.codex' : '' );
		}
		return $home;
	}

	private function secret( $engine ) {
		$v = $this->plugin->settings->secret( self::SECRET[ $engine ] );
		return is_array( $v ) ? (string) wp_json_encode( $v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : (string) $v;
	}

	/** Short, irreversible fingerprint of the current credential ('' = none). */
	public function fingerprint( $engine ) {
		if ( 'cli' === $this->mode( $engine ) ) {
			$f = 'codex' === $engine ? $this->cli_home( 'codex' ) . '/auth.json' : $this->cli_home( 'claude' ) . '/.claude/.credentials.json';
			return @is_file( $f ) ? substr( hash( 'sha256', 'cli|' . (string) @filemtime( $f ) . '|' . (string) @filesize( $f ) ), 0, 16 ) : ''; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$s = $this->secret( $engine );
		return '' === $s ? '' : substr( hash( 'sha256', $s ), 0, 16 );
	}

	public function has_login( $engine ) {
		return '' !== $this->fingerprint( $engine );
	}

	/* ------------------------------------------------------------------ state */

	public function state( $engine ) {
		$all = (array) get_option( self::STATE, array() );
		$s   = isset( $all[ $engine ] ) ? (array) $all[ $engine ] : array();
		return array_merge( array( 'state' => 'unknown', 'code' => '', 'at' => '', 'fp' => '', 'last_ok' => '', 'version' => '' ), $s );
	}

	private function save_state( $engine, array $s ) {
		$all            = (array) get_option( self::STATE, array() );
		$all[ $engine ] = $s;
		update_option( self::STATE, $all, false );
	}

	/** Record the outcome of a real request (null = success). */
	public function record( $engine, $code = null ) {
		$s       = $this->state( $engine );
		$s['at'] = gmdate( 'c' );
		$s['fp'] = $this->fingerprint( $engine );
		if ( null === $code ) {
			$s['state']   = 'ok';
			$s['code']    = '';
			$s['last_ok'] = $s['at'];
		} else {
			$s['state'] = 'auth_required' === CliErrors::group( $code ) ? 'auth' : 'error';
			$s['code']  = $code;
		}
		$this->save_state( $engine, $s );
	}

	/** A known-dead login for the credential that is still saved → error without starting the CLI. */
	public function blocked( $engine ) {
		if ( ! $this->has_login( $engine ) ) {
			return CliErrors::error( 'AUTH_REQUIRED', '', array(), $engine );
		}
		$s = $this->state( $engine );
		if ( 'auth' === $s['state'] && $s['fp'] === $this->fingerprint( $engine ) ) {
			return CliErrors::error( $s['code'], '', array( 'since' => $s['at'] ), $engine );
		}
		return null;
	}

	public function forget( $engine ) {
		$all = (array) get_option( self::STATE, array() );
		unset( $all[ $engine ] );
		update_option( self::STATE, $all, false );
	}

	/* ------------------------------------------------------------------ Codex home */

	/** Persistent private CODEX_HOME for the plugin-managed login. */
	public function codex_home() {
		$home = ProcessRunner::private_dir( 'codex-home' );
		if ( ! is_file( $home . '/config.toml' ) ) {
			ProcessRunner::write_secret( $home . '/config.toml', "cli_auth_credentials_store = \"file\"\n" );
		}
		return $home;
	}

	private static function parse( $json ) {
		$a = json_decode( (string) $json, true );
		return is_array( $a ) && ! empty( $a['tokens'] ) && is_array( $a['tokens'] ) ? $a : null;
	}

	private static function account( array $a ) {
		return isset( $a['tokens']['account_id'] ) ? (string) $a['tokens']['account_id'] : '';
	}

	private static function refreshed( array $a ) {
		return isset( $a['last_refresh'] ) ? (int) strtotime( (string) $a['last_refresh'] ) : 0;
	}

	/**
	 * Prepare CODEX_HOME before a run.
	 *
	 * @return string|\WP_Error The folder.
	 */
	public function codex_before() {
		if ( 'cli' === $this->mode( 'codex' ) ) {
			$home = $this->cli_home( 'codex' );
			return $home && is_file( $home . '/auth.json' ) ? $home : CliErrors::error( 'AUTH_REQUIRED', '', array(), 'codex' );
		}
		$secret = self::parse( $this->secret( 'codex' ) );
		if ( ! $secret ) {
			return CliErrors::error( $this->secret( 'codex' ) ? 'AUTH_INVALID' : 'AUTH_REQUIRED', '', array(), 'codex' );
		}
		$home = $this->codex_home();
		$file = self::parse( is_file( $home . '/auth.json' ) ? (string) file_get_contents( $home . '/auth.json' ) : '' ); // phpcs:ignore
		$same = $file && self::account( $file ) === self::account( $secret );
		if ( $file && $same && self::refreshed( $file ) > self::refreshed( $secret ) ) {
			// The folder holds a newer refresh of the same login: keep it and update the settings.
			$this->codex_after( $home );
			return $home;
		}
		if ( ! $file || ! $same || self::refreshed( $secret ) >= self::refreshed( $file ) ) {
			ProcessRunner::write_secret( $home . '/auth.json', (string) wp_json_encode( $secret, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		}
		return $home;
	}

	/** After a run (or a login): copy a refreshed, valid auth.json back into the settings. */
	public function codex_after( $home ) {
		if ( 'cli' === $this->mode( 'codex' ) || ! is_file( $home . '/auth.json' ) ) {
			return;
		}
		$raw  = (string) file_get_contents( $home . '/auth.json' ); // phpcs:ignore
		$file = self::parse( $raw );
		if ( ! $file ) {
			return;
		}
		$norm = (string) wp_json_encode( $file, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$old  = self::parse( $this->secret( 'codex' ) );
		if ( $norm !== $this->secret( 'codex' ) && ( ! $old || self::refreshed( $file ) >= self::refreshed( $old ) || self::account( $file ) !== self::account( $old ) ) ) {
			$this->plugin->settings->set_secret( 'codex_auth_json', $norm );
		}
	}

	/** Remove the plugin-managed Codex login files (sign out / reconnect). */
	public function codex_clear() {
		$home = ProcessRunner::private_dir( 'codex-home' );
		ProcessRunner::shred( $home . '/auth.json' );
	}

	/** Exclusive lock around Codex runs: two processes refreshing one token at once revoke it. */
	public function lock( $engine ) {
		$dir = ProcessRunner::private_dir( 'locks' );
		$fh  = @fopen( $dir . '/' . $engine . '.lock', 'c' ); // phpcs:ignore
		if ( $fh ) {
			@flock( $fh, LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		return $fh;
	}

	public function unlock( $fh ) {
		if ( $fh ) {
			@flock( $fh, LOCK_UN ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			@fclose( $fh ); // phpcs:ignore
		}
	}

	/* ------------------------------------------------------------------ Claude home */

	/** HOME for Claude Code: the account home for a CLI-managed login, else a private folder. */
	public function claude_home() {
		if ( 'cli' === $this->mode( 'claude' ) && $this->cli_home( 'claude' ) ) {
			return $this->cli_home( 'claude' );
		}
		return ProcessRunner::private_dir( 'claude-home' );
	}

	/* ------------------------------------------------------------------ Codex sign-in from WordPress */

	/** Does this Codex support the official device-code sign-in (`codex login --device-auth`)? */
	public function device_supported() {
		$bin = $this->plugin->localcli->binary( 'codex' );
		if ( '' === $bin ) {
			return false;
		}
		$key    = 'seolity_codex_device_' . substr( md5( $bin . '|' . (string) @filemtime( $bin ) ), 0, 10 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return '1' === $cached;
		}
		$r  = ProcessRunner::run( array( $bin, 'login', '--help' ), '', ProcessRunner::env( $this->plugin->localcli->path_bins( 'codex', $bin ) ), get_temp_dir(), 30 );
		$ok = ! is_wp_error( $r ) && false !== strpos( $r['out'] . $r['err'], '--device-auth' );
		set_transient( $key, $ok ? '1' : '0', DAY_IN_SECONDS );
		return $ok;
	}

	public function login_state( $engine ) {
		$s = get_option( self::LOGIN . $engine );
		return is_array( $s ) ? $s : array( 'status' => 'idle' );
	}

	/** Start the device-code sign-in in the background. */
	public function start_login( $engine ) {
		if ( 'codex' !== $engine ) {
			return new \WP_Error( 'seolity_cli', __( 'Claude Code signs in with a token: run “claude setup-token” on your computer and paste the token.', 'seolity' ), array( 'status' => 400 ) );
		}
		if ( ! $this->device_supported() ) {
			return new \WP_Error( 'seolity_cli', __( 'This Codex version has no device sign-in. Update it with “Install / repair”, or paste auth.json from your computer.', 'seolity' ), array( 'status' => 400 ) );
		}
		update_option( self::LOGIN . $engine, array( 'status' => 'starting', 'at' => gmdate( 'c' ) ), false );
		$this->plugin->queue->push( 'cli_login', array( 'engine' => $engine ), array( 'unique' => 'cli_login_' . $engine, 'priority' => 0, 'max_attempts' => 1, 'timeout' => 1000 ) );
		\SEOlity\Activity\AiJobs::run_after_response( $this->plugin );
		return $this->login_state( $engine );
	}

	/** Queue handler: run `codex login --device-auth` and wait until the user approves (≤ 15 min). */
	public function run_login( $engine ) {
		$bin  = $this->plugin->localcli->binary( 'codex' );
		$home = $this->codex_home();
		$lock = $this->lock( 'codex' );
		$tmp  = $home . '/login-' . wp_generate_password( 8, false, false );
		wp_mkdir_p( $tmp );
		// Sign in into a fresh folder: a failed attempt never destroys the login that still works.
		ProcessRunner::write_secret( $tmp . '/config.toml', "cli_auth_credentials_store = \"file\"\n" );
		$opt  = self::LOGIN . $engine;
		$seen = '';
		$r    = ProcessRunner::run(
			array( $bin, 'login', '--device-auth' ),
			'',
			ProcessRunner::env( $this->plugin->localcli->path_bins( 'codex', $bin ), array( 'CODEX_HOME' => $tmp ) ),
			$tmp,
			900,
			static function ( $out, $err ) use ( $opt, &$seen ) {
				$text = preg_replace( "/\x1b\[[0-9;]*[A-Za-z]/", '', $out . "\n" . $err );
				$url  = preg_match( '#https://[^\s"\'<>]+#', $text, $m ) ? $m[0] : '';
				$code = preg_match( '/\b([A-Z0-9]{4,5}-[A-Z0-9]{4,6})\b/', $text, $c ) ? $c[1] : '';
				if ( $url && $code && $seen !== $url . $code ) {
					$seen = $url . $code;
					update_option( $opt, array( 'status' => 'waiting', 'url' => esc_url_raw( $url ), 'code' => $code, 'at' => gmdate( 'c' ) ), false );
				}
			}
		);
		$ok = ! is_wp_error( $r ) && 0 === $r['code'] && self::parse( is_file( $tmp . '/auth.json' ) ? (string) file_get_contents( $tmp . '/auth.json' ) : '' ); // phpcs:ignore
		if ( $ok ) {
			ProcessRunner::write_secret( $home . '/auth.json', (string) file_get_contents( $tmp . '/auth.json' ) ); // phpcs:ignore
			$this->plugin->settings->update( array( 'cli_auth_codex' => 'plugin' ) );
			$this->plugin->settings->set_secret( 'codex_auth_json', (string) wp_json_encode( self::parse( (string) file_get_contents( $tmp . '/auth.json' ) ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); // phpcs:ignore
			$this->forget( 'codex' );
			update_option( $opt, array( 'status' => 'done', 'at' => gmdate( 'c' ) ), false );
		} else {
			$code = is_wp_error( $r ) ? $r->get_error_data()['cli_code'] : CliErrors::classify( $r );
			update_option( $opt, array( 'status' => 'failed', 'code' => $code, 'message' => CliErrors::message( $code ), 'at' => gmdate( 'c' ) ), false );
		}
		ProcessRunner::rrmdir( $tmp, true );
		$this->unlock( $lock );
		$this->plugin->audit_log->log( 'cli.login', 'site', null, array( 'engine' => $engine, 'ok' => (bool) $ok ) );
		return true;
	}

	/** Sign out: remove the saved login of one engine (the other engine is untouched). */
	public function sign_out( $engine ) {
		$this->plugin->settings->set_secret( self::SECRET[ $engine ], '' );
		if ( 'codex' === $engine ) {
			$this->codex_clear();
		}
		$this->forget( $engine );
		delete_option( self::LOGIN . $engine );
		$this->plugin->audit_log->log( 'cli.sign_out', 'site', null, array( 'engine' => $engine ) );
	}
}
