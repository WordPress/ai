<?php
/**
 * Tests for the Feature_Registry class.
 *
 * @package WordPress\AI\Tests\Includes
 */

namespace WordPress\AI\Tests\Integration\Includes;

use WordPress\AI\Feature_Registry;
use WordPress\AI\Feature_Loader;
use WordPress\AI\Abstracts\Abstract_Feature;
use WP_UnitTestCase;

/**
 * Test feature for registry tests.
 *
 * @since 0.1.0
 */
class Test_Feature extends Abstract_Feature {
	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 */
	public function __construct() {
		$this->id          = 'test-feature';
		$this->label       = 'Test Feature';
		$this->description = 'A test feature for unit testing';
	}

	/**
	 * Registers the feature.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		// No-op for testing.
	}
}

/**
 * Feature_Registry test case.
 *
 * @since 0.1.0
 */
class Feature_Registry_Test extends WP_UnitTestCase {
	/**
	 * Feature registry instance.
	 *
	 * @var Feature_Registry
	 */
	private $registry;

	/**
	 * Setup test case.
	 *
	 * @since 0.1.0
	 */
	public function setUp(): void {
		parent::setUp();
		$this->registry = new Feature_Registry();
	}

	/**
	 * Test registering a feature.
	 *
	 * @since 0.1.0
	 */
	public function test_register_feature() {
		$feature = new Test_Feature();
		$result  = $this->registry->register_feature( $feature );

		$this->assertTrue( $result, 'Feature should register successfully' );
		$this->assertTrue( $this->registry->has_feature( 'test-feature' ), 'Feature should exist in registry' );
	}

	/**
	 * Test registering duplicate feature fails.
	 *
	 * @since 0.1.0
	 */
	public function test_register_duplicate_feature_fails() {
		$feature = new Test_Feature();
		$this->registry->register_feature( $feature );
		$result = $this->registry->register_feature( $feature );

		$this->assertFalse( $result, 'Duplicate feature registration should fail' );
	}

	/**
	 * Test getting a registered feature.
	 *
	 * @since 0.1.0
	 */
	public function test_get_feature() {
		$feature = new Test_Feature();
		$this->registry->register_feature( $feature );
		$retrieved = $this->registry->get_feature( 'test-feature' );

		$this->assertSame( $feature, $retrieved, 'Should retrieve the same feature instance' );
	}

	/**
	 * Test getting non-existent feature returns null.
	 *
	 * @since 0.1.0
	 */
	public function test_get_nonexistent_feature_returns_null() {
		$retrieved = $this->registry->get_feature( 'nonexistent-feature' );

		$this->assertNull( $retrieved, 'Non-existent feature should return null' );
	}

	/**
	 * Test getting all features.
	 *
	 * @since 0.1.0
	 */
	public function test_get_all_features() {
		$feature1 = new Test_Feature();
		$this->registry->register_feature( $feature1 );

		$features = $this->registry->get_all_features();

		$this->assertIsArray( $features, 'get_all_features should return an array' );
		$this->assertCount( 1, $features, 'Should have one feature' );
		$this->assertArrayHasKey( 'test-feature', $features, 'Features array should contain registered feature' );
		$this->assertSame( $feature1, $features['test-feature'], 'Should return same instance' );
	}

	/**
	 * Test has_feature returns true for existing feature.
	 *
	 * @since 0.1.0
	 */
	public function test_has_feature_returns_true_for_existing_feature() {
		$feature = new Test_Feature();
		$this->registry->register_feature( $feature );

		$this->assertTrue( $this->registry->has_feature( 'test-feature' ), 'Should find existing feature' );
	}

	/**
	 * Test has_feature returns false for non-existent feature.
	 *
	 * @since 0.1.0
	 */
	public function test_has_feature_returns_false_for_nonexistent_feature() {
		$this->assertFalse( $this->registry->has_feature( 'nonexistent-feature' ), 'Should not find non-existent feature' );
	}

	/**
	 * Test feature initialization.
	 *
	 * @since 0.1.0
	 */
	public function test_initialize_features() {
		$feature = new Test_Feature();
		$this->registry->register_feature( $feature );

		$loader = new Feature_Loader( $this->registry );
		$loader->initialize_features();

		$this->assertTrue( $loader->is_initialized(), 'Loader should be marked as initialized' );
	}

	/**
	 * Test that disabled features are not initialized.
	 *
	 * @since 0.1.0
	 */
	public function test_disabled_features_not_initialized() {
		add_filter(
			'ai_feature_enabled',
			function ( $enabled, $feature_id ) {
				if ( 'test-feature' === $feature_id ) {
					return false;
				}
				return $enabled;
			},
			10,
			2
		);

		$feature = new Test_Feature();
		$this->registry->register_feature( $feature );

		$loader = new Feature_Loader( $this->registry );
		$loader->initialize_features();

		$this->assertFalse( $feature->is_enabled(), 'Feature should be disabled' );
	}
}