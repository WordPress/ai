<?php
/**
 * Abilities Explorer Feature
 *
 * Discover, inspect, test, and document all abilities registered via the WordPress Abilities API.
 *
 * @package WordPress\AI\Features\Abilities_Explorer
 * @since 0.1.0
 */

namespace WordPress\AI\Features\Abilities_Explorer;

use WordPress\AI\Abstracts\Abstract_Feature;

/**
 * Abilities Explorer Feature Class
 *
 * Provides a comprehensive interface for exploring the WordPress Abilities API.
 *
 * @since 0.1.0
 */
class Abilities_Explorer extends Abstract_Feature {

	/**
	 * Feature version.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private const VERSION = '1.0.0';

	/**
	 * Load feature metadata.
	 *
	 * @since 0.1.0
	 *
	 * @return array{id: string, label: string, description: string} Feature metadata.
	 */
	protected function load_feature_metadata(): array {
		return array(
			'id'          => 'abilities-explorer',
			'label'       => __( 'Abilities Explorer', 'ai' ),
			'description' => __( 'Discover, inspect, test, and document all abilities registered via the WordPress Abilities API.', 'ai' ),
		);
	}

	/**
	 * Register the feature.
	 *
	 * Sets up hooks and initializes the feature functionality.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		// Initialize admin interface
		if ( is_admin() ) {
			$this->init_admin();
		}
	}
	/**
	 * Initialize admin functionality.
	 *
	 * @since 0.1.0
	 */
	private function init_admin(): void {
		require_once __DIR__ . '/Admin_Page.php';

		$admin_page = new Admin_Page();
		$admin_page->init();
	}
}
