<?php
/**
 * Where Google results come from. Every source returns the same normalized shape
 * {results[position,title,url,domain,snippet], questions[], related[], features[]}.
 *
 * Scraping google.com from the server is not offered: it breaks Google's terms and is blocked
 * with CAPTCHAs. The options are official / commercial SERP APIs, Google's own Programmable
 * Search, and a no-key "your browser" import (a bookmarklet reads the results page you opened
 * yourself and sends it to SEOlity).
 *
 * @package SEOlity
 */

namespace SEOlity\Serp;

use SEOlity\Core\Http;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class Sources {

	/** Catalog shown in the settings: cost, what signing up needs, and the fields. */
	public static function catalog() {
		return array(
			'manual'     => array(
				'label'  => __( 'Your own browser (no key)', 'seolity' ),
				'cost'   => __( 'Free', 'seolity' ),
				'signup' => __( 'Nothing — no account, key, phone or card', 'seolity' ),
				'note'   => __( 'Search the query in Google yourself (ideally in an incognito window), then click the SEOlity bookmark: the results on that page are sent to SEOlity. Not automatic, but free and exact.', 'seolity' ),
				'fields' => array(),
				'url'    => '',
			),
			'serper'     => array(
				'label'  => 'Serper.dev',
				'cost'   => __( '2,500 free searches, then from about $1 per 1,000', 'seolity' ),
				'signup' => __( 'Email or Google account only — no phone, no card for the free searches', 'seolity' ),
				'note'   => __( 'Fast and cheap Google results API. Recommended.', 'seolity' ),
				'fields' => array( 'serper_api_key' ),
				'url'    => 'https://serper.dev/api-key',
			),
			'searchapi'  => array(
				'label'  => 'SearchApi.io',
				'cost'   => __( '100 free searches, then paid plans', 'seolity' ),
				'signup' => __( 'Email only', 'seolity' ),
				'note'   => __( 'Google results including AI Overview data.', 'seolity' ),
				'fields' => array( 'searchapi_api_key' ),
				'url'    => 'https://www.searchapi.io/',
			),
			'serpapi'    => array(
				'label'  => 'SerpApi',
				'cost'   => __( '100–250 free searches a month, then paid plans', 'seolity' ),
				'signup' => __( 'Email; the free plan may ask to verify a phone number', 'seolity' ),
				'note'   => __( 'The best-known Google results API, very complete.', 'seolity' ),
				'fields' => array( 'serp_api_key' ),
				'url'    => 'https://serpapi.com/manage-api-key',
			),
			'dataforseo' => array(
				'label'  => 'DataForSEO',
				'cost'   => __( 'Pay as you go (about $0.6–2 per 1,000 results), small first deposit', 'seolity' ),
				'signup' => __( 'Email; payment needed after the trial credit', 'seolity' ),
				'note'   => __( 'Cheapest at large volumes; uses login + API password.', 'seolity' ),
				'fields' => array( 'dataforseo_login', 'dataforseo_password' ),
				'url'    => 'https://app.dataforseo.com/api-access',
			),
			'google_cse' => array(
				'label'  => __( 'Google Programmable Search (official)', 'seolity' ),
				'cost'   => __( '100 free searches a day, then $5 per 1,000', 'seolity' ),
				'signup' => __( 'Google account + a Google Cloud API key and a search engine ID', 'seolity' ),
				'note'   => __( 'Google’s own API. Its order is close to, but not identical with, google.com.', 'seolity' ),
				'fields' => array( 'google_cse_key', 'google_cse_cx' ),
				'url'    => 'https://programmablesearchengine.google.com/',
			),
		);
	}

	private static function host( $url ) {
		return preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) );
	}

	private static function results( array $rows ) {
		$out = array();
		foreach ( $rows as $i => $r ) {
			if ( empty( $r['url'] ) ) {
				continue;
			}
			$out[] = array(
				'position' => isset( $r['position'] ) && $r['position'] ? (int) $r['position'] : count( $out ) + 1,
				'title'    => sanitize_text_field( isset( $r['title'] ) ? $r['title'] : '' ),
				'url'      => esc_url_raw( $r['url'] ),
				'domain'   => self::host( $r['url'] ),
				'snippet'  => sanitize_text_field( isset( $r['snippet'] ) ? $r['snippet'] : '' ),
			);
		}
		return $out;
	}

	private static function strings( $rows, $key ) {
		$out = array();
		foreach ( (array) $rows as $r ) {
			$v = is_array( $r ) ? ( isset( $r[ $key ] ) ? $r[ $key ] : '' ) : $r;
			if ( '' !== (string) $v ) {
				$out[] = sanitize_text_field( (string) $v );
			}
		}
		return array_slice( $out, 0, 10 );
	}

	/**
	 * Search one query.
	 *
	 * @return array|\WP_Error Normalized result.
	 */
	public static function search( Plugin $p, $source, $query, $gl, $hl, $num ) {
		$s = $p->settings;
		switch ( $source ) {
			case 'serper':
				$key = $s->secret( 'serper_api_key' );
				$res = Http::json( 'POST', 'https://google.serper.dev/search', array( 'q' => $query, 'gl' => $gl, 'hl' => $hl, 'num' => $num ), array( 'X-API-KEY' => (string) $key, 'Content-Type' => 'application/json' ), 45 );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				if ( 200 !== $res['status'] || ! is_array( $res['body'] ) ) {
					return self::api_error( 'Serper', $res );
				}
				$b = $res['body'];
				return array(
					'results'   => self::results( array_map( static function ( $r ) {
						return array( 'position' => isset( $r['position'] ) ? $r['position'] : 0, 'title' => isset( $r['title'] ) ? $r['title'] : '', 'url' => isset( $r['link'] ) ? $r['link'] : '', 'snippet' => isset( $r['snippet'] ) ? $r['snippet'] : '' );
					}, isset( $b['organic'] ) ? (array) $b['organic'] : array() ) ),
					'questions' => self::strings( isset( $b['peopleAlsoAsk'] ) ? $b['peopleAlsoAsk'] : array(), 'question' ),
					'related'   => self::strings( isset( $b['relatedSearches'] ) ? $b['relatedSearches'] : array(), 'query' ),
					'features'  => array_values( array_filter( array( ! empty( $b['answerBox'] ) ? 'featured_snippet' : '', ! empty( $b['knowledgeGraph'] ) ? 'knowledge_panel' : '', ! empty( $b['peopleAlsoAsk'] ) ? 'people_also_ask' : '', ! empty( $b['places'] ) ? 'local_pack' : '' ) ) ),
				);
			case 'searchapi':
				$url = add_query_arg( array( 'engine' => 'google', 'q' => rawurlencode( $query ), 'gl' => $gl, 'hl' => $hl, 'num' => $num ), 'https://www.searchapi.io/api/v1/search' );
				$res = Http::json( 'GET', $url, null, array( 'Authorization' => 'Bearer ' . $s->secret( 'searchapi_api_key' ) ), 45 );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				if ( 200 !== $res['status'] || ! is_array( $res['body'] ) ) {
					return self::api_error( 'SearchApi', $res );
				}
				return self::serpapi_shape( $res['body'] );
			case 'dataforseo':
				$auth = base64_encode( $s->get( 'dataforseo_login' ) . ':' . $s->secret( 'dataforseo_password' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
				$loc  = array( 'ir' => 2364, 'us' => 2840, 'gb' => 2826, 'de' => 2276, 'fr' => 2250, 'tr' => 2792, 'ae' => 2784, 'es' => 2724 );
				$task = array( array( 'keyword' => $query, 'language_code' => $hl, 'location_code' => isset( $loc[ $gl ] ) ? $loc[ $gl ] : 2840, 'depth' => $num ) );
				$res  = Http::json( 'POST', 'https://api.dataforseo.com/v3/serp/google/organic/live/advanced', $task, array( 'Authorization' => 'Basic ' . $auth, 'Content-Type' => 'application/json' ), 60 );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				$items = isset( $res['body']['tasks'][0]['result'][0]['items'] ) ? (array) $res['body']['tasks'][0]['result'][0]['items'] : null;
				if ( 200 !== $res['status'] || null === $items ) {
					return self::api_error( 'DataForSEO', $res );
				}
				$rows = array();
				$q    = array();
				$feat = array();
				foreach ( $items as $it ) {
					if ( 'organic' === $it['type'] ) {
						$rows[] = array( 'position' => $it['rank_group'], 'title' => $it['title'], 'url' => $it['url'], 'snippet' => isset( $it['description'] ) ? $it['description'] : '' );
					} elseif ( 'people_also_ask' === $it['type'] ) {
						$feat[] = 'people_also_ask';
						foreach ( (array) $it['items'] as $pa ) {
							$q[] = isset( $pa['title'] ) ? $pa['title'] : '';
						}
					} elseif ( in_array( $it['type'], array( 'featured_snippet', 'local_pack', 'knowledge_graph', 'ai_overview', 'video', 'images', 'top_stories' ), true ) ) {
						$feat[] = $it['type'];
					}
				}
				return array( 'results' => self::results( $rows ), 'questions' => self::strings( $q, '' ), 'related' => array(), 'features' => array_values( array_unique( $feat ) ) );
			case 'google_cse':
				$rows = array();
				for ( $start = 1; $start <= min( 11, $num ); $start += 10 ) {
					$url = add_query_arg( array( 'key' => rawurlencode( (string) $s->secret( 'google_cse_key' ) ), 'cx' => rawurlencode( (string) $s->get( 'google_cse_cx' ) ), 'q' => rawurlencode( $query ), 'gl' => $gl, 'hl' => $hl, 'num' => 10, 'start' => $start ), 'https://www.googleapis.com/customsearch/v1' );
					$res = Http::json( 'GET', $url, null, array(), 30 );
					if ( is_wp_error( $res ) ) {
						return $res;
					}
					if ( 200 !== $res['status'] ) {
						return self::api_error( 'Google Programmable Search', $res );
					}
					foreach ( isset( $res['body']['items'] ) ? (array) $res['body']['items'] : array() as $it ) {
						$rows[] = array( 'position' => count( $rows ) + 1, 'title' => $it['title'], 'url' => $it['link'], 'snippet' => isset( $it['snippet'] ) ? $it['snippet'] : '' );
					}
				}
				return array( 'results' => self::results( $rows ), 'questions' => array(), 'related' => array(), 'features' => array() );
			case 'manual':
				return new \WP_Error( 'seolity_serp_manual', __( 'The “your own browser” source is not automatic: search the query in Google and click the SEOlity bookmark on the results page.', 'seolity' ), array( 'status' => 400 ) );
			default: // serpapi.
				$url = add_query_arg( array( 'engine' => 'google', 'q' => rawurlencode( $query ), 'gl' => $gl, 'hl' => $hl, 'num' => $num, 'api_key' => rawurlencode( (string) $s->secret( 'serp_api_key' ) ) ), 'https://serpapi.com/search.json' );
				$res = Http::json( 'GET', $url, null, array(), 45 );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				if ( 200 !== $res['status'] || ! is_array( $res['body'] ) || ! empty( $res['body']['error'] ) ) {
					return self::api_error( 'SerpApi', $res );
				}
				return self::serpapi_shape( $res['body'] );
		}
	}

	/** SerpApi / SearchApi share this response layout. */
	private static function serpapi_shape( array $b ) {
		$feat = array();
		foreach ( SerpService::FEATURES as $field => $name ) {
			if ( ! empty( $b[ $field ] ) ) {
				$feat[] = $name;
			}
		}
		return array(
			'results'   => self::results( array_map( static function ( $r ) {
				return array( 'position' => isset( $r['position'] ) ? $r['position'] : 0, 'title' => isset( $r['title'] ) ? $r['title'] : '', 'url' => isset( $r['link'] ) ? $r['link'] : '', 'snippet' => isset( $r['snippet'] ) ? $r['snippet'] : '' );
			}, isset( $b['organic_results'] ) ? (array) $b['organic_results'] : array() ) ),
			'questions' => self::strings( isset( $b['related_questions'] ) ? $b['related_questions'] : array(), 'question' ),
			'related'   => self::strings( isset( $b['related_searches'] ) ? $b['related_searches'] : array(), 'query' ),
			'features'  => $feat,
		);
	}

	private static function api_error( $name, array $res ) {
		$msg = is_array( $res['body'] ) ? ( isset( $res['body']['error'] ) ? ( is_array( $res['body']['error'] ) ? wp_json_encode( $res['body']['error'] ) : $res['body']['error'] ) : ( isset( $res['body']['message'] ) ? $res['body']['message'] : ( isset( $res['body']['status_message'] ) ? $res['body']['status_message'] : '' ) ) ) : '';
		/* translators: 1 source 2 error */
		return new \WP_Error( 'seolity_serp_api', sprintf( __( '%1$s error: %2$s', 'seolity' ), $name, $msg ? $msg : 'HTTP ' . $res['status'] ), array( 'status' => 502 ) );
	}

	/** Is the chosen source set up? */
	public static function ready( Plugin $p, $source ) {
		$s = $p->settings;
		switch ( $source ) {
			case 'manual':
				return true;
			case 'serper':
				return (bool) $s->secret( 'serper_api_key' );
			case 'searchapi':
				return (bool) $s->secret( 'searchapi_api_key' );
			case 'dataforseo':
				return '' !== (string) $s->get( 'dataforseo_login' ) && (bool) $s->secret( 'dataforseo_password' );
			case 'google_cse':
				return (bool) $s->secret( 'google_cse_key' ) && '' !== (string) $s->get( 'google_cse_cx' );
		}
		return (bool) $s->secret( 'serp_api_key' );
	}
}
