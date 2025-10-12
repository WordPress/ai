<?php
/**
 * WordPress test suite configuration for the AI plugin.
 *
 * This file is loaded by the wp-phpunit bootstrap when running the plugin tests.
 * Environment variables can override the defaults below.
 */

$env = static function ( string $key, ?string $default = null ): ?string {
	$value = getenv( $key );

	if ( false === $value || '' === $value ) {
		return $default;
	}

	return $value;
};

defined( 'DB_NAME' ) || define( 'DB_NAME', $env( 'WP_DB_NAME', 'wordpress_test' ) );
defined( 'DB_USER' ) || define( 'DB_USER', $env( 'WP_DB_USER', 'root' ) );
defined( 'DB_PASSWORD' ) || define( 'DB_PASSWORD', $env( 'WP_DB_PASSWORD', '' ) );
defined( 'DB_HOST' ) || define( 'DB_HOST', $env( 'WP_DB_HOST', '127.0.0.1' ) );
defined( 'DB_CHARSET' ) || define( 'DB_CHARSET', $env( 'WP_DB_CHARSET', 'utf8' ) );
defined( 'DB_COLLATE' ) || define( 'DB_COLLATE', $env( 'WP_DB_COLLATE', '' ) );

$table_prefix = $env( 'WP_TABLE_PREFIX', 'wptests_' );

defined( 'WP_TESTS_DOMAIN' ) || define( 'WP_TESTS_DOMAIN', $env( 'WP_TESTS_DOMAIN', 'example.org' ) );
defined( 'WP_TESTS_EMAIL' ) || define( 'WP_TESTS_EMAIL', $env( 'WP_TESTS_EMAIL', 'admin@example.org' ) );
defined( 'WP_TESTS_TITLE' ) || define( 'WP_TESTS_TITLE', $env( 'WP_TESTS_TITLE', 'WordPress Test Site' ) );
defined( 'WP_PHP_BINARY' ) || define( 'WP_PHP_BINARY', $env( 'WP_PHP_BINARY', 'php' ) );

defined( 'WP_DEBUG' ) || define( 'WP_DEBUG', true );
defined( 'WP_DEBUG_DISPLAY' ) || define( 'WP_DEBUG_DISPLAY', false );
defined( 'WPLANG' ) || define( 'WPLANG', '' );

$core_dir_candidates = array(
	$env( 'WP_CORE_DIR' ),
	$env( 'WP_ABSPATH' ),
	$env( 'WP_PHPUNIT__DIR' ) ? rtrim( $env( 'WP_PHPUNIT__DIR' ), '/\\' ) . '/wordpress' : null,
	$env( 'WP_PHPUNIT__DIR' ) ? rtrim( $env( 'WP_PHPUNIT__DIR' ), '/\\' ) . '/src' : null,
	rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib/src',
);

$core_dir = null;
foreach ( $core_dir_candidates as $candidate ) {
	if ( null === $candidate ) {
		continue;
	}

	$candidate = rtrim( $candidate, '/\\' );

	if ( file_exists( $candidate . '/wp-settings.php' ) ) {
		$core_dir = $candidate;
		break;
	}
}

if ( null === $core_dir ) {
	throw new RuntimeException(
		sprintf(
			'Unable to locate a WordPress installation for the test suite. Checked: %s',
			implode( ', ', array_filter( $core_dir_candidates ) )
		)
	);
}

defined( 'ABSPATH' ) || define( 'ABSPATH', $core_dir . '/' );
