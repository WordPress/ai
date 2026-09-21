<?php
/**
 * Tests for the AI_Request_Log_Schema database class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Logging
 */

declare( strict_types=1 );

namespace WordPress\AI\Tests\Integration\Includes\Logging;

use WP_UnitTestCase;
use WordPress\AI\Logging\AI_Request_Log_Schema;

/**
 * AI_Request_Log_Schema test case.
 *
 * @covers \WordPress\AI\Logging\AI_Request_Log_Schema
 *
 * @since x.x.x
 */
class AI_Request_Log_SchemaTest extends WP_UnitTestCase {

	/**
	 * Schema instance under test.
	 *
	 * @var \WordPress\AI\Logging\AI_Request_Log_Schema
	 */
	private AI_Request_Log_Schema $schema;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		$this->schema = new AI_Request_Log_Schema();
		$this->reset_storage();
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		$this->reset_storage();

		parent::tearDown();
	}

	/**
	 * Removes the log table and its schema version option.
	 *
	 * DDL statements (CREATE TABLE, DROP TABLE, ALTER TABLE) cause implicit transaction
	 * commits in MySQL and MariaDB, which ends the transaction opened by WP_UnitTestCase.
	 * Explicit cleanup is required before and after every test.
	 *
	 * @since x.x.x
	 */
	private function reset_storage(): void {
		global $wpdb;

		$table_name = $this->schema->get_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		delete_option( 'wpai_request_logs_schema_version' );
	}

	/**
	 * Invokes a private or protected method on the schema instance via reflection.
	 *
	 * @param string $method_name The method name to invoke.
	 * @param array  $args        Arguments to pass to the method.
	 * @return mixed The method return value.
	 */
	private function invoke_private_method( string $method_name, array $args = array() ) {
		$reflection = new \ReflectionClass( $this->schema );
		$method     = $reflection->getMethod( $method_name );

		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		return $method->invoke( $this->schema, ...$args );
	}

	/**
	 * Tests that get_table_name returns the table name with the database prefix.
	 *
	 * @since x.x.x
	 */
	public function test_get_table_name_is_prefixed(): void {
		global $wpdb;

		$this->assertSame(
			$wpdb->prefix . AI_Request_Log_Schema::TABLE_NAME,
			$this->schema->get_table_name()
		);
	}

	/**
	 * Tests that maybe_upgrade_table creates the table and stores the schema version option.
	 *
	 * @since x.x.x
	 */
	public function test_maybe_upgrade_table_creates_table_and_records_version(): void {
		$this->assertFalse( $this->invoke_private_method( 'table_exists' ) );

		$this->schema->maybe_upgrade_table();

		$this->assertTrue( $this->invoke_private_method( 'table_exists' ) );
		$this->assertSame( '1', get_option( 'wpai_request_logs_schema_version' ) );
	}

	/**
	 * Tests that maybe_upgrade_table is idempotent and safe to invoke multiple times.
	 *
	 * @since x.x.x
	 */
	public function test_maybe_upgrade_table_is_idempotent(): void {
		$this->schema->maybe_upgrade_table();
		$this->assertTrue( $this->invoke_private_method( 'table_exists' ) );

		// Second invocation should safely no-op without errors or duplicate schema modifications.
		$this->schema->maybe_upgrade_table();
		$this->assertTrue( $this->invoke_private_method( 'table_exists' ) );
		$this->assertSame( '1', get_option( 'wpai_request_logs_schema_version' ) );
	}

	/**
	 * Tests that maybe_create_table creates the table even if the version option exists.
	 *
	 * @since x.x.x
	 */
	public function test_maybe_create_table_creates_missing_table(): void {
		update_option( 'wpai_request_logs_schema_version', '1', false );
		$this->assertFalse( $this->invoke_private_method( 'table_exists' ) );

		$this->schema->maybe_create_table();

		$this->assertTrue( $this->invoke_private_method( 'table_exists' ) );
	}

	/**
	 * Tests that the created table contains all expected schema columns.
	 *
	 * @since x.x.x
	 */
	public function test_table_has_expected_columns(): void {
		global $wpdb;

		$this->schema->maybe_upgrade_table();

		$table_name = $this->schema->get_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table_name}" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$expected_columns = array(
			'id',
			'log_id',
			'timestamp',
			'type',
			'operation',
			'provider',
			'model',
			'duration_ms',
			'tokens_input',
			'tokens_output',
			'tokens_total',
			'status',
			'error_message',
			'user_id',
			'context',
			'request_preview',
			'response_preview',
		);

		$this->assertSame( $expected_columns, $columns );
	}

	/**
	 * Tests that the created table contains all expected indexes.
	 *
	 * @since x.x.x
	 */
	public function test_table_has_expected_indexes(): void {
		global $wpdb;

		$this->schema->maybe_upgrade_table();

		$table_name = $this->schema->get_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$table_name}", ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$index_names = array_unique( array_column( $indexes, 'Key_name' ) );

		$this->assertContains( 'PRIMARY', $index_names );
		$this->assertContains( 'idx_timestamp', $index_names );
		$this->assertContains( 'idx_type', $index_names );
		$this->assertContains( 'idx_status', $index_names );
		$this->assertContains( 'idx_user_id', $index_names );
		$this->assertContains( 'idx_log_id', $index_names );
		$this->assertContains( 'idx_provider', $index_names );
		$this->assertContains( 'idx_operation', $index_names );
		$this->assertContains( 'idx_timestamp_type_status', $index_names );
		$this->assertContains( 'idx_timestamp_provider', $index_names );
	}

	/**
	 * Tests that maybe_add_columns incrementally adds missing preview columns to an existing table.
	 *
	 * @since x.x.x
	 */
	public function test_maybe_add_columns_restores_missing_preview_columns(): void {
		global $wpdb;

		$this->schema->maybe_upgrade_table();

		$table_name = $this->schema->get_table_name();

		// Drop the preview columns to simulate a legacy table schema.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE {$table_name} DROP COLUMN response_preview" );
		$wpdb->query( "ALTER TABLE {$table_name} DROP COLUMN request_preview" );
		$columns_after_drop = $wpdb->get_col( "SHOW COLUMNS FROM {$table_name}" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertNotContains( 'request_preview', $columns_after_drop );
		$this->assertNotContains( 'response_preview', $columns_after_drop );

		// Call maybe_create_table which delegates to maybe_add_columns on existing tables.
		$this->schema->maybe_create_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$restored_columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table_name}" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertContains( 'request_preview', $restored_columns );
		$this->assertContains( 'response_preview', $restored_columns );
	}

	/**
	 * Tests that maybe_add_indexes incrementally adds missing indexes to an existing table.
	 *
	 * @since x.x.x
	 */
	public function test_maybe_add_indexes_restores_missing_indexes(): void {
		global $wpdb;

		$this->schema->maybe_upgrade_table();

		$table_name = $this->schema->get_table_name();

		// Drop an index to simulate a table missing an index.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE {$table_name} DROP INDEX idx_provider" );
		$indexes_after_drop = $wpdb->get_results( "SHOW INDEX FROM {$table_name}", ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$index_names_after_drop = array_unique( array_column( $indexes_after_drop, 'Key_name' ) );
		$this->assertNotContains( 'idx_provider', $index_names_after_drop );

		// Call maybe_create_table which delegates to maybe_add_indexes on existing tables.
		$this->schema->maybe_create_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$restored_indexes = $wpdb->get_results( "SHOW INDEX FROM {$table_name}", ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$restored_index_names = array_unique( array_column( $restored_indexes, 'Key_name' ) );
		$this->assertContains( 'idx_provider', $restored_index_names );
	}

	/**
	 * Tests has_fulltext_index returns a boolean.
	 *
	 * @since x.x.x
	 */
	public function test_has_fulltext_index_returns_boolean(): void {
		$this->schema->maybe_upgrade_table();

		$result = $this->schema->has_fulltext_index();
		$this->assertIsBool( $result );
	}
}
