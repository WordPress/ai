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
 * @since x.x.x
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
	 * @since x.x.x
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->schema = new Embedding_Schema();

		$this->reset_storage();
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
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
	 * @since x.x.x
	 */
	private function reset_storage(): void {
		$this->schema->drop_table();
		delete_option( Embedding_Schema::SCHEMA_VERSION_OPTION );
	}

	/**
	 * Tests the prefixed table name.
	 *
	 * @since x.x.x
	 */
	public function test_get_table_name_is_prefixed(): void {
		global $wpdb;

		$this->assertSame( $wpdb->prefix . 'wpai_embeddings', $this->schema->get_table_name() );
	}

	/**
	 * Tests that upgrading creates the table and records the schema version.
	 *
	 * @since x.x.x
	 */
	public function test_maybe_upgrade_table_creates_table_and_records_version(): void {
		$this->assertFalse( $this->schema->table_exists() );

		$this->schema->maybe_upgrade_table();

		$this->assertTrue( $this->schema->table_exists() );
		$this->assertSame( '1', get_option( Embedding_Schema::SCHEMA_VERSION_OPTION ) );
	}

	/**
	 * Tests that the table has the expected columns and unique key.
	 *
	 * @since x.x.x
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
		$this->assertContains( 'idx_provider_model', $names );
		$this->assertContains( 'idx_object', $names );
	}

	/**
	 * Tests the exact column sequence of the unique key.
	 *
	 * The key defines what "the same embedding" means, so its membership is a data-format decision
	 * rather than an implementation detail. `object_subtype` and `dimensions` must stay out of it:
	 * both can legitimately change between two indexing passes over one object, and including
	 * either turns the upsert into an insert that strands the row it should have replaced.
	 *
	 * @since x.x.x
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
	 * @since x.x.x
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
	 * @since x.x.x
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
	 * @since x.x.x
	 */
	public function test_maybe_upgrade_table_is_idempotent(): void {
		$this->schema->maybe_upgrade_table();
		$this->schema->maybe_upgrade_table();

		$this->assertTrue( $this->schema->table_exists() );
	}

	/**
	 * Tests that a stale version option does not stop the table from being recreated.
	 *
	 * @since x.x.x
	 */
	public function test_maybe_upgrade_table_recreates_missing_table_despite_version_option(): void {
		update_option( Embedding_Schema::SCHEMA_VERSION_OPTION, '1', false );

		$this->schema->maybe_upgrade_table();

		$this->assertTrue( $this->schema->table_exists() );
	}

	/**
	 * Tests that dropping removes the table and the version option.
	 *
	 * @since x.x.x
	 */
	public function test_drop_table(): void {
		$this->schema->maybe_upgrade_table();
		$this->schema->drop_table();

		$this->assertFalse( $this->schema->table_exists() );
		$this->assertFalse( get_option( Embedding_Schema::SCHEMA_VERSION_OPTION ) );
	}
}
