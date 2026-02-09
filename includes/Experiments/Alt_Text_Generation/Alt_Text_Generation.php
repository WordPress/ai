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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Alt text generation experiment.
 *
 * Generates descriptive alt text for images using AI vision models.
 *
 * @since 0.3.0
 */
class Alt_Text_Generation extends Abstract_Experiment {
	/**
	 * Tracks whether the media-focused assets have already been enqueued.
	 *
	 * @since 0.3.0
	 *
	 * @var bool
	 */
	private bool $media_assets_enqueued = false;

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.3.0
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
	 * @since 0.3.0
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
		add_action( 'wp_enqueue_media', array( $this, 'enqueue_media_frame_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_media_library_assets' ) );
		add_action( 'add_meta_boxes_attachment', array( $this, 'setup_attachment_meta_box' ) );
		add_filter( 'attachment_fields_to_edit', array( $this, 'add_button_to_media_modal' ), 10, 2 );
	}

	/**
	 * Registers any needed abilities.
	 *
	 * @since 0.3.0
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
	 * @since 0.3.0
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
	 * @since 0.3.0
	 */
	public function enqueue_media_frame_assets(): void {
		$this->maybe_enqueue_media_script();
	}

	/**
	 * Conditionally enqueues assets on media-related admin screens (e.g., upload.php).
	 *
	 * @since 0.3.0
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
	 * @since 0.3.0
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

	/**
	 * Sets up the attachment meta box.
	 *
	 * Adds a meta box to the attachment edit screen that contains
	 * the Generate/Regenerate button.
	 *
	 * @since 0.3.0
	 *
	 * @param \WP_Post $post The attachment post.
	 */
	public function setup_attachment_meta_box( \WP_Post $post ): void {
		if (
			! $this->is_enabled() ||
			! wp_attachment_is_image( $post )
		) {
			return;
		}

		add_meta_box(
			'ai_alt_text_generation',
			__( 'AI Alt Text', 'ai' ),
			array( $this, 'render_attachment_meta_box' ),
			'attachment',
		);
	}

	/**
	 * Renders the attachment meta box content.
	 *
	 * @since 0.3.0
	 *
	 * @param \WP_Post $post The attachment post.
	 */
	public function render_attachment_meta_box( \WP_Post $post ): void {
		$button_text = empty( get_post_meta( $post->ID, '_wp_attachment_image_alt', true ) ) ? __( 'Generate', 'ai' ) : __( 'Regenerate', 'ai' );

		echo '<div class="ai-alt-text-media-actions" style="margin-top: 16px; max-width: 150px;">';
		echo '<button id="ai-alt-text-generate-button" class="button button-secondary" type="button" data-attachment-id="' . absint( $post->ID ) . '">' . esc_html( $button_text ) . '</button><span class="spinner" aria-hidden="true" style="margin-left: 8px;"></span><p class="description" aria-live="polite" style="margin-top: 10px; line-height: 1.3;"></p>';
		echo '</div>';
	}

	/**
	 * Adds a button to the media modal to generate alt text.
	 *
	 * @since 0.3.0
	 *
	 * @param array<string, mixed> $fields The attachment fields.
	 * @param \WP_Post|null $post The attachment post.
	 * @return array<string, mixed> The attachment fields with the button added.
	 */
	public function add_button_to_media_modal( array $fields, ?\WP_Post $post ): array {
		if (
			! $this->is_enabled() ||
			null === $post ||
			! wp_attachment_is_image( $post )
		) {
			return $fields;
		}

		$button_text = empty( get_post_meta( $post->ID, '_wp_attachment_image_alt', true ) ) ? __( 'Generate', 'ai' ) : __( 'Regenerate', 'ai' );

		$fields['ai_alt_text'] = array(
			'label'        => __( 'AI Alt Text', 'ai' ),
			'input'        => 'html',
			'show_in_edit' => false,
			'html'         => '<div class="ai-alt-text-media-actions"><button id="ai-alt-text-generate-button" class="button button-secondary" type="button" data-attachment-id="' . absint( $post->ID ) . '">' . esc_html( $button_text ) . '</button><span class="spinner" aria-hidden="true" style="margin-left: 8px;"></span><p class="description" aria-live="polite" style="margin-top: 6px; font-size: 12px;"></p></div>',
		);

		return $fields;
	}
}
