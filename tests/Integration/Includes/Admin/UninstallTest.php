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

	/**
	 * Cleanup Hook constant.
	 */
	private const CLEANUP_HOOK = 'wpai_request_logs_cleanup';

	/**
	 * Seeded object IDs used to verify user meta cleanup.
	 *
	 * @var int
	 */
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
		add_option( '_secrets_master_key', 'master' );
		add_option( 'ai_experiment_summarization_enabled', true );
		add_option( 'ai_experiments_enabled', true );
		add_option( 'wp_ai_client_provider_credentials', 'creds' );

		// Options that look similar but must be preserved (guards against
		// over-matching the LIKE patterns).
		add_option( 'not_a_wpai_option', 'keep-me' );
		add_option( 'ai_experimental', 'keep-me' );
		add_option( '_secretsauce', 'keep-me' );

		set_transient( 'wpai_test_transient', 'value', HOUR_IN_SECONDS );

		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CLEANUP_HOOK );
		}

		// User meta owned by the plugin.
		$this->user_id = self::factory()->user->create();
		update_user_meta( $this->user_id, 'wpai_connector_approval_notice_dismissed', 'signature' );
	}

	/**
	 * Seeds the request logs table and a plugin option on the current site.
	 *
	 * Used for the multisite test, where data is seeded per site.
	 *
	 * @return void
	 */
	private function seed_site_table_and_option(): void {
		( new AI_Request_Log_Schema() )->maybe_create_table();
		add_option( 'wpai_features_enabled', true );
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
		delete_option( '_secrets_master_key' );
		delete_option( 'ai_experiment_summarization_enabled' );
		delete_option( 'ai_experiments_enabled' );
		delete_option( 'wp_ai_client_provider_credentials' );
		delete_option( 'not_a_wpai_option' );
		delete_option( '_secretsauce' );
		delete_option( 'ai_experimental' );
		delete_transient( 'wpai_test_transient' );
		wp_clear_scheduled_hook( self::CLEANUP_HOOK );

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
		$this->assertFalse( get_option( '_secrets_master_key' ), 'Secrets master key should be deleted.' );
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
			get_option( '_secretsauce' ),
			'Options that only resemble the _secret_ prefix should be preserved.'
		);
		$this->assertSame(
			'keep-me',
			get_option( 'ai_experimental' ),
			'Options that only resemble the ai_experiment_ prefix should be preserved.'
		);

		$this->assertSame( '', get_user_meta( $this->user_id, 'wpai_connector_approval_notice_dismissed', true ), 'Connector approval user meta should be deleted.' );
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
		$this->assertSame( 'master', get_option( '_secrets_master_key' ), 'Secrets master key should be preserved when filtered out.' );
		$this->assertSame( 'value', get_transient( 'wpai_test_transient' ), 'Transients should be preserved when filtered out.' );
		$this->assertNotFalse( wp_next_scheduled( self::CLEANUP_HOOK ), 'Scheduled cleanup should be preserved when filtered out.' );
		$this->assertSame( 'signature', get_user_meta( $this->user_id, 'wpai_connector_approval_notice_dismissed', true ), 'User meta should be preserved when filtered out.' );
	}

	/**
	 * Tests that uninstall cleans data on every site in a multisite network.
	 *
	 * Exercises the multisite branch of run() (the get_sites() + switch_to_blog()
	 * loop). Only runs under a multisite installation.
	 *
	 * @group ms-required
	 *
	 * @since x.x.x
	 */
	public function test_uninstall_cleans_all_sites_on_multisite(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires a multisite installation.' );
		}

		$second_blog_id = self::factory()->blog->create();

		// Seed plugin data on the main site.
		$this->seed_site_table_and_option();

		// Seed plugin data on the second site.
		switch_to_blog( $second_blog_id );
		$this->seed_site_table_and_option();
		$second_table_exists_before = $this->table_exists();
		restore_current_blog();

		$this->assertTrue( $this->table_exists(), 'Main site table should exist before uninstall.' );
		$this->assertTrue( $second_table_exists_before, 'Second site table should exist before uninstall.' );

		Uninstall::run();

		// Direct SQL deletes bypass the in-request caches.
		wp_cache_flush();

		// Main site should be cleaned.
		$this->assertFalse( $this->table_exists(), 'Main site table should be dropped.' );
		$this->assertFalse( get_option( 'wpai_features_enabled' ), 'Main site option should be deleted.' );

		// Second site should be cleaned too.
		switch_to_blog( $second_blog_id );
		$second_table_exists_after = $this->table_exists();
		$second_option_after       = get_option( 'wpai_features_enabled' );
		restore_current_blog();

		$this->assertFalse( $second_table_exists_after, 'Second site table should be dropped.' );
		$this->assertFalse( $second_option_after, 'Second site option should be deleted.' );

		// Clean up the second site.
		wp_delete_site( get_site( $second_blog_id ) );
	}
}
