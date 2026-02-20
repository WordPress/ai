<?php
/**
 * Helper functions for the AI plugin.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI;

use Throwable;
use WordPress\AI\Services\AI_Service;
use WordPress\AI_Client\AI_Client;

/**
 * Purposely using return instead of exit here.
 *
 * This file is loaded via the composer files directive.
 * When tools like PHPCS and PHPStan run, they include
 * our composer autoloader and that will then load this file,
 * causing the script to exit and not function properly.
 */
if ( ! defined( 'ABSPATH' ) ) {
	return;
}

/**
 * Normalizes the content by cleaning it and removing unwanted HTML tags.
 *
 * @since x.x.x
 *
 * @param string $content The content to normalize.
 * @return string The normalized content.
 */
function normalize_content( string $content ): string {
	/**
	 * Hook to filter content before cleaning it.
	 *
	 * @since 0.1.0
	 *
	 * @param string $post_content The post content.
	 *
	 * @return string The filtered Post content.
	 */
	$content = (string) apply_filters( 'ai_experiments_pre_normalize_content', $content );

	// Strip HTML entities.
	$content = preg_replace( '/&#?[a-z0-9]{2,8};/i', '', $content ) ?? $content;

	// Replace HTML linebreaks with newlines.
	$content = preg_replace( '#<br\s?/?>#', "\n\n", $content ) ?? $content;

	// Remove linebreaks but replace with spaces to avoid sentences running together.
	$content = str_replace( array( "\r", "\n" ), ' ', (string) $content );

	// Strip all HTML tags.
	$content = wp_strip_all_tags( (string) $content );

	// Remove unrendered shortcode tags.
	$content = preg_replace( '#\[.+\](.+)\[/.+\]#', '$1', $content ) ?? $content;

	/**
	 * Filters the normalized content to allow for additional cleanup.
	 *
	 * @since 0.1.0
	 *
	 * @param string $content The normalized content.
	 *
	 * @return string The filtered normalized content.
	 */
	$content = (string) apply_filters( 'ai_experiments_normalize_content', (string) $content );

	return trim( $content );
}

/**
 * Returns the context for the given post ID.
 *
 * @since x.x.x
 *
 * @param int $post_id The ID of the post to get the context for.
 * @return array<string, string> The context for the given post ID.
 */
function get_post_context( int $post_id ): array {
	$context = array();

	// Get the post details using the get-post-details ability.
	$details_ability = wp_get_ability( 'ai/get-post-details' );
	if ( $details_ability ) {
		$details = $details_ability->execute( array( 'post_id' => $post_id ) );

		if ( is_array( $details ) ) {
			$context = array_merge( $context, $details );

			if ( isset( $context['content'] ) ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
				$context['content'] = normalize_content( (string) apply_filters( 'the_content', $context['content'] ) );
			}

			if ( isset( $context['type'] ) ) {
				$context['content_type'] = $context['type'];
				unset( $context['type'] );
			}

			// Remove any empty context values.
			$context = array_filter( $context );
		}
	}

	// Get the post terms using the get-terms ability.
	$terms_ability = wp_get_ability( 'ai/get-post-terms' );
	if ( $terms_ability ) {
		$terms = $terms_ability->execute( array( 'post_id' => $post_id ) );

		if ( $terms && ! is_wp_error( $terms ) ) {
			$grouped_terms = array();

			foreach ( $terms as $term ) {
				$grouped_terms[ $term->taxonomy ][] = $term->name;
			}

			$context = array_merge(
				$context,
				array_map(
					static fn( array $term_names ): string => implode( ', ', $term_names ),
					$grouped_terms
				)
			);
		}
	}

	return $context;
}

/**
 * Returns the preferred models for text generation.
 *
 * @since 0.2.1
 *
 * @return array<int, array{string, string}> The preferred models for text generation.
 */
function get_preferred_models_for_text_generation(): array {
	$preferred_models = array(
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
	 * Filters the preferred models for text generation.
	 *
	 * @since 0.2.1
	 *
	 * @param array<int, array{string, string}> $preferred_models The preferred models for text generation.
	 * @return array<int, array{string, string}> The filtered preferred models.
	 */
	return (array) apply_filters( 'ai_experiments_preferred_models_for_text_generation', $preferred_models );
}

/**
 * Gets the AI Service instance.
 *
 * Provides a convenient way to access the AI Service for performing AI operations.
 *
 * Example usage:
 * ```php
 * $service = WordPress\AI\get_ai_service();
 *
 * // Check if text generation is supported before generating
 * $builder = $service->create_textgen_prompt( 'Summarize this article...' );
 * if ( ! $builder->is_supported_for_text_generation() ) {
 *     return new WP_Error( 'ai_unsupported', 'No AI provider supports text generation.' );
 * }
 * $text = $builder->generate_text();
 *
 * // With options array
 * $text = $service->create_textgen_prompt( 'Translate to French: Hello', array(
 *     'system_instruction' => 'You are a translator.',
 *     'temperature'        => 0.3,
 * ) )->generate_text();
 *
 * // Chain additional SDK methods
 * $titles = $service->create_textgen_prompt( 'Generate titles for: My blog post' )
 *     ->using_candidate_count( 5 )
 *     ->generate_texts();
 * ```
 *
 * @since 0.2.1
 *
 * @return \WordPress\AI\Services\AI_Service The AI Service instance.
 */
function get_ai_service(): AI_Service {
	return AI_Service::get_instance();
}

/**
 * Returns the preferred image models.
 *
 * @since 0.2.0
 *
 * @return array<int, array{string, string}> The preferred image models.
 */
function get_preferred_image_models(): array {
	$preferred_models = array(
		array(
			'google',
			'gemini-3-pro-image-preview',
		),
		array(
			'google',
			'gemini-2.5-flash-image',
		),
		array(
			'google',
			'imagen-4.0-generate-001',
		),
		array(
			'openai',
			'gpt-image-1.5',
		),
		array(
			'openai',
			'gpt-image-1',
		),
		array(
			'openai',
			'dall-e-3',
		),
	);

	/**
	 * Filters the preferred image models.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, array{string, string}> $preferred_models The preferred image models.
	 * @return array<int, array{string, string}> The filtered preferred image models.
	 */
	return (array) apply_filters( 'ai_experiments_preferred_image_models', $preferred_models );
}

/**
 * Returns the preferred vision models.
 *
 * @since 0.3.0
 *
 * @return array<int, array{string, string}> The preferred vision models.
 */
function get_preferred_vision_models(): array {
	$preferred_models = array(
		array(
			'anthropic',
			'claude-haiku-4-5-20251001',
		),
		array(
			'google',
			'gemini-2.5-flash',
		),
		array(
			'openai',
			'gpt-5-nano',
		),
	);

	/**
	 * Filters the preferred vision models.
	 *
	 * @since 0.3.0
	 *
	 * @param array<int, array{string, string}> $preferred_models The preferred vision models.
	 * @return array<int, array{string, string}> The filtered preferred vision models.
	 */
	return (array) apply_filters( 'ai_experiments_preferred_vision_models', $preferred_models );
}

/**
 * Returns a prompt builder with WP_Error error handling.
 *
 * Uses the core AI Client API when available, otherwise falls back to the
 * bundled WP AI Client package.
 *
 * @since x.x.x
 *
 * @param mixed $prompt Optional initial prompt content.
 * @return mixed Prompt builder instance.
 */
function ai_client_prompt_with_wp_error( $prompt = null ) {
	if ( ! should_use_bundled_wp_ai_client() && function_exists( 'wp_ai_client_prompt' ) ) {
		return wp_ai_client_prompt( $prompt );
	}

	if ( class_exists( AI_Client::class ) ) {
		return AI_Client::prompt_with_wp_error( $prompt );
	}

	throw new \RuntimeException( 'No AI Client prompt builder is available.' );
}

/**
 * Returns a prompt builder.
 *
 * Uses the core AI Client API when available, otherwise falls back to the
 * bundled WP AI Client package.
 *
 * @since x.x.x
 *
 * @param mixed $prompt Optional initial prompt content.
 * @return mixed Prompt builder instance.
 */
function ai_client_prompt( $prompt = null ) {
	if ( ! should_use_bundled_wp_ai_client() && function_exists( 'wp_ai_client_prompt' ) ) {
		return wp_ai_client_prompt( $prompt );
	}

	if ( class_exists( AI_Client::class ) ) {
		return AI_Client::prompt( $prompt );
	}

	throw new \RuntimeException( 'No AI Client prompt builder is available.' );
}

/**
 * Converts a value to CONSTANT_CASE.
 *
 * @since 0.1.0
 *
 * @param string $value Value to convert.
 * @return string CONSTANT_CASE value.
 */
function to_constant_case( string $value ): string {
	$value = str_replace( '-', '_', $value );
	$value = preg_replace( '/([a-z])([A-Z])/', '$1_$2', $value );
	return strtoupper( (string) $value );
}

/**
 * Returns registered provider IDs for the AI client.
 *
 * @since 0.1.0
 *
 * @return array<int, string> Provider IDs.
 */
function get_ai_provider_ids(): array {
	$provider_ids = array();

	if ( class_exists( '\WordPress\AiClient\AiClient' ) ) {
		try {
			$registry     = \WordPress\AiClient\AiClient::defaultRegistry();
			$provider_ids = array_filter(
				$registry->getRegisteredProviderIds(),
				static function ( string $provider_id ): bool {
					return '' !== $provider_id;
				}
			);
		} catch ( Throwable $t ) {
			$provider_ids = array();
		}
	}

	$stored_credentials = get_option( 'wp_ai_client_provider_credentials', array() );
	if ( is_array( $stored_credentials ) ) {
		$provider_ids = array_merge( $provider_ids, array_keys( $stored_credentials ) );
	}

	$provider_ids = array_values(
		array_unique(
			array_filter(
				$provider_ids,
				static function ( $provider_id ): bool {
					return is_string( $provider_id ) && '' !== $provider_id;
				}
			)
		)
	);

	// Fall back to known cloud providers if IDs cannot be resolved at runtime.
	if ( empty( $provider_ids ) ) {
		$provider_ids = array( 'openai', 'anthropic', 'google' );
	}

	/**
	 * Filters AI provider IDs used for credential discovery.
	 *
	 * @since x.x.x
	 * @hook ai_experiments_provider_ids
	 *
	 * @param array<int, string> $provider_ids Provider IDs.
	 * @return array<int, string> Filtered provider IDs.
	 */
	return (array) apply_filters( 'ai_experiments_provider_ids', $provider_ids );
}

/**
 * Returns provider API key environment variable / constant names.
 *
 * The default format follows the core/provider registry convention:
 * `<PROVIDER_ID>_API_KEY`.
 *
 * @since x.x.x
 *
 * @return array<int, string> Environment variable / constant names.
 */
function get_ai_provider_api_key_variable_names(): array {
	$variable_names = array_map(
		static function ( string $provider_id ): string {
			return to_constant_case( $provider_id ) . '_API_KEY';
		},
		get_ai_provider_ids()
	);

	$variable_names = array_values( array_unique( $variable_names ) );

	/**
	 * Filters provider API key variable names used for credential discovery.
	 *
	 * @since x.x.x
	 * @hook ai_experiments_provider_api_key_variable_names
	 *
	 * @param array<int, string> $variable_names Variable names.
	 * @return array<int, string> Filtered variable names.
	 */
	return (array) apply_filters( 'ai_experiments_provider_api_key_variable_names', $variable_names );
}

/**
 * Checks whether any provider API key exists in env vars or constants.
 *
 * @since x.x.x
 *
 * @return bool True if an API key variable is set, otherwise false.
 */
function has_ai_credentials_in_environment(): bool {
	foreach ( get_ai_provider_api_key_variable_names() as $variable_name ) {
		$env_value = getenv( $variable_name );
		if ( false !== $env_value && '' !== trim( (string) $env_value ) ) {
			return true;
		}

		if ( ! defined( $variable_name ) ) {
			continue;
		}

		$constant_value = constant( $variable_name );
		if ( is_scalar( $constant_value ) && '' !== trim( (string) $constant_value ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Checks if we have AI credentials set.
 *
 * @since 0.1.0
 *
 * @return bool True if we have AI credentials, false otherwise.
 */
function has_ai_credentials(): bool {
	$credentials = get_option( 'wp_ai_client_provider_credentials', array() );

	if ( is_array( $credentials ) && ! empty( $credentials ) ) {
		// If all of the AI keys are empty, return false; otherwise, return true.
		$has_option_credentials = ! empty(
			array_filter(
				$credentials,
				static function ( $api_key ): bool {
					return is_string( $api_key ) && '' !== trim( $api_key );
				}
			)
		);

		if ( $has_option_credentials ) {
			return true;
		}
	}

	return has_ai_credentials_in_environment();
}

/**
 * Checks if we have valid AI credentials.
 *
 * @since 0.1.0
 *
 * @return bool True if we have valid AI credentials, false otherwise.
 */
function has_valid_ai_credentials(): bool {
	// If we have no AI credentials, return false.
	if ( ! has_ai_credentials() ) {
		return false;
	}

	/**
	 * Filters whether valid AI credentials are available.
	 *
	 * Allows overriding the credentials check, useful for testing.
	 *
	 * @since 0.1.0
	 *
	 * @param bool|null $has_valid_credentials Whether valid credentials are available. Return null to use default check.
	 * @return bool|null True if valid credentials are available, false otherwise, or null to use default check.
	 */
	$valid = apply_filters( 'ai_experiments_pre_has_valid_credentials_check', null );
	if ( null !== $valid ) {
		return (bool) $valid;
	}

	// See if we have credentials that give us access to generate text.
	try {
		return ai_client_prompt( 'Test' )->is_supported_for_text_generation();
	} catch ( Throwable $t ) {
		return false;
	}
}
