<?php
/**
 * Logging integration with the AI Client SDK.
 *
 * @package WordPress\AI\Logging
 */

declare( strict_types=1 );

namespace WordPress\AI\Logging;

use WordPress\AiClient\AiClient;

defined( 'ABSPATH' ) || exit;

/**
 * Initializes logging integration with the AI Client SDK.
 *
 * This class handles wrapping the SDK's HTTP transporter with a logging
 * decorator using the public setHttpTransporter() API.
 *
 * @since 1.0.0
 */
class Logging_Integration {

	/**
	 * Shared log manager instance.
	 *
	 * @var \WordPress\AI\Logging\AI_Request_Log_Manager|null
	 */
	private static ?AI_Request_Log_Manager $log_manager = null;

	/**
	 * Whether logging has been initialized.
	 *
	 * @var bool
	 */
	private static bool $initialized = false;

	/**
	 * Initialize logging integration with the AI Client SDK.
	 *
	 * This method wraps the SDK's HTTP transporter with a logging decorator.
	 * It should be called after AI_Client::init() to ensure the registry exists.
	 *
	 * @param \WordPress\AI\Logging\AI_Request_Log_Manager $log_manager The log manager instance.
	 */
	public static function init( AI_Request_Log_Manager $log_manager ): void {
		if ( self::$initialized ) {
			return;
		}

		self::$log_manager = $log_manager;

		// Check if the AiClient SDK is available.
		if ( ! class_exists( AiClient::class ) ) {
			return;
		}

		// Hook into the first request to wrap the transporter.
		// This ensures the registry is fully initialized before we wrap it.
		add_action( 'wp_loaded', array( self::class, 'wrap_transporter' ), 1 );

		// Also hook early in admin for immediate availability.
		add_action( 'admin_init', array( self::class, 'wrap_transporter' ), 1 );

		self::$initialized = true;
	}

	/**
	 * Wraps the SDK's HTTP transporter with logging.
	 *
	 * Uses the public setHttpTransporter() API to replace the transporter
	 * with a logging decorator.
	 */
	public static function wrap_transporter(): void {
		static $wrapped = false;

		if ( $wrapped || ! self::$log_manager ) {
			return;
		}

		try {
			$registry = AiClient::defaultRegistry();

			// Get the current transporter.
			$current_transporter = $registry->getHttpTransporter();

			// Don't wrap if already wrapped.
			if ( $current_transporter instanceof Logging_Http_Transporter ) {
				$wrapped = true;
				return;
			}

			// Create a logging wrapper around the existing transporter.
			$logging_transporter = new Logging_Http_Transporter(
				$current_transporter,
				self::$log_manager
			);

			// Replace the transporter with the logging version.
			$registry->setHttpTransporter( $logging_transporter );

			$wrapped = true;
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Silently fail - logging is optional and shouldn't break the site.
		}
	}
}
