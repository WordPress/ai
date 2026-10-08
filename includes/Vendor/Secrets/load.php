<?php
/**
 * Bootstraps the vendored Secrets API feature plugin.
 *
 * If WordPress core (7.2+) or the standalone Secrets API feature plugin already
 * provides wp_get_secret(), this loader stands down completely to avoid collision.
 *
 * @package WordPress\AI
 * @since 1.5.0
 */

declare( strict_types=1 );

namespace WordPress\AI\Vendor\Secrets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( function_exists( 'wp_get_secret' ) ) {
	return;
}

$core_bound = array(
	'src/wp-includes/secrets.php',
	'src/wp-includes/secrets/class-wp-secret-version.php',
	'src/wp-includes/secrets/class-wp-secret.php',
	'src/wp-includes/secrets/interface-wp-secrets-provider.php',
	'src/wp-includes/secrets/interface-wp-secrets-keyring.php',
	'src/wp-includes/secrets/interface-wp-secrets-store.php',
	'src/wp-includes/secrets/class-wp-secrets-config-key-provider.php',
	'src/wp-includes/secrets/class-wp-secrets-broken-keyring.php',
	'src/wp-includes/secrets/class-wp-secrets-cipher.php',
	'src/wp-includes/secrets/class-wp-secrets-key-manager.php',
	'src/wp-includes/secrets/class-wp-secrets-option-store.php',
	'src/wp-includes/secrets/class-wp-secrets-broken-store.php',
	'src/wp-includes/secrets/class-wp-secrets-libsodium-provider.php',
	'src/wp-includes/secrets/class-wp-secrets-broken-provider.php',
	'plugin/class-secrets-api-legacy-reader.php',
	'plugin/class-secrets-api-prototype-fallback-store.php',
	'plugin/class-secrets-api-migrator.php',
);

foreach ( $core_bound as $file ) {
	require_once __DIR__ . '/' . $file;
}

if ( ! isset( $GLOBALS['wp_secrets_store'] ) && class_exists( 'Secrets_API_Prototype_Fallback_Store' ) && class_exists( 'WP_Secrets_Option_Store' ) ) {
	$GLOBALS['wp_secrets_store'] = new \Secrets_API_Prototype_Fallback_Store( new \WP_Secrets_Option_Store() );
}
