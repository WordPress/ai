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
 * The repository ships `ensure_text_generation_supported()` and
 * `ensure_image_generation_supported()` on `Abstract_Ability`, but nothing that
 * asks for function declarations, so this gate is net new (KTD4). It is answered
 * from model metadata rather than by making a request, so it is cheap enough to
 * run before every turn and on every render of the workspace screen.
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

		if ( class_exists( AiClient::class ) ) {
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
					/*
					 * A provider that cannot be reached or cannot describe its models is
					 * treated as offering no function calling, so an unreachable connector
					 * degrades the screen rather than breaking it.
					 *
					 * An Error is re-thrown rather than swallowed. Catching everything here
					 * once turned a programming mistake in this method into a permanent,
					 * silent "no compatible model available" that no test could see: the
					 * screen degraded correctly for the wrong reason, so nothing looked
					 * broken. A bug in this file should surface; an unreachable provider
					 * should not.
					 */
					if ( $e instanceof Error ) {
						throw $e;
					}

					continue;
				}
			}
		}

		/**
		 * Filters whether a function-calling-capable model is available.
		 *
		 * Allows third-party plugins to declare function-calling support for
		 * connectors that do not expose model metadata, without triggering a
		 * live API request.
		 *
		 * @since x.x.x
		 *
		 * @param bool  $has_support Whether function calling is supported.
		 * @param array $connectors  The registered connectors.
		 */
		return (bool) apply_filters( 'wpai_has_function_calling_support', $has_support, $connectors );
	}
}
