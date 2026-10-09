<?php
/**
 * Encryption for the ad platform tokens.
 *
 * @package KDNA_EcommerceInsights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Keeps secrets (access tokens, the Google client secret and developer
 * token) encrypted in the database, and never sends them to the browser.
 *
 * In plain English: each secret is locked with a key made from this site's
 * own security keys in wp-config.php (the "salts"). Someone who copies the
 * database without wp-config.php cannot read the tokens. If the salts are
 * ever changed, saved tokens can no longer be read, and the Marketing
 * screen asks for them to be pasted in again.
 *
 * Uses libsodium (built into PHP) when available, otherwise OpenSSL
 * AES-256-GCM. Both are authenticated, so a tampered value is rejected.
 *
 * Secrets live in their own option (kdna_ei_secrets), separate from the
 * settings, so they are never part of a settings response.
 */
class KDNA_EcommerceInsights_Crypto {

	/**
	 * Option holding the encrypted secrets.
	 */
	const OPTION = 'kdna_ei_secrets';

	/**
	 * Marks values encrypted by this class, with the method used.
	 */
	const PREFIX_SODIUM = 'kdna1s:';
	const PREFIX_OPENSSL = 'kdna1o:';

	/**
	 * The 32-byte key, made from the site's auth and secure auth salts.
	 *
	 * @return string
	 */
	private static function key(): string {
		$material = wp_salt( 'auth' ) . wp_salt( 'secure_auth' );
		return hash_hkdf( 'sha256', $material, 32, 'kdna-ecommerce-insights tokens' );
	}

	/**
	 * Encrypts text. Empty text stays empty.
	 *
	 * @param string $plain Text to encrypt.
	 * @return string
	 */
	public static function encrypt( string $plain ): string {
		if ( '' === $plain ) {
			return '';
		}

		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			return self::PREFIX_SODIUM . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		$iv     = random_bytes( 12 );
		$tag    = '';
		$cipher = openssl_encrypt( $plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );
		return self::PREFIX_OPENSSL . base64_encode( $iv . $tag . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypts text from encrypt(). Returns null when it cannot be read,
	 * for example after the site's salts were changed.
	 *
	 * @param string $stored Encrypted text.
	 * @return string|null
	 */
	public static function decrypt( string $stored ): ?string {
		if ( '' === $stored ) {
			return '';
		}

		if ( 0 === strpos( $stored, self::PREFIX_SODIUM ) && function_exists( 'sodium_crypto_secretbox_open' ) ) {
			$raw = base64_decode( substr( $stored, strlen( self::PREFIX_SODIUM ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return null;
			}
			$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::key() );
			return false === $plain ? null : $plain;
		}

		if ( 0 === strpos( $stored, self::PREFIX_OPENSSL ) ) {
			$raw = base64_decode( substr( $stored, strlen( self::PREFIX_OPENSSL ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( false === $raw || strlen( $raw ) <= 28 ) {
				return null;
			}
			$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
			return false === $plain ? null : $plain;
		}

		return null;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Stored secrets
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Saves a secret, encrypted. An empty value removes it.
	 *
	 * @param string $name  Secret name, for example 'meta_token'.
	 * @param string $value Plain value.
	 */
	public static function set( string $name, string $value ): void {
		$secrets = (array) get_option( self::OPTION, array() );
		if ( '' === $value ) {
			unset( $secrets[ $name ] );
		} else {
			$secrets[ $name ] = self::encrypt( $value );
		}
		update_option( self::OPTION, $secrets, false );
	}

	/**
	 * Reads a secret. Returns an empty string when none is saved and null
	 * when one is saved but cannot be read.
	 *
	 * @param string $name Secret name.
	 * @return string|null
	 */
	public static function get( string $name ): ?string {
		$secrets = (array) get_option( self::OPTION, array() );
		return isset( $secrets[ $name ] ) ? self::decrypt( (string) $secrets[ $name ] ) : '';
	}

	/**
	 * Whether a secret is saved (readable or not). Safe to show in the
	 * browser: it says nothing about the value.
	 *
	 * @param string $name Secret name.
	 * @return bool
	 */
	public static function has( string $name ): bool {
		$secrets = (array) get_option( self::OPTION, array() );
		return ! empty( $secrets[ $name ] );
	}
}
