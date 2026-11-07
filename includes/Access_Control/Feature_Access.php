<?php
/**
 * Per-feature access control.
 *
 * @package WordPress\AI\Access_Control
 */

declare( strict_types=1 );

namespace WordPress\AI\Access_Control;

use WP_Ability;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Restricts features, and the abilities they register, to chosen roles and users.
 *
 * The `wpai_use_feature` meta capability is the source of truth. Abilities a feature
 * registers carry its ID in `meta['wpai_feature']`, and the capability is
 * enforced on them through the `wp_ability_permission_result` filter, so the
 * restriction holds on every execution path except WP-CLI commands run
 * without `--user`.
 *
 * @since x.x.x
 */
final class Feature_Access {

	/**
	 * Meta capability checked with the feature ID as its argument.
	 *
	 * @since x.x.x
	 * @var string
	 */
	public const CAPABILITY = 'wpai_use_feature';

	/**
	 * Ability meta key holding the ID of the feature that registered the ability.
	 *
	 * @since x.x.x
	 * @var string
	 */
	public const ABILITY_META_KEY = 'wpai_feature';

	/**
	 * Hooks the capability mapping and ability enforcement.
	 *
	 * @since x.x.x
	 */
	public function init(): void {
		add_filter( 'map_meta_cap', array( $this, 'map_meta_cap' ), 10, 4 );
		add_filter( 'wp_ability_permission_result', array( $this, 'filter_ability_permission' ), 10, 4 );
	}

	/**
	 * Returns the option name for a feature's access control toggle.
	 *
	 * @since x.x.x
	 *
	 * @param string $feature_id The feature ID.
	 * @return string The option name.
	 */
	public static function enabled_option( string $feature_id ): string {
		return "wpai_feature_{$feature_id}_access_control_enabled";
	}

	/**
	 * Returns the option name for a feature's allowed roles.
	 *
	 * @since x.x.x
	 *
	 * @param string $feature_id The feature ID.
	 * @return string The option name.
	 */
	public static function roles_option( string $feature_id ): string {
		return "wpai_feature_{$feature_id}_roles";
	}

	/**
	 * Returns the option name for a feature's allowed user IDs.
	 *
	 * @since x.x.x
	 *
	 * @param string $feature_id The feature ID.
	 * @return string The option name.
	 */
	public static function users_option( string $feature_id ): string {
		return "wpai_feature_{$feature_id}_users";
	}

	/**
	 * Maps the `wpai_use_feature` meta capability to `exist` or `do_not_allow`.
	 *
	 * @since x.x.x
	 *
	 * @param list<string>     $caps    Primitive capabilities required.
	 * @param string           $cap     Capability being checked.
	 * @param int              $user_id User ID.
	 * @param array<int,mixed> $args    Extra arguments; `$args[0]` is the feature ID.
	 * @return list<string> Primitive capabilities required.
	 */
	public function map_meta_cap( $caps, $cap, $user_id, $args ): array {
		if ( self::CAPABILITY !== $cap ) {
			return (array) $caps;
		}

		$feature_id = isset( $args[0] ) && is_string( $args[0] ) ? $args[0] : '';
		if ( '' === $feature_id ) {
			return array( 'do_not_allow' );
		}

		return self::user_can_use_feature( (int) $user_id, $feature_id ) ? array( 'exist' ) : array( 'do_not_allow' );
	}

	/**
	 * Checks whether a user may use a feature under its access control settings.
	 *
	 * @since x.x.x
	 *
	 * @param int    $user_id    The user ID (0 for logged out).
	 * @param string $feature_id The feature ID.
	 * @return bool True if the user may use the feature.
	 */
	public static function user_can_use_feature( int $user_id, string $feature_id ): bool {
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) && empty( \WP_CLI::get_config( 'user' ) ) ) {
			return true;
		}

		if ( ! (bool) get_option( self::enabled_option( $feature_id ), false ) ) {
			return true;
		}

		// Unset roles mean "no role restriction".
		// The explicit null default bypasses the registered setting default.
		$roles = get_option( self::roles_option( $feature_id ), null );
		if ( null === $roles ) {
			return true;
		}

		if ( $user_id > 0 && is_multisite() && is_super_admin( $user_id ) ) {
			return true;
		}

		$users = get_option( self::users_option( $feature_id ), array() );
		if ( $user_id > 0 && is_array( $users ) && in_array( $user_id, array_map( 'intval', $users ), true ) ) {
			return true;
		}

		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( ! $user ) {
			return false;
		}

		return is_array( $roles ) && array() !== array_intersect( (array) $user->roles, $roles );
	}

	/**
	 * Denies tagged abilities to users without access to the owning feature.
	 *
	 * @since x.x.x
	 *
	 * @param bool|\WP_Error $result       Permission result so far.
	 * @param string         $ability_name The ability name.
	 * @param mixed          $input        The ability input.
	 * @param mixed          $ability      The ability instance.
	 * @return bool|\WP_Error The permission result.
	 */
	public function filter_ability_permission( $result, $ability_name, $input, $ability ) {
		if ( true !== $result || ! $ability instanceof WP_Ability ) {
			return $result;
		}

		$feature_id = $ability->get_meta_item( self::ABILITY_META_KEY );
		if ( ! is_string( $feature_id ) || '' === $feature_id ) {
			return $result;
		}

		if ( current_user_can( self::CAPABILITY, $feature_id ) ) { // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Plugin meta capability, mapped in map_meta_cap().
			return $result;
		}

		return new WP_Error(
			'wpai_feature_access_denied',
			__( 'You do not have permission to use this feature.', 'ai' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}
}
