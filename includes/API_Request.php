<?php
/**
 * API Request class.
 *
 * @package WordPress\AI
 */

namespace WordPress\AI;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WP_Error;

/**
 * Handles API requests to various AI services.
 *
 * @since 0.1.0
 */
class API_Request {

	/**
	 * The desired AI provider.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	protected $provider = null;

	/**
	 * The desired AI model.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	protected $model = null;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param string $provider The desired AI provider.
	 * @param string $model The desired AI model.
	 */
	public function __construct( string $provider = '', string $model = '' ) {
		$this->provider = $provider ?? null;
		$this->model    = $model ?? null;
	}

	/**
	 * Make a text generation request using the AI SDK Client.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $prompt The prompt to send.
	 * @param string|null $system_instruction The system instruction to send.
	 * @param array $options The options to send.
	 * @return array|\WP_Error The result of the request.
	 */
	public function generate_text( $prompt = null, $system_instruction = null, array $options = [] ) {
		if ( ! $this->is_client_available() ) {
			return new WP_Error( 'ai_client_not_available', __( 'AI Client is not available', 'ai' ) );
		}

		try {
			$model_config   = $this->process_model_config( $options );
			$prompt_builder = AiClient::prompt( $prompt );
			$prompt_builder = $prompt_builder->usingModelConfig( $model_config );

			if ( ! empty( $system_instruction ) ) {
				$prompt_builder = $prompt_builder->usingSystemInstruction( $system_instruction );
			}

			if ( ! empty( $this->provider ) ) {
				$prompt_builder = $prompt_builder->usingProvider( $this->provider );

				// Set the model.
				if ( ! empty( $this->model ) ) {
					$registry            = AiClient::defaultRegistry();
					$provider_class_name = $registry->getProviderClassName( $this->provider );
					$prompt_builder      = $prompt_builder->usingModel( $provider_class_name::model( $this->model ) );
				}
			}

			return $this->get_result( $prompt_builder->generateTexts() );
		} catch ( \Exception $e ) {
			return new WP_Error( 'ai_client_error', $e->getMessage() );
		}
	}

	/**
	 * Process the response from the AI SDK Client.
	 *
	 * @since 0.1.0
	 *
	 * @param array $response The response from the AI SDK Client.
	 * @return array|\WP_Error
	 */
	protected function get_result( array $response ) {
		if ( empty( $response ) ) {
			return new WP_Error( 'no_choices', __( 'No choices were returned from the AI provider', 'ai' ) );
		}

		$results = [];
		foreach ( $response as $choice ) {
			$results[] = $this->sanitize_choice( $choice );
		}

		return $results;
	}

	/**
	 * Sanitize a choice from AI response.
	 *
	 * @since 0.1.0
	 *
	 * @param string $choice The choice to sanitize.
	 * @return string
	 */
	protected function sanitize_choice( string $choice ): string {
		return sanitize_text_field( trim( $choice, ' "\'' ) );
	}

	/**
	 * Process the model config.
	 *
	 * @since 0.1.0
	 *
	 * @param array $options The options to add to the model config.
	 * @return ModelConfig
	 */
	protected function process_model_config( array $options ): ModelConfig {
		$schema       = ModelConfig::getJsonSchema()['properties'];
		$model_config = [];

		foreach ( $options as $key => $value ) {
			if ( ! isset( $schema[ $key ] ) ) {
				continue;
			}

			$property_schema = $schema[ $key ];
			$type            = $property_schema['type'] ?? null;

			$processed_value = (string) $value;

			if ( 'array' === $type || 'object' === $type ) {
				$processed_value = (array) $value;
			} elseif ( 'integer' === $type ) {
				$processed_value = (int) $value;
			} elseif ( 'number' === $type ) {
				$processed_value = (float) $value;
			} elseif ( 'boolean' === $type ) {
				$processed_value = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );

				if ( null === $processed_value ) {
					continue;
				}
			}

			$model_config[ $key ] = $processed_value;
		}

		return ModelConfig::fromArray( $model_config );
	}

	/**
	 * Check if the AI SDK Client is available.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True if the client is available, false otherwise.
	 */
	protected function is_client_available(): bool {
		return class_exists( AiClient::class );
	}
}
