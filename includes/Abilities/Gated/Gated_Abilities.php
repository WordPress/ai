<?php
/**
 * Registry of gated abilities.
 *
 * @package WordPress\AI\Abilities\Gated
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Gated;

use Throwable;
use WordPress\AI\Abstracts\Abstract_Gated_Ability;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Provides the abilities gated behind the Custom Abilities experiment.
 *
 * New gated abilities are added to the class list below, or registered by third
 * parties via the `wpai_gated_abilities` filter — no other code needs to change.
 *
 * @internal
 *
 * @since 1.3.0
 */
final class Gated_Abilities {
	/**
	 * The default gated ability classes.
	 *
	 * @since 1.3.0
	 *
	 * @var array<class-string<\WordPress\AI\Abstracts\Abstract_Gated_Ability>>
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
	 * @since 1.3.0
	 *
	 * @return array<int, \WordPress\AI\Abstracts\Abstract_Gated_Ability> The gated ability instances.
	 */
	public static function get_all(): array {
		/**
		 * Filters the list of gated ability classes.
		 *
		 * Allows developers to add, remove, or replace the abilities gated behind
		 * the Custom Abilities experiment.
		 *
		 * @since 1.3.0
		 *
		 * @param array<class-string<\WordPress\AI\Abstracts\Abstract_Gated_Ability>> $classes Gated ability class names.
		 */
		$items = apply_filters( 'wpai_gated_abilities', self::GATED_ABILITY_CLASSES );

		$class_names = array();

		foreach ( (array) $items as $item ) {
			if ( ! is_string( $item ) ) {
				_doing_it_wrong(
					__METHOD__,
					esc_html__( 'Attempted to register an invalid gated ability. Gated abilities must be class-strings.', 'ai' ),
					'1.3.0'
				);
				continue;
			}

			$class_names[] = $item;
		}

		$abilities = array();
		foreach ( array_unique( $class_names ) as $item ) {
			if ( ! is_a( $item, Abstract_Gated_Ability::class, true ) ) {
				_doing_it_wrong(
					__METHOD__,
					esc_html__( 'Attempted to register an invalid gated ability. All gated abilities must extend Abstract_Gated_Ability.', 'ai' ),
					'1.3.0'
				);
				continue;
			}

			try {
				$abilities[] = new $item();
			} catch ( Throwable $e ) {
				_doing_it_wrong(
					__METHOD__,
					sprintf(
						/* translators: 1: Gated ability class name, 2: Error message. */
						esc_html__( 'Failed to instantiate gated ability "%1$s": %2$s', 'ai' ),
						esc_html( $item ),
						esc_html( $e->getMessage() )
					),
					'1.3.0'
				);
				continue;
			}
		}

		return $abilities;
	}
}
