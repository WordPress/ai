<?php
/**
 * Cache storage backed by the object cache, with a transient fallback.
 *
 * @package WordPress\AI\Cache
 */

declare( strict_types=1 );

namespace WordPress\AI\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Small wrapper around the WordPress object cache.
 *
 * Values are stored in a dedicated cache group. When the site has no
 * persistent object cache the same values are stored as transients, so the
 * layer still works without Redis or Memcached (at the cost of option rows).
 *
 * @since 1.6.0
 */
class Cache_Store {

	/**
	 * Cache group used for object cache entries.
	 *
	 * @since 1.6.0
	 *
	 * @var string
	 */
	public const GROUP = 'wpai';

	/**
	 * Prefix used for transient names when no persistent cache is available.
	 *
	 * @since 1.6.0
	 *
	 * @var string
	 */
	private const TRANSIENT_PREFIX = 'wpai_cache_';

	/**
	 * Returns whether the site has a persistent object cache.
	 *
	 * @since 1.6.0
	 *
	 * @return bool True when a persistent object cache is in use.
	 */
	public function is_persistent(): bool {
		return (bool) wp_using_ext_object_cache();
	}

	/**
	 * Gets a cached value.
	 *
	 * @since 1.6.0
	 *
	 * @param string $key      The cache key.
	 * @param mixed  $fallback Optional. Value returned when the key is missing. Default null.
	 * @return mixed The cached value, or `$fallback`.
	 */
	public function get( string $key, $fallback = null ) {
		$found = false;
		$value = wp_cache_get( $key, self::GROUP, false, $found );

		if ( $found ) {
			return $value;
		}

		$value = get_transient( $this->transient_name( $key ) );

		if ( false === $value ) {
			return $fallback;
		}

		// Back-fill the object cache so the next read in this request is local.
		wp_cache_set( $key, $value, self::GROUP );

		return $value;
	}

	/**
	 * Stores a value.
	 *
	 * @since 1.6.0
	 *
	 * @param string $key   The cache key.
	 * @param mixed  $value The value to store.
	 * @param int    $ttl   Optional. Seconds the value stays valid. Default 3600.
	 * @return bool True when the value was stored.
	 */
	public function set( string $key, $value, int $ttl = HOUR_IN_SECONDS ): bool {
		$ttl = max( 1, $ttl );

		// The caller decides the TTL; cache entries intentionally share the
		// lifetime of the provider response they store.
		wp_cache_set( $key, $value, self::GROUP, $ttl ); // phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined

		// Without a persistent object cache the value above only lives for this
		// request, so keep a transient copy for the next one.
		if ( $this->is_persistent() ) {
			return true;
		}

		return (bool) set_transient( $this->transient_name( $key ), $value, $ttl );
	}

	/**
	 * Deletes a value.
	 *
	 * @since 1.6.0
	 *
	 * @param string $key The cache key.
	 * @return bool True when a stored value was removed.
	 */
	public function delete( string $key ): bool {
		$deleted = (bool) wp_cache_delete( $key, self::GROUP );

		return (bool) delete_transient( $this->transient_name( $key ) ) || $deleted;
	}

	/**
	 * Removes every value stored by this plugin.
	 *
	 * Used on uninstall only. The object cache group is flushed when supported.
	 *
	 * @since 1.6.0
	 */
	public function flush(): void {
		if ( ! function_exists( 'wp_cache_flush_group' ) ) {
			return;
		}

		wp_cache_flush_group( self::GROUP );
	}

	/**
	 * Builds the transient name for a cache key.
	 *
	 * @since 1.6.0
	 *
	 * @param string $key The cache key.
	 * @return string The transient name.
	 */
	private function transient_name( string $key ): string {
		return self::TRANSIENT_PREFIX . $key;
	}
}
