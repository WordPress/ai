<?php
/**
 * Conditional loader for the vendored PHP AI Client SDK overlay.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Vendor\AiClient;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the vendored SDK overlay only when the environment's own PHP AI Client
 * predates the changes we ship.
 *
 * @since x.x.x
 */
final class SDK_Overlay {

	/**
	 * Namespace prefix served by the overlay autoloader.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const NAMESPACE_PREFIX = 'WordPress\\AiClient\\';

	/**
	 * Sentinel class: present in the environment only if it already provides these changes.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const SENTINEL_CLASS = 'WordPress\\AiClient\\Builders\\EmbeddingBuilder';

	/**
	 * Base SDK class the environment must provide for the overlay to function.
	 *
	 * The vendored files extend base SDK classes (e.g. AbstractDataTransferObject) that we do not
	 * ship; without the base SDK present, loading them would fatal. This class is never vendored.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const BASE_SDK_CLASS = 'WordPress\\AiClient\\AiClient';

	/**
	 * Override-race classes we ship that the environment may already define, mapped to a
	 * method that exists only in our (newer) copy. Used to detect an unwinnable conflict.
	 *
	 * @since x.x.x
	 *
	 * @var array<string, string>
	 */
	private const OVERRIDE_GUARDS = array(
		'WordPress\\AiClient\\Providers\\Models\\DTO\\ModelRequirements' => 'fromEmbeddingData',
	);

	/**
	 * Registers the overlay autoloader when appropriate.
	 *
	 * Must run at plugin bootstrap, before any AI operation could reference an SDK class.
	 *
	 * @since x.x.x
	 *
	 * @return void
	 */
	public static function register(): void {
		// The overlay supplements an existing base SDK; it cannot (and need not) activate when the
		// environment provides no PHP AI Client SDK at all, since the vendored files extend base
		// SDK classes that must already be present. This also keeps the overlay inert under static
		// analysis / CLI tooling that boots the plugin without the SDK.
		if ( ! class_exists( self::BASE_SDK_CLASS ) ) {
			return;
		}

		// Probe the environment BEFORE registering our autoloader, so our own copy cannot
		// satisfy the sentinel and mask the environment's true capability.
		$environment_capable = class_exists( self::SENTINEL_CLASS );
		$conflict_loaded     = self::conflicting_class_loaded();

		switch ( self::decide( $environment_capable, $conflict_loaded ) ) {
			case 'activate':
				spl_autoload_register( array( self::class, 'autoload' ), true, true );
				break;
			case 'skip':
				self::log_conflict();
				break;
			case 'defer':
			default:
				break;
		}
	}

	/**
	 * Decides what the loader should do given the probed environment state.
	 *
	 * @since x.x.x
	 *
	 * @param bool $environment_capable Whether the environment already provides these changes.
	 * @param bool $conflict_loaded     Whether an override-race class is already loaded in an
	 *                                   older form that cannot be replaced.
	 * @return string One of 'defer', 'skip', or 'activate'.
	 */
	public static function decide( bool $environment_capable, bool $conflict_loaded ): string {
		if ( $environment_capable ) {
			return 'defer';
		}

		if ( $conflict_loaded ) {
			return 'skip';
		}

		return 'activate';
	}

	/**
	 * Autoloads a class from the overlay, if we ship it.
	 *
	 * @since x.x.x
	 *
	 * @param string $class_name Fully-qualified class name.
	 * @return void
	 */
	public static function autoload( string $class_name ): void {
		$file = self::class_to_file( $class_name );

		if ( null !== $file ) {
			require $file; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
		}
	}

	/**
	 * Maps a class name to the overlay file that defines it, if any.
	 *
	 * @since x.x.x
	 *
	 * @param string $class_name Fully-qualified class name.
	 * @return string|null Absolute file path, or null if not served by the overlay.
	 */
	public static function class_to_file( string $class_name ): ?string {
		$len = strlen( self::NAMESPACE_PREFIX );

		if ( strncmp( $class_name, self::NAMESPACE_PREFIX, $len ) !== 0 ) {
			return null;
		}

		$relative = substr( $class_name, $len );
		$file     = self::src_dir() . str_replace( '\\', '/', $relative ) . '.php';

		return file_exists( $file ) ? $file : null;
	}

	/**
	 * Returns the absolute path to the overlay source directory (trailing slash).
	 *
	 * @since x.x.x
	 *
	 * @return string
	 */
	public static function src_dir(): string {
		return __DIR__ . '/src/';
	}

	/**
	 * Determines whether an override-race class is already loaded in a form we cannot replace.
	 *
	 * Uses autoload=false so the probe does not itself force-load the environment's copy.
	 *
	 * @since x.x.x
	 *
	 * @return bool
	 */
	private static function conflicting_class_loaded(): bool {
		foreach ( self::OVERRIDE_GUARDS as $class_name => $method_name ) {
			if ( class_exists( $class_name, false ) && ! method_exists( $class_name, $method_name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Logs that the overlay could not activate because of an already-loaded older SDK class.
	 *
	 * @since x.x.x
	 *
	 * @return void
	 */
	private static function log_conflict(): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				'AI plugin: PHP AI Client embedding overlay could not activate because an older '
				. 'SDK class was already loaded. Embedding features are unavailable this request.'
			);
		}
	}
}
