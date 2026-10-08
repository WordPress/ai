<?php
/**
 * Bridges WordPress connector option storage to the bundled Secrets API.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Key_Encryption;

use WordPress\AI\Vendor\Secrets\Secrets;
use WordPress\AI\Vendor\Secrets\Secrets_Manager;
use WordPress\AI\Vendor\Secrets\Secrets_Provider;
use WordPress\AI\Vendor\Secrets\Secrets_Provider_Encrypted_Options;

use function WordPress\AI\get_ai_connectors;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Encrypts and decrypts connector API keys at rest via the bundled Secrets API.
 *
 * Stateless filter handlers; safe to instantiate per request. The class never reaches into
 * connector internals — it relies only on the `authentication.setting_name` field exposed by
 * `get_ai_connectors()` and the vendored {@see \WordPress\AI\Vendor\Secrets\Secrets} facade.
 *
 * Secrets live under the `ai/` namespace and every call passes an explicit `['plugin' => 'ai']`
 * context so the Secrets access-control layer grants self-namespace access regardless of the
 * current user. This matters because these option filters run in unauthenticated contexts
 * (cron, front-end, REST) where no user holds the `manage_secrets` capability.
 *
 * @since 1.1.0
 */
final class Secrets_Bridge {

	/**
	 * Secret-key namespace used for every AI connector API key.
	 *
	 * @since 1.1.0
	 */
	public const SECRET_NAMESPACE = 'ai';

	/**
	 * Whether the read filter is currently bypassed.
	 *
	 * Used to allow internal `get_option()` calls during migration to read the raw stored value
	 * without being intercepted by the read filter (which would otherwise short-circuit and
	 * return the empty placeholder).
	 *
	 * @since 1.1.0
	 * @var bool
	 */
	private bool $bypass_read_filter = false;

	/**
	 * Registers transparent read/write filters for every connector API key option.
	 *
	 * @since 1.1.0
	 */
	public function register_option_filters(): void {
		foreach ( $this->get_connector_setting_names() as $setting_name ) {
			$write_hook   = "pre_update_option_{$setting_name}";
			$read_hook    = "option_{$setting_name}";
			$default_hook = "default_option_{$setting_name}";

			if ( false === has_filter( $write_hook, array( $this, 'on_write' ) ) ) {
				add_filter( $write_hook, array( $this, 'on_write' ), 10, 1 );
			}

			if ( false === has_filter( $read_hook, array( $this, 'on_read' ) ) ) {
				add_filter( $read_hook, array( $this, 'on_read' ), 10, 2 );
			}

			if ( false !== has_filter( $default_hook, array( $this, 'on_read_default' ) ) ) {
				continue;
			}

			add_filter( $default_hook, array( $this, 'on_read_default' ), 11, 2 );
		}
	}

	/**
	 * Unregisters every transparent option filter previously installed.
	 *
	 * Called before `decrypt_all()` so the plaintext writes during
	 * reversal are not re-encrypted by the very filters we are tearing down.
	 *
	 * @since 1.1.0
	 */
	public function unregister_option_filters(): void {
		foreach ( $this->get_connector_setting_names() as $setting_name ) {
			remove_filter( "pre_update_option_{$setting_name}", array( $this, 'on_write' ), 10 );
			remove_filter( "option_{$setting_name}", array( $this, 'on_read' ), 10 );
			remove_filter( "default_option_{$setting_name}", array( $this, 'on_read_default' ), 11 );
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
	 * @since 1.1.0
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

			$stored = $this->set_secret( $secret_key, $plaintext );
			if ( ! $stored ) {
				continue;
			}

			// Verify the secret actually persisted before we drop the plaintext.
			if ( $this->get_secret( $secret_key ) !== $plaintext ) {
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
	 * @since 1.1.0
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
			$plaintext = $this->get_secret( $this->secret_key( $connector_id ) );
			if ( null === $plaintext || '' === $plaintext ) {
				continue;
			}

			update_option( $setting_name, $plaintext );
			$this->delete_secret( $this->secret_key( $connector_id ) );
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
	 * @since 1.1.0
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

		$this->set_secret( $this->secret_key( $connector_id ), $value );
		return '';
	}

	/**
	 * Filter callback for `option_{$setting_name}`.
	 *
	 * Returns the decrypted secret if one is stored; otherwise passes
	 * through to the stored value (which may be a not-yet-migrated plaintext key).
	 *
	 * @since 1.1.0
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

		$secret = $this->get_secret( $this->secret_key( $connector_id ) );
		if ( null === $secret ) {
			return $value;
		}

		return $secret;
	}

	/**
	 * Filter callback for `default_option_{$setting_name}`.
	 *
	 * Fires when `get_option()` finds no stored row for the key. Returns the decrypted
	 * secret if one is stored so the key is readable without a backing row; otherwise passes the
	 * default through untouched.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed  $default_value The default value WordPress would return.
	 * @param string $option        Option name.
	 * @return mixed The decrypted secret, or the original default value.
	 */
	public function on_read_default( $default_value, string $option ) {
		if ( $this->bypass_read_filter ) {
			return $default_value;
		}

		if ( ! $this->is_secrets_manager_available() ) {
			return $default_value;
		}

		$connector_id = $this->connector_id_from_setting_name( $option );
		if ( null === $connector_id ) {
			return $default_value;
		}

		$secret = $this->get_secret( $this->secret_key( $connector_id ) );
		if ( null === $secret || '' === $secret ) {
			return $default_value;
		}

		return $secret;
	}

	/**
	 * Ensures the Secrets API is loaded (via Core, standalone feature plugin, or bundled vendor).
	 *
	 * @since 1.5.0
	 */
	public function ensure_secrets_api(): void {
		if ( function_exists( 'wp_get_secret' ) ) {
			return;
		}

		$load_path = dirname( __DIR__, 2 ) . '/Vendor/Secrets/load.php';
		if ( ! file_exists( $load_path ) ) {
			return;
		}

		// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
		require_once $load_path;
	}

	/**
	 * Returns whether a secrets backend can encrypt in this environment.
	 *
	 * Returns true if the global Secrets API is available, or if the bundled
	 * encrypted-options provider can be activated.
	 *
	 * @since 1.1.0
	 * @since 1.5.0 Supports the global Secrets API when present.
	 *
	 * @return bool Whether an encryption provider is available.
	 */
	public function is_secrets_manager_available(): bool {
		$this->ensure_secrets_api();

		if ( function_exists( 'wp_get_secret' ) || function_exists( 'wp_set_secret' ) ) {
			return true;
		}

		return $this->is_legacy_provider_available();
	}

	/**
	 * Returns whether the bundled legacy secrets backend can encrypt in this environment.
	 *
	 * @since 1.5.0
	 *
	 * @return bool Whether the bundled encryption provider is available.
	 */
	public function is_legacy_provider_available(): bool {
		return null !== $this->active_provider();
	}

	/**
	 * Retrieves a secret value, preferring the global Secrets API when present,
	 * falling back to the bundled provider.
	 *
	 * Transparently promotes any legacy prototype row to the new API on read.
	 *
	 * @since 1.5.0
	 *
	 * @param string $secret_key Namespaced secret key.
	 * @return string|null Decrypted secret value, or null if not found.
	 */
	public function get_secret( string $secret_key ): ?string {
		$this->ensure_secrets_api();

		if ( function_exists( 'wp_get_secret' ) ) {
			$secret = wp_get_secret( $secret_key );
			if ( is_object( $secret ) && method_exists( $secret, 'reveal' ) ) {
				return $secret->reveal();
			}
			if ( is_string( $secret ) && '' !== $secret ) {
				return $secret;
			}
		}

		// If absent in the new API (or Secrets API unavailable), check if legacy store holds it:
		if ( $this->is_legacy_provider_available() ) {
			try {
				$legacy = Secrets::get( $secret_key, $this->secret_context() );
			} catch ( \Throwable $e ) {
				$legacy = null;
			}

			if ( null !== $legacy && '' !== $legacy ) {
				// Transparent read-time promotion if new API is present:
				if ( function_exists( 'wp_set_secret' ) ) {
					$stored = wp_set_secret( $secret_key, $legacy );
					if ( false !== $stored && ! is_wp_error( $stored ) ) {
						delete_option( Secrets_Provider_Encrypted_Options::OPTION_PREFIX . $secret_key );
					}
				}
				return $legacy;
			}
		}

		return null;
	}

	/**
	 * Stores a secret value, preferring the global Secrets API when present,
	 * falling back to the bundled provider.
	 *
	 * @since 1.5.0
	 *
	 * @param string $secret_key Namespaced secret key.
	 * @param string $value      Plaintext secret value.
	 * @return bool True on success, false on failure.
	 */
	public function set_secret( string $secret_key, string $value ): bool {
		$this->ensure_secrets_api();

		if ( function_exists( 'wp_set_secret' ) ) {
			$result = wp_set_secret( $secret_key, $value );
			if ( false !== $result && ! is_wp_error( $result ) ) {
				delete_option( Secrets_Provider_Encrypted_Options::OPTION_PREFIX . $secret_key );
				return true;
			}
		}

		if ( $this->is_legacy_provider_available() ) {
			return Secrets::set( $secret_key, $value, $this->secret_context() );
		}

		return false;
	}

	/**
	 * Deletes a secret from both the global Secrets API (if present) and the bundled provider.
	 *
	 * @since 1.5.0
	 *
	 * @param string $secret_key Namespaced secret key.
	 * @return bool True if deleted or attempted.
	 */
	public function delete_secret( string $secret_key ): bool {
		$this->ensure_secrets_api();

		$deleted = false;

		if ( function_exists( 'wp_delete_secret' ) ) {
			$result = wp_delete_secret( $secret_key );
			if ( false !== $result && ! is_wp_error( $result ) ) {
				$deleted = true;
			}
		}

		if ( $this->is_legacy_provider_available() ) {
			if ( Secrets::delete( $secret_key, $this->secret_context() ) ) {
				$deleted = true;
			}
		}

		delete_option( Secrets_Provider_Encrypted_Options::OPTION_PREFIX . $secret_key );

		return $deleted;
	}

	/**
	 * Migrates any legacy `_secret_ai/*` option rows to the active Secrets API.
	 *
	 * Idempotent: safely reads existing ciphertext via the internal provider, stores
	 * it into the new Secrets API, verifies read-back, and deletes the old option row.
	 *
	 * @since 1.5.0
	 *
	 * @return int Number of secrets migrated.
	 */
	public function maybe_migrate_legacy_secrets(): int {
		$this->ensure_secrets_api();

		if ( ! function_exists( 'wp_set_secret' ) || ! function_exists( 'wp_get_secret' ) ) {
			return 0;
		}

		if ( ! $this->is_legacy_provider_available() ) {
			return 0;
		}

		global $wpdb;

		$like = $wpdb->esc_like( Secrets_Provider_Encrypted_Options::OPTION_PREFIX . self::SECRET_NAMESPACE . '/' ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration query.
		$option_names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$like
			)
		);

		if ( empty( $option_names ) ) {
			return 0;
		}

		$count         = 0;
		$prefix_length = strlen( Secrets_Provider_Encrypted_Options::OPTION_PREFIX );

		foreach ( $option_names as $option_name ) {
			$secret_key = substr( $option_name, $prefix_length );

			try {
				$plaintext = Secrets::get( $secret_key, $this->secret_context() );
			} catch ( \Throwable $e ) {
				continue;
			}

			if ( null === $plaintext || '' === $plaintext ) {
				continue;
			}

			$stored = wp_set_secret( $secret_key, $plaintext );
			if ( false === $stored || is_wp_error( $stored ) ) {
				continue;
			}

			// Verify the new secret was written and matches the plaintext before deleting the old row.
			$secret   = wp_get_secret( $secret_key );
			$readback = is_object( $secret ) && method_exists( $secret, 'reveal' ) ? $secret->reveal() : $secret;
			if ( $readback !== $plaintext ) {
				continue;
			}

			delete_option( $option_name );
			++$count;
		}

		return $count;
	}

	/**
	 * Returns the explicit caller context passed to every Secrets operation.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string, string> The caller context.
	 */
	private function secret_context(): array {
		return array( 'plugin' => self::SECRET_NAMESPACE );
	}

	/**
	 * Lazily registers the bundled encryption provider and returns the active provider.
	 *
	 * @since 1.1.0
	 *
	 * @return \WordPress\AI\Vendor\Secrets\Secrets_Provider|null The active provider, or null.
	 */
	private function active_provider(): ?Secrets_Provider {
		$manager = Secrets_Manager::get_instance();

		if ( null === $manager->get_active_provider_id() ) {
			if ( null === $manager->get_provider( 'encrypted-options' ) ) {
				$manager->register_provider( new Secrets_Provider_Encrypted_Options() );
			}
			$manager->select_provider();
		}

		return $manager->get_active_provider();
	}

	/**
	 * Returns a map of connector_id => setting_name for every connector that uses api_key auth.
	 *
	 * Includes inactive connectors so we can clean up keys stored by
	 * previously-active connectors.
	 *
	 * @since 1.1.0
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
	 * @since 1.1.0
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
	 * @since 1.1.0
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
	 * @since 1.1.0
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
	 * @since 1.1.0
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
	 * @since 1.1.0
	 */
	private function delete_secret_for_current_filter(): void {
		if ( ! $this->is_secrets_manager_available() ) {
			return;
		}

		$connector_id = $this->connector_id_for_current_filter();
		if ( null === $connector_id ) {
			return;
		}

		$this->delete_secret( $this->secret_key( $connector_id ) );
	}
}
