<?php
/**
 * Secrets API: legacy reader (plugin-only, never copied to core)
 *
 * @package SecretsAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only access to the earlier prototype's on-disk format.
 *
 * Never writes, never deletes -- this class exists purely so
 * Secrets_API_Migrator can read a value out of the old format to write it into the
 * new one. All legacy crypto is isolated here specifically so it can be deleted in
 * one commit when the compatibility window closes.
 *
 * The legacy format, verified against the prototype's own source:
 *
 * - Secret option: '_secret_{key}'.
 * - Master key option: '_secrets_master_key'.
 * - Cipher: sodium_crypto_secretbox (XSalsa20-Poly1305).
 * - Record: base64( nonce . ciphertext ) -- a single string, not an array. No AAD.
 * - Site key: sodium_crypto_generichash( $material, '', 32 ), where $material is
 *   the literal WP_SECRETS_KEY string if defined, or LOGGED_IN_KEY . LOGGED_IN_SALT.
 *
 * Critically, legacy always hashes WP_SECRETS_KEY's literal string form -- there is
 * no "raw base64-32 bytes" path here at all, unlike the new format's key provider.
 * A site with WP_SECRETS_KEY defined is therefore not automatically compatible
 * between the two formats even when the constant happens to be valid base64-32; the
 * salt-fallback path is the one that is byte-identical between old and new, which
 * is deliberate and is what lets those sites migrate with zero credential re-entry.
 *
 * Because of that asymmetry, this reader does not assume the currently-defined
 * constants describe how existing records were sealed. It tries every site key the
 * legacy system could have used and keeps whichever one actually opens the master
 * key record -- see unwrap_master_key() for why that is safe and why it matters.
 *
 * Deliberately does not reject the wp-config-sample.php placeholder the way
 * WP_Secrets_Config_Key_Provider does: that check is a hardening this project added
 * to the new format, not something the legacy system ever did. A site that
 * legitimately encrypted a value under a placeholder-derived key on the old system
 * must still be able to read it back here -- refusing it would break exactly the
 * migration this class exists to support, not improve security for a record that
 * already exists.
 */
final class Secrets_API_Legacy_Reader {

	const SECRET_OPTION_PREFIX = '_secret_';

	const MASTER_KEY_OPTION = '_secrets_master_key';

	/**
	 * Lists every legacy secret's bare key name. Still read-only: a listing, not a
	 * value.
	 *
	 * @return array|WP_Error List of bare key names on success. WP_Error on failure.
	 */
	public function list_keys() {
		global $wpdb;

		$pattern = $wpdb->esc_like( self::SECRET_OPTION_PREFIX ) . '%';

		$option_names = $wpdb->get_col(
			$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern )
		);

		if ( ! is_array( $option_names ) ) {
			return new WP_Error(
				'legacy_store_unavailable',
				__( 'Could not list legacy secrets.', 'secrets-api' )
			);
		}

		$keys = array();

		foreach ( $option_names as $option_name ) {
			$keys[] = substr( $option_name, strlen( self::SECRET_OPTION_PREFIX ) );
		}

		return $keys;
	}

	/**
	 * Reads and decrypts a legacy secret.
	 *
	 * @param string $key The legacy secret's bare key name (e.g. 'api_key' for the
	 *                     option '_secret_api_key') -- legacy names are not
	 *                     namespaced the way new-format names are.
	 *
	 * @return string|WP_Error Plaintext on success. WP_Error on failure.
	 */
	public function get( $key ) {
		$wrapped_master = get_option( self::MASTER_KEY_OPTION );

		if ( ! is_string( $wrapped_master ) || '' === $wrapped_master ) {
			return new WP_Error(
				'legacy_master_key_missing',
				__( 'The legacy master key option does not exist.', 'secrets-api' )
			);
		}

		$stored_secret = get_option( self::SECRET_OPTION_PREFIX . $key );

		if ( ! is_string( $stored_secret ) || '' === $stored_secret ) {
			return new WP_Error(
				'legacy_secret_missing',
				sprintf(
					/* translators: %s: Legacy secret key name. */
					__( 'No legacy secret found for key "%s".', 'secrets-api' ),
					$key
				)
			);
		}

		$master_key = $this->unwrap_master_key( $wrapped_master );

		if ( is_wp_error( $master_key ) ) {
			return $master_key;
		}

		$plaintext = $this->open( $stored_secret, $master_key );

		wp_secrets_memzero( $master_key );

		return $plaintext;
	}

	/**
	 * Unwraps the legacy master key, trying each site key the legacy system could
	 * plausibly have used to wrap it.
	 *
	 * Trying more than one candidate is safe rather than sloppy: secretbox is
	 * authenticated, so a wrong key returns false, never plausible-looking garbage.
	 * A candidate that successfully opens the master key record is, to within the
	 * strength of the Poly1305 tag, the key that sealed it.
	 *
	 * This exists because the two derivations are not interchangeable and an
	 * operator can easily end up with records sealed under one while the other
	 * looks current. The likely sequence: a site runs the legacy system with no
	 * WP_SECRETS_KEY (so its records are sealed under the salt fallback), then
	 * installs this plugin, follows the new format's own documentation and defines
	 * WP_SECRETS_KEY, and only then runs `wp secret migrate-legacy`. Deriving from
	 * the now-defined constant alone would fail every key with a generic
	 * decryption error, and nothing in that message would suggest that the value
	 * is perfectly recoverable under the other derivation.
	 *
	 * @param string $wrapped_master The stored, wrapped master key record.
	 *
	 * @return string|WP_Error Raw 32-byte master key, or WP_Error if no candidate
	 *                          opened it.
	 */
	private function unwrap_master_key( $wrapped_master ) {
		$candidates = $this->candidate_site_keys();

		if ( is_wp_error( $candidates ) ) {
			return $candidates;
		}

		foreach ( $candidates as $site_key ) {
			$master_key = $this->open( $wrapped_master, $site_key );

			wp_secrets_memzero( $site_key );

			if ( ! is_wp_error( $master_key ) ) {
				return $master_key;
			}
		}

		return new WP_Error(
			'legacy_master_key_unwrap_failed',
			__( 'The legacy master key could not be unwrapped. Neither WP_SECRETS_KEY nor the LOGGED_IN_KEY/LOGGED_IN_SALT fallback produced the key it was sealed under -- check that this site still has the same wp-config.php values it had when the legacy secrets were written.', 'secrets-api' )
		);
	}

	/**
	 * Every site key the legacy system could have wrapped the master key under, in
	 * the order they are worth trying.
	 *
	 * Legacy always hashed the *literal* WP_SECRETS_KEY string -- there is no
	 * raw-base64-bytes candidate here, because the legacy system never produced
	 * one. See this class's docblock.
	 *
	 * @return array|WP_Error List of raw 32-byte candidate keys.
	 */
	private function candidate_site_keys() {
		$candidates = array();

		if ( defined( 'WP_SECRETS_KEY' ) ) {
			$material = constant( 'WP_SECRETS_KEY' );

			if ( is_string( $material ) && '' !== $material ) {
				$candidates[] = sodium_crypto_generichash( $material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
			}
		}

		/*
		 * The prototype supports WP_SECRETS_KEY_PREVIOUS so that rotating
		 * WP_SECRETS_KEY does not strand records sealed under the old value. A site
		 * mid-rotation has records under both, so both have to be candidates here or
		 * the older ones become unreadable the moment this plugin takes over.
		 */
		if ( defined( 'WP_SECRETS_KEY_PREVIOUS' ) ) {
			$previous = constant( 'WP_SECRETS_KEY_PREVIOUS' );

			if ( is_string( $previous ) && '' !== $previous ) {
				$candidates[] = sodium_crypto_generichash( $previous, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
			}
		}

		if ( defined( 'LOGGED_IN_KEY' ) && defined( 'LOGGED_IN_SALT' )
			&& is_string( LOGGED_IN_KEY ) && is_string( LOGGED_IN_SALT )
			&& '' !== LOGGED_IN_KEY && '' !== LOGGED_IN_SALT
		) {
			$candidates[] = sodium_crypto_generichash( LOGGED_IN_KEY . LOGGED_IN_SALT, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		}

		if ( empty( $candidates ) ) {
			return new WP_Error(
				'legacy_key_unavailable',
				__( 'No legacy site key could be derived: WP_SECRETS_KEY and WP_SECRETS_KEY_PREVIOUS are unusable or undefined, and LOGGED_IN_KEY/LOGGED_IN_SALT are not usable either.', 'secrets-api' )
			);
		}

		return $candidates;
	}

	/**
	 * Decrypts a single legacy record.
	 *
	 * @param string $encoded base64( nonce . ciphertext ).
	 * @param string $key     32-byte secretbox key.
	 *
	 * @return string|WP_Error
	 */
	private function open( $encoded, $key ) {
		$raw = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding a legacy encrypted record, not obfuscating code.

		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return new WP_Error(
				'legacy_record_malformed',
				__( 'The legacy record is not valid base64, or is too short to contain a nonce.', 'secrets-api' )
			);
		}

		$nonce      = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		$plaintext = sodium_crypto_secretbox_open( $ciphertext, $nonce, $key );

		if ( false === $plaintext ) {
			return new WP_Error(
				'legacy_decryption_failed',
				__( 'The legacy record could not be decrypted with the derived key.', 'secrets-api' )
			);
		}

		return $plaintext;
	}
}
