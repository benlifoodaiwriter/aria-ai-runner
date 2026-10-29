<?php
/**
 * Visibility in AI search: asks AI answer engines a tracked query with live web search and
 * records which sources they cite — whether this site is among them, in which place, and which
 * competitors are cited instead. Uses the API keys already saved for the AI connections.
 *
 *  - gemini     : Gemini with Google Search grounding (groundingMetadata.groundingChunks).
 *  - anthropic  : Claude with the web search tool (web_search_tool_result blocks).
 *  - openai     : OpenAI Responses API with the web_search tool (url_citation annotations).
 *  - perplexity : Perplexity Sonar (search_results / citations).
 *
 * @package SEOlity
 */

namespace SEOlity\Serp;

use SEOlity\Core\Http;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class AiVisibility {

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public static function catalog() {
		return array(
			'gemini'     => array( 'label' => __( 'Google Gemini with Google Search', 'seolity' ), 'key' => 'gemini_api_key', 'cost' => __( 'Free tier with a daily limit (Google AI Studio key — Google account only, no phone or card)', 'seolity' ) ),
			'anthropic'  => array( 'label' => __( 'Claude with web search', 'seolity' ), 'key' => 'anthropic_api_key', 'cost' => __( 'Paid per search ($10 per 1,000) plus tokens', 'seolity' ) ),
			'openai'     => array( 'label' => __( 'ChatGPT web search (OpenAI API)', 'seolity' ), 'key' => 'openai_api_key', 'cost' => __( 'Paid per search plus tokens', 'seolity' ) ),
			'perplexity' => array( 'label' => 'Perplexity', 'key' => 'perplexity_api_key', 'cost' => __( 'Paid (a few dollars per 1,000 requests)', 'seolity' ) ),
		);
	}

	public function enabled() {
		$all = array_filter( array_map( 'trim', explode( ',', (string) $this->plugin->settings->get( 'ai_visibility_engines' ) ) ) );
		$ok  = array();
		foreach ( $all as $e ) {
			$c = self::catalog();
			if ( isset( $c[ $e ] ) && $this->plugin->settings->secret( $c[ $e ]['key'] ) ) {
				$ok[] = $e;
			}
		}
		return $ok;
	}

	private function model( $engine ) {
		$m = array( 'gemini' => 'gemini-2.5-flash', 'anthropic' => 'claude-haiku-4-5', 'openai' => 'gpt-4.1-mini', 'perplexity' => 'sonar' );
		// A small, cheap model is enough to see which sources the engine cites.
		return $m[ $engine ];
	}

	/**
	 * Ask one engine one query.
	 *
	 * @return array|\WP_Error {answer, sources[url,title]}
	 */
	public function ask( $engine, $query ) {
		$key    = (string) $this->plugin->settings->secret( self::catalog()[ $engine ]['key'] );
		$prompt = $query;
		switch ( $engine ) {
			case 'gemini':
				$res = Http::json( 'POST', 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $this->model( 'gemini' ) ) . ':generateContent', array( 'contents' => array( array( 'role' => 'user', 'parts' => array( array( 'text' => $prompt ) ) ) ), 'tools' => array( array( 'google_search' => new \stdClass() ) ) ), array( 'x-goog-api-key' => $key, 'Content-Type' => 'application/json' ), 60 );
				if ( is_wp_error( $res ) || 200 !== $res['status'] ) {
					return $this->err( 'Gemini', $res );
				}
				$c       = isset( $res['body']['candidates'][0] ) ? $res['body']['candidates'][0] : array();
				$answer  = '';
				foreach ( isset( $c['content']['parts'] ) ? (array) $c['content']['parts'] : array() as $part ) {
					$answer .= isset( $part['text'] ) ? $part['text'] : '';
				}
				$sources = array();
				// Grounding chunks carry a redirect URI and the source's domain as title.
				foreach ( isset( $c['groundingMetadata']['groundingChunks'] ) ? (array) $c['groundingMetadata']['groundingChunks'] : array() as $g ) {
					if ( isset( $g['web'] ) ) {
						$dom       = isset( $g['web']['title'] ) ? strtolower( $g['web']['title'] ) : '';
						$sources[] = array( 'url' => false !== strpos( $dom, '.' ) ? 'https://' . $dom . '/' : (string) $g['web']['uri'], 'title' => $dom );
					}
				}
				return array( 'answer' => $answer, 'sources' => $sources );
			case 'anthropic':
				$res = Http::json( 'POST', 'https://api.anthropic.com/v1/messages', array( 'model' => $this->model( 'anthropic' ), 'max_tokens' => 1500, 'messages' => array( array( 'role' => 'user', 'content' => $prompt ) ), 'tools' => array( array( 'type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 3 ) ) ), array( 'x-api-key' => $key, 'anthropic-version' => '2023-06-01', 'Content-Type' => 'application/json' ), 90 );
				if ( is_wp_error( $res ) || 200 !== $res['status'] ) {
					return $this->err( 'Claude', $res );
				}
				$answer  = '';
				$sources = array();
				foreach ( (array) $res['body']['content'] as $b ) {
					if ( 'text' === $b['type'] ) {
						$answer .= $b['text'];
						foreach ( isset( $b['citations'] ) ? (array) $b['citations'] : array() as $ci ) {
							if ( ! empty( $ci['url'] ) ) {
								$sources[] = array( 'url' => $ci['url'], 'title' => isset( $ci['title'] ) ? $ci['title'] : '' );
							}
						}
					} elseif ( 'web_search_tool_result' === $b['type'] && is_array( $b['content'] ) ) {
						foreach ( $b['content'] as $r ) {
							if ( ! empty( $r['url'] ) ) {
								$sources[] = array( 'url' => $r['url'], 'title' => isset( $r['title'] ) ? $r['title'] : '' );
							}
						}
					}
				}
				return array( 'answer' => $answer, 'sources' => $sources );
			case 'openai':
				$res = Http::json( 'POST', 'https://api.openai.com/v1/responses', array( 'model' => $this->model( 'openai' ), 'tools' => array( array( 'type' => 'web_search' ) ), 'input' => $prompt ), array( 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json' ), 90 );
				if ( is_wp_error( $res ) || 200 !== $res['status'] ) {
					return $this->err( 'OpenAI', $res );
				}
				$answer  = '';
				$sources = array();
				foreach ( isset( $res['body']['output'] ) ? (array) $res['body']['output'] : array() as $o ) {
					foreach ( isset( $o['content'] ) ? (array) $o['content'] : array() as $c ) {
						$answer .= isset( $c['text'] ) ? $c['text'] : '';
						foreach ( isset( $c['annotations'] ) ? (array) $c['annotations'] : array() as $a ) {
							if ( isset( $a['type'], $a['url'] ) && 'url_citation' === $a['type'] ) {
								$sources[] = array( 'url' => $a['url'], 'title' => isset( $a['title'] ) ? $a['title'] : '' );
							}
						}
					}
				}
				return array( 'answer' => $answer, 'sources' => $sources );
			case 'perplexity':
				$res = Http::json( 'POST', 'https://api.perplexity.ai/chat/completions', array( 'model' => 'sonar', 'messages' => array( array( 'role' => 'user', 'content' => $prompt ) ) ), array( 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json' ), 90 );
				if ( is_wp_error( $res ) || 200 !== $res['status'] ) {
					return $this->err( 'Perplexity', $res );
				}
				$b       = $res['body'];
				$sources = array();
				if ( ! empty( $b['search_results'] ) ) {
					foreach ( (array) $b['search_results'] as $r ) {
						$sources[] = array( 'url' => $r['url'], 'title' => isset( $r['title'] ) ? $r['title'] : '' );
					}
				} else {
					foreach ( isset( $b['citations'] ) ? (array) $b['citations'] : array() as $u ) {
						$sources[] = array( 'url' => (string) $u, 'title' => '' );
					}
				}
				return array( 'answer' => isset( $b['choices'][0]['message']['content'] ) ? (string) $b['choices'][0]['message']['content'] : '', 'sources' => $sources );
		}
		return new \WP_Error( 'seolity_aiv', __( 'Unknown AI search engine.', 'seolity' ) );
	}

	private function err( $name, $res ) {
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$b   = $res['body'];
		$msg = is_array( $b ) && isset( $b['error'] ) ? ( is_array( $b['error'] ) ? ( isset( $b['error']['message'] ) ? $b['error']['message'] : wp_json_encode( $b['error'] ) ) : $b['error'] ) : 'HTTP ' . $res['status'];
		return new \WP_Error( 'seolity_aiv', $name . ': ' . $msg );
	}

	/** Check the tracked queries in every enabled engine; store each answer as a snapshot. */
	public function check( array $queries = array(), $limit = 20 ) {
		$engines = $this->enabled();
		$queries = array_slice( $queries ? $queries : $this->plugin->intel->tracked_queries(), 0, $limit );
		$errors  = array();
		foreach ( $engines as $e ) {
			foreach ( $queries as $q ) {
				$this->plugin->queue->checkpoint();
				$recent = $this->plugin->db->value( 'SELECT id FROM {serp_snapshots} WHERE project_id = %d AND engine = %s AND query_hash = %s AND checked_at >= %s', array( $this->plugin->db->project_id(), 'ai:' . $e, sha1( $q ), gmdate( 'Y-m-d H:i:s', time() - 20 * HOUR_IN_SECONDS ) ) );
				if ( $recent ) {
					continue;
				}
				$r = $this->ask( $e, $q );
				if ( is_wp_error( $r ) ) {
					$errors[ $e ] = $r->get_error_message();
					break;
				}
				// Cited sources in citation order, one per domain.
				$seen = array();
				$rows = array();
				foreach ( $r['sources'] as $s ) {
					$d = preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( $s['url'], PHP_URL_HOST ) ) );
					if ( ! $d || isset( $seen[ $d ] ) ) {
						continue;
					}
					$seen[ $d ] = true;
					$rows[]     = array( 'position' => count( $rows ) + 1, 'title' => $s['title'] ? $s['title'] : $d, 'url' => $s['url'], 'domain' => $d, 'snippet' => '' );
				}
				$this->plugin->intel->snapshot( $q, array( 'results' => $rows ), 'api', 'ai:' . $e, mb_substr( (string) $r['answer'], 0, 4000 ) );
			}
		}
		update_option( 'seolity_ai_visibility_last', array( 'at' => gmdate( 'c' ), 'engines' => $engines, 'errors' => $errors ), false );
		return true;
	}

	/** Latest answer per query and engine: are we cited, in which place, who else. */
	public function overview() {
		$db   = $this->plugin->db;
		$rows = $db->rows( "SELECT s.* FROM {serp_snapshots} s JOIN (SELECT engine, query_hash, MAX(id) id FROM {serp_snapshots} WHERE project_id = %d AND engine LIKE 'ai:%%' GROUP BY engine, query_hash) l ON l.id = s.id ORDER BY s.query", array( $db->project_id() ) );
		$out  = array();
		foreach ( $rows as $s ) {
			$cited = $db->rows( 'SELECT position, domain, url, competitor_id, is_own FROM {serp_results} WHERE snapshot_id = %d ORDER BY position LIMIT 12', array( (int) $s['id'] ) );
			$q     = $s['query'];
			if ( ! isset( $out[ $q ] ) ) {
				$out[ $q ] = array( 'query' => $q, 'engines' => array() );
			}
			$out[ $q ]['engines'][ substr( $s['engine'], 3 ) ] = array(
				'cited_at'    => null !== $s['own_position'] ? (int) $s['own_position'] : null,
				'sources'     => $cited,
				'competitors' => array_values( array_filter( $cited, static function ( $c ) {
					return (int) $c['competitor_id'] > 0;
				} ) ),
				'answer'      => mb_substr( (string) $s['answer'], 0, 600 ),
				'checked_at'  => $s['checked_at'],
			);
		}
		return array_values( $out );
	}
}
