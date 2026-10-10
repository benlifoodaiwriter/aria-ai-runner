<?php
/**
 * The central AI SEO Engine used by every feature.
 *
 * Pipeline: task lookup → budget check → context assembly (policy, site
 * profile, brand voice, language, site-specific learnings, knowledge base
 * snippets) → cache → provider call with strict schema → JSON parse →
 * schema validation (+1 self-repair round) → usage logging → result with
 * full provenance (provider, model, prompt version, knowledge refs).
 *
 * @package SEOlity
 */

namespace SEOlity\AI;

use SEOlity\Analysis\Text;
use SEOlity\Core\Database;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class Engine {

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function provider( $id = null ) {
		$id = $id ? $id : (string) $this->plugin->settings->get( 'ai_provider' );
		return ProviderRegistry::get( $this->plugin, $id );
	}

	public function is_ready() {
		$p = $this->provider();
		return $p && $p->is_configured();
	}

	/** Month-to-date spend in USD. */
	public function month_spend() {
		return (float) $this->plugin->db->value(
			"SELECT COALESCE(SUM(cost_usd),0) FROM {ai_requests} WHERE created_at >= %s AND status = 'ok'",
			array( gmdate( 'Y-m-01 00:00:00' ) )
		);
	}

	public function usage_summary() {
		$db = $this->plugin->db;
		return array(
			'month_spend'   => round( $this->month_spend(), 4 ),
			'budget'        => (float) $this->plugin->settings->get( 'ai_monthly_budget' ),
			'requests_30d'  => (int) $db->value( 'SELECT COUNT(*) FROM {ai_requests} WHERE created_at >= %s', array( gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) ),
			'by_task'       => $db->rows( 'SELECT task, COUNT(*) AS requests, SUM(tokens_in) AS tokens_in, SUM(tokens_out) AS tokens_out, SUM(cost_usd) AS cost FROM {ai_requests} WHERE created_at >= %s GROUP BY task ORDER BY cost DESC', array( gmdate( 'Y-m-01 00:00:00' ) ) ),
			'errors'        => $db->rows( "SELECT task, error, created_at FROM {ai_requests} WHERE status <> 'ok' ORDER BY id DESC LIMIT 10" ),
		);
	}

	/**
	 * Run an AI task.
	 *
	 * @param string $task  Task name (see Tasks).
	 * @param array  $input Task input (JSON-serializable).
	 * @param array  $opts  provider, no_cache, knowledge_query, history (chat), max_tokens.
	 * @return array{data:array, meta:array}|\WP_Error
	 */
	public function run( $task, array $input, array $opts = array() ) {
		$def = Tasks::get( $task );
		if ( ! $def ) {
			return new \WP_Error( 'seolity_ai_task', sprintf( 'Unknown AI task: %s', $task ) );
		}
		$provider = $this->provider( isset( $opts['provider'] ) ? $opts['provider'] : null );
		if ( ! $provider || ! $provider->is_configured() ) {
			return new \WP_Error( 'seolity_ai_not_configured', __( 'No AI provider is configured. Add an API key in SEOlity → Settings → AI.', 'seolity' ), array( 'status' => 400 ) );
		}
		$budget = (float) $this->plugin->settings->get( 'ai_monthly_budget' );
		if ( $budget > 0 && $this->month_spend() >= $budget ) {
			return new \WP_Error( 'seolity_ai_budget', __( 'Monthly AI budget reached. Increase it in Settings or wait until next month.', 'seolity' ), array( 'status' => 402 ) );
		}

		$knowledge = array();
		if ( $this->plugin->settings->get( 'ai_use_knowledge' ) && ! empty( $def['topics'] ) ) {
			$query     = isset( $opts['knowledge_query'] ) ? $opts['knowledge_query'] : $this->knowledge_query( $input );
			$knowledge = $this->plugin->knowledge->retrieve( $query, $def['topics'], isset( $opts['knowledge_limit'] ) ? max( 1, min( 16, (int) $opts['knowledge_limit'] ) ) : 4 );
		}

		$system = $this->build_system( $def, $task, $knowledge, $input );
		$market = in_array( $task, self::MARKET_TASKS, true ) && empty( $opts['no_market'] ) ? $this->market_context( $input, isset( $opts['knowledge_query'] ) ? $opts['knowledge_query'] : '' ) : '';
		if ( '' !== $market ) {
			$system = str_replace( "\nTASK INSTRUCTIONS:\n", "\n" . $market . "\n\nTASK INSTRUCTIONS:\n", $system );
		}
		$messages = array();
		if ( ! empty( $opts['history'] ) ) {
			foreach ( $opts['history'] as $m ) {
				$messages[] = array( 'role' => $m['role'], 'content' => $m['content'] );
			}
		}
		$messages[] = array(
			'role'    => 'user',
			'content' => "TASK: {$task}\nINPUT (JSON):\n" . wp_json_encode( $input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ),
		);

		$request = array(
			'system'      => $system,
			'messages'    => $messages,
			'schema'      => $def['schema'],
			'schema_name' => $task,
			'max_tokens'  => isset( $opts['max_tokens'] ) ? (int) $opts['max_tokens'] : min( (int) $def['max_tokens'], max( 2000, (int) $this->plugin->settings->get( 'ai_max_tokens' ) * 2 ) ),
		);

		$cache_key = sha1( $def['version'] . '|' . $provider->id() . '|' . $provider->model() . '|' . wp_json_encode( $request ) );
		$use_cache = ! empty( $def['cache'] ) && empty( $opts['no_cache'] ) && (int) $this->plugin->settings->get( 'ai_cache_hours' ) > 0;
		if ( $use_cache ) {
			$cached = $this->plugin->db->value( 'SELECT response FROM {ai_cache} WHERE cache_key = %s AND expires_at > %s', array( $cache_key, Database::now() ) );
			if ( $cached ) {
				$data = json_decode( $cached, true );
				if ( is_array( $data ) ) {
					return array(
						'data' => $data,
						'meta' => $this->meta( $provider, $def, $knowledge, array( 'input' => 0, 'output' => 0 ), true, array() ),
					);
				}
			}
		}

		$start  = microtime( true );
		$result = $provider->complete( $request );
		if ( is_wp_error( $result ) ) {
			$this->log( $provider, $provider->model(), $task, $def, array( 'input' => 0, 'output' => 0 ), $start, 'error', $result->get_error_message() );
			return $result;
		}
		$usage = $result['usage'];
		$data  = Schema::parse_json( $result['text'] );
		$errs  = null === $data ? array( 'Response is not valid JSON' ) : Schema::validate( $data, $def['schema'] );

		if ( $errs ) {
			// One self-repair round with the validation errors.
			$request['messages'][] = array( 'role' => 'assistant', 'content' => mb_substr( (string) $result['text'], 0, 20000 ) );
			$request['messages'][] = array( 'role' => 'user', 'content' => "Your output did not validate:\n- " . implode( "\n- ", array_slice( $errs, 0, 10 ) ) . "\nReturn the corrected, complete JSON object only." );
			$retry                 = $provider->complete( $request );
			if ( ! is_wp_error( $retry ) ) {
				$usage['input']  += $retry['usage']['input'];
				$usage['output'] += $retry['usage']['output'];
				$data             = Schema::parse_json( $retry['text'] );
				$errs             = null === $data ? array( 'Response is not valid JSON' ) : Schema::validate( $data, $def['schema'] );
			}
		}
		if ( $errs ) {
			$this->log( $provider, $result['model'], $task, $def, $usage, $start, 'invalid', implode( '; ', array_slice( $errs, 0, 5 ) ) );
			return new \WP_Error( 'seolity_ai_invalid', __( 'The AI response did not match the expected structure. Please try again.', 'seolity' ), array( 'errors' => array_slice( $errs, 0, 10 ) ) );
		}

		$this->log( $provider, $result['model'], $task, $def, $usage, $start, 'ok', null );
		if ( $use_cache ) {
			$this->plugin->db->upsert(
				'ai_cache',
				array(
					'cache_key'  => $cache_key,
					'task'       => $task,
					'response'   => wp_json_encode( $data, JSON_UNESCAPED_UNICODE ),
					'expires_at' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS * (int) $this->plugin->settings->get( 'ai_cache_hours' ) ),
				),
				array( 'response', 'expires_at' )
			);
		}
		return array(
			'data' => $data,
			'meta' => $this->meta( $provider, $def, $knowledge, $usage, false, array(), $result['model'] ),
		);
	}

	private function meta( ProviderInterface $provider, array $def, array $knowledge, array $usage, $cached, array $warnings, $model = null ) {
		return array(
			'provider'       => $provider->id(),
			'model'          => $model ? $model : $provider->model(),
			'prompt_version' => $def['version'],
			'cached'         => (bool) $cached,
			'tokens'         => $usage,
			'warnings'       => $warnings,
			'knowledge_refs' => array_values( array_unique( array_map( static function ( $k ) {
				return $k['title'];
			}, $knowledge ) ) ),
		);
	}

	/** Tasks that write or judge SEO content and therefore get competitor/SERP/Search Console evidence. */
	const MARKET_TASKS = array( 'optimize_content', 'meta_tags', 'product_seo', 'image_alt', 'ctr_rewrite', 'faq_generate', 'content_brief', 'keyword_research', 'keyword_plan', 'eeat_analysis', 'schema_suggest', 'content_plan', 'search_intent' );

	/**
	 * Evidence from the market for the topic of this task: what ranks (cached SERP, People Also Ask),
	 * how tracked competitors cover it, and the queries this site already earns impressions for.
	 * Uses stored data only — never calls a paid API on its own.
	 */
	public function market_context( array $input, $query = '' ) {
		$query = trim( '' !== $query ? (string) $query : $this->knowledge_query( $input ) );
		if ( '' === $query ) {
			return '';
		}
		$focus = $query;
		foreach ( array( 'focus_keyword', 'keyword' ) as $k ) {
			if ( ! empty( $input[ $k ] ) && is_string( $input[ $k ] ) ) {
				$focus = $input[ $k ];
				break;
			}
		}
		$focus = trim( mb_substr( $focus, 0, 80 ) );
		$lines = array();
		$serp  = $this->plugin->serp->cached( $focus );
		if ( $serp && ! empty( $serp['results'] ) ) {
			$lines[] = '- Google top results for "' . $focus . '":';
			foreach ( array_slice( $serp['results'], 0, 6 ) as $r ) {
				$lines[] = '  ' . $r['position'] . '. ' . $r['title'] . ' (' . $r['domain'] . ')';
			}
			if ( ! empty( $serp['questions'] ) ) {
				$lines[] = '- People also ask: ' . implode( ' | ', array_slice( $serp['questions'], 0, 6 ) );
			}
			if ( ! empty( $serp['features'] ) ) {
				$lines[] = '- SERP features: ' . implode( ', ', $serp['features'] );
			}
		}
		$db    = $this->plugin->db;
		$terms = array_slice( array_filter( Text::words( $focus ), static function ( $w ) {
			return mb_strlen( $w ) > 2;
		} ), 0, 3 );
		if ( $terms ) {
			$where = array();
			$args  = array( $db->project_id() );
			foreach ( $terms as $w ) {
				$where[] = '(p.title LIKE %s OR p.h1 LIKE %s)';
				$like    = '%' . $db->wpdb->esc_like( $w ) . '%';
				$args[]  = $like;
				$args[]  = $like;
			}
			$rows = $db->rows( 'SELECT c.domain, p.title, p.word_count, p.faq_count, p.schema_types FROM {competitor_pages} p JOIN {competitors} c ON c.id = p.competitor_id WHERE c.project_id = %d AND p.status_code = 200 AND ' . implode( ' AND ', $where ) . ' ORDER BY p.word_count DESC LIMIT 5', $args );
			if ( $rows ) {
				$words   = array_map( 'intval', wp_list_pluck( $rows, 'word_count' ) );
				$lines[] = '- Competitor pages on this topic (median ' . (int) $words[ (int) floor( count( $words ) / 2 ) ] . ' words):';
				foreach ( $rows as $r ) {
					$lines[] = '  · ' . $r['domain'] . ': "' . $r['title'] . '" — ' . (int) $r['word_count'] . ' words' . ( (int) $r['faq_count'] ? ', FAQ ' . (int) $r['faq_count'] : '' ) . ( $r['schema_types'] ? ', schema ' . $r['schema_types'] : '' );
				}
			}
		}
		if ( $this->plugin->gsc_oauth->is_connected() && method_exists( $this->plugin->gsc_analytics, 'query_pages' ) ) {
			$pages = array_slice( (array) $this->plugin->gsc_analytics->query_pages( $focus, 90 ), 0, 3 );
			foreach ( $pages as $p ) {
				$lines[] = '- This site in Search Console for "' . $focus . '": ' . $p['page'] . ' — position ' . round( (float) $p['position'], 1 ) . ', ' . (int) $p['impressions'] . ' impressions, ' . (int) $p['clicks'] . ' clicks';
			}
		}
		if ( ! $lines ) {
			return '';
		}
		return "MARKET EVIDENCE (real data — match or beat what ranks, cover the questions searchers ask, never copy competitor wording):\n" . implode( "\n", $lines );
	}

	private function knowledge_query( array $input ) {
		$parts = array();
		foreach ( array( 'focus_keyword', 'keyword', 'query', 'question', 'title', 'field', 'object_type' ) as $k ) {
			if ( ! empty( $input[ $k ] ) && is_string( $input[ $k ] ) ) {
				$parts[] = $input[ $k ];
			}
		}
		return implode( ' ', $parts );
	}

	private function build_system( array $def, $task, array $knowledge, array $input ) {
		$s        = $this->plugin->settings;
		$lang     = (string) $s->get( 'ai_content_language' );
		$language = 'auto' === $lang ? 'the same language as the input content (Persian content → fluent, natural Persian with correct half-spaces/ZWNJ)' : $lang;
		$parts    = array(
			Guardrails::policy(),
			'',
			'SITE PROFILE:',
			'- Name: ' . get_bloginfo( 'name' ),
			'- URL: ' . home_url( '/' ),
			'- Tagline: ' . get_bloginfo( 'description' ),
			'- Locale: ' . get_locale(),
			'- E-commerce: ' . ( class_exists( 'WooCommerce' ) ? 'WooCommerce store' : 'no' ),
			'- Output language: ' . $language,
		);
		$voice = trim( (string) $s->get( 'ai_brand_voice' ) );
		if ( '' !== $voice ) {
			$parts[] = '- Brand voice / editorial rules: ' . $voice;
		}
		$insights = $this->plugin->learning->prompt_insights();
		if ( $insights ) {
			$parts[] = '';
			$parts[] = 'SITE-SPECIFIC LEARNINGS (from measured SEO experiments on this site — prefer what worked here):';
			foreach ( $insights as $line ) {
				$parts[] = '- ' . $line;
			}
		}
		if ( $knowledge ) {
			$parts[] = '';
			$parts[] = 'SEO KNOWLEDGE BASE (curated best practices; apply when relevant, do not quote verbatim):';
			foreach ( $knowledge as $k ) {
				$parts[] = '### ' . $k['title'];
				$parts[] = mb_substr( $k['content'], 0, count( $knowledge ) > 6 ? 1400 : 1800 );
			}
		}
		$parts[] = '';
		$parts[] = 'TASK INSTRUCTIONS:';
		$parts[] = $def['instructions'];
		return implode( "\n", $parts );
	}

	private function log( ProviderInterface $provider, $model, $task, array $def, array $usage, $start, $status, $error ) {
		$price = ProviderRegistry::price( $model );
		$this->plugin->db->insert(
			'ai_requests',
			array(
				'provider'       => $provider->id(),
				'model'          => substr( (string) $model, 0, 100 ),
				'task'           => $task,
				'prompt_version' => $def['version'],
				'tokens_in'      => (int) $usage['input'],
				'tokens_out'     => (int) $usage['output'],
				'cost_usd'       => round( ( $usage['input'] * $price[0] + $usage['output'] * $price[1] ) / 1000000, 5 ),
				'duration_ms'    => (int) round( ( microtime( true ) - $start ) * 1000 ),
				'status'         => $status,
				'error'          => $error ? substr( $error, 0, 1000 ) : null,
				'user_id'        => get_current_user_id(),
				'created_at'     => Database::now(),
			)
		);
	}

	/** Lightweight connectivity test used by Settings → "Test connection". */
	public function test( $provider_id = null ) {
		$res = $this->run(
			'search_intent',
			array( 'query' => 'best espresso machine for home' ),
			array( 'provider' => $provider_id, 'no_cache' => true, 'max_tokens' => 1500 )
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array(
			'ok'    => true,
			'model' => $res['meta']['model'],
			'reply' => $res['data'],
		);
	}
}
