<?php
/**
 * Encrypted Options Provider
 *
 * The sole built-in provider. Stores secrets in wp_options encrypted with
 * sodium_crypto_secretbox (XSalsa20-Poly1305).
 *
 * Uses a two-tier key architecture:
 *
 *   1. A "secrets key" (WP_SECRETS_KEY constant, or derived from
 *      LOGGED_IN_KEY . LOGGED_IN_SALT) protects the master key.
 *   2. A randomly-generated "master key" (stored encrypted in wp_options)
 *      protects individual secrets.
 *
 * This means key rotation (changing WP_SECRETS_KEY) only requires
 * re-encrypting the single master key option, not every stored secret.
 *
 * Supports WP_SECRETS_KEY_PREVIOUS for seamless rotation: if decryption
 * of the master key fails with the current secrets key, the provider
 * automatically retries with the previous key, then re-encrypts the
 * master key under the new key.
 *
 * @package Displace_Secrets_Manager
 */

namespace WordPress\AI\Vendor\Secrets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encrypted secrets storage using libsodium and wp_options.
 */
class Secrets_Provider_Encrypted_Options implements Secrets_Provider {

	/**
	 * Prefix applied to secret option names.
	 *
	 * @var string
	 */
	const OPTION_PREFIX = '_secret_';

	/**
	 * Option name where the encrypted master key is stored.
	 *
	 * @var string
	 */
	const MASTER_KEY_OPTION = '_secrets_master_key';

	/**
	 * Key source identifiers for health reporting.
	 */
	const KEY_SOURCE_CONSTANT = 'constant';
	const KEY_SOURCE_FALLBACK = 'fallback';

	/**
	 * Cached derived secrets key (the key that encrypts the master key).
	 *
	 * @var string|null
	 */
	private $secrets_key_cache = null;

	/**
	 * Cached plaintext master key (the key that encrypts individual secrets).
	 *
	 * @var string|null
	 */
	private $master_key_cache = null;

	/**
	 * Cached key source identifier.
	 *
	 * @var string|null
	 */
	private $key_source_cache = null;

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'encrypted-options';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name(): string {
		return __( 'Encrypted Options', 'displace-secrets-manager' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_priority(): int {
		return 50;
	}

	/**
	 * Available when sodium is loaded. A key is always derivable because
	 * WordPress requires LOGGED_IN_KEY and LOGGED_IN_SALT.
	 *
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return function_exists( 'sodium_crypto_secretbox' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get( string $key, array $context = [] ): ?string {
		$option_name = self::option_name( $key );
		$raw         = get_option( $option_name, null );

		if ( null === $raw || false === $raw ) {
			return null;
		}

		$master_key = $this->get_master_key();

		return $this->decrypt( $raw, $master_key, $key );
	}

	/**
	 * {@inheritDoc}
	 */
	public function set( string $key, string $value, array $context = [] ): bool {
		$option_name = self::option_name( $key );
		$master_key  = $this->get_master_key();
		$encrypted   = $this->encrypt( $value, $master_key, $key );

		if ( false === get_option( $option_name ) ) {
			return add_option( $option_name, $encrypted, '', 'no' );
		}

		return update_option( $option_name, $encrypted, 'no' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function delete( string $key, array $context = [] ): bool {
		return delete_option( self::option_name( $key ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function exists( string $key, array $context = [] ): bool {
		return false !== get_option( self::option_name( $key ), false );
	}

	/**
	 * {@inheritDoc}
	 */
	public function list_keys( string $prefix = '', array $context = [] ): array {
		global $wpdb;

		$like = $wpdb->esc_like( self::OPTION_PREFIX . $prefix ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name != %s ORDER BY option_name ASC",
				$like,
				self::MASTER_KEY_OPTION
			)
		);

		$prefix_length = strlen( self::OPTION_PREFIX );

		return array_map(
			function ( $option_name ) use ( $prefix_length ) {
				return substr( $option_name, $prefix_length );
			},
			$results
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function health_check(): array {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return array(
				'status'  => 'critical',
				'message' => __( 'Sodium functions are not available. Secrets cannot be encrypted.', 'displace-secrets-manager' ),
			);
		}

		$source = $this->get_key_source();

		// Verify master key round-trip.
		try {
			$master_key     = $this->get_master_key();
			$test_plaintext = 'secrets-health-check-' . wp_generate_password( 12, false );
			$ciphertext     = $this->encrypt( $test_plaintext, $master_key, '__health_check__' );
			$decrypted      = $this->decrypt( $ciphertext, $master_key, '__health_check__' );

			if ( $test_plaintext !== $decrypted ) {
				return array(
					'status'  => 'critical',
					'message' => __( 'Encryption round-trip failed. The master key or secrets key may be corrupted.', 'displace-secrets-manager' ),
				);
			}
		} catch ( Secrets_Exception $e ) {
			return array(
				'status'  => 'critical',
				'message' => $e->getMessage(),
			);
		}

		if ( self::KEY_SOURCE_FALLBACK === $source ) {
			return array(
				'status'  => 'recommended',
				'message' => __( 'Encryption active using key derived from WordPress salts. Define a dedicated WP_SECRETS_KEY in wp-config.php for independent key management.', 'displace-secrets-manager' ),
			);
		}

		return array(
			'status'  => 'good',
			'message' => __( 'Encryption active with dedicated WP_SECRETS_KEY.', 'displace-secrets-manager' ),
		);
	}

	/**
	 * Get the key source for external reporting.
	 *
	 * @return string One of the KEY_SOURCE_* constants.
	 */
	public function get_key_source(): string {
		if ( null !== $this->key_source_cache ) {
			return $this->key_source_cache;
		}

		if ( defined( 'WP_SECRETS_KEY' ) && '' !== WP_SECRETS_KEY ) {
			$this->key_source_cache = self::KEY_SOURCE_CONSTANT;
		} else {
			$this->key_source_cache = self::KEY_SOURCE_FALLBACK;
		}

		return $this->key_source_cache;
	}

	/**
	 * Get or create the plaintext master key.
	 *
	 * The master key is generated once and stored encrypted in wp_options.
	 * It is decrypted on each request using the secrets key.
	 *
	 * @return string 32-byte binary master key.
	 *
	 * @throws Secrets_Exception If the master key cannot be decrypted or created.
	 */
	private function get_master_key(): string {
		if ( null !== $this->master_key_cache ) {
			return $this->master_key_cache;
		}

		$stored = get_option( self::MASTER_KEY_OPTION, false );

		if ( false !== $stored && '' !== $stored ) {
			$this->master_key_cache = $this->decrypt_master_key( $stored );
			return $this->master_key_cache;
		}

		// First run: generate and store a new master key.
		$master_key             = random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		$this->master_key_cache = $master_key;

		$secrets_key       = $this->derive_secrets_key();
		$encrypted_master  = $this->encrypt( $master_key, $secrets_key, '__master_key__' );

		add_option( self::MASTER_KEY_OPTION, $encrypted_master, '', 'no' );

		return $this->master_key_cache;
	}

	/**
	 * Decrypt the stored master key.
	 *
	 * Tries the current secrets key first. If that fails and
	 * WP_SECRETS_KEY_PREVIOUS is defined, retries with the previous key
	 * and re-encrypts the master key under the current key (transparent
	 * rotation).
	 *
	 * @param string $stored The encrypted master key from wp_options.
	 * @return string 32-byte binary master key.
	 *
	 * @throws Secrets_Exception If decryption fails with all available keys.
	 */
	private function decrypt_master_key( string $stored ): string {
		$secrets_key = $this->derive_secrets_key();

		try {
			return $this->decrypt( $stored, $secrets_key, '__master_key__' );
		} catch ( Secrets_Exception $e ) {
			// Fall through to try previous key.
		}

		// Try the previous key for rotation support.
		if ( defined( 'WP_SECRETS_KEY_PREVIOUS' ) && '' !== WP_SECRETS_KEY_PREVIOUS ) {
			$previous_key = $this->derive_key_from_material( WP_SECRETS_KEY_PREVIOUS );

			try {
				$master_key = $this->decrypt( $stored, $previous_key, '__master_key__' );
			} catch ( Secrets_Exception $e ) {
				throw new Secrets_Exception(
				esc_html__( 'Cannot decrypt master key with current or previous secrets key. Secrets are inaccessible.', 'displace-secrets-manager' ),
				0,
				$e, // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- chained exception, not output.
				sanitize_key( '__master_key__' ),
				sanitize_key( $this->get_id() )
			);
			}

			// Re-encrypt master key under the new secrets key.
			$re_encrypted = $this->encrypt( $master_key, $secrets_key, '__master_key__' );
			update_option( self::MASTER_KEY_OPTION, $re_encrypted, 'no' );

			/**
			 * Fires when the master key is re-encrypted after a key rotation.
			 *
			 * @param string $key_source The new key source.
			 */
			do_action( 'secrets_master_key_rotated', $this->get_key_source() );

			return $master_key;
		}

		throw new Secrets_Exception(
			esc_html__( 'Cannot decrypt master key. If you recently changed WP_SECRETS_KEY, set WP_SECRETS_KEY_PREVIOUS to the old value.', 'displace-secrets-manager' ),
			0,
			null,
			sanitize_key( '__master_key__' ),
			sanitize_key( $this->get_id() )
		);
	}

	/**
	 * Re-encrypt the master key with the current secrets key.
	 *
	 * Called by the `wp secret rotate` CLI command after a key change.
	 *
	 * @return bool True on success.
	 *
	 * @throws Secrets_Exception If rotation fails.
	 */
	public function rotate_master_key(): bool {
		$master_key   = $this->get_master_key();
		$secrets_key  = $this->derive_secrets_key();
		$re_encrypted = $this->encrypt( $master_key, $secrets_key, '__master_key__' );

		$result = update_option( self::MASTER_KEY_OPTION, $re_encrypted, 'no' );

		if ( $result ) {
			/** This action is documented in decrypt_master_key(). */
			do_action( 'secrets_master_key_rotated', $this->get_key_source() );
		}

		return $result;
	}

	/**
	 * Encrypt a plaintext value.
	 *
	 * @param string $plaintext      The value to encrypt.
	 * @param string $encryption_key The 32-byte encryption key.
	 * @param string $context_key    The secret key name (for error context only).
	 * @return string Base64-encoded nonce + ciphertext.
	 *
	 * @throws Secrets_Exception If encryption fails.
	 */
	private function encrypt( string $plaintext, string $encryption_key, string $context_key ): string {
		try {
			$nonce      = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, $encryption_key );
		} catch ( \Exception $e ) {
			throw new Secrets_Exception(
				esc_html__( 'Encryption failed.', 'displace-secrets-manager' ),
				0,
				$e, // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- chained exception, not output.
				sanitize_key( $context_key ),
				sanitize_key( $this->get_id() )
			);
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return base64_encode( $nonce . $ciphertext );
	}

	/**
	 * Decrypt a stored value.
	 *
	 * @param string $stored         The base64-encoded nonce + ciphertext.
	 * @param string $encryption_key The 32-byte encryption key.
	 * @param string $context_key    The secret key name (for error context only).
	 * @return string The decrypted plaintext.
	 *
	 * @throws Secrets_Exception If decryption fails.
	 */
	private function decrypt( string $stored, string $encryption_key, string $context_key ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$decoded = base64_decode( $stored, true );
		if ( false === $decoded ) {
			throw new Secrets_Exception(
				esc_html__( 'Invalid stored ciphertext (base64 decode failed).', 'displace-secrets-manager' ),
				0,
				null,
				sanitize_key( $context_key ),
				sanitize_key( $this->get_id() )
			);
		}

		$min_length = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES;
		if ( strlen( $decoded ) < $min_length ) {
			throw new Secrets_Exception(
				esc_html__( 'Stored ciphertext is too short to contain a valid nonce and payload.', 'displace-secrets-manager' ),
				0,
				null,
				sanitize_key( $context_key ),
				sanitize_key( $this->get_id() )
			);
		}

		$nonce      = substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		try {
			$plaintext = sodium_crypto_secretbox_open( $ciphertext, $nonce, $encryption_key );
		} catch ( \SodiumException $e ) {
			throw new Secrets_Exception(
				esc_html__( 'Decryption failed.', 'displace-secrets-manager' ),
				0,
				$e, // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- chained exception, not output.
				sanitize_key( $context_key ),
				sanitize_key( $this->get_id() )
			);
		}

		if ( false === $plaintext ) {
			throw new Secrets_Exception(
				esc_html__( 'Decryption failed. The key may have changed or the data is corrupt.', 'displace-secrets-manager' ),
				0,
				null,
				sanitize_key( $context_key ),
				sanitize_key( $this->get_id() )
			);
		}

		return $plaintext;
	}

	/**
	 * Derive the 32-byte secrets key (the key that protects the master key).
	 *
	 * Sources, in order:
	 *   1. WP_SECRETS_KEY constant (recommended)
	 *   2. LOGGED_IN_KEY . LOGGED_IN_SALT (always available)
	 *
	 * @return string 32-byte binary key.
	 */
	private function derive_secrets_key(): string {
		if ( null !== $this->secrets_key_cache ) {
			return $this->secrets_key_cache;
		}

		if ( defined( 'WP_SECRETS_KEY' ) && '' !== WP_SECRETS_KEY ) {
			$this->secrets_key_cache = $this->derive_key_from_material( WP_SECRETS_KEY );
		} else {
			$this->secrets_key_cache = $this->derive_key_from_material( LOGGED_IN_KEY . LOGGED_IN_SALT );
		}

		return $this->secrets_key_cache;
	}

	/**
	 * Derive a 32-byte key from arbitrary key material using BLAKE2b.
	 *
	 * Any string of any length is accepted. The material is always hashed
	 * to produce a fixed-length key suitable for sodium_crypto_secretbox.
	 *
	 * @param string $material Raw key material.
	 * @return string 32-byte binary key.
	 */
	private function derive_key_from_material( string $material ): string {
		return sodium_crypto_generichash( $material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * Build the wp_options option_name for a given secret key.
	 *
	 * @param string $key The secret key.
	 * @return string
	 */
	public static function option_name( string $key ): string {
		return self::OPTION_PREFIX . $key;
	}

	/**
	 * Reset cached keys (for testing only).
	 *
	 * @return void
	 */
	public function reset_cache(): void {
		$this->secrets_key_cache = null;
		$this->master_key_cache  = null;
		$this->key_source_cache  = null;
	}
}
