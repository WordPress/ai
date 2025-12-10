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
 * Plugin Name:       AI Experiments
 * Plugin URI:        https://github.com/WordPress/ai
 * Description:       AI experiments and capabilities for WordPress.
 * Version:           0.1.1
 * Requires at least: 6.8
 * Requires PHP:      7.4
 * Author:            WordPress.org Contributors
 * Author URI:        https://make.wordpress.org/ai/
 * License:           GPL-2.0-or-later
 * License URI:       https://spdx.org/licenses/GPL-2.0-or-later.html
 * Text Domain:       ai
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcut constant to the path of this file.
 */
define( 'AI_EXPERIMENTS_DIR', plugin_dir_path( __FILE__ ) );

require_once AI_EXPERIMENTS_DIR . 'includes/bootstrap.php';
