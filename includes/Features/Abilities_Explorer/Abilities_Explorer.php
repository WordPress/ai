<?php
/**
 * Abilities Explorer Feature
 *
 * Discover, inspect, test, and document all abilities registered via the WordPress Abilities API.
 *
 * @package WordPress\AI\Features\Abilities_Explorer
 * @since 0.1.0
 */

namespace WordPress\AI\Features\Abilities_Explorer;

use WordPress\AI\Abstracts\Abstract_Feature;

/**
 * Abilities Explorer Feature Class
 *
 * Provides a comprehensive interface for exploring the WordPress Abilities API.
 *
 * @since 0.1.0
 */
class Abilities_Explorer extends Abstract_Feature {

	/**
	 * Feature version.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const VERSION = '1.0.0';

	/**
	 * Load feature metadata.
	 *
	 * @since 0.1.0
	 *
	 * @return array{id: string, label: string, description: string} Feature metadata.
	 */
	protected function load_feature_metadata(): array {
		return array(
			'id'          => 'abilities-explorer',
			'label'       => __( 'Abilities Explorer', 'ai' ),
			'description' => __( 'Discover, inspect, test, and document all abilities registered via the WordPress Abilities API.', 'ai' ),
		);
	}

	/**
	 * Register the feature.
	 *
	 * Sets up hooks and initializes the feature functionality.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		// Check if Abilities API is available
		if ( ! $this->check_abilities_api() ) {
			add_action( 'admin_notices', array( $this, 'abilities_api_notice' ) );
			return;
		}

		// Initialize admin interface
		if ( is_admin() ) {
			$this->init_admin();
		}
	}


	/**
	 * Check if Abilities API is available.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True if API is available, false otherwise.
	 */
	private function check_abilities_api(): bool {
		// Allow bypassing the check for development/testing
		if ( defined( 'AI_ABILITIES_EXPLORER_SKIP_API_CHECK' ) && AI_ABILITIES_EXPLORER_SKIP_API_CHECK ) {
			return true;
		}

		// Check for WP_Ability class (the official way)
		if ( class_exists( 'WP_Ability' ) ) {
			return true;
		}

		// Check for the main API functions
		if ( function_exists( 'wp_get_abilities' ) && function_exists( 'wp_register_ability' ) ) {
			return true;
		}

		return false;
	}


	/**
	 * Display Abilities API notice.
	 *
	 * @since 0.1.0
	 */
	public function abilities_api_notice(): void {
		global $wp_version;

		$message = __(
			'Abilities Explorer requires the Abilities API which is not available.',
			'ai'
		);

		// Build debug information
		$debug_info   = array();
		$debug_info[] = sprintf( 'WordPress Version: %s', $wp_version );
		$debug_info[] = '';
		$debug_info[] = '=== Required Components ===';
		$debug_info[] = sprintf( 'WP_Ability class: %s', class_exists( 'WP_Ability' ) ? 'EXISTS ✓' : 'NOT FOUND ✗' );
		$debug_info[] = sprintf( 'WP_Ability_Category class: %s', class_exists( 'WP_Ability_Category' ) ? 'EXISTS ✓' : 'NOT FOUND ✗' );
		$debug_info[] = sprintf( 'WP_Abilities_Registry class: %s', class_exists( 'WP_Abilities_Registry' ) ? 'EXISTS ✓' : 'NOT FOUND ✗' );
		$debug_info[] = '';
		$debug_info[] = '=== Required Functions ===';
		$debug_info[] = sprintf( 'wp_register_ability(): %s', function_exists( 'wp_register_ability' ) ? 'EXISTS ✓' : 'NOT FOUND ✗' );
		$debug_info[] = sprintf( 'wp_get_abilities(): %s', function_exists( 'wp_get_abilities' ) ? 'EXISTS ✓' : 'NOT FOUND ✗' );
		$debug_info[] = sprintf( 'wp_get_ability(): %s', function_exists( 'wp_get_ability' ) ? 'EXISTS ✓' : 'NOT FOUND ✗' );
		$debug_info[] = sprintf( 'wp_has_ability(): %s', function_exists( 'wp_has_ability' ) ? 'EXISTS ✓' : 'NOT FOUND ✗' );

		// Check for API version
		if ( defined( 'WP_ABILITIES_API_VERSION' ) ) {
			$debug_info[] = '';
			$debug_info[] = sprintf( 'API Version: %s', WP_ABILITIES_API_VERSION );
		}

		// Check if the plugin is installed but not activated
		$debug_info[] = '';
		$debug_info[] = '=== Installation Status ===';
		$plugins      = get_plugins();
		$found_plugin = false;
		foreach ( $plugins as $plugin_file => $plugin_data ) {
			if ( stripos( $plugin_data['Name'], 'abilit' ) !== false ) {
				$is_active    = is_plugin_active( $plugin_file );
				$debug_info[] = sprintf(
					'Plugin: %s (%s)',
					$plugin_data['Name'],
					$is_active ? 'ACTIVE ✓' : 'INACTIVE ✗'
				);
				$found_plugin = true;
			}
		}
		if ( ! $found_plugin ) {
			$debug_info[] = 'No Abilities API plugin found in /wp-content/plugins/';
		}

		$help_text  = '<strong>' . esc_html__( 'The Abilities API is not available in your WordPress installation.', 'ai' ) . '</strong><br><br>';
		$help_text .= esc_html__( 'To use this feature, the Abilities API must be available in your WordPress installation.', 'ai' ) . '<br><br>';
		$help_text .= '<em>' . sprintf(
			/* translators: %s: constant name */
			esc_html__( 'For development/testing: add %s to wp-config.php to bypass this check', 'ai' ),
			"<code>define( 'AI_ABILITIES_EXPLORER_SKIP_API_CHECK', true );</code>"
		) . '</em>';

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong></p><p>%s</p><details style="margin-top: 10px;"><summary style="cursor: pointer;">%s</summary><pre style="background: #f0f0f0; padding: 10px; margin-top: 10px; overflow-x: auto;">%s</pre></details></div>',
			esc_html( $message ),
			$help_text, // Already escaped above
			esc_html__( 'Debug Information', 'ai' ),
			esc_html( implode( "\n", $debug_info ) )
		);
	}

	/**
	 * Initialize admin functionality.
	 *
	 * @since 0.1.0
	 */
	private function init_admin(): void {
		require_once __DIR__ . '/Admin_Page.php';

		$admin_page = new Admin_Page();
		$admin_page->init();
	}
}
