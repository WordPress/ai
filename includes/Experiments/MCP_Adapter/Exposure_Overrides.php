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
 * @since 0.9.0
 */
final class Exposure_Overrides {
	/**
	 * Option storing the per-ability exposure overrides.
	 *
	 * Map of ability name => bool (true: exposed, false: hidden). Abilities
	 * not present in the map keep their registration-time default.
	 *
	 * @since 0.9.0
	 * @var string
	 */
	public const OPTION_NAME = 'wpai_mcp_exposed_abilities';

	/**
	 * Registration-time exposure defaults, keyed by ability name.
	 *
	 * Captured before an override is injected, so the original default stays
	 * recoverable for the settings screen even though the override is baked
	 * into the registered ability's meta.
	 *
	 * @since 0.9.0
	 * @var array<string, bool>
	 */
	private static array $registration_defaults = array();

	/**
	 * Filters ability registration args to apply a stored exposure override.
	 *
	 * Known limitation, mirrored from the MCP Adapter's own guidance: the
	 * registration layer cannot guarantee the last word on exposure. Consumers
	 * that trigger ability registration before this filter is added (early
	 * `init` access) see unfiltered defaults for that request, and later
	 * `wp_register_ability_args` callbacks can still change the meta. A
	 * resolution-time filter in the adapter is the planned long-term fix.
	 *
	 * @since 0.9.0
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
	 * @since 0.9.0
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
	 * Delegates to the MCP Adapter's resolver when the plugin is active, so
	 * the screen always agrees with what the server actually exposes. The
	 * local fallback mirrors the adapter's documented resolution: an explicit
	 * `meta.mcp.public` wins, otherwise exposure is inherited from
	 * `meta.public`.
	 *
	 * @since 0.9.0
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
	 * @since 0.9.0
	 *
	 * @return array<string, bool> Map of ability name => exposed flag.
	 */
	public static function get_overrides(): array {
		$overrides = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $overrides ) ) {
			return array();
		}

		$sanitized = array();
		foreach ( $overrides as $name => $exposed ) {
			if ( ! is_string( $name ) || '' === $name ) {
				continue;
			}

			$sanitized[ $name ] = (bool) $exposed;
		}

		return $sanitized;
	}

	/**
	 * Persists exposure overrides, merging into the stored map.
	 *
	 * A `null` value removes the override for that ability, restoring the
	 * ability's registration-time default.
	 *
	 * @since 0.9.0
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
