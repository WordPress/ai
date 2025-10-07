<?php
/**
 * Main Plugin class.
 *
 * @package WordPress\AI
 */

namespace WordPress\AI;

/**
 * Main plugin class.
 *
 * Coordinates all plugin components including feature registry.
 * Implements singleton pattern to ensure only one instance exists.
 *
 * @since 0.1.0
 */
final class Plugin {
	/**
	 * Singleton instance.
	 *
	 * @since 0.1.0
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Feature registry instance.
	 *
	 * @since 0.1.0
	 * @var Feature_Registry
	 */
	private $feature_registry;

	/**
	 * Gets the singleton instance.
	 *
	 * @since 0.1.0
	 *
	 * @return Plugin The singleton instance.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor to enforce singleton pattern.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {
		$this->feature_registry = Feature_Registry::instance();
	}

	/**
	 * Initializes the plugin.
	 *
	 * Called on the 'plugins_loaded' hook from the main plugin file.
	 *
	 * @since 0.1.0
	 */
	public function init(): void {
		// Load text domain for translations.
		$this->load_textdomain();

		// Register hooks.
		$this->register_hooks();

		// Initialize features.
		$this->feature_registry->initialize_features();
	}

	/**
	 * Loads the plugin text domain for translations.
	 *
	 * @since 0.1.0
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'ai',
			false,
			dirname( plugin_basename( AI_PLUGIN_FILE ) ) . '/languages'
		);
	}

	/**
	 * Registers plugin hooks.
	 *
	 * Admin interface registration will be added in issue #25.
	 *
	 * @since 0.1.0
	 */
	private function register_hooks(): void {
		/**
		 * Fires after the AI plugin has been initialized.
		 *
		 * @since 0.1.0
		 */
		do_action( 'ai_plugin_initialized' );
	}

	/**
	 * Gets the feature registry.
	 *
	 * @since 0.1.0
	 *
	 * @return Feature_Registry The feature registry instance.
	 */
	public function get_feature_registry(): Feature_Registry {
		return $this->feature_registry;
	}
}
