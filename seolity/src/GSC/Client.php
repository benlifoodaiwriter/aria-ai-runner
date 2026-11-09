<?php
/**
 * Google Search Console API client (Webmasters v3 + URL Inspection v1).
 *
 * @package SEOlity
 */

namespace SEOlity\GSC;

use SEOlity\Core\Http;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class Client {

	const BASE = 'https://www.googleapis.com/webmasters/v3';

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	private function request( $method, $url, $body = null ) {
		$headers = $this->plugin->gsc_oauth->auth_headers();
		if ( is_wp_error( $headers ) ) {
			return $headers;
		}
		$res = Http::json( $method, $url, $body, $headers, 60 );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( $res['status'] >= 400 ) {
			$msg = isset( $res['body']['error']['message'] ) ? $res['body']['error']['message'] : 'HTTP ' . $res['status'];
			return new \WP_Error( 429 === $res['status'] ? 'seolity_gsc_quota' : 'seolity_gsc_api', 'Search Console: ' . $msg, array( 'status' => $res['status'] ) );
		}
		return is_array( $res['body'] ) ? $res['body'] : array();
	}

	private function site() {
		return rawurlencode( (string) $this->plugin->settings->get( 'gsc_property' ) );
	}

	/** Properties the connected account can access. */
	public function sites() {
		$res = $this->request( 'GET', self::BASE . '/sites' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$out = array();
		foreach ( (array) ( isset( $res['siteEntry'] ) ? $res['siteEntry'] : array() ) as $s ) {
			if ( 'siteUnverifiedUser' === $s['permissionLevel'] ) {
				continue;
			}
			$out[] = array(
				'site_url'   => $s['siteUrl'],
				'permission' => $s['permissionLevel'],
				'matches'    => $this->matches_site( $s['siteUrl'] ),
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return (int) $b['matches'] - (int) $a['matches'];
			}
		);
		return $out;
	}

	private function matches_site( $property ) {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( 0 === strpos( $property, 'sc-domain:' ) ) {
			$domain = substr( $property, 10 );
			return $host === $domain || substr( $host, -strlen( '.' . $domain ) ) === '.' . $domain;
		}
		return wp_parse_url( $property, PHP_URL_HOST ) === $host;
	}

	/**
	 * Search Analytics query.
	 *
	 * @param array $body startDate, endDate, dimensions, rowLimit, startRow, dimensionFilterGroups, type, dataState.
	 */
	public function query( array $body ) {
		$body = array_merge( array( 'type' => 'web', 'dataState' => 'final' ), $body );
		return $this->request( 'POST', self::BASE . '/sites/' . $this->site() . '/searchAnalytics/query', $body );
	}

	public function sitemaps() {
		return $this->request( 'GET', self::BASE . '/sites/' . $this->site() . '/sitemaps' );
	}

	public function submit_sitemap( $url ) {
		return $this->request( 'PUT', self::BASE . '/sites/' . $this->site() . '/sitemaps/' . rawurlencode( $url ) );
	}

	/** URL Inspection (index status, canonical, mobile usability, rich results). */
	public function inspect( $url ) {
		return $this->request(
			'POST',
			'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect',
			array(
				'inspectionUrl' => $url,
				'siteUrl'       => (string) $this->plugin->settings->get( 'gsc_property' ),
				'languageCode'  => str_replace( '_', '-', get_locale() ),
			)
		);
	}
}
