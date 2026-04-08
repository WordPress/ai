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
 * Test feature with custom settings fields.
 *
 * @since x.x.x
 */
class Test_Feature_With_Settings extends Abstract_Feature {
	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'test-with-settings';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => 'Test With Settings',
			'description' => 'Test feature with custom settings fields',
			'category'    => Experiment_Category::EDITOR,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {}

	/**
	 * {@inheritDoc}
	 */
	public function get_settings_fields(): array {
		return array(
			array(
				'id'       => 'color',
				'label'    => 'Color',
				'type'     => 'string',
				'default'  => 'blue',
				'elements' => array(
					array(
						'value' => 'blue',
						'label' => 'Blue',
					),
					array(
						'value' => 'red',
						'label' => 'Red',
					),
				),
			),
			array(
				'id'      => 'count',
				'label'   => 'Count',
				'type'    => 'integer',
				'default' => 3,
			),
		);
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

	/**
	 * Tests that get_settings_fields() returns an empty array by default.
	 *
	 * @since x.x.x
	 */
	public function test_get_settings_fields_returns_empty_by_default(): void {
		$experiment = new Test_Uncategorized_Experiment();

		$this->assertSame(
			array(),
			$experiment->get_settings_fields(),
			'get_settings_fields() should return an empty array by default'
		);
	}

	/**
	 * Tests that get_settings_fields_metadata() returns an empty array when no fields are defined.
	 *
	 * @since x.x.x
	 */
	public function test_get_settings_fields_metadata_returns_empty_when_no_fields(): void {
		$experiment = new Test_Uncategorized_Experiment();

		$this->assertSame(
			array(),
			$experiment->get_settings_fields_metadata(),
			'get_settings_fields_metadata() should return an empty array when no fields are defined'
		);
	}

	/**
	 * Tests that get_settings_fields() returns declared fields.
	 *
	 * @since x.x.x
	 */
	public function test_get_settings_fields_returns_declared_fields(): void {
		$feature = new Test_Feature_With_Settings();
		$fields  = $feature->get_settings_fields();

		$this->assertCount( 2, $fields, 'Should return two settings fields' );
		$this->assertSame( 'color', $fields[0]['id'], 'First field should have short id "color"' );
		$this->assertSame( 'count', $fields[1]['id'], 'Second field should have short id "count"' );
		$this->assertSame( 'string', $fields[0]['type'] );
		$this->assertSame( 'integer', $fields[1]['type'] );
	}

	/**
	 * Tests that get_settings_fields_metadata() resolves short IDs to full option names.
	 *
	 * @since x.x.x
	 */
	public function test_get_settings_fields_metadata_resolves_option_names(): void {
		$feature = new Test_Feature_With_Settings();
		$fields  = $feature->get_settings_fields_metadata();

		$this->assertCount( 2, $fields, 'Should return two settings fields' );
		$this->assertSame(
			'wpai_feature_test-with-settings_field_color',
			$fields[0]['id'],
			'First field id should be resolved to full option name'
		);
		$this->assertSame(
			'wpai_feature_test-with-settings_field_count',
			$fields[1]['id'],
			'Second field id should be resolved to full option name'
		);
	}

	/**
	 * Tests that get_settings_fields_metadata() preserves other field properties.
	 *
	 * @since x.x.x
	 */
	public function test_get_settings_fields_metadata_preserves_field_properties(): void {
		$feature = new Test_Feature_With_Settings();
		$fields  = $feature->get_settings_fields_metadata();

		$this->assertSame( 'Color', $fields[0]['label'] );
		$this->assertSame( 'string', $fields[0]['type'] );
		$this->assertSame( 'blue', $fields[0]['default'] );
		$this->assertCount( 2, $fields[0]['elements'] );
		$this->assertSame( 'blue', $fields[0]['elements'][0]['value'] );
		$this->assertSame( 'red', $fields[0]['elements'][1]['value'] );

		$this->assertSame( 'Count', $fields[1]['label'] );
		$this->assertSame( 'integer', $fields[1]['type'] );
		$this->assertSame( 3, $fields[1]['default'] );
	}
}
