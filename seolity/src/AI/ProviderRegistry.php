<?php
/**
 * AI provider registry. Add providers with the `seolity_ai_providers` filter:
 *
 *   add_filter( 'seolity_ai_providers', function ( $providers, $plugin ) {
 *       $providers['mistral'] = new My_Mistral_Provider( $plugin ); // implements ProviderInterface
 *       return $providers;
 *   }, 10, 2 );
 *
 * @package SEOlity
 */

namespace SEOlity\AI;

use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class ProviderRegistry {

	/** @var array<string, ProviderInterface>|null */
	private static $providers = null;

	public static function all( Plugin $plugin ) {
		if ( null === self::$providers ) {
			$providers = array(
				'anthropic'  => new Providers\Anthropic( $plugin ),
				'openai'     => new Providers\OpenAI( $plugin ),
				'gemini'     => new Providers\Gemini( $plugin ),
				'openrouter' => new Providers\OpenRouter( $plugin ),
				'ollama'     => new Providers\Ollama( $plugin ),
				'custom'     => new Providers\CustomApi( $plugin ),
				'sub_claude' => new Providers\SubscriptionClaude( $plugin ),
				'sub_codex'  => new Providers\SubscriptionCodex( $plugin ),
				'github_models' => new Providers\GitHubModels( $plugin ),
			);
			$providers = apply_filters( 'seolity_ai_providers', $providers, $plugin );
			self::$providers = array_filter(
				$providers,
				static function ( $p ) {
					return $p instanceof ProviderInterface;
				}
			);
		}
		return self::$providers;
	}

	public static function get( Plugin $plugin, $id ) {
		$all = self::all( $plugin );
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/** Rough USD prices per 1M tokens [input, output] for budget tracking. */
	public static function price( $model ) {
		$table = apply_filters(
			'seolity_ai_prices',
			array(
				'claude-fable'     => array( 10, 50 ),
				'claude-opus-5-5'  => array( 4, 20 ),
				'claude-opus'      => array( 5, 25 ),
				'claude-sonnet'    => array( 2, 10 ),
				'claude-haiku'     => array( 1, 5 ),
				'gpt-4.1-mini'     => array( 0.4, 1.6 ),
				'gpt-4.1-nano'     => array( 0.1, 0.4 ),
				'gpt-4.1'          => array( 2, 8 ),
				'gpt-4o-mini'      => array( 0.15, 0.6 ),
				'gpt-4o'           => array( 2.5, 10 ),
				'gemini-2.5-flash' => array( 0.3, 2.5 ),
				'gemini-2.5-pro'   => array( 1.25, 10 ),
			)
		);
		$model = strtolower( (string) $model );
		$model = preg_replace( '#^[a-z-]+/#', '', $model ); // OpenRouter vendor prefix.
		$best  = null;
		$len   = 0;
		foreach ( $table as $prefix => $price ) {
			if ( 0 === strpos( $model, $prefix ) && strlen( $prefix ) > $len ) {
				$best = $price;
				$len  = strlen( $prefix );
			}
		}
		return $best ? $best : array( 0, 0 );
	}
}
