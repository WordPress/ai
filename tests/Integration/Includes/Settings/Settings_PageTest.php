<?php
/**
 * Integration tests for the Settings_Page class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Settings
 */

namespace WordPress\AI\Tests\Integration\Includes\Settings;

use WP_UnitTestCase;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Features\Feature_Category;
use WordPress\AI\Features\Registry;
use WordPress\AI\Settings\Settings_Page;

/**
 * Stub feature for testing with a known category.
 */
class Stub_Editor_Feature extends Abstract_Feature {
	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'stub-editor';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => 'Stub Editor Feature',
			'description' => 'An editor feature for testing.',
			'category'    => Experiment_Category::EDITOR,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {}
}

/**
 * Stub feature with a custom AI capability.
 */
class Stub_Image_Capability_Feature extends Abstract_Feature {
	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'stub-image-capability';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => 'Stub Image Capability',
			'description' => 'A feature with a custom capability.',
			'category'    => Experiment_Category::EDITOR,
			'capability'  => 'image_generation',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {}
}

/**
 * Stub feature for admin category.
 */
class Stub_Admin_Feature extends Abstract_Feature {
	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'stub-admin';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => 'Stub Admin Feature',
			'description' => 'An admin feature for testing.',
			'category'    => Experiment_Category::ADMIN,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {}
}

/**
 * Stub feature with an unknown category.
 */
class Stub_Custom_Category_Feature extends Abstract_Feature {
	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'stub-custom';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => 'Stub Custom Feature',
			'description' => 'A feature with an unknown category.',
			'category'    => 'custom-category',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {}
}

/**
 * Stub feature with an empty category (should fall back to OTHER).
 */
class Stub_No_Category_Feature extends Abstract_Feature {
	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'stub-no-category';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => 'Stub No Category',
			'description' => 'A feature without a category.',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {}
}

/**
 * Stub feature with custom settings fields.
 */
class Stub_Feature_With_Settings extends Abstract_Feature {
	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'stub-with-settings';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => 'Stub With Settings',
			'description' => 'A feature with custom settings.',
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
				'id'       => 'mode',
				'label'    => 'Mode',
				'type'     => 'text',
				'default'  => 'auto',
				'elements' => array(
					array(
						'value' => 'auto',
						'label' => 'Auto',
					),
					array(
						'value' => 'manual',
						'label' => 'Manual',
					),
				),
			),
			array(
				'id'      => 'limit',
				'label'   => 'Limit',
				'type'    => 'integer',
				'default' => 10,
			),
		);
	}
}

/**
 * Stub feature with HTML in its description.
 */
class Stub_HTML_Description_Feature extends Abstract_Feature {
	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'stub-html-desc';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => 'HTML Description Feature',
			'description' => 'A <strong>bold</strong> feature with <em>emphasis</em>.',
			'category'    => Experiment_Category::EDITOR,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {}
}

/**
 * Tests for get_settings_feature_metadata().
 *
 * @since 0.6.0
 */
class Settings_PageTest extends WP_UnitTestCase {
	/**
	 * Registry instance.
	 *
	 * @var \WordPress\AI\Features\Registry
	 */
	private Registry $registry;

	/**
	 * {@inheritDoc}
	 */
	public function setUp(): void {
		parent::setUp();
		$this->registry = new Registry();
	}

	/**
	 * {@inheritDoc}
	 */
	public function tearDown(): void {
		remove_all_filters( 'wpai_settings_feature_groups' );
		remove_all_filters( 'wpai_settings_feature_metadata' );
		remove_all_filters( 'wp_redirect' );
		$_GET = array();
		parent::tearDown();
	}

	private function get_settings_feature_metadata( Registry $registry ): array {
		$method = new \ReflectionMethod( Settings_Page::class, 'get_settings_feature_metadata' );
		$method->setAccessible( true );
		return $method->invoke( null, $registry );
	}

	/**
	 * Test that an empty registry returns empty groups and features.
	 */
	public function test_empty_registry_returns_empty_metadata() {
		$result = $this->get_settings_feature_metadata( $this->registry );

		$this->assertSame( array(), $result['groups'] );
		$this->assertSame( array(), $result['features'] );
	}

	/**
	 * Test that a single feature produces the correct metadata structure.
	 */
	public function test_single_feature_produces_correct_metadata() {
		$this->registry->register_feature( new Stub_Editor_Feature() );

		$result = $this->get_settings_feature_metadata( $this->registry );

		$this->assertCount( 1, $result['features'] );
		$this->assertCount( 1, $result['groups'] );

		$feature = $result['features'][0];
		$this->assertSame( 'stub-editor', $feature['id'] );
		$this->assertSame( 'wpai_feature_stub-editor_enabled', $feature['settingName'] );
		$this->assertSame( 'Stub Editor Feature', $feature['label'] );
		$this->assertSame( 'An editor feature for testing.', $feature['description'] );
		$this->assertSame( Experiment_Category::EDITOR, $feature['category'] );
		$this->assertSame( 'text_generation', $feature['capability'] );

		$group = $result['groups'][0];
		$this->assertSame( Experiment_Category::EDITOR, $group['id'] );
		$this->assertSame( 'Editor Experiments', $group['label'] );
		$this->assertArrayNotHasKey( 'order', $group, 'Order should be stripped from output' );
	}

	/**
	 * Test that groups are sorted by order, then by label.
	 */
	public function test_groups_are_sorted_by_order() {
		$this->registry->register_feature( new Stub_Admin_Feature() );
		$this->registry->register_feature( new Stub_Editor_Feature() );

		$result = $this->get_settings_feature_metadata( $this->registry );

		$this->assertCount( 2, $result['groups'] );
		// Editor (order 10) should come before Admin (order 20).
		$this->assertSame( Experiment_Category::EDITOR, $result['groups'][0]['id'] );
		$this->assertSame( Experiment_Category::ADMIN, $result['groups'][1]['id'] );
	}

	/**
	 * Test that only categories with registered features appear as groups.
	 */
	public function test_only_used_categories_appear_as_groups() {
		$this->registry->register_feature( new Stub_Editor_Feature() );

		$result = $this->get_settings_feature_metadata( $this->registry );

		$group_ids = array_column( $result['groups'], 'id' );
		$this->assertContains( Experiment_Category::EDITOR, $group_ids );
		$this->assertNotContains( Experiment_Category::ADMIN, $group_ids );
		$this->assertNotContains( Feature_Category::OTHER, $group_ids );
	}

	/**
	 * Test that a feature with an unknown category creates a dynamic group.
	 */
	public function test_unknown_category_creates_dynamic_group() {
		$this->registry->register_feature( new Stub_Custom_Category_Feature() );

		$result = $this->get_settings_feature_metadata( $this->registry );

		$this->assertCount( 1, $result['groups'] );
		$group = $result['groups'][0];
		$this->assertSame( 'custom-category', $group['id'] );
		$this->assertSame( 'Custom Category', $group['label'] );
		$this->assertSame( '', $group['description'] );
	}

	/**
	 * Test that a feature without a category falls back to OTHER.
	 */
	public function test_feature_without_category_falls_back_to_other() {
		$this->registry->register_feature( new Stub_No_Category_Feature() );

		$result = $this->get_settings_feature_metadata( $this->registry );

		$this->assertSame( Feature_Category::OTHER, $result['features'][0]['category'] );
		$this->assertSame( Feature_Category::OTHER, $result['groups'][0]['id'] );
		$this->assertSame( 'Other Features', $result['groups'][0]['label'] );
	}

	/**
	 * Test that HTML is stripped from feature descriptions.
	 */
	public function test_html_is_stripped_from_descriptions() {
		$this->registry->register_feature( new Stub_HTML_Description_Feature() );

		$result = $this->get_settings_feature_metadata( $this->registry );

		$this->assertSame(
			'A bold feature with emphasis.',
			$result['features'][0]['description']
		);
	}

	/**
	 * Test that multiple features in the same category share a single group.
	 */
	public function test_multiple_features_share_single_group() {
		$this->registry->register_feature( new Stub_Editor_Feature() );
		$this->registry->register_feature( new Stub_HTML_Description_Feature() );

		$result = $this->get_settings_feature_metadata( $this->registry );

		$this->assertCount( 2, $result['features'] );
		$this->assertCount( 1, $result['groups'] );
		$this->assertSame( Experiment_Category::EDITOR, $result['groups'][0]['id'] );
	}

	/**
	 * Test that the wpai_settings_feature_groups filter can modify groups.
	 */
	public function test_feature_groups_filter() {
		add_filter(
			'wpai_settings_feature_groups',
			static function ( array $groups ): array {
				$groups['custom-category'] = array(
					'label'       => 'My Custom Group',
					'description' => 'Custom group description.',
					'order'       => 5,
				);
				return $groups;
			}
		);

		$this->registry->register_feature( new Stub_Custom_Category_Feature() );

		$result = $this->get_settings_feature_metadata( $this->registry );

		$group = $result['groups'][0];
		$this->assertSame( 'custom-category', $group['id'] );
		$this->assertSame( 'My Custom Group', $group['label'] );
		$this->assertSame( 'Custom group description.', $group['description'] );
	}

	/**
	 * Test that the wpai_settings_feature_metadata filter can modify the final output.
	 */
	public function test_metadata_filter() {
		$this->registry->register_feature( new Stub_Editor_Feature() );

		add_filter(
			'wpai_settings_feature_metadata',
			static function ( array $metadata ): array {
				$metadata['features'][0]['label'] = 'Overridden Label';
				return $metadata;
			}
		);

		$result = $this->get_settings_feature_metadata( $this->registry );

		$this->assertSame( 'Overridden Label', $result['features'][0]['label'] );
	}

	/**
	 * Test that the wpai_settings_feature_metadata filter returning non-array falls back.
	 */
	public function test_metadata_filter_non_array_fallback() {
		$this->registry->register_feature( new Stub_Editor_Feature() );

		add_filter( 'wpai_settings_feature_metadata', '__return_false' );

		$result = $this->get_settings_feature_metadata( $this->registry );

		// Should fall back to the unfiltered metadata.
		$this->assertCount( 1, $result['features'] );
		$this->assertSame( 'Stub Editor Feature', $result['features'][0]['label'] );
	}

	/**
	 * Test that the wpai_settings_feature_groups filter returning non-array falls back.
	 */
	public function test_feature_groups_filter_non_array_fallback() {
		$this->registry->register_feature( new Stub_Editor_Feature() );

		add_filter( 'wpai_settings_feature_groups', '__return_false' );

		$result = $this->get_settings_feature_metadata( $this->registry );

		// Should still produce valid output using default groups.
		$this->assertCount( 1, $result['groups'] );
		$this->assertSame( 'Editor Experiments', $result['groups'][0]['label'] );
	}

	/**
	 * Test that settingsFields are included in feature metadata.
	 */
	public function test_feature_with_settings_includes_settings_fields() {
		$this->registry->register_feature( new Stub_Feature_With_Settings() );

		$result = $this->get_settings_feature_metadata( $this->registry );

		$this->assertCount( 1, $result['features'] );
		$feature = $result['features'][0];

		$this->assertArrayHasKey( 'settingsFields', $feature, 'Feature metadata should include settingsFields' );
		$this->assertCount( 2, $feature['settingsFields'], 'Should include two settings fields' );

		// IDs should be resolved to full option names.
		$this->assertSame(
			'wpai_feature_stub-with-settings_field_mode',
			$feature['settingsFields'][0]['id'],
			'Settings field id should be resolved to full option name'
		);
		$this->assertSame(
			'wpai_feature_stub-with-settings_field_limit',
			$feature['settingsFields'][1]['id'],
			'Settings field id should be resolved to full option name'
		);

		// Other properties should be preserved.
		$this->assertSame( 'Mode', $feature['settingsFields'][0]['label'] );
		$this->assertSame( 'text', $feature['settingsFields'][0]['type'] );
		$this->assertCount( 2, $feature['settingsFields'][0]['elements'] );
		$this->assertSame( 'integer', $feature['settingsFields'][1]['type'] );
	}

	/**
	 * Test that features without settings have empty settingsFields array.
	 */
	public function test_feature_without_settings_has_empty_settings_fields() {
		$this->registry->register_feature( new Stub_Editor_Feature() );

		$result = $this->get_settings_feature_metadata( $this->registry );

		$feature = $result['features'][0];
		$this->assertArrayHasKey( 'settingsFields', $feature );
		$this->assertSame( array(), $feature['settingsFields'], 'Feature without custom settings should have empty settingsFields' );
	}

	/**
	 * Test that feature metadata includes a custom capability when provided.
	 */
	public function test_feature_metadata_includes_custom_capability() {
		$this->registry->register_feature( new Stub_Image_Capability_Feature() );

		$result = $this->get_settings_feature_metadata( $this->registry );

		$this->assertSame( 'image_generation', $result['features'][0]['capability'] );
	}

	/**
	 * Test that init registers an admin redirect hook for the legacy settings slug.
	 */
	public function test_init_registers_legacy_settings_redirect_hook() {
		Settings_Page::init( $this->registry );

		$this->assertTrue(
			has_action( 'admin_init', array( Settings_Page::class, 'maybe_redirect_legacy_page' ) ) !== false
		);
		$this->assertTrue(
			has_action( 'admin_page_access_denied', array( Settings_Page::class, 'maybe_redirect_legacy_page' ) ) !== false
		);
	}

	/**
	 * Test that the legacy settings slug redirects to the new slug.
	 */
	public function test_legacy_settings_slug_redirects_to_new_slug() {
		$captured_location = null;
		$captured_status   = null;

		add_filter(
			'wp_redirect',
			static function ( $location, $status ) use ( &$captured_location, &$captured_status ) {
				$captured_location = $location;
				$captured_status   = $status;
				return false;
			},
			10,
			2
		);

		$_GET['page'] = 'ai';
		Settings_Page::maybe_redirect_legacy_page();

		$this->assertSame( admin_url( 'options-general.php?page=ai-wp-admin' ), $captured_location );
		$this->assertSame( 301, $captured_status );
	}

	/**
	 * Tests that active connectors are exposed as pseudo-features.
	 *
	 * @since 1.0.2
	 */
	public function test_active_connectors_are_exposed_in_metadata(): void {
		// Mock/register a connector
		$connector_id = 'wpai_settings_page_test_connector';
		$registry_wp = \WP_Connector_Registry::get_instance();
		if ( null !== $registry_wp ) {
			$registry_wp->register(
				$connector_id,
				array(
					'name'           => 'Settings Page Test Connector',
					'type'           => 'ai_provider',
					'authentication' => array(
						'method' => 'none',
					),
				)
			);
		}

		// Mock/register in AiClient registry
		$registry_ai = \WordPress\AiClient\AiClient::defaultRegistry();
		$ids_to_classes = new \ReflectionProperty( $registry_ai, 'registeredIdsToClassNames' );
		$ids_to_classes->setAccessible( true );
		$id_map                  = (array) $ids_to_classes->getValue( $registry_ai );
		$id_map[ $connector_id ] = \WordPress\AI\Tests\Integration\Includes\Helper_Test_Provider::class;
		$ids_to_classes->setValue( $registry_ai, $id_map );

		try {
			$result = $this->get_settings_feature_metadata( $this->registry );

			// Verify connectors group
			$group_ids = array_column( $result['groups'], 'id' );
			$this->assertContains( 'connectors', $group_ids );

			$connectors_group = null;
			foreach ( $result['groups'] as $group ) {
				if ( 'connectors' === $group['id'] ) {
					$connectors_group = $group;
					break;
				}
			}
			$this->assertNotNull( $connectors_group );
			$this->assertSame( 'AI Connectors', $connectors_group['label'] );

			// Verify connectors pseudo-feature
			$feature_ids = array_column( $result['features'], 'id' );
			$this->assertContains( "connector-{$connector_id}", $feature_ids );

			$connector_feature = null;
			foreach ( $result['features'] as $feature ) {
				if ( "connector-{$connector_id}" === $feature['id'] ) {
					$connector_feature = $feature;
					break;
				}
			}
			$this->assertNotNull( $connector_feature );
			$this->assertSame( "wpai_connector_{$connector_id}_enabled", $connector_feature['settingName'] );
			$this->assertSame( 'Settings Page Test Connector', $connector_feature['label'] );
			$this->assertSame( 'connectors', $connector_feature['category'] );
		} finally {
			if ( null !== $registry_wp ) {
				$registry_wp->unregister( $connector_id );
			}
			$id_map = (array) $ids_to_classes->getValue( $registry_ai );
			unset( $id_map[ $connector_id ] );
			$ids_to_classes->setValue( $registry_ai, $id_map );
		}
	}
}
