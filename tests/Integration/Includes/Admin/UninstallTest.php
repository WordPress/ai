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
		add_option( 'not_a_wpai_option', 'keep-me' );

		set_transient( 'wpai_test_transient', 'value', HOUR_IN_SECONDS );

		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CLEANUP_HOOK );
		}
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
		delete_option( 'not_a_wpai_option' );
		delete_option( Uninstall::OPTION_REMOVE_DATA );
		delete_transient( 'wpai_test_transient' );
		wp_clear_scheduled_hook( self::CLEANUP_HOOK );

		parent::tearDown();
	}

	/**
	 * Tests that opted-in uninstall removes the plugin's data.
	 *
	 * @since x.x.x
	 */
	public function test_uninstall_removes_data_when_opted_in(): void {
		$this->seed_data();
		update_option( Uninstall::OPTION_REMOVE_DATA, true );

		$this->assertTrue( $this->table_exists(), 'Table should exist before uninstall.' );

		Uninstall::run();

		// Direct SQL deletes bypass the in-request options cache.
		wp_cache_flush();

		$this->assertFalse( $this->table_exists(), 'Table should be dropped.' );
		$this->assertFalse( get_option( 'wpai_features_enabled' ), 'wpai_ options should be deleted.' );
		$this->assertFalse( get_option( 'wpai_test_foo' ), 'wpai_ options should be deleted.' );
		$this->assertFalse( get_transient( 'wpai_test_transient' ), 'wpai_ transients should be deleted.' );
		$this->assertFalse( wp_next_scheduled( self::CLEANUP_HOOK ), 'Scheduled cleanup should be cleared.' );

		$this->assertSame(
			'keep-me',
			get_option( 'not_a_wpai_option' ),
			'Non-plugin options should be preserved.'
		);
	}

	/**
	 * Tests that data is preserved when the site has not opted in.
	 *
	 * @since x.x.x
	 */
	public function test_uninstall_preserves_data_when_not_opted_in(): void {
		$this->seed_data();
		update_option( Uninstall::OPTION_REMOVE_DATA, false );

		Uninstall::run();

		wp_cache_flush();

		$this->assertTrue( $this->table_exists(), 'Table should be preserved when not opted in.' );
		$this->assertSame( 'bar', get_option( 'wpai_test_foo' ), 'Options should be preserved when not opted in.' );
		$this->assertSame( 'value', get_transient( 'wpai_test_transient' ), 'Transients should be preserved when not opted in.' );
		$this->assertNotFalse( wp_next_scheduled( self::CLEANUP_HOOK ), 'Scheduled cleanup should be preserved when not opted in.' );
	}
}
