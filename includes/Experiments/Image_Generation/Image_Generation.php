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
use WordPress\AI\Asset_Loader;

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
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
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

	/**
	 * Enqueues and localizes the admin script.
	 *
	 * @since x.x.x
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		// Load asset in new post and edit post screens only.
		if ( 'post.php' !== $hook_suffix && 'post-new.php' !== $hook_suffix ) {
			return;
		}

		$screen = get_current_screen();

		// Load the assets only if the post type supports featured images.
		if (
			! $screen ||
			! post_type_supports( $screen->post_type, 'thumbnail' )
		) {
			return;
		}

		Asset_Loader::enqueue_script( 'image_generation', 'experiments/image-generation' );
		Asset_Loader::localize_script(
			'image_generation',
			'ImageGenerationData',
			array(
				'enabled' => $this->is_enabled(),
				'path'    => 'wp-abilities/v1/abilities/ai/' . $this->get_id() . '/run',
			)
		);
	}
}
