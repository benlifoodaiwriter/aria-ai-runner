<?php
/**
 * Google Gemini provider (generateContent with JSON schema output).
 *
 * @package SEOlity
 */

namespace SEOlity\AI\Providers;

use SEOlity\AI\AbstractProvider;

defined( 'ABSPATH' ) || exit;

final class Gemini extends AbstractProvider {

	public function id() {
		return 'gemini';
	}

	public function label() {
		return 'Google Gemini';
	}

	public function complete( array $request ) {
		$contents = array();
		foreach ( $request['messages'] as $m ) {
			$contents[] = array(
				'role'  => 'assistant' === $m['role'] ? 'model' : 'user',
				'parts' => array( array( 'text' => (string) $m['content'] ) ),
			);
		}
		$body = array(
			'systemInstruction' => array( 'parts' => array( array( 'text' => (string) $request['system'] ) ) ),
			'contents'          => $contents,
			'generationConfig'  => array( 'maxOutputTokens' => (int) $request['max_tokens'] ),
		);
		if ( ! empty( $request['schema'] ) ) {
			$body['generationConfig']['responseMimeType']   = 'application/json';
			$body['generationConfig']['responseJsonSchema'] = $request['schema'];
		}
		$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $this->model() ) . ':generateContent';
		$res = $this->post( $url, $body, array( 'x-goog-api-key' => $this->api_key() ) );

		if ( is_wp_error( $res ) && ! empty( $request['schema'] ) && false !== stripos( $res->get_error_message(), 'responseJsonSchema' ) ) {
			unset( $body['generationConfig']['responseJsonSchema'] );
			$body['systemInstruction']['parts'][0]['text'] .= $this->schema_instruction( $request['schema'] );
			$res = $this->post( $url, $body, array( 'x-goog-api-key' => $this->api_key() ) );
		}
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$candidate = isset( $res['candidates'][0] ) ? $res['candidates'][0] : array();
		$finish    = isset( $candidate['finishReason'] ) ? (string) $candidate['finishReason'] : '';
		if ( in_array( $finish, array( 'SAFETY', 'PROHIBITED_CONTENT', 'BLOCKLIST' ), true ) ) {
			return new \WP_Error( 'seolity_ai_refusal', __( 'Gemini blocked this response for safety reasons.', 'seolity' ) );
		}
		$text = '';
		foreach ( (array) ( isset( $candidate['content']['parts'] ) ? $candidate['content']['parts'] : array() ) as $part ) {
			if ( isset( $part['text'] ) && empty( $part['thought'] ) ) {
				$text .= $part['text'];
			}
		}
		return array(
			'text'        => $text,
			'usage'       => array(
				'input'  => isset( $res['usageMetadata']['promptTokenCount'] ) ? (int) $res['usageMetadata']['promptTokenCount'] : 0,
				'output' => isset( $res['usageMetadata']['candidatesTokenCount'] ) ? (int) $res['usageMetadata']['candidatesTokenCount'] : 0,
			),
			'model'       => $this->model(),
			'stop_reason' => $finish,
		);
	}
}
