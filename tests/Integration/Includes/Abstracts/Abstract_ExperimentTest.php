<?php
/**
 * Integration tests for the Abstract_Feature class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abstracts
 */

namespace WordPress\AI\Tests\Integration\Includes\Abstracts;

use WP_UnitTestCase;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Features\Feature_Category;

/**
 * Test experiment with an explicit category.
 *
 * @since 0.4.0
 */
class Test_Categorized_Experiment extends Abstract_Feature {
	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'test-categorized';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
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
class Test_Uncategorized_Experiment extends Abstract_Feature {
	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'test-uncategorized';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
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
 * Mock categorised experiment for get_stability test
 *
 * @since 0.4.0
 */
class Mock_Custom_Stability_Experiment extends Test_Uncategorized_Experiment {
	/**
	 * @return string
	 */
	public static function get_id(): string {
		return 'test-custom-stability';
	}

	/**
	 * @return array
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => 'Test Custom Stability',
			'description' => 'Test description',
			'stability'   => 'stable',
		);
	}
}

/**
 * Test experiment with an empty string category.
 *
 * @since 0.4.0
 */
class Test_Empty_Category_Experiment extends Abstract_Feature {
	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'test-empty-category';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
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
 * Abstract_Feature test case.
 *
 * @since 0.4.0
 */
class Abstract_FeatureTest extends WP_UnitTestCase {

	/**
	 * Set up test case.
	 *
	 * @since 0.4.0
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( 'wpai_features_enabled', true );
		update_option( 'wpai_feature_test-categorized_enabled', true );
		update_option( 'wpai_feature_test-uncategorized_enabled', true );
		update_option( 'wpai_feature_test-empty-category_enabled', true );
	}

	/**
	 * Tear down test case.
	 *
	 * @since 0.4.0
	 */
	public function tearDown(): void {
		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_test-categorized_enabled' );
		delete_option( 'wpai_feature_test-uncategorized_enabled' );
		delete_option( 'wpai_feature_test-empty-category_enabled' );
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
			'get_category() should return the category declared in load_metadata()'
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
			Feature_Category::OTHER,
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
			Feature_Category::OTHER,
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
		$this->assertSame( 'other', Feature_Category::OTHER );
	}

	/**
	 * @since 0.6.0
	 */
	public function test_get_stability_default() {
		$experiment = new Test_Uncategorized_Experiment();
		$this->assertEquals( 'experimental', $experiment->get_stability(), 'Default stability should be experimental' );
	}

	/**
	 * @since 0.6.0
	 */
	public function test_get_stability_custom() {
		$experiment = new Mock_Custom_Stability_Experiment();
		$this->assertEquals( 'stable', $experiment->get_stability(), 'Custom stability should be returned from metadata' );
	}
}
