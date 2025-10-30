<?php
/**
 * Title generation feature implementation.
 *
 * @package WordPress\AI
 */

namespace WordPress\AI\Features\Title_Generation;

use WordPress\AI\Abilities\Title_Generation as Title_Generation_Ability;
use WordPress\AI\Abstracts\Abstract_Feature;

/**
 * Title generation feature.
 *
 * @since 0.1.0
 */
class Title_Generation extends Abstract_Feature {

	/**
	 * Load feature metadata.
	 *
	 * @since 0.1.0
	 *
	 * @return array{id: string, label: string, description: string} Feature metadata.
	 */
	protected function load_feature_metadata(): array {
		return array(
			'id'          => 'title-generation',
			'label'       => __( 'Title Generation', 'ai' ),
			'description' => __( 'Generates title suggestions from content', 'ai' ),
		);
	}

	/**
	 * Register any needed hooks.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_categories' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Registers needed ability categories.
	 *
	 * TODO: If we want to use the same category for all abilities
	 * in this plugin, this should be moved out of this class into
	 * it's own category registration class.
	 *
	 * @since 0.1.0
	 */
	public function register_categories(): void {
		wp_register_ability_category(
			'ai-experiments',
			array(
				'label'       => __( 'AI Experiments', 'ai' ),
				'description' => __( 'Various AI experiment features.', 'ai' ),
			),
		);
	}

	/**
	 * Registers any needed abilities.
	 *
	 * @since 0.1.0
	 */
	public function register_abilities(): void {
		wp_register_ability(
			'ai/title-generation', // TODO: add a method to build this slug from the feature ID.
			array(
				'label'         => $this->get_label(),
				'feature'       => $this,
				'ability_class' => Title_Generation_Ability::class,
			),
		);
	}
}
