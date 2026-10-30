<?php
/**
 * Loads the vendored copy of core's Secrets API.
 *
 * If WordPress core (7.2+) or the standalone Secrets API feature plugin already
 * provides wp_get_secret(), this loader stands down completely to avoid collision.
 * Only core's files are loaded, and no store, keyring, or provider is overridden,
 * so the API behaves here exactly as it does in core.
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
);

foreach ( $core_bound as $file ) {
	require_once __DIR__ . '/' . $file;
}
