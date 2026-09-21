<?php
/**
 * Tests for the database-backed embedding repository.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Embeddings
 */

namespace WordPress\AI\Tests\Integration\Includes\Embeddings;

use WP_UnitTestCase;
use WordPress\AI\Embeddings\Embedding_Record;
use WordPress\AI\Embeddings\Embedding_Repository;
use WordPress\AI\Embeddings\Embedding_Schema;
use WordPress\AI\Embeddings\Vector_Codec;

/**
 * Embedding_Repository test case.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Embeddings\Embedding_Repository
 */
class Embedding_RepositoryTest extends WP_UnitTestCase {

	private const PROVIDER = 'ollama';
	private const MODEL    = 'nomic-embed-text:latest';

	/**
	 * Repository under test.
	 *
	 * @var \WordPress\AI\Embeddings\Embedding_Repository
	 */
	private Embedding_Repository $repository;

	/**
	 * Schema instance.
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

		$this->repository = new Embedding_Repository( $this->schema );
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
	 * Builds a record with sensible defaults.
	 *
	 * @since x.x.x
	 *
	 * @param int             $object_id   Object ID.
	 * @param list<int|float> $vector      Optional. Vector. Default a 3-component vector.
	 * @param string          $model       Optional. Model ID. Default the test model.
	 * @param int             $chunk_index Optional. Chunk index. Default 0.
	 * @param string          $hash        Optional. Content hash. Default empty.
	 * @param string          $object_type Optional. Object type. Default `post`.
	 * @param string          $provider    Optional. Provider ID. Default the test provider.
	 * @return \WordPress\AI\Embeddings\Embedding_Record The record.
	 */
	private function make_record(
		int $object_id,
		array $vector = array( 0.1, 0.2, 0.3 ),
		string $model = self::MODEL,
		int $chunk_index = 0,
		string $hash = '',
		string $object_type = 'post',
		string $provider = self::PROVIDER
	): Embedding_Record {
		return new Embedding_Record( $object_type, $object_id, $provider, $model, $vector, $chunk_index, $hash );
	}

	/**
	 * Tests that reads on a site that never stored an embedding do not create the table.
	 *
	 * @since x.x.x
	 */
	public function test_reads_do_not_create_table(): void {
		$this->assertSame( array(), $this->repository->get( 'post', 1, self::PROVIDER, self::MODEL ) );
		$this->assertNull( $this->repository->get_by_id( 1 ) );
		$this->assertNull( $this->repository->get_content_hash( 'post', 1, self::PROVIDER, self::MODEL ) );
		$this->assertSame( array(), $this->repository->get_object_ids( 'post', self::PROVIDER, self::MODEL, 10 ) );
		$this->assertSame( 0, $this->repository->count_objects( 'post', self::PROVIDER, self::MODEL ) );
		$this->assertSame( array(), iterator_to_array( $this->repository->iterate( self::PROVIDER, self::MODEL ), false ) );
		$this->assertSame( 0, $this->repository->delete_for_object( 'post', 1 ) );
		$this->assertSame( 0, $this->repository->delete_for_model( self::PROVIDER, self::MODEL ) );

		$this->assertFalse( $this->schema->table_exists() );
	}

	/**
	 * Tests that the first write creates the table.
	 *
	 * @since x.x.x
	 */
	public function test_save_creates_table_on_first_write(): void {
		$this->assertFalse( $this->schema->table_exists() );

		$this->repository->save( $this->make_record( 1 ) );

		$this->assertTrue( $this->schema->table_exists() );
	}

	/**
	 * Tests a save and read round trip.
	 *
	 * @since x.x.x
	 */
	public function test_save_and_get_round_trip(): void {
		$vector = array( 0.123456, -0.654321, 1.0, 0.0 );
		$saved  = $this->repository->save( $this->make_record( 5, $vector, self::MODEL, 0, 'hash-5' ) );

		$this->assertGreaterThan( 0, $saved->get_id() );

		$records = $this->repository->get( 'post', 5, self::PROVIDER, self::MODEL );

		$this->assertCount( 1, $records );
		$record = $records[0];

		$this->assertSame( $saved->get_id(), $record->get_id() );
		$this->assertSame( 'post', $record->get_object_type() );
		$this->assertSame( 5, $record->get_object_id() );
		$this->assertSame( self::PROVIDER, $record->get_provider() );
		$this->assertSame( self::MODEL, $record->get_model() );
		$this->assertSame( 4, $record->get_dimensions() );
		$this->assertSame( 'hash-5', $record->get_content_hash() );
		$this->assertEqualsWithDelta( $vector, $record->get_vector(), 1.0e-6 );

		$by_id = $this->repository->get_by_id( $saved->get_id() );
		$this->assertNotNull( $by_id );
		$this->assertSame( 5, $by_id->get_object_id() );
	}

	/**
	 * Tests that saving again for the same object, model and chunk replaces the row in place.
	 *
	 * @since x.x.x
	 */
	public function test_save_replaces_existing_vector_in_place(): void {
		global $wpdb;

		$first  = $this->repository->save( $this->make_record( 3, array( 0.1, 0.1 ), self::MODEL, 0, 'old' ) );
		$second = $this->repository->save( $this->make_record( 3, array( 0.9, 0.9 ), self::MODEL, 0, 'new' ) );

		$this->assertSame( $first->get_id(), $second->get_id() );

		$records = $this->repository->get( 'post', 3, self::PROVIDER, self::MODEL );
		$this->assertCount( 1, $records );
		$this->assertEqualsWithDelta( array( 0.9, 0.9 ), $records[0]->get_vector(), 1.0e-6 );
		$this->assertSame( 'new', $records[0]->get_content_hash() );

		$table = $this->schema->get_table_name();
		$this->assertSame( '1', $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Tests that re-indexing an object whose subtype changed replaces the row.
	 *
	 * An object's subtype is mutable — a post converted to a page, a term moved between
	 * taxonomies — and the read side of the interface does not take a subtype argument. If subtype
	 * were part of the row's identity, the second save would insert instead of replacing and
	 * `get()` would return two vectors for one chunk while `get_content_hash()` picked between them
	 * by index order.
	 *
	 * @since x.x.x
	 */
	public function test_save_replaces_row_when_object_subtype_changes(): void {
		$first  = $this->repository->save(
			new Embedding_Record( 'post', 7, self::PROVIDER, self::MODEL, array( 0.1, 0.1 ), 0, 'old', 0, 'post' )
		);
		$second = $this->repository->save(
			new Embedding_Record( 'post', 7, self::PROVIDER, self::MODEL, array( 0.9, 0.9 ), 0, 'new', 0, 'page' )
		);

		$this->assertSame( $first->get_id(), $second->get_id() );

		$records = $this->repository->get( 'post', 7, self::PROVIDER, self::MODEL );

		$this->assertCount( 1, $records );
		$this->assertSame( 'page', $records[0]->get_object_subtype(), 'The stored subtype should follow the object.' );
		$this->assertSame( 'new', $this->repository->get_content_hash( 'post', 7, self::PROVIDER, self::MODEL ) );
	}

	/**
	 * Tests that a record saved without a subtype and then with one stays a single row.
	 *
	 * The documented usage example omits the subtype argument, so the same object reaching the
	 * repository from a subtype-aware path and a subtype-blind one is the likeliest way to end up
	 * with duplicates.
	 *
	 * @since x.x.x
	 */
	public function test_save_replaces_row_when_subtype_is_added_later(): void {
		$this->repository->save( $this->make_record( 8, array( 0.2, 0.2 ) ) );
		$this->repository->save(
			new Embedding_Record( 'post', 8, self::PROVIDER, self::MODEL, array( 0.4, 0.4 ), 0, '', 0, 'post' )
		);

		$records = $this->repository->get( 'post', 8, self::PROVIDER, self::MODEL );

		$this->assertCount( 1, $records );
		$this->assertSame( 'post', $records[0]->get_object_subtype() );
		$this->assertSame( 1, $this->repository->count_objects( 'post', self::PROVIDER, self::MODEL ) );
	}

	/**
	 * Tests that re-indexing the same model at a different dimension count replaces the row.
	 *
	 * `generate_embeddings()` accepts a dimensions argument, so one model can legitimately produce
	 * vectors of two lengths on the same site. The newer vector has to win outright: a mixture
	 * would hand a similarity pass two vectors of different lengths for a single chunk.
	 *
	 * @since x.x.x
	 */
	public function test_save_replaces_row_when_dimensions_change(): void {
		$this->repository->save( $this->make_record( 9, array( 0.1, 0.2, 0.3 ) ) );
		$this->repository->save( $this->make_record( 9, array( 0.4, 0.5 ) ) );

		$records = $this->repository->get( 'post', 9, self::PROVIDER, self::MODEL );

		$this->assertCount( 1, $records );
		$this->assertSame( 2, $records[0]->get_dimensions() );
		$this->assertEqualsWithDelta( array( 0.4, 0.5 ), $records[0]->get_vector(), 1.0e-6 );
	}

	/**
	 * Tests that models whose IDs share a long prefix are stored as separate vectors.
	 *
	 * Registry-qualified model IDs routinely run past 64 characters and differ only in a trailing
	 * quantization tag. If the unique key indexed a prefix of `model`, these two would collide on
	 * one row: the second save would overwrite the first's vector via `ON DUPLICATE KEY UPDATE`
	 * while the row kept reporting the first model's name, so reading back the first model would
	 * silently return the second model's vector.
	 *
	 * @since x.x.x
	 */
	public function test_models_sharing_a_long_id_prefix_are_kept_apart(): void {
		$model_a = 'hf.co/sentence-transformers/paraphrase-multilingual-mpnet-base-v2:Q4_K_M';
		$model_b = 'hf.co/sentence-transformers/paraphrase-multilingual-mpnet-base-v2:Q8_0';

		$this->assertSame( substr( $model_a, 0, 64 ), substr( $model_b, 0, 64 ), 'The fixtures must share a 64-character prefix.' );

		$this->repository->save( $this->make_record( 1, array( 0.1, 0.1 ), $model_a, 0, 'hash-a' ) );
		$this->repository->save( $this->make_record( 1, array( 0.9, 0.9 ), $model_b, 0, 'hash-b' ) );

		$records_a = $this->repository->get( 'post', 1, self::PROVIDER, $model_a );
		$records_b = $this->repository->get( 'post', 1, self::PROVIDER, $model_b );

		$this->assertCount( 1, $records_a );
		$this->assertCount( 1, $records_b );
		$this->assertSame( $model_a, $records_a[0]->get_model() );
		$this->assertSame( $model_b, $records_b[0]->get_model() );
		$this->assertEqualsWithDelta( array( 0.1, 0.1 ), $records_a[0]->get_vector(), 1.0e-6 );
		$this->assertEqualsWithDelta( array( 0.9, 0.9 ), $records_b[0]->get_vector(), 1.0e-6 );
	}

	/**
	 * Tests that vectors from different models for the same object are kept apart.
	 *
	 * @since x.x.x
	 */
	public function test_models_are_isolated(): void {
		$this->repository->save( $this->make_record( 1, array( 0.1, 0.2 ), 'nomic-embed-text:latest' ) );
		$this->repository->save( $this->make_record( 1, array( 0.5, 0.6, 0.7 ), 'mxbai-embed-large:latest' ) );
		$this->repository->save( $this->make_record( 1, array( 0.9 ), 'gemini-embedding-001', 0, '', 'post', 'google' ) );

		$nomic  = $this->repository->get( 'post', 1, self::PROVIDER, 'nomic-embed-text:latest' );
		$mxbai  = $this->repository->get( 'post', 1, self::PROVIDER, 'mxbai-embed-large:latest' );
		$gemini = $this->repository->get( 'post', 1, 'google', 'gemini-embedding-001' );

		$this->assertCount( 1, $nomic );
		$this->assertSame( 2, $nomic[0]->get_dimensions() );
		$this->assertCount( 1, $mxbai );
		$this->assertSame( 3, $mxbai[0]->get_dimensions() );
		$this->assertCount( 1, $gemini );
		$this->assertSame( 1, $gemini[0]->get_dimensions() );

		// Same model ID under a different provider is a different model.
		$this->assertSame( array(), $this->repository->get( 'post', 1, 'google', 'nomic-embed-text:latest' ) );

		$this->assertSame( 1, $this->repository->count_objects( 'post', self::PROVIDER, 'nomic-embed-text:latest' ) );
		$this->assertSame( 0, $this->repository->count_objects( 'post', self::PROVIDER, 'gemini-embedding-001' ) );
	}

	/**
	 * Tests that chunks are stored separately and returned in order.
	 *
	 * @since x.x.x
	 */
	public function test_chunks_are_returned_in_order(): void {
		$this->repository->save_many(
			array(
				$this->make_record( 8, array( 0.3 ), self::MODEL, 2 ),
				$this->make_record( 8, array( 0.1 ), self::MODEL, 0 ),
				$this->make_record( 8, array( 0.2 ), self::MODEL, 1 ),
			)
		);

		$records = $this->repository->get( 'post', 8, self::PROVIDER, self::MODEL );

		$this->assertSame( array( 0, 1, 2 ), array_map( static fn( Embedding_Record $r ): int => $r->get_chunk_index(), $records ) );
		$this->assertSame( 1, $this->repository->count_objects( 'post', self::PROVIDER, self::MODEL ) );
	}

	/**
	 * Tests that object types are kept apart.
	 *
	 * @since x.x.x
	 */
	public function test_object_types_are_isolated(): void {
		$this->repository->save( $this->make_record( 1, array( 0.1 ), self::MODEL, 0, '', 'post' ) );
		$this->repository->save( $this->make_record( 1, array( 0.2 ), self::MODEL, 0, '', 'term' ) );

		$this->assertCount( 1, $this->repository->get( 'post', 1, self::PROVIDER, self::MODEL ) );
		$this->assertCount( 1, $this->repository->get( 'term', 1, self::PROVIDER, self::MODEL ) );
		$this->assertSame( array( 1 ), $this->repository->get_object_ids( 'term', self::PROVIDER, self::MODEL, 10 ) );
	}

	/**
	 * Tests the content hash lookup.
	 *
	 * @since x.x.x
	 */
	public function test_get_content_hash(): void {
		$this->repository->save( $this->make_record( 4, array( 0.1 ), self::MODEL, 0, 'sha-4' ) );

		$this->assertSame( 'sha-4', $this->repository->get_content_hash( 'post', 4, self::PROVIDER, self::MODEL ) );
		$this->assertNull( $this->repository->get_content_hash( 'post', 99, self::PROVIDER, self::MODEL ) );
		$this->assertNull( $this->repository->get_content_hash( 'post', 4, 'google', self::MODEL ) );
	}

	/**
	 * Tests the bounded, newest-first object ID lookup.
	 *
	 * @since x.x.x
	 */
	public function test_get_object_ids_is_bounded_and_newest_first(): void {
		foreach ( array( 10, 30, 20, 40 ) as $id ) {
			$this->repository->save( $this->make_record( $id ) );
			$this->repository->save( $this->make_record( $id, array( 0.1, 0.2, 0.3 ), self::MODEL, 1 ) );
		}
		$this->repository->save( $this->make_record( 50, array( 0.1 ), 'other-model' ) );

		$this->assertSame( array( 40, 30, 20, 10 ), $this->repository->get_object_ids( 'post', self::PROVIDER, self::MODEL, 10 ) );
		$this->assertSame( array( 40, 30 ), $this->repository->get_object_ids( 'post', self::PROVIDER, self::MODEL, 2 ) );
		$this->assertSame( array( 20, 10 ), $this->repository->get_object_ids( 'post', self::PROVIDER, self::MODEL, 2, 2 ) );
		$this->assertSame( array(), $this->repository->get_object_ids( 'post', self::PROVIDER, self::MODEL, 0 ) );
		$this->assertSame( 4, $this->repository->count_objects( 'post', self::PROVIDER, self::MODEL ) );
	}

	/**
	 * Tests that iteration yields every record for a model across batches.
	 *
	 * @since x.x.x
	 */
	public function test_iterate_yields_all_records_in_batches(): void {
		for ( $i = 1; $i <= 7; $i++ ) {
			$this->repository->save( $this->make_record( $i, array( $i / 10 ) ) );
		}
		$this->repository->save( $this->make_record( 99, array( 0.5 ), 'other-model' ) );
		$this->repository->save( $this->make_record( 100, array( 0.5 ), self::MODEL, 0, '', 'term' ) );

		$all = iterator_to_array( $this->repository->iterate( self::PROVIDER, self::MODEL, null, 3 ), false );
		$this->assertCount( 8, $all );
		$this->assertSame( range( 1, 7 ), array_map( static fn( Embedding_Record $r ): int => $r->get_object_id(), array_slice( $all, 0, 7 ) ) );

		$posts = iterator_to_array( $this->repository->iterate( self::PROVIDER, self::MODEL, 'post', 3 ), false );
		$this->assertCount( 7, $posts );

		$other = iterator_to_array( $this->repository->iterate( self::PROVIDER, 'other-model' ), false );
		$this->assertCount( 1, $other );
		$this->assertSame( 99, $other[0]->get_object_id() );
	}

	/**
	 * Tests deleting an object's vectors, optionally scoped to a model.
	 *
	 * @since x.x.x
	 */
	public function test_delete_for_object(): void {
		$this->repository->save( $this->make_record( 1, array( 0.1 ), 'model-a' ) );
		$this->repository->save( $this->make_record( 1, array( 0.2 ), 'model-a', 1 ) );
		$this->repository->save( $this->make_record( 1, array( 0.3 ), 'model-b' ) );
		$this->repository->save( $this->make_record( 2, array( 0.4 ), 'model-a' ) );

		$this->assertSame( 2, $this->repository->delete_for_object( 'post', 1, self::PROVIDER, 'model-a' ) );
		$this->assertCount( 0, $this->repository->get( 'post', 1, self::PROVIDER, 'model-a' ) );
		$this->assertCount( 1, $this->repository->get( 'post', 1, self::PROVIDER, 'model-b' ) );

		$this->assertSame( 1, $this->repository->delete_for_object( 'post', 1 ) );
		$this->assertCount( 0, $this->repository->get( 'post', 1, self::PROVIDER, 'model-b' ) );
		$this->assertCount( 1, $this->repository->get( 'post', 2, self::PROVIDER, 'model-a' ) );
	}

	/**
	 * Tests deleting every vector produced by a model.
	 *
	 * @since x.x.x
	 */
	public function test_delete_for_model(): void {
		$this->repository->save( $this->make_record( 1, array( 0.1 ), 'model-a' ) );
		$this->repository->save( $this->make_record( 2, array( 0.2 ), 'model-a' ) );
		$this->repository->save( $this->make_record( 3, array( 0.3 ), 'model-b' ) );

		$this->assertSame( 2, $this->repository->delete_for_model( self::PROVIDER, 'model-a' ) );
		$this->assertSame( 0, $this->repository->count_objects( 'post', self::PROVIDER, 'model-a' ) );
		$this->assertSame( 1, $this->repository->count_objects( 'post', self::PROVIDER, 'model-b' ) );
	}

	/**
	 * Tests that a row whose bytes no longer match its dimensions is skipped rather than fatal.
	 *
	 * @since x.x.x
	 */
	public function test_corrupt_rows_are_skipped(): void {
		global $wpdb;

		$saved = $this->repository->save( $this->make_record( 1, array( 0.1, 0.2 ) ) );
		$this->repository->save( $this->make_record( 2, array( 0.3, 0.4 ) ) );

		$wpdb->update( $this->schema->get_table_name(), array( 'dimensions' => 5 ), array( 'id' => $saved->get_id() ), array( '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$this->assertNull( $this->repository->get_by_id( $saved->get_id() ) );
		$this->assertCount( 1, iterator_to_array( $this->repository->iterate( self::PROVIDER, self::MODEL ), false ) );
	}

	/**
	 * Tests that save_many() rejects a batch containing a non-record without writing anything.
	 *
	 * Silently skipping bad entries returned a list shorter than the input with no signal, so a
	 * caller pairing `$records[$i]` with `$saved[$i]` read back the wrong row IDs.
	 *
	 * @since x.x.x
	 */
	public function test_save_many_rejects_a_batch_containing_a_non_record(): void {
		$records = array(
			$this->make_record( 1 ),
			'not a record',
			$this->make_record( 2 ),
		);

		try {
			$this->repository->save_many( $records );
			$this->fail( 'Expected an InvalidArgumentException for the invalid entry.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'index 1', $e->getMessage() );
		}

		// The batch is validated before anything is written, so the valid entries must not persist.
		$this->assertSame( array(), $this->repository->get( 'post', 1, self::PROVIDER, self::MODEL ) );
		$this->assertSame( array(), $this->repository->get( 'post', 2, self::PROVIDER, self::MODEL ) );
	}

	/**
	 * Tests that save_many() returns one record per input, aligned by position.
	 *
	 * @since x.x.x
	 */
	public function test_save_many_returns_a_positionally_aligned_list(): void {
		$records = array(
			$this->make_record( 11 ),
			$this->make_record( 12 ),
			$this->make_record( 13 ),
		);

		$saved = $this->repository->save_many( $records );

		$this->assertCount( 3, $saved );
		$this->assertSame(
			array( 11, 12, 13 ),
			array_map( static fn( Embedding_Record $r ): int => $r->get_object_id(), $saved )
		);
	}

	/**
	 * Tests that iterate() raises rather than reporting a clean finish when a batch fails.
	 *
	 * A failed query cannot be told apart from an exhausted result set by return value, so the
	 * scan used to end early and complete normally — a caller rebuilding an index over the whole
	 * corpus would believe it had seen every vector.
	 *
	 * @since x.x.x
	 */
	public function test_iterate_throws_when_a_batch_cannot_be_read(): void {
		global $wpdb;

		$this->repository->save( $this->make_record( 1 ) );
		$this->repository->save( $this->make_record( 2 ) );

		$suppress = $wpdb->suppress_errors( true );
		$thrown   = null;
		$seen     = 0;

		try {
			// Dropping the table mid-scan makes the next batch query fail, which is the shape of the
			// real hazard: a deadlock, lock-wait timeout or dropped connection part-way through.
			foreach ( $this->repository->iterate( self::PROVIDER, self::MODEL, null, 1 ) as $record ) {
				++$seen;

				if ( 1 !== $seen ) {
					continue;
				}

				$this->schema->drop_table();
			}
		} catch ( \RuntimeException $e ) {
			$thrown = $e;
		} finally {
			// Leave the table and the error state as the next test expects to find them.
			$wpdb->suppress_errors( $suppress );
			$wpdb->last_error = '';
			$this->schema->maybe_upgrade_table();
		}

		$this->assertInstanceOf(
			\RuntimeException::class,
			$thrown,
			'iterate() must not complete normally when a batch cannot be read.'
		);
		$this->assertStringContainsString( 'Failed to read embedding records while iterating', $thrown->getMessage() );
		$this->assertSame( 1, $seen, 'Only the records read before the failure should have been yielded.' );
	}

	/**
	 * Tests that a read does not suppress the schema upgrade a later write depends on.
	 *
	 * The table has to already exist for this to bite: `table_available()` used to set the very
	 * flag `ensure_table()` checks, so once a read had confirmed the table was there, the write
	 * that followed skipped `maybe_upgrade_table()` entirely. On a request that reads before it
	 * writes — `get_content_hash()` then `save()`, the natural order for a sync pass — a pending
	 * schema migration would never run.
	 *
	 * @since x.x.x
	 */
	public function test_a_read_before_a_write_still_runs_the_schema_upgrade(): void {
		// Arrange: the table already exists, as it would on any request after the first.
		$this->repository->save( $this->make_record( 1, array( 0.1, 0.2, 0.3 ), self::MODEL, 0, 'hash-1' ) );
		$this->assertTrue( $this->schema->table_exists() );

		$schema     = new Recording_Embedding_Schema();
		$repository = new Embedding_Repository( $schema );

		// A read finds the existing table.
		$this->assertSame( 'hash-1', $repository->get_content_hash( 'post', 1, self::PROVIDER, self::MODEL ) );

		// The write that follows must still consult the schema.
		$repository->save( $this->make_record( 2 ) );

		$this->assertSame(
			1,
			$schema->upgrade_calls,
			'A preceding read must not stop the write from running the schema upgrade.'
		);

		// And the upgrade is cached for the rest of the request rather than re-run per write.
		$repository->save( $this->make_record( 3 ) );
		$this->assertSame( 1, $schema->upgrade_calls );
	}

	/**
	 * Tests that saving stores a coarse code alongside the vector.
	 *
	 * Populating it on write is the point: deriving it later would mean reading and rewriting every
	 * row, so the initial backfill would be paid for twice.
	 *
	 * @since x.x.x
	 */
	public function test_save_stores_a_coarse_code_alongside_the_vector(): void {
		global $wpdb;

		$vector = array( 0.5, -0.5, 2.0, -2.0, 0.0, -0.1, -9.0, 0.001 );
		$saved  = $this->repository->save( $this->make_record( 1, $vector ) );

		$table  = $this->schema->get_table_name();
		$coarse = $wpdb->get_var( $wpdb->prepare( "SELECT embedding_coarse FROM {$table} WHERE id = %d", $saved->get_id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertSame( bin2hex( Vector_Codec::pack_coarse( $vector ) ), bin2hex( (string) $coarse ) );
		$this->assertSame( 1, strlen( (string) $coarse ), 'Eight components pack into a single byte.' );
	}

	/**
	 * Tests that the stored coarse code survives the column unaltered at realistic dimensions.
	 *
	 * A `VARBINARY` column silently truncates an over-long value under a non-strict SQL mode, and a
	 * truncated code scores as a valid but wrong distance, so the round trip has to be exact.
	 *
	 * @since x.x.x
	 */
	public function test_coarse_code_round_trips_at_realistic_dimensions(): void {
		global $wpdb;

		$vector = array();
		for ( $i = 0; $i < 3072; $i++ ) {
			$vector[] = sin( $i ) / 10;
		}

		$saved = $this->repository->save( $this->make_record( 1, $vector, 'gemini-embedding-001', 0, '', 'post', 'google' ) );

		$table  = $this->schema->get_table_name();
		$coarse = (string) $wpdb->get_var( $wpdb->prepare( "SELECT embedding_coarse FROM {$table} WHERE id = %d", $saved->get_id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertSame( 384, strlen( $coarse ), '3072 components pack into 384 bytes.' );
		$this->assertSame( bin2hex( Vector_Codec::pack_coarse( $vector ) ), bin2hex( $coarse ) );
		$this->assertSame( 0, Vector_Codec::hamming( $coarse, Vector_Codec::pack_coarse( $vector ) ) );
	}

	/**
	 * Tests that re-indexing refreshes the coarse code rather than leaving the old one.
	 *
	 * @since x.x.x
	 */
	public function test_save_refreshes_the_coarse_code_on_reindex(): void {
		global $wpdb;

		$this->repository->save( $this->make_record( 1, array( 1.0, 1.0, 1.0, 1.0 ) ) );
		$saved = $this->repository->save( $this->make_record( 1, array( -1.0, -1.0, -1.0, -1.0 ) ) );

		$table  = $this->schema->get_table_name();
		$coarse = (string) $wpdb->get_var( $wpdb->prepare( "SELECT embedding_coarse FROM {$table} WHERE id = %d", $saved->get_id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertSame( bin2hex( Vector_Codec::pack_coarse( array( -1.0, -1.0, -1.0, -1.0 ) ) ), bin2hex( $coarse ) );
	}

	/**
	 * Tests that the coarse column stays inline in the clustered index.
	 *
	 * This is the whole reason the column is a `VARBINARY` and not a BLOB: a first-pass scan that
	 * reads only this column must never follow an off-page pointer.
	 *
	 * @since x.x.x
	 */
	public function test_coarse_column_is_a_varbinary(): void {
		global $wpdb;

		$this->repository->save( $this->make_record( 1 ) );

		$table  = $this->schema->get_table_name();
		$column = $wpdb->get_row( "SHOW COLUMNS FROM {$table} LIKE 'embedding_coarse'", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertSame( 'varbinary(512)', strtolower( (string) $column['Type'] ) );
		$this->assertSame( 'YES', (string) $column['Null'], 'Rows written before the coarse path existed have no code.' );
	}

	/**
	 * Tests that a large, realistic vector round-trips.
	 *
	 * @since x.x.x
	 */
	public function test_large_vector_round_trip(): void {
		$vector = array();
		for ( $i = 0; $i < 3072; $i++ ) {
			$vector[] = sin( $i ) / 10;
		}

		$this->repository->save( $this->make_record( 1, $vector, 'gemini-embedding-001', 0, '', 'post', 'google' ) );

		$records = $this->repository->get( 'post', 1, 'google', 'gemini-embedding-001' );

		$this->assertCount( 1, $records );
		$this->assertSame( 3072, $records[0]->get_dimensions() );
		$this->assertEqualsWithDelta( $vector, $records[0]->get_vector(), 1.0e-6 );
	}
}

/**
 * Schema that counts how many times an upgrade was requested.
 *
 * @since x.x.x
 */
class Recording_Embedding_Schema extends Embedding_Schema {

	/**
	 * Number of times maybe_upgrade_table() was called.
	 *
	 * @var int
	 */
	public int $upgrade_calls = 0;

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function maybe_upgrade_table(): void {
		++$this->upgrade_calls;

		parent::maybe_upgrade_table();
	}
}
