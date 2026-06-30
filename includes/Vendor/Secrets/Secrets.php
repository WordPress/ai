<?php
/**
 * Secrets Public API Facade
 *
 * Static facade that delegates to the Secrets_Manager orchestrator.
 * This is the primary API surface for plugin and theme developers.
 *
 * @package Displace_Secrets_Manager
 */

namespace WordPress\AI\Vendor\Secrets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static facade providing the public secrets API.
 *
 * Usage:
 *   $key = Secrets::get( 'my-plugin/api_key' );
 *   Secrets::set( 'my-plugin/api_key', $value );
 */
final class Secrets {

	/**
	 * Retrieve a secret.
	 *
	 * @param string $key     Namespaced secret key (e.g. 'plugin-slug/key-name').
	 * @param array  $context Optional. Additional context passed to the provider.
	 * @return string|null The decrypted/retrieved value, or null if not found.
	 */
	public static function get( string $key, array $context = [] ): ?string {
		return Secrets_Manager::get_instance()->get( $key, $context );
	}

	/**
	 * Store a secret.
	 *
	 * @param string $key     Namespaced secret key.
	 * @param string $value   The plaintext secret value (encryption handled by provider).
	 * @param array  $context Optional. Additional context.
	 * @return bool True on success.
	 */
	public static function set( string $key, string $value, array $context = [] ): bool {
		return Secrets_Manager::get_instance()->set( $key, $value, $context );
	}

	/**
	 * Delete a secret.
	 *
	 * @param string $key     Namespaced secret key.
	 * @param array  $context Optional. Additional context.
	 * @return bool True on success, false if not found.
	 */
	public static function delete( string $key, array $context = [] ): bool {
		return Secrets_Manager::get_instance()->delete( $key, $context );
	}

	/**
	 * Check existence without retrieving the value.
	 *
	 * @param string $key     Namespaced secret key.
	 * @param array  $context Optional. Additional context.
	 * @return bool
	 */
	public static function exists( string $key, array $context = [] ): bool {
		return Secrets_Manager::get_instance()->exists( $key, $context );
	}

	/**
	 * List secret keys (NOT values) matching a prefix.
	 *
	 * @param string $prefix  Key prefix filter (e.g. 'woocommerce/').
	 * @param array  $context Optional. Additional context.
	 * @return string[]
	 */
	public static function list_keys( string $prefix = '', array $context = [] ): array {
		return Secrets_Manager::get_instance()->list_keys( $prefix, $context );
	}
}
