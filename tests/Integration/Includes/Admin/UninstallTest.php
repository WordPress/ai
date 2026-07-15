<?php
/**
 * Integration tests for the Uninstall class.
 *
 * @package WordPress\AI\Tests\Integration\Admin
 */

namespace WordPress\AI\Tests\Integration\Admin;

use WP_UnitTestCase;
use WordPress\AI\Admin\Uninstall;
use WordPress\AI\Logging\AI_Request_Log_Schema;

/**
 * Uninstall test case.
 *
 * @since x.x.x
 */
class UninstallTest extends WP_UnitTestCase {

	private const CLEANUP_HOOK = 'wpai_request_logs_cleanup';

	/**
	 * Seeded object IDs used to verify meta cleanup.
	 *
	 * @var int
	 */
	private int $post_id;
	private int $attachment_id;
	private int $comment_id;
	private int $user_id;

	/**
	 * Returns the prefixed request logs table name.
	 *
	 * @return string
	 */
	private function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . AI_Request_Log_Schema::TABLE_NAME;
	}

	/**
	 * Whether the request logs table exists.
	 *
	 * @return bool
	 */
	private function table_exists(): bool {
		global $wpdb;
		$table = $this->table_name();
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * Seeds the table, options, a transient, and a scheduled event.
	 *
	 * @return void
	 */
	private function seed_data(): void {
		( new AI_Request_Log_Schema() )->maybe_create_table();

		add_option( 'wpai_features_enabled', true );
		add_option( 'wpai_test_foo', 'bar' );

		// Options without the plugin prefix that must still be removed.
		add_option( 'ai_experiment_summarization_enabled', true );
		add_option( 'ai_experiments_enabled', true );
		add_option( 'wp_ai_client_provider_credentials', 'creds' );

		// Options that look similar but must be preserved (guards against
		// over-matching the LIKE patterns).
		add_option( 'not_a_wpai_option', 'keep-me' );
		add_option( 'ai_experimental', 'keep-me' );

		set_transient( 'wpai_test_transient', 'value', HOUR_IN_SECONDS );

		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CLEANUP_HOOK );
		}

		// Post meta owned by the plugin.
		$this->post_id = self::factory()->post->create();
		update_post_meta( $this->post_id, 'ai_generated_summary', 'Summary text' );
		update_post_meta( $this->post_id, 'wpai_meta_description', 'Meta description' );

		// Post meta the plugin writes into but does not own (must be preserved).
		update_post_meta( $this->post_id, '_wp_attachment_image_alt', 'Keep alt' );
		update_post_meta( $this->post_id, '_yoast_wpseo_metadesc', 'Keep SEO' );

		$this->attachment_id = self::factory()->post->create( array( 'post_type' => 'attachment' ) );
		update_post_meta( $this->attachment_id, 'ai_generated', 1 );

		// Comment meta owned by the plugin.
		$this->comment_id = self::factory()->comment->create();
		update_comment_meta( $this->comment_id, 'ai_note', true );
		update_comment_meta( $this->comment_id, '_wpai_analysis_status', 'complete' );
		update_comment_meta( $this->comment_id, '_wpai_sentiment', 'positive' );
		update_comment_meta( $this->comment_id, '_wpai_toxicity_score', 0.2 );
		update_comment_meta( $this->comment_id, '_wpai_analyzed_at', 1234567890 );

		// User meta owned by the plugin.
		$this->user_id = self::factory()->user->create();
		update_user_meta( $this->user_id, 'wpai_connector_approval_notice_dismissed', 'signature' );
	}

	/**
	 * Tear down test case.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS `' . $this->table_name() . '`' );

		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_test_foo' );
		delete_option( 'ai_experiment_summarization_enabled' );
		delete_option( 'ai_experiments_enabled' );
		delete_option( 'wp_ai_client_provider_credentials' );
		delete_option( 'not_a_wpai_option' );
		delete_option( 'ai_experimental' );
		delete_transient( 'wpai_test_transient' );
		wp_clear_scheduled_hook( self::CLEANUP_HOOK );

		if ( isset( $this->post_id ) ) {
			wp_delete_post( $this->post_id, true );
		}
		if ( isset( $this->attachment_id ) ) {
			wp_delete_post( $this->attachment_id, true );
		}
		if ( isset( $this->comment_id ) ) {
			wp_delete_comment( $this->comment_id, true );
		}
		if ( isset( $this->user_id ) ) {
			delete_user_meta( $this->user_id, 'wpai_connector_approval_notice_dismissed' );
		}

		remove_all_filters( 'wpai_remove_data_on_uninstall' );

		parent::tearDown();
	}

	/**
	 * Tests that uninstall removes the plugin's data by default.
	 *
	 * The filter defaults to true, so no callback is registered here.
	 *
	 * @since x.x.x
	 */
	public function test_uninstall_removes_data_by_default(): void {
		$this->seed_data();

		$this->assertTrue( $this->table_exists(), 'Table should exist before uninstall.' );

		Uninstall::run();

		// Direct SQL deletes bypass the in-request options cache.
		wp_cache_flush();

		$this->assertFalse( $this->table_exists(), 'Table should be dropped.' );
		$this->assertFalse( get_option( 'wpai_features_enabled' ), 'wpai_ options should be deleted.' );
		$this->assertFalse( get_option( 'wpai_test_foo' ), 'wpai_ options should be deleted.' );
		$this->assertFalse( get_option( 'ai_experiment_summarization_enabled' ), 'Legacy ai_experiment_ options should be deleted.' );
		$this->assertFalse( get_option( 'ai_experiments_enabled' ), 'Legacy ai_experiments_enabled should be deleted.' );
		$this->assertFalse( get_option( 'wp_ai_client_provider_credentials' ), 'Legacy credentials option should be deleted.' );
		$this->assertFalse( get_transient( 'wpai_test_transient' ), 'wpai_ transients should be deleted.' );
		$this->assertFalse( wp_next_scheduled( self::CLEANUP_HOOK ), 'Scheduled cleanup should be cleared.' );

		$this->assertSame(
			'keep-me',
			get_option( 'not_a_wpai_option' ),
			'Non-plugin options should be preserved.'
		);
		$this->assertSame(
			'keep-me',
			get_option( 'ai_experimental' ),
			'Options that only resemble the ai_experiment_ prefix should be preserved.'
		);

		// Plugin-owned meta should be removed.
		$this->assertSame( '', get_post_meta( $this->post_id, 'ai_generated_summary', true ), 'Summary post meta should be deleted.' );
		$this->assertSame( '', get_post_meta( $this->post_id, 'wpai_meta_description', true ), 'Meta description post meta should be deleted.' );
		$this->assertSame( '', get_post_meta( $this->attachment_id, 'ai_generated', true ), 'AI-generated attachment meta should be deleted.' );
		$this->assertSame( '', get_comment_meta( $this->comment_id, 'ai_note', true ), 'Editorial note comment meta should be deleted.' );
		$this->assertSame( '', get_comment_meta( $this->comment_id, '_wpai_analysis_status', true ), 'Comment moderation meta should be deleted.' );
		$this->assertSame( '', get_comment_meta( $this->comment_id, '_wpai_sentiment', true ), 'Comment moderation meta should be deleted.' );
		$this->assertSame( '', get_comment_meta( $this->comment_id, '_wpai_toxicity_score', true ), 'Comment moderation meta should be deleted.' );
		$this->assertSame( '', get_comment_meta( $this->comment_id, '_wpai_analyzed_at', true ), 'Comment moderation meta should be deleted.' );
		$this->assertSame( '', get_user_meta( $this->user_id, 'wpai_connector_approval_notice_dismissed', true ), 'Connector approval user meta should be deleted.' );

		// // Meta the plugin does not own should be preserved.
		$this->assertSame( 'Keep alt', get_post_meta( $this->post_id, '_wp_attachment_image_alt', true ), 'Core alt text meta should be preserved.' );
		$this->assertSame( 'Keep SEO', get_post_meta( $this->post_id, '_yoast_wpseo_metadesc', true ), 'Third-party SEO meta should be preserved.' );
	}

	/**
	 * Tests that data is preserved when a developer opts out via the filter.
	 *
	 * @since x.x.x
	 */
	public function test_uninstall_preserves_data_when_filtered_out(): void {
		$this->seed_data();
		add_filter( 'wpai_remove_data_on_uninstall', '__return_false' );

		Uninstall::run();

		wp_cache_flush();

		$this->assertTrue( $this->table_exists(), 'Table should be preserved when filtered out.' );
		$this->assertSame( 'bar', get_option( 'wpai_test_foo' ), 'Options should be preserved when filtered out.' );
		$this->assertSame( 'value', get_transient( 'wpai_test_transient' ), 'Transients should be preserved when filtered out.' );
		$this->assertNotFalse( wp_next_scheduled( self::CLEANUP_HOOK ), 'Scheduled cleanup should be preserved when filtered out.' );

		$this->assertSame( 'Summary text', get_post_meta( $this->post_id, 'ai_generated_summary', true ), 'Post meta should be preserved when filtered out.' );
		$this->assertSame( 'complete', get_comment_meta( $this->comment_id, '_wpai_analysis_status', true ), 'Comment meta should be preserved when filtered out.' );
		$this->assertSame( 'signature', get_user_meta( $this->user_id, 'wpai_connector_approval_notice_dismissed', true ), 'User meta should be preserved when filtered out.' );
	}
}
