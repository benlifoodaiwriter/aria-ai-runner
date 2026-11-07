<?php
/**
 * HTTP helpers: JSON requests, parallel fetches and SSRF protection.
 *
 * @package SEOlity
 */

namespace SEOlity\Core;

defined( 'ABSPATH' ) || exit;

final class Http {

	public static function user_agent() {
		return 'Mozilla/5.0 (compatible; SEOlityBot/' . SEOLITY_VERSION . '; +' . home_url( '/' ) . ')';
	}

	/**
	 * JSON request returning [status, decoded body, raw body, headers] or WP_Error.
	 *
	 * @param string $method  GET|POST|PUT|PATCH|DELETE.
	 * @param string $url     URL.
	 * @param array  $body    Body (JSON-encoded) or null.
	 * @param array  $headers Headers.
	 * @param int    $timeout Seconds.
	 * @return array|\WP_Error
	 */
	public static function json( $method, $url, $body = null, array $headers = array(), $timeout = 30 ) {
		$args = array(
			'method'  => $method,
			'timeout' => $timeout,
			'headers' => array_merge(
				array(
					'Accept'     => 'application/json',
					'User-Agent' => 'SEOlity/' . SEOLITY_VERSION . '; WordPress/' . get_bloginfo( 'version' ),
				),
				$headers
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = is_string( $body ) ? $body : wp_json_encode( $body );
		}
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$raw = wp_remote_retrieve_body( $response );
		return array(
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'body'    => json_decode( $raw, true ),
			'raw'     => $raw,
			'headers' => wp_remote_retrieve_headers( $response ),
		);
	}

	/**
	 * Reject URLs that resolve to private/reserved networks (SSRF guard) for
	 * user-supplied targets such as competitor domains or custom endpoints.
	 *
	 * @param string $url URL.
	 * @param bool   $allow_local Allow localhost (Ollama).
	 */
	public static function is_safe_public_url( $url, $allow_local = false ) {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) || empty( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return false;
		}
		if ( $allow_local ) {
			return true;
		}
		$host = $parts['host'];
		$ips  = filter_var( $host, FILTER_VALIDATE_IP ) ? array( $host ) : (array) gethostbynamel( $host );
		if ( ! $ips ) {
			return false;
		}
		foreach ( $ips as $ip ) {
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Fetch several URLs in parallel (WordPress' bundled Requests library).
	 * Redirects are NOT followed so the crawler can record 3xx chains.
	 *
	 * @param string[] $urls    URLs.
	 * @param int      $timeout Seconds.
	 * @param string   $method  GET|HEAD.
	 * @return array<string, array{status:int, body:string, headers:array, time_ms:int, error:?string, location:?string}>
	 */
	public static function multi( array $urls, $timeout = 15, $method = 'GET' ) {
		$requests = array();
		foreach ( array_values( $urls ) as $i => $url ) {
			$requests[ $i ] = array(
				'url'     => $url,
				'type'    => $method,
				'headers' => array(
					'User-Agent' => self::user_agent(),
					'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
				),
			);
		}
		$options = array(
			'timeout'          => $timeout,
			'connect_timeout'  => min( 10, $timeout ),
			'follow_redirects' => false,
			'verify'           => apply_filters( 'https_ssl_verify', true, '' ) ? ABSPATH . WPINC . '/certificates/ca-bundle.crt' : false,
		);
		$results  = array();
		$start    = microtime( true );
		$class    = class_exists( '\WpOrg\Requests\Requests' ) ? '\WpOrg\Requests\Requests' : ( class_exists( '\Requests' ) ? '\Requests' : null );
		$url_list = array_values( $urls );

		if ( $class && count( $requests ) > 1 ) {
			try {
				$responses = call_user_func( array( $class, 'request_multiple' ), $requests, $options );
				foreach ( $responses as $i => $res ) {
					$url = $url_list[ $i ];
					if ( $res instanceof \Exception ) {
						$results[ $url ] = self::error_result( $res->getMessage() );
						continue;
					}
					$headers = array();
					foreach ( $res->headers as $k => $v ) {
						$headers[ strtolower( $k ) ] = $v;
					}
					$results[ $url ] = array(
						'status'   => (int) $res->status_code,
						'body'     => (string) $res->body,
						'headers'  => $headers,
						'time_ms'  => (int) round( ( microtime( true ) - $start ) * 1000 ),
						'error'    => null,
						'location' => isset( $headers['location'] ) ? $headers['location'] : null,
					);
				}
				return $results;
			} catch ( \Exception $e ) {
				// Fall back to sequential below.
				unset( $e );
			}
		}

		foreach ( $url_list as $url ) {
			$t   = microtime( true );
			$res = wp_remote_request(
				$url,
				array(
					'method'      => $method,
					'timeout'     => $timeout,
					'redirection' => 0,
					'user-agent'  => self::user_agent(),
				)
			);
			if ( is_wp_error( $res ) ) {
				$results[ $url ] = self::error_result( $res->get_error_message() );
				continue;
			}
			$headers = array();
			foreach ( wp_remote_retrieve_headers( $res ) as $k => $v ) {
				$headers[ strtolower( $k ) ] = is_array( $v ) ? end( $v ) : $v;
			}
			$results[ $url ] = array(
				'status'   => (int) wp_remote_retrieve_response_code( $res ),
				'body'     => (string) wp_remote_retrieve_body( $res ),
				'headers'  => $headers,
				'time_ms'  => (int) round( ( microtime( true ) - $t ) * 1000 ),
				'error'    => null,
				'location' => isset( $headers['location'] ) ? $headers['location'] : null,
			);
		}
		return $results;
	}

	private static function error_result( $message ) {
		return array(
			'status'   => 0,
			'body'     => '',
			'headers'  => array(),
			'time_ms'  => 0,
			'error'    => $message,
			'location' => null,
		);
	}
}
