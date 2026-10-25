<?php
/**
 * Anthropic Claude provider (Messages API, native structured outputs).
 *
 * WordPress plugins cannot ship Composer dependencies reliably, so this uses
 * the documented REST shape through wp_remote_*.
 *
 * @package SEOlity
 */

namespace SEOlity\AI\Providers;

use SEOlity\AI\AbstractProvider;

defined( 'ABSPATH' ) || exit;

final class Anthropic extends AbstractProvider {

	const ENDPOINT = 'https://api.anthropic.com/v1/messages';

	public function id() {
		return 'anthropic';
	}

	public function label() {
		return 'Anthropic Claude';
	}

	/** Models with adaptive thinking + effort + refusal fallbacks (Claude 4.6+ / 5.x). */
	private function is_modern( $model ) {
		return (bool) preg_match( '/claude-(opus|sonnet|fable|mythos)-(4-[6-9]|5)/', $model );
	}

	private function supports_default_fallbacks( $model ) {
		return (bool) preg_match( '/claude-(opus-5|sonnet-5-5|fable-5-1)/', $model );
	}

	public function complete( array $request ) {
		$model = $this->model();
		$body  = array(
			'model'      => $model,
			'max_tokens' => (int) $request['max_tokens'],
			'system'     => (string) $request['system'],
			'messages'   => array_map(
				static function ( $m ) {
					return array(
						'role'    => 'assistant' === $m['role'] ? 'assistant' : 'user',
						'content' => (string) $m['content'],
					);
				},
				$request['messages']
			),
		);
		$headers = array(
			'x-api-key'         => $this->api_key(),
			'anthropic-version' => '2023-06-01',
		);

		$output_config = array();
		if ( $this->is_modern( $model ) ) {
			$output_config['effort'] = (string) $this->plugin->settings->get( 'ai_effort', 'medium' );
		}
		if ( ! empty( $request['schema'] ) ) {
			$output_config['format'] = array(
				'type'   => 'json_schema',
				'schema' => $request['schema'],
			);
		}
		if ( $output_config ) {
			$body['output_config'] = $output_config;
		}
		if ( $this->supports_default_fallbacks( $model ) ) {
			// Server-side refusal fallback: a false-positive safety decline is re-run on a suitable model.
			$headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
			$body['fallbacks']         = 'default';
		}

		$res = $this->post( self::ENDPOINT, $body, $headers );

		// Older models without structured-output support: retry with an inline schema instruction.
		if ( is_wp_error( $res ) && ! empty( $request['schema'] ) && false !== stripos( $res->get_error_message(), 'output_config' ) ) {
			unset( $body['output_config'], $body['fallbacks'] );
			unset( $headers['anthropic-beta'] );
			$body['system'] .= $this->schema_instruction( $request['schema'] );
			$res             = $this->post( self::ENDPOINT, $body, $headers );
		}
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$stop = isset( $res['stop_reason'] ) ? (string) $res['stop_reason'] : '';
		if ( 'refusal' === $stop ) {
			$why = isset( $res['stop_details']['explanation'] ) ? $res['stop_details']['explanation'] : '';
			return new \WP_Error( 'seolity_ai_refusal', trim( __( 'The model declined this request.', 'seolity' ) . ' ' . $why ) );
		}
		$text = '';
		foreach ( (array) ( isset( $res['content'] ) ? $res['content'] : array() ) as $block ) {
			if ( isset( $block['type'] ) && 'text' === $block['type'] ) {
				$text .= $block['text'];
			}
		}
		return array(
			'text'        => $text,
			'usage'       => array(
				'input'  => isset( $res['usage']['input_tokens'] ) ? (int) $res['usage']['input_tokens'] : 0,
				'output' => isset( $res['usage']['output_tokens'] ) ? (int) $res['usage']['output_tokens'] : 0,
			),
			'model'       => isset( $res['model'] ) ? $res['model'] : $model,
			'stop_reason' => $stop,
		);
	}

	/** Models API: https://docs.anthropic.com/en/api/models-list (paginated). */
	public function list_models() {
		$out   = array();
		$after = '';
		for ( $i = 0; $i < 10; $i++ ) {
			$url  = 'https://api.anthropic.com/v1/models?limit=1000' . ( '' !== $after ? '&after_id=' . rawurlencode( $after ) : '' );
			$body = $this->get( $url, array( 'x-api-key' => $this->api_key(), 'anthropic-version' => '2023-06-01' ) );
			if ( is_wp_error( $body ) ) {
				return $body;
			}
			foreach ( isset( $body['data'] ) ? (array) $body['data'] : array() as $m ) {
				if ( empty( $m['id'] ) ) {
					continue;
				}
				$out[] = array(
					'id'      => (string) $m['id'],
					'label'   => isset( $m['display_name'] ) ? (string) $m['display_name'] : (string) $m['id'],
					'created' => isset( $m['created_at'] ) ? (string) $m['created_at'] : '',
					'chat'    => true,
				);
			}
			if ( empty( $body['has_more'] ) || empty( $body['last_id'] ) ) {
				break;
			}
			$after = (string) $body['last_id'];
		}
		return $out;
	}
}
