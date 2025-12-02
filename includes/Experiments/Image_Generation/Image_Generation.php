<?php
/**
 * Image generation experiment implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Image_Generation;

use WordPress\AI\Abilities\Image\Generate as Image_Generation_Ability;
use WordPress\AI\Abilities\Image\Import as Image_Import_Ability;
use WordPress\AI\Abstracts\Abstract_Experiment;

/**
 * Image generation experiment.
 *
 * @since x.x.x
 */
class Image_Generation extends Abstract_Experiment {

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 *
	 * @return array{id: string, label: string, description: string} Experiment metadata.
	 */
	protected function load_experiment_metadata(): array {
		return array(
			'id'          => 'image-generation',
			'label'       => __( 'Image Generation', 'ai' ),
			'description' => __( 'Generates an image from a passed in prompt', 'ai' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Registers any needed abilities.
	 *
	 * @since x.x.x
	 */
	public function register_abilities(): void {
		wp_register_ability(
			'ai/' . $this->get_id(),
			array(
				'label'         => $this->get_label(),
				'description'   => $this->get_description(),
				'ability_class' => Image_Generation_Ability::class,
			),
		);

		wp_register_ability(
			'ai/image-import',
			array(
				'label'         => __( 'Image Import', 'ai' ),
				'description'   => __( 'Imports an image into the media library from a base64 encoded string', 'ai' ),
				'ability_class' => Image_Import_Ability::class,
			),
		);
	}
}
