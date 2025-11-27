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

use WordPress\AI_Client\AI_Client;
use WordPress\AI_Client\Builders\Prompt_Builder_With_WP_Error;

use function WordPress\AI\get_preferred_models;

/**
 * AI Service class.
 *
 * Manages AI provider configuration and provides a consistent interface
 * for experimental features to communicate with AI providers.
 *
 * @since 0.1.0
 */
class AI_Service {

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
	 * Creates a prompt builder with default configuration applied.
	 *
	 * This is the primary method for interacting with AI providers. It returns
	 * a configured prompt builder that consumers can use with the full SDK API.
	 *
	 * Example usage:
	 * ```php
	 * $service = AI_Service::get_instance();
	 *
	 * // Simple usage
	 * $text = $service->create_prompt( 'Summarize this text' )->generate_text();
	 *
	 * // With options
	 * $text = $service->create_prompt( 'Translate to French', array(
	 *     'system_instruction' => 'You are a translator.',
	 *     'temperature'        => 0.3,
	 *     'max_tokens'         => 500,
	 * ) )->generate_text();
	 *
	 * // Generate multiple candidates
	 * $titles = $service->create_prompt( 'Generate titles', array(
	 *     'candidate_count' => 5,
	 *     'temperature'     => 0.8,
	 * ) )->generate_texts();
	 * ```
	 *
	 * @since 0.1.0
	 *
	 * @param string|null          $prompt  Optional. Initial prompt content.
	 * @param array<string, mixed> $options Optional. Configuration options. {
	 *     @type string        $system_instruction System instruction for the AI.
	 *     @type float         $temperature        Temperature for generation (0.0-2.0).
	 *     @type int           $max_tokens         Maximum tokens to generate.
	 *     @type float         $top_p              Top-p (nucleus) sampling value.
	 *     @type int           $top_k              Top-k sampling value.
	 *     @type int           $candidate_count    Number of candidates to generate.
	 *     @type float         $presence_penalty   Presence penalty for generation.
	 *     @type float         $frequency_penalty  Frequency penalty for generation.
	 *     @type list<string>  $stop_sequences     Stop sequences for generation.
	 *     @type int|null      $top_logprobs       Top log probabilities to return.
	 * }
	 * @return \WordPress\AI_Client\Builders\Prompt_Builder_With_WP_Error The prompt builder instance.
	 */
	public function create_prompt( ?string $prompt = null, array $options = array() ): Prompt_Builder_With_WP_Error {
		$builder = AI_Client::prompt_with_wp_error( $prompt );

		// Apply default model preferences.
		$models = get_preferred_models();
		if ( ! empty( $models ) ) {
			$builder = $builder->using_model_preference( ...$models );
		}

		// Apply options if provided (no forced defaults).
		if ( isset( $options['system_instruction'] ) && is_string( $options['system_instruction'] ) ) {
			$builder = $builder->using_system_instruction( $options['system_instruction'] );
		}

		if ( isset( $options['temperature'] ) && is_numeric( $options['temperature'] ) ) {
			$builder = $builder->using_temperature( (float) $options['temperature'] );
		}

		if ( isset( $options['max_tokens'] ) && is_int( $options['max_tokens'] ) ) {
			$builder = $builder->using_max_tokens( $options['max_tokens'] );
		}

		if ( isset( $options['top_p'] ) && is_numeric( $options['top_p'] ) ) {
			$builder = $builder->using_top_p( (float) $options['top_p'] );
		}

		if ( isset( $options['top_k'] ) && is_int( $options['top_k'] ) ) {
			$builder = $builder->using_top_k( $options['top_k'] );
		}

		if ( isset( $options['candidate_count'] ) && is_int( $options['candidate_count'] ) ) {
			$builder = $builder->using_candidate_count( $options['candidate_count'] );
		}

		if ( isset( $options['presence_penalty'] ) && is_numeric( $options['presence_penalty'] ) ) {
			$builder = $builder->using_presence_penalty( (float) $options['presence_penalty'] );
		}

		if ( isset( $options['frequency_penalty'] ) && is_numeric( $options['frequency_penalty'] ) ) {
			$builder = $builder->using_frequency_penalty( (float) $options['frequency_penalty'] );
		}

		if ( isset( $options['stop_sequences'] ) && is_array( $options['stop_sequences'] ) ) {
			$builder = $builder->using_stop_sequences( ...$options['stop_sequences'] );
		}

		if ( array_key_exists( 'top_logprobs', $options ) ) {
			$builder = $builder->using_top_logprobs( $options['top_logprobs'] );
		}

		return $builder;
	}
}
