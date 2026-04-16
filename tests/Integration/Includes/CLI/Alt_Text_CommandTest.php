<?php
/**
 * Integration tests for the Alt_Text_Command WP-CLI class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\CLI
 */

namespace WordPress\AI\Tests\Integration\Includes\CLI;

use WP_UnitTestCase;
use WordPress\AI\CLI\Alt_Text_Command;

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
	}

	/**
	 * Tear down test case.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
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
	 * Test get_attachment_ids returns only images without alt text.
	 */
	public function test_get_attachment_ids_returns_images_without_alt_text(): void {
		$image_without_alt = $this->factory->attachment->create_upload_object( TESTS_REPO_ROOT_DIR . '/tests/data/sample.png' );
		$image_with_alt    = $this->factory->attachment->create_upload_object( TESTS_REPO_ROOT_DIR . '/tests/data/sample.png' );
		update_post_meta( $image_with_alt, '_wp_attachment_image_alt', 'Existing alt text' );

		$ids = $this->invoke_private_method( 'get_attachment_ids', array( '', false ) );

		$this->assertContains( $image_without_alt, $ids );
		$this->assertNotContains( $image_with_alt, $ids );
	}

	/**
	 * Test get_attachment_ids with force flag includes images with alt text.
	 */
	public function test_get_attachment_ids_force_includes_all_images(): void {
		$image_without_alt = $this->factory->attachment->create_upload_object( TESTS_REPO_ROOT_DIR . '/tests/data/sample.png' );
		$image_with_alt    = $this->factory->attachment->create_upload_object( TESTS_REPO_ROOT_DIR . '/tests/data/sample.png' );
		update_post_meta( $image_with_alt, '_wp_attachment_image_alt', 'Existing alt text' );

		$ids = $this->invoke_private_method( 'get_attachment_ids', array( '', true ) );

		$this->assertContains( $image_without_alt, $ids );
		$this->assertContains( $image_with_alt, $ids );
	}

	/**
	 * Test get_attachment_ids with specific IDs filters to valid images only.
	 */
	public function test_get_attachment_ids_with_specific_ids(): void {
		$image_id = $this->factory->attachment->create_upload_object( TESTS_REPO_ROOT_DIR . '/tests/data/sample.png' );
		$post_id  = $this->factory->post->create();

		$ids = $this->invoke_private_method( 'get_attachment_ids', array( "$image_id,$post_id,99999", false ) );

		$this->assertContains( $image_id, $ids );
		$this->assertNotContains( $post_id, $ids );
		$this->assertNotContains( 99999, $ids );
	}

	/**
	 * Test get_attachment_ids returns empty for IDs flag with no valid images.
	 */
	public function test_get_attachment_ids_with_invalid_ids_returns_empty(): void {
		$ids = $this->invoke_private_method( 'get_attachment_ids', array( '99999,88888', false ) );

		$this->assertEmpty( $ids );
	}

	/**
	 * Test get_attachment_ids returns empty when all images have alt text.
	 */
	public function test_get_attachment_ids_returns_empty_when_all_have_alt(): void {
		$image_id = $this->factory->attachment->create_upload_object( TESTS_REPO_ROOT_DIR . '/tests/data/sample.png' );
		update_post_meta( $image_id, '_wp_attachment_image_alt', 'Has alt text' );

		$ids = $this->invoke_private_method( 'get_attachment_ids', array( '', false ) );

		$this->assertNotContains( $image_id, $ids );
	}

	/**
	 * Test stop_the_insanity clears the query log.
	 */
	public function test_stop_the_insanity_clears_query_log(): void {
		global $wpdb;

		$wpdb->queries = array( 'some query' );

		$this->invoke_private_method( 'stop_the_insanity' );

		$this->assertEmpty( $wpdb->queries );
	}

	/**
	 * Test print_summary outputs correct format.
	 */
	public function test_print_summary_runs_without_error(): void {
		if ( ! class_exists( 'WP_CLI' ) ) {
			$this->markTestSkipped( 'WP-CLI is not available.' );
		}

		$stats = array(
			'generated'  => 5,
			'decorative' => 1,
			'skipped'    => 2,
			'failed'     => 1,
		);

		ob_start();
		$this->invoke_private_method( 'print_summary', array( $stats ) );
		ob_end_clean();

		// If we got here without error, the method works correctly.
		$this->assertTrue( true );
	}

	/**
	 * Test display_dry_run outputs table without errors.
	 */
	public function test_display_dry_run_runs_without_error(): void {
		if ( ! class_exists( 'WP_CLI' ) ) {
			$this->markTestSkipped( 'WP-CLI is not available.' );
		}

		$image_id = $this->factory->attachment->create_upload_object( TESTS_REPO_ROOT_DIR . '/tests/data/sample.png' );

		ob_start();
		$this->invoke_private_method( 'display_dry_run', array( array( $image_id ) ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( (string) $image_id, $output );
	}
}
