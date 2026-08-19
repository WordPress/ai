<?php
/**
 * Integration tests for the Summarization class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments
 */

namespace WordPress\AI\Tests\Integration\Experiments\Summarization;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Experiments\Experiments;
use WordPress\AI\Experiments\Summarization\Summarization;
use WordPress\AI\Features\Loader;
use WordPress\AI\Features\Registry;

/**
 * Summarization test case.
 *
 * @since 0.2.0
 */
class SummarizationTest extends WP_UnitTestCase {
	/**
	 * Set up test case.
	 *
	 * @since 0.2.0
	 */
	public function setUp(): void {
		parent::setUp();

		// Set up mock AI credentials so has_ai_credentials() returns true.
		update_option( 'wp_ai_client_provider_credentials', array( 'openai' => 'test-api-key' ) );

		// Mock has_valid_ai_credentials to return true for tests.
		add_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );

		// Enable experiments globally and individually.
		update_option( 'wpai_features_enabled', true );
		update_option( 'wpai_feature_summarization_enabled', true );

		$experiments = new Experiments();
		$experiments->init();

		$registry = new Registry();
		$loader   = new Loader( $registry );
		$loader->init();

		$experiment = $registry->get_feature( 'summarization' );
		$this->assertInstanceOf( Summarization::class, $experiment, 'Summarization experiment should be registered in the registry.' );
	}

	/**
	 * Tear down test case.
	 *
	 * @since 0.2.0
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_summarization_enabled' );
		delete_option( 'wp_ai_client_provider_credentials' );
		remove_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );
		remove_filter( 'wpai_default_feature_classes', array( Experiments::class, 'register_default_experiment_classes' ), 9 );

		// Reset script state so the bulk enqueue assertions do not depend on test order.
		wp_dequeue_script( 'ai_summarization_bulk' );
		wp_deregister_script( 'ai_summarization_bulk' );

		parent::tearDown();
	}

	/**
	 * Test that the experiment is registered correctly.
	 *
	 * @since 0.2.0
	 */
	public function test_experiment_registration() {
		$experiment = new Summarization();

		$this->assertEquals( 'summarization', $experiment->get_id() );
		$this->assertEquals( 'Content Summarization', $experiment->get_label() );
		$this->assertEquals( Experiment_Category::EDITOR, $experiment->get_category() );
		$this->assertTrue( $experiment->is_enabled() );
	}

	/**
	 * Tests that the editor assets are registered with the block editor assets hook.
	 *
	 * @since 1.0.2
	 */
	public function test_register_uses_block_editor_assets_hook() {
		$experiment = new Summarization();

		try {
			$experiment->register();

			$this->assertSame(
				5,
				has_action( 'enqueue_block_editor_assets', array( $experiment, 'enqueue_assets' ) ),
				'Summarization editor assets should load before other block editor controls.'
			);
			$this->assertFalse(
				has_action( 'admin_enqueue_scripts', array( $experiment, 'enqueue_assets' ) ),
				'Summarization editor assets should not load through the general admin assets hook.'
			);
		} finally {
			remove_action( 'enqueue_block_editor_assets', array( $experiment, 'enqueue_assets' ), 5 );
			remove_action( 'admin_enqueue_scripts', array( $experiment, 'enqueue_assets' ) );
			remove_action( 'wp_abilities_api_init', array( $experiment, 'register_abilities' ) );
			remove_action( 'enqueue_block_assets', array( $experiment, 'enqueue_block_assets' ) );
			remove_action( 'load-edit.php', array( $experiment, 'register_bulk_action_hooks_for_screen' ) );
			remove_action( 'admin_enqueue_scripts', array( $experiment, 'maybe_enqueue_bulk_assets' ) );
		}
	}

	/**
	 * Tests that enqueue_assets() does not load outside the post editor.
	 *
	 * @since 1.0.2
	 */
	public function test_enqueue_assets_skips_non_post_screens() {
		$experiment = new Summarization();

		set_current_screen( 'dashboard' );

		try {
			$experiment->enqueue_assets();

			$this->assertFalse(
				wp_script_is( 'ai_summarization', 'enqueued' ),
				'Summarization assets should not load outside post editor screens.'
			);
		} finally {
			set_current_screen( 'front' );
		}
	}

	/**
	 * Tests that enqueue_assets() localizes the default minimum content length.
	 *
	 * @since 1.1.0
	 */
	public function test_enqueue_assets_localizes_default_min_content_length() {
		set_current_screen( 'post' );

		$experiment = new Summarization();
		$experiment->enqueue_assets();

		$this->assertTrue( wp_script_is( 'ai_summarization', 'enqueued' ) );
		$this->assertStringContainsString(
			'"minContentLength":"250"',
			(string) wp_scripts()->get_data( 'ai_summarization', 'data' )
		);
	}

	/**
	 * Tests that enqueue_assets() localizes the filtered minimum content length.
	 *
	 * @since 1.1.0
	 */
	public function test_enqueue_assets_localizes_filtered_min_content_length() {
		set_current_screen( 'post' );

		$filter = static function () {
			return 250;
		};

		add_filter( 'wpai_min_content_length', $filter );

		$experiment = new Summarization();
		$experiment->enqueue_assets();

		remove_filter( 'wpai_min_content_length', $filter );

		$this->assertStringContainsString(
			'"minContentLength":"250"',
			(string) wp_scripts()->get_data( 'ai_summarization', 'data' )
		);
	}

	/**
	 * Tests that register_bulk_action() adds the Generate Summary option.
	 *
	 * @since 1.2.0
	 */
	public function test_register_bulk_action_adds_option(): void {
		$experiment = new Summarization();
		$result     = $experiment->register_bulk_action( array() );

		$this->assertArrayHasKey( 'wpai_generate_summary', $result );
		$this->assertEquals( 'Generate Summary', $result['wpai_generate_summary'] );
	}

	/**
	 * Tests that register_bulk_action() does nothing when the experiment is disabled.
	 *
	 * @since 1.2.0
	 */
	public function test_register_bulk_action_skips_when_experiment_disabled(): void {
		update_option( 'wpai_feature_summarization_enabled', false );

		$experiment = new Summarization();
		$result     = $experiment->register_bulk_action( array() );

		$this->assertArrayNotHasKey( 'wpai_generate_summary', $result );
	}

	/**
	 * Tests that handle_bulk_action() does nothing for users without edit_posts capability.
	 *
	 * @since 1.2.0
	 */
	public function test_handle_bulk_action_requires_edit_posts_capability(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$post_id    = self::factory()->post->create();
		$experiment = new Summarization();
		$url        = 'https://example.com/wp-admin/edit.php';
		$result     = $experiment->handle_bulk_action( $url, 'wpai_generate_summary', array( $post_id ) );

		$this->assertSame( $url, $result );
	}

	/**
	 * Tests that handle_bulk_action() appends the expected query args to the redirect URL.
	 *
	 * @since 1.2.0
	 */
	public function test_handle_bulk_action_appends_post_ids_to_redirect_url(): void {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		$post_id_1 = self::factory()->post->create();
		$post_id_2 = self::factory()->post->create();

		$experiment = new Summarization();
		$result     = $experiment->handle_bulk_action(
			'https://example.com/wp-admin/edit.php',
			'wpai_generate_summary',
			array( $post_id_1, $post_id_2 )
		);

		parse_str( (string) wp_parse_url( $result, PHP_URL_QUERY ), $query );

		$this->assertEquals( '1', $query['wpai_bulk_summary'] );
		$this->assertEqualsCanonicalizing(
			array( $post_id_1, $post_id_2 ),
			array_map( 'intval', explode( ',', $query['wpai_post_ids'] ) )
		);
		$this->assertArrayHasKey( '_wpai_bulk_nonce', $query, 'The redirect must be signed so the next request can verify it.' );
		$this->assertNotFalse(
			wp_verify_nonce( $query['_wpai_bulk_nonce'], 'wpai_bulk_summary' ),
			'The signed redirect must carry a nonce valid for the bulk summary action.'
		);
	}

	/**
	 * Tests that handle_bulk_action() returns the original URL when no editable posts remain.
	 *
	 * @since 1.2.0
	 */
	public function test_handle_bulk_action_returns_unchanged_url_when_no_editable_posts(): void {
		$author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$admin_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $author_id );

		$others_post_id = self::factory()->post->create(
			array(
				'post_author' => $admin_id,
				'post_status' => 'draft',
			)
		);

		$experiment = new Summarization();
		$url        = 'https://example.com/wp-admin/edit.php';
		$result     = $experiment->handle_bulk_action( $url, 'wpai_generate_summary', array( $others_post_id ) );

		$this->assertSame( $url, $result );
	}

	/**
	 * Tests that maybe_enqueue_bulk_assets() does nothing on non-edit screens.
	 *
	 * @since 1.2.0
	 */
	public function test_maybe_enqueue_bulk_assets_skips_non_edit_screens(): void {
		$original_get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
			wp_set_current_user( $editor_id );

			$_GET['wpai_bulk_summary'] = '1';
			$_GET['wpai_post_ids']     = '1';
			$_GET['_wpai_bulk_nonce']  = wp_create_nonce( 'wpai_bulk_summary' );

			$experiment = new Summarization();
			$experiment->maybe_enqueue_bulk_assets( 'post.php' );

			$this->assertFalse( wp_script_is( 'ai_summarization_bulk', 'enqueued' ) );
		} finally {
			$_GET = $original_get; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
	}

	/**
	 * Tests that maybe_enqueue_bulk_assets() does nothing when the query param is absent.
	 *
	 * @since 1.2.0
	 */
	public function test_maybe_enqueue_bulk_assets_skips_without_query_param(): void {
		$original_get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
			wp_set_current_user( $editor_id );

			unset( $_GET['wpai_bulk_summary'], $_GET['wpai_post_ids'], $_GET['_wpai_bulk_nonce'] );

			$experiment = new Summarization();
			$experiment->maybe_enqueue_bulk_assets( 'edit.php' );

			$this->assertFalse( wp_script_is( 'ai_summarization_bulk', 'enqueued' ) );
		} finally {
			$_GET = $original_get; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
	}

	/**
	 * Tests that maybe_enqueue_bulk_assets() enqueues the bulk script when all conditions are met.
	 *
	 * @since 1.2.0
	 */
	public function test_maybe_enqueue_bulk_assets_enqueues_script(): void {
		$original_get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
			wp_set_current_user( $editor_id );

			$post_id                   = self::factory()->post->create();
			$_GET['wpai_bulk_summary'] = '1';
			$_GET['wpai_post_ids']     = (string) $post_id;
			$_GET['_wpai_bulk_nonce']  = wp_create_nonce( 'wpai_bulk_summary' );

			$experiment = new Summarization();
			$experiment->maybe_enqueue_bulk_assets( 'edit.php' );

			$this->assertTrue( wp_script_is( 'ai_summarization_bulk', 'enqueued' ) );
		} finally {
			$_GET = $original_get; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
	}

	/**
	 * Tests that maybe_enqueue_bulk_assets() does nothing when the nonce is missing.
	 *
	 * Enqueueing is the trigger for a run that writes post content, so an
	 * unsigned request must not start one. Guards against CSRF where a victim is
	 * lured into loading an attacker-supplied admin URL.
	 *
	 * @since x.x.x
	 */
	public function test_maybe_enqueue_bulk_assets_skips_without_nonce(): void {
		$original_get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
			wp_set_current_user( $editor_id );

			$post_id                   = self::factory()->post->create();
			$_GET['wpai_bulk_summary'] = '1';
			$_GET['wpai_post_ids']     = (string) $post_id;
			unset( $_GET['_wpai_bulk_nonce'] );

			$experiment = new Summarization();
			$experiment->maybe_enqueue_bulk_assets( 'edit.php' );

			$this->assertFalse( wp_script_is( 'ai_summarization_bulk', 'enqueued' ) );
		} finally {
			$_GET = $original_get; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
	}

	/**
	 * Tests that maybe_enqueue_bulk_assets() does nothing when the nonce is invalid.
	 *
	 * @since x.x.x
	 */
	public function test_maybe_enqueue_bulk_assets_skips_with_invalid_nonce(): void {
		$original_get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
			wp_set_current_user( $editor_id );

			$post_id                   = self::factory()->post->create();
			$_GET['wpai_bulk_summary'] = '1';
			$_GET['wpai_post_ids']     = (string) $post_id;
			$_GET['_wpai_bulk_nonce']  = 'not-a-valid-nonce';

			$experiment = new Summarization();
			$experiment->maybe_enqueue_bulk_assets( 'edit.php' );

			$this->assertFalse( wp_script_is( 'ai_summarization_bulk', 'enqueued' ) );
		} finally {
			$_GET = $original_get; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
	}

	/**
	 * Tests that a nonce created for a different action does not unlock the bulk run.
	 *
	 * @since x.x.x
	 */
	public function test_maybe_enqueue_bulk_assets_skips_with_nonce_for_other_action(): void {
		$original_get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
			wp_set_current_user( $editor_id );

			$post_id                   = self::factory()->post->create();
			$_GET['wpai_bulk_summary'] = '1';
			$_GET['wpai_post_ids']     = (string) $post_id;
			$_GET['_wpai_bulk_nonce']  = wp_create_nonce( 'wpai_bulk_alt_text' );

			$experiment = new Summarization();
			$experiment->maybe_enqueue_bulk_assets( 'edit.php' );

			$this->assertFalse( wp_script_is( 'ai_summarization_bulk', 'enqueued' ) );
		} finally {
			$_GET = $original_get; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
	}

	/**
	 * Tests that maybe_enqueue_bulk_assets() caps the batch at the configured maximum.
	 *
	 * Each post in a run costs one billed model call, so the batch is bounded and
	 * the overflow count is handed to the script to report.
	 *
	 * @since x.x.x
	 */
	public function test_maybe_enqueue_bulk_assets_caps_batch_size(): void {
		$original_get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$cap = static function (): int {
			return 2;
		};

		add_filter( 'wpai_bulk_action_max_items', $cap );

		// Asset_Loader::enqueue_script() bails when the .asset.php metadata file is
		// absent (build and test jobs run in parallel in CI), which would leave no
		// localized data to assert on. Create a stub so the enqueue proceeds.
		$asset_path   = WPAI_PLUGIN_DIR . 'build-scripts/experiments/summarization-bulk.asset.php';
		$stub_created = ! file_exists( $asset_path );
		if ( $stub_created ) {
			wp_mkdir_p( dirname( $asset_path ) );
			file_put_contents( $asset_path, "<?php return array( 'dependencies' => array(), 'version' => '1.0.0' );" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		try {
			$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
			wp_set_current_user( $editor_id );

			$post_ids = self::factory()->post->create_many( 4 );

			$_GET['wpai_bulk_summary'] = '1';
			$_GET['wpai_post_ids']     = implode( ',', $post_ids );
			$_GET['_wpai_bulk_nonce']  = wp_create_nonce( 'wpai_bulk_summary' );

			$experiment = new Summarization();
			$experiment->maybe_enqueue_bulk_assets( 'edit.php' );

			$data = wp_scripts()->get_data( 'ai_summarization_bulk', 'data' );

			$this->assertIsString( $data );
			$this->assertStringContainsString( '"postIds":[' . $post_ids[0] . ',' . $post_ids[1] . ']', $data );
			// wp_localize_script() stringifies scalar values.
			$this->assertStringContainsString( '"truncatedCount":"2"', $data );
		} finally {
			if ( $stub_created ) {
				unlink( $asset_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
			remove_filter( 'wpai_bulk_action_max_items', $cap );
			$_GET = $original_get; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
	}

	/**
	 * Tests that the bulk summary trigger params are registered as removable query args.
	 *
	 * Core cleans removable args out of the address bar, so a reload of the
	 * results page does not re-trigger the whole generation.
	 *
	 * @since 1.3.0
	 */
	public function test_bulk_summary_params_are_removable_query_args(): void {
		$experiment = new Summarization();
		$experiment->register();

		$removable = wp_removable_query_args();

		$this->assertContains( 'wpai_bulk_summary', $removable );
		$this->assertContains( 'wpai_post_ids', $removable );
		$this->assertContains( '_wpai_bulk_nonce', $removable, 'The nonce must not linger in the address bar or browser history.' );
	}

	/**
	 * Tests that maybe_enqueue_bulk_assets() scrubs the trigger params from the request URI.
	 *
	 * Sort header links are built from the request URI and only strip `paged`,
	 * so leaving the params in place re-triggers generation on every sort click.
	 *
	 * @since 1.3.0
	 */
	public function test_maybe_enqueue_bulk_assets_scrubs_request_uri(): void {
		$original_get         = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$original_request_uri = $_SERVER['REQUEST_URI'] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		try {
			$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
			wp_set_current_user( $editor_id );

			$post_id                   = self::factory()->post->create();
			$nonce                     = wp_create_nonce( 'wpai_bulk_summary' );
			$_GET['wpai_bulk_summary'] = '1';
			$_GET['wpai_post_ids']     = (string) $post_id;
			$_GET['_wpai_bulk_nonce']  = $nonce;
			$_SERVER['REQUEST_URI']    = '/wp-admin/edit.php?wpai_bulk_summary=1&wpai_post_ids=' . $post_id . '&_wpai_bulk_nonce=' . $nonce . '&orderby=date&order=desc'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			$experiment = new Summarization();
			$experiment->maybe_enqueue_bulk_assets( 'edit.php' );

			// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Asserting on the raw value.
			$this->assertStringNotContainsString( 'wpai_bulk_summary', $_SERVER['REQUEST_URI'] );
			$this->assertStringNotContainsString( 'wpai_post_ids', $_SERVER['REQUEST_URI'] );
			$this->assertStringNotContainsString( '_wpai_bulk_nonce', $_SERVER['REQUEST_URI'] );
			$this->assertStringContainsString( 'orderby=date', $_SERVER['REQUEST_URI'], 'Unrelated query args must survive the scrub.' );
			// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		} finally {
			$_GET                   = $original_get; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$_SERVER['REQUEST_URI'] = $original_request_uri;
		}
	}

	/**
	 * Tests that register() wires register_bulk_action_hooks_for_screen() onto load-edit.php.
	 *
	 * @since 1.2.0
	 */
	public function test_register_wires_bulk_action_hooks_via_load_edit(): void {
		$experiment = new Summarization();

		try {
			$experiment->register();

			$this->assertNotFalse(
				has_action( 'load-edit.php', array( $experiment, 'register_bulk_action_hooks_for_screen' ) )
			);
		} finally {
			remove_action( 'load-edit.php', array( $experiment, 'register_bulk_action_hooks_for_screen' ) );
			remove_action( 'enqueue_block_editor_assets', array( $experiment, 'enqueue_assets' ), 5 );
			remove_action( 'enqueue_block_assets', array( $experiment, 'enqueue_block_assets' ) );
			remove_action( 'wp_abilities_api_init', array( $experiment, 'register_abilities' ) );
			remove_action( 'admin_enqueue_scripts', array( $experiment, 'maybe_enqueue_bulk_assets' ) );
		}
	}

	/**
	 * Tests that register_bulk_action_hooks_for_screen() registers filters for REST-enabled post types.
	 *
	 * @since 1.2.0
	 */
	public function test_register_bulk_action_hooks_for_screen_registers_for_rest_post_type(): void {
		$original_get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$_GET['post_type'] = 'post';

			$experiment = new Summarization();
			$experiment->register_bulk_action_hooks_for_screen();

			$this->assertNotFalse(
				has_filter( 'bulk_actions-edit-post', array( $experiment, 'register_bulk_action' ) )
			);
			$this->assertNotFalse(
				has_filter( 'handle_bulk_actions-edit-post', array( $experiment, 'handle_bulk_action' ) )
			);
		} finally {
			remove_filter( 'bulk_actions-edit-post', array( $experiment, 'register_bulk_action' ) );
			remove_filter( 'handle_bulk_actions-edit-post', array( $experiment, 'handle_bulk_action' ) );
			$_GET = $original_get; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
	}

	/**
	 * Tests that register_bulk_action_hooks_for_screen() skips post types not exposed in REST.
	 *
	 * @since 1.2.0
	 */
	public function test_register_bulk_action_hooks_for_screen_skips_non_rest_post_types(): void {
		$original_get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		register_post_type(
			'no_rest_cpt',
			array(
				'public'       => true,
				'show_in_rest' => false,
				'show_ui'      => true,
			)
		);

		try {
			$_GET['post_type'] = 'no_rest_cpt';

			$experiment = new Summarization();
			$experiment->register_bulk_action_hooks_for_screen();

			$this->assertFalse(
				has_filter( 'bulk_actions-edit-no_rest_cpt', array( $experiment, 'register_bulk_action' ) )
			);
		} finally {
			$_GET = $original_get; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			unregister_post_type( 'no_rest_cpt' );
		}
	}

	/**
	 * Tests that register_bulk_action_hooks_for_screen() skips the attachment post type for summarization.
	 *
	 * @since 1.2.0
	 */
	public function test_register_bulk_action_summarization_hooks_for_screen_skips_attachment(): void {
		$original_get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		try {
			$_GET['post_type'] = 'attachment';

			$experiment = new Summarization();
			$experiment->register_bulk_action_hooks_for_screen();

			$this->assertFalse(
				has_filter( 'bulk_actions-edit-attachment', array( $experiment, 'register_bulk_action' ) )
			);
		} finally {
			$_GET = $original_get; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
	}
}
