<?php
/**
 * Tests for the deprecated AI_Service class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Services
 */

namespace WordPress\AI\Tests\Integration\Includes\Services;

use ReflectionProperty;
use WP_UnitTestCase;
use WordPress\AI\Services\AI_Service;

use function WordPress\AI\get_ai_service;

/**
 * AI_Service test case.
 *
 * `AI_Service` and `get_ai_service()` are deprecated and scheduled for removal in
 * the next major release. These tests assert that the deprecated surface still
 * behaves as documented so third-party code keeps working through the deprecation
 * window. Each `setExpectedDeprecated()` call asserts in both directions: the test
 * fails if the notice is missing and if an unexpected notice is triggered.
 *
 * @since 0.2.1
 */
class AI_Service_Test extends WP_UnitTestCase {

	/**
	 * Setup test case.
	 *
	 * @since 0.2.1
	 */
	public function setUp(): void {
		parent::setUp();
		$this->reset_instance();
	}

	/**
	 * Teardown test case.
	 *
	 * @since 0.2.1
	 */
	public function tearDown(): void {
		$this->reset_instance();
		parent::tearDown();
	}

	/**
	 * Resets the singleton instance.
	 *
	 * The `_deprecated_class()` notice fires from the constructor, which the
	 * singleton only reaches once per process. Clearing the instance around every
	 * test keeps that notice deterministic instead of landing on whichever test
	 * happens to run first.
	 *
	 * @since 1.3.0
	 */
	private function reset_instance(): void {
		$instance = new ReflectionProperty( AI_Service::class, 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );
	}

	/**
	 * Test singleton instance.
	 *
	 * @since 0.2.1
	 */
	public function test_get_instance_returns_singleton(): void {
		$this->setExpectedDeprecated( AI_Service::class );

		$instance1 = AI_Service::get_instance();
		$instance2 = AI_Service::get_instance();

		$this->assertSame( $instance1, $instance2, 'Should return the same instance' );
	}

	/**
	 * Test helper function returns service instance.
	 *
	 * @since 0.2.1
	 */
	public function test_get_ai_service_helper_returns_instance(): void {
		$this->setExpectedDeprecated( 'WordPress\AI\get_ai_service' );
		$this->setExpectedDeprecated( AI_Service::class );

		$service = get_ai_service();

		$this->assertInstanceOf( AI_Service::class, $service, 'Helper should return AI_Service instance' );
		$this->assertSame( AI_Service::get_instance(), $service, 'Helper should return singleton instance' );
	}

	/**
	 * Test create_textgen_prompt returns prompt builder.
	 *
	 * @since 0.2.1
	 */
	public function test_create_textgen_prompt_returns_builder(): void {
		$this->setExpectedDeprecated( AI_Service::class );

		$builder = AI_Service::get_instance()->create_textgen_prompt( 'Test prompt' );

		$this->assertInstanceOf(
			\WP_AI_Client_Prompt_Builder::class,
			$builder,
			'Should return WP_AI_Client_Prompt_Builder instance'
		);
	}

	/**
	 * Test create_textgen_prompt with options applies configuration.
	 *
	 * @since 0.2.1
	 */
	public function test_create_textgen_prompt_with_options(): void {
		$this->setExpectedDeprecated( AI_Service::class );

		$builder = AI_Service::get_instance()->create_textgen_prompt(
			'Test prompt',
			array(
				'system_instruction' => 'You are helpful.',
				'temperature'        => 0.5,
				'max_tokens'         => 100,
			)
		);

		$this->assertInstanceOf(
			\WP_AI_Client_Prompt_Builder::class,
			$builder,
			'Should return WP_AI_Client_Prompt_Builder instance with options applied'
		);
	}
}
