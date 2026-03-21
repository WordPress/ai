<?php
/**
 * PSR-4 autoloader for the AI plugin.
 *
 * Handles autoloading for the plugin's own classes and the bundled
 * wp-ai-client package. Does NOT autoload WordPress\AiClient (php-ai-client)
 * since WordPress core provides that.
 *
 * @since 0.5.0
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

spl_autoload_register(
	static function ( string $class_name ): void {
		$namespace_map = array(
			'WordPress\\AI\\'        => __DIR__ . '/',
			'WordPress\\AI_Client\\' => dirname( __DIR__ ) . '/vendor/wordpress/wp-ai-client/includes/',
		);

		foreach ( $namespace_map as $prefix => $base_dir ) {
			$len = strlen( $prefix );

			if ( strncmp( $class_name, $prefix, $len ) !== 0 ) {
				continue;
			}

			$relative_class = substr( $class_name, $len );
			$file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

			if ( file_exists( $file ) ) {
				require $file; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
				return;
			}
		}
	}
);
