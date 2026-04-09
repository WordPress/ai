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
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Experiments\Experiments;
use WordPress\AI\Features\Feature_Category;
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
	define( 'WPAI_VERSION', '0.7.0' );
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
 * Gets feature group metadata for the settings UI.
 *
 * @since 0.7.0
 *
 * @return array<string, array{label:string, description:string, order:int}>
 */
function get_settings_feature_groups(): array {
	$default_groups = array(
		Experiment_Category::EDITOR => array(
			'label'       => __( 'Editor Experiments', 'ai' ),
			'description' => __( 'AI-powered experiments for the block editor, including content generation and enhancement tools.', 'ai' ),
			'order'       => 10,
		),
		Experiment_Category::ADMIN  => array(
			'label'       => __( 'Admin Experiments', 'ai' ),
			'description' => __( 'AI-powered experiments for the WordPress admin area, including exploration and testing tools.', 'ai' ),
			'order'       => 20,
		),
		Feature_Category::OTHER     => array(
			'label'       => __( 'Other Features', 'ai' ),
			'description' => __( 'Additional AI-powered features.', 'ai' ),
			'order'       => 90,
		),
	);

	/**
	 * Filters feature group metadata used by the settings UI.
	 *
	 * @since 0.7.0
	 *
	 * @param array<string, array{label:string, description:string, order:int}> $default_groups Feature group metadata keyed by category.
	 */
	$filtered_groups = apply_filters( 'wpai_settings_feature_groups', $default_groups );

	return is_array( $filtered_groups ) ? $filtered_groups : $default_groups;
}

/**
 * Builds feature metadata used by the settings route UI.
 *
 * @since 0.7.0
 *
 * @param \WordPress\AI\Features\Registry $registry Feature registry instance.
 * @return array{
 *   groups:list<array{id:string, label:string, description:string}>,
 *   features:list<array{id:string, settingName:string, label:string, description:string, category:string}>
 * }
 */
function get_settings_feature_metadata( Registry $registry ): array {
	$group_definitions = get_settings_feature_groups();
	$categories_in_use = array();
	$features          = array();

	foreach ( $registry->get_all_features() as $feature ) {
		$feature_id = $feature::get_id();
		$category   = $feature->get_category();

		if ( ! is_string( $category ) || '' === $category ) {
			$category = Feature_Category::OTHER;
		}

		if ( ! isset( $group_definitions[ $category ] ) ) {
			$group_definitions[ $category ] = array(
				'label'       => ucwords( str_replace( array( '-', '_' ), ' ', $category ) ),
				'description' => '',
				'order'       => 100,
			);
		}

		$categories_in_use[ $category ] = true;
		$features[]                     = array(
			'id'             => $feature_id,
			'settingName'    => "wpai_feature_{$feature_id}_enabled",
			'label'          => $feature->get_label(),
			'description'    => wp_strip_all_tags( $feature->get_description() ),
			'category'       => $category,
			'settingsFields' => $feature->get_settings_fields_metadata(),
		);
	}

	$groups = array();
	foreach ( array_keys( $categories_in_use ) as $category ) {
		$group = $group_definitions[ $category ] ?? array();

		$groups[] = array(
			'id'          => $category,
			'label'       => isset( $group['label'] ) && is_string( $group['label'] ) && '' !== $group['label']
				? $group['label']
				: ucwords( str_replace( array( '-', '_' ), ' ', $category ) ),
			'description' => isset( $group['description'] ) && is_string( $group['description'] )
				? $group['description']
				: '',
			'order'       => isset( $group['order'] ) ? (int) $group['order'] : 100,
		);
	}

	usort(
		$groups,
		static function ( array $first, array $second ): int {
			if ( $first['order'] === $second['order'] ) {
				return strcasecmp( (string) $first['label'], (string) $second['label'] );
			}

			return $first['order'] <=> $second['order'];
		}
	);

	$groups = array_values(
		array_map(
			static function ( array $group ): array {
				unset( $group['order'] );
				return $group;
			},
			$groups
		)
	);

	$metadata = array(
		'groups'   => $groups,
		'features' => $features,
	);

	/**
	 * Filters settings metadata passed to the settings route client.
	 *
	 * @since 0.7.0
	 *
	 * @param array{
	 *   groups:list<array{id:string, label:string, description:string}>,
	 *   features:list<array{id:string, settingName:string, label:string, description:string, category:string}>
	 * } $metadata Settings UI metadata.
	 * @param \WordPress\AI\Features\Registry $registry Feature registry instance.
	 */
	$filtered_metadata = apply_filters( 'wpai_settings_feature_metadata', $metadata, $registry );

	return is_array( $filtered_metadata ) ? $filtered_metadata : $metadata;
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

	// Load auto-generated wp-build registration if present.
	if ( file_exists( WPAI_PLUGIN_DIR . 'build/build.php' ) ) {
		require_once WPAI_PLUGIN_DIR . 'build/build.php';
	}

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

		// Register admin settings page menu item.
		if ( is_admin() ) {
			Settings_Page::init( $registry );
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
 * @since 0.7.0
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
