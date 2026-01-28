<?php
/**
 * Alt text generation experiment implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Alt_Text_Generation;

use WordPress\AI\Abilities\Image\Alt_Text_Generation as Alt_Text_Generation_Ability;
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
	 * Tracks whether the media-focused assets have already been enqueued.
	 *
	 * @since x.x.x
	 *
	 * @var bool
	 */
	private bool $media_assets_enqueued = false;

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
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
		add_action( 'wp_enqueue_media', array( $this, 'enqueue_media_frame_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_media_library_assets' ) );
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

		$this->maybe_enqueue_media_script();
	}

	/**
	 * Enqueues assets whenever the core media modal is registered.
	 *
	 * @since x.x.x
	 */
	public function enqueue_media_frame_assets(): void {
		$this->maybe_enqueue_media_script();
	}

	/**
	 * Conditionally enqueues assets on media-related admin screens (e.g., upload.php).
	 *
	 * @since x.x.x
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function maybe_enqueue_media_library_assets( string $hook_suffix ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		if ( in_array( $hook_suffix, array( 'upload.php', 'media-new.php' ), true ) ) {
			$this->maybe_enqueue_media_script();
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'attachment' !== $screen->post_type ) {
			return;
		}

		$this->maybe_enqueue_media_script();
	}

	/**
	 * Shared helper to enqueue and localize the media UI script once per request.
	 *
	 * @since x.x.x
	 */
	private function maybe_enqueue_media_script(): void {
		if ( $this->media_assets_enqueued || ! $this->is_enabled() ) {
			return;
		}

		Asset_Loader::enqueue_script( 'alt_text_generation_media', 'experiments/alt-text-generation-media' );
		Asset_Loader::localize_script(
			'alt_text_generation_media',
			'AltTextGenerationMediaData',
			array(
				'enabled' => $this->is_enabled(),
			)
		);

		$this->media_assets_enqueued = true;
	}
}
