<?php
/**
 * Canonical WordPress abilities: support-surface feature detection.
 *
 * @package WordPress\Abilities
 */
declare( strict_types = 1 );

namespace WordPress\Abilities\Support;

/**
 * Detection map for core support-surface features that abilities may require.
 *
 * Fail-closed: an unknown feature, or a feature the host lacks, reports as
 * unavailable, and the Registrar skips the requiring ability. Polyfill files
 * are loaded through this class via require_once, only from the Registrar,
 * when an ability requires a feature and core lacks it. They are never
 * autoloaded and never ship as part of the package's public API.
 */
final class Features {

	/**
	 * Feature name => polyfill file path, relative to this directory.
	 *
	 * @var array<string, string>
	 */
	private static $polyfills = array(
		'show_in_abilities'          => 'polyfills/show-in-abilities.php',
		'setting_show_in_abilities'  => 'polyfills/setting-show-in-abilities.php',
		'taxonomy_show_in_abilities' => 'polyfills/taxonomy-show-in-abilities.php',
	);

	/**
	 * Polyfills already loaded in this process.
	 *
	 * @var array<string, true>
	 */
	private static $loaded = array();

	/**
	 * Static-only class.
	 */
	private function __construct() {}

	/**
	 * Detects whether a support-surface feature is available on this host.
	 *
	 * @param string $feature The feature key, e.g. 'show_in_abilities'.
	 * @return bool True when the feature is available; unknown features fail closed.
	 */
	public static function is_available( string $feature ): bool {
		if ( 'show_in_abilities' === $feature ) {
			return self::is_show_in_abilities_available();
		}

		if ( 'setting_show_in_abilities' === $feature ) {
			return self::is_setting_show_in_abilities_available();
		}

		if ( 'taxonomy_show_in_abilities' === $feature ) {
			return self::is_taxonomy_show_in_abilities_available();
		}

		return false;
	}

	/**
	 * Loads the polyfill for a feature, when one exists, then re-checks.
	 *
	 * Called only by the Registrar, through require_once, so the file is
	 * never autoloaded. Calling it again is a no-op per feature.
	 *
	 * @param string $feature The feature key.
	 * @return bool Whether the feature is available after loading.
	 */
	public static function load_polyfill( string $feature ): bool {
		if ( ! isset( self::$polyfills[ $feature ] ) ) {
			return false;
		}

		if ( ! isset( self::$loaded[ $feature ] ) ) {
			require_once __DIR__ . '/' . self::$polyfills[ $feature ];
			self::$loaded[ $feature ] = true;
		}

		return self::is_available( $feature );
	}

	/**
	 * Detects the `show_in_abilities` post type registration argument.
	 *
	 * Natively declared on WP_Post_Type since 7.2. On 6.9-7.1 the argument
	 * does not exist, and it is only available once the package polyfill
	 * has been loaded, mirroring the polyfill-backed registration path.
	 *
	 * @return bool
	 */
	private static function is_show_in_abilities_available(): bool {
		if ( class_exists( 'WP_Post_Type', false ) && property_exists( 'WP_Post_Type', 'show_in_abilities' ) ) {
			return true;
		}

		return isset( self::$loaded['show_in_abilities'] );
	}

	/**
	 * Detects the `show_in_abilities` setting registration argument.
	 *
	 * Core declares it in register_setting() since 7.2, together with
	 * _wp_register_initial_settings_for_abilities(), which flags and registers
	 * core's own settings before the abilities read them; the function is the
	 * detectable half.
	 *
	 * @return bool
	 */
	private static function is_setting_show_in_abilities_available(): bool {
		if ( function_exists( '_wp_register_initial_settings_for_abilities' ) ) {
			return true;
		}

		return isset( self::$loaded['setting_show_in_abilities'] );
	}

	/**
	 * Detects the `show_in_abilities` taxonomy registration argument.
	 *
	 * Natively declared on WP_Taxonomy since 7.2. On 6.9-7.1 the argument
	 * does not exist, and it is only available once the package polyfill
	 * has been loaded, mirroring the polyfill-backed registration path.
	 *
	 * @return bool
	 */
	private static function is_taxonomy_show_in_abilities_available(): bool {
		if ( class_exists( 'WP_Taxonomy', false ) && property_exists( 'WP_Taxonomy', 'show_in_abilities' ) ) {
			return true;
		}

		return isset( self::$loaded['taxonomy_show_in_abilities'] );
	}
}
