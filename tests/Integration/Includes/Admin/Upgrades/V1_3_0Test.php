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
 * @since 1.3.0
 */
class V1_3_0Test extends WP_UnitTestCase {

	/**
	 * Tests that run() renames the ai_generated attachment meta key.
	 *
	 * @since 1.3.0
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
	 * @since 1.3.0
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
	 * @since 1.3.0
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
	 * @since 1.3.0
	 */
	public function test_run_returns_success(): void {
		$this->assertTrue( ( new V1_3_0( '1.2.0' ) )->run() );
	}

	/**
	 * Tests that run() skips migration when the version is already current.
	 *
	 * @since 1.3.0
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
	 * @since 1.3.0
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
	 * @since 1.3.0
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

	/**
	 * Tests that run() leaves identically named meta alone on a new install, where
	 * an empty database version means the plugin has never stored the legacy keys.
	 *
	 * @since 1.3.0
	 */
	public function test_run_skips_migration_on_a_new_install(): void {
		$post_id    = self::factory()->post->create();
		$comment_id = self::factory()->comment->create();
		update_post_meta( $post_id, 'ai_generated_summary', 'Third-party summary.' );
		update_comment_meta( $comment_id, 'ai_note', true );

		$this->assertTrue( ( new V1_3_0( '' ) )->run() );

		wp_cache_flush();

		$this->assertSame( 'Third-party summary.', get_post_meta( $post_id, 'ai_generated_summary', true ), 'Post meta owned by another plugin should survive a new install.' );
		$this->assertSame( '', get_post_meta( $post_id, 'wpai_generated_summary', true ), 'No new post meta should be written on a new install.' );
		$this->assertSame( '1', get_comment_meta( $comment_id, 'ai_note', true ), 'Comment meta owned by another plugin should survive a new install.' );
		$this->assertSame( '', get_comment_meta( $comment_id, 'wpai_note', true ), 'No new comment meta should be written on a new install.' );
	}

	/**
	 * Tests that run() reports a failed rename as a WP_Error and leaves the legacy
	 * meta in place, so the retry offered by the failed upgrade notice can migrate it.
	 *
	 * Guards against data loss: `wpdb` signals failure with a `false` return rather
	 * than an exception, so an unchecked rename would be followed by a cleanup that
	 * deletes rows that were never migrated.
	 *
	 * @since 1.3.0
	 */
	public function test_run_returns_error_and_keeps_legacy_meta_when_the_rename_fails(): void {
		global $wpdb;

		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'ai_generated_summary', 'A summary.' );

		// Break only the rename, so the cleanup would still succeed if it were reached.
		$break_rename = static function ( $query ) {
			return 0 === strpos( $query, 'UPDATE' ) ? 'UPDATE wpai_no_such_table SET meta_key = 1' : $query;
		};

		$suppress_errors = $wpdb->suppress_errors( true );
		add_filter( 'query', $break_rename );

		$result = ( new V1_3_0( '1.2.0' ) )->run();

		remove_filter( 'query', $break_rename );
		$wpdb->suppress_errors( $suppress_errors );

		wp_cache_flush();

		$this->assertSame( 'A summary.', get_post_meta( $post_id, 'ai_generated_summary', true ), 'Legacy meta must not be deleted when the rename fails.' );
		$this->assertWPError( $result, 'run() should return a WP_Error when a migration query fails.' );
		$this->assertSame( 'wpai_upgrade_failed', $result->get_error_code(), 'The error should use the upgrade failure code the admin notice reads.' );
		$this->assertStringContainsString( 'Failed to rename the ai_generated post meta key', $result->get_error_message(), 'The error should come from the rename guard rather than an unrelated failure.' );
	}

	/**
	 * Tests that run() leaves no stale meta cache behind, so a read that was primed
	 * before the migration returns the migrated value without an explicit flush.
	 *
	 * @since 1.3.0
	 */
	public function test_run_invalidates_the_meta_cache(): void {
		$post_id    = self::factory()->post->create();
		$comment_id = self::factory()->comment->create();
		update_post_meta( $post_id, 'ai_generated_summary', 'A summary.' );
		update_comment_meta( $comment_id, 'ai_note', true );

		// Prime the meta caches the direct SQL in the migration bypasses.
		get_post_meta( $post_id, 'ai_generated_summary', true );
		get_comment_meta( $comment_id, 'ai_note', true );

		( new V1_3_0( '1.2.0' ) )->run();

		$this->assertSame( 'A summary.', get_post_meta( $post_id, 'wpai_generated_summary', true ), 'The migrated post meta should be readable without flushing the cache first.' );
		$this->assertSame( '1', get_comment_meta( $comment_id, 'wpai_note', true ), 'The migrated comment meta should be readable without flushing the cache first.' );
	}

	/**
	 * Tests that run() migrates a post carrying more than one legacy row without
	 * losing the value or leaving a legacy row behind.
	 *
	 * The resulting row count is left unasserted: the rename joins the meta table to
	 * itself, so whether a row renamed earlier in the statement is visible to a later
	 * row of the same post depends on the query plan.
	 *
	 * @since 1.3.0
	 */
	public function test_run_migrates_multiple_legacy_rows_for_the_same_post(): void {
		$post_id = self::factory()->post->create();
		add_post_meta( $post_id, 'ai_generated_summary', 'First summary.' );
		add_post_meta( $post_id, 'ai_generated_summary', 'Second summary.' );

		( new V1_3_0( '1.2.0' ) )->run();

		wp_cache_flush();

		$migrated = get_post_meta( $post_id, 'wpai_generated_summary', false );

		$this->assertNotEmpty( $migrated, 'At least one legacy value should survive under the new key.' );
		$this->assertSame( array(), array_diff( $migrated, array( 'First summary.', 'Second summary.' ) ), 'The new key should only hold values that came from the legacy rows.' );
		$this->assertSame( '', get_post_meta( $post_id, 'ai_generated_summary', true ), 'No legacy post meta row should remain.' );
	}
}
