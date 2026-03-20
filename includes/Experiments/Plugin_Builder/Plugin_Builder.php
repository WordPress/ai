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
use WordPress\AI\Experiments\Plugin_Builder\Rest\DownloadController;
use WordPress\AI\Experiments\Plugin_Builder\Rest\WriteController;
use WordPress\AI_Client\AI_Client;

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
		add_filter(
			'wp_ai_client_default_request_timeout',
			static function () {
				return 300;
			}
		);

		add_action( 'init', array( AI_Client::class, 'init' ) );

		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// Instantiate REST controllers.
		add_action(
			'rest_api_init',
			static function () {
				( new WriteController() )->register();
				( new DownloadController() )->register();
			}
		);

		// Admin-post handler for downloading an AI-generated plugin as a ZIP from the plugins list.
		add_action( 'admin_post_ai_download_plugin', array( $this, 'handle_plugin_download' ) );

		// Add "Download" action link to AI-generated plugins in the plugins list.
		add_filter( 'plugin_action_links', array( $this, 'add_download_action_link' ), 10, 2 );
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

		wp_enqueue_script( 'wp-ai-client' );

		$asset_file = plugin_dir_path( dirname( __DIR__, 2 ) ) . 'build/experiments/plugin-builder.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$assets = require $asset_file;

		wp_enqueue_script(
			'ai-plugin-builder',
			plugins_url( 'build/experiments/plugin-builder.js', dirname( __DIR__, 2 ) ),
			array_merge( $assets['dependencies'], array( 'wp-ai-client' ) ),
			$assets['version'],
			true
		);

		wp_enqueue_style(
			'ai-plugin-builder',
			plugins_url( 'build/experiments/style-plugin-builder.css', dirname( __DIR__, 2 ) ),
			array(),
			$assets['version']
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

	/**
	 * Delegates the admin-post download request to DownloadController.
	 *
	 * @since x.x.x
	 */
	public function handle_plugin_download(): void {
		( new DownloadController() )->handle_admin_post();
	}

	/**
	 * Adds a "Download" action link to AI-generated plugins in the plugins list.
	 *
	 * @since x.x.x
	 *
	 * @param string[] $actions     Existing action links.
	 * @param string   $plugin_file Plugin file relative to the plugins directory (e.g. "slug/slug.php").
	 * @return string[] Modified action links.
	 */
	public function add_download_action_link( array $actions, string $plugin_file ): array {
		$slug      = dirname( $plugin_file );
		$ai_slugs  = get_option( DownloadController::OPTION_KEY, array() );

		if ( ! in_array( $slug, (array) $ai_slugs, true ) ) {
			return $actions;
		}

		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=ai_download_plugin&slug=' . rawurlencode( $slug ) ),
			'ai_download_' . $slug
		);

		$actions['ai_download'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $url ),
			esc_html__( 'Download', 'ai' )
		);

		return $actions;
	}
}
