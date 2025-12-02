<?php
/**
 * Bootstrap file for the AI Experiments plugin.
 *
 * Handles plugin initialization, version checks, and feature loading.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI;

use WordPress\AI\Abilities\Utilities\Posts;
use WordPress\AI\Settings\Settings_Page;
use WordPress\AI\Settings\Settings_Registration;
use WordPress\AI_Client\AI_Client;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
if ( ! defined( 'AI_EXPERIMENTS_VERSION' ) ) {
	define( 'AI_EXPERIMENTS_VERSION', '0.1.1' );
}
if ( ! defined( 'AI_EXPERIMENTS_PLUGIN_FILE' ) ) {
	define( 'AI_EXPERIMENTS_PLUGIN_FILE', defined( 'AI_EXPERIMENTS_DIR' ) ? AI_EXPERIMENTS_DIR . 'ai.php' : '' );
}
if ( ! defined( 'AI_EXPERIMENTS_PLUGIN_DIR' ) ) {
	define( 'AI_EXPERIMENTS_PLUGIN_DIR', defined( 'AI_EXPERIMENTS_DIR' ) ? AI_EXPERIMENTS_DIR : '' );
}
if ( ! defined( 'AI_EXPERIMENTS_PLUGIN_URL' ) ) {
	define( 'AI_EXPERIMENTS_PLUGIN_URL', plugin_dir_url( AI_EXPERIMENTS_PLUGIN_FILE ) );
}
if ( ! defined( 'AI_EXPERIMENTS_MIN_PHP_VERSION' ) ) {
	define( 'AI_EXPERIMENTS_MIN_PHP_VERSION', '7.4' );
}
if ( ! defined( 'AI_EXPERIMENTS_MIN_WP_VERSION' ) ) {
	define( 'AI_EXPERIMENTS_MIN_WP_VERSION', '6.8' );
}
if ( ! defined( 'AI_EXPERIMENTS_DEFAULT_ABILITY_CATEGORY' ) ) {
	define( 'AI_EXPERIMENTS_DEFAULT_ABILITY_CATEGORY', 'ai-experiments' );
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
	if ( version_compare( phpversion(), AI_EXPERIMENTS_MIN_PHP_VERSION, '<' ) ) {
		add_action(
			'admin_notices',
			static function () {
				version_notice(
					sprintf(
						/* translators: 1: Required PHP version, 2: Current PHP version */
						__( 'AI Experiments plugin requires PHP version %1$s or higher. You are running PHP version %2$s.', 'ai' ),
						AI_EXPERIMENTS_MIN_PHP_VERSION,
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

	if ( version_compare( $wp_version, AI_EXPERIMENTS_MIN_WP_VERSION, '<' ) ) {
		add_action(
			'admin_notices',
			static function () {
				global $wp_version;
				version_notice(
					sprintf(
						/* translators: 1: Required WordPress version, 2: Current WordPress version */
						__( 'AI Experiments plugin requires WordPress version %1$s or higher. You are running WordPress version %2$s.', 'ai' ),
						AI_EXPERIMENTS_MIN_WP_VERSION,
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
				esc_html__( 'Your installation of the AI Experiments plugin is incomplete. Please run %s.', 'ai' ),
				'<code>composer install</code>'
			);
			?>
		</p>
	</div>
	<?php
}

/**
 * Adds action links to the plugin list table.
 *
 * This adds a "Settings" link to the plugin's action links on the Plugins page.
 *
 * @since 0.1.1
 *
 * @param array<string> $links Existing action links.
 * @return array<string> Modified action links.
 */
function plugin_action_links( array $links ): array {
	$settings_link = sprintf(
		'<a href="%1$s">%2$s</a>',
		admin_url( 'options-general.php?page=ai-experiments' ),
		esc_html__( 'Settings', 'ai' )
	);

	array_unshift( $links, $settings_link );

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

	// Load the Jetpack autoloader.
	if ( ! file_exists( AI_EXPERIMENTS_PLUGIN_DIR . 'vendor/autoload_packages.php' ) ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\display_composer_notice' );
		return;
	}
	require_once AI_EXPERIMENTS_PLUGIN_DIR . 'vendor/autoload_packages.php';

	$loaded = true;

	// Add plugin action links.
	add_filter( 'plugin_action_links_' . plugin_basename( AI_EXPERIMENTS_PLUGIN_FILE ), __NAMESPACE__ . '\plugin_action_links' );

	// Hook experiment initialization to init.
	add_action( 'init', __NAMESPACE__ . '\initialize_experiments' );
}

/**
 * Initializes plugin experiments.
 *
 * @since 0.1.0
 */
function initialize_experiments(): void {
	try {
		// Initialize the WP AI Client.
		AI_Client::init();

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
					AI_EXPERIMENTS_DEFAULT_ABILITY_CATEGORY,
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

add_action( 'plugins_loaded', __NAMESPACE__ . '\load' );
