<?php
/**
 * Tests for the embeddings table schema.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Embeddings
 */

namespace WordPress\AI\Tests\Integration\Includes\Embeddings;

use WP_UnitTestCase;
use WordPress\AI\Embeddings\Embedding_Schema;

/**
 * Embedding_Schema test case.
 *
 * @since 1.4.0
 *
 * @covers \WordPress\AI\Embeddings\Embedding_Schema
 */
class Embedding_SchemaTest extends WP_UnitTestCase {

	/**
	 * Schema under test.
	 *
	 * @var \WordPress\AI\Embeddings\Embedding_Schema
	 */
	private Embedding_Schema $schema;

	/**
	 * Set up test case.
	 *
	 * @since 1.4.0
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->schema = new Embedding_Schema();

		$this->reset_storage();
	}

	/**
	 * Tear down test case.
	 *
	 * @since 1.4.0
	 */
	protected function tearDown(): void {
		$this->reset_storage();

		parent::tearDown();
	}

	/**
	 * Removes every trace of the storage layer's own state.
	 *
	 * These tests need real DDL, and `CREATE TABLE` / `DROP TABLE` force an implicit commit on both
	 * MySQL and MariaDB — which ends the transaction `WP_UnitTestCase` opened, so anything written
	 * before the DDL is committed for real and will not roll back. Isolation therefore has to be
	 * explicit rather than inherited: this runs before and after every test, and anything that
	 * mutates state mid-test is responsible for restoring it.
	 *
	 * @since 1.4.0
	 */
	private function reset_storage(): void {
		$this->schema->drop_table();
		delete_option( Embedding_Schema::SCHEMA_VERSION_OPTION );
	}

	/**
	 * Tests the prefixed table name.
	 *
	 * @since 1.4.0
	 */
	public function test_get_table_name_is_prefixed(): void {
		global $wpdb;

		$this->assertSame( $wpdb->prefix . 'wpai_embeddings', $this->schema->get_table_name() );
	}

	/**
	 * Tests that upgrading creates the table and records the schema version.
	 *
	 * @since 1.4.0
	 */
	public function test_maybe_upgrade_table_creates_table_and_records_version(): void {
		$this->assertFalse( $this->schema->table_exists() );

		$this->schema->maybe_upgrade_table();

		$this->assertTrue( $this->schema->table_exists() );
		$this->assertSame( '2', get_option( Embedding_Schema::SCHEMA_VERSION_OPTION ) );
	}

	/**
	 * Tests that the table has the expected columns and unique key.
	 *
	 * @since 1.4.0
	 */
	public function test_table_has_expected_columns_and_unique_key(): void {
		global $wpdb;

		$this->schema->maybe_upgrade_table();

		$table   = $this->schema->get_table_name();
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertSame(
			array( 'id', 'object_type', 'object_id', 'chunk_index', 'provider', 'model', 'object_subtype', 'dimensions', 'embedding', 'embedding_norm', 'embedding_coarse', 'content_hash', 'created_at', 'updated_at' ),
			$columns
		);

		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$names   = array_unique( array_column( $indexes, 'Key_name' ) );

		$this->assertContains( 'uniq_object_model_chunk', $names );
		$this->assertContains( 'idx_model_coverage', $names );
		$this->assertNotContains( 'idx_provider_model', $names );
		$this->assertNotContains( 'idx_object', $names );
		$this->assertNotContains( 'idx_content_hash', $names );
	}

	/**
	 * Tests the exact column sequence of the unique key.
	 *
	 * The key defines what "the same embedding" means, so its membership is a data-format decision
	 * rather than an implementation detail. `object_subtype` and `dimensions` must stay out of it:
	 * both can legitimately change between two indexing passes over one object, and including
	 * either turns the upsert into an insert that strands the row it should have replaced.
	 *
	 * @since 1.4.0
	 */
	public function test_unique_key_identifies_object_model_and_chunk_only(): void {
		$parts = $this->get_index_parts( 'uniq_object_model_chunk' );

		$this->assertSame(
			array( 'object_type', 'object_id', 'provider', 'model', 'chunk_index' ),
			array_column( $parts, 'Column_name' )
		);

		$this->assertSame( '0', (string) $parts[0]['Non_unique'], 'uniq_object_model_chunk must be a unique index.' );
	}

	/**
	 * Tests that no part of the unique key is a prefix of its column.
	 *
	 * A prefixed unique index enforces uniqueness on the prefix, not the value. Indexing
	 * `model(64)` of a `VARCHAR(128)` would let two models whose IDs share their first 64
	 * characters collide on a single row, so saving one would overwrite the other's vector while
	 * the row kept reporting the first model's name — wrong results rather than no results.
	 *
	 * @since 1.4.0
	 */
	public function test_unique_key_indexes_full_column_values(): void {
		foreach ( $this->get_index_parts( 'uniq_object_model_chunk' ) as $part ) {
			$this->assertNull(
				$part['Sub_part'],
				sprintf( 'Column %s is indexed by prefix; the unique key must cover whole values.', (string) $part['Column_name'] )
			);
		}
	}

	/**
	 * Returns one index's parts in key order.
	 *
	 * @since 1.4.0
	 *
	 * @param string $key_name Index name.
	 * @return list<array<string, mixed>> The index parts, ordered by position in the key.
	 */
	private function get_index_parts( string $key_name ): array {
		global $wpdb;

		$this->schema->maybe_upgrade_table();

		$table = $this->schema->get_table_name();
		$rows  = $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$parts = array_values(
			array_filter(
				is_array( $rows ) ? $rows : array(),
				static function ( array $row ) use ( $key_name ): bool {
					return $key_name === $row['Key_name'];
				}
			)
		);

		usort(
			$parts,
			static function ( array $a, array $b ): int {
				return (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index'];
			}
		);

		$this->assertNotEmpty( $parts, sprintf( 'Index %s does not exist.', $key_name ) );

		return $parts;
	}

	/**
	 * Tests that upgrading is a no-op once the table exists at the current version.
	 *
	 * @since 1.4.0
	 */
	public function test_maybe_upgrade_table_is_idempotent(): void {
		$this->schema->maybe_upgrade_table();
		$this->schema->maybe_upgrade_table();

		$this->assertTrue( $this->schema->table_exists() );
	}

	/**
	 * Tests that a stale version option does not stop the table from being recreated.
	 *
	 * @since 1.4.0
	 */
	public function test_maybe_upgrade_table_recreates_missing_table_despite_version_option(): void {
		update_option( Embedding_Schema::SCHEMA_VERSION_OPTION, '1', false );

		$this->schema->maybe_upgrade_table();

		$this->assertTrue( $this->schema->table_exists() );
	}

	/**
	 * Tests that dropping removes the table and the version option.
	 *
	 * @since 1.4.0
	 */
	public function test_drop_table(): void {
		$this->schema->maybe_upgrade_table();
		$this->schema->drop_table();

		$this->assertFalse( $this->schema->table_exists() );
		$this->assertFalse( get_option( Embedding_Schema::SCHEMA_VERSION_OPTION ) );
	}

	/**
	 * Tests that the coverage index has the column order the sync and search queries rely on.
	 *
	 * @since x.x.x
	 */
	public function test_coverage_index_column_order(): void {
		global $wpdb;

		$this->schema->maybe_upgrade_table();

		$table   = $this->schema->get_table_name();
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'idx_model_coverage'", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		usort(
			$indexes,
			static fn( array $a, array $b ): int => (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index']
		);

		$this->assertSame(
			array( 'provider', 'model', 'object_type', 'object_subtype', 'chunk_index', 'object_id' ),
			array_column( $indexes, 'Column_name' )
		);
	}

	/**
	 * Tests that a version 1 table is migrated in place, keeping its rows.
	 *
	 * @since x.x.x
	 */
	public function test_migrates_a_version_1_table_in_place(): void {
		global $wpdb;

		$this->create_version_1_table();
		update_option( Embedding_Schema::SCHEMA_VERSION_OPTION, '1', false );

		$table = $this->schema->get_table_name();
		$wpdb->query( "INSERT INTO {$table} (object_type, object_id, chunk_index, provider, model, dimensions, embedding, embedding_norm, created_at, updated_at) VALUES ('post', 1, 0, 'openai', 'm', 1, 'abcd', 1, NOW(), NOW())" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->schema->maybe_upgrade_table();

		$names = $this->schema->get_index_names();

		$this->assertArrayHasKey( 'idx_model_coverage', $names );
		$this->assertArrayNotHasKey( 'idx_provider_model', $names );
		$this->assertArrayNotHasKey( 'idx_object', $names );
		$this->assertArrayNotHasKey( 'idx_content_hash', $names );
		$this->assertSame( '2', get_option( Embedding_Schema::SCHEMA_VERSION_OPTION ) );
		$this->assertSame( '1', $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		wp_cache_delete( 'alloptions', 'options' );

		$this->assertArrayHasKey( Embedding_Schema::SCHEMA_VERSION_OPTION, wp_load_alloptions() );
	}

	/**
	 * Tests that a failed migration leaves the version unstamped so the next request retries it.
	 *
	 * @since x.x.x
	 */
	public function test_failed_migration_does_not_stamp_the_version(): void {
		global $wpdb;

		$this->create_version_1_table();
		update_option( Embedding_Schema::SCHEMA_VERSION_OPTION, '1', false );

		$break_alter = static function ( string $query ): string {
			return false !== strpos( $query, 'ADD KEY idx_model_coverage' )
				? 'SELECT * FROM wpai_table_that_does_not_exist'
				: $query;
		};

		add_filter( 'query', $break_alter );
		$suppress = $wpdb->suppress_errors( true );

		$this->schema->maybe_upgrade_table();

		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $break_alter );

		$this->assertSame( '1', get_option( Embedding_Schema::SCHEMA_VERSION_OPTION ) );
		$this->assertFalse( $this->schema->is_version_current() );
		$this->assertArrayHasKey( 'idx_provider_model', $this->schema->get_index_names(), 'Removed indexes must survive until the coverage index exists.' );

		// The next request, with the database healthy again, completes the migration.
		$this->schema->maybe_upgrade_table();

		$this->assertTrue( $this->schema->is_version_current() );
	}

	/**
	 * Tests the version check.
	 *
	 * @since x.x.x
	 */
	public function test_is_version_current(): void {
		$this->assertFalse( $this->schema->is_version_current() );

		$this->schema->maybe_upgrade_table();

		$this->assertTrue( $this->schema->is_version_current() );
	}

	/**
	 * Creates the table exactly as schema version 1 shipped it in 1.4.0.
	 *
	 * @since x.x.x
	 */
	private function create_version_1_table(): void {
		global $wpdb;

		$table = $this->schema->get_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE {$table} (
				id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				object_type VARCHAR(32) NOT NULL,
				object_id BIGINT UNSIGNED NOT NULL,
				chunk_index INT UNSIGNED NOT NULL DEFAULT 0,
				provider VARCHAR(64) NOT NULL,
				model VARCHAR(128) NOT NULL,
				object_subtype VARCHAR(32) NOT NULL DEFAULT '',
				dimensions INT UNSIGNED NOT NULL,
				embedding MEDIUMBLOB NOT NULL,
				embedding_norm DOUBLE NOT NULL,
				embedding_coarse VARBINARY(512) NULL,
				content_hash VARCHAR(64) NOT NULL DEFAULT '',
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				UNIQUE KEY uniq_object_model_chunk (object_type, object_id, provider, model, chunk_index),
				KEY idx_provider_model (provider, model),
				KEY idx_object (object_type, object_id),
				KEY idx_content_hash (content_hash)
			) {$wpdb->get_charset_collate()}"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
