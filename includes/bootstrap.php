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
use WordPress\AI\Experiments\Extended_Providers\Extended_Providers;
use WordPress\AI\Logging\Logging_Discovery_Strategy;
use WordPress\AI\Settings\Settings_Page;
use WordPress\AI\Settings\Settings_Registration;
use WordPress\AI_Client\AI_Client;
use WordPress\AI_Client\HTTP\WP_AI_Client_Discovery_Strategy;
use WordPress\AiClient\AiClient as PhpAiClient;
use WordPress\AiClient\Providers\Http\HttpTransporterFactory;

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
	define( 'AI_MIN_WP_VERSION', '6.8' );
}
if ( ! defined( 'AI_DEFAULT_ABILITY_CATEGORY' ) ) {
	define( 'AI_DEFAULT_ABILITY_CATEGORY', 'ai-experiments' );
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

	// Hook experiment initialization early on init so abilities register before discovery.
	add_action( 'init', __NAMESPACE__ . '\initialize_experiments', 1 );
}

/**
 * Initializes plugin experiments.
 *
 * @since 0.1.0
 */
function initialize_experiments(): void {
	try {
		maybe_register_extended_providers();

		// Initialize the WP AI Client.
		AI_Client::init();

		$log_manager = get_request_log_manager();
		if ( $log_manager && class_exists( Logging_Discovery_Strategy::class ) ) {
			$log_manager->init();
			Logging_Discovery_Strategy::init( $log_manager );

			if ( class_exists( PhpAiClient::class ) && class_exists( HttpTransporterFactory::class ) ) {
				PhpAiClient::defaultRegistry()->setHttpTransporter(
					HttpTransporterFactory::createTransporter()
				);
			}
		}

		$registry = new Experiment_Registry();
		$loader   = new Experiment_Loader( $registry );
		$loader->register_default_experiments();
		$loader->initialize_experiments();

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
					AI_DEFAULT_ABILITY_CATEGORY,
					array(
						'label'       => __( 'AI Experiments', 'ai' ),
						'description' => __( 'Various AI experiments.', 'ai' ),
					),
				);
			}
		);
	} catch ( \Throwable $t ) {
		_doing_it_wrong(
			__NAMESPACE__ . '\initialize_experiments',
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
 * Ensures extended provider classes are registered with the AI client registry before credentials load.
 *
 * @since 0.1.0
 */
function maybe_register_extended_providers(): void {
	static $providers_registered = false;

	if ( $providers_registered ) {
		return;
	}

	if ( ! class_exists( Extended_Providers::class ) ) {
		return;
	}

	if ( class_exists( WP_AI_Client_Discovery_Strategy::class ) ) {
		WP_AI_Client_Discovery_Strategy::init();
	}

	try {
		$experiment = new Extended_Providers();
		$experiment->register_providers();
		$providers_registered = true;
	} catch ( \Throwable $t ) {
		// Surface a developer notice but do not block bootstrapping.
		_doing_it_wrong(
			__NAMESPACE__ . '\maybe_register_extended_providers',
			sprintf(
				/* translators: %s: Error message. */
				esc_html__( 'Failed to preload extended providers: %s', 'ai' ),
				esc_html( $t->getMessage() )
			),
			'0.1.0'
		);
	}
}

add_action( 'plugins_loaded', __NAMESPACE__ . '\load' );
