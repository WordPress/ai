<?php
/**
 * Abilities Explorer Experiment
 *
 * Discover, inspect, test, and document all abilities
 * registered via the WordPress Abilities API.
 *
 * @package WordPress\AI\Experiments\Abilities_Explorer
 * @since 0.2.0
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Abilities_Explorer;

use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Experiments\Abilities_Explorer\REST\Abilities_Controller;
use WordPress\AI\Experiments\Experiment_Category;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abilities Explorer Experiment Class.
 *
 * Provides a comprehensive interface for exploring
 * the WordPress Abilities API.
 *
 * @since 0.2.0
 */
class Abilities_Explorer extends Abstract_Feature {
	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'abilities-explorer';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'Abilities Explorer', 'ai' ),
			'description' => __( 'Discover, inspect, test, and document all abilities registered via the WordPress Abilities API.', 'ai' ),
			'category'    => Experiment_Category::ADMIN,
			'capability'  => 'none',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		// The page hooks its own assets on its screen's `load-{hook}` action.
		$admin_page = new Admin_Page();
		$admin_page->init();

		// Registered only here, so the routes exist only while the experiment is on.
		( new Abilities_Controller() )->init();
	}
}
