<?php
/**
 * Integration tests for the Abstract_Experiment class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abstracts
 */

namespace WordPress\AI\Tests\Integration\Includes\Abstracts;

use WP_UnitTestCase;
use WordPress\AI\Abstracts\Abstract_Experiment;
use WordPress\AI\Experiment_Category;

/**
 * Test experiment with an explicit category.
 *
 * @since 0.4.0
 */
class Test_Categorized_Experiment extends Abstract_Experiment {
	/**
	 * Loads experiment metadata.
	 *
	 * @since 0.4.0
	 *
	 * @return array{id: string, label: string, description: string, category: string} Experiment metadata.
	 */
	protected function load_experiment_metadata(): array {
		return array(
			'id'          => 'test-categorized',
			'label'       => 'Test Categorized',
			'description' => 'Test experiment with explicit category',
			'category'    => Experiment_Category::EDITOR,
		);
	}

	/**
	 * Registers the experiment.
	 *
	 * @since 0.4.0
	 */
	public function register(): void {
		// No-op for testing.
	}
}

/**
 * Test experiment without a category key in metadata.
 *
 * @since 0.4.0
 */
class Test_Uncategorized_Experiment extends Abstract_Experiment {
	/**
	 * Loads experiment metadata.
	 *
	 * @since 0.4.0
	 *
	 * @return array{id: string, label: string, description: string, category: string} Experiment metadata.
	 */
	protected function load_experiment_metadata(): array {
		return array(
			'id'          => 'test-uncategorized',
			'label'       => 'Test Uncategorized',
			'description' => 'Test experiment without category key',
		);
	}

	/**
	 * Registers the experiment.
	 *
	 * @since 0.4.0
	 */
	public function register(): void {
		// No-op for testing.
	}
}

/**
 * Test experiment with an empty string category.
 *
 * @since 0.4.0
 */
class Test_Empty_Category_Experiment extends Abstract_Experiment {
	/**
	 * Loads experiment metadata.
	 *
	 * @since 0.4.0
	 *
	 * @return array{id: string, label: string, description: string, category: string} Experiment metadata.
	 */
	protected function load_experiment_metadata(): array {
		return array(
			'id'          => 'test-empty-category',
			'label'       => 'Test Empty Category',
			'description' => 'Test experiment with empty string category',
			'category'    => '',
		);
	}

	/**
	 * Registers the experiment.
	 *
	 * @since 0.4.0
	 */
	public function register(): void {
		// No-op for testing.
	}
}

/**
 * Abstract_Experiment test case.
 *
 * @since 0.4.0
 */
class Abstract_ExperimentTest extends WP_UnitTestCase {

	/**
	 * Set up test case.
	 *
	 * @since 0.4.0
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( 'ai_experiments_enabled', true );
		update_option( 'ai_experiment_test-categorized_enabled', true );
		update_option( 'ai_experiment_test-uncategorized_enabled', true );
		update_option( 'ai_experiment_test-empty-category_enabled', true );
	}

	/**
	 * Tear down test case.
	 *
	 * @since 0.4.0
	 */
	public function tearDown(): void {
		delete_option( 'ai_experiments_enabled' );
		delete_option( 'ai_experiment_test-categorized_enabled' );
		delete_option( 'ai_experiment_test-uncategorized_enabled' );
		delete_option( 'ai_experiment_test-empty-category_enabled' );
		parent::tearDown();
	}

	/**
	 * Tests that get_category() returns the value declared in metadata.
	 *
	 * @since 0.4.0
	 */
	public function test_get_category_returns_set_value(): void {
		$experiment = new Test_Categorized_Experiment();

		$this->assertSame(
			Experiment_Category::EDITOR,
			$experiment->get_category(),
			'get_category() should return the category declared in load_experiment_metadata()'
		);
	}

	/**
	 * Tests that get_category() falls back to OTHER when the category key is absent from metadata.
	 *
	 * @since 0.4.0
	 */
	public function test_get_category_defaults_to_other_when_missing(): void {
		$experiment = new Test_Uncategorized_Experiment();

		$this->assertSame(
			Experiment_Category::OTHER,
			$experiment->get_category(),
			'get_category() should return OTHER when no category key is present in metadata'
		);
	}

	/**
	 * Tests that get_category() falls back to OTHER when category is an empty string.
	 *
	 * @since 0.4.0
	 */
	public function test_get_category_defaults_to_other_when_empty(): void {
		$experiment = new Test_Empty_Category_Experiment();

		$this->assertSame(
			Experiment_Category::OTHER,
			$experiment->get_category(),
			'get_category() should return OTHER when category is an empty string'
		);
	}

	/**
	 * Tests that the EDITOR constant has the expected string value.
	 *
	 * @since 0.4.0
	 */
	public function test_experiment_category_editor_constant_value(): void {
		$this->assertSame( 'editor', Experiment_Category::EDITOR );
	}

	/**
	 * Tests that the ADMIN constant has the expected string value.
	 *
	 * @since 0.4.0
	 */
	public function test_experiment_category_admin_constant_value(): void {
		$this->assertSame( 'admin', Experiment_Category::ADMIN );
	}

	/**
	 * Tests that the OTHER constant has the expected string value.
	 *
	 * @since 0.4.0
	 */
	public function test_experiment_category_other_constant_value(): void {
		$this->assertSame( 'other', Experiment_Category::OTHER );
	}
}
