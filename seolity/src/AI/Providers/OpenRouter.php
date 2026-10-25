<?php
/**
 * OpenRouter (OpenAI-compatible, hundreds of models behind one key).
 *
 * @package SEOlity
 */

namespace SEOlity\AI\Providers;

defined( 'ABSPATH' ) || exit;

final class OpenRouter extends OpenAI {

	public function id() {
		return 'openrouter';
	}

	public function label() {
		return 'OpenRouter';
	}

	protected function endpoint() {
		return 'https://openrouter.ai/api/v1/chat/completions';
	}

	protected function headers() {
		return array(
			'Authorization' => 'Bearer ' . $this->api_key(),
			'HTTP-Referer'  => home_url( '/' ),
			'X-Title'       => 'SEOlity',
		);
	}

	protected static function is_chat_model( $id ) {
		return true;
	}

	/**
	 * Key check (GET /api/v1/key) + model catalog (GET /api/v1/models). OpenRouter lists every
	 * routable model; the key check proves the key, a test request proves a model.
	 */
	public function list_models() {
		$key = $this->get( 'https://openrouter.ai/api/v1/key', $this->headers() );
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		$body = $this->get( 'https://openrouter.ai/api/v1/models', $this->headers() );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		$free = ! empty( $key['data']['is_free_tier'] );
		$out  = array();
		foreach ( isset( $body['data'] ) ? (array) $body['data'] : array() as $m ) {
			if ( empty( $m['id'] ) ) {
				continue;
			}
			$outputs = isset( $m['architecture']['output_modalities'] ) ? (array) $m['architecture']['output_modalities'] : array( 'text' );
			$is_free = isset( $m['pricing']['prompt'] ) && 0.0 === (float) $m['pricing']['prompt'] && 0.0 === (float) ( isset( $m['pricing']['completion'] ) ? $m['pricing']['completion'] : 0 );
			$out[]   = array(
				'id'      => (string) $m['id'],
				'label'   => isset( $m['name'] ) ? (string) $m['name'] : (string) $m['id'],
				'created' => isset( $m['created'] ) ? gmdate( 'Y-m-d', (int) $m['created'] ) : '',
				'context' => isset( $m['context_length'] ) ? (int) $m['context_length'] : 0,
				'chat'    => in_array( 'text', $outputs, true ),
				'note'    => $free && ! $is_free ? __( 'needs credits (your key is on the free tier)', 'seolity' ) : ( $is_free ? __( 'free', 'seolity' ) : '' ),
			);
		}
		return $out;
	}
}
