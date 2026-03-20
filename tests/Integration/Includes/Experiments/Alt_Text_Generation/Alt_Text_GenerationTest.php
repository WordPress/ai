<?php
/**
 * Integration tests for the Alt_Text_Generation experiment class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Alt_Text_Generation
 */

namespace WordPress\AI\Tests\Integration\Experiments\Alt_Text_Generation;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Alt_Text_Generation\Alt_Text_Generation;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Features\Loader;
use WordPress\AI\Features\Registry;

/**
 * Alt_Text_Generation experiment test case.
 *
 * @since 0.3.0
 */
class Alt_Text_GenerationTest extends WP_UnitTestCase {
	/**
	 * Set up test case.
	 *
	 * @since 0.3.0
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( 'wp_ai_client_provider_credentials', array( 'openai' => 'test-api-key' ) );
		add_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );

		update_option( 'wpai_features_enabled', true );
		update_option( 'wpai_feature_alt-text-generation_enabled', true );

		$registry = new Registry();
		$loader   = new Loader( $registry );
		$loader->register_features();

		$experiment = $registry->get_feature( 'alt-text-generation' );
		$this->assertInstanceOf(
			Alt_Text_Generation::class,
			$experiment,
			'Alt Text Generation experiment should be registered in the registry.'
		);
	}

	/**
	 * Tear down test case.
	 *
	 * @since 0.3.0
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_alt-text-generation_enabled' );
		delete_option( 'wp_ai_client_provider_credentials' );
		remove_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );
		parent::tearDown();
	}

	/**
	 * Test that the experiment is registered correctly.
	 *
	 * @since 0.3.0
	 */
	public function test_experiment_registration() {
		$experiment = new Alt_Text_Generation();

		$this->assertEquals( 'alt-text-generation', $experiment->get_id() );
		$this->assertEquals( 'Alt Text Generation', $experiment->get_label() );
		$this->assertEquals( Experiment_Category::EDITOR, $experiment->get_category() );
		$this->assertTrue( $experiment->is_enabled() );
	}

	/**
	 * Test that the experiment can be disabled via filter.
	 *
	 * @since 0.3.0
	 */
	public function test_experiment_can_be_disabled_via_filter() {
		add_filter( 'wpai_feature_alt-text-generation_enabled', '__return_false' );

		$experiment = new Alt_Text_Generation();
		$this->assertFalse( $experiment->is_enabled() );

		remove_all_filters( 'wpai_feature_alt-text-generation_enabled' );
	}

	/**
	 * Test that the bulk action is added to the actions list when the experiment is enabled.
	 *
	 * @since x.x.x
	 */
	public function test_bulk_action_is_registered(): void {
		$experiment = new Alt_Text_Generation();
		$actions    = $experiment->register_bulk_action( array() );

		$this->assertArrayHasKey( 'ai_generate_alt_text', $actions );
		$this->assertSame( 'Generate Alt Text', $actions['ai_generate_alt_text'] );
	}

	/**
	 * Test that the bulk action is not added when the experiment is disabled.
	 *
	 * @since x.x.x
	 */
	public function test_bulk_action_not_registered_when_disabled(): void {
		add_filter( 'wpai_feature_alt-text-generation_enabled', '__return_false' );

		$experiment = new Alt_Text_Generation();
		$initial    = array( 'delete' => 'Delete Permanently' );
		$actions    = $experiment->register_bulk_action( $initial );

		$this->assertSame( $initial, $actions );
		$this->assertArrayNotHasKey( 'ai_generate_alt_text', $actions );

		remove_all_filters( 'wpai_feature_alt-text-generation_enabled' );
	}

	/**
	 * Test that the bulk action handler adds query args for image attachments.
	 *
	 * @since x.x.x
	 */
	public function test_handle_bulk_action_adds_query_args_for_images(): void {
		$image_id = self::factory()->attachment->create_upload_object(
			dirname( __FILE__ ) . '/../../../../data/sample.png'
		);

		$experiment   = new Alt_Text_Generation();
		$redirect     = 'https://example.com/wp-admin/upload.php';
		$result       = $experiment->handle_bulk_action( $redirect, 'ai_generate_alt_text', array( $image_id ) );

		$this->assertStringContainsString( 'ai_bulk_alt_text=1', $result );
		$this->assertStringContainsString( 'ai_attachment_ids=' . $image_id, $result );
	}

	/**
	 * Test that the bulk action handler ignores non-image attachments.
	 *
	 * @since x.x.x
	 */
	public function test_handle_bulk_action_filters_out_non_images(): void {
		$non_image_id = self::factory()->post->create(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'text/plain',
			)
		);

		$experiment = new Alt_Text_Generation();
		$redirect   = 'https://example.com/wp-admin/upload.php';
		$result     = $experiment->handle_bulk_action( $redirect, 'ai_generate_alt_text', array( $non_image_id ) );

		$this->assertSame( $redirect, $result, 'Redirect should be unchanged when no image attachments are selected.' );
	}

	/**
	 * Test that the bulk action handler ignores unrelated bulk actions.
	 *
	 * @since x.x.x
	 */
	public function test_handle_bulk_action_ignores_other_actions(): void {
		$experiment = new Alt_Text_Generation();
		$redirect   = 'https://example.com/wp-admin/upload.php';
		$result     = $experiment->handle_bulk_action( $redirect, 'delete', array( 1, 2, 3 ) );

		$this->assertSame( $redirect, $result );
	}
}
