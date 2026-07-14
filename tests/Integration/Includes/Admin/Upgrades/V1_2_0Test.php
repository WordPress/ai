<?php
/**
 * Integration tests for V1_2_0.
 *
 * @package WordPress\AI\Tests\Integration\Admin\Upgrades
 */

namespace WordPress\AI\Tests\Integration\Admin\Upgrades;

use WP_UnitTestCase;
use WordPress\AI\Admin\Upgrades\V1_2_0;

/**
 * V1_2_0 test case.
 *
 * @covers \WordPress\AI\Admin\Upgrades\V1_2_0
 * @since 1.2.0
 */
class V1_2_0Test extends WP_UnitTestCase {

	/**
	 * Tests that run() renames the ai_generated attachment meta key.
	 *
	 * @since 1.2.0
	 */
	public function test_run_renames_generated_meta(): void {
		$attachment_id = self::factory()->post->create( array( 'post_type' => 'attachment' ) );
		update_post_meta( $attachment_id, 'ai_generated', 1 );

		( new V1_2_0( '1.1.0' ) )->run();

		// Direct SQL updates bypass the in-request meta cache.
		wp_cache_flush();

		$this->assertSame( '1', get_post_meta( $attachment_id, 'wpai_generated', true ), 'ai_generated should migrate to wpai_generated.' );
		$this->assertSame( '', get_post_meta( $attachment_id, 'ai_generated', true ), 'Old ai_generated meta should be removed.' );
	}

	/**
	 * Tests that run() renames the ai_generated_summary post meta key.
	 *
	 * @since 1.2.0
	 */
	public function test_run_renames_summary_meta(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'ai_generated_summary', 'A summary.' );

		( new V1_2_0( '1.1.0' ) )->run();

		wp_cache_flush();

		$this->assertSame( 'A summary.', get_post_meta( $post_id, 'wpai_generated_summary', true ), 'ai_generated_summary should migrate to wpai_generated_summary.' );
		$this->assertSame( '', get_post_meta( $post_id, 'ai_generated_summary', true ), 'Old ai_generated_summary meta should be removed.' );
	}

	/**
	 * Tests that run() returns true on success.
	 *
	 * @since 1.2.0
	 */
	public function test_run_returns_success(): void {
		$this->assertTrue( ( new V1_2_0( '1.1.0' ) )->run() );
	}

	/**
	 * Tests that run() skips migration when the version is already current.
	 *
	 * @since 1.2.0
	 */
	public function test_run_skips_when_version_already_current(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'ai_generated_summary', 'A summary.' );

		( new V1_2_0( '1.2.0' ) )->run();

		wp_cache_flush();

		$this->assertSame( 'A summary.', get_post_meta( $post_id, 'ai_generated_summary', true ), 'Old meta should be untouched when the upgrade is skipped.' );
		$this->assertSame( '', get_post_meta( $post_id, 'wpai_generated_summary', true ), 'New meta should not be written when the upgrade is skipped.' );
	}
}
