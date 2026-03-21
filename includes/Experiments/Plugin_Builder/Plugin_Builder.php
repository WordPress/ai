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
use WordPress\AI\Experiments\Plugin_Builder\Rest\WriteController;
use WordPress\AI_Client\AI_Client;
use WP_Error;

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
			'label'              => __( 'Plugin Builder', 'ai' ),
			'description'        => __( 'Uses AI to create plugins in WordPress.', 'ai' ),
			'category'           => Experiment_Category::ADMIN,
			'check_requirements' => function () {
				if ( ! class_exists( 'WordPress\AI_Client\AI_Client' ) ) {
					return new WP_Error(
						'missing_ai_client',
						__( 'This feature requires the <a href="https://github.com/WordPress/wp-ai-client" target="_blank">WP AI Client</a> plugin. You must currently install this plugin from GitHub. Clone it into your plugins directory and run <code>npm install &amp;&amp; composer install &amp;&amp; npm run build</code>.', 'ai' )
					);
				}
				return true;
			},
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

		add_action( 'init', array( $this, 'register_post_type' ) );

		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// Instantiate REST controllers
		add_action(
			'rest_api_init',
			static function () {
				( new WriteController() )->register();
				( new Rest\ChatHistoryController() )->register();
				( new Rest\FilesController() )->register();
			}
		);

		// Add "Edit with AI" link to plugin rows.
		add_filter( 'plugin_action_links', array( $this, 'add_edit_with_ai_link' ), 10, 2 );
	}

	/**
	 * Adds "Edit with AI" link to plugins row.
	 *
	 * @since x.x.x
	 *
	 * @param array  $actions     Plugin action links.
	 * @param string $plugin_file Plugin file path inside wp-content/plugins.
	 * @return array
	 */
	public function add_edit_with_ai_link( $actions, $plugin_file ) {
		if ( ! current_user_can( 'install_plugins' ) ) {
			return $actions;
		}

		$plugin_slug = dirname( $plugin_file );
		if ( '.' === $plugin_slug ) {
			return $actions; // Don't match single-file plugins for simplicity.
		}

		// Find a chat that generated this plugin slug.
		$args = array(
			'post_type'      => 'abp-chat',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'   => '_abp_plugin_slug',
					'value' => $plugin_slug,
				),
			),
		);

		$query = new \WP_Query( $args );
		if ( ! empty( $query->posts ) ) {
			$post_id = $query->posts[0];
			$url     = admin_url( 'plugins.php?page=ai-plugin-builder&chat_id=' . $post_id );
			$actions['edit_with_ai'] = '<a href="' . esc_url( $url ) . '" style="color: #6366f1; font-weight: 500;">' . esc_html__( 'Edit with AI ✨', 'ai' ) . '</a>';
		}

		return $actions;
	}

	/**
	 * Registers the custom post type for storing chat histories.
	 *
	 * @since x.x.x
	 */
	public function register_post_type(): void {
		register_post_type(
			'abp-chat',
			array(
				'label'               => __( 'Chat Histories', 'ai' ),
				'public'              => false,
				'show_ui'             => false,
				'show_in_rest'        => true,
				'rest_base'           => 'abp-chats',
				'supports'            => array( 'title', 'editor', 'custom-fields' ),
			)
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
}
