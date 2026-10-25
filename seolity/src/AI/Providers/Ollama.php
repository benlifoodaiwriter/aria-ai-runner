<?php
/**
 * Local LLM via Ollama (/api/chat with JSON-schema "format").
 * Keeps all content on your own infrastructure — useful for privacy-sensitive sites.
 *
 * @package SEOlity
 */

namespace SEOlity\AI\Providers;

use SEOlity\AI\AbstractProvider;

defined( 'ABSPATH' ) || exit;

final class Ollama extends AbstractProvider {

	public function id() {
		return 'ollama';
	}

	public function label() {
		return __( 'Local LLM (Ollama)', 'seolity' );
	}

	public function is_configured() {
		return '' !== $this->model() && '' !== (string) $this->plugin->settings->get( 'ollama_base_url' );
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
			'model'    => $this->model(),
			'messages' => $messages,
			'stream'   => false,
			'options'  => array( 'num_predict' => (int) $request['max_tokens'] ),
		);
		if ( ! empty( $request['schema'] ) ) {
			$body['format'] = $request['schema'];
		}
		$url = untrailingslashit( (string) $this->plugin->settings->get( 'ollama_base_url' ) ) . '/api/chat';
		$res = $this->post( $url, $body, array() );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array(
			'text'        => isset( $res['message']['content'] ) ? (string) $res['message']['content'] : '',
			'usage'       => array(
				'input'  => isset( $res['prompt_eval_count'] ) ? (int) $res['prompt_eval_count'] : 0,
				'output' => isset( $res['eval_count'] ) ? (int) $res['eval_count'] : 0,
			),
			'model'       => $this->model(),
			'stop_reason' => isset( $res['done_reason'] ) ? (string) $res['done_reason'] : '',
		);
	}

	public function key_name() {
		return '';
	}

	public function has_key() {
		return true;
	}

	/** Models installed on the Ollama server (GET /api/tags). */
	public function list_models() {
		$url = untrailingslashit( (string) $this->plugin->settings->get( 'ollama_base_url' ) ) . '/api/tags';
		$res = \SEOlity\Core\Http::json( 'GET', $url, null, array(), 15 );
		if ( is_wp_error( $res ) ) {
			return new \WP_Error( 'seolity_ai_http', sprintf( __( 'Ollama could not be reached at %1$s: %2$s', 'seolity' ), $url, $res->get_error_message() ) );
		}
		if ( $res['status'] >= 400 || ! is_array( $res['body'] ) ) {
			return new \WP_Error( 'seolity_ai_api', sprintf( __( 'Ollama answered HTTP %d.', 'seolity' ), $res['status'] ) );
		}
		$out = array();
		foreach ( isset( $res['body']['models'] ) ? (array) $res['body']['models'] : array() as $m ) {
			if ( ! empty( $m['name'] ) ) {
				$out[] = array( 'id' => (string) $m['name'], 'label' => (string) $m['name'], 'chat' => true, 'note' => isset( $m['details']['parameter_size'] ) ? (string) $m['details']['parameter_size'] : '' );
			}
		}
		return $out;
	}
}
