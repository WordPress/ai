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
		remove_all_filters( 'ai_experiments_experiment_alt-text-generation_enabled' );
		unset( $_GET['wpai_bulk_alt_text'], $_GET['wpai_attachment_ids'] );
		wp_dequeue_script( 'ai_alt_text_generation_bulk' );
		wp_deregister_script( 'ai_alt_text_generation_bulk' );
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

		$this->assertArrayHasKey( 'wpai_generate_alt_text', $actions );
		$this->assertSame( 'Generate Alt Text', $actions['wpai_generate_alt_text'] );
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
		$this->assertArrayNotHasKey( 'wpai_generate_alt_text', $actions );

		remove_all_filters( 'wpai_feature_alt-text-generation_enabled' );
	}

	/**
	 * Test that the bulk action handler adds query args for image attachments.
	 *
	 * @since x.x.x
	 */
	public function test_handle_bulk_action_adds_query_args_for_images(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$image_id = self::factory()->attachment->create_upload_object(
			dirname( __FILE__ ) . '/../../../../data/sample.png'
		);

		$experiment   = new Alt_Text_Generation();
		$redirect     = 'https://example.com/wp-admin/upload.php';
		$result       = $experiment->handle_bulk_action( $redirect, 'wpai_generate_alt_text', array( $image_id ) );

		$this->assertStringContainsString( 'wpai_bulk_alt_text=1', $result );
		$this->assertStringContainsString( 'wpai_attachment_ids=' . $image_id, $result );
	}

	/**
	 * Test that the bulk action handler ignores non-image attachments.
	 *
	 * @since x.x.x
	 */
	public function test_handle_bulk_action_filters_out_non_images(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$non_image_id = self::factory()->post->create(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'text/plain',
			)
		);

		$experiment = new Alt_Text_Generation();
		$redirect   = 'https://example.com/wp-admin/upload.php';
		$result     = $experiment->handle_bulk_action( $redirect, 'wpai_generate_alt_text', array( $non_image_id ) );

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

	/**
	 * Test that the bulk action handler requires upload_files capability.
	 *
	 * @since x.x.x
	 */
	public function test_handle_bulk_action_requires_capability(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$experiment = new Alt_Text_Generation();
		$redirect   = 'https://example.com/wp-admin/upload.php';
		$result     = $experiment->handle_bulk_action( $redirect, 'wpai_generate_alt_text', array( 1 ) );

		$this->assertSame( $redirect, $result, 'Redirect should be unchanged when user lacks upload_files capability.' );
	}

	/**
	 * Test that the bulk script is enqueued on upload.php when valid GET params and capability are present.
	 *
	 * @since x.x.x
	 */
	public function test_maybe_enqueue_bulk_script_enqueues_with_valid_params(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$_GET['wpai_bulk_alt_text']  = '1';
		$_GET['wpai_attachment_ids'] = '1,2';

		// Asset_Loader::enqueue_script() bails early if the compiled JS file does not exist
		// (build and test jobs run in parallel in CI). Create a stub so the enqueue proceeds.
		$script_path  = WPAI_PLUGIN_DIR . 'build-scripts/experiments/alt-text-generation-bulk.js';
		$stub_created = ! file_exists( $script_path );
		if ( $stub_created ) {
			wp_mkdir_p( dirname( $script_path ) );
			file_put_contents( $script_path, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$experiment = new Alt_Text_Generation();
		$experiment->register();
		$experiment->maybe_enqueue_media_library_assets( 'upload.php' );

		$enqueued = wp_script_is( 'ai_alt_text_generation_bulk', 'enqueued' );

		if ( $stub_created ) {
			unlink( $script_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}

		$this->assertTrue( $enqueued );
	}

	/**
	 * Test that the bulk script is not enqueued when the GET flag is absent.
	 *
	 * @since x.x.x
	 */
	public function test_maybe_enqueue_bulk_script_skips_without_flag(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$experiment = new Alt_Text_Generation();
		$experiment->register();
		$experiment->maybe_enqueue_media_library_assets( 'upload.php' );

		$this->assertFalse( wp_script_is( 'ai_alt_text_generation_bulk', 'enqueued' ) );
	}

	/**
	 * Test that the bulk script is not enqueued when the user lacks the upload_files capability.
	 *
	 * @since x.x.x
	 */
	public function test_maybe_enqueue_bulk_script_skips_without_capability(): void {
		wp_set_current_user( 0 );

		$_GET['wpai_bulk_alt_text']  = '1';
		$_GET['wpai_attachment_ids'] = '1,2';

		$experiment = new Alt_Text_Generation();
		$experiment->register();
		$experiment->maybe_enqueue_media_library_assets( 'upload.php' );

		$this->assertFalse( wp_script_is( 'ai_alt_text_generation_bulk', 'enqueued' ) );
	}

	/**
	 * Test that the bulk script is not enqueued when the attachment IDs resolve to an empty list.
	 *
	 * @since x.x.x
	 */
	public function test_maybe_enqueue_bulk_script_skips_empty_ids(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$_GET['wpai_bulk_alt_text']  = '1';
		$_GET['wpai_attachment_ids'] = '0,0';

		$experiment = new Alt_Text_Generation();
		$experiment->register();
		$experiment->maybe_enqueue_media_library_assets( 'upload.php' );

		$this->assertFalse( wp_script_is( 'ai_alt_text_generation_bulk', 'enqueued' ) );
	}
}
