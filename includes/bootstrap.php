<?php
/**
 * Bootstrap file for the AI plugin.
 *
 * Handles plugin initialization, version checks, and feature loading.
 *
 * @package WordPress\AI
 */

namespace WordPress\AI;

use WordPress\AI\Admin\Admin_Settings_Page;
use WordPress\AI\Admin\Settings\Feature_Toggles;
use WordPress\AI\Admin\Settings\Settings_Registry;
use WordPress\AI\Admin\Settings\Settings_Renderer;
use WordPress\AI\Admin\Settings\Settings_Service;
use WordPress\AI\Admin\Settings\Settings_Toggle;
use WordPress\AI\Admin\Settings_Page_Assets;
use WordPress\AI\Admin\Settings_Payload_Builder;

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
	if ( defined( 'AI_PLUGIN_FILE' ) && AI_PLUGIN_FILE ) {
		define( 'AI_PLUGIN_DIR', plugin_dir_path( AI_PLUGIN_FILE ) );
	} elseif ( defined( 'WP_AI_DIR' ) ) {
		define( 'AI_PLUGIN_DIR', WP_AI_DIR );
	} else {
		define( 'AI_PLUGIN_DIR', '' );
	}
}
if ( ! defined( 'AI_PLUGIN_URL' ) ) {
	if ( defined( 'AI_PLUGIN_FILE' ) && AI_PLUGIN_FILE ) {
		define( 'AI_PLUGIN_URL', plugin_dir_url( AI_PLUGIN_FILE ) );
	} else {
		define( 'AI_PLUGIN_URL', '' );
	}
}
if ( ! defined( 'AI_MIN_PHP_VERSION' ) ) {
	define( 'AI_MIN_PHP_VERSION', '7.4' );
}
if ( ! defined( 'AI_MIN_WP_VERSION' ) ) {
	define( 'AI_MIN_WP_VERSION', '6.8' );
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
			static function () {
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
			static function () {
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

	// Load the Jetpack autoloader.
	if ( ! file_exists( AI_PLUGIN_DIR . 'vendor/autoload_packages.php' ) ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\display_composer_notice' );
		return;
	}
	require_once AI_PLUGIN_DIR . 'vendor/autoload_packages.php';

	$loaded = true;

	// Hook feature initialization to init.
	add_action( 'init', __NAMESPACE__ . '\initialize_features' );

	// Initialize admin settings early so REST constants and hooks are available.
	add_action( 'init', __NAMESPACE__ . '\initialize_admin_settings', 5 );
}

/**
 * Initializes plugin features.
 *
 * @since 0.1.0
 */
function initialize_features(): void {
	try {
		$registry = new Feature_Registry();
		$loader   = new Feature_Loader( $registry );
		$loader->register_default_features();
		$loader->initialize_features();
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

/**
 * Bootstraps the admin settings subsystem.
 *
 * @since 0.1.0
 */
function initialize_admin_settings(): void {
	static $initialized = false;

	if ( $initialized ) {
		return;
	}

	$toggle          = new Settings_Toggle();
	$feature_toggles = new Feature_Toggles();
	$registry        = new Settings_Registry();
	$renderer        = new Settings_Renderer();
	$payload_builder = new Settings_Payload_Builder( $toggle, $feature_toggles, $registry );
	$assets          = new Settings_Page_Assets( $payload_builder );
	$page            = new Admin_Settings_Page( $toggle, $registry, $assets, $payload_builder );
	$service         = new Settings_Service( $toggle, $feature_toggles, $registry, $page, $renderer );

	add_filter(
		'ai_feature_toggles_service',
		static function () use ( $feature_toggles ) {
			return $feature_toggles;
		}
	);

	$service->register();

	$initialized = true;
}

add_action( 'plugins_loaded', __NAMESPACE__ . '\load' );
