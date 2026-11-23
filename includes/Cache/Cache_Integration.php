<?php
/**
 * Wires the response cache into the AI Client SDK transport.
 *
 * @package WordPress\AI\Cache
 */

declare( strict_types=1 );

namespace WordPress\AI\Cache;

use Throwable;
use WordPress\AiClient\AiClient;

defined( 'ABSPATH' ) || exit;

/**
 * Installs the caching HTTP transporter decorator.
 *
 * This mirrors {@see \WordPress\AI\Logging\Logging_Integration}: the SDK's
 * public `setHttpTransporter()` API is used so no provider needs to change.
 * The decorator is installed even when caching is off, so a site can enable
 * it later with a filter without reinstalling anything.
 *
 * @since 1.6.0
 */
class Cache_Integration {

	/**
	 * Cache store shared by the decorator.
	 *
	 * @since 1.6.0
	 *
	 * @var \WordPress\AI\Cache\Cache_Store|null
	 */
	private static ?Cache_Store $store = null;

	/**
	 * Whether the integration has been initialized.
	 *
	 * @since 1.6.0
	 *
	 * @var bool
	 */
	private static bool $initialized = false;

	/**
	 * Initializes the integration.
	 *
	 * @since 1.6.0
	 */
	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}

		if ( ! class_exists( AiClient::class ) ) {
			return;
		}

		self::$store = new Cache_Store();

		// Run after the logging decorator (priority 1) so cache hits never
		// reach the logging transporter and are not reported as provider calls.
		add_action( 'wp_loaded', array( self::class, 'wrap_transporter' ), 2 );
		add_action( 'admin_init', array( self::class, 'wrap_transporter' ), 2 );

		self::$initialized = true;
	}

	/**
	 * Returns the shared cache store.
	 *
	 * @since 1.6.0
	 *
	 * @return \WordPress\AI\Cache\Cache_Store The store.
	 */
	public static function get_store(): Cache_Store {
		if ( null === self::$store ) {
			self::$store = new Cache_Store();
		}

		return self::$store;
	}

	/**
	 * Wraps the SDK's current transporter with the caching decorator.
	 *
	 * @since 1.6.0
	 */
	public static function wrap_transporter(): void {
		static $wrapped = false;

		if ( $wrapped ) {
			return;
		}

		try {
			$registry = AiClient::defaultRegistry();
			$current  = $registry->getHttpTransporter();

			if ( $current instanceof Http_Cache_Transporter ) {
				$wrapped = true;
				return;
			}

			$registry->setHttpTransporter( new Http_Cache_Transporter( $current, self::get_store() ) );

			$wrapped = true;
		} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Caching is an optimization and must never break generation.
		}
	}
}
