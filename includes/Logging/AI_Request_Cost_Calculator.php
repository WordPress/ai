<?php
/**
 * Calculates estimated costs for AI requests based on token usage.
 *
 * @package WordPress\AI\Logging
 */

declare( strict_types=1 );

namespace WordPress\AI\Logging;

defined( 'ABSPATH' ) || exit;

/**
 * Provides cost estimation for AI requests across multiple providers.
 *
 * @since x.x.x
 */
class AI_Request_Cost_Calculator {

	/**
	 * Model pricing registry (per 1K tokens in USD).
	 *
	 * Prices are sourced from each provider's public pricing page.
	 * The prefix-matching strategy in estimate() means shorter keys
	 * act as catch-alls for versioned variants (e.g. "gpt-4o" matches
	 * "gpt-4o-2024-08-06"). More specific keys must appear before
	 * shorter prefixes that would otherwise shadow them.
	 *
	 * @var array<string, array<string, array{input: float, output: float}>>
	 */
	private static array $model_costs = array(
		'openai'    => array(
			// GPT-5 family.
			'gpt-5.1'       => array(
				'input'  => 0.00125,
				'output' => 0.01,
			),
			'gpt-5-pro'     => array(
				'input'  => 0.015,
				'output' => 0.12,
			),
			'gpt-5-mini'    => array(
				'input'  => 0.00025,
				'output' => 0.002,
			),
			'gpt-5-nano'    => array(
				'input'  => 0.00005,
				'output' => 0.0004,
			),
			// GPT-4 family.
			'gpt-4-turbo'   => array(
				'input'  => 0.01,
				'output' => 0.03,
			),
			'gpt-4o-mini'   => array(
				'input'  => 0.00015,
				'output' => 0.0006,
			),
			'gpt-4o'        => array(
				'input'  => 0.005,
				'output' => 0.015,
			),
			'gpt-4'         => array(
				'input'  => 0.03,
				'output' => 0.06,
			),
			'gpt-3.5-turbo' => array(
				'input'  => 0.0005,
				'output' => 0.0015,
			),
			// Reasoning models (o-series).
			'o4-mini'       => array(
				'input'  => 0.00055,
				'output' => 0.0022,
			),
			'o3-pro'        => array(
				'input'  => 0.02,
				'output' => 0.08,
			),
			'o3-mini'       => array(
				'input'  => 0.00055,
				'output' => 0.0022,
			),
			'o3'            => array(
				'input'  => 0.002,
				'output' => 0.008,
			),
			'o1-pro'        => array(
				'input'  => 0.15,
				'output' => 0.6,
			),
			'o1-mini'       => array(
				'input'  => 0.001,
				'output' => 0.004,
			),
			'o1'            => array(
				'input'  => 0.015,
				'output' => 0.06,
			),
		),
		'anthropic' => array(
			// Claude 4.6 (current model ID format: claude-{role}-{version}).
			'claude-opus-4-6'   => array(
				'input'  => 0.015,
				'output' => 0.075,
			),
			'claude-sonnet-4-6' => array(
				'input'  => 0.003,
				'output' => 0.015,
			),
			// Claude 4.5.
			'claude-opus-4-5'   => array(
				'input'  => 0.015,
				'output' => 0.075,
			),
			'claude-sonnet-4-5' => array(
				'input'  => 0.003,
				'output' => 0.015,
			),
			'claude-haiku-4-5'  => array(
				'input'  => 0.001,
				'output' => 0.005,
			),
			// Alternate naming variants (marketing names).
			'claude-4.5-opus'   => array(
				'input'  => 0.015,
				'output' => 0.075,
			),
			'claude-4.5-sonnet' => array(
				'input'  => 0.003,
				'output' => 0.015,
			),
			'claude-4.5-haiku'  => array(
				'input'  => 0.001,
				'output' => 0.005,
			),
			// Claude 3.5 / 3.
			'claude-3-5-sonnet' => array(
				'input'  => 0.003,
				'output' => 0.015,
			),
			'claude-3-5-haiku'  => array(
				'input'  => 0.001,
				'output' => 0.005,
			),
			'claude-3-opus'     => array(
				'input'  => 0.015,
				'output' => 0.075,
			),
			'claude-3-sonnet'   => array(
				'input'  => 0.003,
				'output' => 0.015,
			),
			'claude-3-haiku'    => array(
				'input'  => 0.00025,
				'output' => 0.00125,
			),
		),
		'google'    => array(
			'gemini-3-pro-preview-high-context' => array(
				'input'  => 0.004,
				'output' => 0.018,
			),
			'gemini-3-pro-preview'              => array(
				'input'  => 0.002,
				'output' => 0.012,
			),
			'gemini-2.5-pro-high-context'       => array(
				'input'  => 0.0025,
				'output' => 0.015,
			),
			'gemini-2.5-pro'                    => array(
				'input'  => 0.00125,
				'output' => 0.01,
			),
			'gemini-2.5-flash-lite'             => array(
				'input'  => 0.0001,
				'output' => 0.0004,
			),
			'gemini-2.5-flash'                  => array(
				'input'  => 0.0003,
				'output' => 0.0025,
			),
			'gemini-2.0-flash-lite'             => array(
				'input'  => 0.000075,
				'output' => 0.0003,
			),
			'gemini-2.0-flash'                  => array(
				'input'  => 0.0001,
				'output' => 0.0004,
			),
			'gemini-1.5-pro'                    => array(
				'input'  => 0.00125,
				'output' => 0.005,
			),
			'gemini-1.5-flash'                  => array(
				'input'  => 0.000075,
				'output' => 0.0003,
			),
		),
		'deepseek'  => array(
			'deepseek-chat'     => array(
				'input'  => 0.00027,
				'output' => 0.0011,
			),
			'deepseek-reasoner' => array(
				'input'  => 0.00055,
				'output' => 0.00219,
			),
		),
		'mistral'   => array(
			'mistral-large'  => array(
				'input'  => 0.002,
				'output' => 0.006,
			),
			'mistral-medium' => array(
				'input'  => 0.0027,
				'output' => 0.0081,
			),
			'mistral-small'  => array(
				'input'  => 0.001,
				'output' => 0.003,
			),
			'codestral'      => array(
				'input'  => 0.001,
				'output' => 0.003,
			),
			'pixtral-large'  => array(
				'input'  => 0.002,
				'output' => 0.006,
			),
			'pixtral'        => array(
				'input'  => 0.001,
				'output' => 0.003,
			),
			'open-mistral'   => array(
				'input'  => 0.00015,
				'output' => 0.00015,
			),
		),
		'cohere'    => array(
			'command-a'      => array(
				'input'  => 0.0025,
				'output' => 0.01,
			),
			'command-r-plus' => array(
				'input'  => 0.00275,
				'output' => 0.015,
			),
			'command-r'      => array(
				'input'  => 0.00015,
				'output' => 0.0006,
			),
			'command-light'  => array(
				'input'  => 0.0003,
				'output' => 0.0006,
			),
			'command'        => array(
				'input'  => 0.001,
				'output' => 0.002,
			),
		),
		'groq'      => array(
			'llama-3.3-70b' => array(
				'input'  => 0.00059,
				'output' => 0.00079,
			),
			'llama-3.1-8b'  => array(
				'input'  => 0.00005,
				'output' => 0.00008,
			),
			'gemma2-9b'     => array(
				'input'  => 0.0002,
				'output' => 0.0002,
			),
			'mixtral-8x7b'  => array(
				'input'  => 0.00024,
				'output' => 0.00024,
			),
		),
		'grok'      => array(
			'grok-3'      => array(
				'input'  => 0.003,
				'output' => 0.015,
			),
			'grok-3-mini' => array(
				'input'  => 0.0003,
				'output' => 0.0005,
			),
			'grok-2'      => array(
				'input'  => 0.002,
				'output' => 0.01,
			),
		),
		// Ollama runs locally — zero cost.
		'ollama'    => array(),
	);

	/**
	 * Estimates the cost of an AI request based on token usage.
	 *
	 * @since x.x.x
	 *
	 * @param string $provider      The AI provider identifier.
	 * @param string $model         The model identifier.
	 * @param int    $tokens_input  Number of input tokens.
	 * @param int    $tokens_output Number of output tokens.
	 * @return float|null Estimated cost in USD, or null if pricing is unknown.
	 */
	/**
	 * Provider aliases — maps providers that host another provider's
	 * models to the canonical provider key for pricing lookup.
	 *
	 * @var array<string, string>
	 */
	private static array $provider_aliases = array(
		'azure' => 'openai',
	);

	public function estimate( string $provider, string $model, int $tokens_input, int $tokens_output ): ?float {
		$costs = $this->get_model_costs();

		$provider_lower = strtolower( $provider );
		$model_lower    = strtolower( $model );

		// Local providers (e.g. Ollama) are free — return zero when the
		// provider key exists in the registry but has an empty pricing table.
		if ( isset( $costs[ $provider_lower ] ) && empty( $costs[ $provider_lower ] ) ) {
			return 0.0;
		}

		$pricing = $this->find_pricing( $costs, $provider_lower, $model_lower );

		// Fall back to aliased provider (e.g. azure → openai).
		if ( null === $pricing && isset( self::$provider_aliases[ $provider_lower ] ) ) {
			$pricing = $this->find_pricing( $costs, self::$provider_aliases[ $provider_lower ], $model_lower );
		}

		if ( null === $pricing ) {
			return null;
		}

		return ( $tokens_input / 1000 * $pricing['input'] ) +
				( $tokens_output / 1000 * $pricing['output'] );
	}

	/**
	 * Looks up pricing for a provider + model combination.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, array<string, array{input: float, output: float}>> $costs    Full pricing registry.
	 * @param string                                                            $provider Lowercase provider key.
	 * @param string                                                            $model    Lowercase model identifier.
	 * @return array{input: float, output: float}|null Pricing entry or null.
	 */
	private function find_pricing( array $costs, string $provider, string $model ): ?array {
		// Exact match.
		if ( isset( $costs[ $provider ][ $model ] ) ) {
			return $costs[ $provider ][ $model ];
		}

		// Prefix match for model variants (e.g., gpt-4-turbo-preview → gpt-4-turbo).
		if ( isset( $costs[ $provider ] ) ) {
			foreach ( $costs[ $provider ] as $model_key => $pricing ) {
				if ( 0 === strpos( $model, $model_key ) ) {
					return $pricing;
				}
			}
		}

		return null;
	}

	/**
	 * Returns the model costs registry, allowing for filtering.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array<string, array{input: float, output: float}>> Model pricing data.
	 */
	public function get_model_costs(): array {
		/**
		 * Filters the model cost registry.
		 *
		 * Allows plugins to add or modify pricing data for AI models.
		 *
		 * @since x.x.x
		 *
		 * @param array<string, array<string, array{input: float, output: float}>> $model_costs Model pricing data.
		 */
		return apply_filters( 'wpai_model_costs', self::$model_costs );
	}
}
