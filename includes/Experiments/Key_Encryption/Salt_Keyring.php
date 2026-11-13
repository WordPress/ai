<?php
/**
 * Read-only keyring for a root key wrapped under the WordPress salts.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Key_Encryption;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Unwraps a Secrets API root key that was wrapped under `LOGGED_IN_KEY . LOGGED_IN_SALT`.
 *
 * @since x.x.x
 */
final class Salt_Keyring extends \WP_Secrets_Keyring {

	/**
	 * The placeholder `wp-config-sample.php` ships for every key and salt.
	 *
	 * @since x.x.x
	 */
	private const PLACEHOLDER = 'put your unique phrase here';

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 *
	 * @param string $key_material Raw key material to protect.
	 * @return \WP_Error Always, since this keyring is read-only.
	 */
	public function wrap( string $key_material ) {
		unset( $key_material );

		return new \WP_Error(
			WP_SECRETS_ERROR_KEY_UNAVAILABLE,
			__( 'Nothing can be stored under the WordPress salts.', 'ai' )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 *
	 * @param string $wrapped The wrapped root key.
	 * @return string|\WP_Error The raw root key, or an error if it was not wrapped under the salts.
	 */
	public function unwrap( string $wrapped ) {
		$site_key = $this->get_site_key();
		$raw      = base64_decode( $wrapped, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- The Secrets API stores the wrapped root key base64-encoded.
		$nonce    = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

		if ( null === $site_key || false === $raw || strlen( $raw ) <= $nonce ) {
			return $this->unavailable();
		}

		try {
			$root_key = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
				substr( $raw, $nonce ),
				\WP_Secrets_Config_Key_Provider::AAD,
				substr( $raw, 0, $nonce ),
				$site_key
			);
		} catch ( \Throwable $e ) {
			$root_key = false;
		}

		wp_secrets_memzero( $site_key );

		return is_string( $root_key ) ? $root_key : $this->unavailable();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function get_key_source(): string {
		return 'LOGGED_IN_KEY . LOGGED_IN_SALT';
	}

	/**
	 * Derives the key the Secrets API used when `WP_SECRETS_KEY` was not defined.
	 *
	 * @since x.x.x
	 *
	 * @return string|null The 32-byte key, or null when the salts are not usable.
	 */
	private function get_site_key(): ?string {
		$key  = defined( 'LOGGED_IN_KEY' ) ? LOGGED_IN_KEY : null;
		$salt = defined( 'LOGGED_IN_SALT' ) ? LOGGED_IN_SALT : null;

		foreach ( array( $key, $salt ) as $value ) {
			if ( ! is_string( $value ) || '' === $value || self::PLACEHOLDER === $value ) {
				return null;
			}
		}

		return sodium_crypto_generichash( $key . $salt, '', SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES );
	}

	/**
	 * Returns the error for a root key this keyring cannot unwrap.
	 *
	 * @since x.x.x
	 */
	private function unavailable(): \WP_Error {
		return new \WP_Error(
			WP_SECRETS_ERROR_KEY_UNAVAILABLE,
			__( 'The root key was not wrapped under the WordPress salts.', 'ai' )
		);
	}
}
