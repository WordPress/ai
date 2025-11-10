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
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Registers any needed abilities.
	 *
	 * @since 0.1.0
	 */
	public function register_abilities(): void {
		wp_register_ability(
			'ai/' . $this->get_id(),
			array(
				'label'         => $this->get_label(),
				'description'   => $this->get_description(),
				'ability_class' => Title_Generation_Ability::class,
			),
		);
	}
}
