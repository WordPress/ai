<?php

/**
 * PSR-4 autoloader for the AI Experiments plugin.
 *
 * @since 0.5.0
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix   = 'WordPress\\AI\\';
		$base_dir = __DIR__ . '/';

		$len = strlen( $prefix );

		if ( strncmp( $class_name, $prefix, $len ) !== 0 ) {
			return;
		}

		$relative_class = substr( $class_name, $len );
		$file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

		if ( ! file_exists( $file ) ) {
			return;
		}

		require $file; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
	}
);
