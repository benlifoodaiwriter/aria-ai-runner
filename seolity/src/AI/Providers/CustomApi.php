<?php
/**
 * Custom API: use any AI service with exactly the settings its documentation gives.
 *
 * Everything that differs between services is configurable:
 *  - API format: OpenAI-compatible Chat Completions (DeepSeek, Groq, Mistral,
 *    Together, Fireworks, xAI, Perplexity, Gemini's OpenAI endpoint, Cloudflare
 *    Workers AI, Azure OpenAI, LM Studio, vLLM, LiteLLM, OpenRouter-style
 *    gateways…) or Anthropic-compatible Messages.
 *  - Base URL (full endpoint URLs are accepted too; {ACCOUNT_ID} is filled in).
 *  - Authentication: Bearer, x-api-key, api-key (Azure), any header, a query
 *    parameter, or none (local servers).
 *  - Extra headers and query parameters (e.g. api-version=2024-10-21).
 *  - Structured output: json_schema, json_object, prompt-only, or auto (tries
 *    them in that order and remembers what this endpoint + model accepts).
 *  - Token limit parameter (max_tokens / max_completion_tokens / none) and
 *    temperature (omitted unless set — reasoning models reject it).
 * Reasoning text (<think>…</think>) is stripped from answers.
 *
 * @package SEOlity
 */

namespace SEOlity\AI\Providers;

use SEOlity\AI\AbstractProvider;
use SEOlity\Core\Http;

defined( 'ABSPATH' ) || exit;

final class CustomApi extends AbstractProvider {

	/**
	 * Ready-made settings for common services. base may contain {ACCOUNT_ID},
	 * {RESOURCE} or {DEPLOYMENT} placeholders the user replaces.
	 */
	const PRESETS = array(
		'custom'      => array( 'label' => 'Custom', 'format' => 'openai', 'base' => '', 'auth' => 'bearer', 'json' => 'auto', 'models' => array() ),
		'deepseek'    => array( 'label' => 'DeepSeek', 'format' => 'openai', 'base' => 'https://api.deepseek.com/v1', 'auth' => 'bearer', 'json' => 'json_object', 'models' => array( 'deepseek-chat', 'deepseek-reasoner' ), 'keys' => 'https://platform.deepseek.com/api_keys', 'docs' => 'https://api-docs.deepseek.com/' ),
		'groq'        => array( 'label' => 'Groq', 'format' => 'openai', 'base' => 'https://api.groq.com/openai/v1', 'auth' => 'bearer', 'json' => 'json_object', 'models' => array( 'llama-3.3-70b-versatile', 'openai/gpt-oss-120b' ), 'keys' => 'https://console.groq.com/keys', 'docs' => 'https://console.groq.com/docs/openai' ),
		'mistral'     => array( 'label' => 'Mistral AI', 'format' => 'openai', 'base' => 'https://api.mistral.ai/v1', 'auth' => 'bearer', 'json' => 'json_object', 'models' => array( 'mistral-large-latest', 'mistral-small-latest' ), 'keys' => 'https://console.mistral.ai/api-keys', 'docs' => 'https://docs.mistral.ai/api/' ),
		'together'    => array( 'label' => 'Together AI', 'format' => 'openai', 'base' => 'https://api.together.xyz/v1', 'auth' => 'bearer', 'json' => 'json_object', 'models' => array( 'meta-llama/Llama-3.3-70B-Instruct-Turbo' ), 'keys' => 'https://api.together.ai/settings/api-keys', 'docs' => 'https://docs.together.ai/docs/openai-api-compatibility' ),
		'fireworks'   => array( 'label' => 'Fireworks AI', 'format' => 'openai', 'base' => 'https://api.fireworks.ai/inference/v1', 'auth' => 'bearer', 'json' => 'json_object', 'models' => array(), 'keys' => 'https://fireworks.ai/account/api-keys', 'docs' => 'https://docs.fireworks.ai/tools-sdks/openai-compatibility' ),
		'xai'         => array( 'label' => 'xAI (Grok)', 'format' => 'openai', 'base' => 'https://api.x.ai/v1', 'auth' => 'bearer', 'json' => 'json_schema', 'models' => array(), 'keys' => 'https://console.x.ai/', 'docs' => 'https://docs.x.ai/docs/api-reference' ),
		'perplexity'  => array( 'label' => 'Perplexity', 'format' => 'openai', 'base' => 'https://api.perplexity.ai', 'auth' => 'bearer', 'json' => 'json_schema', 'models' => array( 'sonar', 'sonar-pro' ), 'keys' => 'https://www.perplexity.ai/settings/api', 'docs' => 'https://docs.perplexity.ai/' ),
		'gemini'      => array( 'label' => 'Google Gemini (OpenAI-compatible)', 'format' => 'openai', 'base' => 'https://generativelanguage.googleapis.com/v1beta/openai', 'auth' => 'bearer', 'json' => 'json_schema', 'models' => array( 'gemini-2.5-flash', 'gemini-2.5-pro' ), 'keys' => 'https://aistudio.google.com/app/apikey', 'docs' => 'https://ai.google.dev/gemini-api/docs/openai' ),
		'cloudflare'  => array( 'label' => 'Cloudflare Workers AI', 'format' => 'openai', 'base' => 'https://api.cloudflare.com/client/v4/accounts/{ACCOUNT_ID}/ai/v1', 'auth' => 'bearer', 'json' => 'prompt', 'models' => array( '@cf/meta/llama-3.3-70b-instruct-fp8-fast', '@cf/qwen/qwen2.5-coder-32b-instruct' ), 'keys' => 'https://dash.cloudflare.com/profile/api-tokens', 'docs' => 'https://developers.cloudflare.com/workers-ai/configuration/open-ai-compatibility/' ),
		'azure'       => array( 'label' => 'Azure OpenAI', 'format' => 'openai', 'base' => 'https://{RESOURCE}.openai.azure.com/openai/deployments/{DEPLOYMENT}', 'auth' => 'api-key', 'query' => 'api-version=2024-10-21', 'json' => 'json_schema', 'models' => array(), 'docs' => 'https://learn.microsoft.com/azure/ai-services/openai/reference' ),
		'anthropic_compatible' => array( 'label' => 'Anthropic-compatible gateway', 'format' => 'anthropic', 'base' => '', 'auth' => 'x-api-key', 'json' => 'prompt', 'models' => array() ),
		'lmstudio'    => array( 'label' => 'LM Studio (local)', 'format' => 'openai', 'base' => 'http://localhost:1234/v1', 'auth' => 'none', 'json' => 'json_schema', 'models' => array(), 'docs' => 'https://lmstudio.ai/docs/app/api/endpoints/openai' ),
		'vllm'        => array( 'label' => 'vLLM (self-hosted)', 'format' => 'openai', 'base' => 'http://localhost:8000/v1', 'auth' => 'bearer', 'json' => 'json_schema', 'models' => array(), 'docs' => 'https://docs.vllm.ai/en/latest/serving/openai_compatible_server.html' ),
		'litellm'     => array( 'label' => 'LiteLLM proxy', 'format' => 'openai', 'base' => 'http://localhost:4000', 'auth' => 'bearer', 'json' => 'auto', 'models' => array(), 'docs' => 'https://docs.litellm.ai/docs/proxy/user_keys' ),
	);

	const JSON_CACHE = 'seolity_custom_json_mode';

	public function id() {
		return 'custom';
	}

	public function label() {
		$l = trim( (string) $this->plugin->settings->get( 'custom_label' ) );
		return '' !== $l ? $l : __( 'Custom API', 'seolity' );
	}

	private function opt( $key ) {
		return $this->plugin->settings->get( 'custom_' . $key );
	}

	public function is_configured() {
		$needs_key = 'none' !== $this->opt( 'auth' );
		return '' !== $this->model() && '' !== $this->base_url() && false === strpos( $this->base_url(), '{' ) && ( ! $needs_key || '' !== $this->api_key() );
	}

	/** Base URL with placeholders filled in where SEOlity knows them. */
	public function base_url() {
		$base = trim( (string) $this->opt( 'base_url' ) );
		$acc  = (string) $this->plugin->settings->get( 'cf_account_id' );
		if ( '' !== $acc ) {
			$base = str_replace( '{ACCOUNT_ID}', $acc, $base );
		}
		return untrailingslashit( $base );
	}

	/** Final request URL (accepts a base URL or a full endpoint URL). */
	public function endpoint() {
		$base = $this->base_url();
		$path = (string) wp_parse_url( $base, PHP_URL_PATH );
		if ( 'anthropic' === $this->opt( 'format' ) ) {
			if ( ! preg_match( '#/messages$#', $path ) ) {
				$base .= preg_match( '#/v1$#', $path ) ? '/messages' : '/v1/messages';
			}
		} elseif ( ! preg_match( '#/chat/completions$#', $path ) ) {
			$base .= '/chat/completions';
		}
		return $this->with_query( $base );
	}

	/** Models listing URL for the "Fetch models" button. */
	public function models_url() {
		$base = $this->base_url();
		$base = preg_replace( '#/(chat/completions|messages)$#', '', $base );
		if ( 'anthropic' === $this->opt( 'format' ) && ! preg_match( '#/v1$#', (string) wp_parse_url( $base, PHP_URL_PATH ) ) ) {
			$base .= '/v1';
		}
		return $this->with_query( $base . '/models' );
	}

	private function with_query( $url ) {
		$args = array();
		$q    = trim( (string) $this->opt( 'query' ), " ?&\t\n" );
		if ( '' !== $q ) {
			parse_str( $q, $args );
		}
		if ( 'query' === $this->opt( 'auth' ) && '' !== $this->api_key() ) {
			$name          = trim( (string) $this->opt( 'auth_name' ) );
			$args[ '' !== $name ? $name : 'key' ] = $this->api_key();
		}
		return $args ? add_query_arg( array_map( 'rawurlencode', $args ), $url ) : $url;
	}

	protected function headers() {
		$h   = array();
		$key = $this->api_key();
		switch ( $this->opt( 'auth' ) ) {
			case 'x-api-key':
				$h['x-api-key'] = $key;
				break;
			case 'api-key':
				$h['api-key'] = $key;
				break;
			case 'header':
				$name = trim( (string) $this->opt( 'auth_name' ) );
				if ( '' !== $name ) {
					$h[ $name ] = $key; // Other value formats ("Token {key}") go in the extra headers box.
				}
				break;
			case 'query':
			case 'none':
				break;
			default:
				if ( '' !== $key ) {
					$h['Authorization'] = 'Bearer ' . $key;
				}
		}
		if ( 'anthropic' === $this->opt( 'format' ) ) {
			$h['anthropic-version'] = '2023-06-01';
		}
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $this->opt( 'headers' ) ) as $line ) {
			if ( preg_match( '/^\s*([A-Za-z0-9!#$%&\'*+.^_`|~-]+)\s*:\s*(.*?)\s*$/', $line, $m ) ) {
				$h[ $m[1] ] = str_replace( '{key}', $key, $m[2] );
			}
		}
		return $h;
	}

	private function temperature() {
		$t = trim( (string) $this->opt( 'temperature' ) );
		return '' !== $t && is_numeric( $t ) ? max( 0, min( 2, (float) $t ) ) : null;
	}

	private function json_cache_key() {
		return md5( $this->endpoint() . '|' . $this->model() );
	}

	/** Structured-output strategies to try, best first. */
	private function json_modes() {
		$mode = (string) $this->opt( 'json_mode' );
		if ( 'auto' !== $mode ) {
			return array( $mode );
		}
		$known = get_option( self::JSON_CACHE, array() );
		$hit   = is_array( $known ) && isset( $known[ $this->json_cache_key() ] ) ? $known[ $this->json_cache_key() ] : null;
		$all   = 'anthropic' === $this->opt( 'format' ) ? array( 'json_schema', 'prompt' ) : array( 'json_schema', 'json_object', 'prompt' );
		return $hit ? array_values( array_unique( array_merge( array( $hit ), $all ) ) ) : $all;
	}

	private function remember_json_mode( $mode ) {
		if ( 'auto' !== $this->opt( 'json_mode' ) ) {
			return;
		}
		$known = get_option( self::JSON_CACHE, array() );
		$known = is_array( $known ) ? array_slice( $known, -20, null, true ) : array();
		$known[ $this->json_cache_key() ] = $mode;
		update_option( self::JSON_CACHE, $known, false );
	}

	/** Remove model reasoning that some servers return inside the answer. */
	private static function clean( $text ) {
		$text = preg_replace( '#<think>.*?</think>#s', '', (string) $text );
		return trim( (string) $text );
	}

	public function complete( array $request ) {
		$url = $this->endpoint();
		if ( false !== strpos( $url, '{' ) ) {
			return new \WP_Error( 'seolity_ai_url', __( 'Replace the {placeholders} in the Custom API base URL with your own values.', 'seolity' ) );
		}
		if ( ! Http::is_safe_public_url( $url, (bool) apply_filters( 'seolity_custom_api_allow_local', true ) ) ) {
			return new \WP_Error( 'seolity_ai_url', __( 'Custom AI endpoint URL is not allowed.', 'seolity' ) );
		}
		$modes = empty( $request['schema'] ) ? array( 'none' ) : $this->json_modes();
		$last  = null;
		foreach ( $modes as $mode ) {
			$res = 'anthropic' === $this->opt( 'format' ) ? $this->anthropic( $request, $mode ) : $this->openai( $request, $mode );
			if ( ! is_wp_error( $res ) ) {
				if ( 'none' !== $mode ) {
					$this->remember_json_mode( $mode );
				}
				return $res;
			}
			$last = $res;
			// Only fall back when the server rejected the structured-output request itself.
			if ( ! preg_match( '/response_format|json_schema|json_object|output_config|structured|schema|not supported|unsupported|unknown (field|parameter)|extra (fields|inputs)|invalid.*param/i', $res->get_error_message() ) ) {
				break;
			}
		}
		return $last;
	}

	private function openai( array $request, $mode ) {
		$system = (string) $request['system'];
		if ( in_array( $mode, array( 'json_object', 'prompt' ), true ) ) {
			$system .= $this->schema_instruction( $request['schema'] );
		}
		$messages = array( array( 'role' => 'system', 'content' => $system ) );
		foreach ( $request['messages'] as $m ) {
			$messages[] = array( 'role' => 'assistant' === $m['role'] ? 'assistant' : 'user', 'content' => (string) $m['content'] );
		}
		$body  = array( 'model' => $this->model(), 'messages' => $messages );
		$param = (string) $this->opt( 'token_param' );
		$param = 'auto' === $param ? 'max_tokens' : $param;
		if ( 'none' !== $param ) {
			$body[ $param ] = (int) $request['max_tokens'];
		}
		if ( null !== $this->temperature() ) {
			$body['temperature'] = $this->temperature();
		}
		if ( 'json_schema' === $mode ) {
			$body['response_format'] = array(
				'type'        => 'json_schema',
				'json_schema' => array( 'name' => preg_replace( '/[^a-zA-Z0-9_-]/', '_', $request['schema_name'] ), 'schema' => $request['schema'], 'strict' => true ),
			);
		} elseif ( 'json_object' === $mode ) {
			$body['response_format'] = array( 'type' => 'json_object' );
		}
		$res = $this->post( $this->endpoint(), $body, $this->headers() );
		if ( is_wp_error( $res ) && 'auto' === $this->opt( 'token_param' ) && preg_match( '/max_tokens|max_completion_tokens/i', $res->get_error_message() ) ) {
			// Newer OpenAI-style models want max_completion_tokens: swap once.
			$body['max_completion_tokens'] = $body['max_tokens'];
			unset( $body['max_tokens'] );
			$res = $this->post( $this->endpoint(), $body, $this->headers() );
		}
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$choice  = isset( $res['choices'][0] ) ? $res['choices'][0] : array();
		$content = isset( $choice['message']['content'] ) ? $choice['message']['content'] : '';
		if ( is_array( $content ) ) {
			$content = implode( '', array_map( static function ( $part ) {
				return is_array( $part ) && isset( $part['text'] ) ? $part['text'] : ( is_string( $part ) ? $part : '' );
			}, $content ) );
		}
		if ( ! empty( $choice['message']['refusal'] ) ) {
			return new \WP_Error( 'seolity_ai_refusal', (string) $choice['message']['refusal'] );
		}
		if ( '' === trim( (string) $content ) && ! isset( $res['choices'] ) ) {
			return new \WP_Error( 'seolity_ai_parse', __( 'The Custom API answered, but not in the selected format. Check “API format”.', 'seolity' ) );
		}
		return array(
			'text'        => self::clean( $content ),
			'usage'       => array(
				'input'  => isset( $res['usage']['prompt_tokens'] ) ? (int) $res['usage']['prompt_tokens'] : 0,
				'output' => isset( $res['usage']['completion_tokens'] ) ? (int) $res['usage']['completion_tokens'] : 0,
			),
			'model'       => isset( $res['model'] ) ? (string) $res['model'] : $this->model(),
			'stop_reason' => isset( $choice['finish_reason'] ) ? (string) $choice['finish_reason'] : '',
		);
	}

	private function anthropic( array $request, $mode ) {
		$body = array(
			'model'      => $this->model(),
			'max_tokens' => (int) $request['max_tokens'],
			'system'     => (string) $request['system'] . ( 'prompt' === $mode ? $this->schema_instruction( $request['schema'] ) : '' ),
			'messages'   => array_map(
				static function ( $m ) {
					return array( 'role' => 'assistant' === $m['role'] ? 'assistant' : 'user', 'content' => (string) $m['content'] );
				},
				$request['messages']
			),
		);
		if ( null !== $this->temperature() ) {
			$body['temperature'] = min( 1, $this->temperature() );
		}
		if ( 'json_schema' === $mode ) {
			$body['output_config'] = array( 'format' => array( 'type' => 'json_schema', 'schema' => $request['schema'] ) );
		}
		$res = $this->post( $this->endpoint(), $body, $this->headers() );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( ! isset( $res['content'] ) ) {
			return new \WP_Error( 'seolity_ai_parse', __( 'The Custom API answered, but not in the selected format. Check “API format”.', 'seolity' ) );
		}
		$text = '';
		foreach ( (array) $res['content'] as $block ) {
			if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
				$text .= $block['text'];
			}
		}
		return array(
			'text'        => self::clean( $text ),
			'usage'       => array(
				'input'  => isset( $res['usage']['input_tokens'] ) ? (int) $res['usage']['input_tokens'] : 0,
				'output' => isset( $res['usage']['output_tokens'] ) ? (int) $res['usage']['output_tokens'] : 0,
			),
			'model'       => isset( $res['model'] ) ? (string) $res['model'] : $this->model(),
			'stop_reason' => isset( $res['stop_reason'] ) ? (string) $res['stop_reason'] : '',
		);
	}

	/**
	 * Model ids the endpoint offers (GET …/models).
	 *
	 * @return string[]|\WP_Error
	 */
	public function list_models() {
		$url = $this->models_url();
		if ( '' === $this->base_url() ) {
			return new \WP_Error( 'seolity_ai_url', __( 'Enter the base URL first.', 'seolity' ), array( 'status' => 400 ) );
		}
		if ( false !== strpos( $url, '{' ) ) {
			return new \WP_Error( 'seolity_ai_url', __( 'Replace the {placeholders} in the Custom API base URL with your own values.', 'seolity' ), array( 'status' => 400 ) );
		}
		if ( ! Http::is_safe_public_url( $url, (bool) apply_filters( 'seolity_custom_api_allow_local', true ) ) ) {
			return new \WP_Error( 'seolity_ai_url', __( 'Custom AI endpoint URL is not allowed.', 'seolity' ), array( 'status' => 400 ) );
		}
		$res = Http::json( 'GET', $url, null, $this->headers(), 30 );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( $res['status'] >= 400 ) {
			return new \WP_Error( 'seolity_ai_api', sprintf( __( 'The models list answered HTTP %1$d: %2$s', 'seolity' ), $res['status'], $this->extract_error( $res['body'], $res['raw'] ) ), array( 'status' => 400 ) );
		}
		$ids  = array();
		$list = is_array( $res['body'] ) ? ( isset( $res['body']['data'] ) ? $res['body']['data'] : ( isset( $res['body']['models'] ) ? $res['body']['models'] : ( isset( $res['body']['result'] ) ? $res['body']['result'] : $res['body'] ) ) ) : array();
		foreach ( (array) $list as $m ) {
			$id = is_array( $m ) ? ( isset( $m['id'] ) ? $m['id'] : ( isset( $m['name'] ) ? $m['name'] : '' ) ) : ( is_string( $m ) ? $m : '' );
			if ( '' !== $id ) {
				$ids[] = (string) $id;
			}
		}
		sort( $ids );
		return array_values( array_unique( $ids ) );
	}

	/** What will be sent (for the settings preview; never includes the key). */
	public function describe() {
		$mask = function ( $url ) {
			if ( 'query' !== $this->opt( 'auth' ) ) {
				return $url;
			}
			$name = trim( (string) $this->opt( 'auth_name' ) );
			return preg_replace( '/([?&]' . preg_quote( '' !== $name ? $name : 'key', '/' ) . '=)[^&]+/', '$1••••', $url );
		};
		return array(
			'endpoint'   => $mask( $this->endpoint() ),
			'models_url' => $mask( $this->models_url() ),
			'headers'    => array_keys( $this->headers() ),
			'configured' => $this->is_configured(),
			'presets'    => self::PRESETS,
		);
	}
}
