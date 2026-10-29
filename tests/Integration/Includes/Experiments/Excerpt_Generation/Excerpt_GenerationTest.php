<?php
/**
 * Integration tests for the Excerpt_Generation experiment class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Excerpt_Generation
 */

namespace WordPress\AI\Tests\Integration\Experiments\Excerpt_Generation;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Excerpt_Generation\Excerpt_Generation;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Features\Loader;
use WordPress\AI\Features\Registry;

/**
 * Excerpt_Generation experiment test case.
 *
 * @since 0.4.0
 */
class Excerpt_GenerationTest extends WP_UnitTestCase {

	/**
	 * Set up test case.
	 *
	 * @since 0.4.0
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( 'wp_ai_client_provider_credentials', array( 'openai' => 'test-api-key' ) );
		add_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );

		update_option( 'wpai_feature_excerpt-generation_enabled', true );

		$registry = new Registry();
		$loader   = new Loader( $registry );
		$loader->init();

		$experiment = $registry->get_feature( 'excerpt-generation' );
		$this->assertInstanceOf(
			Excerpt_Generation::class,
			$experiment,
			'Excerpt generation experiment should be registered in the registry.'
		);
	}

	/**
	 * Tear down test case.
	 *
	 * @since 0.4.0
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( 'wpai_feature_excerpt-generation_enabled' );
		delete_option( 'wp_ai_client_provider_credentials' );
		remove_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );
		parent::tearDown();
	}

	/**
	 * Tests that the experiment reports correct metadata.
	 *
	 * @since 0.4.0
	 */
	public function test_experiment_registration(): void {
		$experiment = new Excerpt_Generation();

		$this->assertEquals( 'excerpt-generation', $experiment->get_id() );
		$this->assertEquals( 'Excerpt Generation', $experiment->get_label() );
		$this->assertEquals( Experiment_Category::EDITOR, $experiment->get_category() );
		$this->assertTrue( $experiment->is_enabled() );
	}

	/**
	 * Tests that the experiment can be disabled via the filter.
	 *
	 * @since 0.4.0
	 */
	public function test_experiment_can_be_disabled_via_filter(): void {
		add_filter( 'wpai_feature_excerpt-generation_enabled', '__return_false' );

		$experiment = new Excerpt_Generation();
		$this->assertFalse( $experiment->is_enabled() );

		remove_all_filters( 'wpai_feature_excerpt-generation_enabled' );
	}

	/**
	 * Tests that enqueue_assets() localizes the default minimum content length.
	 *
	 * @since 1.1.0
	 */
	public function test_enqueue_assets_localizes_default_min_content_length(): void {
		set_current_screen( 'post' );

		$experiment = new Excerpt_Generation();
		$experiment->enqueue_assets( 'post.php' );

		$this->assertTrue( wp_script_is( 'ai_excerpt_generation', 'enqueued' ) );
		$this->assertStringContainsString(
			'"minContentLength":"250"',
			(string) wp_scripts()->get_data( 'ai_excerpt_generation', 'data' )
		);
	}

	/**
	 * Tests that enqueue_assets() localizes the filtered minimum content length.
	 *
	 * @since 1.1.0
	 */
	public function test_enqueue_assets_localizes_filtered_min_content_length(): void {
		set_current_screen( 'post' );

		$filter = static function () {
			return 250;
		};

		add_filter( 'wpai_min_content_length', $filter );

		$experiment = new Excerpt_Generation();
		$experiment->enqueue_assets( 'post.php' );

		remove_filter( 'wpai_min_content_length', $filter );

		$this->assertStringContainsString(
			'"minContentLength":"250"',
			(string) wp_scripts()->get_data( 'ai_excerpt_generation', 'data' )
		);
	}

	/**
	 * Tests that enqueue_assets() tells the script whether the editor renders
	 * the excerpt with the Entity Fields API (WordPress 7.2+), and enqueues
	 * the stylesheet of the excerpt field's summary only then.
	 *
	 * @since n.e.x.t
	 */
	public function test_enqueue_assets_localizes_entity_fields_api(): void {
		set_current_screen( 'post' );

		$experiment = new Excerpt_Generation();
		$experiment->enqueue_assets( 'post.php' );

		$entity_fields_api = function_exists( 'wp_update_entity_fields' );
		$this->assertStringContainsString(
			$entity_fields_api ? '"entityFieldsApi":"1"' : '"entityFieldsApi":""',
			(string) wp_scripts()->get_data( 'ai_excerpt_generation', 'data' )
		);
		$this->assertSame( $entity_fields_api, wp_style_is( 'ai_excerpt_generation', 'enqueued' ) );
	}

	/**
	 * Tests that update_excerpt_field() hands the excerpt field of the post
	 * types supporting excerpts to the plugin's script module, and keeps the
	 * field's summary in charge of empty excerpts (WordPress 7.2+).
	 *
	 * @since n.e.x.t
	 */
	public function test_update_excerpt_field_hands_the_excerpt_field_to_the_script_module(): void {
		if ( ! function_exists( 'wp_update_entity_fields' ) ) {
			$this->markTestSkipped( 'The Entity Fields API needs WordPress 7.2.' );
		}

		// Registers the entity fields anew, so that the experiment's
		// `wp_entity_fields_api_init` callback (hooked in setUp) runs.
		$instance = new \ReflectionProperty( \WP_Entity_Fields_Registry::class, 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );

		$modules = array_column( wp_get_registered_entity_field_modules( 'postType', 'post' ), 'fields', 'id' );
		$this->assertSame( array( 'excerpt' ), $modules['@ai/excerpt-field'] ?? null );
		// Pages support no excerpts, and templates support excerpts but core
		// registers no excerpt field for them.
		foreach ( array( 'page', 'wp_template' ) as $post_type ) {
			$this->assertArrayNotHasKey(
				'@ai/excerpt-field',
				array_column( wp_get_registered_entity_field_modules( 'postType', $post_type ), 'fields', 'id' ),
				$post_type
			);
		}

		$excerpt_layout = null;
		foreach ( wp_get_entity_view_config( 'postType', 'post' )['form']['fields'] as $field ) {
			if ( ! is_array( $field ) || 'excerpt' !== ( $field['id'] ?? null ) ) {
				continue;
			}
			$excerpt_layout = $field['layout'];
		}
		$this->assertSame( 'panel', $excerpt_layout['type'] ?? null );
		$this->assertFalse( $excerpt_layout['showPlaceholderIfEmpty'] ?? null );
	}
}
