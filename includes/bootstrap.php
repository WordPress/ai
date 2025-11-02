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
	define( 'AI_PLUGIN_DIR', AI_PLUGIN_FILE ? plugin_dir_path( AI_PLUGIN_FILE ) : '' );
}
if ( ! defined( 'AI_PLUGIN_URL' ) ) {
	define( 'AI_PLUGIN_URL', AI_PLUGIN_FILE ? plugin_dir_url( AI_PLUGIN_FILE ) : '' );
}
if ( ! defined( 'AI_MIN_PHP_VERSION' ) ) {
	define( 'AI_MIN_PHP_VERSION', '7.4' );
}
if ( ! defined( 'AI_MIN_WP_VERSION' ) ) {
	define( 'AI_MIN_WP_VERSION', '6.8' );
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

	// Check PHP version.
	if ( version_compare( phpversion(), AI_MIN_PHP_VERSION, '<' ) ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\display_php_version_notice' );
		return;
	}

	// Check WordPress version.
	global $wp_version;
	if ( version_compare( $wp_version, AI_MIN_WP_VERSION, '<' ) ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\display_wp_version_notice' );
		return;
	}

	// Check Composer autoload.
	if ( ! file_exists( AI_PLUGIN_DIR . 'vendor/autoload_packages.php' ) ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\display_composer_notice' );
		return;
	}

	// Load the Jetpack autoloader.
	require_once AI_PLUGIN_DIR . 'vendor/autoload_packages.php';

	$loaded = true;

	// Hook feature initialization to init.
	add_action( 'init', __NAMESPACE__ . '\initialize_features' );

	// Initialize admin services - use init hook to ensure REST_REQUEST is defined.
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
 * Displays PHP version notice.
 *
 * @since 0.1.0
 */
function display_php_version_notice(): void {
	if ( ! is_admin() ) {
		return;
	}
	?>
	<div class="notice notice-error">
		<p>
			<?php
			printf(
				/* translators: 1: Required PHP version, 2: Current PHP version */
				esc_html__( 'AI plugin requires PHP version %1$s or higher. You are running PHP version %2$s.', 'ai' ),
				esc_html( AI_MIN_PHP_VERSION ),
				esc_html( PHP_VERSION )
			);
			?>
		</p>
	</div>
	<?php
}

/**
 * Displays WordPress version notice.
 *
 * @since 0.1.0
 */
function display_wp_version_notice(): void {
	global $wp_version;

	if ( ! is_admin() ) {
		return;
	}
	?>
	<div class="notice notice-error">
		<p>
			<?php
			printf(
				/* translators: 1: Required WordPress version, 2: Current WordPress version */
				esc_html__( 'AI plugin requires WordPress version %1$s or higher. You are running WordPress version %2$s.', 'ai' ),
				esc_html( AI_MIN_WP_VERSION ),
				esc_html( $wp_version )
			);
			?>
		</p>
	</div>
	<?php
}

/**
 * Displays Composer autoload notice.
 *
 * @since 0.1.0
 */
function display_composer_notice(): void {
	if ( ! is_admin() ) {
		return;
	}
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

add_action( 'plugins_loaded', __NAMESPACE__ . '\load' );

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

	// Instantiate settings components directly.
	$toggle          = new Settings_Toggle();
	$feature_toggles = new Feature_Toggles();
	$registry        = new Settings_Registry();
	$renderer        = new Settings_Renderer();
	$payload_builder = new Settings_Payload_Builder( $toggle, $feature_toggles, $registry );
	$assets          = new Settings_Page_Assets( $payload_builder );
	$page            = new Admin_Settings_Page( $toggle, $registry, $assets, $payload_builder );
	$service         = new Settings_Service( $toggle, $feature_toggles, $registry, $page, $renderer );

	// Provide feature_toggles service via filter for Feature_Loader.
	add_filter(
		'ai_feature_toggles_service',
		static function () use ( $feature_toggles ) {
			return $feature_toggles;
		}
	);

	$service->register();

	$initialized = true;
}
