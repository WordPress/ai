<?php
/**
 * Bootstrap file for the AI plugin.
 *
 * Handles plugin initialization, version checks, and feature loading.
 *
 * @package WordPress\AI
 */

namespace WordPress\AI;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
if ( ! defined( 'AI_VERSION' ) ) {
	define( 'AI_VERSION', '0.1.0' );
}
if ( ! defined( 'AI_PLUGIN_FILE' ) ) {
	define( 'AI_PLUGIN_FILE', defined( 'WP_AI_DIR' ) ? WP_AI_DIR . 'ai.php' : '' );
}
if ( ! defined( 'AI_PLUGIN_DIR' ) ) {
	define( 'AI_PLUGIN_DIR', defined( 'WP_AI_DIR' ) ? WP_AI_DIR : '' );
}
if ( ! defined( 'AI_PLUGIN_URL' ) ) {
	define( 'AI_PLUGIN_URL', plugin_dir_url( AI_PLUGIN_FILE ) );
}
if ( ! defined( 'AI_MIN_PHP_VERSION' ) ) {
	define( 'AI_MIN_PHP_VERSION', '7.4' );
}
if ( ! defined( 'AI_MIN_WP_VERSION' ) ) {
	define( 'AI_MIN_WP_VERSION', '6.9' );
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
	if ( version_compare( phpversion(), AI_MIN_PHP_VERSION, '<' ) ) {
		add_action(
			'admin_notices',
			function () {
				version_notice(
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
function check_wp_version(): bool {
	global $wp_version;

	if ( version_compare( $wp_version, AI_MIN_WP_VERSION, '<' ) ) {
		add_action(
			'admin_notices',
			function () {
				global $wp_version;
				version_notice(
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

/**
 * Displays admin notice about missing Composer autoload files.
 *
 * @since 0.1.0
 */
function display_composer_notice(): void {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			printf(
				/* translators: %s: composer install command */
				esc_html__( 'Your installation of the AI plugin is incomplete. Please run %s.', 'ai' ),
				'<code>composer install</code>'
			);
			?>
		</p>
	</div>
	<?php
}

/**
 * Loads the plugin after checking requirements.
 *
 * @since 0.1.0
 */
function load() {
	static $loaded = false;

	// Check version requirements.
	if ( ! check_php_version() || ! check_wp_version() ) {
		return;
	}

	// Load Composer autoloader.
	if ( ! file_exists( AI_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\display_composer_notice' );
		return;
	}
	require_once AI_PLUGIN_DIR . 'vendor/autoload.php';

	// Prevent loading twice.
	if ( $loaded ) {
		return;
	}
	$loaded = true;

	// Initialize plugin.
	$registry = new Feature_Registry();
	$loader   = new Feature_Loader( $registry );
	$loader->register_default_features();
	$loader->initialize_features();
}

add_action( 'plugins_loaded', __NAMESPACE__ . '\load' );