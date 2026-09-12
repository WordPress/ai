<?php
/**
 * MCP Adapter Experiment
 *
 * Adds an MCP Access screen for controlling which abilities are exposed
 * through the MCP Adapter companion plugin's server. While the experiment is
 * enabled it installs and activates the companion plugin from WordPress.org
 * if it is not already active (see Plugin_Installer). Disabling the
 * experiment does not deactivate the adapter, and the exposure overrides
 * saved here are only enforced while the experiment is enabled — disabling
 * it returns abilities to the defaults the adapter serves on its own.
 *
 * @package WordPress\AI\Experiments\MCP_Adapter
 * @since 0.9.0
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\MCP_Adapter;

use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Asset_Loader;
use WordPress\AI\Experiments\Experiment_Category;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MCP Adapter Experiment Class.
 *
 * @since 0.9.0
 */
class MCP_Adapter extends Abstract_Feature {
	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'mcp-adapter';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'MCP Access', 'ai' ),
			'description' => __( 'Control which WordPress abilities are exposed to AI agents over the Model Context Protocol. Enabling this experiment installs and activates the MCP Adapter plugin from WordPress.org if it is not already active; exposure choices apply only while the experiment is enabled.', 'ai' ),
			'category'    => Experiment_Category::ADMIN,
			'capability'  => 'none',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_filter( 'wp_register_ability_args', array( Exposure_Overrides::class, 'filter_ability_args' ), 100, 2 );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		$admin_page = new Admin_Page();
		$admin_page->init();

		$installer = new Plugin_Installer();
		$installer->init();
	}

	/**
	 * Registers the settings REST routes.
	 *
	 * @since 0.9.0
	 */
	public function register_rest_routes(): void {
		( new Settings_Controller() )->register_routes();
	}

	/**
	 * Enqueues the MCP Access screen assets.
	 *
	 * @since 0.9.0
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'tools_page_' . Admin_Page::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		Asset_Loader::enqueue_script( 'mcp_adapter', 'experiments/mcp-adapter' );
		Asset_Loader::enqueue_style( 'mcp_adapter', 'experiments/mcp-adapter' );
	}
}
