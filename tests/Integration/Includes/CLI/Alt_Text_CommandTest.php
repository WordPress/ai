<?php
/**
 * Integration tests for the Alt_Text_Command WP-CLI class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\CLI
 */

namespace WordPress\AI\Tests\Integration\Includes\CLI;

use WP_UnitTestCase;
use WordPress\AI\CLI\Alt_Text_Command;

require_once __DIR__ . '/wp-cli-stubs.php';

/**
 * Alt_Text_Command test case.
 *
 * @covers \WordPress\AI\CLI\Alt_Text_Command
 *
 * @since x.x.x
 */
class Alt_Text_CommandTest extends WP_UnitTestCase {

	/**
	 * The command instance.
	 *
	 * @var \WordPress\AI\CLI\Alt_Text_Command
	 */
	private $command;

	/**
	 * Set up test case.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->command = new Alt_Text_Command();

		if ( class_exists( '\WP_CLI' ) && method_exists( '\WP_CLI', 'reset' ) ) {
			\WP_CLI::reset();
		}
	}

	/**
	 * Tear down test case.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		$this->unregister_fake_ability();
		remove_all_filters( 'wpai_has_ai_credentials' );
		remove_all_filters( 'wpai_pre_has_valid_credentials_check' );
		remove_all_actions( 'wp_abilities_api_init' );
		parent::tearDown();
	}

	/**
	 * Invokes a private method on the command instance via reflection.
	 *
	 * @param string $method_name The method name to invoke.
	 * @param array  $args        Arguments to pass to the method.
	 * @return mixed The method return value.
	 */
	private function invoke_private_method( string $method_name, array $args = array() ) {
		$reflection = new \ReflectionClass( $this->command );
		$method     = $reflection->getMethod( $method_name );

		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		return $method->invoke( $this->command, ...$args );
	}

	/**
	 * Gets captured WP_CLI messages at a given level.
	 *
	 * @param string|null $level The level to filter by, or null for all.
	 * @return array<int, string> The captured messages.
	 */
	private function get_cli_messages( ?string $level = null ): array {
		$messages = array();
		foreach ( \WP_CLI::$messages as $entry ) {
			if ( null === $level || $entry['level'] === $level ) {
				$messages[] = $entry['message'];
			}
		}
		return $messages;
	}

	/**
	 * Registers a real ai/alt-text-generation ability with a canned response.
	 *
	 * Uses the WP core test pattern of faking the current filter to satisfy
	 * `doing_action( 'wp_abilities_api_init' )` without triggering registered
	 * callbacks. This avoids re-entrant init calls from the registry singleton.
	 *
	 * @param array|\WP_Error $response The response from execute_callback.
	 */
	private function register_fake_ability( $response ): void {
		global $wp_current_filter;

		// Ensure the registry singleton is initialized so its own init action
		// fires before we fake the filter state.
		$registry = \WP_Abilities_Registry::get_instance();

		if ( null !== $registry && $registry->is_registered( 'ai/alt-text-generation' ) ) {
			wp_unregister_ability( 'ai/alt-text-generation' );
		}

		$previous_filter     = $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init';

		try {
			wp_register_ability(
				'ai/alt-text-generation',
				array(
					'label'               => 'Fake Alt Text',
					'description'         => 'Fake ability for testing.',
					'category'            => WPAI_DEFAULT_ABILITY_CATEGORY,
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'attachment_id' => array( 'type' => 'integer' ),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'alt_text' => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => static function () use ( $response ) {
						return $response;
					},
					'permission_callback' => '__return_true',
				)
			);
		} finally {
			$wp_current_filter = $previous_filter;
		}
	}

	/**
	 * Unregisters the test ability.
	 */
	private function unregister_fake_ability(): void {
		if ( ! function_exists( 'wp_unregister_ability' ) ) {
			return;
		}

		$registry = \WP_Abilities_Registry::get_instance();
		if ( null !== $registry && $registry->is_registered( 'ai/alt-text-generation' ) ) {
			wp_unregister_ability( 'ai/alt-text-generation' );
		}
	}

	/**
	 * Test ensure_admin_user sets an admin when no user is set.
	 */
	public function test_ensure_admin_user_sets_admin(): void {
		$this->factory->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( 0 );
		$this->assertEquals( 0, get_current_user_id() );

		$this->invoke_private_method( 'ensure_admin_user' );

		$this->assertNotEquals( 0, get_current_user_id() );
		$this->assertTrue( current_user_can( 'upload_files' ) );
	}

	/**
	 * Test ensure_admin_user does not change user when already set.
	 */
	public function test_ensure_admin_user_preserves_existing_user(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$this->invoke_private_method( 'ensure_admin_user' );

		$this->assertEquals( $user_id, get_current_user_id() );
	}

	/**
	 * Test ensure_admin_user errors when no admin exists.
	 */
	public function test_ensure_admin_user_errors_without_admin(): void {
		wp_set_current_user( 0 );
		// Delete all users.
		foreach ( get_users( array( 'fields' => 'ID' ) ) as $uid ) {
			wp_delete_user( (int) $uid );
		}

		$this->expectException( \WP_CLI_Test_Error_Exception::class );
		$this->invoke_private_method( 'ensure_admin_user' );
	}

	/**
	 * Test fetch_attachment_batch returns only images without alt text.
	 */
	public function test_fetch_attachment_batch_returns_images_without_alt_text(): void {
		$image_without_alt = $this->factory->attachment->create_upload_object( TESTS_REPO_ROOT_DIR . '/tests/data/sample.png' );
		$image_with_alt    = $this->factory->attachment->create_upload_object( TESTS_REPO_ROOT_DIR . '/tests/data/sample.png' );
		update_post_meta( $image_with_alt, '_wp_attachment_image_alt', 'Existing alt text' );

		$ids = $this->invoke_private_method( 'fetch_attachment_batch', array( false, 50, 0 ) );

		$this->assertContains( $image_without_alt, $ids );
		$this->assertNotContains( $image_with_alt, $ids );
	}

	/**
	 * Test fetch_attachment_batch with force flag includes images with alt text.
	 */
	public function test_fetch_attachment_batch_force_includes_all_images(): void {
		$image_without_alt = $this->factory->attachment->create_upload_object( TESTS_REPO_ROOT_DIR . '/tests/data/sample.png' );
		$image_with_alt    = $this->factory->attachment->create_upload_object( TESTS_REPO_ROOT_DIR . '/tests/data/sample.png' );
		update_post_meta( $image_with_alt, '_wp_attachment_image_alt', 'Existing alt text' );

		$ids = $this->invoke_private_method( 'fetch_attachment_batch', array( true, 50, 0 ) );

		$this->assertContains( $image_without_alt, $ids );
		$this->assertContains( $image_with_alt, $ids );
	}

	/**
	 * Test fetch_attachment_batch returns at most the requested batch size.
	 */
	public function test_fetch_attachment_batch_respects_batch_size(): void {
		$ids = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$ids[] = $this->factory->attachment->create_upload_object( TESTS_REPO_ROOT_DIR . '/tests/data/sample.png' );
		}

		$batch = $this->invoke_private_method( 'fetch_attachment_batch', array( true, 2, 0 ) );

		$this->assertCount( 2, $batch );
	}

	/**
	 * Test fetch_attachment_batch advances past a cursor ID.
	 */
	public function test_fetch_attachment_batch_advances_past_cursor(): void {
		$first  = $this->factory->attachment->create_upload_object( TESTS_REPO_ROOT_DIR . '/tests/data/sample.png' );
		$second = $this->factory->attachment->create_upload_object( TESTS_REPO_ROOT_DIR . '/tests/data/sample.png' );

		$batch = $this->invoke_private_method( 'fetch_attachment_batch', array( true, 50, $first ) );

		$this->assertNotContains( $first, $batch );
		$this->assertContains( $second, $batch );
	}

	/**
	 * Test count_matching_attachments counts images missing alt text by default.
	 */
	public function test_count_matching_attachments_counts_missing_alt(): void {
		$this->factory->attachment->create_upload_object( TESTS_REPO_ROOT_DIR . '/tests/data/sample.png' );
		$image_with_alt = $this->factory->attachment->create_upload_object( TESTS_REPO_ROOT_DIR . '/tests/data/sample.png' );
		update_post_meta( $image_with_alt, '_wp_attachment_image_alt', 'Has alt text' );

		$this->assertEquals( 1, $this->invoke_private_method( 'count_matching_attachments', array( false ) ) );
		$this->assertEquals( 2, $this->invoke_private_method( 'count_matching_attachments', array( true ) ) );
	}

	/**
	 * Test parse_ids_flag filters to valid images only.
	 */
	public function test_parse_ids_flag_filters_invalid(): void {
		$image_id = $this->factory->attachment->create_upload_object( TESTS_REPO_ROOT_DIR . '/tests/data/sample.png' );
		$post_id  = $this->factory->post->create();

		$ids = $this->invoke_private_method( 'parse_ids_flag', array( "$image_id,$post_id,99999" ) );

		$this->assertContains( $image_id, $ids );
		$this->assertNotContains( $post_id, $ids );
		$this->assertNotContains( 99999, $ids );
	}

	/**
	 * Test parse_ids_flag returns empty for no valid images.
	 */
	public function test_parse_ids_flag_returns_empty_when_invalid(): void {
		$ids = $this->invoke_private_method( 'parse_ids_flag', array( '99999,88888' ) );

		$this->assertEmpty( $ids );
	}

	/**
	 * Test free_batch_memory clears the query log.
	 */
	public function test_free_batch_memory_clears_query_log(): void {
		global $wpdb;

		$wpdb->queries = array( 'some query' );

		$this->invoke_private_method( 'free_batch_memory' );

		$this->assertEmpty( $wpdb->queries );
	}

	/**
	 * Test print_summary outputs correct format.
	 */
	public function test_print_summary_runs_without_error(): void {
		$stats = array(
			'generated'  => 5,
			'decorative' => 1,
			'skipped'    => 2,
			'failed'     => 1,
		);

		ob_start();
		$this->invoke_private_method( 'print_summary', array( $stats ) );
		ob_end_clean();

		$success_messages = $this->get_cli_messages( 'success' );
		$this->assertNotEmpty( $success_messages );
		$this->assertStringContainsString( '6', $success_messages[0] );
	}

	/**
	 * Test print_summary with no results.
	 */
	public function test_print_summary_with_zero_results(): void {
		$stats = array(
			'generated'  => 0,
			'decorative' => 0,
			'skipped'    => 0,
			'failed'     => 0,
		);

		ob_start();
		$this->invoke_private_method( 'print_summary', array( $stats ) );
		ob_end_clean();

		$this->assertEmpty( $this->get_cli_messages( 'success' ) );
		$this->assertContains( 'No alt text was generated.', $this->get_cli_messages( 'log' ) );
	}

	/**
	 * Test display_dry_run outputs table for explicit IDs without errors.
	 */
	public function test_display_dry_run_runs_without_error(): void {
		$image_id = $this->factory->attachment->create_upload_object( TESTS_REPO_ROOT_DIR . '/tests/data/sample.png' );

		ob_start();
		$this->invoke_private_method( 'display_dry_run', array( array( $image_id ), false, 1 ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( (string) $image_id, $output );
	}

	/**
	 * Test display_dry_run includes images with alt text when force is true.
	 *
	 * Reviewer feedback: dry-run results should match what an actual run would
	 * process, so passing --force should show images that already have alt text.
	 */
	public function test_display_dry_run_respects_force(): void {
		$image_with_alt = $this->factory->attachment->create_upload_object( TESTS_REPO_ROOT_DIR . '/tests/data/sample.png' );
		update_post_meta( $image_with_alt, '_wp_attachment_image_alt', 'Existing alt text' );

		// Without force: image with alt text should not appear.
		ob_start();
		$this->invoke_private_method( 'display_dry_run', array( null, false, 0 ) );
		$without_force = ob_get_clean();
		$this->assertStringNotContainsString( (string) $image_with_alt, $without_force );

		// With force: image with alt text should appear.
		ob_start();
		$this->invoke_private_method( 'display_dry_run', array( null, true, 1 ) );
		$with_force = ob_get_clean();
		$this->assertStringContainsString( (string) $image_with_alt, $with_force );
	}

	/**
	 * Test process_images generates and saves alt text.
	 */
	public function test_process_images_generates_and_saves_alt_text(): void {
		$image_id = $this->factory->attachment->create_upload_object( TESTS_REPO_ROOT_DIR . '/tests/data/sample.png' );

		$fake_ability = new class() {
			public function execute( $input ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
				return array( 'alt_text' => 'A beautiful sunset' );
			}
		};

		ob_start();
		$stats = $this->invoke_private_method( 'process_images', array( $fake_ability, array( $image_id ), 1, 10, 0, false ) );
		ob_end_clean();

		$this->assertEquals( 1, $stats['generated'] );
		$this->assertEquals( 0, $stats['decorative'] );
		$this->assertEquals( 0, $stats['skipped'] );
		$this->assertEquals( 0, $stats['failed'] );
		$this->assertEquals( 'A beautiful sunset', get_post_meta( $image_id, '_wp_attachment_image_alt', true ) );
	}

	/**
	 * Test process_images marks decorative images separately.
	 */
	public function test_process_images_handles_decorative(): void {
		$image_id = $this->factory->attachment->create_upload_object( TESTS_REPO_ROOT_DIR . '/tests/data/sample.png' );

		$fake_ability = new class() {
			public function execute( $input ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
				return array(
					'alt_text'      => '',
					'is_decorative' => true,
				);
			}
		};

		ob_start();
		$stats = $this->invoke_private_method( 'process_images', array( $fake_ability, array( $image_id ), 1, 10, 0, false ) );
		ob_end_clean();

		$this->assertEquals( 0, $stats['generated'] );
		$this->assertEquals( 1, $stats['decorative'] );
		$this->assertEquals( '', get_post_meta( $image_id, '_wp_attachment_image_alt', true ) );
	}

	/**
	 * Test process_images skips images that already have alt text without --force.
	 */
	public function test_process_images_skips_existing_alt_text(): void {
		$image_id = $this->factory->attachment->create_upload_object( TESTS_REPO_ROOT_DIR . '/tests/data/sample.png' );
		update_post_meta( $image_id, '_wp_attachment_image_alt', 'Existing alt text' );

		$fake_ability = new class() {
			public function execute( $input ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
				return array( 'alt_text' => 'New alt text' );
			}
		};

		ob_start();
		$stats = $this->invoke_private_method( 'process_images', array( $fake_ability, array( $image_id ), 1, 10, 0, false ) );
		ob_end_clean();

		$this->assertEquals( 0, $stats['generated'] );
		$this->assertEquals( 1, $stats['skipped'] );
		$this->assertEquals( 'Existing alt text', get_post_meta( $image_id, '_wp_attachment_image_alt', true ) );
	}

	/**
	 * Test process_images with --force overwrites existing alt text.
	 */
	public function test_process_images_force_overwrites(): void {
		$image_id = $this->factory->attachment->create_upload_object( TESTS_REPO_ROOT_DIR . '/tests/data/sample.png' );
		update_post_meta( $image_id, '_wp_attachment_image_alt', 'Old alt text' );

		$fake_ability = new class() {
			public function execute( $input ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
				return array( 'alt_text' => 'New alt text' );
			}
		};

		ob_start();
		$stats = $this->invoke_private_method( 'process_images', array( $fake_ability, array( $image_id ), 1, 10, 0, true ) );
		ob_end_clean();

		$this->assertEquals( 1, $stats['generated'] );
		$this->assertEquals( 0, $stats['skipped'] );
		$this->assertEquals( 'New alt text', get_post_meta( $image_id, '_wp_attachment_image_alt', true ) );
	}

	/**
	 * Test process_images handles errors from the ability.
	 */
	public function test_process_images_handles_errors(): void {
		$image_id = $this->factory->attachment->create_upload_object( TESTS_REPO_ROOT_DIR . '/tests/data/sample.png' );

		$fake_ability = new class() {
			public function execute( $input ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
				return new \WP_Error( 'test_error', 'Test error message' );
			}
		};

		ob_start();
		$stats = $this->invoke_private_method( 'process_images', array( $fake_ability, array( $image_id ), 1, 10, 0, false ) );
		ob_end_clean();

		$this->assertEquals( 1, $stats['failed'] );
		$this->assertEquals( 0, $stats['generated'] );
		$this->assertStringContainsString( 'Test error message', $this->get_cli_messages( 'warning' )[0] );
	}

	/**
	 * Test generate command errors when ability is not registered.
	 */
	public function test_generate_errors_when_ability_missing(): void {
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::get_registered' );

		$this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( 0 );

		$this->expectException( \WP_CLI_Test_Error_Exception::class );
		$this->expectExceptionMessageMatches( '/ability is not registered/i' );

		$this->command->generate( array(), array() );
	}

	/**
	 * Test generate errors when AI credentials are not configured.
	 */
	public function test_generate_errors_without_credentials(): void {
		$this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->register_fake_ability( array( 'alt_text' => 'test' ) );
		add_filter( 'wpai_has_ai_credentials', '__return_true' );
		add_filter( 'wpai_pre_has_valid_credentials_check', '__return_false' );

		$this->expectException( \WP_CLI_Test_Error_Exception::class );
		$this->expectExceptionMessageMatches( '/credentials/i' );

		$this->command->generate( array(), array() );
	}

	/**
	 * Test generate with no matching images completes successfully.
	 */
	public function test_generate_with_no_images(): void {
		$this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->register_fake_ability( array( 'alt_text' => 'test' ) );
		add_filter( 'wpai_has_ai_credentials', '__return_true' );
		add_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );

		$this->command->generate( array(), array() );

		$success_messages = $this->get_cli_messages( 'success' );
		$this->assertNotEmpty( $success_messages );
		$this->assertStringContainsString( 'No images found', $success_messages[0] );
	}

	/**
	 * Test generate processes attachments end-to-end.
	 */
	public function test_generate_processes_attachments(): void {
		$this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->register_fake_ability( array( 'alt_text' => 'A generated description' ) );
		add_filter( 'wpai_has_ai_credentials', '__return_true' );
		add_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );

		$image_id = $this->factory->attachment->create_upload_object( TESTS_REPO_ROOT_DIR . '/tests/data/sample.png' );

		ob_start();
		$this->command->generate(
			array(),
			array(
				'delay' => 0,
				'yes'   => true,
			)
		);
		ob_end_clean();

		$this->assertEquals(
			'A generated description',
			get_post_meta( $image_id, '_wp_attachment_image_alt', true )
		);

		$log_messages = $this->get_cli_messages( 'log' );
		$this->assertTrue(
			(bool) array_filter(
				$log_messages,
				static fn( string $msg ): bool => false !== strpos( $msg, 'Found 1' )
			)
		);
	}

	/**
	 * Test generate with --dry-run does not modify alt text.
	 */
	public function test_generate_dry_run_does_not_modify(): void {
		$this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->register_fake_ability( array( 'alt_text' => 'Should not be saved' ) );
		add_filter( 'wpai_has_ai_credentials', '__return_true' );
		add_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );

		$image_id = $this->factory->attachment->create_upload_object( TESTS_REPO_ROOT_DIR . '/tests/data/sample.png' );

		ob_start();
		$this->command->generate( array(), array( 'dry-run' => true ) );
		ob_end_clean();

		$this->assertEmpty( get_post_meta( $image_id, '_wp_attachment_image_alt', true ) );
	}

	/**
	 * Test generate iterates across multiple paginated batches without holding the full
	 * ID set in memory (reviewer feedback for sites with thousands of attachments).
	 */
	public function test_generate_processes_multiple_batches(): void {
		$this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->register_fake_ability( array( 'alt_text' => 'Generated alt' ) );
		add_filter( 'wpai_has_ai_credentials', '__return_true' );
		add_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );

		$image_ids = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$image_ids[] = $this->factory->attachment->create_upload_object( TESTS_REPO_ROOT_DIR . '/tests/data/sample.png' );
		}

		ob_start();
		$this->command->generate(
			array(),
			array(
				'batch-size' => 2,
				'delay'      => 0,
				'yes'        => true,
			)
		);
		ob_end_clean();

		foreach ( $image_ids as $id ) {
			$this->assertEquals( 'Generated alt', get_post_meta( $id, '_wp_attachment_image_alt', true ) );
		}
	}

	/**
	 * Test generate prompts for confirmation when --yes is not passed.
	 */
	public function test_generate_prompts_for_confirmation(): void {
		$this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->register_fake_ability( array( 'alt_text' => 'A generated description' ) );
		add_filter( 'wpai_has_ai_credentials', '__return_true' );
		add_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );

		$this->factory->attachment->create_upload_object( TESTS_REPO_ROOT_DIR . '/tests/data/sample.png' );

		ob_start();
		$this->command->generate( array(), array( 'delay' => 0 ) );
		ob_end_clean();

		$this->assertNotEmpty(
			$this->get_cli_messages( 'confirm' ),
			'Expected a confirmation prompt when --yes is not provided.'
		);
	}
}
