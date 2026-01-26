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
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;

use function WordPress\AI\get_preferred_models_for_text_generation;

/**
 * AI Service class.
 *
 * Manages AI provider configuration and provides a consistent interface
 * for experimental features to communicate with AI providers.
 *
 * @since 0.2.1
 */
class AI_Service {

	/**
	 * Singleton instance.
	 *
	 * @since 0.2.1
	 *
	 * @var \WordPress\AI\Services\AI_Service|null
	 */
	private static ?self $instance = null;

	/**
	 * Option key mapping from WordPress snake_case to SDK camelCase.
	 *
	 * @since 0.2.1
	 *
	 * @var array<string, string>
	 */
	private static array $option_key_map = array(
		'system_instruction' => 'systemInstruction',
		'candidate_count'    => 'candidateCount',
		'max_tokens'         => 'maxTokens',
		'temperature'        => 'temperature',
		'top_p'              => 'topP',
		'top_k'              => 'topK',
		'stop_sequences'     => 'stopSequences',
		'presence_penalty'   => 'presencePenalty',
		'frequency_penalty'  => 'frequencyPenalty',
		'logprobs'           => 'logprobs',
		'top_logprobs'       => 'topLogprobs',
	);

	/**
	 * Gets the singleton instance.
	 *
	 * @since 0.2.1
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
	 * @since 0.2.1
	 */
	private function __construct() {}

	/**
	 * Creates a text generation prompt builder with default configuration applied.
	 *
	 * This is the primary method for text generation with AI providers. It returns
	 * a configured prompt builder that consumers can use with the full SDK API.
	 *
	 * Example usage:
	 * ```php
	 * $service = AI_Service::get_instance();
	 *
	 * // Simple usage
	 * $text = $service->create_textgen_prompt( 'Summarize this text' )->generate_text();
	 *
	 * // With options
	 * $text = $service->create_textgen_prompt( 'Translate to French', array(
	 *     'system_instruction' => 'You are a translator.',
	 *     'temperature'        => 0.3,
	 *     'max_tokens'         => 500,
	 * ) )->generate_text();
	 *
	 * // Generate multiple candidates
	 * $titles = $service->create_textgen_prompt( 'Generate titles', array(
	 *     'candidate_count' => 5,
	 *     'temperature'     => 0.8,
	 * ) )->generate_texts();
	 * ```
	 *
	 * @since 0.2.1
	 *
	 * @param string|null          $prompt  Optional. Initial prompt content.
	 * @param array<string, mixed> $options Optional. Configuration options. {
	 *     @type string       $system_instruction System instruction for the AI.
	 *     @type float        $temperature        Temperature for generation (0.0-2.0).
	 *     @type int          $max_tokens         Maximum tokens to generate.
	 *     @type float        $top_p              Top-p (nucleus) sampling value.
	 *     @type int          $top_k              Top-k sampling value.
	 *     @type int          $candidate_count    Number of candidates to generate.
	 *     @type float        $presence_penalty   Presence penalty for generation.
	 *     @type float        $frequency_penalty  Frequency penalty for generation.
	 *     @type list<string> $stop_sequences     Stop sequences for generation.
	 *     @type bool         $logprobs           Whether to return log probabilities.
	 *     @type int          $top_logprobs       Top log probabilities to return.
	 * }
	 * @return \WordPress\AI_Client\Builders\Prompt_Builder_With_WP_Error The prompt builder instance.
	 */
	public function create_textgen_prompt( ?string $prompt = null, array $options = array() ): Prompt_Builder_With_WP_Error {
		$builder = AI_Client::prompt_with_wp_error( $prompt );

		// Apply default model preferences.
		$models = get_preferred_models_for_text_generation();
		if ( ! empty( $models ) ) {
			$builder = $builder->using_model_preference( ...$models );
		}

		// Apply options via ModelConfig if any are provided.
		if ( ! empty( $options ) ) {
			$config_array = $this->map_options_to_config( $options );
			if ( ! empty( $config_array ) ) {
				$config  = ModelConfig::fromArray( $config_array );
				$builder = $builder->using_model_config( $config );
			}
		}

		return $builder;
	}

	/**
	 * Maps WordPress snake_case options to SDK camelCase config array.
	 *
	 * @since 0.2.1
	 *
	 * @param array<string, mixed> $options The options array with snake_case keys.
	 * @return array<string, mixed> The mapped config array with camelCase keys.
	 */
	private function map_options_to_config( array $options ): array {
		$config = array();

		foreach ( self::$option_key_map as $wp_key => $sdk_key ) {
			if ( ! array_key_exists( $wp_key, $options ) ) {
				continue;
			}

			$config[ $sdk_key ] = $options[ $wp_key ];
		}

		return $config;
	}
}
