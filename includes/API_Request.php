<?php
/**
 * API Request class.
 *
 * @package WordPress\AI
 */

namespace WordPress\AI;

use Throwable;
use WP_Error;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;

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
	 * The preferred models to use.
	 *
	 * @since 0.1.0
	 *
	 * @var array<int, array<string>>
	 */
	protected $model_preferences = array(
		array(
			'anthropic',
			'claude-haiku-4-5',
		),
		array(
			'google',
			'gemini-2.5-flash',
		),
		array(
			'openai',
			'gpt-4o-mini',
		),
		array(
			'openai',
			'gpt-4.1',
		),
	);

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param string $provider The desired AI provider.
	 * @param string $model The desired AI model.
	 */
	public function __construct( ?string $provider = null, ?string $model = null ) {
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
	 * @param array<string, mixed> $options The options to send.
	 * @return array<string>|\WP_Error The result of the request.
	 */
	public function generate_text( $prompt = null, $system_instruction = null, array $options = array() ) {
		if ( ! $this->is_client_available() ) {
			return new WP_Error( 'ai_client_not_available', __( 'AI Client is not available', 'ai' ) );
		}

		$prompt_builder = $this->prompt_builder( $prompt, $system_instruction, $options );

		if ( is_wp_error( $prompt_builder ) ) {
			return $prompt_builder;
		}

		try {
			return $this->get_result( $prompt_builder->generateTexts() );
		} catch ( Throwable $t ) {
			return new WP_Error( 'ai_client_error', $t->getMessage() );
		}
	}

	/**
	 * Build the prompt builder for the request.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $prompt The prompt to send.
	 * @param string|null $system_instruction The system instruction to send.
	 * @param array<string, mixed> $options The options to send.
	 * @return \WordPress\AiClient\Builders\PromptBuilder|\WP_Error The prompt builder or a WP_Error.
	 */
	protected function prompt_builder( $prompt = null, $system_instruction = null, array $options = array() ) {
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

			// Set our preferred models if no model is specified.
			if ( empty( $this->model ) ) {
				$prompt_builder = $prompt_builder->usingModelPreference( ...$this->model_preferences );
			}

			return $prompt_builder;
		} catch ( Throwable $t ) {
			return new WP_Error( 'ai_client_error', $t->getMessage() );
		}
	}

	/**
	 * Process the response from the AI SDK Client.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string> $response The response from the AI SDK Client.
	 * @return array<string>|\WP_Error
	 */
	protected function get_result( array $response ) {
		if ( empty( $response ) ) {
			return new WP_Error( 'no_choices', __( 'No choices were returned from the AI provider', 'ai' ) );
		}

		$results = array();
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
	 * @param array<string, mixed> $options The options to add to the model config.
	 * @return \WordPress\AiClient\Providers\Models\DTO\ModelConfig
	 */
	protected function process_model_config( array $options ): ModelConfig {
		$schema       = ModelConfig::getJsonSchema()['properties'];
		$model_config = array();

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

		// @phpstan-ignore-next-line - fromArray() validates the array shape at runtime.
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
