<?php
/**
 * Subscription AI through GitHub Actions.
 *
 * Lets SEOlity use the AI plan the site owner already pays for — Claude Pro/Max
 * (Claude Code CLI) or ChatGPT Plus/Pro (OpenAI Codex CLI) — instead of a
 * pay-per-token API key. The official CLIs run on GitHub Actions in the owner's
 * private repository; nothing is scraped and no API is billed.
 *
 *  1. A provider call stores the request locally (remote_jobs) and throws
 *     AI\Deferred. At the end of the PHP request all new requests are written,
 *     AES-256-GCM encrypted, to jobs/<id>.json on the queue branch in ONE commit
 *     and the "SEOlity AI" workflow is dispatched.
 *  2. runner/runner.mjs answers each job and writes results/<id>.json (encrypted).
 *     It also POSTs the result to /seolity/v1/remote/callback (HMAC-signed) when
 *     the site is reachable; otherwise SEOlity collects results from GitHub.
 *  3. Re-running the identical request returns the stored answer. The REST layer
 *     answers 202 while waiting (the admin UI polls and re-sends), and the queue
 *     re-schedules deferred jobs without counting an attempt.
 *
 * @package SEOlity
 */

namespace SEOlity\AI\Remote;

use SEOlity\AI\Deferred;
use SEOlity\Core\Database;
use SEOlity\Plugin;
use SEOlity\Queue\Scheduler;
use SEOlity\Storage\GitHubClient;

defined( 'ABSPATH' ) || exit;

final class SubscriptionRunner {

	const WORKFLOW     = 'seolity-ai.yml';
	const STATE        = 'seolity_sub_state';
	const ENGINES      = array( 'claude', 'codex' );
	const WAIT_MESSAGE = 'Waiting for subscription AI';

	/** Secrets required per engine (GitHub secret name => local secret). */
	const ENGINE_SECRETS = array(
		'claude' => array( 'CLAUDE_CODE_OAUTH_TOKEN', 'claude_oauth_token' ),
		'codex'  => array( 'CODEX_AUTH_JSON', 'codex_auth_json' ),
	);

	/** @var Plugin */
	private $plugin;

	/** @var bool */
	private $flush_scheduled = false;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/* ------------------------------------------------------------------ config */

	private function repo() {
		return (string) $this->plugin->settings->get( 'sub_repo' );
	}

	private function branch() {
		$b = (string) $this->plugin->settings->get( 'sub_branch' );
		return preg_match( '#^[A-Za-z0-9._/-]{1,100}$#', $b ) ? $b : 'seolity-jobs';
	}

	private function state() {
		$s = get_option( self::STATE, array() );
		return is_array( $s ) ? $s : array();
	}

	private function set_state( array $changes ) {
		update_option( self::STATE, array_merge( $this->state(), $changes ), false );
	}

	/** A secret as the exact text the CLI needs (Settings::secret() decodes JSON values such as auth.json). */
	private function raw_secret( $key ) {
		$v = $this->plugin->settings->secret( $key );
		return is_array( $v ) ? (string) wp_json_encode( $v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : (string) $v;
	}

	private function secret() {
		return (string) $this->plugin->settings->secret( 'sub_shared_secret' );
	}

	/** Shared signing/encryption secret (created on first use). */
	public function shared_secret( $create = false ) {
		if ( $create && '' === $this->secret() ) {
			$this->plugin->settings->set_secret( 'sub_shared_secret', bin2hex( random_bytes( 32 ) ) );
		}
		return $this->secret();
	}

	/** HMAC used between WordPress, the runner and the Cloudflare relay. */
	public function sign( $message ) {
		return hash_hmac( 'sha256', (string) $message, $this->mac_key() );
	}

	public function state_value( $key ) {
		$s = $this->state();
		return isset( $s[ $key ] ) ? $s[ $key ] : null;
	}

	/** Bundled runner files (path in the runner repository => local file). */
	private static function files() {
		return array(
			'.github/workflows/' . self::WORKFLOW => SEOLITY_DIR . 'runner/seolity-ai.yml',
			'seolity-runner/runner.mjs'           => SEOLITY_DIR . 'runner/runner.mjs',
			'README.md'                           => SEOLITY_DIR . 'runner/README.md',
		);
	}

	private static function files_hash() {
		$h = '';
		foreach ( self::files() as $path => $file ) {
			$h .= $path . sha1_file( $file );
		}
		return sha1( $h );
	}

	/** Everything needed before a request can be sent for this engine. */
	public function missing( $engine ) {
		$missing = array();
		if ( ! GitHubClient::valid_repo( $this->repo() ) ) {
			$missing[] = __( 'Runner repository (owner/name)', 'seolity' );
		}
		if ( ! $this->plugin->github->has_token() ) {
			$missing[] = __( 'GitHub token', 'seolity' );
		}
		if ( isset( self::ENGINE_SECRETS[ $engine ] ) && ! $this->plugin->settings->has_secret( self::ENGINE_SECRETS[ $engine ][1] ) ) {
			$missing[] = 'claude' === $engine ? __( 'Claude subscription token (claude setup-token)', 'seolity' ) : __( 'ChatGPT auth.json (codex login)', 'seolity' );
		}
		$state = $this->state();
		if ( '' === $this->secret() || empty( $state['setup'] ) || ( isset( $state['repo'] ) && $state['repo'] !== $this->repo() ) ) {
			$missing[] = __( 'Run "Set up GitHub runner"', 'seolity' );
		}
		return $missing;
	}

	public function is_ready( $engine ) {
		return ! $this->missing( $engine );
	}

	/* ------------------------------------------------------------------ crypto (matches runner.mjs) */

	private function enc_key() {
		return hash( 'sha256', 'enc|' . $this->secret(), true );
	}

	private function mac_key() {
		return hash( 'sha256', 'mac|' . $this->secret() );
	}

	public function encrypt( array $data ) {
		$iv   = random_bytes( 12 );
		$tag  = '';
		$data = openssl_encrypt( wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), 'aes-256-gcm', $this->enc_key(), OPENSSL_RAW_DATA, $iv, $tag );
		return array( 'v' => 1, 'iv' => base64_encode( $iv ), 'tag' => base64_encode( $tag ), 'data' => base64_encode( $data ) ); // phpcs:ignore
	}

	/** @return array|null */
	public function decrypt( $envelope ) {
		if ( ! is_array( $envelope ) || 1 !== (int) ( isset( $envelope['v'] ) ? $envelope['v'] : 0 ) || empty( $envelope['iv'] ) || empty( $envelope['tag'] ) || ! isset( $envelope['data'] ) ) {
			return null;
		}
		$plain = openssl_decrypt( base64_decode( $envelope['data'] ), 'aes-256-gcm', $this->enc_key(), OPENSSL_RAW_DATA, base64_decode( $envelope['iv'] ), base64_decode( $envelope['tag'] ) ); // phpcs:ignore
		if ( false === $plain ) {
			return null;
		}
		$data = json_decode( $plain, true );
		return is_array( $data ) ? $data : null;
	}

	/* ------------------------------------------------------------------ provider entry point */

	/**
	 * Answer a provider request, or queue it and throw Deferred.
	 *
	 * @return array|\WP_Error Provider result ({text, usage, model, stop_reason}).
	 * @throws Deferred While the subscription runner is working on it.
	 */
	public function complete( $engine, $model, array $request ) {
		list( $system, $prompt ) = self::flatten( $request );
		$hash = sha1( $engine . '|' . $model . '|' . $system . '|' . $prompt );
		$db   = $this->plugin->db;
		$row  = $db->row( 'SELECT * FROM {remote_jobs} WHERE request_hash = %s ORDER BY created_at DESC', array( $hash ) );

		if ( $row ) {
			if ( 'done' === $row['status'] ) {
				return array(
					'text'        => (string) $row['response'],
					'usage'       => array( 'input' => (int) $row['usage_in'], 'output' => (int) $row['usage_out'] ),
					'model'       => $row['model'] ? $row['model'] : ( 'claude' === $engine ? 'sonnet' : 'codex' ),
					'stop_reason' => 'end_turn',
				);
			}
			if ( 'failed' === $row['status'] ) {
				$db->delete( 'remote_jobs', array( 'id' => $row['id'] ) ); // A later retry starts fresh.
				return new \WP_Error( 'seolity_ai_subscription', (string) $row['error'] );
			}
			if ( $this->expired( $row ) ) {
				$db->delete( 'remote_jobs', array( 'id' => $row['id'] ) );
				return new \WP_Error( 'seolity_ai_subscription', $row['error'] ? (string) $row['error'] : __( 'The subscription runner did not answer in time. Check the "SEOlity AI" workflow runs on GitHub.', 'seolity' ) );
			}
			if ( 'submitted' === $row['status'] ) {
				$this->collect();
				$row = $db->row( 'SELECT * FROM {remote_jobs} WHERE id = %s', array( $row['id'] ) );
				if ( $row && 'submitted' !== $row['status'] ) {
					return $this->complete( $engine, $model, $request );
				}
			} else {
				$this->schedule_flush(); // Still queued (e.g. GitHub was unreachable): try to send it again.
			}
			throw new Deferred( $row ? $row['id'] : '', self::WAIT_MESSAGE );
		}

		$missing = $this->missing( $engine );
		if ( $missing ) {
			/* translators: %s: list of missing settings */
			return new \WP_Error( 'seolity_ai_not_configured', sprintf( __( 'Subscription AI is not ready. Missing: %s.', 'seolity' ), implode( ', ', $missing ) ), array( 'status' => 400 ) );
		}
		$id = bin2hex( random_bytes( 16 ) );
		$db->insert(
			'remote_jobs',
			array(
				'id'           => $id,
				'request_hash' => $hash,
				'engine'       => $engine,
				'model'        => substr( (string) $model, 0, 80 ),
				'task'         => substr( (string) ( isset( $request['schema_name'] ) ? $request['schema_name'] : '' ), 0, 60 ),
				'status'       => 'queued',
				'payload'      => wp_json_encode( array( 'system' => $system, 'prompt' => $prompt ), JSON_UNESCAPED_UNICODE ),
				'created_at'   => Database::now(),
			)
		);
		$this->schedule_flush();
		throw new Deferred( $id, self::WAIT_MESSAGE );
	}

	/**
	 * Send one API request (Anthropic, OpenAI, Gemini, …) from GitHub's network — for hosts that
	 * cannot reach AI APIs (e.g. servers in Iran). Same queue, same encryption as subscription jobs;
	 * the request (with its API key) is stored encrypted and never leaves the site in clear text.
	 *
	 * @param array $req method, url, headers, body (string), timeout.
	 * @return array|\WP_Error Http::json() shape: status, body, raw, headers.
	 * @throws Deferred While the runner works on it.
	 */
	public function relay_http( array $req ) {
		$hash = sha1( 'http|' . $req['method'] . '|' . $req['url'] . '|' . $req['body'] );
		$db   = $this->plugin->db;
		$row  = $db->row( 'SELECT * FROM {remote_jobs} WHERE request_hash = %s ORDER BY created_at DESC', array( $hash ) );
		if ( $row ) {
			if ( 'done' === $row['status'] ) {
				$out = json_decode( (string) $row['response'], true );
				$db->delete( 'remote_jobs', array( 'id' => $row['id'] ) ); // One answer per request: a retry must call the API again.
				$raw = is_array( $out ) && isset( $out['raw'] ) ? (string) $out['raw'] : '';
				return array( 'status' => is_array( $out ) && isset( $out['status'] ) ? (int) $out['status'] : 0, 'body' => json_decode( $raw, true ), 'raw' => $raw, 'headers' => array() );
			}
			if ( 'failed' === $row['status'] || $this->expired( $row ) ) {
				$db->delete( 'remote_jobs', array( 'id' => $row['id'] ) );
				return new \WP_Error( 'seolity_route_github', $row['error'] ? (string) $row['error'] : __( 'GitHub did not deliver the AI answer in time. Check the "SEOlity AI" workflow runs on GitHub.', 'seolity' ) );
			}
			if ( 'submitted' === $row['status'] ) {
				$this->collect();
				$fresh = $db->row( 'SELECT status FROM {remote_jobs} WHERE id = %s', array( $row['id'] ) );
				if ( $fresh && 'submitted' !== $fresh['status'] ) {
					return $this->relay_http( $req );
				}
			} else {
				$this->schedule_flush();
			}
			throw new Deferred( $row['id'], self::WAIT_MESSAGE, 5 );
		}
		$missing = $this->missing( 'http' );
		if ( $missing ) {
			/* translators: %s: list of missing settings */
			return new \WP_Error( 'seolity_route_github', sprintf( __( 'The GitHub route is not ready. Missing: %s.', 'seolity' ), implode( ', ', $missing ) ), array( 'status' => 400 ) );
		}
		$id = bin2hex( random_bytes( 16 ) );
		$db->insert(
			'remote_jobs',
			array(
				'id'           => $id,
				'request_hash' => $hash,
				'engine'       => 'http',
				'model'        => '',
				'task'         => substr( (string) wp_parse_url( $req['url'], PHP_URL_HOST ), 0, 60 ),
				'status'       => 'queued',
				'payload'      => wp_json_encode( array( 'sealed' => $this->encrypt( $req ) ) ), // API keys never sit in the database in clear text.
				'created_at'   => Database::now(),
			)
		);
		$this->schedule_flush();
		throw new Deferred( $id, self::WAIT_MESSAGE, 5 );
	}

	/** Messages + schema → one system prompt and one user prompt for a CLI. */
	public static function flatten( array $request ) {
		$system = (string) ( isset( $request['system'] ) ? $request['system'] : '' );
		if ( ! empty( $request['schema'] ) ) {
			$system .= "\n\nRespond ONLY with a single JSON object (no markdown fences, no commentary) that validates against this JSON Schema:\n" . wp_json_encode( $request['schema'] );
		}
		$messages = isset( $request['messages'] ) ? (array) $request['messages'] : array();
		if ( 1 === count( $messages ) ) {
			return array( $system, (string) $messages[0]['content'] );
		}
		$parts = array();
		foreach ( $messages as $m ) {
			$parts[] = ( 'assistant' === $m['role'] ? "### YOUR PREVIOUS ANSWER\n" : "### USER\n" ) . $m['content'];
		}
		return array( $system, implode( "\n\n", $parts ) );
	}

	private function expired( array $row ) {
		$limit = MINUTE_IN_SECONDS * (int) $this->plugin->settings->get( 'sub_wait_minutes', 45 );
		$age   = time() - strtotime( $row['created_at'] . ' UTC' );
		return 'queued' === $row['status'] ? $age > max( 10 * MINUTE_IN_SECONDS, $limit / 3 ) : $age > $limit;
	}

	/* ------------------------------------------------------------------ sending */

	private function schedule_flush() {
		if ( ! $this->flush_scheduled ) {
			$this->flush_scheduled = true;
			add_action( 'shutdown', array( $this, 'flush' ), 5 );
		}
		$this->plugin->queue->push( 'subscription_collect', array(), array( 'unique' => true, 'priority' => 1, 'delay' => 30 ) );
	}

	/** Upload all queued requests in one commit and start the workflow. */
	public function flush() {
		$db   = $this->plugin->db;
		$rows = $db->rows( "SELECT id, engine, model, task, payload, created_at FROM {remote_jobs} WHERE status = 'queued' ORDER BY created_at ASC LIMIT 100" );
		if ( ! $rows ) {
			return 0;
		}
		$ensure = $this->ensure_files();
		if ( is_wp_error( $ensure ) ) {
			$this->mark_queued_error( $rows, $ensure->get_error_message() );
			return $ensure;
		}
		$files = array();
		foreach ( $rows as $r ) {
			$p   = json_decode( $r['payload'], true );
			$job = array(
				'id'      => $r['id'],
				'engine'  => $r['engine'],
				'model'   => $r['model'],
				'task'    => $r['task'],
				'system'  => isset( $p['system'] ) ? $p['system'] : '',
				'prompt'  => isset( $p['prompt'] ) ? $p['prompt'] : '',
				'created' => gmdate( 'c', strtotime( $r['created_at'] . ' UTC' ) ),
			);
			if ( 'http' === $r['engine'] ) {
				$job['http'] = isset( $p['sealed'] ) ? $this->decrypt( $p['sealed'] ) : null;
			}
			$files[ 'jobs/' . $r['id'] . '.json' ] = wp_json_encode( $this->encrypt( $job ) );
		}
		$res = $this->plugin->github->commit_files( $this->repo(), $this->branch(), $files, sprintf( 'SEOlity: %d AI request(s)', count( $files ) ) );
		if ( is_wp_error( $res ) ) {
			$this->mark_queued_error( $rows, $res->get_error_message() );
			return $res;
		}
		foreach ( $rows as $r ) {
			$db->update( 'remote_jobs', array( 'status' => 'submitted', 'submitted_at' => Database::now(), 'error' => null ), array( 'id' => $r['id'] ) );
		}
		$this->dispatch( true );
		return count( $rows );
	}

	private function mark_queued_error( array $rows, $message ) {
		foreach ( $rows as $r ) {
			$this->plugin->db->update( 'remote_jobs', array( 'error' => substr( (string) $message, 0, 1000 ) ), array( 'id' => $r['id'] ) );
		}
	}

	/** Re-push runner files when this plugin version ships a newer runner. */
	private function ensure_files() {
		$state = $this->state();
		if ( isset( $state['files_hash'] ) && self::files_hash() === $state['files_hash'] && isset( $state['repo'] ) && $state['repo'] === $this->repo() ) {
			return true;
		}
		return $this->push_files();
	}

	private function push_files() {
		$repo = $this->plugin->github->repo( $this->repo() );
		if ( is_wp_error( $repo ) ) {
			return $repo;
		}
		$default = (string) $repo['default_branch'];
		$files   = array();
		foreach ( self::files() as $path => $file ) {
			$files[ $path ] = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$res = $this->plugin->github->commit_files( $this->repo(), $default, $files, 'SEOlity: update subscription runner ' . SEOLITY_VERSION );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$branch = $this->plugin->github->ensure_branch( $this->repo(), $this->branch(), $default );
		if ( is_wp_error( $branch ) ) {
			return $branch;
		}
		$this->set_state( array( 'files_hash' => self::files_hash(), 'repo' => $this->repo(), 'default_branch' => $default ) );
		return true;
	}

	/**
	 * Start the workflow for the engines that have pending work — unless a warm run that
	 * already has those engines is polling the queue (it picks new jobs up within ~2 s).
	 *
	 * @param bool  $force   Skip the 2-minute throttle.
	 * @param array $engines Engines to start (default: those with pending jobs).
	 * @param int   $idle    Seconds the run stays warm after its last job (default: warm setting).
	 */
	public function dispatch( $force = false, array $engines = array(), $idle = 0 ) {
		$state = $this->state();
		if ( ! $force && ! empty( $state['last_dispatch'] ) && time() - (int) $state['last_dispatch'] < 120 ) {
			return false;
		}
		if ( ! $engines ) {
			$engines = $this->plugin->db->column( "SELECT DISTINCT engine FROM {remote_jobs} WHERE status IN ('queued','submitted')" );
		}
		$engines = array_values( array_intersect( array_merge( self::ENGINES, array( 'http' ) ), $engines ) );
		if ( ! $engines ) {
			$engines = array( $this->engine_from_provider() );
		}
		$warm = $this->warm_run();
		if ( $warm && ! array_diff( $engines, $warm['engines'] ) ) {
			$this->set_state( array( 'last_warm_hit' => time() ) );
			return 'warm';
		}
		$idle = $idle > 0 ? (int) $idle : 60 * (int) $this->plugin->settings->get( 'sub_warm_minutes' );
		$ref  = ! empty( $state['default_branch'] ) ? $state['default_branch'] : 'main';
		$res  = $this->plugin->github->dispatch( $this->repo(), self::WORKFLOW, $ref, array( 'engines' => implode( ',', $engines ), 'branch' => $this->branch(), 'idle' => (string) max( 60, min( 1800, $idle ) ), 'poll' => '2' ) );
		if ( ! is_wp_error( $res ) ) {
			$this->set_state( array( 'last_dispatch' => time(), 'last_engines' => $engines ) );
			delete_transient( 'seolity_sub_warm' );
		}
		return $res;
	}

	/**
	 * A run of the workflow that is queued or in progress (cached 15 s).
	 *
	 * @return array|null { status, engines[], started }
	 */
	public function warm_run() {
		$cached = get_transient( 'seolity_sub_warm' );
		if ( is_array( $cached ) ) {
			return $cached['run'];
		}
		$run = null;
		if ( GitHubClient::valid_repo( $this->repo() ) && $this->plugin->github->has_token() ) {
			$runs = $this->plugin->github->active_runs( $this->repo(), self::WORKFLOW );
			if ( ! is_wp_error( $runs ) && $runs ) {
				$r = $runs[0];
				$engines = array( 'http' );
				if ( preg_match( '/\(([a-z,]+)\)/', (string) $r['title'], $m ) ) {
					$engines = array_values( array_unique( array_merge( $engines, explode( ',', $m[1] ) ) ) );
				} else {
					$last    = $this->state_value( 'last_engines' );
					$engines = array_values( array_unique( array_merge( $engines, is_array( $last ) ? $last : array() ) ) );
				}
				$run = array( 'status' => $r['status'], 'engines' => $engines, 'started' => $r['started'], 'url' => $r['url'] );
			}
		}
		set_transient( 'seolity_sub_warm', array( 'run' => $run ), 15 );
		return $run;
	}

	/**
	 * Start a runner ahead of time (e.g. when the AI chat opens) so the first answer does not
	 * wait for GitHub to boot a machine and install the CLI.
	 */
	public function warm( $engine = '' ) {
		$engine = $engine ? $engine : $this->engine_from_provider();
		if ( $this->missing( 'http' ) ) {
			return array( 'warm' => false, 'reason' => 'not_ready' );
		}
		$res = $this->dispatch( true, array( $engine ) );
		return array( 'warm' => ! is_wp_error( $res ), 'state' => is_wp_error( $res ) ? $res->get_error_message() : ( 'warm' === $res ? 'running' : 'starting' ), 'run' => $this->warm_run() );
	}

	private function engine_from_provider() {
		$p = (string) $this->plugin->settings->get( 'ai_provider' );
		return 'sub_codex' === $p ? 'codex' : ( 'sub_claude' === $p ? 'claude' : 'http' );
	}

	/* ------------------------------------------------------------------ receiving */

	/**
	 * Pull finished results from GitHub (throttled), re-dispatch stalled queues,
	 * and expire requests that waited too long.
	 *
	 * @return int Results stored.
	 */
	public function collect( $force = false ) {
		if ( ! $force && get_transient( 'seolity_sub_collect' ) ) {
			return 0;
		}
		set_transient( 'seolity_sub_collect', 1, 4 );
		if ( $this->plugin->cloudflare->is_deployed() ) {
			$this->plugin->cloudflare->pull(); // Mailbox first: one cheap request instead of reading git.
		}
		$db      = $this->plugin->db;
		$pending = $db->rows( "SELECT id, status, created_at, submitted_at FROM {remote_jobs} WHERE status = 'submitted' ORDER BY created_at ASC LIMIT 200" );
		if ( ! $pending || ! GitHubClient::valid_repo( $this->repo() ) ) {
			return 0;
		}
		$listing = $this->plugin->github->list_dir( $this->repo(), 'results', $this->branch() );
		if ( is_wp_error( $listing ) ) {
			return 0;
		}
		$available = array();
		foreach ( $listing as $f ) {
			if ( isset( $f['name'] ) && preg_match( '/^([a-f0-9]{32})\.json$/', $f['name'], $m ) ) {
				$available[ $m[1] ] = $f['path'];
			}
		}
		$stored  = 0;
		$stalled = false;
		foreach ( $pending as $p ) {
			if ( isset( $available[ $p['id'] ] ) ) {
				$file = $this->plugin->github->get_file( $this->repo(), $available[ $p['id'] ], $this->branch() );
				if ( ! is_wp_error( $file ) && $this->store( $this->decrypt( json_decode( $file['content'], true ) ) ) ) {
					$stored++;
				}
				continue;
			}
			if ( $this->expired( $p ) ) {
				$db->update( 'remote_jobs', array( 'status' => 'failed', 'error' => __( 'The subscription runner did not answer in time. Check the "SEOlity AI" workflow runs on GitHub.', 'seolity' ), 'finished_at' => Database::now() ), array( 'id' => $p['id'] ) );
			} elseif ( time() - strtotime( $p['submitted_at'] . ' UTC' ) > 75 ) {
				$stalled = true; // A warm run may have exited just before the job arrived.
			}
		}
		if ( $stalled ) {
			delete_transient( 'seolity_sub_warm' );
			$this->dispatch(); // Throttled: a run that already exited before the job arrived gets restarted.
		}
		return $stored;
	}

	/** Save one decrypted result. */
	public function store( $result ) {
		if ( ! is_array( $result ) || empty( $result['id'] ) || ! preg_match( '/^[a-f0-9]{32}$/', (string) $result['id'] ) ) {
			return false;
		}
		$db  = $this->plugin->db;
		$row = $db->row( 'SELECT id, status FROM {remote_jobs} WHERE id = %s', array( $result['id'] ) );
		if ( ! $row || 'done' === $row['status'] ) {
			return false;
		}
		$ok    = ! empty( $result['ok'] ) && isset( $result['text'] ) && '' !== trim( (string) $result['text'] );
		$usage = isset( $result['usage'] ) && is_array( $result['usage'] ) ? $result['usage'] : array();
		$error = isset( $result['error'] ) ? (string) $result['error'] : __( 'Empty answer from the subscription runner.', 'seolity' );
		if ( ! empty( $result['quota'] ) ) {
			$error = __( 'Your AI subscription usage limit was reached. Try again later or switch provider.', 'seolity' ) . ' (' . $error . ')';
		}
		$db->update(
			'remote_jobs',
			array(
				'status'      => $ok ? 'done' : 'failed',
				'response'    => $ok ? (string) $result['text'] : null,
				'error'       => $ok ? null : substr( $error, 0, 1000 ),
				'usage_in'    => isset( $usage['input'] ) ? (int) $usage['input'] : 0,
				'usage_out'   => isset( $usage['output'] ) ? (int) $usage['output'] : 0,
				'model'       => isset( $result['model'] ) ? substr( sanitize_text_field( (string) $result['model'] ), 0, 80 ) : '',
				'finished_at' => Database::now(),
			),
			array( 'id' => $row['id'] )
		);
		if ( ! empty( $result['codex_auth'] ) && is_string( $result['codex_auth'] ) && null !== json_decode( $result['codex_auth'] ) ) {
			// Codex refreshed the ChatGPT login: keep the newest copy locally and in the runner secret.
			$this->plugin->settings->set_secret( 'codex_auth_json', $result['codex_auth'] );
			$this->plugin->github->put_secret( $this->repo(), 'CODEX_AUTH_JSON', $result['codex_auth'] );
		}
		// Wake queue jobs that were waiting for subscription answers.
		$db->query( "UPDATE {jobs} SET available_at = %s WHERE status = 'pending' AND last_error LIKE %s", array( Database::now(), self::WAIT_MESSAGE . '%' ) );
		Scheduler::kick();
		return true;
	}

	/** REST callback from the runner or the Cloudflare relay (HMAC-signed, 10-minute window). */
	public function handle_callback( \WP_REST_Request $r ) {
		return $this->ingest( (string) $r->get_body(), (string) $r->get_header( 'x_seolity_timestamp' ), (string) $r->get_header( 'x_seolity_signature' ), true );
	}

	/**
	 * Verify and store a signed results body. Mailbox items pulled later skip the
	 * time window (the relay channel is itself signed and results are encrypted).
	 *
	 * @return array|\WP_Error
	 */
	public function ingest( $body, $ts, $sig, $check_time = true ) {
		$ts = (int) $ts;
		if ( '' === $this->secret() || ( $check_time && abs( time() - $ts ) > 600 ) || ! hash_equals( $this->sign( $ts . '.' . $body ), (string) $sig ) ) {
			return new \WP_Error( 'seolity_signature', 'Invalid signature.', array( 'status' => 401 ) );
		}
		$data   = json_decode( $body, true );
		$stored = 0;
		foreach ( isset( $data['results'] ) ? (array) $data['results'] : array() as $env ) {
			$stored += $this->store( $this->decrypt( $env ) ) ? 1 : 0;
		}
		return array( 'stored' => $stored );
	}

	/** Cron heartbeat from the Cloudflare worker: run the background queue now. */
	public function handle_heartbeat( \WP_REST_Request $r ) {
		$ts   = (int) $r->get_header( 'x_seolity_timestamp' );
		$body = (string) $r->get_body();
		if ( '' === $this->secret() || abs( time() - $ts ) > 600 || ! hash_equals( $this->sign( $ts . '.' . $body ), (string) $r->get_header( 'x_seolity_signature' ) ) ) {
			return new \WP_Error( 'seolity_signature', 'Invalid signature.', array( 'status' => 401 ) );
		}
		update_option( 'seolity_cf_heartbeat', time(), false );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$this->flush();
		$this->collect( true );
		$done = $this->plugin->queue->work( 15 );
		return array( 'ok' => true, 'worked' => $done );
	}

	/** Status of one request (polled by the admin UI while it waits). */
	public function job_status( $id ) {
		$row = $this->plugin->db->row( 'SELECT id, engine, task, status, error, created_at, submitted_at, finished_at FROM {remote_jobs} WHERE id = %s', array( $id ) );
		if ( $row && in_array( $row['status'], array( 'queued', 'submitted' ), true ) ) {
			if ( 'queued' === $row['status'] ) {
				$this->flush();
			}
			$this->collect();
			$row = $this->plugin->db->row( 'SELECT id, engine, task, status, error, created_at, submitted_at, finished_at FROM {remote_jobs} WHERE id = %s', array( $id ) );
		}
		if ( ! $row ) {
			return array( 'id' => $id, 'status' => 'unknown' );
		}
		$row['waited'] = time() - strtotime( $row['created_at'] . ' UTC' );
		return $row;
	}

	/** Queue handler: keep collecting while requests are outstanding. */
	public function collect_job() {
		$this->flush();
		$this->collect( true );
		$db = $this->plugin->db;
		if ( (int) $db->value( "SELECT COUNT(*) FROM {remote_jobs} WHERE status IN ('queued','submitted')" ) ) {
			$this->plugin->queue->push( 'subscription_collect', array(), array( 'unique' => true, 'priority' => 1, 'delay' => 30 ) );
		}
		$db->query( 'DELETE FROM {remote_jobs} WHERE created_at < %s', array( gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) ) );
		return true;
	}

	/* ------------------------------------------------------------------ setup */

	/**
	 * Create/prepare the private runner repository: files, queue branch, secrets.
	 */
	public function setup() {
		$repo = $this->repo();
		if ( ! GitHubClient::valid_repo( $repo ) ) {
			return new \WP_Error( 'seolity_sub', __( 'Enter the runner repository as owner/name (it will be created as a private repository if it does not exist).', 'seolity' ), array( 'status' => 400 ) );
		}
		if ( ! $this->plugin->github->has_token() ) {
			return new \WP_Error( 'seolity_sub', __( 'Add a GitHub token first (Settings → GitHub).', 'seolity' ), array( 'status' => 400 ) );
		}
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return new \WP_Error( 'seolity_sub', __( 'The PHP OpenSSL extension is required.', 'seolity' ), array( 'status' => 400 ) );
		}
		$info = $this->plugin->github->repo( $repo );
		if ( is_wp_error( $info ) ) {
			$created = $this->plugin->github->create_repo( $repo, 'SEOlity subscription AI runner (private)' );
			if ( is_wp_error( $created ) ) {
				return $created;
			}
			sleep( 2 ); // GitHub needs a moment before the initial commit is readable.
			$info = $this->plugin->github->repo( $repo );
			if ( is_wp_error( $info ) ) {
				return $info;
			}
		}
		if ( empty( $info['private'] ) ) {
			return new \WP_Error( 'seolity_sub', __( 'The runner repository must be private.', 'seolity' ), array( 'status' => 400 ) );
		}
		if ( '' === $this->secret() ) {
			$this->plugin->settings->set_secret( 'sub_shared_secret', bin2hex( random_bytes( 32 ) ) );
		}
		$files = $this->push_files(); // Always re-push on setup; a failure keeps the previous working state.
		if ( is_wp_error( $files ) ) {
			return $files;
		}
		$secrets = array(
			'SEOLITY_SHARED_SECRET' => $this->secret(),
			'SEOLITY_CALLBACK_URL'  => $this->plugin->settings->get( 'sub_callback' ) && \SEOlity\Integrations\Cloudflare::site_is_public() ? rest_url( 'seolity/v1/remote/callback' ) : '',
			'SEOLITY_RELAY_URL'     => $this->plugin->cloudflare->is_deployed() ? $this->plugin->cloudflare->endpoint() : '',
		);
		foreach ( self::ENGINE_SECRETS as $pair ) {
			$value = $this->raw_secret( $pair[1] );
			if ( '' !== $value ) {
				$secrets[ $pair[0] ] = $value;
			}
		}
		foreach ( $secrets as $name => $value ) {
			$res = $this->plugin->github->put_secret( $repo, $name, '' === $value ? 'disabled' : $value );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}
		$this->set_state( array( 'setup' => time(), 'secrets' => array_keys( $secrets ), 'relay' => $secrets['SEOLITY_RELAY_URL'] ) );
		$this->plugin->audit_log->log( 'subscription.setup', 'site', null, array( 'repo' => $repo, 'secrets' => array_keys( $secrets ) ) );
		return $this->status();
	}

	/** Push one engine credential to the runner repository after it changes. */
	public function sync_secret( $local_key ) {
		foreach ( self::ENGINE_SECRETS as $pair ) {
			if ( $pair[1] === $local_key && GitHubClient::valid_repo( $this->repo() ) && $this->plugin->github->has_token() && ! empty( $this->state()['setup'] ) ) {
				$value = $this->raw_secret( $local_key );
				return $this->plugin->github->put_secret( $this->repo(), $pair[0], '' === $value ? 'disabled' : $value );
			}
		}
		return false;
	}

	/**
	 * Validate a pasted subscription credential.
	 *
	 * @return string|\WP_Error Normalized value.
	 */
	public static function validate_credential( $engine, $value ) {
		$value = trim( (string) $value );
		if ( 'codex' === $engine ) {
			$json = json_decode( $value, true );
			if ( ! is_array( $json ) ) {
				return new \WP_Error( 'seolity_sub', __( 'This is not the whole auth.json file. Copy everything from { to }.', 'seolity' ), array( 'status' => 400 ) );
			}
			if ( empty( $json['tokens'] ) ) {
				return new \WP_Error( 'seolity_sub', __( 'This auth.json was made with an API key, not a ChatGPT account. Run “codex login” and choose “Sign in with ChatGPT”.', 'seolity' ), array( 'status' => 400 ) );
			}
			return (string) wp_json_encode( $json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}
		if ( 0 === strpos( $value, 'sk-ant-api' ) ) {
			return new \WP_Error( 'seolity_sub', __( 'This is an Anthropic API key, not a subscription token. Run “claude setup-token” and paste the token that starts with sk-ant-oat01-, or use the Anthropic API provider instead.', 'seolity' ), array( 'status' => 400 ) );
		}
		if ( ! preg_match( '/^sk-ant-oat[0-9A-Za-z_-]{20,}$/', $value ) ) {
			return new \WP_Error( 'seolity_sub', __( 'This does not look like a Claude token. It starts with sk-ant-oat01- (output of: claude setup-token).', 'seolity' ), array( 'status' => 400 ) );
		}
		return $value;
	}

	/**
	 * One-shot deployment, ARIAGH-style: every step runs on the server and is
	 * reported back, then verified from GitHub's side.
	 *
	 * @param array $o engine, credential (optional when already saved), model, github_token (optional), repo, activate (bool), test (bool)
	 * @return array{ok:bool,steps:array[]}
	 */
	public function deploy( array $o ) {
		$engine = isset( $o['engine'] ) && 'codex' === $o['engine'] ? 'codex' : 'claude';
		$steps  = array();
		$step   = static function ( $id, $title, $result, $ok_detail = '' ) use ( &$steps ) {
			$ok      = ! is_wp_error( $result ) && false !== $result;
			$steps[] = array( 'id' => $id, 'title' => $title, 'ok' => $ok, 'detail' => $ok ? $ok_detail : ( is_wp_error( $result ) ? $result->get_error_message() : '' ) );
			return $ok;
		};
		$done = function ( $ok ) use ( &$steps ) {
			return array( 'ok' => $ok, 'steps' => $steps, 'status' => $this->status() );
		};
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		// 1. Account credential (validated before anything touches GitHub).
		$cred_key = self::ENGINE_SECRETS[ $engine ][1];
		if ( ! empty( $o['credential'] ) ) {
			$cred = self::validate_credential( $engine, $o['credential'] );
			if ( ! $step( 'credential', 'claude' === $engine ? __( 'Claude subscription token', 'seolity' ) : __( 'ChatGPT login (auth.json)', 'seolity' ), $cred, __( 'Valid format, saved encrypted.', 'seolity' ) ) ) {
				return $done( false );
			}
			$this->plugin->settings->set_secret( $cred_key, $cred );
		} elseif ( ! $step( 'credential', 'claude' === $engine ? __( 'Claude subscription token', 'seolity' ) : __( 'ChatGPT login (auth.json)', 'seolity' ), $this->plugin->settings->has_secret( $cred_key ) ? true : new \WP_Error( 'x', __( 'Paste the token from your computer first (step 2).', 'seolity' ) ), __( 'Already saved.', 'seolity' ) ) ) {
			return $done( false );
		}
		if ( isset( $o['model'] ) && '' !== (string) $o['model'] ) {
			$this->plugin->settings->update( array( 'ai_model_sub_' . $engine => sanitize_text_field( (string) $o['model'] ) ) );
		}

		// 2. GitHub account.
		if ( ! empty( $o['github_token'] ) ) {
			$con = $this->plugin->githubhub->connect( $o['github_token'] );
			if ( ! $step( 'github', __( 'GitHub account', 'seolity' ), is_wp_error( $con ) ? $con : true, isset( $con['token']['account']['login'] ) ? '@' . $con['token']['account']['login'] : '' ) ) {
				return $done( false );
			}
		} else {
			$me = $this->plugin->github->has_token() ? $this->plugin->github->whoami() : new \WP_Error( 'x', __( 'Connect GitHub: paste a token created with the ready link.', 'seolity' ) );
			if ( ! $step( 'github', __( 'GitHub account', 'seolity' ), $me, is_array( $me ) ? '@' . $me['login'] : '' ) ) {
				return $done( false );
			}
		}
		$me = $this->plugin->github->whoami();
		if ( is_array( $me ) && is_array( $me['scopes'] ) ) {
			$missing = array_diff( array( 'repo', 'workflow' ), $me['scopes'] );
			if ( ! $step( 'scopes', __( 'Token permissions', 'seolity' ), $missing ? new \WP_Error( 'x', sprintf( __( 'The token is missing: %s', 'seolity' ), implode( ', ', $missing ) ) . ' — ' . __( 'create a new one with the ready link.', 'seolity' ) ) : true, 'repo, workflow' ) ) {
				return $done( false );
			}
		}

		// 3. Repository (created private when missing).
		$repo = trim( (string) ( isset( $o['repo'] ) ? $o['repo'] : '' ) );
		if ( '' === $repo ) {
			$repo = '' !== $this->repo() ? $this->repo() : $this->plugin->githubhub->defaults()['runner'];
		}
		if ( false === strpos( $repo, '/' ) && is_array( $me ) ) {
			$repo = $me['login'] . '/' . $repo;
		}
		if ( ! $step( 'repo_name', __( 'Repository name', 'seolity' ), GitHubClient::valid_repo( $repo ) ? true : new \WP_Error( 'x', __( 'Repository must look like owner/name.', 'seolity' ) ), $repo ) ) {
			return $done( false );
		}
		$this->plugin->settings->update( array( 'sub_repo' => $repo ) );

		// 4–6. Repository, files, branch and secrets (SubscriptionRunner::setup does them in order).
		$setup = $this->setup();
		if ( ! $step( 'install', __( 'Private repository, runner, workflow, queue branch and secrets', 'seolity' ), is_wp_error( $setup ) ? $setup : true, 'https://github.com/' . $repo ) ) {
			return $done( false );
		}

		// 7. GitHub Actions enabled + workflow registered.
		$step( 'actions', __( 'GitHub Actions enabled', 'seolity' ), $this->plugin->github->ensure_actions( $repo ), __( 'Enabled', 'seolity' ) );
		$state = 'missing';
		for ( $i = 0; $i < 4 && 'active' !== $state; $i++ ) {
			$state = $this->plugin->github->workflow_state( $repo, self::WORKFLOW, true );
			if ( 'active' !== $state ) {
				sleep( 2 );
			}
		}
		$step( 'workflow', __( 'Workflow “SEOlity AI” registered', 'seolity' ), 'active' === $state ? true : new \WP_Error( 'x', is_wp_error( $state ) ? $state->get_error_message() : sprintf( __( 'GitHub reports the workflow as “%s”. It usually becomes active within a minute.', 'seolity' ), $state ) ), __( 'Active', 'seolity' ) );

		// 8. Secrets really on GitHub.
		$names    = $this->plugin->github->secret_names( $repo );
		$need     = array( 'SEOLITY_SHARED_SECRET', self::ENGINE_SECRETS[ $engine ][0] );
		$absent   = is_wp_error( $names ) ? $need : array_diff( $need, $names );
		$step( 'secrets', __( 'Secrets stored on GitHub', 'seolity' ), is_wp_error( $names ) ? $names : ( $absent ? new \WP_Error( 'x', sprintf( __( 'Missing on GitHub: %s', 'seolity' ), implode( ', ', $absent ) ) ) : true ), implode( ', ', $need ) );

		// 9. Use it.
		if ( ! isset( $o['activate'] ) || $o['activate'] ) {
			$this->plugin->settings->update( array( 'ai_provider' => 'sub_' . $engine ) );
			$step( 'activate', __( 'Set as the AI engine', 'seolity' ), true, 'claude' === $engine ? 'Claude Pro / Max' : 'ChatGPT Plus / Pro' );
		}
		$ok = ! array_filter(
			$steps,
			static function ( $x ) {
				return ! $x['ok'] && in_array( $x['id'], array( 'credential', 'github', 'scopes', 'repo_name', 'install' ), true );
			}
		);
		$this->plugin->audit_log->log( 'subscription.deployed', 'site', null, array( 'engine' => $engine, 'repo' => $repo, 'ok' => $ok ) );
		return $done( $ok );
	}

	/**
	 * Read-only verification from GitHub's side (what is really deployed).
	 */
	public function verify() {
		$repo = $this->repo();
		$out  = array( 'repo' => $repo, 'checked' => gmdate( 'c' ) );
		if ( ! GitHubClient::valid_repo( $repo ) || ! $this->plugin->github->has_token() ) {
			$out['deployed'] = false;
			return $out;
		}
		$info               = $this->plugin->github->inspect( $repo );
		$out['exists']      = $info['exists'];
		$out['private']     = $info['private'];
		$out['workflow']    = $info['exists'] ? $this->plugin->github->workflow_state( $repo, self::WORKFLOW ) : 'missing';
		$out['workflow']    = is_wp_error( $out['workflow'] ) ? 'unknown' : $out['workflow'];
		$names              = $info['exists'] ? $this->plugin->github->secret_names( $repo ) : array();
		$out['secrets']     = is_wp_error( $names ) ? null : $names;
		$out['deployed']    = $info['exists'] && $info['private'] && 'active' === $out['workflow'] && is_array( $out['secrets'] ) && in_array( 'SEOLITY_SHARED_SECRET', $out['secrets'], true );
		$out['engines']     = array(
			'claude' => is_array( $out['secrets'] ) && in_array( 'CLAUDE_CODE_OAUTH_TOKEN', $out['secrets'], true ),
			'codex'  => is_array( $out['secrets'] ) && in_array( 'CODEX_AUTH_JSON', $out['secrets'], true ),
		);
		return $out;
	}

	/** Tell the runner where the Cloudflare mailbox is (after deploying or removing the worker). */
	public function sync_relay() {
		if ( empty( $this->state()['setup'] ) || ! GitHubClient::valid_repo( $this->repo() ) || ! $this->plugin->github->has_token() ) {
			return false;
		}
		$url = $this->plugin->cloudflare->is_deployed() ? $this->plugin->cloudflare->endpoint() : '';
		$res = $this->plugin->github->put_secret( $this->repo(), 'SEOLITY_RELAY_URL', '' === $url ? 'disabled' : $url );
		if ( ! is_wp_error( $res ) ) {
			$this->set_state( array( 'relay' => $url ) );
		}
		return $res;
	}

	/**
	 * Step-by-step readiness check for the subscription wizard.
	 *
	 * @return array{checks:array[],ready:bool}
	 */
	public function check( $engine ) {
		$engine = 'codex' === $engine ? 'codex' : 'claude';
		$state  = $this->state();
		$checks = array();
		$add    = static function ( $id, $level, $title, $detail = '', $fix = '' ) use ( &$checks ) {
			$checks[] = array( 'id' => $id, 'level' => $level, 'title' => $title, 'detail' => $detail, 'fix' => $fix );
		};
		$add( 'php', function_exists( 'openssl_encrypt' ) && function_exists( 'sodium_crypto_box_seal' ) ? 'ok' : 'fail', __( 'PHP extensions (OpenSSL, sodium)', 'seolity' ), function_exists( 'sodium_crypto_box_seal' ) ? '' : __( 'Ask your host to enable the PHP sodium extension (needed to store GitHub secrets).', 'seolity' ) );

		$me = $this->plugin->github->has_token() ? $this->plugin->github->whoami() : null;
		if ( ! $this->plugin->github->has_token() ) {
			$add( 'github', 'fail', __( 'GitHub account connected', 'seolity' ), __( 'No GitHub token saved yet.', 'seolity' ), 'github' );
		} elseif ( is_wp_error( $me ) ) {
			$add( 'github', 'fail', __( 'GitHub account connected', 'seolity' ), $me->get_error_message(), 'github' );
		} else {
			$missing = is_array( $me['scopes'] ) ? array_diff( array( 'repo', 'workflow' ), $me['scopes'] ) : array();
			/* translators: %s: GitHub login */
			$add( 'github', $missing ? 'fail' : 'ok', __( 'GitHub account connected', 'seolity' ), $missing ? sprintf( __( 'The token is missing: %s', 'seolity' ), implode( ', ', $missing ) ) : sprintf( __( 'Signed in as @%s', 'seolity' ), $me['login'] ), $missing ? 'github' : '' );
		}

		$repo = $this->plugin->github->inspect( $this->repo() );
		if ( ! $repo['valid'] || ! $repo['exists'] ) {
			$add( 'repo', 'fail', __( 'Private runner repository', 'seolity' ), __( 'Not created yet — one click creates it with everything inside.', 'seolity' ), 'runner' );
		} elseif ( ! $repo['private'] ) {
			$add( 'repo', 'fail', __( 'Private runner repository', 'seolity' ), __( 'The repository is PUBLIC. Make it private on GitHub (Settings → General → Danger Zone → Change visibility).', 'seolity' ), 'runner' );
		} elseif ( empty( $state['setup'] ) || ( isset( $state['repo'] ) && $state['repo'] !== $this->repo() ) ) {
			$add( 'repo', 'warn', __( 'Private runner repository', 'seolity' ), __( 'The repository exists but the runner is not installed yet.', 'seolity' ), 'runner' );
		} else {
			$add( 'repo', 'ok', __( 'Private runner repository', 'seolity' ), $this->repo() . ( self::files_hash() === ( isset( $state['files_hash'] ) ? $state['files_hash'] : '' ) ? '' : ' — ' . __( 'runner files will be updated on the next request', 'seolity' ) ) );
		}

		$cred = self::ENGINE_SECRETS[ $engine ][1];
		$add( 'account', $this->plugin->settings->has_secret( $cred ) ? 'ok' : 'fail', 'claude' === $engine ? __( 'Claude subscription token saved', 'seolity' ) : __( 'ChatGPT login (auth.json) saved', 'seolity' ), $this->plugin->settings->has_secret( $cred ) ? ( in_array( self::ENGINE_SECRETS[ $engine ][0], isset( $state['secrets'] ) ? (array) $state['secrets'] : array(), true ) ? __( 'Stored in the runner’s GitHub Secrets.', 'seolity' ) : __( 'Saved here; press “Install / update runner” to copy it to GitHub Secrets.', 'seolity' ) ) : '', 'account' );

		$cf = $this->plugin->cloudflare;
		$public = \SEOlity\Integrations\Cloudflare::site_is_public();
		if ( $cf->is_deployed() ) {
			$add( 'relay', 'ok', __( 'Fast delivery (Cloudflare relay)', 'seolity' ), $cf->endpoint() . ' · ' . ( 'push' === $cf->mode() ? __( 'pushes answers to this site', 'seolity' ) : __( 'this site collects answers', 'seolity' ) ) );
		} else {
			$add( 'relay', $public ? 'info' : 'warn', __( 'Fast delivery (Cloudflare relay)', 'seolity' ), $public ? __( 'Optional. Without it answers arrive through a direct callback or are read from GitHub.', 'seolity' ) : __( 'This site is not reachable from the internet (local or private address). It still works by reading answers from GitHub; the free Cloudflare relay makes it faster.', 'seolity' ), 'cloudflare' );
		}
		$add( 'provider', in_array( $this->plugin->settings->get( 'ai_provider' ), array( 'sub_claude', 'sub_codex' ), true ) ? 'ok' : 'info', __( 'Used as the AI engine', 'seolity' ), in_array( $this->plugin->settings->get( 'ai_provider' ), array( 'sub_claude', 'sub_codex' ), true ) ? '' : __( 'Another provider is active. Press “Use as AI engine” after the test.', 'seolity' ), 'activate' );

		$ready = ! array_filter(
			$checks,
			static function ( $c ) {
				return 'fail' === $c['level'];
			}
		);
		return array( 'engine' => $engine, 'checks' => $checks, 'ready' => $ready );
	}

	/** Overview for Settings → AI. */
	public function status() {
		$state  = $this->state();
		$counts = array();
		foreach ( $this->plugin->db->rows( "SELECT status, COUNT(*) c FROM {remote_jobs} WHERE created_at > %s GROUP BY status", array( gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) ) as $r ) {
			$counts[ $r['status'] ] = (int) $r['c'];
		}
		$runs = array();
		if ( ! empty( $state['setup'] ) && GitHubClient::valid_repo( $this->repo() ) ) {
			$runs = $this->plugin->github->runs( $this->repo(), self::WORKFLOW, 6 );
			$runs = is_wp_error( $runs ) ? array( 'error' => $runs->get_error_message() ) : $runs;
		}
		return array(
			'repo'           => $this->repo(),
			'repo_url'       => GitHubClient::valid_repo( $this->repo() ) ? 'https://github.com/' . $this->repo() : '',
			'branch'         => $this->branch(),
			'setup'          => ! empty( $state['setup'] ) ? gmdate( 'c', (int) $state['setup'] ) : null,
			'files_current'  => isset( $state['files_hash'] ) && self::files_hash() === $state['files_hash'],
			'last_dispatch'  => ! empty( $state['last_dispatch'] ) ? gmdate( 'c', (int) $state['last_dispatch'] ) : null,
			'github_token'   => $this->plugin->github->has_token(),
			'claude_token'   => $this->plugin->settings->has_secret( 'claude_oauth_token' ),
			'codex_auth'     => $this->plugin->settings->has_secret( 'codex_auth_json' ),
			'missing'        => array( 'claude' => $this->missing( 'claude' ), 'codex' => $this->missing( 'codex' ) ),
			'requests_24h'   => $counts,
			'runs'           => $runs,
			'callback_url'   => rest_url( 'seolity/v1/remote/callback' ),
			'relay'          => ! empty( $state['relay'] ) ? $state['relay'] : '',
			'models'         => array( 'claude' => (string) $this->plugin->settings->get( 'ai_model_sub_claude' ), 'codex' => (string) $this->plugin->settings->get( 'ai_model_sub_codex' ) ),
			'provider'       => (string) $this->plugin->settings->get( 'ai_provider' ),
		);
	}
}
