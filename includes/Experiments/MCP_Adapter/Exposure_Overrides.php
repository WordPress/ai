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
	 * Filters ability registration args to apply a stored exposure override.
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

		if ( ! isset( $args['meta']['mcp'] ) || ! is_array( $args['meta']['mcp'] ) ) {
			$args['meta']['mcp'] = array();
		}

		$args['meta']['mcp']['public'] = $overrides[ $name ];

		return $args;
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
