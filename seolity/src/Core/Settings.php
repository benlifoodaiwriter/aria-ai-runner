<?php
/**
 * Typed settings with schema-driven sanitization.
 *
 * Non-secret settings live in a single non-autoloaded option. Secrets (API keys,
 * OAuth tokens, GitHub tokens) live in a separate option, encrypted at rest via
 * Crypto, and are NEVER returned to the browser — the REST layer only exposes
 * a boolean "is set" flag for each secret.
 *
 * @package SEOlity
 */

namespace SEOlity\Core;

use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class Settings {

	const OPTION        = 'seolity_settings';
	const SECRETS       = 'seolity_secrets';
	const SECRET_FIELDS = array(
		'openai_api_key', 'anthropic_api_key', 'gemini_api_key', 'openrouter_api_key', 'custom_api_key',
		'gsc_client_secret', 'gsc_tokens', 'github_token', 'pagespeed_api_key', 'serp_api_key',
	);

	/** @var Plugin|null */
	private $plugin;

	/** @var array|null */
	private $cache = null;

	public function __construct( Plugin $plugin = null ) {
		$this->plugin = $plugin;
	}

	/**
	 * Schema: key => [type, default, extra].
	 * Types: bool, int, float, string, text, url, email, enum, list.
	 */
	public static function schema() {
		return array(
			// General.
			'approval_mode'        => array( 'enum', 'manual', array( 'manual', 'semi', 'autonomous' ) ),
			'ui_language'          => array( 'enum', 'auto', array( 'auto', 'fa', 'en' ) ),
			'brand_name'           => array( 'string', 'SEOlity' ),
			'brand_logo'           => array( 'url', '' ),
			'brand_color'          => array( 'string', '#6d28d9' ),
			'frontend_output'      => array( 'enum', 'auto', array( 'auto', 'on', 'off' ) ),
			'title_separator'      => array( 'string', '|' ),
			// AI.
			'ai_provider'          => array( 'enum', 'anthropic', array( 'openai', 'anthropic', 'gemini', 'openrouter', 'ollama', 'custom' ) ),
			'ai_model_openai'      => array( 'string', 'gpt-4.1-mini' ),
			'ai_model_anthropic'   => array( 'string', 'claude-opus-5-5' ),
			'ai_model_gemini'      => array( 'string', 'gemini-2.5-flash' ),
			'ai_model_openrouter'  => array( 'string', 'anthropic/claude-opus-5-5' ),
			'ai_model_ollama'      => array( 'string', 'llama3.1' ),
			'ai_model_custom'      => array( 'string', '' ),
			'ai_effort'            => array( 'enum', 'medium', array( 'low', 'medium', 'high', 'xhigh', 'max' ) ),
			'ollama_base_url'      => array( 'url', 'http://localhost:11434' ),
			'custom_base_url'      => array( 'url', '' ),
			'ai_max_tokens'        => array( 'int', 8000, array( 512, 64000 ) ),
			'ai_timeout'           => array( 'int', 120, array( 15, 600 ) ),
			'ai_monthly_budget'    => array( 'float', 0.0, array( 0, 100000 ) ),
			'ai_cache_hours'       => array( 'int', 72, array( 0, 2160 ) ),
			'ai_content_language'  => array( 'enum', 'auto', array( 'auto', 'fa', 'en', 'ar', 'tr', 'de', 'fr', 'es' ) ),
			'ai_brand_voice'       => array( 'text', '' ),
			'ai_use_knowledge'     => array( 'bool', true ),
			// Google Search Console.
			'gsc_client_id'        => array( 'string', '' ),
			'gsc_property'         => array( 'string', '' ),
			'gsc_sync_days'        => array( 'int', 90, array( 7, 480 ) ),
			'gsc_row_limit'        => array( 'int', 25000, array( 1000, 25000 ) ),
			// Crawler.
			'crawl_max_urls'       => array( 'int', 5000, array( 50, 200000 ) ),
			'crawl_batch_size'     => array( 'int', 8, array( 1, 50 ) ),
			'crawl_check_external' => array( 'bool', false ),
			'crawl_frequency'      => array( 'enum', 'weekly', array( 'daily', 'weekly', 'monthly', 'manual' ) ),
			'crawl_timeout'        => array( 'int', 15, array( 3, 60 ) ),
			'thin_content_words'   => array( 'int', 300, array( 50, 2000 ) ),
			// Competitors.
			'competitor_max_pages' => array( 'int', 200, array( 10, 5000 ) ),
			// Alerts & reports.
			'alert_email'          => array( 'email', '' ),
			'alert_email_enabled'  => array( 'bool', false ),
			'alert_drop_percent'   => array( 'int', 25, array( 5, 90 ) ),
			'report_frequency'     => array( 'enum', 'weekly', array( 'none', 'daily', 'weekly', 'monthly' ) ),
			'report_recipients'    => array( 'list', array() ),
			// Technical.
			'sitemap_enabled'      => array( 'enum', 'auto', array( 'auto', 'on', 'off' ) ),
			'sitemap_exclude_types' => array( 'list', array( 'attachment' ) ),
			'robots_custom'        => array( 'text', '' ),
			'robots_override'      => array( 'bool', false ),
			'redirects_enabled'    => array( 'bool', true ),
			'log_404'              => array( 'bool', true ),
			'log_404_bots'         => array( 'bool', false ),
			'retention_days'       => array( 'int', 180, array( 14, 1825 ) ),
			// Schema.
			'schema_enabled'       => array( 'bool', true ),
			'org_type'             => array( 'enum', 'Organization', array( 'Organization', 'LocalBusiness', 'Person', 'OnlineStore' ) ),
			'org_name'             => array( 'string', '' ),
			'org_logo'             => array( 'url', '' ),
			'org_phone'            => array( 'string', '' ),
			'org_address'          => array( 'text', '' ),
			'org_same_as'          => array( 'list', array() ),
			// Experiments.
			'experiment_window'    => array( 'int', 14, array( 7, 90 ) ),
			// Knowledge base (GitHub).
			'kb_repo'              => array( 'string', 'benlifoodaiwriter/aria-ai-runner' ),
			'kb_branch'            => array( 'string', 'main' ),
			'kb_path'              => array( 'string', 'seolity/knowledge' ),
			'kb_auto_sync'         => array( 'bool', true ),
			'kb_contribute'        => array( 'bool', false ),
			// GitHub data storage.
			'storage_enabled'      => array( 'bool', false ),
			'storage_repo'         => array( 'string', '' ),
			'storage_branch'       => array( 'string', 'main' ),
			'storage_path'         => array( 'string', 'seolity-data' ),
			'storage_archive_days' => array( 'int', 30, array( 1, 3650 ) ),
			'storage_prune_local'  => array( 'bool', true ),
			// Privacy.
			'anonymize_ip'         => array( 'bool', true ),
			'delete_on_uninstall'  => array( 'bool', false ),
		);
	}

	public static function defaults() {
		$out = array();
		foreach ( self::schema() as $key => $def ) {
			$out[ $key ] = $def[1];
		}
		return $out;
	}

	public static function install_defaults() {
		$current = get_option( self::OPTION, array() );
		$merged  = array_merge( self::defaults(), is_array( $current ) ? $current : array() );
		if ( '' === $merged['org_name'] ) {
			$merged['org_name'] = get_bloginfo( 'name' );
		}
		update_option( self::OPTION, $merged, false );
		if ( false === get_option( self::SECRETS ) ) {
			add_option( self::SECRETS, array(), '', false );
		}
	}

	public function all() {
		if ( null === $this->cache ) {
			$stored      = get_option( self::OPTION, array() );
			$this->cache = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		}
		return $this->cache;
	}

	public function get( $key, $default = null ) {
		$all = $this->all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Update a set of settings after sanitizing them against the schema.
	 *
	 * @param array $values Raw values (unknown keys ignored).
	 * @return array Sanitized values that were saved.
	 */
	public function update( array $values ) {
		$schema = self::schema();
		$clean  = array();
		foreach ( $values as $key => $value ) {
			if ( isset( $schema[ $key ] ) ) {
				$clean[ $key ] = self::sanitize_value( $value, $schema[ $key ] );
			}
		}
		$all = array_merge( $this->all(), $clean );
		update_option( self::OPTION, $all, false );
		$this->cache = $all;
		return $clean;
	}

	public static function sanitize_value( $value, array $def ) {
		list( $type, $default ) = $def;
		$extra                  = isset( $def[2] ) ? $def[2] : null;
		switch ( $type ) {
			case 'bool':
				return (bool) filter_var( $value, FILTER_VALIDATE_BOOLEAN );
			case 'int':
				$v = (int) $value;
				return $extra ? max( $extra[0], min( $extra[1], $v ) ) : $v;
			case 'float':
				$v = (float) $value;
				return $extra ? max( $extra[0], min( $extra[1], $v ) ) : $v;
			case 'url':
				return esc_url_raw( trim( (string) $value ) );
			case 'email':
				$email = sanitize_email( (string) $value );
				return is_email( $email ) ? $email : '';
			case 'enum':
				return in_array( $value, $extra, true ) ? $value : $default;
			case 'text':
				return sanitize_textarea_field( (string) $value );
			case 'list':
				if ( is_string( $value ) ) {
					$value = preg_split( '/[\r\n,]+/', $value );
				}
				return array_values( array_filter( array_map( 'sanitize_text_field', (array) $value ), 'strlen' ) );
			case 'string':
			default:
				return sanitize_text_field( (string) $value );
		}
	}

	/* ---------------------------------------------------------------------
	 * Secrets
	 * ------------------------------------------------------------------- */

	private function crypto() {
		return $this->plugin ? $this->plugin->crypto : new Crypto();
	}

	/**
	 * @param string $key Secret name.
	 * @return string|array|null Decrypted secret.
	 */
	public function secret( $key ) {
		if ( ! in_array( $key, self::SECRET_FIELDS, true ) ) {
			return null;
		}
		$const = 'SEOLITY_' . strtoupper( $key );
		if ( defined( $const ) ) {
			return constant( $const ); // Allow wp-config.php overrides, never stored in DB.
		}
		$secrets = get_option( self::SECRETS, array() );
		if ( empty( $secrets[ $key ] ) ) {
			return null;
		}
		$plain = $this->crypto()->decrypt( $secrets[ $key ] );
		if ( null === $plain ) {
			return null;
		}
		$decoded = json_decode( $plain, true );
		return ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) ? $decoded : $plain;
	}

	/**
	 * @param string            $key   Secret name.
	 * @param string|array|null $value Null/'' deletes.
	 */
	public function set_secret( $key, $value ) {
		if ( ! in_array( $key, self::SECRET_FIELDS, true ) ) {
			return false;
		}
		$secrets = get_option( self::SECRETS, array() );
		$secrets = is_array( $secrets ) ? $secrets : array();
		if ( null === $value || '' === $value || array() === $value ) {
			unset( $secrets[ $key ] );
		} else {
			$plain           = is_array( $value ) ? wp_json_encode( $value ) : (string) $value;
			$secrets[ $key ] = $this->crypto()->encrypt( $plain );
		}
		return update_option( self::SECRETS, $secrets, false );
	}

	public function has_secret( $key ) {
		$value = $this->secret( $key );
		return ! empty( $value );
	}

	/** Map of secret => bool for the UI (never the values). */
	public function secret_flags() {
		$out = array();
		foreach ( self::SECRET_FIELDS as $key ) {
			if ( 'gsc_tokens' === $key ) {
				continue;
			}
			$out[ $key ] = $this->has_secret( $key );
		}
		return $out;
	}
}
