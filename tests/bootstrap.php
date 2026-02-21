<?php
/**
 * Bootstrap the PHPUnit tests.
 *
 * @package WordPress\AI\Tests
 */

define( 'TESTS_REPO_ROOT_DIR', dirname( __DIR__ ) );

/**
 * Check if WordPress core has the AI Client (e.g., in trunk).
 *
 * @return bool True if WordPress core includes AI Client, false otherwise.
 */
function wp_ai_has_core_ai_client(): bool {
	$possible_paths = array(
		// wp-env location
		'/var/www/html/wp-includes/php-ai-client/autoload.php',
		// Relative to tests directory (typical WordPress test setup)
		TESTS_REPO_ROOT_DIR . '/../../../../wp-includes/php-ai-client/autoload.php',
		// Relative to plugin directory (alternative test setup)
		TESTS_REPO_ROOT_DIR . '/../../../../../wp-includes/php-ai-client/autoload.php',
	);

	foreach ( $possible_paths as $path ) {
		if ( file_exists( $path ) ) {
			return true;
		}
	}

	return false;
}

// Load Composer dependencies if applicable.
if ( file_exists( TESTS_REPO_ROOT_DIR . '/vendor/autoload.php' ) ) {
	require_once TESTS_REPO_ROOT_DIR . '/vendor/autoload.php';
}

// Load WordPress core's scoped AI Client autoloader AFTER Composer, with PREPEND so it takes precedence.
// This prevents a fatal error: core's WP_AI_Client_HTTP_Client uses scoped PSR types
// (WordPress\AiClientDependencies\Psr\*), while plugin's Composer uses unscoped Psr\*.
// Composer prepends its autoloader, so we must prepend core's autoloader after Composer to win.
if ( wp_ai_has_core_ai_client() ) {
	$core_ai_client_paths = array(
		'/var/www/html/wp-includes/php-ai-client/autoload.php',
		TESTS_REPO_ROOT_DIR . '/../../../../wp-includes/php-ai-client/autoload.php',
		TESTS_REPO_ROOT_DIR . '/../../../../../wp-includes/php-ai-client/autoload.php',
	);
	foreach ( $core_ai_client_paths as $path ) {
		if ( file_exists( $path ) ) {
			$wp_ai_client_base_dir = dirname( $path );
			spl_autoload_register(
				static function ( $class_name ) use ( $wp_ai_client_base_dir ) {
					$client_prefix     = 'WordPress\\AiClient\\';
					$client_prefix_len = 19;
					$scoped_prefix     = 'WordPress\\AiClientDependencies\\';
					$scoped_prefix_len = 31;

					if ( 0 === strncmp( $class_name, $client_prefix, $client_prefix_len ) ) {
						$relative_class = substr( $class_name, $client_prefix_len );
						$file           = $wp_ai_client_base_dir . '/src/' . str_replace( '\\', '/', $relative_class ) . '.php';
						if ( file_exists( $file ) ) {
							require $file;
							return true;
						}
					}
					if ( 0 === strncmp( $class_name, $scoped_prefix, $scoped_prefix_len ) ) {
						$relative_class = substr( $class_name, $scoped_prefix_len );
						$file           = $wp_ai_client_base_dir . '/third-party/' . str_replace( '\\', '/', $relative_class ) . '.php';
						if ( file_exists( $file ) ) {
							require $file;
							return true;
						}
					}
					return false;
				},
				true,
				true
			);
			break;
		}
	}
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
