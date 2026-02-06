<?php
/**
 * Image generation experiment implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Image_Generation;

use WordPress\AI\Abilities\Image\Generate_Image as Image_Generation_Ability;
use WordPress\AI\Abilities\Image\Generate_Image_Prompt as Generate_Image_Prompt_Ability;
use WordPress\AI\Abilities\Image\Import_Base64_Image as Image_Import_Ability;
use WordPress\AI\Abstracts\Abstract_Experiment;
use WordPress\AI\Asset_Loader;
use WordPress\AI\Experiments\Alt_Text_Generation\Alt_Text_Generation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Image generation experiment.
 *
 * @since 0.2.0
 */
class Image_Generation extends Abstract_Experiment {

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.2.0
	 *
	 * @return array{id: string, label: string, description: string} Experiment metadata.
	 */
	protected function load_experiment_metadata(): array {
		return array(
			'id'          => 'image-generation',
			'label'       => __( 'Image Generation', 'ai' ),
			'description' => __( 'Generates a featured image from a generated image prompt', 'ai' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.2.0
	 */
	public function register(): void {
		$this->register_post_meta();
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register any needed post meta.
	 *
	 * @since 0.3.0
	 */
	public function register_post_meta(): void {
		register_post_meta(
			'attachment',
			'ai_generated',
			array(
				'type'         => 'integer',
				'single'       => true,
				'show_in_rest' => true,
			)
		);
	}

	/**
	 * Registers any needed abilities.
	 *
	 * @since 0.2.0
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
				'label'         => __( 'Base64 Image Import', 'ai' ),
				'description'   => __( 'Imports a base64 encoded image into the media library', 'ai' ),
				'ability_class' => Image_Import_Ability::class,
			),
		);

		wp_register_ability(
			'ai/image-prompt-generation',
			array(
				'label'         => __( 'Image Prompt Generation', 'ai' ),
				'description'   => __( 'Generates a prompt from post content that can be used to generate an image', 'ai' ),
				'ability_class' => Generate_Image_Prompt_Ability::class,
			),
		);
	}

	/**
	 * Enqueues and localizes the admin script.
	 *
	 * @since 0.3.0
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
				'enabled'        => $this->is_enabled(),
				'altTextEnabled' => ( new Alt_Text_Generation() )->is_enabled(),
			)
		);
	}
}
