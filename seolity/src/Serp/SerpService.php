<?php
/**
 * Live SERP data (SerpApi) + AI SERP analysis.
 *
 * Fetches the real Google results page for a keyword (organic results, People
 * Also Ask, related searches, SERP features) with the site owner's SerpApi key,
 * caches it for a day, and asks the AI to infer intent, winning format, title
 * patterns and what it takes to compete. Without a key the feature is disabled
 * (it never scrapes Google directly).
 *
 * @package SEOlity
 */

namespace SEOlity\Serp;

use SEOlity\Core\Http;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class SerpService {

	const ENDPOINT  = 'https://serpapi.com/search.json';
	const CACHE_TTL = DAY_IN_SECONDS;

	/** SerpApi keys that indicate a SERP feature, mapped to a readable name. */
	const FEATURES = array(
		'answer_box'       => 'featured_snippet',
		'knowledge_graph'  => 'knowledge_panel',
		'related_questions' => 'people_also_ask',
		'shopping_results' => 'shopping',
		'local_results'    => 'local_pack',
		'top_stories'      => 'top_stories',
		'inline_videos'    => 'videos',
		'inline_images'    => 'images',
		'ai_overview'      => 'ai_overview',
		'ads'              => 'ads',
	);

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function available() {
		return (bool) $this->plugin->settings->secret( 'serp_api_key' );
	}

	/** A SERP fetched earlier for this keyword (default market), without calling the API. */
	public function cached( $keyword ) {
		$keyword = trim( sanitize_text_field( (string) $keyword ) );
		if ( '' === $keyword ) {
			return null;
		}
		list( $gl, $hl ) = $this->locale( '' );
		$data            = get_transient( 'seolity_serp_' . md5( $keyword . '|' . $gl . '|' . $hl ) );
		return is_array( $data ) ? $data : null;
	}

	/** Google country + interface language for a market code. */
	private function locale( $market ) {
		$market = strtolower( (string) $market );
		if ( '' === $market ) {
			$lang   = (string) $this->plugin->settings->get( 'ai_content_language' );
			$market = 'fa' === $lang || ( 'auto' === $lang && 0 === strpos( get_locale(), 'fa' ) ) ? 'ir' : 'us';
		}
		$map = array( 'ir' => 'fa', 'us' => 'en', 'gb' => 'en', 'de' => 'de', 'fr' => 'fr', 'tr' => 'tr', 'ae' => 'ar', 'es' => 'es' );
		return array( preg_replace( '/[^a-z]/', '', $market ), isset( $map[ $market ] ) ? $map[ $market ] : 'en' );
	}

	/**
	 * Fetch and normalize the SERP for a keyword.
	 *
	 * @return array|\WP_Error {keyword, market, results[], questions[], related[], features[]}
	 */
	public function fetch( $keyword, $market = '' ) {
		$keyword = trim( sanitize_text_field( $keyword ) );
		if ( '' === $keyword ) {
			return new \WP_Error( 'seolity_serp_keyword', __( 'Enter a keyword.', 'seolity' ), array( 'status' => 400 ) );
		}
		$key = $this->plugin->settings->secret( 'serp_api_key' );
		if ( ! $key ) {
			return new \WP_Error( 'seolity_serp_key', __( 'Add a SERP API key (SerpApi) in Settings → Crawler & audits to analyze live search results.', 'seolity' ), array( 'status' => 400 ) );
		}
		list( $gl, $hl ) = $this->locale( $market );
		$cache_key       = 'seolity_serp_' . md5( $keyword . '|' . $gl . '|' . $hl );
		$cached          = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$url = add_query_arg(
			array(
				'engine'  => 'google',
				'q'       => rawurlencode( $keyword ),
				'gl'      => $gl,
				'hl'      => $hl,
				'num'     => 10,
				'api_key' => rawurlencode( (string) $key ),
			),
			self::ENDPOINT
		);
		$res = Http::json( 'GET', $url, null, array(), 45 );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( 200 !== $res['status'] || ! is_array( $res['body'] ) || ! empty( $res['body']['error'] ) ) {
			$msg = is_array( $res['body'] ) && ! empty( $res['body']['error'] ) ? (string) $res['body']['error'] : 'HTTP ' . $res['status'];
			/* translators: %s: error from the SERP API */
			return new \WP_Error( 'seolity_serp_api', sprintf( __( 'SERP API error: %s', 'seolity' ), $msg ), array( 'status' => 502 ) );
		}
		$data = self::normalize( $res['body'], $keyword, $gl );
		set_transient( $cache_key, $data, self::CACHE_TTL );
		return $data;
	}

	/** Reduce a SerpApi response to what the analysis needs. */
	public static function normalize( array $body, $keyword, $market ) {
		$results = array();
		foreach ( array_slice( isset( $body['organic_results'] ) ? (array) $body['organic_results'] : array(), 0, 10 ) as $r ) {
			if ( empty( $r['link'] ) ) {
				continue;
			}
			$results[] = array(
				'position' => isset( $r['position'] ) ? (int) $r['position'] : count( $results ) + 1,
				'title'    => isset( $r['title'] ) ? sanitize_text_field( $r['title'] ) : '',
				'url'      => esc_url_raw( $r['link'] ),
				'domain'   => (string) wp_parse_url( $r['link'], PHP_URL_HOST ),
				'snippet'  => isset( $r['snippet'] ) ? sanitize_text_field( $r['snippet'] ) : '',
			);
		}
		$questions = array();
		foreach ( isset( $body['related_questions'] ) ? (array) $body['related_questions'] : array() as $q ) {
			if ( ! empty( $q['question'] ) ) {
				$questions[] = sanitize_text_field( $q['question'] );
			}
		}
		$related = array();
		foreach ( isset( $body['related_searches'] ) ? (array) $body['related_searches'] : array() as $q ) {
			if ( ! empty( $q['query'] ) ) {
				$related[] = sanitize_text_field( $q['query'] );
			}
		}
		$features = array();
		foreach ( self::FEATURES as $field => $name ) {
			if ( ! empty( $body[ $field ] ) ) {
				$features[] = $name;
			}
		}
		return array(
			'keyword'   => $keyword,
			'market'    => $market,
			'results'   => $results,
			'questions' => array_slice( $questions, 0, 10 ),
			'related'   => array_slice( $related, 0, 10 ),
			'features'  => $features,
			'fetched'   => gmdate( 'c' ),
		);
	}

	/**
	 * Live SERP + AI analysis (+ where this site already ranks, from Search Console).
	 */
	public function analyze( $keyword, $market = '' ) {
		$serp = $this->fetch( $keyword, $market );
		if ( is_wp_error( $serp ) ) {
			return $serp;
		}
		$home  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$yours = array();
		foreach ( $serp['results'] as $r ) {
			if ( preg_replace( '/^www\./', '', $r['domain'] ) === preg_replace( '/^www\./', '', $home ) ) {
				$yours[] = array( 'url' => $r['url'], 'position' => $r['position'] );
			}
		}
		$gsc = array();
		if ( $this->plugin->gsc_oauth->is_connected() ) {
			foreach ( array_slice( $this->plugin->gsc_analytics->query_pages( $serp['keyword'], 90 ), 0, 5 ) as $p ) {
				$gsc[] = array( 'url' => $p['page'], 'position' => $p['position'], 'clicks' => $p['clicks'], 'impressions' => $p['impressions'] );
			}
		}
		$res = $this->plugin->ai->run(
			'serp_analysis',
			array(
				'keyword'          => $serp['keyword'],
				'market'           => $serp['market'],
				'results'          => $serp['results'],
				'people_also_ask'  => $serp['questions'],
				'related_searches' => $serp['related'],
				'serp_features'    => $serp['features'],
				'your_site'        => array( 'in_top_10' => $yours, 'search_console' => $gsc ),
			),
			array( 'knowledge_query' => $serp['keyword'] )
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array( 'serp' => $serp, 'yours' => $yours, 'gsc' => $gsc, 'analysis' => $res['data'], 'meta' => $res['meta'] );
	}
}
