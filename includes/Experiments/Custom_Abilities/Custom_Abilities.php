<?php
/**
 * Custom Abilities Experiment.
 *
 * @package WordPress\AI\Experiments\Custom_Abilities
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Custom_Abilities;

use WordPress\AI\Abilities\Gated\Gated_Abilities;
use WordPress\AI\Abilities\Show_In_Abilities;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Experiments\Experiment_Category;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Gates the plugin's custom WordPress Abilities behind a single experiment.
 *
 * When the experiment is enabled, every gated ability (see the Gated_Abilities
 * registry) is registered together, so a site owner gains access to all custom
 * abilities without toggling anything else on.
 *
 * @since x.x.x
 */
class Custom_Abilities extends Abstract_Feature {
	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'custom-abilities';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'Custom Abilities', 'ai' ),
			'description' => __( 'Register the plugin\'s custom WordPress Abilities (post details & terms, settings, users, and content) for use via the Abilities API and MCP.', 'ai' ),
			'category'    => Experiment_Category::ADMIN,
			'capability'  => 'none',
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Registers every gated ability. Show_In_Abilities runs once beforehand when
	 * any ability depends on core objects being exposed to the Abilities API.
	 */
	public function register(): void {
		$abilities = Gated_Abilities::get_all();

		if ( $this->requires_core_object_exposure( $abilities ) ) {
			( new Show_In_Abilities() )->register();
		}

		foreach ( $abilities as $ability ) {
			$ability->register();
		}
	}

	/**
	 * Whether any of the given abilities needs core objects exposed to the
	 * Abilities API before it registers.
	 *
	 * @since x.x.x
	 *
	 * @param array<\WordPress\AI\Abstracts\Abstract_Gated_Ability> $abilities The gated abilities.
	 * @return bool True if any ability requires core-object exposure.
	 */
	private function requires_core_object_exposure( array $abilities ): bool {
		foreach ( $abilities as $ability ) {
			if ( $ability->requires_core_object_exposure() ) {
				return true;
			}
		}

		return false;
	}
}
