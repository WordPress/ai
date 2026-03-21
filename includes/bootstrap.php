<?php
/**
 * Bootstrap file for the AI plugin.
 *
 * Handles plugin initialization, version checks, and feature loading.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI;

use WordPress\AI\Abilities\Utilities\Posts;
use WordPress\AI\Admin\Activation;
use WordPress\AI\Admin\Upgrades;
use WordPress\AI\Experiments\Experiments;
use WordPress\AI\Features\Loader;
use WordPress\AI\Features\Registry;
use WordPress\AI\Settings\Settings_Page;
use WordPress\AI\Settings\Settings_Registration;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
if ( ! defined( 'WPAI_VERSION' ) ) {
	define( 'WPAI_VERSION', '0.6.0' );
}
if ( ! defined( 'WPAI_PLUGIN_FILE' ) ) {
	define( 'WPAI_PLUGIN_FILE', defined( 'WPAI_DIR' ) ? WPAI_DIR . 'ai.php' : '' );
}
if ( ! defined( 'WPAI_PLUGIN_DIR' ) ) {
	define( 'WPAI_PLUGIN_DIR', defined( 'WPAI_DIR' ) ? WPAI_DIR : '' );
}
if ( ! defined( 'WPAI_PLUGIN_URL' ) ) {
	define( 'WPAI_PLUGIN_URL', plugin_dir_url( WPAI_PLUGIN_FILE ) );
}
if ( ! defined( 'WPAI_MIN_PHP_VERSION' ) ) {
	define( 'WPAI_MIN_PHP_VERSION', '7.4' );
}
if ( ! defined( 'WPAI_MIN_WP_VERSION' ) ) {
	define( 'WPAI_MIN_WP_VERSION', '7.0' );
}
if ( ! defined( 'WPAI_DEFAULT_ABILITY_CATEGORY' ) ) {
	define( 'WPAI_DEFAULT_ABILITY_CATEGORY', 'ai-experiments' );
}

/**
 * Displays an admin notice for version requirement failures.
 *
 * @since 0.1.0
 *
 * @param string $message The error message to display.
 */
function version_notice( string $message ): void {
	if ( ! is_admin() ) {
		return;
	}
	?>
	<div class="notice notice-error">
		<p><?php echo esc_html( $message ); ?></p>
	</div>
	<?php
}

/**
 * Checks if the PHP version meets the minimum requirement.
 *
 * @since 0.1.0
 *
 * @return bool True if PHP version is sufficient, false otherwise.
 */
function check_php_version(): bool {
	if ( version_compare( phpversion(), WPAI_MIN_PHP_VERSION, '<' ) ) {
		add_action(
			'admin_notices',
			static function () {
				version_notice(
					sprintf(
						/* translators: 1: Required PHP version, 2: Current PHP version */
						__( 'AI plugin requires PHP version %1$s or higher. You are running PHP version %2$s.', 'ai' ),
						WPAI_MIN_PHP_VERSION,
						PHP_VERSION
					)
				);
			}
		);
		return false;
	}
	return true;
}

/**
 * Checks if the WordPress version meets the minimum requirement.
 *
 * @since 0.1.0
 *
 * @global string $wp_version WordPress version.
 *
 * @return bool True if WordPress version is sufficient, false otherwise.
 */
function check_wp_version(): bool {
	if ( ! is_wp_version_compatible( WPAI_MIN_WP_VERSION ) ) {
		add_action(
			'admin_notices',
			static function () {
				global $wp_version;
				version_notice(
					sprintf(
						/* translators: 1: Required WordPress version, 2: Current WordPress version */
						__( 'AI plugin requires WordPress version %1$s or higher. You are running WordPress version %2$s.', 'ai' ),
						WPAI_MIN_WP_VERSION,
						$wp_version
					)
				);
			}
		);
		return false;
	}
	return true;
}

/**
 * Adds action links to the plugin list table.
 *
 * This adds "Settings" and "Connectors" links to
 * the plugin's action links on the Plugins page.
 *
 * @since 0.1.1
 *
 * @param array<string> $links Existing action links.
 * @return array<string> Modified action links.
 */
function plugin_action_links( array $links ): array {
	$settings_link = sprintf(
		'<a href="%1$s">%2$s</a>',
		admin_url( 'options-general.php?page=ai' ),
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
 * Loads the plugin after checking requirements.
 *
 * @since 0.1.0
 */
function load(): void {
	static $loaded = false;

	// Prevent loading twice.
	if ( $loaded ) {
		return;
	}

	// Check version requirements.
	if ( ! check_php_version() || ! check_wp_version() ) {
		return;
	}

	// Load required files.
	require_once WPAI_PLUGIN_DIR . 'includes/autoload.php';
	require_once WPAI_PLUGIN_DIR . 'includes/helpers.php';

	// Handle any pending upgrades.
	( new Upgrades() )->init();

	// Handle deprecated code.
	( new Deprecated() )->init();

	$loaded = true;

	// Add plugin action links.
	add_filter( 'plugin_action_links_' . plugin_basename( WPAI_PLUGIN_FILE ), __NAMESPACE__ . '\plugin_action_links' );

	// Hook feature initialization to init.
	add_action( 'init', __NAMESPACE__ . '\initialize_features', 15 );
}

/**
 * Initializes plugin features.
 *
 * @since 0.1.0
 */
function initialize_features(): void {
	try {
		// Experiments are hooked into our Loader, so we need to register them first.
		$experiments = new Experiments();
		$experiments->init();

		$registry = new Registry();
		$loader   = new Loader( $registry );
		$loader->register_features();
		$loader->initialize_features();

		// Initialize settings registration.
		$settings_registration = new Settings_Registration( $registry );
		$settings_registration->init();

		// Initialize admin settings page.
		if ( is_admin() ) {
			$settings_page = new Settings_Page( $registry );
			$settings_page->init();
		}

		// Register our post-related WordPress Abilities.
		$post_abilities = new Posts();
		$post_abilities->register();

		add_action(
			'wp_abilities_api_categories_init',
			static function () {
				/**
				 * Register a generic catch-all category that all
				 * Abilities we register can use. Can re-evaluate this
				 * in the future if we need/want more specific categories.
				 */
				wp_register_ability_category(
					WPAI_DEFAULT_ABILITY_CATEGORY,
					array(
						'label'       => __( 'AI', 'ai' ),
						'description' => __( 'Various AI features and experiments.', 'ai' ),
					),
				);
			}
		);
	} catch ( \Throwable $t ) {
		_doing_it_wrong(
			__NAMESPACE__ . '\initialize_features',
			sprintf(
				/* translators: %s: Error message. */
				esc_html__( 'AI Plugin initialization failed: %s', 'ai' ),
				esc_html( $t->getMessage() )
			),
			'0.1.0'
		);
	}
}

add_action( 'plugins_loaded', __NAMESPACE__ . '\load' );


/**
 * Triggers when the plugin is activated.
 *
 * @since 0.6.0
 */
register_activation_hook(
	WPAI_PLUGIN_FILE,
	static function (): void {
		// Load required files.
		require_once WPAI_PLUGIN_DIR . 'includes/autoload.php';
		require_once WPAI_PLUGIN_DIR . 'includes/helpers.php';

		Activation::activation_callback();
	}
);
