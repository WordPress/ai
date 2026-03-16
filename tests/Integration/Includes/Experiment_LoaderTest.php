<?php
/**
 * Tests for the Experiment_Loader class.
 *
 * @package WordPress\AI\Tests\Integration\Includes
 */

namespace WordPress\AI\Tests\Integration\Includes;

use WP_UnitTestCase;
use WordPress\AI\Abstracts\Abstract_Experiment;
use WordPress\AI\Experiment_Category;
use WordPress\AI\Experiment_Loader;
use WordPress\AI\Experiment_Registry;

/**
 * Test experiment for loader tests.
 *
 * @since 0.1.0
 */
class Mock_Experiment extends Abstract_Experiment {
	/**
	 * Tracks if register was called.
	 *
	 * @var bool
	 */
	public $register_called = false;

	/**
	 * Loads experiment metadata.
	 *
	 * Intentionally omits the category key to exercise the fallback
	 * to Experiment_Category::OTHER in Abstract_Experiment.
	 *
	 * @since 0.1.0
	 *
	 * @return array{id: string, label: string, description: string, category: string} Experiment metadata.
	 */
	protected function load_experiment_metadata(): array {
		return array(
			'id'          => 'mock-experiment',
			'label'       => 'Mock Experiment',
			'description' => 'A mock experiment for testing',
		);
	}

	/**
	 * Registers the experiment.
	 *
	 * @since 0.1.0
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
class Throwing_Experiment extends Abstract_Experiment {
	/**
	 * Loads experiment metadata - throws to simulate failure.
	 *
	 * @since 0.1.0
	 *
	 * @return array{id: string, label: string, description: string} Experiment metadata.
	 */
	protected function load_experiment_metadata(): array {
		throw new \RuntimeException( 'Test exception' );
	}

	/**
	 * Registers the experiment.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {}
}

/**
 * Experiment_Loader test case.
 *
 * @since 0.1.0
 */
class Experiment_LoaderTest extends WP_UnitTestCase {
	/**
	 * Experiment registry instance.
	 *
	 * @var \WordPress\AI\Experiment_Registry
	 */
	private $registry;

	/**
	 * Experiment loader instance.
	 *
	 * @var \WordPress\AI\Experiment_Loader
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
		add_filter( 'ai_experiments_pre_has_valid_credentials_check', '__return_true' );

		$this->registry = new Experiment_Registry();
		$this->loader   = new Experiment_Loader( $this->registry );
	}

	/**
	 * Tear down test case.
	 *
	 * @since 0.1.0
	 */
	public function tearDown(): void {
		delete_option( 'wp_ai_client_provider_credentials' );
		remove_filter( 'ai_experiments_pre_has_valid_credentials_check', '__return_true' );
		parent::tearDown();
	}

	/**
	 * Test register_default_experiments registers default experiments.
	 *
	 * @since 0.1.0
	 */
	public function test_register_default_experiments() {
		$this->loader->register_default_experiments();

		$this->assertTrue(
			$this->registry->has_experiment( 'abilities-explorer' ),
			'Abilities explorer experiment should be registered'
		);
		$this->assertTrue(
			$this->registry->has_experiment( 'alt-text-generation' ),
			'Alt text generation experiment should be registered'
		);
		$this->assertTrue(
			$this->registry->has_experiment( 'excerpt-generation' ),
			'Excerpt generation experiment should be registered'
		);
		$this->assertTrue(
			$this->registry->has_experiment( 'image-generation' ),
			'Image generation experiment should be registered'
		);
		$this->assertTrue(
			$this->registry->has_experiment( 'review-notes' ),
			'Review Notes experiment should be registered'
		);
		$this->assertTrue(
			$this->registry->has_experiment( 'summarization' ),
			'Summarization experiment should be registered'
		);
		$this->assertTrue(
			$this->registry->has_experiment( 'title-generation' ),
			'Title generation experiment should be registered'
		);

		$abilities_explorer_experiment = $this->registry->get_experiment( 'abilities-explorer' );
		$this->assertNotNull( $abilities_explorer_experiment, 'Abilities explorer experiment should exist' );
		$this->assertEquals( 'abilities-explorer', $abilities_explorer_experiment->get_id() );
		$this->assertEquals( Experiment_Category::ADMIN, $abilities_explorer_experiment->get_category() );

		$alt_text_generation_experiment = $this->registry->get_experiment( 'alt-text-generation' );
		$this->assertNotNull( $alt_text_generation_experiment, 'Alt text generation experiment should exist' );
		$this->assertEquals( 'alt-text-generation', $alt_text_generation_experiment->get_id() );
		$this->assertEquals( Experiment_Category::EDITOR, $alt_text_generation_experiment->get_category() );

		$excerpt_experiment = $this->registry->get_experiment( 'excerpt-generation' );
		$this->assertNotNull( $excerpt_experiment, 'Excerpt generation experiment should exist' );
		$this->assertEquals( 'excerpt-generation', $excerpt_experiment->get_id() );
		$this->assertEquals( Experiment_Category::EDITOR, $excerpt_experiment->get_category() );

		$image_experiment = $this->registry->get_experiment( 'image-generation' );
		$this->assertNotNull( $image_experiment, 'Image generation experiment should exist' );
		$this->assertEquals( 'image-generation', $image_experiment->get_id() );
		$this->assertEquals( Experiment_Category::EDITOR, $image_experiment->get_category() );

		$review_notes_experiment = $this->registry->get_experiment( 'review-notes' );
		$this->assertNotNull( $review_notes_experiment, 'Review Notes experiment should exist' );
		$this->assertEquals( 'review-notes', $review_notes_experiment->get_id() );

		$summarization_experiment = $this->registry->get_experiment( 'summarization' );
		$this->assertNotNull( $summarization_experiment, 'Summarization experiment should exist' );
		$this->assertEquals( 'summarization', $summarization_experiment->get_id() );
		$this->assertEquals( Experiment_Category::EDITOR, $summarization_experiment->get_category() );

		$title_experiment = $this->registry->get_experiment( 'title-generation' );
		$this->assertNotNull( $title_experiment, 'Title generation experiment should exist' );
		$this->assertEquals( 'title-generation', $title_experiment->get_id() );
		$this->assertEquals( Experiment_Category::EDITOR, $title_experiment->get_category() );
	}

	/**
	 * Test ai_experiments_register_experiments action hook fires.
	 *
	 * @since 0.1.0
	 */
	public function test_ai_experiments_register_experiments_hook_fires() {
		$hook_fired      = false;
		$passed_registry = null;

		add_action(
			'ai_experiments_register_experiments',
			static function ( $registry ) use ( &$hook_fired, &$passed_registry ) {
				$hook_fired      = true;
				$passed_registry = $registry;
			}
		);

		$this->loader->register_default_experiments();

		$this->assertTrue( $hook_fired, 'ai_experiments_register_experiments hook should fire' );
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
			'ai_experiments_register_experiments',
			static function ( $registry ) {
				$custom_experiment = new Mock_Experiment();
				$registry->register_experiment( $custom_experiment );
			}
		);

		$this->loader->register_default_experiments();

		$this->assertTrue(
			$this->registry->has_experiment( 'mock-experiment' ),
			'Custom experiment should be registered via hook'
		);
	}

	/**
	 * Test initialize_experiments calls register on enabled experiments.
	 *
	 * @since 0.1.0
	 */
	public function test_initialize_experiments_calls_register() {
		// Enable experiments globally and individually.
		update_option( 'ai_experiments_enabled', true );
		update_option( 'ai_experiment_mock-experiment_enabled', true );

		$experiment = new Mock_Experiment();
		$this->registry->register_experiment( $experiment );

		$this->loader->initialize_experiments();

		$this->assertTrue(
			$experiment->register_called,
			'Experiment register() should be called'
		);

		// Cleanup.
		delete_option( 'ai_experiments_enabled' );
		delete_option( 'ai_experiment_mock-experiment_enabled' );
	}

	/**
	 * Test initialize_experiments doesn't initialize twice.
	 *
	 * @since 0.1.0
	 */
	public function test_initialize_experiments_prevents_double_initialization() {
		$experiment = new Mock_Experiment();
		$this->registry->register_experiment( $experiment );

		$this->loader->initialize_experiments();
		$this->assertTrue( $this->loader->is_initialized(), 'Should be initialized' );

		// Reset the flag to track second call.
		$experiment->register_called = false;

		// Try to initialize again.
		$this->loader->initialize_experiments();

		$this->assertFalse(
			$experiment->register_called,
			'Experiment register() should not be called twice'
		);
	}

	/**
	 * Test ai_experiments_initialized action fires.
	 *
	 * @since 0.1.0
	 */
	public function test_ai_experiments_initialized_hook_fires() {
		$hook_fired = false;

		add_action(
			'ai_experiments_initialized',
			static function () use ( &$hook_fired ) {
				$hook_fired = true;
			}
		);

		$experiment = new Mock_Experiment();
		$this->registry->register_experiment( $experiment );

		$this->loader->initialize_experiments();

		$this->assertTrue( $hook_fired, 'ai_experiments_initialized hook should fire' );
	}

	/**
	 * Test ai_experiments_initialized fires before is_initialized is true.
	 *
	 * @since 0.1.0
	 */
	public function test_ai_experiments_initialized_fires_before_initialized_flag() {
		$initialized_during_hook = null;

		add_action(
			'ai_experiments_initialized',
			function () use ( &$initialized_during_hook ) {
				$initialized_during_hook = $this->loader->is_initialized();
			}
		);

		$this->loader->initialize_experiments();

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
	 * Test disabled experiments are skipped during initialization.
	 *
	 * @since 0.1.0
	 */
	public function test_disabled_experiments_are_skipped() {
		$experiment = new Mock_Experiment();
		$this->registry->register_experiment( $experiment );

		// Disable the experiment.
		add_filter( 'ai_experiments_experiment_mock-experiment_enabled', '__return_false' );

		$this->loader->initialize_experiments();

		$this->assertFalse(
			$experiment->register_called,
			'Disabled experiment register() should not be called'
		);
	}

	/**
	 * Test non-existent experiment class triggers _doing_it_wrong().
	 */
	public function test_nonexistent_class_triggers_doing_it_wrong() {
		$this->setExpectedIncorrectUsage( 'WordPress\AI\Experiment_Loader::get_default_experiments' );

		add_filter(
			'ai_experiments_default_experiment_classes',
			static function () {
				return array( 'NonExistent\Class' );
			}
		);

		$this->loader->register_default_experiments();
	}

	/**
	 * Test invalid interface triggers _doing_it_wrong().
	 */
	public function test_invalid_interface_triggers_doing_it_wrong() {
		$this->setExpectedIncorrectUsage( 'WordPress\AI\Experiment_Loader::get_default_experiments' );

		add_filter(
			'ai_experiments_default_experiment_classes',
			static function () {
				return array( \stdClass::class );
			}
		);

		$this->loader->register_default_experiments();
	}

	/**
	 * Test instantiation failure triggers _doing_it_wrong().
	 *
	 * @since 0.1.0
	 */
	public function test_instantiation_failure_triggers_doing_it_wrong() {
		$this->setExpectedIncorrectUsage( 'WordPress\AI\Experiment_Loader::get_default_experiments' );

		add_filter(
			'ai_experiments_default_experiment_classes',
			static function () {
				return array( Throwing_Experiment::class );
			}
		);

		$this->loader->register_default_experiments();
	}
}
