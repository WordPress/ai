<?php
/**
 * Tests for the Feature_Registry class.
 *
 * @package WordPress\AI\Tests
 */

namespace WordPress\AI\Tests;

use WordPress\AI\Feature_Registry;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Interfaces\Conditional_Feature;
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
 * Conditional test feature for registry tests.
 *
 * @since 0.1.0
 */
class Test_Conditional_Feature extends Abstract_Feature implements Conditional_Feature {
	/**
	 * Whether requirements are met.
	 *
	 * @var bool
	 */
	private $requirements_met = true;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $requirements_met Whether requirements should be met.
	 */
	public function __construct( $requirements_met = true ) {
		$this->id               = 'test-conditional-feature';
		$this->label            = 'Test Conditional Feature';
		$this->description      = 'A conditional test feature';
		$this->requirements_met = $requirements_met;
	}

	/**
	 * Checks if requirements are met.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True if requirements met.
	 */
	public function meets_requirements(): bool {
		return $this->requirements_met;
	}

	/**
	 * Gets requirements message.
	 *
	 * @since 0.1.0
	 *
	 * @return string Requirements message.
	 */
	public function get_requirements_message(): string {
		return 'Test requirements not met';
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
		$this->registry = Feature_Registry::instance();
	}

	/**
	 * Test that registry returns singleton.
	 *
	 * @since 0.1.0
	 */
	public function test_instance_returns_singleton() {
		$instance1 = Feature_Registry::instance();
		$instance2 = Feature_Registry::instance();

		$this->assertSame( $instance1, $instance2, 'Feature_Registry should return the same singleton instance' );
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
		$feature = new Test_Feature();
		$this->registry->register_feature( $feature );
		$features = $this->registry->get_all_features();

		$this->assertIsArray( $features, 'get_all_features should return an array' );
		$this->assertArrayHasKey( 'test-feature', $features, 'Features array should contain registered feature' );
	}

	/**
	 * Test feature initialization.
	 *
	 * @since 0.1.0
	 */
	public function test_initialize_features() {
		$feature = new Test_Feature();
		$this->registry->register_feature( $feature );
		$this->registry->initialize_features();

		$this->assertTrue( $this->registry->is_initialized(), 'Registry should be marked as initialized' );
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
		$this->registry->initialize_features();

		$this->assertFalse( $feature->is_enabled(), 'Feature should be disabled' );
	}

	/**
	 * Test that conditional features with unmet requirements are not initialized.
	 *
	 * @since 0.1.0
	 */
	public function test_conditional_features_with_unmet_requirements_not_initialized() {
		$feature = new Test_Conditional_Feature( false );
		$this->registry->register_feature( $feature );

		$this->assertFalse( $feature->meets_requirements(), 'Feature requirements should not be met' );
	}

	/**
	 * Test that conditional features with met requirements are initialized.
	 *
	 * @since 0.1.0
	 */
	public function test_conditional_features_with_met_requirements_initialized() {
		$feature = new Test_Conditional_Feature( true );
		$this->registry->register_feature( $feature );

		$this->assertTrue( $feature->meets_requirements(), 'Feature requirements should be met' );
	}
}