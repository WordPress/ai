<?php
/**
 * PHPStan stubs for the displace-secrets-manager plugin.
 *
 * Mirrors the public API defined in displace-secrets-manager.php. Used only by static analysis;
 * never autoloaded at runtime.
 *
 * @package WordPress\AI\Stubs
 */

// phpcs:disable

/**
 * Retrieve a secret value.
 *
 * @param string                $key     Namespaced secret key.
 * @param array<string, mixed>  $context Optional. Additional context.
 * @return string|null
 */
function get_secret( string $key, array $context = array() ): ?string {}

/**
 * Store a secret value.
 *
 * @param string               $key     Namespaced secret key.
 * @param string               $value   The plaintext secret value.
 * @param array<string, mixed> $context Optional. Additional context.
 */
function set_secret( string $key, string $value, array $context = array() ): bool {}

/**
 * Delete a secret.
 *
 * @param string               $key     Namespaced secret key.
 * @param array<string, mixed> $context Optional. Additional context.
 */
function delete_secret( string $key, array $context = array() ): bool {}

/**
 * Check whether a secret exists without retrieving its value.
 *
 * @param string               $key     Namespaced secret key.
 * @param array<string, mixed> $context Optional. Additional context.
 */
function secret_exists( string $key, array $context = array() ): bool {}
