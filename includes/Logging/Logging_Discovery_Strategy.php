<?php
/**
 * Discovery strategy that wraps HTTP clients with logging.
 *
 * @package WordPress\AI\Logging
 */

declare( strict_types=1 );

namespace WordPress\AI\Logging;

use Http\Discovery\Psr18ClientDiscovery;
use Http\Discovery\Strategy\DiscoveryStrategy;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use WordPress\AI_Client\HTTP\WordPress_HTTP_Client;

/**
 * Discovery strategy that provides a logging-enabled HTTP client.
 *
 * @since 0.1.0
 */
class Logging_Discovery_Strategy implements DiscoveryStrategy {

	/**
	 * Shared log manager instance.
	 *
	 * @var AI_Request_Log_Manager|null
	 */
	private static ?AI_Request_Log_Manager $log_manager = null;

	/**
	 * Initialize and register the discovery strategy.
	 *
	 * @param AI_Request_Log_Manager $log_manager The log manager instance.
	 */
	public static function init( AI_Request_Log_Manager $log_manager ): void {
		self::$log_manager = $log_manager;

		// Check if discovery is available.
		if ( ! class_exists( '\Http\Discovery\Psr18ClientDiscovery' ) ) {
			return;
		}

		// Prepend our strategy so it takes priority.
		Psr18ClientDiscovery::prependStrategy( self::class );
	}

	/**
	 * Get candidates for discovery.
	 *
	 * @param string $type The type of discovery.
	 * @return array<array<string, mixed>>
	 */
	public static function getCandidates( $type ) {
		// Only handle PSR-18 HTTP Client discovery.
		if ( ClientInterface::class !== $type ) {
			return array();
		}

		// If logging is disabled or manager not set, return empty to fall through.
		if ( ! self::$log_manager || ! self::$log_manager->is_logging_enabled() ) {
			return array();
		}

		return array(
			array(
				'class' => static function () {
					return self::createLoggingClient();
				},
			),
		);
	}

	/**
	 * Create an instance of the logging HTTP client.
	 *
	 * @return Logging_HTTP_Client
	 */
	private static function createLoggingClient(): Logging_HTTP_Client {
		$psr17_factory = new Psr17Factory();

		// Create the underlying WordPress HTTP client.
		$wordpress_client = new WordPress_HTTP_Client(
			$psr17_factory,
			$psr17_factory
		);

		// Wrap it with logging.
		return new Logging_HTTP_Client( $wordpress_client, self::$log_manager );
	}
}
