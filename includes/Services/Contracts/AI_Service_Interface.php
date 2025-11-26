<?php
/**
 * AI Service Interface.
 *
 * Defines the contract for AI service implementations.
 *
 * @package WordPress\AI\Services\Contracts
 */

declare( strict_types=1 );

namespace WordPress\AI\Services\Contracts;

use WordPress\AI_Client\Builders\Prompt_Builder_With_WP_Error;

/**
 * Interface for AI service implementations.
 *
 * Provides a consistent API for experimental features to interact with AI providers
 * without directly coupling to the underlying AI Client implementation.
 *
 * @since 0.1.0
 */
interface AI_Service_Interface {

	/**
	 * Checks if an AI provider is available and configured.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True if a provider is available, false otherwise.
	 */
	public function is_available(): bool;

	/**
	 * Generates text from a prompt.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $prompt             The prompt to generate text from.
	 * @param array<string, mixed> $options            Optional. Generation options.
	 * @return string|\WP_Error The generated text or WP_Error on failure.
	 */
	public function generate_text( string $prompt, array $options = array() );

	/**
	 * Generates multiple text candidates from a prompt.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $prompt             The prompt to generate text from.
	 * @param int                  $candidate_count    The number of candidates to generate.
	 * @param array<string, mixed> $options            Optional. Generation options.
	 * @return list<string>|\WP_Error The generated texts or WP_Error on failure.
	 */
	public function generate_texts( string $prompt, int $candidate_count, array $options = array() );

	/**
	 * Creates a prompt builder for advanced use cases.
	 *
	 * Use this method when you need fine-grained control over the prompt configuration
	 * that isn't available through the simpler generate_text/generate_texts methods.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $prompt Optional initial prompt content.
	 * @return \WordPress\AI_Client\Builders\Prompt_Builder_With_WP_Error The prompt builder instance.
	 */
	public function create_prompt( ?string $prompt = null ): Prompt_Builder_With_WP_Error;

	/**
	 * Gets the preferred AI models for generation.
	 *
	 * @since 0.1.0
	 *
	 * @return list<array{string, string}> Array of [provider, model] tuples.
	 */
	public function get_preferred_models(): array;
}
