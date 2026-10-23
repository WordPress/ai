<?php
/**
 * The Site Agent tool catalog.
 *
 * @package WordPress\AI\Experiments\Site_Agent
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Site_Agent;

use Throwable;
use WP_Ability;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Chooses which registered abilities the model may call.
 *
 * The catalog is an allowlist, not a discovery mechanism. It contains only
 * abilities reviewed for a conversational surface. Each entry is a normal
 * WordPress ability: its own input schema, permission callback, and
 * annotations remain the authority.
 *
 * @since x.x.x
 */
final class Catalog {

	/**
	 * Filter that adds or removes reviewed ability names.
	 *
	 * Site code can add an ability it has reviewed for this surface, or remove
	 * one. The filter cannot bypass the ability's own permission callback.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const FILTER = 'wpai_site_agent_tools';

	/**
	 * Abilities that are always declared when they are registered.
	 *
	 * @since x.x.x
	 *
	 * @var list<string>
	 */
	private const READ_ABILITIES = array( 'ai/site-agent-read-site' ); // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is a single array constant.

	/**
	 * Abilities declared only when write proposals are enabled.
	 *
	 * @since x.x.x
	 *
	 * @var list<string>
	 */
	private const WRITE_ABILITIES = array( 'ai/site-agent-create-draft', 'ai/site-agent-update-post', 'ai/site-agent-update-setting' ); // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is a single array constant.

	/**
	 * Returns the reviewed ability names for this request.
	 *
	 * @since x.x.x
	 *
	 * @return list<string>
	 */
	public static function names(): array {
		$names = self::READ_ABILITIES;

		if ( Site_Agent::writes_allowed() ) {
			$names = array_merge( $names, self::WRITE_ABILITIES );
		}

		/**
		 * Filters the abilities the Site Agent may declare to the model.
		 *
		 * @since x.x.x
		 *
		 * @param list<string> $names The allowlisted ability names.
		 */
		$filtered = apply_filters( 'wpai_site_agent_tools', $names );

		if ( ! is_array( $filtered ) ) {
			return array();
		}

		$normalized = array();

		foreach ( $filtered as $name ) {
			if ( ! is_string( $name ) || '' === trim( $name ) ) {
				continue;
			}

			$normalized[] = trim( $name );
		}

		return array_values( array_unique( $normalized ) );
	}

	/**
	 * Returns the ability names that are registered and callable by this user.
	 *
	 * @since x.x.x
	 *
	 * @return list<string>
	 */
	public static function available_names(): array {
		$available = array();

		foreach ( self::names() as $name ) {
			$ability = wp_get_ability( $name );

			if ( ! $ability instanceof WP_Ability ) {
				continue;
			}

			if ( ! self::can_declare( $ability ) ) {
				continue;
			}

			$available[] = $name;
		}

		return $available;
	}

	/**
	 * Reports whether the current user could ever invoke the ability.
	 *
	 * This is a coarse, input-free pre-filter. The ability's own permission
	 * callback runs again with the real input when it executes.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Ability $ability The ability.
	 * @return bool True when the ability may be declared.
	 */
	public static function can_declare( WP_Ability $ability ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		try {
			return true === $ability->check_permissions();
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/**
	 * Reports whether an ability changes the site.
	 *
	 * Anything that does not explicitly declare `readonly => true` is treated
	 * as a write, so a missing annotation fails closed into approval.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Ability $ability The ability.
	 * @return bool True when the ability requires approval.
	 */
	public static function is_write( WP_Ability $ability ): bool {
		$annotations = $ability->get_meta_item( 'annotations', array() );

		if ( ! is_array( $annotations ) ) {
			return true;
		}

		return true !== ( $annotations['readonly'] ?? false );
	}
}
