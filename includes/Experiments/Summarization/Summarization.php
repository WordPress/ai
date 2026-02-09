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

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Content summarization experiment.
 *
 * @since 0.2.0
 */
class Summarization extends Abstract_Experiment {

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.2.0
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
		register_meta(
			'post',
			'ai_generated_summary',
			array(
				'type'         => 'string',
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
				'ability_class' => Summarization_Ability::class,
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

		Asset_Loader::enqueue_script( 'summarization', 'experiments/summarization' );
		Asset_Loader::enqueue_style( 'summarization', 'experiments/summarization' );
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
