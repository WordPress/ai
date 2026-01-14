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

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
}
