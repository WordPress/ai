<?php
/**
 * Bootstrap the PHPUnit tests.
 *
 * @package WordPress\AI\Tests
 */

define( 'TESTS_REPO_ROOT_DIR', dirname( __DIR__ ) );

// Used to conditionally skip code that should not run during tests, such as the autoloader.
if ( ! defined( 'WPAI_IS_TEST' ) ) {
	define( 'WPAI_IS_TEST', true );
}

// Load Composer dependencies if applicable.
if ( file_exists( TESTS_REPO_ROOT_DIR . '/vendor/autoload.php' ) ) {
	require_once TESTS_REPO_ROOT_DIR . '/vendor/autoload.php';
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

/*
 * Register the core ability categories that the WordPress test suite removes.
 *
 * The suite unhooks `wp_register_core_ability_categories()` at priority 1, so that core
 * abilities do not register during tests. The categories go with them. The plugin's own
 * abilities still declare core categories such as `site` and `user`, and
 * `wp_register_ability()` refuses a category that is not registered, so every plugin
 * ability would emit an "incorrect usage" notice as soon as the registry boots.
 *
 * Put the categories back for tests only. This runs after the suite has unhooked core, and
 * before the plugin registers the categories it owns.
 *
 * Core owns `site` and `user`. The plugin owns the categories it registers itself, and those
 * arrive through the plugin's own callbacks on this hook, so they need nothing here.
 *
 * @see _unhook_core_ability_categories_registration() in the WordPress test suite.
 */
tests_add_filter(
	'wp_abilities_api_categories_init',
	static function (): void {
		if ( ! function_exists( 'wp_register_core_ability_categories' ) ) {
			return;
		}

		/*
		 * Should the suite ever stop unhooking core, its callback still runs at priority 10.
		 * Standing down keeps this from registering the same categories twice.
		 */
		if ( has_action( 'wp_abilities_api_categories_init', 'wp_register_core_ability_categories' ) ) {
			return;
		}

		wp_register_core_ability_categories();
	},
	5
);

// Start up the WP testing environment.
require $_test_root . '/includes/bootstrap.php';
