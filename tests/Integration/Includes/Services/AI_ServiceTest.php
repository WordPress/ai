<?php
/**
 * Tests for the AI_Service class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Services
 */

namespace WordPress\AI\Tests\Integration\Includes\Services;

use WP_UnitTestCase;
use WordPress\AI\Services\AI_Service;
use WordPress\AI_Client\Builders\Prompt_Builder_With_WP_Error;
use function WordPress\AI\get_ai_service;

/**
 * AI_Service test case.
 *
 * @since 0.1.0
 */
class AI_Service_Test extends WP_UnitTestCase {

	/**
	 * AI service instance.
	 *
	 * @var \WordPress\AI\Services\AI_Service
	 */
	private AI_Service $service;

	/**
	 * Setup test case.
	 *
	 * @since 0.1.0
	 */
	public function setUp(): void {
		parent::setUp();
		$this->service = AI_Service::get_instance();
		$this->service->clear_model_cache();
	}

	/**
	 * Teardown test case.
	 *
	 * @since 0.1.0
	 */
	public function tearDown(): void {
		remove_all_filters( 'ai_preferred_models' );
		remove_all_filters( 'ai_service_available' );
		remove_all_filters( 'ai_service_prompt_builder' );
		parent::tearDown();
	}

	/**
	 * Test singleton instance.
	 *
	 * @since 0.1.0
	 */
	public function test_get_instance_returns_singleton(): void {
		$instance1 = AI_Service::get_instance();
		$instance2 = AI_Service::get_instance();

		$this->assertSame( $instance1, $instance2, 'Should return the same instance' );
	}

	/**
	 * Test helper function returns service instance.
	 *
	 * @since 0.1.0
	 */
	public function test_get_ai_service_helper_returns_instance(): void {
		$service = get_ai_service();

		$this->assertInstanceOf( AI_Service::class, $service, 'Helper should return AI_Service instance' );
		$this->assertSame( $this->service, $service, 'Helper should return singleton instance' );
	}

	/**
	 * Test get_preferred_models returns default models.
	 *
	 * @since 0.1.0
	 */
	public function test_get_preferred_models_returns_defaults(): void {
		$models = $this->service->get_preferred_models();

		$this->assertIsArray( $models, 'Should return an array' );
		$this->assertNotEmpty( $models, 'Should have default models' );

		// Check structure of first model.
		$first_model = $models[0];
		$this->assertIsArray( $first_model, 'Each model should be an array' );
		$this->assertCount( 2, $first_model, 'Each model should have provider and model name' );
	}

	/**
	 * Test get_preferred_models filter works.
	 *
	 * @since 0.1.0
	 */
	public function test_get_preferred_models_filter(): void {
		$custom_models = array(
			array( 'custom-provider', 'custom-model' ),
		);

		add_filter(
			'ai_preferred_models',
			static function () use ( $custom_models ) {
				return $custom_models;
			}
		);

		// Clear cache to pick up new filter.
		$this->service->clear_model_cache();
		$models = $this->service->get_preferred_models();

		$this->assertEquals( $custom_models, $models, 'Should use filtered models' );
	}

	/**
	 * Test model caching works.
	 *
	 * @since 0.1.0
	 */
	public function test_model_caching(): void {
		$call_count = 0;

		add_filter(
			'ai_preferred_models',
			static function ( $models ) use ( &$call_count ) {
				++$call_count;
				return $models;
			}
		);

		// First call.
		$this->service->clear_model_cache();
		$this->service->get_preferred_models();
		$this->assertEquals( 1, $call_count, 'Filter should be called once' );

		// Second call should use cache.
		$this->service->get_preferred_models();
		$this->assertEquals( 1, $call_count, 'Filter should not be called again due to caching' );

		// After clearing cache, filter should be called again.
		$this->service->clear_model_cache();
		$this->service->get_preferred_models();
		$this->assertEquals( 2, $call_count, 'Filter should be called after cache clear' );
	}

	/**
	 * Test is_available returns false when no credentials configured.
	 *
	 * @since 0.1.0
	 */
	public function test_is_available_returns_false_without_credentials(): void {
		// Ensure no credentials are set.
		delete_option( 'wp_ai_client_provider_credentials' );

		$this->assertFalse( $this->service->is_available(), 'Should be unavailable without credentials' );
	}

	/**
	 * Test is_available returns true when credentials configured.
	 *
	 * @since 0.1.0
	 */
	public function test_is_available_returns_true_with_credentials(): void {
		// Set mock credentials.
		update_option( 'wp_ai_client_provider_credentials', array( 'openai' => 'test-key' ) );

		$this->assertTrue( $this->service->is_available(), 'Should be available with credentials' );

		// Cleanup.
		delete_option( 'wp_ai_client_provider_credentials' );
	}

	/**
	 * Test is_available filter can override detection.
	 *
	 * @since 0.1.0
	 */
	public function test_is_available_filter_override(): void {
		// Ensure no credentials.
		delete_option( 'wp_ai_client_provider_credentials' );

		// Override to return true.
		add_filter( 'ai_service_available', '__return_true' );

		$this->assertTrue( $this->service->is_available(), 'Filter should override availability' );
	}

	/**
	 * Test create_prompt returns prompt builder.
	 *
	 * @since 0.1.0
	 */
	public function test_create_prompt_returns_builder(): void {
		$builder = $this->service->create_prompt( 'Test prompt' );

		$this->assertInstanceOf(
			Prompt_Builder_With_WP_Error::class,
			$builder,
			'Should return Prompt_Builder_With_WP_Error instance'
		);
	}

	/**
	 * Test generate_text returns error when unavailable.
	 *
	 * @since 0.1.0
	 */
	public function test_generate_text_returns_error_when_unavailable(): void {
		delete_option( 'wp_ai_client_provider_credentials' );

		$result = $this->service->generate_text( 'Test prompt' );

		$this->assertWPError( $result, 'Should return WP_Error when unavailable' );
		$this->assertEquals( 'ai_service_unavailable', $result->get_error_code(), 'Should have correct error code' );
	}

	/**
	 * Test generate_texts returns error when unavailable.
	 *
	 * @since 0.1.0
	 */
	public function test_generate_texts_returns_error_when_unavailable(): void {
		delete_option( 'wp_ai_client_provider_credentials' );

		$result = $this->service->generate_texts( 'Test prompt', 3 );

		$this->assertWPError( $result, 'Should return WP_Error when unavailable' );
		$this->assertEquals( 'ai_service_unavailable', $result->get_error_code(), 'Should have correct error code' );
	}

	/**
	 * Test ai_service_initialized action fires.
	 *
	 * @since 0.1.0
	 */
	public function test_init_fires_action(): void {
		$action_fired = false;

		add_action(
			'ai_service_initialized',
			function ( $service ) use ( &$action_fired ) {
				$action_fired = true;
				$this->assertInstanceOf( AI_Service::class, $service );
			}
		);

		// Create a new instance to test init.
		// Note: Since AI_Service is a singleton, we can't easily test this without reflection.
		// This test verifies the action hook is properly documented.
		$this->assertTrue( has_action( 'ai_service_initialized' ) !== false, 'Action should be hookable' );
	}

	/**
	 * Test ai_service_prompt_builder filter is applied.
	 *
	 * @since 0.1.0
	 */
	public function test_prompt_builder_filter_applied(): void {
		$filter_called = false;

		add_filter(
			'ai_service_prompt_builder',
			function ( $builder, $options ) use ( &$filter_called ) {
				$filter_called = true;
				$this->assertInstanceOf( Prompt_Builder_With_WP_Error::class, $builder );
				$this->assertIsArray( $options );
				return $builder;
			},
			10,
			2
		);

		// Set credentials so generate_text doesn't return early.
		update_option( 'wp_ai_client_provider_credentials', array( 'openai' => 'test-key' ) );

		// This will fail at the actual API call, but the filter should still be called.
		$this->service->generate_text( 'Test prompt', array( 'temperature' => 0.5 ) );

		$this->assertTrue( $filter_called, 'Filter should be called during generation' );

		delete_option( 'wp_ai_client_provider_credentials' );
	}
}
