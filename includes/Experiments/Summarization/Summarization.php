<?php
/**
 * Content summarization experiment implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Summarization;

use WordPress\AI\Abilities\Summarization\Summarization as Summarization_Ability;
use WordPress\AI\Abstracts\Abstract_Experiment;
use WordPress\AI\Asset_Loader;

/**
 * Content summarization experiment.
 *
 * @since x.x.x
 */
class Summarization extends Abstract_Experiment {

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 *
	 * @return array{id: string, label: string, description: string} Experiment metadata.
	 */
	protected function load_experiment_metadata(): array {
		return array(
			'id'          => 'summarization',
			'label'       => __( 'Content Summarization', 'ai' ),
			'description' => __( 'Summarizes long-form content into digestible overviews', 'ai' ),
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
				'ability_class' => Summarization_Ability::class,
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

		Asset_Loader::enqueue_script( 'summarization', 'experiments/summarization' );
		Asset_Loader::localize_script(
			'summarization',
			'SummarizationData',
			array(
				'enabled' => $this->is_enabled(),
				'path'    => Summarization_Ability::path( $this->get_id() ),
			)
		);
	}
}
