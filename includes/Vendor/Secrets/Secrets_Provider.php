<?php
/**
 * Secrets Provider Interface
 *
 * All secrets backends must implement this interface. Providers are
 * registered via the `secrets_register_providers` action or by
 * calling displace_secrets_register_provider() directly.
 *
 * @package Displace_Secrets_Manager
 */

namespace WordPress\AI\Vendor\Secrets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Secrets_Provider {

	/**
	 * Unique identifier for this provider (e.g. 'encrypted-options', 'aws-kms').
	 *
	 * @return string
	 */
	public function get_id(): string;

	/**
	 * Human-readable name for admin UI display.
	 *
	 * @return string
	 */
	public function get_name(): string;

	/**
	 * Priority for automatic provider selection.
	 *
	 * Higher values mean higher preference. The manager picks the available
	 * provider with the highest priority unless a specific provider is forced
	 * via constant or filter.
	 *
	 * Built-in priorities:
	 *   - Options (plaintext): 10
	 *   - Encrypted Options:   50
	 *
	 * Third-party remote backends should use 80+ to be preferred when available.
	 *
	 * @return int
	 */
	public function get_priority(): int;

	/**
	 * Whether this provider is available in the current environment.
	 *
	 * For example, the encrypted provider returns false if no encryption
	 * key is derivable; the K8s provider returns false outside a cluster.
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * Retrieve a secret value.
	 *
	 * @param string $key     The secret identifier.
	 * @param array  $context Additional context (plugin slug, etc.).
	 * @return string|null The secret value, or null if not found.
	 *
	 * @throws Secrets_Exception On backend errors.
	 */
	public function get( string $key, array $context = [] ): ?string;

	/**
	 * Store a secret value.
	 *
	 * @param string $key     The secret identifier.
	 * @param string $value   The secret value.
	 * @param array  $context Additional context.
	 * @return bool True on success.
	 *
	 * @throws Secrets_Exception On backend errors.
	 */
	public function set( string $key, string $value, array $context = [] ): bool;

	/**
	 * Delete a secret.
	 *
	 * @param string $key     The secret identifier.
	 * @param array  $context Additional context.
	 * @return bool True on success, false if not found.
	 */
	public function delete( string $key, array $context = [] ): bool;

	/**
	 * Check whether a secret exists.
	 *
	 * @param string $key     The secret identifier.
	 * @param array  $context Additional context.
	 * @return bool
	 */
	public function exists( string $key, array $context = [] ): bool;

	/**
	 * List secret keys matching an optional prefix.
	 *
	 * @param string $prefix  Key prefix filter (e.g. 'woocommerce/').
	 * @param array  $context Additional context.
	 * @return string[] Array of matching keys (values are NOT returned).
	 */
	public function list_keys( string $prefix = '', array $context = [] ): array;

	/**
	 * Provider-specific health check for Site Health integration.
	 *
	 * @return array{status: string, message: string} Status is one of 'good', 'recommended', or 'critical'.
	 */
	public function health_check(): array;
}
