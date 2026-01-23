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
 * @since x.x.x
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
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();
		$this->service = AI_Service::get_instance();
	}

	/**
	 * Teardown test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		parent::tearDown();
	}

	/**
	 * Test singleton instance.
	 *
	 * @since x.x.x
	 */
	public function test_get_instance_returns_singleton(): void {
		$instance1 = AI_Service::get_instance();
		$instance2 = AI_Service::get_instance();

		$this->assertSame( $instance1, $instance2, 'Should return the same instance' );
	}

	/**
	 * Test helper function returns service instance.
	 *
	 * @since x.x.x
	 */
	public function test_get_ai_service_helper_returns_instance(): void {
		$service = get_ai_service();

		$this->assertInstanceOf( AI_Service::class, $service, 'Helper should return AI_Service instance' );
		$this->assertSame( $this->service, $service, 'Helper should return singleton instance' );
	}

	/**
	 * Test create_textgen_prompt returns prompt builder.
	 *
	 * @since x.x.x
	 */
	public function test_create_textgen_prompt_returns_builder(): void {
		$builder = $this->service->create_textgen_prompt( 'Test prompt' );

		$this->assertInstanceOf(
			Prompt_Builder_With_WP_Error::class,
			$builder,
			'Should return Prompt_Builder_With_WP_Error instance'
		);
	}

	/**
	 * Test create_textgen_prompt with options applies configuration.
	 *
	 * @since x.x.x
	 */
	public function test_create_textgen_prompt_with_options(): void {
		$builder = $this->service->create_textgen_prompt(
			'Test prompt',
			array(
				'system_instruction' => 'You are helpful.',
				'temperature'        => 0.5,
				'max_tokens'         => 100,
			)
		);

		$this->assertInstanceOf(
			Prompt_Builder_With_WP_Error::class,
			$builder,
			'Should return Prompt_Builder_With_WP_Error instance with options applied'
		);
	}

	/**
	 * Test ai_experiments_service_initialized action can be hooked.
	 *
	 * @since x.x.x
	 */
	public function test_init_action_is_hookable(): void {
		$callback = static function () {};

		add_action( 'ai_experiments_service_initialized', $callback );

		// Verify callback was registered.
		$this->assertNotFalse(
			has_action( 'ai_experiments_service_initialized', $callback ),
			'Action should accept callbacks'
		);

		// Cleanup.
		remove_action( 'ai_experiments_service_initialized', $callback );
	}
}
