<?php
/**
 * Registers the experimental features to the plugin.
 *
 * @package WordPress\AI\Experiments
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Registers experimental features to the plugin
 *
 * Uses wpai_default_feature_classes filter to register experiments, which are then initialized by the Loader class.
 *
 * @internal
 * @since 0.6.0
 */
final class Experiments {
	/**
	 * The list of experiment classes.
	 *
	 * @var array<class-string<\WordPress\AI\Contracts\Feature>>
	 */
	private const EXPERIMENT_CLASSES = array( // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is used as an array const.
		\WordPress\AI\Experiments\Abilities_Explorer\Abilities_Explorer::class,
		\WordPress\AI\Experiments\Content_Classification\Content_Classification::class,
		\WordPress\AI\Experiments\Excerpt_Generation\Excerpt_Generation::class,
		\WordPress\AI\Experiments\Alt_Text_Generation\Alt_Text_Generation::class,
		\WordPress\AI\Experiments\Meta_Description\Meta_Description::class,
		\WordPress\AI\Experiments\Review_Notes\Review_Notes::class,
		\WordPress\AI\Experiments\Refine_Notes\Refine_Notes::class,
		\WordPress\AI\Experiments\Summarization\Summarization::class,
		\WordPress\AI\Experiments\Title_Generation\Title_Generation::class,
	);

	/**
	 * Initializes the experiments by hooking into the appropriate filter.
	 *
	 * @since 0.6.0
	 */
	public function init(): void {
		// Priority 9 ensures it runs before any potential overrides at default priority.
		add_filter( 'wpai_default_feature_classes', array( self::class, 'register_default_experiment_classes' ), 9 );
	}

	/**
	 * Registers default experiment classes.
	 *
	 * @since 0.6.0
	 *
	 * @param array<string, class-string<\WordPress\AI\Contracts\Feature>> $existing Array of existing experiment class names, with experiment IDs as keys.
	 *
	 * @return array<string, class-string<\WordPress\AI\Contracts\Feature>> Array of experiment class names, with experiment IDs as keys.
	 */
	public static function register_default_experiment_classes( array $existing ): array {
		$experiments = array();
		foreach ( self::EXPERIMENT_CLASSES as $class_name ) {
			$experiments[ $class_name::get_id() ] = $class_name;
		}

		return array_merge( $existing, $experiments );
	}
}
