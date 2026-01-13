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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Excerpt generation experiment.
 *
 * @since x.x.x
 */
class Excerpt_Generation extends Abstract_Experiment {

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
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
				'ability_class' => Excerpt_Generation_Ability::class,
			),
		);
	}
}
