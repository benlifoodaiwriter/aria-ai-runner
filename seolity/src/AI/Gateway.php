<?php
/**
 * Network gateway: how AI and Google requests leave this server.
 *
 * Routes
 *  - direct     : the host calls the API itself (fastest when it is allowed to).
 *  - cloudflare : the site owner's own Cloudflare worker calls the API (synchronous, ~0.3–1 s extra).
 *                 Request and answer are AES-256-GCM encrypted and HMAC-signed end to end.
 *  - github     : the owner's private GitHub runner calls the API (asynchronous, seconds when the
 *                 runner is warm, ~40 s when GitHub has to boot a machine).
 *
 * Servers in Iran usually cannot call Anthropic, OpenAI or Google directly: the vendors block
 * Iranian IP addresses (sanctions) and some endpoints are filtered. There, "auto" never uses the
 * direct route and picks the faster working relay from the last speed test. Abroad, "auto" goes
 * direct and only falls back to a relay when the direct call cannot connect. Every choice can be
 * overridden in Settings, and a failed relay automatically falls through to the next one.
 *
 * @package SEOlity
 */

namespace SEOlity\AI;

use SEOlity\Core\Http;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class Gateway {

	const ROUTES = array( 'direct', 'cloudflare', 'github' );
	const PROBE  = 'seolity_route_probe';

	/** Hosts that sanction-affected servers relay (AI vendors + Google APIs used by SEOlity). */
	const RELAY_HOSTS = array( 'api.anthropic.com', 'api.openai.com', 'openrouter.ai', 'generativelanguage.googleapis.com', 'googleapis.com', 'oauth2.googleapis.com', 'accounts.google.com', 'serpapi.com' );

	/** @var Plugin */
	private $plugin;

	/** @var bool Set while this class itself sends a direct request (no re-routing). */
	private static $bypass = false;

	/** @var array Route actually used by the last request (for the UI and logs). */
	private $last = array();

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/* ------------------------------------------------------------------ settings */

	/** iran | abroad (an unset value is guessed from the site's language and timezone). */
	public function region() {
		$r = (string) $this->plugin->settings->get( 'host_region' );
		return '' !== $r ? $r : $this->guess_region();
	}

	public function region_is_set() {
		return '' !== (string) $this->plugin->settings->get( 'host_region' );
	}

	public function guess_region() {
		$tz = function_exists( 'wp_timezone_string' ) ? (string) wp_timezone_string() : '';
		if ( 'Asia/Tehran' === $tz || '+03:30' === $tz || 0 === strpos( get_locale(), 'fa' ) ) {
			return 'iran';
		}
		return 'abroad';
	}

	public function mode() {
		$m = (string) $this->plugin->settings->get( 'ai_route' );
		return in_array( $m, array( 'auto', 'direct', 'cloudflare', 'github' ), true ) ? $m : 'auto';
	}

	/** Which relays are set up right now. */
	public function available() {
		return array(
			'direct'     => true,
			'cloudflare' => $this->plugin->cloudflare->is_deployed(),
			'github'     => ! $this->plugin->subscription->missing( 'http' ),
		);
	}

	/**
	 * Routes to try, best first.
	 *
	 * @param bool $async_ok Whether the caller can wait for the asynchronous GitHub route.
	 */
	public function order( $async_ok = true ) {
		$avail  = $this->available();
		$mode   = $this->mode();
		$relays = $this->relays_by_speed();
		if ( 'auto' === $mode ) {
			$order = 'iran' === $this->region() ? $relays : array_merge( array( 'direct' ), $relays );
		} elseif ( 'direct' === $mode ) {
			$order = array( 'direct' );
		} else {
			// A chosen relay first; the other relay is the safety net. Never "direct" from Iran.
			$order = array_merge( array( $mode ), array_diff( $relays, array( $mode ) ) );
			if ( 'abroad' === $this->region() ) {
				$order[] = 'direct';
			}
		}
		$order = array_values(
			array_filter(
				array_unique( $order ),
				static function ( $r ) use ( $avail, $async_ok ) {
					return ! empty( $avail[ $r ] ) && ( $async_ok || 'github' !== $r );
				}
			)
		);
		return $order ? $order : array( 'direct' );
	}

	/** Relays ordered by the last measured speed (Cloudflare first when nothing was measured). */
	private function relays_by_speed() {
		$probe = get_option( self::PROBE );
		$cf    = isset( $probe['routes']['cloudflare'] ) ? $probe['routes']['cloudflare'] : null;
		$gh    = isset( $probe['routes']['github'] ) ? $probe['routes']['github'] : null;
		if ( $cf && empty( $cf['ok'] ) && $gh && ! empty( $gh['ok'] ) ) {
			return array( 'github', 'cloudflare' );
		}
		if ( $cf && $gh && ! empty( $cf['ok'] ) && ! empty( $gh['ok'] ) && (int) $gh['estimate_ms'] < (int) $cf['estimate_ms'] ) {
			return array( 'github', 'cloudflare' );
		}
		return array( 'cloudflare', 'github' );
	}

	/** The route the next AI request will most likely use (for status chips). */
	public function current( $provider_id = null ) {
		$provider_id = $provider_id ? $provider_id : (string) $this->plugin->settings->get( 'ai_provider' );
		if ( 0 === strpos( $provider_id, 'sub_' ) ) {
			return 'github';
		}
		if ( 'ollama' === $provider_id ) {
			return 'direct';
		}
		$o = $this->order();
		return $o[0];
	}

	public function last() {
		return $this->last;
	}

	public function reset_last() {
		$this->last = array();
	}

	/* ------------------------------------------------------------------ sending */

	/** Local / private endpoints (Ollama, LM Studio on the same server) are never relayed. */
	public static function routable( $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host || 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
			return false;
		}
		if ( 'localhost' === $host || filter_var( $host, FILTER_VALIDATE_IP ) || false === strpos( $host, '.' ) ) {
			return false;
		}
		return ! preg_match( '/\.(local|localhost|lan|internal|home|test)$/', $host );
	}

	/**
	 * Send an AI request over the best route, falling through to the next route when one cannot
	 * connect (an API error such as 401 or 429 is an answer, not a route failure).
	 *
	 * @return array|\WP_Error Http::json() shape.
	 * @throws Deferred When the GitHub route is used and the runner is still working.
	 */
	public function request( $method, $url, $body, array $headers, $timeout = 120 ) {
		$raw_body = null === $body ? '' : ( is_string( $body ) ? $body : wp_json_encode( $body ) );
		if ( ! self::routable( $url ) ) {
			return $this->direct( $method, $url, $body, $headers, $timeout );
		}
		$errors = array();
		foreach ( $this->order() as $route ) {
			$t0  = microtime( true );
			$res = $this->send( $route, $method, $url, $body, $raw_body, $headers, $timeout );
			if ( is_wp_error( $res ) && in_array( $res->get_error_code(), array( 'http_request_failed', 'http_request_not_executed', 'seolity_route_unreachable', 'seolity_route_github' ), true ) ) {
				$errors[] = $this->label( $route ) . ': ' . $res->get_error_message();
				$this->note_failure( $route, $res->get_error_message() );
				continue;
			}
			if ( ! is_wp_error( $res ) && $this->blocked( $res ) ) {
				$errors[] = $this->label( $route ) . ': ' . __( 'the AI provider refuses requests from this location', 'seolity' );
				$this->note_failure( $route, 'region blocked (HTTP ' . $res['status'] . ')' );
				continue;
			}
			$this->last = array( 'route' => $route, 'ms' => (int) round( ( microtime( true ) - $t0 ) * 1000 ), 'host' => wp_parse_url( $url, PHP_URL_HOST ) );
			return $res;
		}
		$hint = 'iran' === $this->region() && ! $this->plugin->cloudflare->is_deployed() && ! empty( $errors )
			? ' ' . __( 'Servers in Iran need a relay: deploy the free Cloudflare worker or set up the GitHub runner (Connections → Server & speed).', 'seolity' )
			: '';
		return new \WP_Error( 'seolity_route_failed', __( 'No network route could reach the AI provider.', 'seolity' ) . ' ' . implode( ' · ', $errors ) . $hint );
	}

	/** Answer that is really a regional block (sanctions), not an API error. */
	private function blocked( array $res ) {
		if ( 403 !== (int) $res['status'] && 451 !== (int) $res['status'] ) {
			return false;
		}
		return (bool) preg_match( '/unsupported[_ ]country|not (available|supported) in your (country|region)|location is not supported|unsupported_country_region_territory|request not allowed|sanction/i', (string) $res['raw'] );
	}

	private function send( $route, $method, $url, $body, $raw_body, array $headers, $timeout ) {
		if ( 'cloudflare' === $route ) {
			return $this->via_cloudflare( $method, $url, $raw_body, $headers, $timeout );
		}
		if ( 'github' === $route ) {
			return $this->plugin->subscription->relay_http( array( 'method' => $method, 'url' => $url, 'headers' => $this->with_json( $headers, $raw_body ), 'body' => $raw_body, 'timeout' => $timeout ) );
		}
		return $this->direct( $method, $url, $body, $headers, $timeout );
	}

	private function direct( $method, $url, $body, array $headers, $timeout ) {
		self::$bypass = true;
		try {
			return Http::json( $method, $url, $body, $headers, $timeout );
		} finally {
			self::$bypass = false;
		}
	}

	private function with_json( array $headers, $raw_body ) {
		$h = array_merge( array( 'Accept' => 'application/json', 'User-Agent' => 'SEOlity/' . SEOLITY_VERSION ), $headers );
		if ( '' !== $raw_body && ! isset( $h['Content-Type'] ) && ! isset( $h['content-type'] ) ) {
			$h['Content-Type'] = 'application/json';
		}
		return $h;
	}

	/** Synchronous relay through the owner's Cloudflare worker. */
	public function via_cloudflare( $method, $url, $raw_body, array $headers, $timeout ) {
		$sub = $this->plugin->subscription;
		if ( '' === (string) $sub->shared_secret() ) {
			return new \WP_Error( 'seolity_route_unreachable', __( 'Deploy the Cloudflare worker again (no shared key yet).', 'seolity' ) );
		}
		$sealed = $sub->encrypt( array( 'method' => $method, 'url' => $url, 'headers' => $this->with_json( $headers, $raw_body ), 'body' => $raw_body, 'timeout' => (int) $timeout ) );
		$res    = $this->plugin->cloudflare->call( '/proxy', array( 'env' => $sealed ), (int) $timeout + 15 );
		if ( is_wp_error( $res ) || empty( $res['env'] ) ) {
			return new \WP_Error( 'seolity_route_unreachable', is_wp_error( $res ) ? $res->get_error_message() : __( 'The Cloudflare worker is outdated: deploy it again.', 'seolity' ) );
		}
		$out = $sub->decrypt( $res['env'] );
		if ( ! is_array( $out ) ) {
			return new \WP_Error( 'seolity_route_unreachable', __( 'Could not read the answer from the Cloudflare worker (shared key mismatch). Deploy it again.', 'seolity' ) );
		}
		if ( empty( $out['status'] ) ) {
			return new \WP_Error( 'seolity_route_unreachable', 'Cloudflare → API: ' . ( isset( $out['error'] ) ? $out['error'] : 'no answer' ) );
		}
		$raw = isset( $out['raw'] ) ? (string) $out['raw'] : '';
		return array( 'status' => (int) $out['status'], 'body' => json_decode( $raw, true ), 'raw' => $raw, 'headers' => array(), 'colo' => isset( $out['colo'] ) ? $out['colo'] : '' );
	}

	/**
	 * `pre_http_request`: Google / SERP / AI calls made by other parts of SEOlity (Search Console,
	 * PageSpeed, OAuth, SERP API) go through Cloudflare when the host is in Iran.
	 */
	public function filter_http( $pre, $args, $url ) {
		if ( false !== $pre || self::$bypass || ! $this->plugin->settings->get( 'route_google' ) ) {
			return $pre;
		}
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( ! $this->relay_host( $host ) ) {
			return $pre;
		}
		$order = $this->order( false );
		if ( 'cloudflare' !== $order[0] ) {
			return $pre;
		}
		$headers = isset( $args['headers'] ) ? (array) $args['headers'] : array();
		$body    = isset( $args['body'] ) ? $args['body'] : '';
		if ( is_array( $body ) ) {
			$body                      = http_build_query( $body, '', '&' );
			$headers['Content-Type'] = 'application/x-www-form-urlencoded';
		}
		$method = isset( $args['method'] ) ? strtoupper( (string) $args['method'] ) : 'GET';
		$res    = $this->via_cloudflare( $method, $url, (string) $body, $headers, isset( $args['timeout'] ) ? (int) $args['timeout'] : 30 );
		if ( is_wp_error( $res ) ) {
			return 'abroad' === $this->region() ? $pre : new \WP_Error( 'http_request_failed', $res->get_error_message() );
		}
		return array(
			'headers'  => array(),
			'body'     => $res['raw'],
			'response' => array( 'code' => $res['status'], 'message' => get_status_header_desc( $res['status'] ) ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	private function relay_host( $host ) {
		foreach ( apply_filters( 'seolity_relay_hosts', self::RELAY_HOSTS ) as $h ) {
			if ( $host === $h || substr( $host, -strlen( '.' . $h ) ) === '.' . $h ) {
				return true;
			}
		}
		return false;
	}

	/* ------------------------------------------------------------------ speed test */

	/** API endpoint used to measure the path to the active AI provider. */
	public function probe_target() {
		$map = array(
			'anthropic'  => 'https://api.anthropic.com/v1/models',
			'openai'     => 'https://api.openai.com/v1/models',
			'gemini'     => 'https://generativelanguage.googleapis.com/v1beta/models',
			'openrouter' => 'https://openrouter.ai/api/v1/models',
		);
		$p = (string) $this->plugin->settings->get( 'ai_provider' );
		if ( isset( $map[ $p ] ) ) {
			return $map[ $p ];
		}
		if ( 'custom' === $p ) {
			$prov = ProviderRegistry::get( $this->plugin, 'custom' );
			$url  = $prov && method_exists( $prov, 'models_url' ) ? (string) $prov->models_url() : '';
			if ( self::routable( $url ) ) {
				return $url;
			}
		}
		return $map['anthropic'];
	}

	/**
	 * Measure every route (a few small requests) and remember the result.
	 *
	 * @return array { at, region, guess, target, routes{ direct|cloudflare|github: {ok, ms, estimate_ms, detail} }, best }
	 */
	public function probe() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 90 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$target = $this->probe_target();
		$routes = array();

		// Direct: any HTTP answer except a regional block means the API is reachable.
		$t0  = microtime( true );
		$res = $this->direct( 'GET', $target, null, array(), 8 );
		$ms  = (int) round( ( microtime( true ) - $t0 ) * 1000 );
		if ( is_wp_error( $res ) ) {
			$routes['direct'] = array( 'ok' => false, 'ms' => $ms, 'detail' => $res->get_error_message() );
		} elseif ( $this->blocked( $res ) ) {
			$routes['direct'] = array( 'ok' => false, 'ms' => $ms, 'detail' => sprintf( __( 'Blocked for this server’s country (HTTP %d)', 'seolity' ), $res['status'] ) );
		} else {
			$routes['direct'] = array( 'ok' => true, 'ms' => $ms, 'estimate_ms' => $ms, 'detail' => 'HTTP ' . $res['status'] );
		}

		// Cloudflare: a real proxied request to the same API.
		if ( $this->plugin->cloudflare->is_deployed() ) {
			$t0  = microtime( true );
			$res = $this->via_cloudflare( 'GET', $target, '', array(), 10 );
			$ms  = (int) round( ( microtime( true ) - $t0 ) * 1000 );
			if ( is_wp_error( $res ) ) {
				$routes['cloudflare'] = array( 'ok' => false, 'ms' => $ms, 'detail' => $res->get_error_message() );
			} elseif ( $this->blocked( $res ) ) {
				$routes['cloudflare'] = array( 'ok' => false, 'ms' => $ms, 'detail' => sprintf( __( 'The API refuses Cloudflare’s location (HTTP %d)', 'seolity' ), $res['status'] ) );
			} else {
				$routes['cloudflare'] = array( 'ok' => true, 'ms' => $ms, 'estimate_ms' => $ms, 'detail' => 'HTTP ' . $res['status'] . ( ! empty( $res['colo'] ) ? ' · ' . $res['colo'] : '' ) );
			}
		} else {
			$routes['cloudflare'] = array( 'ok' => false, 'ms' => null, 'setup' => true, 'detail' => __( 'Not deployed yet', 'seolity' ) );
		}

		// GitHub: API round trip + how long runners took recently (warm vs. cold start).
		if ( ! $this->plugin->subscription->missing( 'http' ) ) {
			$t0  = microtime( true );
			$res = $this->plugin->github->request( 'GET', '/rate_limit' );
			$ms  = (int) round( ( microtime( true ) - $t0 ) * 1000 );
			if ( is_wp_error( $res ) ) {
				$routes['github'] = array( 'ok' => false, 'ms' => $ms, 'detail' => $res->get_error_message() );
			} else {
				$typical = $this->typical_runner_ms();
				$warm    = $this->plugin->subscription->warm_run();
				$routes['github'] = array(
					'ok'          => true,
					'ms'          => $ms,
					'estimate_ms' => $warm ? max( 3000, $ms * 3 ) : $typical,
					'warm'        => (bool) $warm,
					'detail'      => $warm ? __( 'Runner is warm', 'seolity' ) : sprintf( __( 'Cold start ≈ %d s', 'seolity' ), (int) round( $typical / 1000 ) ),
				);
			}
		} else {
			$routes['github'] = array( 'ok' => false, 'ms' => null, 'setup' => true, 'detail' => __( 'Runner not set up yet', 'seolity' ) );
		}

		$out = array(
			'at'     => time(),
			'region' => $this->region(),
			'guess'  => $this->guess_region(),
			'target' => wp_parse_url( $target, PHP_URL_HOST ),
			'routes' => $routes,
		);
		update_option( self::PROBE, $out, false );
		$out['order'] = $this->order();
		$out['best']  = $out['order'][0];
		return $out;
	}

	/** Median time GitHub runners needed for recent answers (default 45 s for a cold start). */
	private function typical_runner_ms() {
		$rows = $this->plugin->db->column( "SELECT TIMESTAMPDIFF(SECOND, created_at, finished_at) FROM {remote_jobs} WHERE status = 'done' AND finished_at IS NOT NULL ORDER BY created_at DESC LIMIT 15" );
		$rows = array_map( 'intval', (array) $rows );
		if ( ! $rows ) {
			return 45000;
		}
		sort( $rows );
		return 1000 * max( 3, $rows[ (int) floor( count( $rows ) / 2 ) ] );
	}

	private function note_failure( $route, $message ) {
		$probe = get_option( self::PROBE );
		$probe = is_array( $probe ) ? $probe : array( 'routes' => array() );
		$probe['routes'][ $route ] = array_merge( isset( $probe['routes'][ $route ] ) ? (array) $probe['routes'][ $route ] : array(), array( 'ok' => false, 'detail' => substr( (string) $message, 0, 300 ), 'failed_at' => time() ) );
		update_option( self::PROBE, $probe, false );
		$this->plugin->queue->push( 'route_probe', array(), array( 'unique' => true, 'priority' => 5, 'delay' => 300 ) );
	}

	public function label( $route ) {
		$l = array( 'direct' => __( 'Direct', 'seolity' ), 'cloudflare' => 'Cloudflare', 'github' => 'GitHub' );
		return isset( $l[ $route ] ) ? $l[ $route ] : $route;
	}

	/** Everything the "Server & speed" screen shows. */
	public function status() {
		$probe = get_option( self::PROBE );
		$order = $this->order();
		return array(
			'region'       => $this->region(),
			'region_set'   => $this->region_is_set(),
			'guess'        => $this->guess_region(),
			'mode'         => $this->mode(),
			'route_google' => (bool) $this->plugin->settings->get( 'route_google' ),
			'available'    => $this->available(),
			'order'        => $order,
			'current'      => $this->current(),
			'provider'     => (string) $this->plugin->settings->get( 'ai_provider' ),
			'probe'        => is_array( $probe ) ? $probe : null,
			'warm_minutes' => (int) $this->plugin->settings->get( 'sub_warm_minutes' ),
			'local'        => $this->plugin->localcli->status() + array(
				'path_claude' => (string) $this->plugin->settings->get( 'sub_cli_claude' ),
				'path_codex'  => (string) $this->plugin->settings->get( 'sub_cli_codex' ),
			),
			'runner'       => $this->plugin->subscription->missing( 'http' ) ? null : $this->plugin->subscription->warm_run(),
			'cloudflare'   => array(
				'deployed' => $this->plugin->cloudflare->is_deployed(),
				'url'      => $this->plugin->cloudflare->endpoint(),
				'outdated' => $this->plugin->cloudflare->is_deployed() && SEOLITY_VERSION !== (string) ( isset( $this->plugin->cloudflare->state()['version'] ) ? $this->plugin->cloudflare->state()['version'] : '' ),
				'custom'   => '' !== (string) $this->plugin->settings->get( 'cf_custom_url' ),
			),
		);
	}
}
