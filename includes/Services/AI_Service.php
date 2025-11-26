<?php
/**
 * AI Service implementation.
 *
 * Provides a centralized service layer for AI operations.
 *
 * @package WordPress\AI\Services
 */

declare( strict_types=1 );

namespace WordPress\AI\Services;

use WP_Error;
use WordPress\AI\Services\Contracts\AI_Service_Interface;
use WordPress\AI_Client\AI_Client;
use WordPress\AI_Client\Builders\Prompt_Builder_With_WP_Error;

/**
 * AI Service class.
 *
 * Manages AI provider configuration and provides a consistent interface
 * for experimental features to communicate with AI providers.
 *
 * @since 0.1.0
 */
class AI_Service implements AI_Service_Interface {

	/**
	 * Default temperature for text generation.
	 *
	 * @since 0.1.0
	 *
	 * @var float
	 */
	public const DEFAULT_TEMPERATURE = 0.7;

	/**
	 * Singleton instance.
	 *
	 * @since 0.1.0
	 *
	 * @var \WordPress\AI\Services\AI_Service|null
	 */
	private static ?self $instance = null;

	/**
	 * Whether the service has been initialized.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $initialized = false;

	/**
	 * Cached preferred models.
	 *
	 * @since 0.1.0
	 *
	 * @var list<array{string, string}>|null
	 */
	private ?array $cached_models = null;

	/**
	 * Gets the singleton instance.
	 *
	 * @since 0.1.0
	 *
	 * @return \WordPress\AI\Services\AI_Service The singleton instance.
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor to enforce singleton pattern.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {}

	/**
	 * Initializes the AI service.
	 *
	 * This method should be called after AI_Client::init() on the WordPress 'init' hook.
	 *
	 * @since 0.1.0
	 */
	public function init(): void {
		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;

		/**
		 * Fires when the AI service is initialized.
		 *
		 * @since 0.1.0
		 *
		 * @param \WordPress\AI\Services\AI_Service $service The AI service instance.
		 */
		do_action( 'ai_service_initialized', $this );
	}

	/**
	 * Checks if an AI provider is available and configured.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True if a provider is available, false otherwise.
	 */
	public function is_available(): bool {
		/**
		 * Filters whether the AI service is available.
		 *
		 * Allows developers or hosts to override availability detection,
		 * for example when using pre-configured providers.
		 *
		 * @since 0.1.0
		 *
		 * @param bool|null $available Null to use default detection, or boolean to override.
		 */
		$available = apply_filters( 'ai_service_available', null );

		if ( null !== $available ) {
			return (bool) $available;
		}

		// Check if any provider credentials are configured.
		$credentials = get_option( 'wp_ai_client_provider_credentials', array() );

		return ! empty( $credentials );
	}

	/**
	 * Generates text from a prompt.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $prompt  The prompt to generate text from.
	 * @param array<string, mixed> $options Optional. Generation options. {
	 *     @type string $system_instruction System instruction for the AI.
	 *     @type float  $temperature        Temperature for generation (0.0-2.0).
	 *     @type int    $max_tokens         Maximum tokens to generate.
	 * }
	 * @return string|\WP_Error The generated text or WP_Error on failure.
	 */
	public function generate_text( string $prompt, array $options = array() ) {
		if ( ! $this->is_available() ) {
			return new WP_Error(
				'ai_service_unavailable',
				__( 'No AI provider is configured. Please configure an AI provider in the settings.', 'ai' )
			);
		}

		$builder = $this->create_prompt( $prompt );
		$builder = $this->apply_options( $builder, $options );

		return $builder->generate_text();
	}

	/**
	 * Generates multiple text candidates from a prompt.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $prompt          The prompt to generate text from.
	 * @param int                  $candidate_count The number of candidates to generate.
	 * @param array<string, mixed> $options         Optional. Generation options.
	 * @return list<string>|\WP_Error The generated texts or WP_Error on failure.
	 */
	public function generate_texts( string $prompt, int $candidate_count, array $options = array() ) {
		if ( ! $this->is_available() ) {
			return new WP_Error(
				'ai_service_unavailable',
				__( 'No AI provider is configured. Please configure an AI provider in the settings.', 'ai' )
			);
		}

		$builder = $this->create_prompt( $prompt );
		$builder = $this->apply_options( $builder, $options );

		return $builder
			->using_candidate_count( $candidate_count )
			->generate_texts();
	}

	/**
	 * Creates a prompt builder for advanced use cases.
	 *
	 * Use this method when you need fine-grained control over the prompt configuration
	 * that isn't available through the simpler generate_text/generate_texts methods.
	 *
	 * Example:
	 * ```php
	 * $service = AI_Service::get_instance();
	 * $result = $service->create_prompt( 'Summarize this text' )
	 *     ->using_system_instruction( 'You are a helpful assistant.' )
	 *     ->using_temperature( 0.5 )
	 *     ->using_max_tokens( 500 )
	 *     ->generate_text();
	 * ```
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $prompt Optional initial prompt content.
	 * @return \WordPress\AI_Client\Builders\Prompt_Builder_With_WP_Error The prompt builder instance.
	 */
	public function create_prompt( ?string $prompt = null ): Prompt_Builder_With_WP_Error {
		$builder = AI_Client::prompt_with_wp_error( $prompt );

		// Apply default model preferences.
		$models = $this->get_preferred_models();
		if ( ! empty( $models ) ) {
			$builder = $builder->using_model_preference( ...$models );
		}

		return $builder;
	}

	/**
	 * Gets the preferred AI models for generation.
	 *
	 * Models are returned in order of preference. The AI client will try
	 * each model in order until one succeeds.
	 *
	 * @since 0.1.0
	 *
	 * @return list<array{string, string}> Array of [provider, model] tuples.
	 */
	public function get_preferred_models(): array {
		// Return cached models if available.
		if ( null !== $this->cached_models ) {
			return $this->cached_models;
		}

		$default_models = array(
			array( 'anthropic', 'claude-haiku-4-5' ),
			array( 'google', 'gemini-2.5-flash' ),
			array( 'openai', 'gpt-4o-mini' ),
			array( 'openai', 'gpt-4.1' ),
		);

		/**
		 * Filters the preferred AI models.
		 *
		 * @since 0.1.0
		 *
		 * @param list<array{string, string}> $models The preferred models as [provider, model] tuples.
		 */
		$this->cached_models = (array) apply_filters( 'ai_preferred_models', $default_models );

		return $this->cached_models;
	}

	/**
	 * Clears the cached preferred models.
	 *
	 * Call this method if you need to refresh the model preferences,
	 * for example after changing settings.
	 *
	 * @since 0.1.0
	 */
	public function clear_model_cache(): void {
		$this->cached_models = null;
	}

	/**
	 * Applies options to a prompt builder.
	 *
	 * @since 0.1.0
	 *
	 * @param \WordPress\AI_Client\Builders\Prompt_Builder_With_WP_Error $builder The prompt builder.
	 * @param array<string, mixed>         $options The options to apply.
	 * @return \WordPress\AI_Client\Builders\Prompt_Builder_With_WP_Error The modified prompt builder.
	 */
	private function apply_options( Prompt_Builder_With_WP_Error $builder, array $options ): Prompt_Builder_With_WP_Error {
		// Apply system instruction if provided.
		if ( isset( $options['system_instruction'] ) && is_string( $options['system_instruction'] ) ) {
			$builder = $builder->using_system_instruction( $options['system_instruction'] );
		}

		// Apply temperature (use default if not provided).
		$temperature = $options['temperature'] ?? self::DEFAULT_TEMPERATURE;
		if ( is_numeric( $temperature ) ) {
			$builder = $builder->using_temperature( (float) $temperature );
		}

		// Apply max tokens if provided.
		if ( isset( $options['max_tokens'] ) && is_int( $options['max_tokens'] ) ) {
			$builder = $builder->using_max_tokens( $options['max_tokens'] );
		}

		/**
		 * Filters the prompt builder after options are applied.
		 *
		 * Allows developers to modify the prompt builder before generation.
		 *
		 * @since 0.1.0
		 *
		 * @param \WordPress\AI_Client\Builders\Prompt_Builder_With_WP_Error $builder The prompt builder.
		 * @param array<string, mixed>         $options The options that were applied.
		 */
		return apply_filters( 'ai_service_prompt_builder', $builder, $options );
	}
}
