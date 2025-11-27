<?php
/**
 * Alt text generation experiment implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Alt_Text_Generation;

use WordPress\AI\Abilities\Alt_Text_Generation\Alt_Text_Generation as Alt_Text_Generation_Ability;
use WordPress\AI\Abstracts\Abstract_Experiment;
use WordPress\AI\Asset_Loader;

/**
 * Alt text generation experiment.
 *
 * Generates descriptive alt text for images using AI vision models.
 *
 * @since x.x.x
 */
class Alt_Text_Generation extends Abstract_Experiment {

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 *
	 * @return array{id: string, label: string, description: string} Experiment metadata.
	 */
	protected function load_experiment_metadata(): array {
		return array(
			'id'          => 'alt-text-generation',
			'label'       => __( 'Alt Text Generation', 'ai' ),
			'description' => __( 'Generates descriptive alt text for images using AI vision models.', 'ai' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
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
				'ability_class' => Alt_Text_Generation_Ability::class,
			),
		);
	}

	/**
	 * Enqueues block editor assets.
	 *
	 * @since x.x.x
	 */
	public function enqueue_editor_assets(): void {
		Asset_Loader::enqueue_script( 'alt_text_generation', 'experiments/alt-text-generation' );
		Asset_Loader::localize_script(
			'alt_text_generation',
			'AltTextGenerationData',
			array(
				'enabled' => $this->is_enabled(),
			)
		);
	}
}
