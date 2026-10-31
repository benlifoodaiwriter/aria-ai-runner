<?php
/**
 * Reasoning effort per AI connection, translated into each provider's own documented parameter:
 * Anthropic output_config.effort, OpenAI reasoning_effort (reasoning models), OpenRouter
 * reasoning.effort, Gemini thinkingConfig (thinkingBudget for 2.5, thinkingLevel for 3),
 * Ollama "think", Claude Code MAX_THINKING_TOKENS and Codex model_reasoning_effort.
 * "auto" sends nothing, so the provider's own default applies.
 *
 * @package SEOlity
 */

namespace SEOlity\AI;

use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class Effort {

	/** Levels each connection really supports (first = default). */
	const LEVELS = array(
		'anthropic'     => array( 'auto', 'low', 'medium', 'high', 'xhigh', 'max' ),
		'openai'        => array( 'auto', 'minimal', 'low', 'medium', 'high' ),
		'github_models' => array( 'auto', 'low', 'medium', 'high' ),
		'openrouter'    => array( 'auto', 'low', 'medium', 'high' ),
		'gemini'        => array( 'auto', 'low', 'medium', 'high' ),
		'ollama'        => array( 'auto', 'off', 'on', 'low', 'medium', 'high' ),
		'custom'        => array( 'auto', 'low', 'medium', 'high' ),
		'sub_claude'    => array( 'auto', 'low', 'medium', 'high', 'max' ),
		'sub_codex'     => array( 'auto', 'minimal', 'low', 'medium', 'high' ),
	);

	/** Claude Code thinking budgets (tokens) per level. */
	const CLAUDE_CODE_TOKENS = array( 'low' => 4000, 'medium' => 10000, 'high' => 20000, 'max' => 31999 );

	/** Gemini 2.5 thinking budgets (tokens) per level. */
	const GEMINI_BUDGET = array( 'low' => 1024, 'medium' => 8192, 'high' => 24576 );

	public static function setting( $provider ) {
		return 'ai_effort_' . $provider;
	}

	public static function get( $provider ) {
		if ( ! isset( self::LEVELS[ $provider ] ) ) {
			return 'auto';
		}
		$v = (string) Plugin::instance()->settings->get( self::setting( $provider ), 'auto' );
		return in_array( $v, self::LEVELS[ $provider ], true ) ? $v : 'auto';
	}

	/** Levels with a short explanation of what each one does on this provider (for the UI). */
	public static function describe( $provider ) {
		$what = array(
			'anthropic'     => __( 'Sent as Anthropic “effort” (Claude 4.6 and newer). Higher = deeper reasoning, slower and more tokens. xhigh/max only on models that support them.', 'seolity' ),
			'openai'        => __( 'Sent as “reasoning_effort” to reasoning models (o-series, GPT-5). Other models ignore it.', 'seolity' ),
			'github_models' => __( 'Sent as “reasoning_effort” to reasoning models.', 'seolity' ),
			'openrouter'    => __( 'Sent as OpenRouter “reasoning.effort”; OpenRouter translates it for every model that can reason.', 'seolity' ),
			'gemini'        => __( 'Sent as Gemini thinking: a thinking budget for Gemini 2.5, the thinking level for Gemini 3.', 'seolity' ),
			'ollama'        => __( 'Sent as Ollama “think” for thinking models (gpt-oss uses low / medium / high).', 'seolity' ),
			'custom'        => __( 'Sent as reasoning_effort (OpenAI format) or effort (Anthropic format). Use “auto” if your provider rejects it.', 'seolity' ),
			'sub_claude'    => __( 'Sets Claude Code’s extended-thinking budget (MAX_THINKING_TOKENS) on the runner / this server.', 'seolity' ),
			'sub_codex'     => __( 'Sets Codex “model_reasoning_effort”.', 'seolity' ),
		);
		return array(
			'value'  => self::get( $provider ),
			'levels' => isset( self::LEVELS[ $provider ] ) ? self::LEVELS[ $provider ] : array( 'auto' ),
			'note'   => isset( $what[ $provider ] ) ? $what[ $provider ] : '',
		);
	}

	public static function is_openai_reasoning_model( $model ) {
		return (bool) preg_match( '/^(?:openai\/)?(?:o\d|gpt-5)/i', (string) $model );
	}

	/** OpenAI-format body (OpenAI, GitHub Models, custom OpenAI-compatible). */
	public static function apply_openai( array &$body, $provider, $model, $force = false ) {
		$level = self::get( $provider );
		if ( 'auto' === $level || ( ! $force && ! self::is_openai_reasoning_model( $model ) ) ) {
			return;
		}
		// o-series models do not accept "minimal".
		if ( 'minimal' === $level && ! preg_match( '/gpt-5/i', (string) $model ) ) {
			$level = 'low';
		}
		$body['reasoning_effort'] = $level;
	}

	public static function apply_openrouter( array &$body ) {
		$level = self::get( 'openrouter' );
		if ( 'auto' !== $level ) {
			$body['reasoning'] = array( 'effort' => $level );
		}
	}

	public static function apply_gemini( array &$body, $model ) {
		$level = self::get( 'gemini' );
		if ( 'auto' === $level ) {
			return;
		}
		if ( preg_match( '/gemini-3/i', (string) $model ) ) {
			$body['generationConfig']['thinkingConfig'] = array( 'thinkingLevel' => 'high' === $level ? 'high' : 'low' );
		} elseif ( preg_match( '/gemini-2\.5/i', (string) $model ) ) {
			$body['generationConfig']['thinkingConfig'] = array( 'thinkingBudget' => self::GEMINI_BUDGET[ $level ] );
		}
	}

	public static function apply_ollama( array &$body, $model ) {
		$level = self::get( 'ollama' );
		if ( 'auto' === $level ) {
			return;
		}
		if ( in_array( $level, array( 'low', 'medium', 'high' ), true ) ) {
			$body['think'] = preg_match( '/gpt-oss/i', (string) $model ) ? $level : true;
		} else {
			$body['think'] = 'on' === $level;
		}
	}

	/** Environment / arguments for the official CLIs (runner and this server). */
	public static function cli( $engine ) {
		$level = self::get( 'sub_' . $engine );
		if ( 'auto' === $level ) {
			return array( 'env' => array(), 'args' => array(), 'level' => 'auto' );
		}
		if ( 'claude' === $engine ) {
			return array( 'env' => array( 'MAX_THINKING_TOKENS' => (string) self::CLAUDE_CODE_TOKENS[ $level ] ), 'args' => array(), 'level' => $level );
		}
		return array( 'env' => array(), 'args' => array( '-c', 'model_reasoning_effort="' . $level . '"' ), 'level' => $level );
	}
}
