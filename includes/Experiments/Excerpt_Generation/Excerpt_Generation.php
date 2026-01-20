<?php
/**
 * Excerpt generation experiment implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Excerpt_Generation;

use WordPress\AI\Abilities\Excerpt_Generation\Excerpt_Generation as Excerpt_Generation_Ability;
use WordPress\AI\Abstracts\Abstract_Experiment;
use WordPress\AI\Asset_Loader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Excerpt generation experiment.
 *
 * @since 0.2.0
 */
class Excerpt_Generation extends Abstract_Experiment {

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.2.0
	 */
	protected function load_experiment_metadata(): array {
		return array(
			'id'          => 'excerpt-generation',
			'label'       => __( 'Excerpt Generation', 'ai' ),
			'description' => __( 'Generates excerpt suggestions from content', 'ai' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.2.0
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
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
				'ability_class' => Excerpt_Generation_Ability::class,
			),
		);
	}

	/**
	 * Enqueues and localizes the admin script.
	 *
	 * @since 0.2.0
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		// Load asset in new post and edit post screens only.
		if ( 'post.php' !== $hook_suffix && 'post-new.php' !== $hook_suffix ) {
			return;
		}

		$screen = get_current_screen();

		// Load the assets only if the post type supports excerpts and is not an attachment.
		if (
			! $screen ||
			! post_type_supports( $screen->post_type, 'excerpt' ) ||
			in_array( $screen->post_type, array( 'attachment' ), true )
		) {
			return;
		}

		Asset_Loader::enqueue_script( 'excerpt_generation', 'experiments/excerpt-generation' );
		Asset_Loader::localize_script(
			'excerpt_generation',
			'ExcerptGenerationData',
			array(
				'enabled' => $this->is_enabled(),
				'path'    => Excerpt_Generation_Ability::path( $this->get_id() ),
			)
		);
	}
}
