<?php
/**
 * Function-calling capability gate for the AI Workspace.
 *
 * @package WordPress\AI\Experiments\AI_Workspace
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\AI_Workspace;

use Error;
use Throwable;
use WordPress\AiClient\AiClient;

use function WordPress\AI\get_ai_connectors;
use function WordPress\AI\has_connector_authentication;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Reports whether any configured connector exposes a function-calling model.
 *
 * Reads each authenticated connector's model list. The SDK caches that list with wp_cache for a day,
 * so without a persistent object cache the first call in a request can hit each connector's models endpoint.
 *
 * @since x.x.x
 */
final class Function_Calling_Support {

	/**
	 * Reports whether a function-calling capable model is available.
	 *
	 * @since x.x.x
	 *
	 * @return bool True if at least one authenticated connector supports function declarations.
	 */
	public static function is_available(): bool {
		$connectors  = array();
		$has_support = false;

		if ( ! class_exists( AiClient::class ) ) {
			return self::filter( $has_support, $connectors );
		}

		$registry   = AiClient::defaultRegistry();
		$connectors = get_ai_connectors();

		foreach ( array_keys( $connectors ) as $connector_id ) {
			if ( ! has_connector_authentication( $connector_id ) ) {
				continue;
			}

			try {
				$provider_class = $registry->getProviderClassName( $connector_id );

				/** @var \WordPress\AiClient\Providers\Contracts\ProviderInterface $provider_class */
				$models = $provider_class::modelMetadataDirectory()->listModelMetadata();

				foreach ( $models as $model ) {
					foreach ( $model->getSupportedOptions() as $option ) {
						if ( $option->getName()->isFunctionDeclarations() ) {
							$has_support = true;
							break 3;
						}
					}
				}
			} catch ( Throwable $e ) {
				// An unreachable provider counts as no support, but an Error is a bug here and must surface.
				if ( $e instanceof Error ) {
					throw $e;
				}

				continue;
			}
		}

		return self::filter( $has_support, $connectors );
	}

	/**
	 * Applies the support filter.
	 *
	 * @since x.x.x
	 *
	 * @param bool                 $has_support Whether function calling is supported.
	 * @param array<string, mixed> $connectors  The registered connectors.
	 * @return bool Filtered support.
	 */
	private static function filter( bool $has_support, array $connectors ): bool {
		/**
		 * Filters whether a function-calling-capable model is available.
		 *
		 * Lets plugins declare support for connectors that do not expose model metadata.
		 *
		 * @since x.x.x
		 *
		 * @param bool  $has_support Whether function calling is supported.
		 * @param array $connectors  The registered connectors.
		 */
		return (bool) apply_filters( 'wpai_has_function_calling_support', $has_support, $connectors );
	}
}
