<?php
/**
 * Bridges WordPress connector option storage to displace-secrets-manager.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Key_Encryption;

use function WordPress\AI\get_ai_connectors;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Encrypts and decrypts connector API keys at rest via the displace-secrets-manager API.
 *
 * Stateless filter handlers; safe to instantiate per request. The class never reaches into
 * connector internals — it relies only on the `authentication.setting_name` field exposed by
 * `get_ai_connectors()` and the global `set_secret()` / `get_secret()` / `delete_secret()`
 * functions provided by the displace-secrets-manager plugin.
 *
 * @since x.x.x
 */
final class Secrets_Bridge {

	/**
	 * Secret-key namespace used for every AI connector API key.
	 *
	 * @since x.x.x
	 */
	public const SECRET_NAMESPACE = 'ai';

	/**
	 * Whether the read filter is currently bypassed.
	 *
	 * Used to allow internal `get_option()` calls during migration to read the raw stored value
	 * without being intercepted by the read filter (which would otherwise short-circuit and
	 * return the empty placeholder).
	 *
	 * @since x.x.x
	 * @var bool
	 */
	private bool $bypass_read_filter = false;

	/**
	 * Registers transparent read/write filters for every connector API key option.
	 *
	 * @since x.x.x
	 */
	public function register_option_filters(): void {
		foreach ( $this->get_connector_setting_names() as $setting_name ) {
			$write_hook = "pre_update_option_{$setting_name}";
			$read_hook  = "option_{$setting_name}";

			if ( false === has_filter( $write_hook, array( $this, 'on_write' ) ) ) {
				add_filter( $write_hook, array( $this, 'on_write' ), 10, 1 );
			}

			if ( false !== has_filter( $read_hook, array( $this, 'on_read' ) ) ) {
				continue;
			}

			add_filter( $read_hook, array( $this, 'on_read' ), 10, 2 );
		}
	}

	/**
	 * Unregisters every transparent option filter previously installed.
	 *
	 * Called before `decrypt_all()` so the plaintext writes during
	 * reversal are not re-encrypted by the very filters we are tearing down.
	 *
	 * @since x.x.x
	 */
	public function unregister_option_filters(): void {
		foreach ( $this->get_connector_setting_names() as $setting_name ) {
			remove_filter( "pre_update_option_{$setting_name}", array( $this, 'on_write' ), 10 );
			remove_filter( "option_{$setting_name}", array( $this, 'on_read' ), 10 );
		}
	}

	/**
	 * Encrypts every existing plaintext connector API key into the secrets store.
	 *
	 * Reads each `connectors_ai_*_api_key` option, stores it as a secret, and
	 * writes the wp_options row back to an empty string. Skips empty values.
	 * After completion, registers the read filter so subsequent reads in
	 * the same request return the decrypted value.
	 *
	 * @since x.x.x
	 *
	 * @return int Number of keys encrypted.
	 */
	public function encrypt_all(): int {
		if ( ! $this->is_secrets_manager_available() ) {
			return 0;
		}

		// Tear down filters first so the `update_option` calls below
		// don't get intercepted by `on_write` which would "helpfully"
		// delete the secret we just stored.
		$this->unregister_option_filters();

		$count = 0;
		foreach ( $this->get_connector_setting_names() as $connector_id => $setting_name ) {
			$plaintext = $this->read_raw_option( $setting_name );
			if ( '' === $plaintext ) {
				continue;
			}

			$secret_key = $this->secret_key( $connector_id );

			$stored = set_secret( $secret_key, $plaintext );
			if ( ! $stored ) {
				continue;
			}

			// Verify the secret actually persisted before we drop the plaintext.
			if ( get_secret( $secret_key ) !== $plaintext ) {
				continue;
			}

			update_option( $setting_name, '' );
			++$count;
		}

		// Flush the alloptions cache so subsequent get_option() calls in the same request don't
		// serve stale plaintext from cache before our read filter is in place.
		wp_cache_delete( 'alloptions', 'options' );

		$this->register_option_filters();

		return $count;
	}

	/**
	 * Decrypts every secret back into plaintext wp_options storage and removes the secret.
	 *
	 * Used when the user opts out of the experiment or deactivates the
	 * plugin while the experiment is enabled, so the user is never locked out
	 * of their own credentials.
	 *
	 * @since x.x.x
	 *
	 * @return int Number of keys restored.
	 */
	public function decrypt_all(): int {
		if ( ! $this->is_secrets_manager_available() ) {
			return 0;
		}

		// Tear down the transparent filters first so the plaintext writes below are not
		// immediately re-encrypted by `on_write`.
		$this->unregister_option_filters();

		$count = 0;
		foreach ( $this->get_connector_setting_names() as $connector_id => $setting_name ) {
			$plaintext = get_secret( $this->secret_key( $connector_id ) );
			if ( null === $plaintext || '' === $plaintext ) {
				continue;
			}

			update_option( $setting_name, $plaintext );
			delete_secret( $this->secret_key( $connector_id ) );
			++$count;
		}

		wp_cache_delete( 'alloptions', 'options' );

		return $count;
	}

	/**
	 * Filter callback for `pre_update_option_{$setting_name}`.
	 *
	 * Stores the secret out-of-band and forces the wp_options row to remain empty.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $value New value being written.
	 * @return string Always empty — the real value lives in the secrets store.
	 */
	public function on_write( $value ): string {
		if ( ! is_string( $value ) || '' === $value ) {
			$this->delete_secret_for_current_filter();
			return '';
		}

		if ( ! $this->is_secrets_manager_available() ) {
			// Without the secrets manager we cannot encrypt, so fail safe by passing the value
			// through unmodified rather than dropping the user's key on the floor.
			return $value;
		}

		$connector_id = $this->connector_id_for_current_filter();
		if ( null === $connector_id ) {
			return $value;
		}

		set_secret( $this->secret_key( $connector_id ), $value );
		return '';
	}

	/**
	 * Filter callback for `option_{$setting_name}`.
	 *
	 * Returns the decrypted secret if one is stored; otherwise passes
	 * through to the stored value (which may be a not-yet-migrated plaintext key).
	 *
	 * @since x.x.x
	 *
	 * @param mixed  $value  Stored option value.
	 * @param string $option Option name.
	 * @return mixed Decrypted value, or the original stored value.
	 */
	public function on_read( $value, string $option ) {
		if ( $this->bypass_read_filter ) {
			return $value;
		}

		if ( ! $this->is_secrets_manager_available() ) {
			return $value;
		}

		$connector_id = $this->connector_id_from_setting_name( $option );
		if ( null === $connector_id ) {
			return $value;
		}

		$secret = get_secret( $this->secret_key( $connector_id ) );
		if ( null === $secret ) {
			return $value;
		}

		return $secret;
	}

	/**
	 * Returns whether the displace-secrets-manager plugin is loaded.
	 *
	 * @since x.x.x
	 *
	 * @return bool Whether the displace-secrets-manager plugin is loaded.
	 */
	public function is_secrets_manager_available(): bool {
		return function_exists( 'set_secret' )
			&& function_exists( 'get_secret' )
			&& function_exists( 'delete_secret' );
	}

	/**
	 * Returns a map of connector_id => setting_name for every connector that uses api_key auth.
	 *
	 * Includes inactive connectors so we can clean up keys stored by
	 * previously-active connectors.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, string>
	 */
	public function get_connector_setting_names(): array {
		$map = array();

		foreach ( get_ai_connectors( false ) as $connector_id => $data ) {
			$auth = $data['authentication'] ?? array();

			if ( ! is_array( $auth ) ) {
				continue;
			}

			if ( ( $auth['method'] ?? '' ) !== 'api_key' ) {
				continue;
			}

			$setting_name = $auth['setting_name'] ?? '';
			if ( ! is_string( $setting_name ) || '' === $setting_name ) {
				continue;
			}

			$map[ $connector_id ] = $setting_name;
		}

		return $map;
	}

	/**
	 * Reads a wp_option without triggering our read filter (returns the actual stored value).
	 *
	 * @since x.x.x
	 *
	 * @param string $option_name The wp_option name.
	 * @return string The raw option value.
	 */
	private function read_raw_option( string $option_name ): string {
		$this->bypass_read_filter = true;
		try {
			$value = get_option( $option_name, '' );
		} finally {
			$this->bypass_read_filter = false;
		}

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Builds the namespaced secret key for a given connector id.
	 *
	 * @since x.x.x
	 *
	 * @param string $connector_id The connector id.
	 * @return string The namespaced secret key.
	 */
	private function secret_key( string $connector_id ): string {
		return self::SECRET_NAMESPACE . '/' . $connector_id . '_api_key';
	}

	/**
	 * Reverse-lookup: given the wp_option name from the current filter context, find the connector id.
	 *
	 * @since x.x.x
	 *
	 * @param string $setting_name The wp_option name.
	 * @return string|null The connector id, or null if not found.
	 */
	private function connector_id_from_setting_name( string $setting_name ): ?string {
		foreach ( $this->get_connector_setting_names() as $connector_id => $candidate ) {
			if ( $candidate === $setting_name ) {
				return $connector_id;
			}
		}
		return null;
	}

	/**
	 * Resolves the connector id from the current `pre_update_option_{name}` filter.
	 *
	 * WordPress strips the prefix before invoking the callback, so we
	 * recover the option name from `current_filter()` and then map it
	 * to a connector id.
	 *
	 * @since x.x.x
	 *
	 * @return string|null The connector id, or null if not found.
	 */
	private function connector_id_for_current_filter(): ?string {
		$filter = current_filter();
		if ( ! is_string( $filter ) || 0 !== strpos( $filter, 'pre_update_option_' ) ) {
			return null;
		}

		$setting_name = substr( $filter, strlen( 'pre_update_option_' ) );
		return $this->connector_id_from_setting_name( $setting_name );
	}

	/**
	 * Deletes the secret tied to the current write-filter context, if any.
	 *
	 * Called when an empty value is being written (treat as "clear the key").
	 *
	 * @since x.x.x
	 */
	private function delete_secret_for_current_filter(): void {
		if ( ! $this->is_secrets_manager_available() ) {
			return;
		}

		$connector_id = $this->connector_id_for_current_filter();
		if ( null === $connector_id ) {
			return;
		}

		delete_secret( $this->secret_key( $connector_id ) );
	}
}
