<?php
/**
 * Site-owner overrides for MCP ability exposure.
 *
 * @package WordPress\AI\Experiments\MCP_Adapter
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\MCP_Adapter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies stored per-ability exposure overrides to ability registration.
 *
 * The MCP Adapter plugin resolves exposure from `meta.mcp.public` (falling
 * back to `meta.public`). Overrides saved from the MCP Access screen are
 * injected into that meta key at registration time, so the adapter's default
 * server picks them up without the two plugins depending on each other.
 *
 * @since x.x.x
 */
final class Exposure_Overrides {
	/**
	 * Option storing the per-ability exposure overrides.
	 *
	 * Map of ability name => bool (true: exposed, false: hidden). Abilities
	 * not present in the map keep their registration-time default.
	 *
	 * @since x.x.x
	 * @var string
	 */
	public const OPTION_NAME = 'wpai_mcp_exposed_abilities';

	/**
	 * Ability namespace reserved for the adapter's own default-server tools.
	 *
	 * @since x.x.x
	 * @var string
	 */
	public const ADAPTER_NAMESPACE = 'mcp-adapter';

	/**
	 * Checks whether an ability belongs to the adapter's reserved namespace.
	 *
	 * @since x.x.x
	 *
	 * @param string $name Ability name.
	 *
	 * @return bool Whether the ability is one of the adapter's own tools.
	 */
	public static function is_adapter_ability( string $name ): bool {
		return 0 === strpos( $name, self::ADAPTER_NAMESPACE . '/' );
	}

	/**
	 * Registration-time exposure defaults, keyed by ability name.
	 *
	 * Captured before an override is injected, so the original default stays
	 * recoverable for the settings screen even though the override is baked
	 * into the registered ability's meta.
	 *
	 * @since x.x.x
	 * @var array<string, bool>
	 */
	private static array $registration_defaults = array();

	/**
	 * Filters ability registration args to apply a stored exposure override.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $args Ability registration args.
	 * @param string               $name Ability name.
	 *
	 * @return array<string, mixed> Filtered args.
	 */
	public static function filter_ability_args( array $args, string $name ): array {
		$overrides = self::get_overrides();

		if ( ! array_key_exists( $name, $overrides ) ) {
			return $args;
		}

		if ( ! isset( $args['meta'] ) || ! is_array( $args['meta'] ) ) {
			$args['meta'] = array();
		}

		self::$registration_defaults[ $name ] = self::resolve_meta_exposure( $args['meta'] );

		if ( ! isset( $args['meta']['mcp'] ) || ! is_array( $args['meta']['mcp'] ) ) {
			$args['meta']['mcp'] = array();
		}

		$args['meta']['mcp']['public'] = $overrides[ $name ];

		return $args;
	}

	/**
	 * Returns the stashed registration-time exposure default for an ability.
	 *
	 * Only available for abilities that had an override applied during
	 * registration in the current request.
	 *
	 * @since x.x.x
	 *
	 * @param string $name Ability name.
	 *
	 * @return bool|null The registration-time default, or null if not stashed.
	 */
	public static function get_registration_default( string $name ): ?bool {
		return self::$registration_defaults[ $name ] ?? null;
	}

	/**
	 * Resolves effective MCP exposure from ability meta.
	 *
	 * Delegates to the adapter's `McpAbilityExposure` when available, with a
	 * matching local fallback.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $meta Ability meta.
	 *
	 * @return bool Whether the meta resolves to MCP exposure.
	 */
	public static function resolve_meta_exposure( array $meta ): bool {
		if ( class_exists( '\WP\MCP\Abilities\McpAbilityExposure' ) ) {
			return \WP\MCP\Abilities\McpAbilityExposure::is_meta_public( $meta );
		}

		$mcp_meta = $meta['mcp'] ?? array();

		if ( ! is_array( $mcp_meta ) ) {
			return false;
		}

		if ( isset( $mcp_meta['public'] ) ) {
			return (bool) $mcp_meta['public'];
		}

		return true === ( $meta['public'] ?? false );
	}

	/**
	 * Returns the stored exposure overrides.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, bool> Map of ability name => exposed flag.
	 */
	public static function get_overrides(): array {
		static $cached_raw       = null;
		static $cached_sanitized = array();

		$overrides = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $overrides ) ) {
			return array();
		}

		// Memoize the sanitize pass: it runs once per ability registration.
		if ( $overrides === $cached_raw ) {
			return $cached_sanitized;
		}

		$sanitized = array();
		foreach ( $overrides as $name => $exposed ) {
			if ( ! is_string( $name ) || '' === $name ) {
				continue;
			}

			if ( self::is_adapter_ability( $name ) ) {
				continue;
			}

			$sanitized[ $name ] = (bool) $exposed;
		}

		$cached_raw       = $overrides;
		$cached_sanitized = $sanitized;

		return $sanitized;
	}

	/**
	 * Persists exposure overrides, merging into the stored map.
	 *
	 * A `null` value removes the override for that ability, restoring the
	 * ability's registration-time default.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, bool|null> $changes Map of ability name => exposed flag or null.
	 */
	public static function save_overrides( array $changes ): void {
		$overrides = self::get_overrides();

		foreach ( $changes as $name => $exposed ) {
			if ( null === $exposed ) {
				unset( $overrides[ $name ] );
				continue;
			}

			$overrides[ $name ] = (bool) $exposed;
		}

		update_option( self::OPTION_NAME, $overrides );
	}
}
