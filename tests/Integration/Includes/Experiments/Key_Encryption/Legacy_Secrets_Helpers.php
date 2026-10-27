<?php
/**
 * Writes secrets in the format the plugin used before 1.5.0.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\Key_Encryption
 */

declare( strict_types=1 );

namespace WordPress\AI\Tests\Integration\Includes\Experiments\Key_Encryption;

use WordPress\AI\Experiments\Key_Encryption\Secrets_Bridge;

/**
 * Seals legacy `_secret_*` rows so tests can migrate them.
 *
 * @since x.x.x
 */
trait Legacy_Secrets_Helpers {

	/**
	 * Stores a secret as a pre-1.5.0 row, creating the master key if needed.
	 *
	 * @since x.x.x
	 *
	 * @param string $secret_key Namespaced secret key.
	 * @param string $value      Plaintext secret value.
	 */
	private static function set_legacy_secret( string $secret_key, string $value ): void {
		$material = defined( 'WP_SECRETS_KEY' ) ? WP_SECRETS_KEY : LOGGED_IN_KEY . LOGGED_IN_SALT;
		$site_key = sodium_crypto_generichash( $material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		$wrapped  = get_option( Secrets_Bridge::LEGACY_MASTER_KEY_OPTION );
		$master   = is_string( $wrapped ) ? self::open_legacy( $wrapped, $site_key ) : false;

		if ( false === $master ) {
			$master = random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
			update_option( Secrets_Bridge::LEGACY_MASTER_KEY_OPTION, self::seal_legacy( $master, $site_key ), false );
		}

		update_option( Secrets_Bridge::LEGACY_OPTION_PREFIX . $secret_key, self::seal_legacy( $value, $master ), false );
	}

	/**
	 * Encrypts a value as base64( nonce . secretbox ).
	 *
	 * @since x.x.x
	 *
	 * @param string $plaintext The value.
	 * @param string $key       32-byte key.
	 * @return string The record.
	 */
	private static function seal_legacy( string $plaintext, string $key ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the legacy record format.
		return base64_encode( $nonce . sodium_crypto_secretbox( $plaintext, $nonce, $key ) );
	}

	/**
	 * Decrypts a base64( nonce . secretbox ) record.
	 *
	 * @since x.x.x
	 *
	 * @param string $record The record.
	 * @param string $key    32-byte key.
	 * @return string|false The plaintext, or false.
	 */
	private static function open_legacy( string $record, string $key ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- the legacy record format.
		$raw = base64_decode( $record, true );

		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return false;
		}

		return sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $key );
	}
}
