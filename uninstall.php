<?php
/**
 * Uninstall routine for the AI plugin.
 *
 * Runs only when the plugin is deleted (not on deactivation). Data is removed
 * only for sites that opted in via the "remove all data on uninstall" setting.
 *
 * @package WordPress\AI
 */

declare(strict_types=1);

namespace WordPress\AI;

use WordPress\AI\Admin\Uninstall;

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Load the autoloader so the Uninstall class can be resolved. The plugin itself
// is not bootstrapped during uninstall, so we avoid loading ai.php.
require_once __DIR__ . '/includes/autoload.php';

Uninstall::uninstall();
