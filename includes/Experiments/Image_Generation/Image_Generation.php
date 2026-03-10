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
use WordPress\AI\Experiment_Category;
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
	 */
	protected function load_experiment_metadata(): array {
		return array(
			'id'          => 'image-generation',
			'label'       => __( 'Image Generation', 'ai' ),
			'description' => __( 'Generate featured images and inline images using AI', 'ai' ),
			'category'    => Experiment_Category::EDITOR,
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
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_inline_assets' ) );
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_footer-upload.php', array( $this, 'inject_generate_image_button' ) );
	}

	/**
	 * Registers the admin menu under Media.
	 *
	 * @since 0.4.0
	 */
	public function register_admin_menu(): void {
		add_media_page(
			__( 'Generate Image', 'ai' ),
			__( 'Generate Image', 'ai' ),
			'upload_files',
			'generate-image',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Renders the Generate Image admin page.
	 *
	 * @since 0.4.0
	 */
	public function render_admin_page(): void {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Generate Image', 'ai' ) . '</h1>';
		echo '<div id="ai-image-generation-root"></div>';
		echo '</div>';
	}

	/**
	 * Injects a "Generate Image" button into the Media Library header via PHP.
	 * Uses an inline script in admin_footer to run after WP's media grid JS.
	 *
	 * @since 0.4.0
	 */
	public function inject_generate_image_button(): void {
		$url = admin_url( 'upload.php?page=generate-image' );
		?>
		<script type="text/javascript">
		( function() {
			var heading = document.querySelector( 'h1.wp-heading-inline' );
			if ( ! heading || ! heading.parentNode ) return;

			var btn = document.createElement( 'a' );
			btn.href = <?php echo wp_json_encode( esc_url( $url ) ); ?>;
			btn.className = 'page-title-action ai-generate-image-btn';
			btn.textContent = <?php echo wp_json_encode( esc_html__( 'Generate Image', 'ai' ) ); ?>;

			// Capture-phase listener fires before WP's bubbling delegation can intercept.
			btn.addEventListener( 'click', function( e ) {
				e.preventDefault();
				e.stopImmediatePropagation();
				window.location.href = btn.href;
			}, true );

			// Insert after the existing "Add New" button, or after the heading.
			var sibling = heading.nextElementSibling;
			if ( sibling && sibling.classList.contains( 'page-title-action' ) ) {
				heading.parentNode.insertBefore( btn, sibling.nextSibling );
			} else {
				heading.parentNode.insertBefore( btn, sibling );
			}
		} )();
		</script>
		<?php
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
		$is_post_screen  = in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true );
		$is_media_screen = in_array( $hook_suffix, array( 'upload.php', 'media_page_generate-image' ), true );

		if ( ! $is_post_screen && ! $is_media_screen ) {
			return;
		}

		if ( $is_post_screen ) {
			$screen = get_current_screen();

			// Load the assets only if the post type supports featured images.
			if (
				! $screen ||
				! post_type_supports( $screen->post_type, 'thumbnail' )
			) {
				return;
			}
		}

		$this->enqueue_shared_assets();
	}

	/**
	 * Enqueues and localizes the inline block editor script.
	 *
	 * @since 0.4.0
	 */
	public function enqueue_inline_assets(): void {
		$this->enqueue_shared_assets();
	}

	/**
	 * Enqueues the shared assets.
	 *
	 * @since 0.4.0
	 */
	private function enqueue_shared_assets(): void {
		Asset_Loader::enqueue_script( 'image_generation', 'experiments/image-generation' );
		Asset_Loader::enqueue_style( 'image_generation', 'experiments/image-generation' );
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
