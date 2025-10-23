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
 * Description:       Experimental AI features for WordPress
 * Requires at least: 6.9
 * Version:           0.1.0
 * Requires PHP:      7.4
 * Author:            WordPress.org Contributors
 * Author URI:        https://make.wordpress.org/ai/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcut constant to the path of this file.
 */
define( 'WP_AI_DIR', plugin_dir_path( __FILE__ ) );

require_once WP_AI_DIR . 'includes/bootstrap.php';
