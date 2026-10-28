<?php
/**
 * Tests for the two-pass embedding similarity search.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Embeddings
 */

namespace WordPress\AI\Tests\Integration\Includes\Embeddings;

use WP_UnitTestCase;
use WordPress\AI\Embeddings\Embedding_Record;
use WordPress\AI\Embeddings\Embedding_Repository;
use WordPress\AI\Embeddings\Embedding_Schema;
use WordPress\AI\Embeddings\Similarity_Search;

/**
 * Similarity_Search test case.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Embeddings\Similarity_Search
 */
class Similarity_SearchTest extends WP_UnitTestCase {

	private const PROVIDER = 'ollama';
	private const MODEL    = 'nomic-embed-text:latest';

	/**
	 * Repository the search reads from.
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
	 * Search under test.
	 *
	 * @var \WordPress\AI\Embeddings\Similarity_Search
	 */
	private Similarity_Search $search;

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
		$this->search     = new Similarity_Search( $this->repository );
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
	 * Drops the embeddings table, since its DDL commits and escapes the test transaction.
	 *
	 * @since x.x.x
	 */
	private function reset_storage(): void {
		$this->schema->drop_table();
		delete_option( Embedding_Schema::SCHEMA_VERSION_OPTION );
	}

	/**
	 * Builds a term record.
	 *
	 * @since x.x.x
	 *
	 * @param int             $term_id     Term ID.
	 * @param list<int|float> $vector      Vector.
	 * @param string          $subtype     Optional. Taxonomy. Default `post_tag`.
	 * @param string          $model       Optional. Model ID. Default the test model.
	 * @param int             $chunk_index Optional. Chunk index. Default 0.
	 * @return \WordPress\AI\Embeddings\Embedding_Record The record.
	 */
	private function make_term( int $term_id, array $vector, string $subtype = 'post_tag', string $model = self::MODEL, int $chunk_index = 0 ): Embedding_Record {
		return new Embedding_Record( 'term', $term_id, self::PROVIDER, $model, $vector, $chunk_index, '', 0, $subtype );
	}

	/**
	 * Tests that the most similar object comes back first.
	 *
	 * @since x.x.x
	 */
	public function test_search_returns_most_similar_objects_first(): void {
		$query = array( 0.9, -0.4, 0.3, 0.1, -0.7, 0.2, 0.5, -0.1 );

		$this->repository->save_many(
			array(
				$this->make_term( 1, array( -0.8, 0.5, -0.2, 0.3, 0.6, -0.1, -0.4, 0.2 ) ),
				$this->make_term( 2, array( 0.91, -0.39, 0.31, 0.1, -0.69, 0.2, 0.49, -0.1 ) ),
				$this->make_term( 3, array( 0.5, 0.4, -0.3, 0.1, -0.2, 0.6, 0.1, 0.3 ) ),
			)
		);

		$results = $this->search->search( $query, self::PROVIDER, self::MODEL, 'term', array( 'post_tag' ), 3 );

		$this->assertSame( array( 2, 3, 1 ), array_keys( $results ) );
		$this->assertGreaterThan( 0.99, $results[2] );
	}

	/**
	 * Tests that an object with several chunks appears once, scored by its best chunk.
	 *
	 * @since x.x.x
	 */
	public function test_search_collapses_chunks_to_best_score(): void {
		$query = array( 1.0, 0.5, -0.5, 0.2, 0.0, 0.3, -0.2, 0.1 );
		$close = array( 0.95, 0.5, -0.5, 0.2, 0.05, 0.3, -0.2, 0.1 );
		$far   = array( -1.0, -0.5, 0.5, -0.2, 0.0, -0.3, 0.2, -0.1 );

		$this->repository->save_many(
			array(
				$this->make_term( 7, $far, 'post_tag', self::MODEL, 0 ),
				$this->make_term( 7, $close, 'post_tag', self::MODEL, 1 ),
				$this->make_term( 8, array( 0.1, 0.9, 0.2, -0.4, 0.3, 0.0, 0.1, 0.5 ) ),
			)
		);

		$results = $this->search->search( $query, self::PROVIDER, self::MODEL, 'term', array( 'post_tag' ), 10 );

		$this->assertSame( array( 7, 8 ), array_keys( $results ) );
		$this->assertGreaterThan( 0.99, $results[7] );
	}

	/**
	 * Tests that vectors from another model or subtype are never returned, however close.
	 *
	 * @since x.x.x
	 */
	public function test_search_never_crosses_models_or_subtypes(): void {
		$query = array( 0.3, 0.6, -0.2, 0.8, -0.5, 0.1, 0.4, -0.3 );

		$this->repository->save_many(
			array(
				$this->make_term( 1, array( -0.3, 0.6, 0.2, 0.1, -0.5, 0.4, -0.4, 0.3 ) ),
				$this->make_term( 2, $query, 'post_tag', 'other-model' ),
				$this->make_term( 3, $query, 'category' ),
			)
		);

		$results = $this->search->search( $query, self::PROVIDER, self::MODEL, 'term', array( 'post_tag' ), 10 );

		$this->assertSame( array( 1 ), array_keys( $results ) );
	}

	/**
	 * Tests that objects outside the allowlist are never returned, however close.
	 *
	 * @since x.x.x
	 */
	public function test_search_respects_object_id_allowlist(): void {
		$query = array( 0.3, 0.6, -0.2, 0.8, -0.5, 0.1, 0.4, -0.3 );

		$this->repository->save_many(
			array(
				$this->make_term( 1, $query ),
				$this->make_term( 2, array( 0.25, 0.6, -0.1, 0.7, -0.5, 0.2, 0.3, -0.3 ) ),
			)
		);

		$results = $this->search->search( $query, self::PROVIDER, self::MODEL, 'term', array( 'post_tag' ), 10, array( 2 ) );

		$this->assertSame( array( 2 ), array_keys( $results ) );
	}

	/**
	 * Tests that a search over 50,000 1536-dimension rows finishes in under a second.
	 *
	 * Excluded from the default run in phpunit.xml.dist. Run it with
	 * `npm run test:php -- --group slow --filter Similarity_SearchTest`.
	 *
	 * @since x.x.x
	 *
	 * @group slow
	 */
	public function test_search_over_50k_terms_under_one_second(): void {
		$dimensions = 1536;
		$rows       = 50000;
		$batch      = 500;

		mt_srand( 1536 );

		for ( $offset = 0; $offset < $rows; $offset += $batch ) {
			$records = array();

			for ( $i = 1; $i <= $batch; $i++ ) {
				$records[] = $this->make_term( $offset + $i, $this->random_vector( $dimensions ) );
			}

			$this->repository->save_many( $records );
		}

		$query = $this->random_vector( $dimensions );

		$start   = microtime( true );
		$results = $this->search->search( $query, self::PROVIDER, self::MODEL, 'term', array( 'post_tag' ), 10 );
		$elapsed = microtime( true ) - $start;

		fwrite( STDERR, sprintf( "\nSimilarity_Search over %d rows: %.3f s\n", $rows, $elapsed ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

		$this->assertCount( 10, $results );
		$this->assertLessThan( 1.0, $elapsed );
	}

	/**
	 * Returns a random vector with components in [-1, 1].
	 *
	 * @since x.x.x
	 *
	 * @param int $dimensions Number of components.
	 * @return list<float> The vector.
	 */
	private function random_vector( int $dimensions ): array {
		$vector = array();

		for ( $i = 0; $i < $dimensions; $i++ ) {
			$vector[] = mt_rand( -10000, 10000 ) / 10000;
		}

		return $vector;
	}
}
