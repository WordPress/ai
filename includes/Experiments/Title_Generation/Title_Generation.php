<?php
/**
 * Title generation experiment implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Title_Generation;

use WordPress\AI\Abilities\Title_Generation\Title_Generation as Title_Generation_Ability;
use WordPress\AI\Abstracts\Abstract_Experiment;
use WordPress\AI\Asset_Loader;

use function admin_url;

/**
 * Title generation experiment.
 *
 * Experiment notes:
 * - Adds a toolbar button in the post and site editor title area via a React bundle
 *   (`experiments/title-generation`) that calls the `ai/title-generation` ability.
 * - Ability input: current post content/post_id; output: up to three candidate strings.
 * - Manual test: open an edit or new post screen for a post type that supports titles,
 *   click "Generate" in the title toolbar, and confirm suggestions populate the modal.
 *
 * @since 0.1.0
 */
class Title_Generation extends Abstract_Experiment {

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @return array{id: string, label: string, description: string} Experiment metadata.
	 */
	protected function load_experiment_metadata(): array {
		return array(
			'id'          => 'title-generation',
			'label'       => __( 'Title Generation', 'ai' ),
			'description' => __( 'Generates title suggestions from content', 'ai' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Registers any needed abilities.
	 *
	 * @since 0.1.0
	 */
	public function register_abilities(): void {
		wp_register_ability(
			'ai/' . $this->get_id(),
			array(
				'label'         => $this->get_label(),
				'description'   => $this->get_description(),
				'ability_class' => Title_Generation_Ability::class,
			),
		);
	}

	/**
	 * Enqueues and localizes the admin script.
	 *
	 * @since 0.1.0
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		// Load asset in new post and edit post screens only.
		if ( 'post.php' !== $hook_suffix && 'post-new.php' !== $hook_suffix ) {
			return;
		}

		$screen = get_current_screen();

		// Load the assets only if the post type supports titles and is not an attachment.
		if (
			! $screen ||
			! post_type_supports( $screen->post_type, 'title' ) ||
			in_array( $screen->post_type, array( 'attachment' ), true )
		) {
			return;
		}

		Asset_Loader::enqueue_script( 'title_generation', 'experiments/title-generation' );
		Asset_Loader::localize_script(
			'title_generation',
			'TitleGenerationData',
			array(
				'enabled' => $this->is_enabled(),
				'path'    => Title_Generation_Ability::path( $this->get_id() ),
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_entry_points(): array {
		return array(
			array(
				'label' => __( 'Try', 'ai' ),
				'url'   => admin_url( 'post-new.php' ),
				'type'  => 'try',
			),
		);
	}
}
