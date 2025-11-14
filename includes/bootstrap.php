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

use WordPress\AI\Admin\Admin_Settings_Page;
use WordPress\AI\Admin\Settings\Feature_Toggles;
use WordPress\AI\Admin\Settings\Settings_Registry;
use WordPress\AI\Admin\Settings\Settings_Toggle;

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

		add_action(
			'wp_abilities_api_categories_init',
			static function () {
				/**
				 * Register a generic catch-all category that all
				 * Abilities we register can use. Can re-evaluate this
				 * in the future if we need/want more specific categories.
				 */
				wp_register_ability_category(
					'ai-experiments',
					array(
						'label'       => __( 'AI Experiments', 'ai' ),
						'description' => __( 'Various AI experiment features.', 'ai' ),
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
	$page            = new Admin_Settings_Page( $toggle, $feature_toggles, $registry );

	add_filter(
		'ai_feature_toggles_service',
		static function () {
			return Feature_Toggles::class;
		}
	);

	add_filter(
		'ai_features_enabled',
		array( $toggle, 'filter_features_enabled' )
	);

	$register_settings = static function () use ( $toggle, $feature_toggles, $registry ): void {
		static $sections_initialized = false;

		$toggle->register();
		$feature_toggles->register();

		if ( $sections_initialized ) {
			return;
		}

		/**
		 * Allows features to register their settings sections.
		 *
		 * @since 0.1.0
		 *
		 * @param \WordPress\AI\Admin\Settings\Settings_Registry $registry Settings registry.
		 */
		do_action( 'ai_register_settings_sections', $registry );

		$sections_initialized = true;
	};

	add_action( 'admin_init', $register_settings );
	add_action( 'rest_api_init', $register_settings );

	add_action( 'admin_menu', array( $page, 'register_menu' ) );
	add_action( 'ai_register_settings_sections', array( $page, 'register_default_sections' ), 0 );

	$initialized = true;
}

add_action( 'plugins_loaded', __NAMESPACE__ . '\load' );
