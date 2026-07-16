<?php
/**
 * Integration tests for V1_3_0.
 *
 * @package WordPress\AI\Tests\Integration\Admin\Upgrades
 */

namespace WordPress\AI\Tests\Integration\Admin\Upgrades;

use WP_UnitTestCase;
use WordPress\AI\Admin\Upgrades\V1_3_0;

/**
 * V1_3_0 test case.
 *
 * @covers \WordPress\AI\Admin\Upgrades\V1_3_0
 *
 * @since x.x.x
 */
class V1_3_0Test extends WP_UnitTestCase {

	/**
	 * Tests that run() renames the ai_generated attachment meta key.
	 *
	 * @since x.x.x
	 */
	public function test_run_renames_generated_meta(): void {
		$attachment_id = self::factory()->post->create( array( 'post_type' => 'attachment' ) );
		update_post_meta( $attachment_id, 'ai_generated', 1 );

		( new V1_3_0( '1.2.0' ) )->run();

		// Direct SQL updates bypass the in-request meta cache.
		wp_cache_flush();

		$this->assertSame( '1', get_post_meta( $attachment_id, 'wpai_generated', true ), 'ai_generated should migrate to wpai_generated.' );
		$this->assertSame( '', get_post_meta( $attachment_id, 'ai_generated', true ), 'Old ai_generated meta should be removed.' );
	}

	/**
	 * Tests that run() renames the ai_generated_summary post meta key.
	 *
	 * @since x.x.x
	 */
	public function test_run_renames_summary_meta(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'ai_generated_summary', 'A summary.' );

		( new V1_3_0( '1.2.0' ) )->run();

		wp_cache_flush();

		$this->assertSame( 'A summary.', get_post_meta( $post_id, 'wpai_generated_summary', true ), 'ai_generated_summary should migrate to wpai_generated_summary.' );
		$this->assertSame( '', get_post_meta( $post_id, 'ai_generated_summary', true ), 'Old ai_generated_summary meta should be removed.' );
	}

	/**
	 * Tests that run() renames the ai_note comment meta key.
	 *
	 * @since x.x.x
	 */
	public function test_run_renames_note_comment_meta(): void {
		$comment_id = self::factory()->comment->create();
		update_comment_meta( $comment_id, 'ai_note', true );

		( new V1_3_0( '1.2.0' ) )->run();

		wp_cache_flush();

		$this->assertSame( '1', get_comment_meta( $comment_id, 'wpai_note', true ), 'ai_note should migrate to wpai_note.' );
		$this->assertSame( '', get_comment_meta( $comment_id, 'ai_note', true ), 'Old ai_note comment meta should be removed.' );
	}

	/**
	 * Tests that run() returns true on success.
	 *
	 * @since x.x.x
	 */
	public function test_run_returns_success(): void {
		$this->assertTrue( ( new V1_3_0( '1.2.0' ) )->run() );
	}

	/**
	 * Tests that run() skips migration when the version is already current.
	 *
	 * @since x.x.x
	 */
	public function test_run_skips_when_version_already_current(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'ai_generated_summary', 'A summary.' );

		( new V1_3_0( '1.3.0' ) )->run();

		wp_cache_flush();

		$this->assertSame( 'A summary.', get_post_meta( $post_id, 'ai_generated_summary', true ), 'Old meta should be untouched when the upgrade is skipped.' );
		$this->assertSame( '', get_post_meta( $post_id, 'wpai_generated_summary', true ), 'New meta should not be written when the upgrade is skipped.' );
	}

	/**
	 * Tests that run() does not create duplicate post meta when the new key
	 * already exists, keeping the new value and removing the legacy row.
	 *
	 * @since x.x.x
	 */
	public function test_run_does_not_duplicate_post_meta_when_new_key_exists(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'ai_generated_summary', 'Legacy summary.' );
		update_post_meta( $post_id, 'wpai_generated_summary', 'New summary.' );

		( new V1_3_0( '1.2.0' ) )->run();

		wp_cache_flush();

		$this->assertSame( array( 'New summary.' ), get_post_meta( $post_id, 'wpai_generated_summary', false ), 'The new key should keep its value with no duplicate row.' );
		$this->assertSame( '', get_post_meta( $post_id, 'ai_generated_summary', true ), 'The legacy post meta row should be removed.' );
	}

	/**
	 * Tests that run() does not create duplicate comment meta when the new key
	 * already exists, keeping the new value and removing the legacy row.
	 *
	 * @since x.x.x
	 */
	public function test_run_does_not_duplicate_comment_meta_when_new_key_exists(): void {
		$comment_id = self::factory()->comment->create();
		update_comment_meta( $comment_id, 'ai_note', 'Legacy note.' );
		update_comment_meta( $comment_id, 'wpai_note', 'New note.' );

		( new V1_3_0( '1.2.0' ) )->run();

		wp_cache_flush();

		$this->assertSame( array( 'New note.' ), get_comment_meta( $comment_id, 'wpai_note', false ), 'The new key should keep its value with no duplicate row.' );
		$this->assertSame( '', get_comment_meta( $comment_id, 'ai_note', true ), 'The legacy comment meta row should be removed.' );
	}
}
