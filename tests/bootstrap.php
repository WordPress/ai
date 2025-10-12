<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package WordPress\AI\Tests
 */

// Composer autoloader.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// WordPress tests directory.
$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
 	$_tests_dir = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';
}

// Forward plugin defines to tests.
define( 'AI_PLUGIN_FILE', dirname( __DIR__ ) . '/ai.php' );
define( 'AI_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'AI_PLUGIN_URL', 'http://example.org/wp-content/plugins/ai/' );
define( 'AI_VERSION', '0.1.0' );
define( 'AI_MIN_PHP_VERSION', '7.4' );
define( 'AI_MIN_WP_VERSION', '6.7' );

// Share the project-level wp-tests config with wp-phpunit.
$tests_config = dirname( __DIR__ ) . '/tests/wp-tests-config.php';
if ( file_exists( $tests_config ) ) {
	putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . $tests_config );
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin() {
	require AI_PLUGIN_FILE;
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';