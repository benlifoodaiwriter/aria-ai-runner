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
		$res = $this->post( $this->endpoint(), $body, $this->headers() );

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
}
