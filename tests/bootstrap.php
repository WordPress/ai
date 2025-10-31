<?php
/**
 * Bootstrap the PHPUnit tests.
 *
 * @package WordPress\AI\Tests
 */

define( 'TESTS_REPO_ROOT_DIR', dirname( __DIR__ ) );

// Load Abilities API classes before autoloader to ensure WP_Ability class is available.
if ( file_exists( TESTS_REPO_ROOT_DIR . '/vendor/wordpress/abilities-api/includes/abilities-api/class-wp-ability.php' ) ) {
	require_once TESTS_REPO_ROOT_DIR . '/vendor/wordpress/abilities-api/includes/abilities-api/class-wp-ability.php';
}

// Load Composer dependencies if applicable.
if ( file_exists( TESTS_REPO_ROOT_DIR . '/vendor/autoload.php' ) ) {
	require_once TESTS_REPO_ROOT_DIR . '/vendor/autoload.php';
}

// Load Abilities API bootstrap for functions.
if ( file_exists( TESTS_REPO_ROOT_DIR . '/vendor/wordpress/abilities-api/includes/bootstrap.php' ) ) {
	require_once TESTS_REPO_ROOT_DIR . '/vendor/wordpress/abilities-api/includes/bootstrap.php';
}

// Detect where to load the WordPress tests environment from.
if ( false !== getenv( 'WP_TESTS_DIR' ) ) {
	$_test_root = getenv( 'WP_TESTS_DIR' );
} elseif ( false !== getenv( 'WP_DEVELOP_DIR' ) ) {
	$_test_root = getenv( 'WP_DEVELOP_DIR' ) . '/tests/phpunit';
} elseif ( false !== getenv( 'WP_PHPUNIT__DIR' ) ) {
	$_test_root = getenv( 'WP_PHPUNIT__DIR' );
} elseif ( file_exists( TESTS_REPO_ROOT_DIR . '/../../../../../tests/phpunit/includes/functions.php' ) ) {
	$_test_root = TESTS_REPO_ROOT_DIR . '/../../../../../tests/phpunit';
} else {
	$_test_root = '/tmp/wordpress-tests-lib';
}

// Give access to tests_add_filter() function.
require_once $_test_root . '/includes/functions.php';

// Activate the plugin.
tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/ai.php';
	}
);

// Start up the WP testing environment.
require $_test_root . '/includes/bootstrap.php';
