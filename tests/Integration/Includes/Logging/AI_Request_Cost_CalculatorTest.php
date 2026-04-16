<?php
/**
 * Integration tests for AI request cost calculator.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Logging
 */

namespace WordPress\AI\Tests\Integration\Includes\Logging;

use WP_UnitTestCase;
use WordPress\AI\Logging\AI_Request_Cost_Calculator;

/**
 * AI_Request_Cost_Calculator test case.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Logging\AI_Request_Cost_Calculator
 */
class AI_Request_Cost_CalculatorTest extends WP_UnitTestCase {

	/**
	 * Calculator instance under test.
	 *
	 * @var \WordPress\AI\Logging\AI_Request_Cost_Calculator
	 */
	private AI_Request_Cost_Calculator $calculator;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->calculator = new AI_Request_Cost_Calculator();
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	protected function tearDown(): void {
		remove_all_filters( 'wpai_model_costs' );
		parent::tearDown();
	}

	/**
	 * Tests exact model match for an OpenAI model.
	 *
	 * @since x.x.x
	 */
	public function test_estimate_exact_openai_model(): void {
		// gpt-4o: input=0.005/1K, output=0.015/1K => 0.005 + 0.015 = 0.02.
		$cost = $this->calculator->estimate( 'openai', 'gpt-4o', 1000, 1000 );

		$this->assertNotNull( $cost );
		$this->assertEqualsWithDelta( 0.02, $cost, 0.0001 );
	}

	/**
	 * Tests prefix-based matching for versioned model variants.
	 *
	 * @since x.x.x
	 */
	public function test_estimate_prefix_matches_model_variant(): void {
		$cost = $this->calculator->estimate( 'openai', 'gpt-4o-2024-08-06', 1000, 1000 );

		$this->assertNotNull( $cost );
		$this->assertGreaterThan( 0, $cost );
	}

	/**
	 * Tests cost estimation for an Anthropic model.
	 *
	 * @since x.x.x
	 */
	public function test_estimate_anthropic_model(): void {
		// claude-sonnet-4-5: input=0.003/1K, output=0.015/1K => 0.018.
		$cost = $this->calculator->estimate( 'anthropic', 'claude-sonnet-4-5', 1000, 1000 );

		$this->assertNotNull( $cost );
		$this->assertEqualsWithDelta( 0.018, $cost, 0.0001 );
	}

	/**
	 * Tests cost estimation for a Google model.
	 *
	 * @since x.x.x
	 */
	public function test_estimate_google_model(): void {
		$cost = $this->calculator->estimate( 'google', 'gemini-2.5-flash', 1000, 1000 );

		$this->assertNotNull( $cost );
		$this->assertGreaterThan( 0, $cost );
	}

	/**
	 * Tests that Ollama (local provider) returns zero cost.
	 *
	 * @since x.x.x
	 */
	public function test_estimate_ollama_returns_zero(): void {
		$cost = $this->calculator->estimate( 'ollama', 'llama3', 1000, 1000 );

		$this->assertSame( 0.0, $cost );
	}

	/**
	 * Tests that an unknown provider returns null.
	 *
	 * @since x.x.x
	 */
	public function test_estimate_unknown_provider_returns_null(): void {
		$this->assertNull( $this->calculator->estimate( 'unknown-provider', 'some-model', 1000, 1000 ) );
	}

	/**
	 * Tests that an unknown model for a known provider returns null.
	 *
	 * @since x.x.x
	 */
	public function test_estimate_unknown_model_returns_null(): void {
		$this->assertNull( $this->calculator->estimate( 'openai', 'nonexistent-model-xyz', 1000, 1000 ) );
	}

	/**
	 * Tests the Azure provider alias falls back to OpenAI pricing.
	 *
	 * @since x.x.x
	 */
	public function test_estimate_azure_alias_falls_back_to_openai(): void {
		$openai_cost = $this->calculator->estimate( 'openai', 'gpt-4o', 1000, 1000 );
		$azure_cost  = $this->calculator->estimate( 'azure', 'gpt-4o', 1000, 1000 );

		$this->assertNotNull( $azure_cost );
		$this->assertSame( $openai_cost, $azure_cost );
	}

	/**
	 * Tests that provider and model matching is case-insensitive.
	 *
	 * @since x.x.x
	 */
	public function test_estimate_is_case_insensitive(): void {
		$lower = $this->calculator->estimate( 'openai', 'gpt-4o', 1000, 1000 );
		$upper = $this->calculator->estimate( 'OpenAI', 'GPT-4o', 1000, 1000 );

		$this->assertSame( $lower, $upper );
	}

	/**
	 * Tests that zero tokens produce a zero cost for a known model.
	 *
	 * @since x.x.x
	 */
	public function test_estimate_zero_tokens_returns_zero_cost(): void {
		$cost = $this->calculator->estimate( 'openai', 'gpt-4o', 0, 0 );

		$this->assertNotNull( $cost );
		$this->assertSame( 0.0, $cost );
	}

	/**
	 * Tests that get_model_costs returns the expected provider keys.
	 *
	 * @since x.x.x
	 */
	public function test_get_model_costs_returns_expected_providers(): void {
		$costs = $this->calculator->get_model_costs();

		$this->assertIsArray( $costs );
		$this->assertArrayHasKey( 'openai', $costs );
		$this->assertArrayHasKey( 'anthropic', $costs );
		$this->assertArrayHasKey( 'google', $costs );
		$this->assertArrayHasKey( 'ollama', $costs );
	}

	/**
	 * Tests that the wpai_model_costs filter can add custom providers.
	 *
	 * @since x.x.x
	 */
	public function test_get_model_costs_is_filterable(): void {
		add_filter(
			'wpai_model_costs',
			static function ( array $costs ): array {
				$costs['custom_provider'] = array(
					'custom-model' => array(
						'input'  => 0.01,
						'output' => 0.02,
					),
				);
				return $costs;
			}
		);

		$cost = $this->calculator->estimate( 'custom_provider', 'custom-model', 1000, 1000 );

		$this->assertNotNull( $cost );
		$this->assertEqualsWithDelta( 0.03, $cost, 0.0001 );
	}
}
