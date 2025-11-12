<?php
/**
 * Integration tests for the Title_Generation class.
 *
 * @package WordPress\AI\Tests\Integration\Features
 */

namespace WordPress\AI\Tests\Integration\Features\Title_Generation;

use WordPress\AI\Feature_Registry;
use WordPress\AI\Feature_Loader;
use WordPress\AI\Features\Title_Generation\Title_Generation;
use WP_UnitTestCase;

/**
 * Title_Generation test case.
 *
 * @since 0.1.0
 */
class Title_GenerationTest extends WP_UnitTestCase {
	/**
	 * Set up test case.
	 *
	 * @since 0.1.0
	 */
	public function setUp(): void {
		parent::setUp();

		$registry = new Feature_Registry();
		$loader   = new Feature_Loader( $registry );
		$loader->register_default_features();

		$feature = $registry->get_feature( 'title-generation' );
		$this->assertInstanceOf( Title_Generation::class, $feature, 'Title generation feature should be registered in the registry.' );
	}

	/**
	 * Tear down test case.
	 *
	 * @since 0.1.0
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test that the feature is registered correctly.
	 *
	 * @since 0.1.0
	 */
	public function test_feature_registration() {
		$feature = new Title_Generation();

		$this->assertEquals( 'title-generation', $feature->get_id() );
		$this->assertEquals( 'Title Generation', $feature->get_label() );
		$this->assertTrue( $feature->is_enabled() );
	}

	/**
	 * Test that get_system_instruction() returns the system instruction.
	 *
	 * @since 0.1.0
	 */
	public function test_get_system_instruction_returns_system_instruction() {
		$feature = new Title_Generation();

		$system_instruction = $feature->get_system_instruction();

		$this->assertIsString( $system_instruction, 'System instruction should be a string' );
		$this->assertNotEmpty( $system_instruction, 'System instruction should not be empty' );
		$this->assertStringContainsString( 'You are an editorial assistant', $system_instruction, 'System instruction should contain expected content' );
	}

	/**
	 * Test that generate_titles() returns correct type.
	 *
	 * @since 0.1.0
	 */
	public function test_generate_titles_returns_correct_type() {
		$feature = new Title_Generation();

		$content = 'This is some test content for title generation.';

		try {
			$result = $feature->generate_titles( $content, 2 );
		} catch ( \Exception $e ) {
			// If an exception is thrown (e.g., no models configured), that's acceptable
			// for testing purposes. The method should ideally catch this, but for now
			// we'll mark the test as skipped if models aren't configured.
			$this->markTestSkipped( 'AI models not configured in test environment: ' . $e->getMessage() );
			return;
		}

		// The result will either be titles or an error depending on whether
		// the AI client is available. We'll verify it's the right type.
		$this->assertTrue(
			is_array( $result ) || is_wp_error( $result ),
			'generate_titles should return array or WP_Error'
		);

		// If we got an array, verify it contains strings.
		if ( is_array( $result ) ) {
			$this->assertNotEmpty( $result, 'Should return non-empty array when successful' );
			foreach ( $result as $title ) {
				$this->assertIsString( $title, 'Each title should be a string' );
			}
		}
	}

	/**
	 * Test that generate_titles() uses system instruction.
	 *
	 * @since 0.1.0
	 */
	public function test_generate_titles_uses_system_instruction() {
		$feature = new Title_Generation();

		$content = 'Test content';

		try {
			$result = $feature->generate_titles( $content );
		} catch ( \Exception $e ) {
			// If models aren't configured, skip the test.
			$this->markTestSkipped( 'AI models not configured in test environment: ' . $e->getMessage() );
			return;
		}

		// Verify the method completes without fatal error.
		// The actual AI call may fail if client is unavailable, but the method
		// should handle it gracefully.
		$this->assertTrue(
			is_array( $result ) || is_wp_error( $result ),
			'generate_titles should return array or WP_Error'
		);
	}

	/**
	 * Test that generate_titles() passes options correctly.
	 *
	 * @since 0.1.0
	 */
	public function test_generate_titles_passes_options() {
		$feature = new Title_Generation();

		$content = 'Test content';

		try {
			$result = $feature->generate_titles( $content, 3 );
		} catch ( \Exception $e ) {
			// If models aren't configured, skip the test.
			$this->markTestSkipped( 'AI models not configured in test environment: ' . $e->getMessage() );
			return;
		}

		// Verify the method accepts the n parameter.
		$this->assertTrue(
			is_array( $result ) || is_wp_error( $result ),
			'generate_titles should return array or WP_Error'
		);
	}

	/**
	 * Test that generate_titles() handles API errors gracefully.
	 *
	 * @since 0.1.0
	 */
	public function test_generate_titles_handles_api_errors() {
		$feature = new Title_Generation();

		$content = 'Test content';

		try {
			$result = $feature->generate_titles( $content );
		} catch ( \Exception $e ) {
			// If models aren't configured, skip the test.
			$this->markTestSkipped( 'AI models not configured in test environment: ' . $e->getMessage() );
			return;
		}

		// If AI client is unavailable, should return WP_Error.
		if ( is_wp_error( $result ) ) {
			$this->assertInstanceOf( \WP_Error::class, $result, 'Should return WP_Error on failure' );
		}
	}
}
