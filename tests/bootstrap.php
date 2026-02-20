<?php
/**
 * Bootstrap the PHPUnit tests.
 *
 * @package WordPress\AI\Tests
 */

define( 'TESTS_REPO_ROOT_DIR', dirname( __DIR__ ) );

if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', TESTS_REPO_ROOT_DIR . '/vendor/yoast/phpunit-polyfills' );
}

/**
 * Preloads core AI client contracts to avoid test bootstrap interface conflicts.
 *
 * PHPUnit is invoked through Composer, which registers the plugin's Composer
 * autoloader before WordPress core loads. On WordPress trunk/7.0, core ships a
 * scoped AI client contract for `ClientWithOptionsInterface`. If that interface
 * is first loaded from Composer dependencies, signature mismatch fatals can
 * occur when core classes are declared.
 *
 * @return void
 */
function wp_ai_maybe_preload_core_ai_client_contracts(): void {
	$core_roots = array(
		'/var/www/html/wp-includes',
		TESTS_REPO_ROOT_DIR . '/../../../../wp-includes',
		TESTS_REPO_ROOT_DIR . '/../../../../../wp-includes',
	);

	$core_autoload_path = '';
	foreach ( $core_roots as $core_root ) {
		$autoload_path = rtrim( $core_root, '/\\' ) . '/php-ai-client/autoload.php';
		if ( ! file_exists( $autoload_path ) ) {
			continue;
		}

		$core_autoload_path = $autoload_path;
		break;
	}

	if ( '' === $core_autoload_path ) {
		return;
	}

	require_once $core_autoload_path;

	// Move the core AI client autoloader ahead of Composer's autoloader for tests.
	$autoloaders = spl_autoload_functions();
	if ( ! is_array( $autoloaders ) ) {
		return;
	}

	foreach ( $autoloaders as $autoloader ) {
		if ( ! $autoloader instanceof \Closure ) {
			continue;
		}

		$reflection = new \ReflectionFunction( $autoloader );
		$file_name  = $reflection->getFileName();
		if ( ! is_string( $file_name ) ) {
			continue;
		}

		$normalized_file_name         = str_replace( '\\', '/', $file_name );
		$normalized_core_autoload_path = str_replace( '\\', '/', $core_autoload_path );
		if ( false === strpos( $normalized_file_name, $normalized_core_autoload_path ) ) {
			continue;
		}

		spl_autoload_unregister( $autoloader );
		spl_autoload_register( $autoloader, true, true );
		break;
	}

	// Preload key classes/contracts from core to avoid mixed-version declarations.
	$symbols_to_preload = array(
		array(
			'type' => 'class',
			'name' => '\WordPress\AiClient\AiClient',
		),
		array(
			'type' => 'interface',
			'name' => '\WordPress\AiClient\Providers\Http\Contracts\ClientWithOptionsInterface',
		),
		array(
			'type' => 'class',
			'name' => '\WordPress\AiClient\Providers\Http\Abstracts\AbstractClientDiscoveryStrategy',
		),
	);

	foreach ( $symbols_to_preload as $symbol ) {
		if ( ! isset( $symbol['type'], $symbol['name'] ) || ! is_string( $symbol['type'] ) || ! is_string( $symbol['name'] ) ) {
			continue;
		}

		if ( 'interface' === $symbol['type'] ) {
			interface_exists( $symbol['name'] );
			continue;
		}

		if ( 'class' === $symbol['type'] ) {
			class_exists( $symbol['name'] );
			continue;
		}
	}
}

wp_ai_maybe_preload_core_ai_client_contracts();

/**
 * Check if WordPress core has the Abilities API (e.g., in trunk).
 *
 * @return bool True if WordPress core includes Abilities API, false otherwise.
 */
function wp_ai_has_core_abilities_api(): bool {
	// Check common WordPress core locations for the Abilities API file.
	$possible_paths = array(
		// wp-env location
		'/var/www/html/wp-includes/abilities-api/class-wp-ability.php',
		// Relative to tests directory (typical WordPress test setup)
		TESTS_REPO_ROOT_DIR . '/../../../../wp-includes/abilities-api/class-wp-ability.php',
		// Relative to plugin directory (alternative test setup)
		TESTS_REPO_ROOT_DIR . '/../../../../../wp-includes/abilities-api/class-wp-ability.php',
	);

	foreach ( $possible_paths as $path ) {
		if ( file_exists( $path ) ) {
			return true;
		}
	}

	return false;
}

// Load Abilities API classes before autoloader to ensure WP_Ability class is available.
// Only load from vendor if WordPress core doesn't already include it (e.g., when running against trunk).
if ( ! wp_ai_has_core_abilities_api() && file_exists( TESTS_REPO_ROOT_DIR . '/vendor/wordpress/abilities-api/includes/abilities-api/class-wp-ability.php' ) ) {
	require_once TESTS_REPO_ROOT_DIR . '/vendor/wordpress/abilities-api/includes/abilities-api/class-wp-ability.php';
}

// Do not load Composer's regular autoloader in this bootstrap.
// The plugin itself loads Jetpack autoloader from ai.php, and loading Composer
// here can preload conflicting classes when core provides AI client packages.

// Load Abilities API bootstrap for functions.
// Only load from vendor if WordPress core doesn't already include it.
if ( ! wp_ai_has_core_abilities_api() && file_exists( TESTS_REPO_ROOT_DIR . '/vendor/wordpress/abilities-api/includes/bootstrap.php' ) ) {
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
