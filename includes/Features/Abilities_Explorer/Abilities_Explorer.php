<?php
/**
 * Abilities Explorer Feature
 *
 * Discover, inspect, test, and document all abilities registered via the WordPress Abilities API.
 *
 * @package WordPress\AI\Features\Abilities_Explorer
 * @since n.e.x.t
 */

namespace WordPress\AI\Features\Abilities_Explorer;

use WordPress\AI\Abstracts\Abstract_Experiment;

/**
 * Abilities Explorer Feature Class
 *
 * Provides a comprehensive interface for exploring the WordPress Abilities API.
 *
 * @since n.e.x.t
 */
class Abilities_Explorer extends Abstract_Experiment {
	/**
	 * {@inheritDoc}
	 */
	protected function load_experiment_metadata(): array {
		return array(
			'id'          => 'abilities-explorer',
			'label'       => __( 'Abilities Explorer', 'ai' ),
			'description' => __( 'Discover, inspect, test, and document all abilities registered via the WordPress Abilities API.', 'ai' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		// @todo: evaluate standardization after triaging existing comments.
		$admin_page = new Admin_Page();
		$admin_page->init();
	}
}
