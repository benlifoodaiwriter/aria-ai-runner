<?php
/**
 * Contract every AI provider implements.
 *
 * @package SEOlity
 */

namespace SEOlity\AI;

defined( 'ABSPATH' ) || exit;

interface ProviderInterface {

	/** Machine id (openai, anthropic …). */
	public function id();

	/** Human label. */
	public function label();

	/** Whether credentials/config are present. */
	public function is_configured();

	/** Model that will be used. */
	public function model();

	/**
	 * Run a completion.
	 *
	 * @param array $request {
	 *   @type string     $system      System prompt.
	 *   @type array      $messages    [ ['role' => 'user'|'assistant', 'content' => string], … ].
	 *   @type array|null $schema      JSON Schema for structured output (null = free text).
	 *   @type string     $schema_name Schema name.
	 *   @type int        $max_tokens  Output token cap.
	 * }
	 * @return array{text:string, usage:array{input:int,output:int}, model:string, stop_reason:string}|\WP_Error
	 */
	public function complete( array $request );
}
