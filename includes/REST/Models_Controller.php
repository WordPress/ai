<?php
/**
 * REST controller for provider/model discovery.
 *
 * @package WordPress\AI\REST
 */

declare( strict_types=1 );

namespace WordPress\AI\REST;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Models\DTO\ModelRequirements;
use WordPress\AiClient\Providers\Models\DTO\RequiredOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;

use function WordPress\AI\get_ai_connectors;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Handles the GET /ai/v1/providers REST endpoint.
 *
 * Returns providers and models filtered by capability.
 *
 * @since 0.9.0
 */
final class Models_Controller {

	/**
	 * The REST API namespace.
	 *
	 * @since 0.9.0
	 *
	 * @var string
	 */
	private const API_NAMESPACE = 'ai/v1';

	/**
	 * The REST API route.
	 *
	 * @since 0.9.0
	 *
	 * @var string
	 */
	private const ROUTE = '/providers';

	/**
	 * Supported capability values.
	 *
	 * @since 0.9.0
	 *
	 * @var list<string>
	 */
	private const VALID_CAPABILITIES = array( 'text_generation', 'image_generation', 'vision' ); // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is a single array constant.

	/**
	 * Initializes the REST routes.
	 *
	 * @since 0.9.0
	 */
	public function init(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the REST routes.
	 *
	 * @since 0.9.0
	 */
	public function register_routes(): void {
		register_rest_route(
			self::API_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_providers' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'capability' => array(
						'type'              => 'string',
						'required'          => true,
						'enum'              => self::VALID_CAPABILITIES,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * Checks whether the current user can access this endpoint.
	 *
	 * @since 0.9.0
	 *
	 * @return bool True if the user has permission.
	 */
	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Returns providers and their models for the requested capability.
	 *
	 * @since 0.9.0
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response|\WP_Error The response.
	 */
	public function get_providers( \WP_REST_Request $request ) {
		if ( ! class_exists( AiClient::class ) ) {
			return new \WP_Error(
				'ai_client_unavailable',
				__( 'AI client is not available.', 'ai' ),
				array( 'status' => 500 )
			);
		}

		$capability = (string) $request->get_param( 'capability' );

		try {
			$requirements = $this->build_requirements( $capability );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error(
				'invalid_capability',
				$e->getMessage(),
				array( 'status' => 400 )
			);
		}

		try {
			$providers = $this->fetch_providers( $requirements );
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'model_fetch_failed',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}

		return new \WP_REST_Response( $providers, 200 );
	}

	/**
	 * Builds model requirements for a capability string.
	 *
	 * @since 0.9.0
	 *
	 * @param string $capability The capability slug.
	 * @return \WordPress\AiClient\Providers\Models\DTO\ModelRequirements The requirements.
	 * @throws \InvalidArgumentException If the capability is unrecognized.
	 */
	private function build_requirements( string $capability ): ModelRequirements {
		switch ( $capability ) {
			case 'text_generation':
				return new ModelRequirements(
					array( CapabilityEnum::textGeneration() ),
					array()
				);

			case 'image_generation':
				return new ModelRequirements(
					array( CapabilityEnum::imageGeneration() ),
					array()
				);

			case 'vision':
				return new ModelRequirements(
					array( CapabilityEnum::textGeneration() ),
					array(
						new RequiredOption(
							OptionEnum::inputModalities(),
							array( ModalityEnum::text(), ModalityEnum::image() )
						),
					)
				);

			default:
				throw new \InvalidArgumentException(
					sprintf(
						/* translators: %s: Capability slug. */
						esc_html__( 'Unsupported capability: %s', 'ai' ),
						esc_html( $capability )
					)
				);
		}
	}

	/**
	 * Fetches providers and their models matching the given requirements.
	 *
	 * Only considers active connectors registered in the plugin.
	 *
	 * @since 0.9.0
	 *
	 * @param \WordPress\AiClient\Providers\Models\DTO\ModelRequirements $requirements The model requirements.
	 * @return list<array{id: string, name: string, models: list<array{id: string, name: string}>}> Provider data.
	 */
	private function fetch_providers( ModelRequirements $requirements ): array {
		$registry          = AiClient::defaultRegistry();
		$active_connectors = get_ai_connectors();
		$providers         = array();

		foreach ( array_keys( $active_connectors ) as $connector_id ) {
			try {
				$models = $registry->findProviderModelsMetadataForSupport( $connector_id, $requirements );

				if ( empty( $models ) ) {
					continue;
				}

				$provider_class = $registry->getProviderClassName( $connector_id );

				/** @var \WordPress\AiClient\Providers\Contracts\ProviderInterface $provider_class */
				$provider_name = $provider_class::metadata()->getName();

				$model_items = array();
				foreach ( $models as $model ) {
					$model_items[] = array(
						'id'   => $model->getId(),
						'name' => $model->getName(),
					);
				}

				$providers[] = array(
					'id'     => $connector_id,
					'name'   => $provider_name,
					'models' => $model_items,
				);
			} catch ( \Throwable $e ) {
				// Skip providers that throw during model discovery.
				continue;
			}
		}

		return $providers;
	}
}
