<?php
/**
 * Custom Abilities Experiment.
 *
 * @package WordPress\AI\Experiments\Custom_Abilities
 * @since 1.3.0
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Custom_Abilities;

use WordPress\AI\Abilities\Gated\Gated_Abilities;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\Abilities\Registrar;
use WordPress\Abilities\Support\Features;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Gates the plugin's WordPress Abilities behind a single experiment.
 *
 * When the experiment is enabled, every gated ability (see the Gated_Abilities
 * registry) is registered together, so a site owner gains access to all custom
 * abilities without toggling anything else on.
 *
 * The core-facing abilities (`core/*` and `wp/*`) are provided by the vendored
 * `wordpress/abilities` package and registered through its Registrar. Selection
 * policy for them ships through two filters:
 *
 * - `wpai_register_wordpress_abilities` (bool, default `true`): pass `false`
 *   to skip the package registration entirely while the plugin-owned gated
 *   abilities still register. The package's core-provided stand-down is
 *   unaffected — that happens in the Registrar either way.
 * - `wpai_wordpress_abilities_args` (array, default `array()`): passed straight
 *   to `Registrar::register_all()`. Its `filter` callable is the per-ability
 *   selection policy (it can never veto a core-provided ability name);
 *   `public_only` restricts the set to abilities carrying `meta.public`. This
 *   is the replacement for removing or replacing package abilities through the
 *   `wpai_gated_abilities` filter, which no longer has package classes to act on.
 *
 * @since 1.3.0
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
			'description' => __( 'Register the plugin\'s custom WordPress Abilities for use via the Abilities API and MCP.', 'ai' ),
			'category'    => Experiment_Category::ADMIN,
			'capability'  => 'none',
			'usage'       => array(
				'trigger' => 'none',
				'runs'    => __( 'When an authorized AI tool calls an ability', 'ai' ),
				'sends'   => __( 'Nothing', 'ai' ),
				'details' => __( 'Lets authorized tools read posts, users, and settings', 'ai' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Registers every gated ability. If any gated ability needs core objects
	 * exposed to the Abilities API (e.g. a third-party gated ability reading
	 * curated core objects on a host that lacks the flag), the package's
	 * support polyfills are loaded first — the same mechanism the Registrar
	 * uses for the package's own abilities.
	 */
	public function register(): void {
		$abilities = Gated_Abilities::get_all();

		if ( $this->requires_core_object_exposure( $abilities ) ) {
			Features::load_polyfill( 'show_in_abilities' );
			Features::load_polyfill( 'setting_show_in_abilities' );
		}

		foreach ( $abilities as $ability ) {
			$ability->register();
		}

		if ( ! apply_filters( 'wpai_register_wordpress_abilities', true ) ) {
			return;
		}

		Registrar::register_all( apply_filters( 'wpai_wordpress_abilities_args', array() ) );
	}

	/**
	 * Whether any of the given abilities needs core objects exposed to the
	 * Abilities API before it registers.
	 *
	 * @since 1.3.0
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
