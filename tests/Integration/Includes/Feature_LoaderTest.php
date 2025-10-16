<?php
/**
 * Tests for the Feature_Loader class.
 *
 * @package WordPress\AI\Tests\Integration\Includes
 */

namespace WordPress\AI\Tests\Integration\Includes;

use WordPress\AI\Feature_Registry;
use WordPress\AI\Feature_Loader;
use WordPress\AI\Abstracts\Abstract_Feature;
use WP_UnitTestCase;

/**
 * Test feature for loader tests.
 *
 * @since 0.1.0
 */
class Mock_Feature extends Abstract_Feature {
	/**
	 * Tracks if register was called.
	 *
	 * @var bool
	 */
	public $register_called = false;

	/**
	 * Loads feature metadata.
	 *
	 * @since 0.1.0
	 *
	 * @return array{id: string, label: string, description: string} Feature metadata.
	 */
	protected function load_feature_metadata(): array {
		return array(
			'id'          => 'mock-feature',
			'label'       => 'Mock Feature',
			'description' => 'A mock feature for testing',
		);
	}

	/**
	 * Registers the feature.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		$this->register_called = true;
	}
}

/**
 * Feature_Loader test case.
 *
 * @since 0.1.0
 */
class Feature_LoaderTest extends WP_UnitTestCase {
	/**
	 * Feature registry instance.
	 *
	 * @var Feature_Registry
	 */
	private $registry;

	/**
	 * Feature loader instance.
	 *
	 * @var Feature_Loader
	 */
	private $loader;

	/**
	 * Setup test case.
	 *
	 * @since 0.1.0
	 */
	public function setUp(): void {
		parent::setUp();
		$this->registry = new Feature_Registry();
		$this->loader   = new Feature_Loader( $this->registry );
	}

	/**
	 * Test register_default_features registers Example_Feature.
	 *
	 * @since 0.1.0
	 */
	public function test_register_default_features() {
		$this->loader->register_default_features();

		$this->assertTrue(
			$this->registry->has_feature( 'example-feature' ),
			'Example feature should be registered'
		);

		$feature = $this->registry->get_feature( 'example-feature' );
		$this->assertNotNull( $feature, 'Example feature should exist' );
		$this->assertEquals( 'example-feature', $feature->get_id() );
	}

	/**
	 * Test ai_register_features action hook fires.
	 *
	 * @since 0.1.0
	 */
	public function test_ai_register_features_hook_fires() {
		$hook_fired = false;
		$passed_registry = null;

		add_action(
			'ai_register_features',
			function ( $registry ) use ( &$hook_fired, &$passed_registry ) {
				$hook_fired = true;
				$passed_registry = $registry;
			}
		);

		$this->loader->register_default_features();

		$this->assertTrue( $hook_fired, 'ai_register_features hook should fire' );
		$this->assertSame(
			$this->registry,
			$passed_registry,
			'Registry should be passed to hook'
		);
	}

	/**
	 * Test third-party features can be registered via hook.
	 *
	 * @since 0.1.0
	 */
	public function test_third_party_feature_registration() {
		add_action(
			'ai_register_features',
			function ( $registry ) {
				$custom_feature = new Mock_Feature();
				$registry->register_feature( $custom_feature );
			}
		);

		$this->loader->register_default_features();

		$this->assertTrue(
			$this->registry->has_feature( 'mock-feature' ),
			'Custom feature should be registered via hook'
		);
	}

	/**
	 * Test initialize_features calls register on enabled features.
	 *
	 * @since 0.1.0
	 */
	public function test_initialize_features_calls_register() {
		$feature = new Mock_Feature();
		$this->registry->register_feature( $feature );

		$this->loader->initialize_features();

		$this->assertTrue(
			$feature->register_called,
			'Feature register() should be called'
		);
	}

	/**
	 * Test initialize_features doesn't initialize twice.
	 *
	 * @since 0.1.0
	 */
	public function test_initialize_features_prevents_double_initialization() {
		$feature = new Mock_Feature();
		$this->registry->register_feature( $feature );

		$this->loader->initialize_features();
		$this->assertTrue( $this->loader->is_initialized(), 'Should be initialized' );

		// Reset the flag to track second call.
		$feature->register_called = false;

		// Try to initialize again.
		$this->loader->initialize_features();

		$this->assertFalse(
			$feature->register_called,
			'Feature register() should not be called twice'
		);
	}

	/**
	 * Test ai_features_initialized action fires.
	 *
	 * @since 0.1.0
	 */
	public function test_ai_features_initialized_hook_fires() {
		$hook_fired = false;

		add_action(
			'ai_features_initialized',
			function () use ( &$hook_fired ) {
				$hook_fired = true;
			}
		);

		$feature = new Mock_Feature();
		$this->registry->register_feature( $feature );

		$this->loader->initialize_features();

		$this->assertTrue( $hook_fired, 'ai_features_initialized hook should fire' );
	}

	/**
	 * Test ai_features_initialized fires before is_initialized is true.
	 *
	 * @since 0.1.0
	 */
	public function test_ai_features_initialized_fires_before_initialized_flag() {
		$initialized_during_hook = null;

		add_action(
			'ai_features_initialized',
			function () use ( &$initialized_during_hook ) {
				$initialized_during_hook = $this->loader->is_initialized();
			}
		);

		$this->loader->initialize_features();

		$this->assertFalse(
			$initialized_during_hook,
			'Loader should not be marked initialized during hook'
		);
		$this->assertTrue(
			$this->loader->is_initialized(),
			'Loader should be initialized after hook'
		);
	}

	/**
	 * Test disabled features are skipped during initialization.
	 *
	 * @since 0.1.0
	 */
	public function test_disabled_features_are_skipped() {
		$feature = new Mock_Feature();
		$this->registry->register_feature( $feature );

		// Disable the feature.
		add_filter(
			'ai_feature_enabled',
			function ( $enabled, $feature_id ) {
				if ( 'mock-feature' === $feature_id ) {
					return false;
				}
				return $enabled;
			},
			10,
			2
		);

		$this->loader->initialize_features();

		$this->assertFalse(
			$feature->register_called,
			'Disabled feature register() should not be called'
		);
	}
}