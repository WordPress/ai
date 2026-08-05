<?php
/**
 * Slug generation experiment implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Slug_Generation;

use WordPress\AI\Abilities\Slug_Generation\Slug_Generation as Slug_Generation_Ability;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Asset_Loader;
use WordPress\AI\Experiments\Experiment_Category;

use function WordPress\AI\get_min_content_length;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Slug generation experiment.
 *
 * @since x.x.x
 */
class Slug_Generation extends Abstract_Feature {

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public static function get_id(): string {
		return 'slug-generation';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'Slug Generation', 'ai' ),
			'description' => __( 'Suggests SEO-friendly permalink slugs from post title or content. Requires an AI connector that includes support for text generation models.', 'ai' ),
			'category'    => Experiment_Category::EDITOR,
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
		// Register the AI ability to generate slugs using the Abilities API.
		wp_register_ability(
			'ai/' . $this->get_id(),
			array(
				'label'         => $this->get_label(),
				'description'   => $this->get_description(),
				'ability_class' => Slug_Generation_Ability::class,
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
		// Only enqueue on post creation and edit screens.
		if ( 'post.php' !== $hook_suffix && 'post-new.php' !== $hook_suffix ) {
			return;
		}

		$screen = get_current_screen();

		// Ensure the post type supports titles and isn't an attachment screen.
		if (
			! $screen ||
			! post_type_supports( $screen->post_type, 'title' ) ||
			in_array( $screen->post_type, array( 'attachment' ), true )
		) {
			return;
		}

		// Enqueue backend scripts, styles, and pass localized configuration settings to window.
		Asset_Loader::enqueue_script( 'slug_generation', 'experiments/slug-generation', array( 'include_core_abilities' => true ) );
		Asset_Loader::enqueue_style( 'slug_generation', 'experiments/slug-generation' );
		Asset_Loader::localize_script(
			'slug_generation',
			'SlugGenerationData',
			array(
				'enabled'             => $this->is_enabled(),
				'minContentLength'    => get_min_content_length( 'slug-generation', 250 ),
				'numberOfSuggestions' => (int) apply_filters( 'wpai_slug_generation_number_of_suggestions', 3 ),
			)
		);
	}
}
