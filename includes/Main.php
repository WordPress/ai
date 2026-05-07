<?php
/**
 * The main plugin file.
 *
 * @package WordPress\AI
 *
 * @since 0.8.0
 */

declare( strict_types=1 );

namespace WordPress\AI;

use WordPress\AI\Abilities\Utilities\Posts;
use WordPress\AI\Admin\Activation;
use WordPress\AI\Admin\Dashboard\Dashboard_Widgets;
use WordPress\AI\Admin\Upgrades;
use WordPress\AI\Experiments\Experiments;
use WordPress\AI\Features\Loader;
use WordPress\AI\Features\Registry;
use WordPress\AI\Settings\Settings_Page;
use WordPress\AI\Settings\Settings_Registration;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Main
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since 0.8.0
 */
final class Main {
	/**
	 * Instance of the class.
	 * @since 0.8.0
	 *
	 *
	 * @var ?static
	 */
	private static $instance;

	/**
	 * Gets the (singleton) instance of the Main class.
	 *
	 * @since 0.8.0
	 */
	public static function get_instance(): self {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
			self::$instance->setup();
		}

		return self::$instance;
	}

	/**
	 * Setup the plugin.
	 */
	private function setup(): void {
		// Load the plugin classes.
		add_action( 'plugins_loaded', array( $this, 'load' ) );

		// Register activation and deactivation hooks.
		register_activation_hook( WPAI_PLUGIN_FILE, array( Activation::class, 'activation_callback' ) );
	}

	/**
	 * Load the plugin classes.
	 *
	 * @since 0.8.0
	 *
	 * @internal Used in the plugins_loaded action.
	 */
	public function load(): void {
		// Check plugin requirements before continuing.
		if ( ! ( new Requirements() )->are_requirements_met() ) {
			return;
		}

		// Include globals
		require_once WPAI_PLUGIN_DIR . 'includes/helpers.php';

		// Handle any pending upgrades.
		( new Upgrades() )->init();

		// Handle deprecated code.
		( new Deprecated() )->init();

		// Add plugin action links to plugins screen.
		add_filter( 'plugin_action_links_' . plugin_basename( WPAI_PLUGIN_FILE ), array( $this, 'plugin_action_links' ) );

		// Defer feature initialization to the 'init' action.
		add_action( 'init', array( $this, 'initialize_features' ), 15 );

		// Register the default ability category.
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_ability_category' ) );
	}

	/**
	 * Initializes plugin features.
	 *
	 * @since 0.8.0
	 */
	public function initialize_features(): void {
		try {
			// Experiments are hooked into our Loader, so we need to register them first.
			( new Experiments() )->init();

			// The one true registry of all features.
			$registry = new Registry();

			// Initializes all the features.
			( new Loader( $registry ) )->init();

			// Initialize settings registration.
			( new Settings_Registration( $registry ) )->init();

			// Register admin settings page menu item and dashboard widgets.
			if ( is_admin() ) {
				Settings_Page::init( $registry );

				( new Dashboard_Widgets( $registry ) )->init();
			}

			// Register our post-related WordPress Abilities.
			( new Posts() )->register();
		} catch ( \Throwable $e ) {
			_doing_it_wrong(
				__METHOD__,
				sprintf(
				/* translators: %s: Error message. */
					esc_html__( 'AI Plugin initialization failed: %s', 'ai' ),
					esc_html( $e->getMessage() )
				),
				'0.8.0'
			);
		}
	}

	/**
	 * Adds action links to the plugin list table.
	 *
	 * This adds "Settings" and "Connectors" links to
	 * the plugin's action links on the Plugins page.
	 *
	 * @since 0.8.0
	 *
	 * @param array<string> $links Existing action links.
	 * @return array<string> Modified action links.
	 */
	public function plugin_action_links( array $links ): array {
		$settings_link = sprintf(
			'<a href="%1$s">%2$s</a>',
			admin_url( 'options-general.php?page=ai-wp-admin' ),
			esc_html__( 'Settings', 'ai' )
		);

		$connectors_link = sprintf(
			'<a href="%1$s">%2$s</a>',
			admin_url( 'options-connectors.php' ),
			esc_html__( 'Connectors', 'ai' )
		);

		array_unshift( $links, $connectors_link, $settings_link );

		return $links;
	}

	/**
	 * Register a generic catch-all category that all Abilities we register can use.
	 *
	 * This can be re-evaluated in the future if we need/want more specific categories.
	 *
	 * @internal Used in the wp_abilities_api_categories_init action.
	 *
	 * @since 0.8.0
	 */
	public function register_ability_category(): void {
		wp_register_ability_category(
			WPAI_DEFAULT_ABILITY_CATEGORY,
			array(
				'label'       => __( 'AI', 'ai' ),
				'description' => __( 'Various AI features and experiments.', 'ai' ),
			),
		);
	}

	/**
	 * Prevent the class from being cloned.
	 *
	 * @since 0.8.0
	 */
	public function __clone() {
		_doing_it_wrong(
			__FUNCTION__,
			sprintf(
				// translators: %s: Class name.
				esc_html__( 'The %s class should not be cloned.', 'ai' ),
				esc_html( self::class ),
			),
			'0.8.0'
		);
	}

	/**
	 * Prevent the class from being deserialized.
	 *
	 * @since 0.8.0
	 */
	public function __wakeup() {
		_doing_it_wrong(
			__FUNCTION__,
			sprintf(
				// translators: %s: Class name.
				esc_html__( 'De-serializing instances of %s is not allowed.', 'ai' ),
				esc_html( self::class ),
			),
			'0.8.0'
		);
	}
}
