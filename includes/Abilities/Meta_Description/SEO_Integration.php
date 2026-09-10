<?php
/**
 * SEO plugin integration utility for meta description storage.
 *
 * @package WordPress\AI\Abilities\Meta_Description
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Meta_Description;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles detection of active SEO plugins and their meta description storage keys.
 *
 * @since 0.7.0
 */
class SEO_Integration {

	/**
	 * Fallback meta key when no SEO plugin is detected.
	 *
	 * @since 0.7.0
	 * @var string
	 */
	public const FALLBACK_META_KEY = 'wpai_meta_description';

	/**
	 * Transient key caching the detected SEO plugin.
	 *
	 * @since x.x.x
	 * @var string
	 */
	public const CACHE_KEY = 'wpai_active_seo_plugin';

	/**
	 * Cached value meaning "no supported SEO plugin is active".
	 *
	 * @since x.x.x
	 * @var string
	 */
	private const CACHE_NONE = 'none';

	/**
	 * Returns the list of supported SEO plugins and their meta keys.
	 *
	 * @since 0.7.0
	 *
	 * @return array<string, array{file: string, meta_key: string}> Map of plugin slug to detection info.
	 */
	public static function get_supported_plugins(): array {
		$plugins = array(
			'yoast-seo'      => array(
				'file'     => 'wordpress-seo/wp-seo.php',
				'meta_key' => '_yoast_wpseo_metadesc',
			),
			'rank-math'      => array(
				'file'     => 'seo-by-rank-math/rank-math.php',
				'meta_key' => 'rank_math_description',
			),
			'all-in-one-seo' => array(
				'file'     => 'all-in-one-seo-pack/all_in_one_seo_pack.php',
				'meta_key' => '_aioseo_description',
			),
			'seopress'       => array(
				'file'     => 'wp-seopress/seopress.php',
				'meta_key' => '_seopress_titles_desc',
			),
		);

		/**
		 * Filters the list of supported SEO plugins for meta description integration.
		 *
		 * Allows developers to register additional SEO plugins or modify existing entries.
		 *
		 * @since 0.7.0
		 *
		 * @param array<string, array{file: string, meta_key: string}> $plugins Map of plugin slug to detection info.
		 */
		return (array) apply_filters( 'wpai_meta_description_seo_plugins', $plugins );
	}

	/**
	 * Detects the currently active SEO plugin.
	 *
	 * Result is cached with a TTL and flushed on plugin activation/deactivation.
	 *
	 * @since 0.7.0
	 *
	 * @return string|null The slug of the active SEO plugin, or null if none detected.
	 */
	public static function detect_active_plugin(): ?string {
		$cached = get_transient( self::CACHE_KEY );

		if ( self::CACHE_NONE === $cached ) {
			return null;
		}

		if ( ! empty( $cached ) ) {
			return $cached;
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( self::get_supported_plugins() as $slug => $info ) {
			if ( is_plugin_active( $info['file'] ) ) {
				set_transient( self::CACHE_KEY, $slug, DAY_IN_SECONDS );
				return $slug;
			}
		}

		// Cache the "none active" result so the scan is not repeated every call.
		set_transient( self::CACHE_KEY, self::CACHE_NONE, DAY_IN_SECONDS );
		return null;
	}

	/**
	 * Registers cache invalidation on plugin activation and deactivation.
	 *
	 * Runs regardless of the Meta Description experiment state so the cache is
	 * never left stale when a plugin changes while the experiment is disabled.
	 *
	 * @since x.x.x
	 */
	public static function register_cache_invalidation(): void {
		add_action( 'activated_plugin', array( self::class, 'clear_cache_on_plugin_change' ), 10, 2 );
		add_action( 'deactivated_plugin', array( self::class, 'clear_cache_on_plugin_change' ), 10, 2 );
	}

	/**
	 * Clears the cache when a plugin is activated or deactivated. Also
	 * supports multisite and network activated/deactivated plugins.
	 *
	 * @since x.x.x
	 *
	 * @param string $plugin       Path to the plugin file, relative to the plugins directory.
	 * @param bool   $network_wide Whether the change applied network-wide.
	 */
	public static function clear_cache_on_plugin_change( string $plugin, bool $network_wide = false ): void {
		if ( $network_wide && is_multisite() ) {
			self::clear_cache_for_network();
			return;
		}

		self::clear_cache();
	}

	/**
	 * Clears the detected SEO plugin cache for the current site.
	 *
	 * @since x.x.x
	 */
	public static function clear_cache(): void {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Clears the detected SEO plugin cache for every site on the network.
	 *
	 * @since x.x.x
	 */
	private static function clear_cache_for_network(): void {
		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog
			self::clear_cache();
			restore_current_blog();
		}
	}

	/**
	 * Returns the meta key to use for storing the meta description.
	 *
	 * @since 0.7.0
	 *
	 * @param string|null $plugin_slug Optional. The SEO plugin slug. If null, auto-detects.
	 * @return string The meta key.
	 */
	public static function get_meta_key( ?string $plugin_slug = null ): string {
		if ( null === $plugin_slug ) {
			$plugin_slug = self::detect_active_plugin();
		}

		$plugins = self::get_supported_plugins();
		$key     = self::FALLBACK_META_KEY;

		if ( $plugin_slug && isset( $plugins[ $plugin_slug ] ) ) {
			$key = $plugins[ $plugin_slug ]['meta_key'];
		}

		/**
		 * Filters the meta key used to store the meta description.
		 *
		 * @since 0.7.0
		 *
		 * @param string      $key         The meta key.
		 * @param string|null $plugin_slug The detected SEO plugin slug, or null.
		 */
		return (string) apply_filters( 'wpai_meta_description_meta_key', $key, $plugin_slug );
	}
}
