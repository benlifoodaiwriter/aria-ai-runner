<?php
/**
 * Cloudflare relay: deploys and manages the SEOlity worker on the site
 * owner's own (free) Cloudflare account, straight from WordPress.
 *
 *  - Verify the API token and find the Account ID.
 *  - Create the KV namespace, upload cloudflare/worker.js as an ES module with
 *    its bindings, store the shared signing secret, enable workers.dev and set
 *    the Cron Trigger (push mode only).
 *  - Tell the subscription runner where the mailbox is (GitHub secret
 *    SEOLITY_RELAY_URL), pull results in pull mode, run diagnostics.
 *
 * Modes: push = the worker calls the site (public sites; adds a reliable cron),
 * pull = WordPress calls the worker (localhost, intranet, firewalled hosts),
 * auto = push when the site address is public, otherwise pull.
 *
 * @package SEOlity
 */

namespace SEOlity\Integrations;

use SEOlity\Plugin;
use SEOlity\Storage\GitHubClient;

defined( 'ABSPATH' ) || exit;

final class Cloudflare {

	const API   = 'https://api.cloudflare.com/client/v4';
	const STATE = 'seolity_cf_state';

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/* ------------------------------------------------------------------ config */

	private function token() {
		return (string) $this->plugin->settings->secret( 'cloudflare_api_token' );
	}

	private function account() {
		return preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $this->plugin->settings->get( 'cf_account_id' ) ) );
	}

	private function script() {
		$n = strtolower( (string) $this->plugin->settings->get( 'cf_worker_name' ) );
		$n = preg_replace( '/[^a-z0-9-]/', '-', $n );
		return '' === trim( $n, '-' ) ? 'seolity-relay' : substr( trim( $n, '-' ), 0, 60 );
	}

	public function state() {
		$s = get_option( self::STATE, array() );
		return is_array( $s ) ? $s : array();
	}

	private function set_state( array $changes ) {
		update_option( self::STATE, array_merge( $this->state(), $changes ), false );
	}

	public function is_configured() {
		return '' !== $this->token() && 32 === strlen( $this->account() );
	}

	public function is_deployed() {
		$s = $this->state();
		return ! empty( $s['deployed_at'] ) && '' !== $this->endpoint();
	}

	/** Worker address (custom domain wins over workers.dev). */
	public function endpoint() {
		$custom = untrailingslashit( (string) $this->plugin->settings->get( 'cf_custom_url' ) );
		if ( '' !== $custom ) {
			return $custom;
		}
		$s = $this->state();
		return isset( $s['url'] ) ? untrailingslashit( (string) $s['url'] ) : '';
	}

	/** Is the site address reachable from the internet (not localhost / private network)? */
	public static function site_is_public() {
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( '' === $host || 'localhost' === $host || preg_match( '/\.(local|localhost|test|lan|internal|home|invalid|example)$/', $host ) ) {
			return false;
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return (bool) filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}
		return false !== strpos( $host, '.' );
	}

	/** push | pull */
	public function mode() {
		$m = (string) $this->plugin->settings->get( 'cf_mode' );
		if ( 'push' === $m || 'pull' === $m ) {
			return $m;
		}
		return self::site_is_public() ? 'push' : 'pull';
	}

	/* ------------------------------------------------------------------ API */

	/**
	 * Cloudflare API call. Returns the "result" member on success.
	 *
	 * @return mixed|\WP_Error
	 */
	public function request( $method, $path, $body = null, $content_type = 'application/json', $token = null ) {
		$token = null === $token ? $this->token() : $token;
		if ( '' === $token ) {
			return new \WP_Error( 'seolity_cf', __( 'Save your Cloudflare API token first.', 'seolity' ), array( 'status' => 400 ) );
		}
		$args = array(
			'method'  => $method,
			'timeout' => 60,
			'headers' => array( 'Authorization' => 'Bearer ' . $token ),
		);
		if ( null !== $body ) {
			$args['body']                    = 'application/json' === $content_type && ! is_string( $body ) ? wp_json_encode( $body ) : $body;
			$args['headers']['Content-Type'] = $content_type;
		}
		$res = wp_remote_request( self::API . $path, $args );
		if ( is_wp_error( $res ) ) {
			return new \WP_Error( 'seolity_cf', 'Cloudflare: ' . $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$json = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $json ) ) {
			return new \WP_Error( 'seolity_cf', sprintf( 'Cloudflare: HTTP %d', $code ), array( 'status' => $code ) );
		}
		if ( empty( $json['success'] ) ) {
			$msgs = array();
			foreach ( isset( $json['errors'] ) ? (array) $json['errors'] : array() as $e ) {
				$msgs[] = ( isset( $e['code'] ) ? '[' . $e['code'] . '] ' : '' ) . ( isset( $e['message'] ) ? $e['message'] : '' );
			}
			return new \WP_Error( 'seolity_cf', 'Cloudflare: ' . ( $msgs ? implode( ' | ', $msgs ) : 'HTTP ' . $code ), array( 'status' => $code >= 400 ? $code : 400 ) );
		}
		return array_key_exists( 'result', $json ) ? $json['result'] : $json;
	}

	/* ------------------------------------------------------------------ account */

	/**
	 * Save + verify the token; fill in the Account ID when it is the only one.
	 *
	 * @return array|\WP_Error
	 */
	public function connect( $token, $account_id = '' ) {
		$token = trim( (string) $token );
		if ( '' === $token ) {
			$token = $this->token();
		}
		if ( '' === $token ) {
			return new \WP_Error( 'seolity_cf', __( 'Paste the Cloudflare API token first.', 'seolity' ), array( 'status' => 400 ) );
		}
		$check = $this->request( 'GET', '/user/tokens/verify', null, 'application/json', $token );
		$acc   = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $account_id ) );
		if ( is_wp_error( $check ) && 32 === strlen( $acc ) ) {
			$check = $this->request( 'GET', '/accounts/' . $acc . '/tokens/verify', null, 'application/json', $token ); // Account-owned tokens.
		}
		if ( is_wp_error( $check ) ) {
			return new \WP_Error( 'seolity_cf', __( 'Cloudflare did not accept this token. Create it again with the permissions listed in the guide.', 'seolity' ) . ' (' . $check->get_error_message() . ')', array( 'status' => 400 ) );
		}
		$this->plugin->settings->set_secret( 'cloudflare_api_token', $token );
		$accounts = $this->accounts();
		$name     = '';
		if ( is_array( $accounts ) ) {
			if ( '' === $acc && 1 === count( $accounts ) ) {
				$acc = $accounts[0]['id'];
			}
			foreach ( $accounts as $a ) {
				if ( $a['id'] === $acc ) {
					$name = $a['name'];
				}
			}
		}
		if ( 32 !== strlen( $acc ) ) {
			// Token is fine but we cannot tell which account to use: never report "connected" in this state.
			$this->plugin->settings->update( array( 'cf_account_id' => '' ) );
			$out             = $this->status();
			$out['accounts'] = is_array( $accounts ) ? $accounts : array();
			$out['notice']   = is_array( $accounts ) && count( $accounts ) > 1
				? __( 'The token works and has access to several accounts. Pick the account to use.', 'seolity' )
				: __( 'The token works, but the Account ID could not be read automatically. Copy it from the Cloudflare dashboard (Workers & Pages → Account ID on the right) and save again.', 'seolity' );
			return $out;
		}
		$this->plugin->settings->update( array( 'cf_account_id' => $acc ) );
		$this->set_state( array( 'account_name' => $name ) );
		$this->plugin->audit_log->log( 'cloudflare.connected', 'site', null, array( 'account' => $acc ) );
		return $this->status();
	}

	/** @return array[]|\WP_Error id, name */
	public function accounts() {
		$list = $this->request( 'GET', '/accounts?per_page=50' );
		if ( is_wp_error( $list ) ) {
			return $list;
		}
		return array_map(
			static function ( $a ) {
				return array( 'id' => (string) $a['id'], 'name' => (string) $a['name'] );
			},
			(array) $list
		);
	}

	/* ------------------------------------------------------------------ deploy */

	private function ensure_kv() {
		$acc   = $this->account();
		$state = $this->state();
		if ( ! empty( $state['kv'] ) && ! is_wp_error( $this->request( 'GET', "/accounts/{$acc}/storage/kv/namespaces/" . rawurlencode( $state['kv'] ) ) ) ) {
			return $state['kv'];
		}
		$title = $this->script() . '-mailbox';
		$list  = $this->request( 'GET', "/accounts/{$acc}/storage/kv/namespaces?per_page=100" );
		foreach ( is_array( $list ) ? $list : array() as $ns ) {
			if ( isset( $ns['title'] ) && $ns['title'] === $title ) {
				$this->set_state( array( 'kv' => $ns['id'] ) );
				return $ns['id'];
			}
		}
		$created = $this->request( 'POST', "/accounts/{$acc}/storage/kv/namespaces", array( 'title' => $title ) );
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		$this->set_state( array( 'kv' => $created['id'] ) );
		return $created['id'];
	}

	private function upload( $meta ) {
		$boundary = 'seolity' . wp_generate_password( 20, false, false );
		$eol      = "\r\n";
		$src      = (string) file_get_contents( SEOLITY_DIR . 'cloudflare/worker.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$body     = '--' . $boundary . $eol
			. 'Content-Disposition: form-data; name="metadata"; filename="metadata.json"' . $eol
			. 'Content-Type: application/json' . $eol . $eol
			. wp_json_encode( $meta ) . $eol
			. '--' . $boundary . $eol
			. 'Content-Disposition: form-data; name="worker.js"; filename="worker.js"' . $eol
			. 'Content-Type: application/javascript+module' . $eol . $eol
			. $src . $eol
			. '--' . $boundary . '--' . $eol;
		return $this->request( 'PUT', '/accounts/' . $this->account() . '/workers/scripts/' . rawurlencode( $this->script() ), $body, 'multipart/form-data; boundary=' . $boundary );
	}

	private function ensure_workers_dev() {
		$acc = $this->account();
		$sub = $this->request( 'GET', "/accounts/{$acc}/workers/subdomain" );
		if ( is_wp_error( $sub ) || empty( $sub['subdomain'] ) ) {
			$sub = $this->request( 'PUT', "/accounts/{$acc}/workers/subdomain", array( 'subdomain' => 'seolity-' . strtolower( wp_generate_password( 8, false, false ) ) ) );
			if ( is_wp_error( $sub ) ) {
				return new \WP_Error( 'seolity_cf', __( 'Your workers.dev subdomain is not set up yet. Open Workers & Pages once in the Cloudflare dashboard, then deploy again.', 'seolity' ) . ' (' . $sub->get_error_message() . ')' );
			}
		}
		$on = $this->request( 'POST', "/accounts/{$acc}/workers/scripts/" . rawurlencode( $this->script() ) . '/subdomain', array( 'enabled' => true, 'previews_enabled' => false ) );
		if ( is_wp_error( $on ) ) {
			return $on;
		}
		return 'https://' . $this->script() . '.' . $sub['subdomain'] . '.workers.dev';
	}

	private function cron() {
		return '*/' . max( 5, min( 60, (int) $this->plugin->settings->get( 'cf_cron_minutes', 10 ) ) ) . ' * * * *';
	}

	/**
	 * Deploy (or update) the worker.
	 *
	 * @return array|\WP_Error
	 */
	public function deploy() {
		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'seolity_cf', __( 'Save the Cloudflare API token and Account ID first.', 'seolity' ), array( 'status' => 400 ) );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$kv = $this->ensure_kv();
		if ( is_wp_error( $kv ) ) {
			return $kv;
		}
		$secret = $this->plugin->subscription->shared_secret( true );
		$mode   = $this->mode();
		$meta   = array(
			'main_module'        => 'worker.js',
			'compatibility_date' => '2024-11-01',
			'keep_bindings'      => array( 'secret_text' ),
			'bindings'           => array(
				array( 'type' => 'kv_namespace', 'name' => 'SEOLITY_KV', 'namespace_id' => $kv ),
				array( 'type' => 'plain_text', 'name' => 'SITE_URL', 'text' => home_url( '/' ) ),
				array( 'type' => 'plain_text', 'name' => 'WP_API', 'text' => untrailingslashit( $this->wp_api() ) ),
				array( 'type' => 'plain_text', 'name' => 'MODE', 'text' => $mode ),
				array( 'type' => 'plain_text', 'name' => 'VERSION', 'text' => SEOLITY_VERSION ),
			),
		);
		$up = $this->upload( $meta );
		if ( is_wp_error( $up ) ) {
			return $up;
		}
		$sec = $this->request( 'PUT', '/accounts/' . $this->account() . '/workers/scripts/' . rawurlencode( $this->script() ) . '/secrets', array( 'name' => 'SEOLITY_SHARED_SECRET', 'text' => $secret, 'type' => 'secret_text' ) );
		if ( is_wp_error( $sec ) ) {
			return $sec;
		}
		$url = $this->ensure_workers_dev();
		if ( is_wp_error( $url ) ) {
			return $url;
		}
		$schedules = 'push' === $mode ? array( array( 'cron' => $this->cron() ) ) : array();
		$cron      = $this->request( 'PUT', '/accounts/' . $this->account() . '/workers/scripts/' . rawurlencode( $this->script() ) . '/schedules', $schedules );
		$this->set_state( array( 'url' => $url, 'deployed_at' => time(), 'version' => SEOLITY_VERSION, 'mode' => $mode, 'cron' => 'push' === $mode ? $this->cron() : '', 'cron_error' => is_wp_error( $cron ) ? $cron->get_error_message() : null ) );
		$relay = $this->plugin->subscription->sync_relay();
		$this->plugin->audit_log->log( 'cloudflare.deployed', 'site', null, array( 'url' => $url, 'mode' => $mode ) );
		$out          = $this->status();
		$out['relay'] = is_wp_error( $relay ) ? $relay->get_error_message() : $relay;
		return $out;
	}

	/** Remove the worker (KV data expires by itself). */
	public function remove() {
		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'seolity_cf', __( 'Cloudflare is not connected.', 'seolity' ), array( 'status' => 400 ) );
		}
		$r = $this->request( 'DELETE', '/accounts/' . $this->account() . '/workers/scripts/' . rawurlencode( $this->script() ) . '?force=true' );
		if ( is_wp_error( $r ) && 404 !== (int) ( $r->get_error_data()['status'] ?? 0 ) ) {
			return $r;
		}
		update_option( self::STATE, array( 'kv' => isset( $this->state()['kv'] ) ? $this->state()['kv'] : null ), false );
		$this->plugin->subscription->sync_relay();
		$this->plugin->audit_log->log( 'cloudflare.removed', 'site', null, array() );
		return $this->status();
	}

	public function disconnect() {
		$this->plugin->settings->set_secret( 'cloudflare_api_token', '' );
		return $this->status();
	}

	/** REST base the worker calls (plain permalinks fall back to ?rest_route=). */
	public function wp_api() {
		$override = (string) $this->plugin->settings->get( 'cf_wp_api' );
		return '' !== $override ? $override : rest_url( 'seolity/v1' );
	}

	/* ------------------------------------------------------------------ talking to the worker */

	/** Signed POST to the worker. */
	public function call( $route, array $payload = array(), $timeout = 30 ) {
		if ( ! $this->is_deployed() ) {
			return new \WP_Error( 'seolity_cf', __( 'Deploy the Cloudflare worker first.', 'seolity' ), array( 'status' => 400 ) );
		}
		$body = wp_json_encode( (object) $payload );
		$ts   = (string) time();
		$res  = wp_remote_post(
			$this->endpoint() . $route,
			array(
				'timeout' => $timeout,
				'headers' => array(
					'Content-Type'        => 'application/json',
					'X-SEOlity-Timestamp' => $ts,
					'X-SEOlity-Signature' => $this->plugin->subscription->sign( $ts . '.' . $body ),
				),
				'body'    => $body,
			)
		);
		if ( is_wp_error( $res ) ) {
			return new \WP_Error( 'seolity_cf', __( 'The Cloudflare worker is not reachable from this server.', 'seolity' ) . ' (' . $res->get_error_message() . ')' );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$json = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code >= 400 || ! is_array( $json ) ) {
			return new \WP_Error( 'seolity_cf', sprintf( __( 'Cloudflare worker answered HTTP %d. Deploy it again.', 'seolity' ), $code ), array( 'status' => 400 ) );
		}
		return $json;
	}

	/** Worker health (public endpoint). */
	public function health() {
		if ( ! $this->is_deployed() ) {
			return null;
		}
		$res = wp_remote_get( $this->endpoint() . '/health', array( 'timeout' => 10 ) );
		if ( is_wp_error( $res ) ) {
			return array( 'ok' => false, 'error' => $res->get_error_message() );
		}
		$json = json_decode( wp_remote_retrieve_body( $res ), true );
		return is_array( $json ) ? $json : array( 'ok' => false, 'error' => 'HTTP ' . wp_remote_retrieve_response_code( $res ) );
	}

	/**
	 * Take results from the mailbox (pull mode, or anything the site could not receive).
	 *
	 * @return int Results stored.
	 */
	public function pull() {
		if ( ! $this->is_deployed() || get_transient( 'seolity_cf_pull' ) ) {
			return 0;
		}
		set_transient( 'seolity_cf_pull', 1, 5 );
		$list = $this->call( '/mailbox/list', array( 't' => time() ), 8 );
		if ( is_wp_error( $list ) || empty( $list['items'] ) ) {
			return 0;
		}
		$stored = 0;
		$ack    = array();
		foreach ( $list['items'] as $item ) {
			if ( ! isset( $item['key'], $item['ts'], $item['sig'], $item['body'] ) ) {
				continue;
			}
			$res = $this->plugin->subscription->ingest( (string) $item['body'], (string) $item['ts'], (string) $item['sig'], false );
			if ( ! is_wp_error( $res ) ) {
				$stored += (int) $res['stored'];
				$ack[]   = $item['key'];
			}
		}
		if ( $ack ) {
			$this->call( '/mailbox/ack', array( 'keys' => $ack ), 15 );
		}
		$this->set_state( array( 'last_pull' => time() ) );
		return $stored;
	}

	public function diagnose() {
		$res = $this->call( '/diagnose', array( 't' => time() ), 45 );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$res['health'] = $this->health();
		return $res;
	}

	/* ------------------------------------------------------------------ status */

	public function status() {
		$state = $this->state();
		return array(
			'token'          => '' !== $this->token(),
			'account_id'     => $this->account(),
			'account_name'   => isset( $state['account_name'] ) ? $state['account_name'] : '',
			'notice'         => '' !== $this->token() && 32 !== strlen( $this->account() ) ? __( 'The token works, but the Account ID could not be read automatically. Copy it from the Cloudflare dashboard (Workers & Pages → Account ID on the right) and save again.', 'seolity' ) : null,
			'configured'     => $this->is_configured(),
			'deployed'       => $this->is_deployed(),
			'url'            => $this->endpoint(),
			'worker_name'    => $this->script(),
			'deployed_at'    => ! empty( $state['deployed_at'] ) ? gmdate( 'c', (int) $state['deployed_at'] ) : null,
			'version'        => isset( $state['version'] ) ? $state['version'] : null,
			'outdated'       => ! empty( $state['version'] ) && SEOLITY_VERSION !== $state['version'],
			'mode'           => $this->mode(),
			'mode_setting'   => (string) $this->plugin->settings->get( 'cf_mode' ),
			'deployed_mode'  => isset( $state['mode'] ) ? $state['mode'] : null,
			'cron'           => isset( $state['cron'] ) ? $state['cron'] : '',
			'cron_error'     => isset( $state['cron_error'] ) ? $state['cron_error'] : null,
			'site_public'    => self::site_is_public(),
			'wp_api'         => $this->wp_api(),
			'last_heartbeat' => get_option( 'seolity_cf_heartbeat' ) ? gmdate( 'c', (int) get_option( 'seolity_cf_heartbeat' ) ) : null,
			'last_pull'      => ! empty( $state['last_pull'] ) ? gmdate( 'c', (int) $state['last_pull'] ) : null,
			'runner_relay'   => GitHubClient::valid_repo( (string) $this->plugin->settings->get( 'sub_repo' ) ) && ! empty( $this->plugin->subscription->state_value( 'relay' ) ),
		);
	}
}
