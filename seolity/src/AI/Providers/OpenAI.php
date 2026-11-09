<?php
/**
 * OpenAI provider (Chat Completions + json_schema response format).
 * Also the base for OpenAI-compatible endpoints (OpenRouter, custom gateways).
 *
 * @package SEOlity
 */

namespace SEOlity\AI\Providers;

use SEOlity\AI\AbstractProvider;

defined( 'ABSPATH' ) || exit;

class OpenAI extends AbstractProvider {

	public function id() {
		return 'openai';
	}

	public function label() {
		return 'OpenAI';
	}

	protected function endpoint() {
		return 'https://api.openai.com/v1/chat/completions';
	}

	protected function headers() {
		return array( 'Authorization' => 'Bearer ' . $this->api_key() );
	}

	/** Reasoning effort in this provider's own parameter. */
	protected function apply_effort( array &$body ) {
		\SEOlity\AI\Effort::apply_openai( $body, $this->id(), $body['model'] );
	}

	/** Whether the endpoint supports response_format=json_schema. */
	protected function supports_json_schema() {
		return true;
	}

	public function complete( array $request ) {
		$messages = array(
			array(
				'role'    => 'system',
				'content' => (string) $request['system'],
			),
		);
		foreach ( $request['messages'] as $m ) {
			$messages[] = array(
				'role'    => 'assistant' === $m['role'] ? 'assistant' : 'user',
				'content' => (string) $m['content'],
			);
		}
		$body = array(
			'model'                 => $this->model(),
			'messages'              => $messages,
			'max_completion_tokens' => (int) $request['max_tokens'],
		);
		if ( ! empty( $request['schema'] ) ) {
			if ( $this->supports_json_schema() ) {
				$body['response_format'] = array(
					'type'        => 'json_schema',
					'json_schema' => array(
						'name'   => preg_replace( '/[^a-zA-Z0-9_-]/', '_', $request['schema_name'] ),
						'schema' => $request['schema'],
						'strict' => true,
					),
				);
			} else {
				$body['response_format']        = array( 'type' => 'json_object' );
				$body['messages'][0]['content'] .= $this->schema_instruction( $request['schema'] );
			}
		}
		$this->apply_effort( $body );
		$res = $this->post( $this->endpoint(), $body, $this->headers() );
		// A model that does not take the reasoning setting: ask again without it.
		if ( is_wp_error( $res ) && ( isset( $body['reasoning_effort'] ) || isset( $body['reasoning'] ) ) && preg_match( '/reasoning/i', $res->get_error_message() ) ) {
			unset( $body['reasoning_effort'], $body['reasoning'] );
			$res = $this->post( $this->endpoint(), $body, $this->headers() );
		}

		// Some compatible gateways reject json_schema or max_completion_tokens: degrade gracefully once.
		if ( is_wp_error( $res ) && preg_match( '/response_format|json_schema|max_completion_tokens/i', $res->get_error_message() ) ) {
			$body['max_tokens'] = $body['max_completion_tokens'];
			unset( $body['max_completion_tokens'] );
			if ( ! empty( $request['schema'] ) ) {
				$body['response_format']        = array( 'type' => 'json_object' );
				$body['messages'][0]['content'] = (string) $request['system'] . $this->schema_instruction( $request['schema'] );
			}
			$res = $this->post( $this->endpoint(), $body, $this->headers() );
		}
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$choice = isset( $res['choices'][0] ) ? $res['choices'][0] : array();
		if ( ! empty( $choice['message']['refusal'] ) ) {
			return new \WP_Error( 'seolity_ai_refusal', (string) $choice['message']['refusal'] );
		}
		return array(
			'text'        => isset( $choice['message']['content'] ) ? (string) $choice['message']['content'] : '',
			'usage'       => array(
				'input'  => isset( $res['usage']['prompt_tokens'] ) ? (int) $res['usage']['prompt_tokens'] : 0,
				'output' => isset( $res['usage']['completion_tokens'] ) ? (int) $res['usage']['completion_tokens'] : 0,
			),
			'model'       => isset( $res['model'] ) ? $res['model'] : $this->model(),
			'stop_reason' => isset( $choice['finish_reason'] ) ? (string) $choice['finish_reason'] : '',
		);
	}

	/** Models URL of the same API (…/v1/models). */
	protected function models_endpoint() {
		return preg_replace( '#/chat/completions$#', '/models', $this->endpoint() );
	}

	/** Text-generation models (the list also contains audio, image, embedding and moderation models). */
	protected static function is_chat_model( $id ) {
		$id = strtolower( (string) $id );
		if ( preg_match( '/(embedding|whisper|tts|dall-e|davinci|babbage|moderation|transcribe|realtime|audio|image|search-preview|computer-use|sora)/', $id ) ) {
			return false;
		}
		return (bool) preg_match( '/^(gpt-|o\d|chatgpt-|codex)/', $id );
	}

	/** Models API: https://platform.openai.com/docs/api-reference/models/list — only models this key can use. */
	public function list_models() {
		$body = $this->get( $this->models_endpoint(), $this->headers() );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		$out = array();
		foreach ( isset( $body['data'] ) ? (array) $body['data'] : array() as $m ) {
			if ( empty( $m['id'] ) ) {
				continue;
			}
			$out[] = array(
				'id'      => (string) $m['id'],
				'label'   => (string) $m['id'],
				'created' => isset( $m['created'] ) ? gmdate( 'Y-m-d', (int) $m['created'] ) : '',
				'chat'    => static::is_chat_model( $m['id'] ),
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( $b['created'], $a['created'] );
			}
		);
		return $out;
	}
}
