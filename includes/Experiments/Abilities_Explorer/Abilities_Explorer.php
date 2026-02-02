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

use WordPress\AI\Abstracts\Abstract_Experiment;
use WordPress\AI\Asset_Loader;

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
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// @todo: evaluate standardization after triaging existing comments.
		$admin_page = new Admin_Page();
		$admin_page->init();
	}

	/**
	 * Enqueues and localizes the admin script and styles.
	 *
	 * @since 0.2.0
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		// Load asset in Abilities Explorer page only.
		if ( 'tools_page_ai-abilities-explorer' !== $hook_suffix ) {
			return;
		}

		Asset_Loader::enqueue_script( 'abilities_explorer', 'experiments/abilities-explorer' );
		Asset_Loader::enqueue_style( 'abilities_explorer', 'experiments/abilities-explorer' );
		Asset_Loader::localize_script(
			'abilities_explorer',
			'AbilityExplorer',
			array(
				'enabled' => $this->is_enabled(),
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'ai_ability_explorer_invoke' ),
				'strings' => array(
					'invoking'      => esc_html__( 'Invoking ability...', 'ai' ),
					'success'       => esc_html__( 'Success!', 'ai' ),
					'error'         => esc_html__( 'Error', 'ai' ),
					'invalidJson'   => esc_html__( 'Invalid JSON input', 'ai' ),
					'confirmInvoke' => esc_html__( 'Are you sure you want to invoke this ability?', 'ai' ),
					'copySuccess'   => esc_html__( 'Copied to clipboard!', 'ai' ),
					'copyError'     => esc_html__( 'Failed to copy', 'ai' ),
				),
			)
		);
	}
}
