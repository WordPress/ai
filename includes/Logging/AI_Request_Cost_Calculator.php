<?php
/**
 * Calculates estimated costs for AI requests based on token usage.
 *
 * @package WordPress\AI\Logging
 */

declare( strict_types=1 );

namespace WordPress\AI\Logging;

/**
 * Provides cost estimation for AI requests across multiple providers.
 *
 * @since x.x.x
 */
class AI_Request_Cost_Calculator {

	/**
	 * Model pricing registry (per 1K tokens in USD).
	 *
	 * @var array<string, array<string, array{input: float, output: float}>>
	 */
	private static array $model_costs = array(
		'openai'    => array(
			'gpt-5.1'       => array(
				'input'  => 0.00125,
				'output' => 0.01,
			),
			'gpt-5-mini'    => array(
				'input'  => 0.00025,
				'output' => 0.002,
			),
			'gpt-5-nano'    => array(
				'input'  => 0.00005,
				'output' => 0.0004,
			),
			'gpt-5-pro'     => array(
				'input'  => 0.015,
				'output' => 0.12,
			),
			'gpt-4'         => array(
				'input'  => 0.03,
				'output' => 0.06,
			),
			'gpt-4-turbo'   => array(
				'input'  => 0.01,
				'output' => 0.03,
			),
			'gpt-4o'        => array(
				'input'  => 0.005,
				'output' => 0.015,
			),
			'gpt-4o-mini'   => array(
				'input'  => 0.00015,
				'output' => 0.0006,
			),
			'gpt-3.5-turbo' => array(
				'input'  => 0.0005,
				'output' => 0.0015,
			),
		),
		'anthropic' => array(
			'claude-4.5-opus'   => array(
				'input'  => 0.005,
				'output' => 0.025,
			),
			'claude-4.5-sonnet' => array(
				'input'  => 0.003,
				'output' => 0.015,
			),
			'claude-4.5-haiku'  => array(
				'input'  => 0.001,
				'output' => 0.005,
			),
			'claude-haiku-4-5'  => array(
				'input'  => 0.001,
				'output' => 0.005,
			),
			'claude-3-opus'     => array(
				'input'  => 0.015,
				'output' => 0.075,
			),
			'claude-3-5-sonnet' => array(
				'input'  => 0.003,
				'output' => 0.015,
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
			'gemini-3-pro-preview'              => array(
				'input'  => 0.002,
				'output' => 0.012,
			),
			'gemini-3-pro-preview-high-context' => array(
				'input'  => 0.004,
				'output' => 0.018,
			),
			'gemini-2.5-pro'                    => array(
				'input'  => 0.00125,
				'output' => 0.01,
			),
			'gemini-2.5-pro-high-context'       => array(
				'input'  => 0.0025,
				'output' => 0.015,
			),
			'gemini-2.5-flash'                  => array(
				'input'  => 0.0003,
				'output' => 0.0025,
			),
			'gemini-2.5-flash-lite'             => array(
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
	public function estimate( string $provider, string $model, int $tokens_input, int $tokens_output ): ?float {
		$costs = $this->get_model_costs();

		$provider_lower = strtolower( $provider );
		$model_lower    = strtolower( $model );

		// Try exact match first.
		if ( isset( $costs[ $provider_lower ][ $model_lower ] ) ) {
			$pricing = $costs[ $provider_lower ][ $model_lower ];
			return ( $tokens_input / 1000 * $pricing['input'] ) +
					( $tokens_output / 1000 * $pricing['output'] );
		}

		// Try prefix match for model variants (e.g., gpt-4-turbo-preview -> gpt-4-turbo).
		if ( isset( $costs[ $provider_lower ] ) ) {
			foreach ( $costs[ $provider_lower ] as $model_key => $pricing ) {
				if ( 0 === strpos( $model_lower, $model_key ) ) {
					return ( $tokens_input / 1000 * $pricing['input'] ) +
							( $tokens_output / 1000 * $pricing['output'] );
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
