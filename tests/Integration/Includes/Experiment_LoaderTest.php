<?php
/**
 * Tests for the Loader class.
 *
 * @package WordPress\AI\Tests\Integration\Includes
 */

namespace WordPress\AI\Tests\Integration\Includes;

use WP_UnitTestCase;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Features\Loader;
use WordPress\AI\Features\Registry;

/**
 * Test experiment for loader tests.
 *
 * @since 0.1.0
 */
class Mock_Experiment extends Abstract_Feature {
	/**
	 * Tracks if register was called.
	 *
	 * @var bool
	 */
	public $register_called = false;

	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'mock-experiment';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => 'Mock Experiment',
			'description' => 'A mock experiment for testing',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		$this->register_called = true;
	}
}

/**
 * Experiment that throws during instantiation.
 *
 * @since 0.1.0
 */
class Throwing_Experiment extends Abstract_Feature {
	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'throwing-experiment';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		throw new \RuntimeException( 'Test exception' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {}
}

/**
 * Loader test case.
 *
 * @since 0.1.0
 */
class LoaderTest extends WP_UnitTestCase {
	/**
	 * Experiment registry instance.
	 *
	 * @var \WordPress\AI\Features\Registry
	 */
	private $registry;

	/**
	 * Experiment loader instance.
	 *
	 * @var \WordPress\AI\Features\Loader
	 */
	private $loader;

	/**
	 * Setup test case.
	 *
	 * @since 0.1.0
	 */
	public function setUp(): void {
		parent::setUp();

		// Set up mock AI credentials so has_ai_credentials() returns true.
		update_option( 'wp_ai_client_provider_credentials', array( 'openai' => 'test-api-key' ) );

		// Mock has_valid_ai_credentials to return true for tests.
		add_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );

		$this->registry = new Registry();
		$this->loader   = new Loader( $this->registry );
	}

	/**
	 * Tear down test case.
	 *
	 * @since 0.1.0
	 */
	public function tearDown(): void {
		delete_option( 'wp_ai_client_provider_credentials' );
		remove_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );
		parent::tearDown();
	}

	/**
	 * Test register_features registers default experiments.
	 *
	 * @since 0.1.0
	 */
	public function test_register_features() {
		// Access the protected method to register features.
		$reflection = new \ReflectionClass( Loader::class );
		$method     = $reflection->getMethod( 'register_features' );
		$method->setAccessible( true );
		$method->invoke( $this->loader );

		$this->assertTrue(
			$this->registry->has_feature( 'abilities-explorer' ),
			'Abilities explorer experiment should be registered'
		);
		$this->assertTrue(
			$this->registry->has_feature( 'alt-text-generation' ),
			'Alt text generation experiment should be registered'
		);
		$this->assertTrue(
			$this->registry->has_feature( 'excerpt-generation' ),
			'Excerpt generation experiment should be registered'
		);
		$this->assertTrue(
			$this->registry->has_feature( 'image-generation' ),
			'Image generation experiment should be registered'
		);
		$this->assertTrue(
			$this->registry->has_feature( 'review-notes' ),
			'Review Notes experiment should be registered'
		);
		$this->assertTrue(
			$this->registry->has_feature( 'summarization' ),
			'Summarization experiment should be registered'
		);
		$this->assertTrue(
			$this->registry->has_feature( 'title-generation' ),
			'Title generation experiment should be registered'
		);

		$abilities_explorer_experiment = $this->registry->get_feature( 'abilities-explorer' );
		$this->assertNotNull( $abilities_explorer_experiment, 'Abilities explorer experiment should exist' );
		$this->assertEquals( 'abilities-explorer', $abilities_explorer_experiment->get_id() );
		$this->assertEquals( Experiment_Category::ADMIN, $abilities_explorer_experiment->get_category() );

		$alt_text_generation_experiment = $this->registry->get_feature( 'alt-text-generation' );
		$this->assertNotNull( $alt_text_generation_experiment, 'Alt text generation experiment should exist' );
		$this->assertEquals( 'alt-text-generation', $alt_text_generation_experiment->get_id() );
		$this->assertEquals( Experiment_Category::EDITOR, $alt_text_generation_experiment->get_category() );

		$excerpt_experiment = $this->registry->get_feature( 'excerpt-generation' );
		$this->assertNotNull( $excerpt_experiment, 'Excerpt generation experiment should exist' );
		$this->assertEquals( 'excerpt-generation', $excerpt_experiment->get_id() );
		$this->assertEquals( Experiment_Category::EDITOR, $excerpt_experiment->get_category() );

		$image_feature = $this->registry->get_feature( 'image-generation' );
		$this->assertNotNull( $image_feature, 'Image generation experiment should exist' );
		$this->assertEquals( 'image-generation', $image_feature->get_id() );
		$this->assertEquals( Experiment_Category::OTHER, $image_feature->get_category() );

		$review_notes_experiment = $this->registry->get_feature( 'review-notes' );
		$this->assertNotNull( $review_notes_experiment, 'Review Notes experiment should exist' );
		$this->assertEquals( 'review-notes', $review_notes_experiment->get_id() );

		$summarization_experiment = $this->registry->get_feature( 'summarization' );
		$this->assertNotNull( $summarization_experiment, 'Summarization experiment should exist' );
		$this->assertEquals( 'summarization', $summarization_experiment->get_id() );
		$this->assertEquals( Experiment_Category::EDITOR, $summarization_experiment->get_category() );

		$title_experiment = $this->registry->get_feature( 'title-generation' );
		$this->assertNotNull( $title_experiment, 'Title generation experiment should exist' );
		$this->assertEquals( 'title-generation', $title_experiment->get_id() );
		$this->assertEquals( Experiment_Category::EDITOR, $title_experiment->get_category() );
	}

	/**
	 * Test wpai_register_experiments action hook fires.
	 *
	 * @since 0.1.0
	 */
	public function test_wpai_register_features_hook_fires() {
		$hook_fired      = false;
		$passed_registry = null;

		add_action(
			'wpai_register_features',
			static function ( $registry ) use ( &$hook_fired, &$passed_registry ) {
				$hook_fired      = true;
				$passed_registry = $registry;
			}
		);

		$this->loader->init();

		$this->assertTrue( $hook_fired, 'wpai_register_features hook should fire' );
		$this->assertSame(
			$this->registry,
			$passed_registry,
			'Registry should be passed to hook'
		);
	}

	/**
	 * Test third-party experiments can be registered via hook.
	 *
	 * @since 0.1.0
	 */
	public function test_third_party_experiment_registration() {
		add_action(
			'wpai_register_features',
			static function ( $registry ) {
				$custom_experiment = new Mock_Experiment();
				$registry->register_feature( $custom_experiment );
			}
		);

		$this->loader->init();

		$this->assertTrue(
			$this->registry->has_feature( 'mock-experiment' ),
			'Custom experiment should be registered via hook'
		);
	}

	/**
	 * Test initialize_features calls register on enabled experiments.
	 *
	 * @since 0.1.0
	 */
	public function test_initialize_features_calls_register() {
		// Enable experiments globally and individually.
		update_option( 'wpai_features_enabled', true );
		update_option( 'wpai_feature_mock-experiment_enabled', true );

		$experiment = new Mock_Experiment();
		$this->registry->register_feature( $experiment );

		$this->invoke_initialize_features();

		$this->assertTrue(
			$experiment->register_called,
			'Experiment register() should be called'
		);

		// Cleanup.
		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_mock-experiment_enabled' );
	}

	/**
	 * Test initialize_features doesn't initialize twice.
	 *
	 * @since 0.1.0
	 */
	public function test_initialize_features_prevents_double_initialization() {
		$experiment = new Mock_Experiment();
		$this->registry->register_feature( $experiment );

		$this->invoke_initialize_features();

		// Reset the flag to track second call.
		$experiment->register_called = false;

		// Try to initialize again.
		$this->invoke_initialize_features();

		$this->assertFalse(
			$experiment->register_called,
			'Experiment register() should not be called twice'
		);
	}

	/**
	 * Test wpai_features_initialized action fires.
	 *
	 * @since 0.1.0
	 */
	public function test_wpai_features_initialized_hook_fires() {
		$hook_fired = false;

		add_action(
			'wpai_features_initialized',
			static function () use ( &$hook_fired ) {
				$hook_fired = true;
			}
		);

		$experiment = new Mock_Experiment();
		$this->registry->register_feature( $experiment );

		$this->invoke_initialize_features();

		$this->assertTrue( $hook_fired, 'wpai_features_initialized hook should fire' );
	}

	/**
	 * Test wpai_features_initialized fires before is_initialized is true.
	 *
	 * @since 0.1.0
	 */
	public function test_wpai_features_initialized_fires_before_initialized_flag() {
		$initialized_during_hook = null;

		$reflection = new \ReflectionClass( $this->loader );
		$property   = $reflection->getProperty( 'initialized' );
		$property->setAccessible( true );

		add_action(
			'wpai_features_initialized',
			function () use ( &$initialized_during_hook, $property ) {
				$initialized_during_hook = $property->getValue( $this->loader );
				$this->assertFalse(
					$initialized_during_hook,
					'Loader should not be marked initialized during wpai_features_initialized hook'
				);
			}
		);

		$this->invoke_initialize_features();

		$this->assertFalse(
			$initialized_during_hook,
			'Loader should not be marked initialized during hook'
		);
	}

	/**
	 * Test disabled experiments are skipped during initialization.
	 *
	 * @since 0.1.0
	 */
	public function test_disabled_experiments_are_skipped() {
		$experiment = new Mock_Experiment();
		$this->registry->register_feature( $experiment );

		// Disable the experiment.
		add_filter( 'wpai_feature_mock-experiment_enabled', '__return_false' );

		$this->invoke_initialize_features();

		$this->assertFalse(
			$experiment->register_called,
			'Disabled experiment register() should not be called'
		);
	}

	/**
	 * Test non-existent experiment class triggers _doing_it_wrong().
	 */
	public function test_nonexistent_class_triggers_doing_it_wrong() {
		$this->setExpectedIncorrectUsage( 'WordPress\AI\Features\Loader::get_default_features' );

		add_filter(
			'wpai_default_feature_classes',
			static function () {
				return array( 'NonExistent\Class' );
			}
		);

		$this->loader->init();
	}

	/**
	 * Test invalid interface triggers _doing_it_wrong().
	 */
	public function test_invalid_interface_triggers_doing_it_wrong() {
		$this->setExpectedIncorrectUsage( 'WordPress\AI\Features\Loader::get_default_features' );

		add_filter(
			'wpai_default_feature_classes',
			static function () {
				return array( \stdClass::class );
			}
		);

		$this->loader->init();
	}

	/**
	 * Test instantiation failure triggers _doing_it_wrong().
	 *
	 * @since 0.1.0
	 */
	public function test_instantiation_failure_triggers_doing_it_wrong() {
		$this->setExpectedIncorrectUsage( 'WordPress\AI\Features\Loader::get_default_features' );

		add_filter(
			'wpai_default_feature_classes',
			static function () {
				return array( Throwing_Experiment::class );
			}
		);

		$this->loader->init();
	}

	/**
	 * Calls the private Loader::initialize_features method via reflection.
	 */
	private function invoke_initialize_features(): void {
		$reflection = new \ReflectionClass( Loader::class );
		$method     = $reflection->getMethod( 'initialize_features' );
		$method->setAccessible( true );
		$method->invoke( $this->loader );
	}
}
