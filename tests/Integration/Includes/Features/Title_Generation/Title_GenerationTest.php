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
}
