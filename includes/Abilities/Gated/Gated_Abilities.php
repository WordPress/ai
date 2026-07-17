<?php
/**
 * Registry of gated abilities.
 *
 * @package WordPress\AI\Abilities\Gated
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Gated;

use Throwable;
use WordPress\AI\Contracts\Gated_Ability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides the abilities that the Abilities Explorer experiment exposes as
 * individual toggles.
 *
 * New gated abilities are added to the class list below, or registered by third
 * parties via the `wpai_gated_abilities` filter — no other code needs to change.
 *
 * @internal
 *
 * @since x.x.x
 */
final class Gated_Abilities {
	/**
	 * The default gated ability classes.
	 *
	 * @since x.x.x
	 *
	 * @var array<class-string<\WordPress\AI\Contracts\Gated_Ability>>
	 */
	private const GATED_ABILITY_CLASSES = array( // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is used as an array const.
		Post_Utilities::class,
		Read_Settings::class,
		Read_Users::class,
		Read_Content::class,
	);

	/**
	 * Gets all registered gated ability instances.
	 *
	 * @since x.x.x
	 *
	 * @return array<int, \WordPress\AI\Contracts\Gated_Ability> The gated ability instances.
	 */
	public static function get_all(): array {
		$classes = array();
		foreach ( self::GATED_ABILITY_CLASSES as $class_name ) {
			$classes[ $class_name::get_key() ] = $class_name;
		}

		/**
		 * Filters the list of gated ability classes.
		 *
		 * Allows developers to add, remove, or replace the abilities exposed as
		 * individual toggles in the Abilities Explorer experiment.
		 *
		 * @since x.x.x
		 *
		 * @param array<string, class-string<\WordPress\AI\Contracts\Gated_Ability>> $classes Gated ability class names, keyed by ability key.
		 */
		$items = apply_filters( 'wpai_gated_abilities', $classes );

		$abilities = array();
		foreach ( $items as $item ) {
			if ( ! is_string( $item ) ) {
				_doing_it_wrong(
					__METHOD__,
					esc_html__( 'Attempted to register an invalid gated ability. Gated abilities must be class-strings.', 'ai' ),
					'x.x.x'
				);
				continue;
			}

			if ( ! is_a( $item, Gated_Ability::class, true ) ) {
				_doing_it_wrong(
					__METHOD__,
					esc_html__( 'Attempted to register an invalid gated ability. All gated abilities must implement the Gated_Ability interface.', 'ai' ),
					'x.x.x'
				);
				continue;
			}

			try {
				$abilities[ $item::get_key() ] = new $item();
			} catch ( Throwable $e ) {
				_doing_it_wrong(
					__METHOD__,
					sprintf(
						/* translators: 1: Gated ability class name, 2: Error message. */
						esc_html__( 'Failed to instantiate gated ability "%1$s": %2$s', 'ai' ),
						esc_html( $item ),
						esc_html( $e->getMessage() )
					),
					'x.x.x'
				);
				continue;
			}
		}

		return array_values( $abilities );
	}
}
