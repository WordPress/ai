<?php
/**
 * Admin Page Class
 *
 * Handles admin menu, pages, and UI rendering.
 *
 * @package WordPress\AI\Experiments\Abilities_Explorer
 * @since 0.2.0
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Abilities_Explorer;

use WordPress\AI\Asset_Loader;
use WordPress\AI\Experiments\Abilities_Explorer\REST\Abilities_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin Page Class
 *
 * Manages the admin interface for Abilities Explorer.
 *
 * @since 0.2.0
 */
class Admin_Page {

	/**
	 * Menu slug of the Explorer screen.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const PAGE_SLUG = 'ai-abilities-explorer';

	/**
	 * Script and style handle, without the Asset_Loader prefix.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const ASSET_HANDLE = 'abilities_explorer';

	/**
	 * Built asset path, relative to the build directory and without extension.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const ASSET_PATH = 'experiments/abilities-explorer';

	/**
	 * Initialize admin functionality.
	 *
	 * @since 0.2.0
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
	}

	/**
	 * Add admin menu item.
	 *
	 * @since 0.2.0
	 */
	public function add_admin_menu(): void {
		$hook = add_submenu_page(
			'tools.php',
			__( 'Abilities Explorer', 'ai' ),
			__( 'Abilities Explorer', 'ai' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);

		if ( ! $hook ) {
			return;
		}

		add_action( "load-{$hook}", array( $this, 'add_help_tabs' ) );
		add_action( "load-{$hook}", array( $this, 'on_load' ) );
	}

	/**
	 * Hooks the screen's assets once WordPress dispatches the Explorer page.
	 *
	 * @since x.x.x
	 */
	public function on_load(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueues the React bundle and passes its localized settings.
	 *
	 * @since x.x.x
	 */
	public function enqueue_assets(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		/*
		 * The list renders with DataViews, whose styles ship with the plugin
		 * because `wp-dataviews` is not a registered style on every supported
		 * WordPress version. The bundled copy is used only when WordPress does
		 * not register its own.
		 */
		$dataviews_css = WPAI_PLUGIN_DIR . 'build/admin/dataviews.css';

		if ( ! wp_styles()->query( 'wp-dataviews' ) && file_exists( $dataviews_css ) ) {
			wp_enqueue_style(
				'ai-dataviews',
				WPAI_PLUGIN_URL . 'build/admin/dataviews.css',
				array(),
				(string) filemtime( $dataviews_css )
			);
		}

		Asset_Loader::enqueue_script( self::ASSET_HANDLE, self::ASSET_PATH );
		Asset_Loader::enqueue_style( self::ASSET_HANDLE, self::ASSET_PATH );

		/*
		 * DataViews ships its own UI strings, which WordPress only inlines in
		 * block-editor contexts, so they are loaded explicitly here.
		 */
		wp_set_script_translations( 'wp-dataviews', 'default' );

		Asset_Loader::localize_script(
			self::ASSET_HANDLE,
			'AbilitiesExplorer',
			array(
				'rest'                => array(
					'nonce'  => wp_create_nonce( 'wp_rest' ),
					'root'   => esc_url_raw( rest_url() ),
					/*
					 * Every path is sourced from the constant the controller
					 * registers with, so the map cannot drift from the routes.
					 */
					'routes' => array(
						'abilities' => Abilities_Controller::ABILITIES_ROUTE,
						'item'      => Abilities_Controller::ITEM_ROUTE,
						'invoke'    => Abilities_Controller::INVOKE_ROUTE,
						'surface'   => Abilities_Controller::SURFACE_ROUTE,
					),
				),
				'pageSlug'            => self::PAGE_SLUG,
				'surfaceReasonLabels' => Ability_Handler::get_surface_reason_labels(),
				'providerLabels'      => Ability_Handler::get_provider_labels(),
			)
		);
	}

	/**
	 * Outputs the root DOM node the React application mounts into.
	 *
	 * The admin-ui `Page` component provides the screen's single `h1`, so no
	 * heading is printed here.
	 *
	 * @since 0.2.0
	 * @since x.x.x Renders only the React root.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap ability-explorer-wrap"><div id="ai-abilities-explorer-root"></div></div>';
	}

	/**
	 * Add contextual help tabs to the screen.
	 *
	 * @since 0.4.0
	 */
	public function add_help_tabs(): void {
		$screen = get_current_screen();

		if ( ! $screen ) {
			return;
		}

		$screen->add_help_tab(
			array(
				'id'      => 'abilities-overview',
				'title'   => __( 'Overview', 'ai' ),
				'content' =>
					'<p>' . esc_html__( 'Abilities are a standardized way for WordPress core, plugins, and themes to expose discrete units of functionality. Each ability has a name, optional input/output schemas, and can be invoked programmatically.', 'ai' ) . '</p>' .
					'<p>' . esc_html__( 'The Abilities Explorer lets you browse every registered ability, inspect its schemas, and test it with custom input right from the admin.', 'ai' ) . '</p>',
			)
		);

		$provider_tags = array( 'strong' => array() );

		$screen->add_help_tab(
			array(
				'id'      => 'abilities-providers',
				'title'   => esc_html__( 'Providers', 'ai' ),
				'content' =>
					'<p>' . esc_html__( 'Every ability is associated with a provider that indicates where it comes from:', 'ai' ) . '</p>' .
					'<ul>' .
						'<li>' . wp_kses( __( '<strong>Core</strong>: Built into WordPress itself.', 'ai' ), $provider_tags ) . '</li>' .
						'<li>' . wp_kses( __( '<strong>Plugin</strong>: Registered by an active plugin.', 'ai' ), $provider_tags ) . '</li>' .
						'<li>' . wp_kses( __( '<strong>Theme</strong>: Registered by the active theme.', 'ai' ), $provider_tags ) . '</li>' .
					'</ul>',
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'abilities-testing',
				'title'   => esc_html__( 'Testing', 'ai' ),
				'content' =>
					'<p>' . esc_html__( 'You can test any ability directly from this screen:', 'ai' ) . '</p>' .
					'<ol>' .
						'<li>' . esc_html__( 'Choose the "Test" action from an ability\'s row actions in the list.', 'ai' ) . '</li>' .
						'<li>' . __( 'Edit the pre-filled Input Data if the ability accepts JSON parameters.', 'ai' ) . '</li>' .
						'<li>' . __( 'Use "Validate Input" to check your JSON against the schema.', 'ai' ) . '</li>' .
						'<li>' . __( 'Click "Invoke Ability" to execute it and see the result.', 'ai' ) . '</li>' .
					'</ol>',
			)
		);

		$screen->set_help_sidebar(
			'<p><strong>' . esc_html__( 'For more information:', 'ai' ) . '</strong></p>' .
			'<p><a href="https://developer.wordpress.org/apis/abilities/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Abilities API Documentation', 'ai' ) . '</a></p>'
		);
	}
}
