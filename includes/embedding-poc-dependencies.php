<?php

/**
 * Temporary loader for unmerged upstream "embedding generation" branches.
 *
 * Loads two Composer-style autoloaders: the dev-branch copies of the PHP AI Client SDK and
 * three AI Provider packages (OpenAI, Google, Ollama) that add embedding support ahead of
 * those changes landing upstream and their Strauss-prefixed PSR/HTTP
 * dependencies — prefixed to match the exact `WordPress\AiClientDependencies\` convention
 * WP core's own bundled php-ai-client uses. Makes sure both win over whatever
 * WP core or an installed AI Provider plugin already defines under the same class names.
 *
 * This file exists only to support the embedding PoC and should be removed once the
 * upstream PRs it depends on are merged and released:
 * - https://github.com/WordPress/php-ai-client/pull/244
 * - https://github.com/WordPress/ai-provider-for-openai/pull/34
 * - https://github.com/WordPress/ai-provider-for-google/pull/30
 * - https://github.com/Fueled/ai-provider-for-ollama/pull/69
 *
 * Setup is a plain `composer install` (or `composer update`) — its `post-install-cmd`/
 * `post-update-cmd` hooks run the `prefix-namespaces` script, which downloads a pinned Strauss
 * `.phar` to `bin/strauss.phar` and runs it, reading the `extra.strauss` config block
 * in composer.json to produce `third-party/autoload.php` containing the Strauss-prefixed
 * PSR/HTTP classes alongside the usual `vendor/autoload.php`.
 *
 * This is not a pattern to reuse elsewhere in the plugin. Not mergeable to trunk as-is.
 *
 * @since x.x.x
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$wpai_embedding_poc_vendor_autoload      = WPAI_PLUGIN_DIR . 'vendor/autoload.php';
$wpai_embedding_poc_third_party_autoload = WPAI_PLUGIN_DIR . 'third-party/autoload.php';

// No `composer install` has been run yet.
if ( ! file_exists( $wpai_embedding_poc_vendor_autoload ) ) {
	return;
}

// `composer install`/`update` normally runs Strauss automatically via the `prefix-namespaces`
// script. If Composer scripts were skipped or the phar download failed, that won't have
// happened.
if ( ! file_exists( $wpai_embedding_poc_third_party_autoload ) ) {
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	error_log(
		'WordPress AI Experiments: embedding PoC dependencies are only half-installed. ' .
		'`vendor/autoload.php` exists but `third-party/autoload.php` does not — the ' .
		'`prefix-namespaces` Composer script (which runs Strauss) did not run. Skipping the ' .
		'dev-branch php-ai-client load to avoid a fatal; embeddings will not work until you run:' .
		"\ncomposer run prefix-namespaces"
	);

	return;
}

$wpai_embedding_poc_loaders = array(
	// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
	require $wpai_embedding_poc_vendor_autoload,
	// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
	require $wpai_embedding_poc_third_party_autoload,
);

// Ensure our loaders win over WP core's already-registered bundled autoloader.
add_action(
	'plugins_loaded',
	static function () use ( $wpai_embedding_poc_loaders ): void {
		foreach ( $wpai_embedding_poc_loaders as $wpai_embedding_poc_loader ) {
			spl_autoload_unregister( array( $wpai_embedding_poc_loader, 'loadClass' ) );
			$wpai_embedding_poc_loader->register( true );
		}
	},
	PHP_INT_MAX
);
