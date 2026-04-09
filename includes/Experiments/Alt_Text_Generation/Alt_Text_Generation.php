<?php
/**
 * Alt text generation experiment implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Alt_Text_Generation;

use WordPress\AI\Abilities\Image\Alt_Text_Generation as Alt_Text_Generation_Ability;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Asset_Loader;
use WordPress\AI\Experiments\Experiment_Category;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Alt text generation experiment.
 *
 * Generates accessible alternative text for images using AI vision models.
 *
 * @since 0.3.0
 */
class Alt_Text_Generation extends Abstract_Feature {
	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'alt-text-generation';
	}

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
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'Alt Text Generation', 'ai' ),
			'description' => __( 'Generates accessible alternative (alt) text for images using AI vision models, following common web accessibility guidance.', 'ai' ),
			'category'    => Experiment_Category::EDITOR,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
		add_action( 'wp_enqueue_media', array( $this, 'enqueue_media_frame_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_media_library_assets' ) );
		add_action( 'add_meta_boxes_attachment', array( $this, 'setup_attachment_meta_box' ) );
		add_filter( 'attachment_fields_to_edit', array( $this, 'add_button_to_media_modal' ), 10, 2 );
		add_filter( 'bulk_actions-upload', array( $this, 'register_bulk_action' ) );
		add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_bulk_action' ), 10, 3 );
	}

	/**
	 * Registers any needed abilities.
	 *
	 * @since 0.3.0
	 */
	public function register_abilities(): void {
		wp_register_ability(
			'ai/' . self::get_id(),
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
			$this->maybe_enqueue_bulk_script();
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
			__( 'Alt Text', 'ai' ),
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
	 * Adds the "Generate Alt Text" option to the Media Library bulk actions menu.
	 *
	 * @since 0.7.0
	 *
	 * @param array<string, string> $actions The existing bulk actions.
	 * @return array<string, string> The bulk actions with the generate alt text option added.
	 */
	public function register_bulk_action( array $actions ): array {
		if ( ! $this->is_enabled() ) {
			return $actions;
		}

		$actions['wpai_generate_alt_text'] = __( 'Generate Alt Text', 'ai' );

		return $actions;
	}

	/**
	 * Handles the "Generate Alt Text" bulk action by redirecting with selected image IDs.
	 *
	 * @since 0.7.0
	 *
	 * @param string        $redirect_url The current redirect URL.
	 * @param string        $doaction     The bulk action being performed.
	 * @param list<int>     $post_ids     The list of post IDs to process.
	 * @return string The redirect URL, possibly with bulk alt text query args appended.
	 */
	public function handle_bulk_action( string $redirect_url, string $doaction, array $post_ids ): string {
		if ( 'wpai_generate_alt_text' !== $doaction || ! current_user_can( 'upload_files' ) ) {
			return $redirect_url;
		}

		$image_ids = array_values( array_filter( $post_ids, 'wp_attachment_is_image' ) );

		if ( empty( $image_ids ) ) {
			return $redirect_url;
		}

		return add_query_arg(
			array(
				'wpai_bulk_alt_text'  => 1,
				'wpai_attachment_ids' => implode( ',', array_map( 'absint', $image_ids ) ),
			),
			$redirect_url
		);
	}

	/**
	 * Enqueues the bulk alt text script when a bulk action redirect is detected.
	 *
	 * @since 0.7.0
	 */
	private function maybe_enqueue_bulk_script(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading query param for script enqueue only; no privileged action taken.
		if ( ! isset( $_GET['wpai_bulk_alt_text'] ) || ! current_user_can( 'upload_files' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading query param for script enqueue only; no privileged action taken.
		$raw_ids = isset( $_GET['wpai_attachment_ids'] ) ? sanitize_text_field( wp_unslash( $_GET['wpai_attachment_ids'] ) ) : '';
		$ids     = array_values( array_filter( array_map( 'absint', explode( ',', $raw_ids ) ) ) );

		if ( empty( $ids ) ) {
			return;
		}

		Asset_Loader::enqueue_script( 'alt_text_generation_bulk', 'experiments/alt-text-generation-bulk' );
		Asset_Loader::localize_script(
			'alt_text_generation_bulk',
			'AltTextGenerationBulkData',
			array(
				'attachmentIds' => $ids,
			)
		);
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
			'label'        => __( 'Alt Text', 'ai' ),
			'input'        => 'html',
			'show_in_edit' => false,
			'html'         => '<div class="ai-alt-text-media-actions"><button id="ai-alt-text-generate-button" class="button button-secondary" type="button" data-attachment-id="' . absint( $post->ID ) . '">' . esc_html( $button_text ) . '</button><span class="spinner" aria-hidden="true" style="margin-left: 8px;"></span><p class="description" aria-live="polite" style="margin-top: 6px; font-size: 12px;"></p></div>',
		);

		return $fields;
	}
}
