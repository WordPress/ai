<?php
/**
 * AI
 *
 * @package     ai
 * @author      WordPress.org Contributors
 * @copyright   2025 Plugin Contributors
 * @license     GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       AI
 * Plugin URI:        https://github.com/WordPress/ai
 * Description:       AI features, experiments and capabilities for WordPress.
 * Version:           0.8.0
 * Requires at least: 7.0
 * Requires PHP:      7.4
 * Author:            WordPress.org Contributors
 * Author URI:        https://make.wordpress.org/ai/
 * License:           GPL-2.0-or-later
 * License URI:       https://spdx.org/licenses/GPL-2.0-or-later.html
 * Text Domain:       ai
 */

declare(strict_types=1);

namespace WordPress\AI;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Define the plugin constants.
 */
function constants(): void {
	/**
	 * Main plugin file path.
	 */
	if ( ! defined( 'WPAI_PLUGIN_FILE' ) ) {
		define( 'WPAI_PLUGIN_FILE', __FILE__ );
	}

	/**
	 * Plugin version.
	 */
	if ( ! defined( 'WPAI_VERSION' ) ) {
		define( 'WPAI_VERSION', '0.8.0' );
	}

	/**
	 * Plugin directory path.
	 */
	if ( ! defined( 'WPAI_PLUGIN_DIR' ) ) {
		define( 'WPAI_PLUGIN_DIR', plugin_dir_path( WPAI_PLUGIN_FILE ) );
	}

	/**
	 * Plugin directory URL.
	 */
	if ( ! defined( 'WPAI_PLUGIN_URL' ) ) {
		define( 'WPAI_PLUGIN_URL', plugin_dir_url( WPAI_PLUGIN_FILE ) );
	}

	/**
	 * Default ability category for the plugin.
	 */
	if ( ! defined( 'WPAI_DEFAULT_ABILITY_CATEGORY' ) ) {
		define( 'WPAI_DEFAULT_ABILITY_CATEGORY', 'ai-experiments' );
	}

	// Define other plugin constants here as needed.
}
constants();

// Load the autoloader.
require_once WPAI_PLUGIN_DIR . 'includes/autoload.php';

\WordPress\AI\Main::get_instance();
