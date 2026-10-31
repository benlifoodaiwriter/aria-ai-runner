<?php
/**
 * Authenticated encryption for secrets at rest.
 *
 * Uses libsodium (XSalsa20-Poly1305) when available, AES-256-GCM via OpenSSL
 * otherwise. The key is derived from SEOLITY_ENCRYPTION_KEY (recommended, set in
 * wp-config.php) or the site's AUTH salts. Ciphertext is versioned so keys and
 * algorithms can be rotated.
 *
 * @package SEOlity
 */

namespace SEOlity\Core;

defined( 'ABSPATH' ) || exit;

final class Crypto {

	public function __construct( $plugin = null ) {}

	private function key() {
		$material = defined( 'SEOLITY_ENCRYPTION_KEY' ) && SEOLITY_ENCRYPTION_KEY
			? SEOLITY_ENCRYPTION_KEY
			: ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : '' ) . wp_salt( 'auth' );
		return hash_hmac( 'sha256', 'seolity-secrets-v1', $material, true );
	}

	public function encrypt( $plain ) {
		$key = $this->key();
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			return 's1:' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, $key ) ); // phpcs:ignore
		}
		if ( function_exists( 'openssl_encrypt' ) ) {
			$iv     = random_bytes( 12 );
			$tag    = '';
			$cipher = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			return 'o1:' . base64_encode( $iv . $tag . $cipher ); // phpcs:ignore
		}
		// Last-resort obfuscation (should never happen on supported PHP builds).
		return 'p0:' . base64_encode( $plain ); // phpcs:ignore
	}

	/**
	 * @param string $payload Encrypted payload.
	 * @return string|null Plain text, null when tampered/undecryptable.
	 */
	public function decrypt( $payload ) {
		if ( ! is_string( $payload ) || strlen( $payload ) < 4 ) {
			return null;
		}
		$version = substr( $payload, 0, 3 );
		$raw     = base64_decode( substr( $payload, 3 ), true ); // phpcs:ignore
		if ( false === $raw ) {
			return null;
		}
		$key = $this->key();
		if ( 's1:' === $version && function_exists( 'sodium_crypto_secretbox_open' ) ) {
			$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$box   = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plain = sodium_crypto_secretbox_open( $box, $nonce, $key );
			return false === $plain ? null : $plain;
		}
		if ( 'o1:' === $version && function_exists( 'openssl_decrypt' ) ) {
			$iv    = substr( $raw, 0, 12 );
			$tag   = substr( $raw, 12, 16 );
			$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			return false === $plain ? null : $plain;
		}
		if ( 'p0:' === $version ) {
			return $raw;
		}
		return null;
	}

	/** Random URL-safe token. */
	public static function token( $bytes = 32 ) {
		return rtrim( strtr( base64_encode( random_bytes( $bytes ) ), '+/', '-_' ), '=' ); // phpcs:ignore
	}
}
