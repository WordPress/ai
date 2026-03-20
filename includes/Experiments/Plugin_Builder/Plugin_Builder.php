<?php
/**
 * AI Plugin Builder experiment implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Plugin_Builder;

use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Experiments\Experiment_Category;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI Plugin Builder experiment.
 *
 * Uses the AI infrastructure to create plugins in WordPress.
 *
 * @since x.x.x
 */
class Plugin_Builder extends Abstract_Feature {

	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'plugin-builder';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'Plugin Builder', 'ai' ),
			'description' => __( 'Uses AI to create plugins in WordPress.', 'ai' ),
			'category'    => Experiment_Category::ADMIN,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// Register background job handler
		\WordPress\AI\Experiments\Plugin_Builder\Ai\BackgroundJob::register();

		// Instantiate REST controllers
		add_action(
			'rest_api_init',
			static function () {
				( new \WordPress\AI\Experiments\Plugin_Builder\Rest\GenerateController() )->register();
				( new \WordPress\AI\Experiments\Plugin_Builder\Rest\StatusController() )->register();
				( new \WordPress\AI\Experiments\Plugin_Builder\Rest\InstallController() )->register();
			}
		);
	}

	/**
	 * Registers the admin menu page for the plugin builder.
	 *
	 * @since x.x.x
	 */
	public function register_admin_menu(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		// Use the same capability as adding new plugins since this generates and installs them.
		add_plugins_page(
			__( 'AI Plugin Builder', 'ai' ),
			__( 'AI Plugin Builder', 'ai' ),
			'install_plugins',
			'ai-plugin-builder',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Renders the admin page container.
	 *
	 * @since x.x.x
	 */
	public function render_admin_page(): void {
		echo '<div id="wp-ai-plugin-builder-root"></div>';
	}

	/**
	 * Enqueues the React frontend scripts for the admin page.
	 *
	 * @since x.x.x
	 *
	 * @param string $hook The current admin page.
	 */
	public function enqueue_scripts( string $hook ): void {
		if ( 'plugins_page_ai-plugin-builder' !== $hook ) {
			return;
		}

		$asset_file = plugin_dir_path( dirname( __DIR__, 2 ) ) . 'build/experiments/plugin-builder.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$assets = require $asset_file;

		wp_enqueue_script(
			'ai-plugin-builder',
			plugins_url( 'build/experiments/plugin-builder.js', dirname( __DIR__, 2 ) ),
			$assets['dependencies'],
			$assets['version'],
			true
		);

		wp_localize_script(
			'ai-plugin-builder',
			'aiPluginBuilder',
			array(
				'restUrl'  => esc_url_raw( rest_url( 'wordpress-ai-plugin-builder/v1/' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'adminUrl' => admin_url( 'plugins.php' ),
			)
		);
	}
}
