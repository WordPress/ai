<?php
/**
 * Plugin Name: AI
 * Plugin URI: https://github.com/WordPress/ai
 * Description: Experimental AI features for WordPress
 * Version: 0.1.0
 * Requires at least: 6.7
 * Requires PHP: 7.4
 * Author: WordPress Contributors
 * Author URI: https://make.wordpress.org/ai/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ai
 * Domain Path: /languages
 *
 * @package WordPress\AI
 */

namespace WordPress\AI;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'AI_VERSION', '0.1.0' );
define( 'AI_PLUGIN_FILE', __FILE__ );
define( 'AI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AI_MIN_PHP_VERSION', '7.4' );
define( 'AI_MIN_WP_VERSION', '6.7' );

/**
 * Displays an admin notice for version requirement failures.
 *
 * @since 0.1.0
 *
 * @param string $message The error message to display.
 */
function ai_version_notice( $message ) {
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
function ai_check_php_version() {
	if ( version_compare( PHP_VERSION, AI_MIN_PHP_VERSION, '<' ) ) {
		add_action(
			'admin_notices',
			function () {
				ai_version_notice(
					sprintf(
						/* translators: 1: Required PHP version, 2: Current PHP version */
						__( 'AI plugin requires PHP version %1$s or higher. You are running PHP version %2$s.', 'ai' ),
						AI_MIN_PHP_VERSION,
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
 * @return bool True if WordPress version is sufficient, false otherwise.
 */
function ai_check_wp_version() {
	global $wp_version;

	if ( version_compare( $wp_version, AI_MIN_WP_VERSION, '<' ) ) {
		add_action(
			'admin_notices',
			function () {
				global $wp_version;
				ai_version_notice(
					sprintf(
						/* translators: 1: Required WordPress version, 2: Current WordPress version */
						__( 'AI plugin requires WordPress version %1$s or higher. You are running WordPress version %2$s.', 'ai' ),
						AI_MIN_WP_VERSION,
						$wp_version
					)
				);
			}
		);
		return false;
	}
	return true;
}

// Check version requirements before proceeding.
if ( ! ai_check_php_version() || ! ai_check_wp_version() ) {
	return;
}

// Load Composer autoloader.
if ( file_exists( AI_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once AI_PLUGIN_DIR . 'vendor/autoload.php';
}

/**
 * Initializes the plugin.
 *
 * @since 0.1.0
 */
function ai_init() {
	// Initialize the main plugin instance.
	$plugin = Plugin::instance();
	$plugin->init();
}

// Hook into plugins_loaded to initialize the plugin.
add_action( 'plugins_loaded', __NAMESPACE__ . '\ai_init' );

/**
 * Activation hook callback.
 *
 * @since 0.1.0
 */
function ai_activate() {
	// Check requirements on activation.
	if ( ! ai_check_php_version() || ! ai_check_wp_version() ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			esc_html(
				sprintf(
					/* translators: 1: Required PHP version, 2: Required WordPress version */
					__( 'AI plugin requires PHP %1$s+ and WordPress %2$s+.', 'ai' ),
					AI_MIN_PHP_VERSION,
					AI_MIN_WP_VERSION
				)
			)
		);
	}

	// Flush rewrite rules.
	flush_rewrite_rules();
}

register_activation_hook( __FILE__, __NAMESPACE__ . '\ai_activate' );

/**
 * Deactivation hook callback.
 *
 * @since 0.1.0
 */
function ai_deactivate() {
	// Flush rewrite rules on deactivation.
	flush_rewrite_rules();
}

register_deactivation_hook( __FILE__, __NAMESPACE__ . '\ai_deactivate' );