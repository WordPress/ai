<?php
/**
 * Integration tests for the compact memory RAG index repository.
 *
 * @package WordPress\AI\Tests\Integration\Includes\RAG
 */

namespace WordPress\AI\Tests\Integration\Includes\RAG;

use WP_UnitTestCase;
use WordPress\AI\RAG\Index_Manager;
use WordPress\AI\RAG\Memory_Index_Repository;

/**
 * Memory index repository test case.
 *
 * @since 1.1.0
 */
class Memory_Index_RepositoryTest extends WP_UnitTestCase {
	/**
	 * Tests storing compact vectors and exact search.
	 *
	 * @since 1.1.0
	 */
	public function test_replace_post_chunks_stores_packed_vectors_and_searches_exact_matches(): void {
		$repo     = new Memory_Index_Repository( 3 );
		$post_one = self::factory()->post->create(
			array(
				'post_title'   => 'Alpha',
				'post_content' => 'Alpha content',
				'post_status'  => 'publish',
			)
		);
		$post_two = self::factory()->post->create(
			array(
				'post_title'   => 'Beta',
				'post_content' => 'Beta content',
				'post_status'  => 'publish',
			)
		);

		$repo->replace_post_chunks(
			get_post( $post_one ),
			array( $this->create_chunk( 'alpha', 'Alpha content' ) ),
			array( array( 1.0, 0.0, 0.0 ) ),
			'test-embedding',
			'hash-one'
		);
		$repo->replace_post_chunks(
			get_post( $post_two ),
			array( $this->create_chunk( 'beta', 'Beta content' ) ),
			array( array( 0.0, 1.0, 0.0 ) ),
			'test-embedding',
			'hash-two'
		);
		update_post_meta( $post_one, Index_Manager::META_STATUS, Index_Manager::STATUS_CLEAN );
		update_post_meta( $post_two, Index_Manager::META_STATUS, Index_Manager::STATUS_CLEAN );

		$stored = get_post_meta( $post_one, Memory_Index_Repository::META_CHUNKS, true );

		$this->assertIsArray( $stored );
		$this->assertArrayNotHasKey( 'title', $stored[0] );
		$this->assertArrayNotHasKey( 'permalink', $stored[0] );
		$this->assertArrayNotHasKey( 'anchor', $stored[0] );
		$this->assertArrayNotHasKey( 'embedding_dimensions', $stored[0] );
		$this->assertSame( 16, strlen( $stored[0]['embedding'] ) );
		$this->assertSame( 12, strlen( base64_decode( $stored[0]['embedding'], true ) ) );

		$rows = $repo->search(
			array( 1.0, 0.0, 0.0 ),
			array(
				'limit' => 2,
				'model' => 'test-embedding',
			)
		);

		$this->assertCount( 2, $rows );
		$this->assertSame( $post_one, (int) $rows[0]['post_id'] );
		$this->assertSame( 'alpha', $rows[0]['chunk_id'] );
		$this->assertSame( 0.0, (float) $rows[0]['distance'] );
		$this->assertSame( $post_two, (int) $rows[1]['post_id'] );
		$this->assertGreaterThan( 0.9, (float) $rows[1]['distance'] );
	}

	/**
	 * Tests that model filters are applied.
	 *
	 * @since 1.1.0
	 */
	public function test_search_filters_by_model(): void {
		$repo    = new Memory_Index_Repository( 3 );
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$repo->replace_post_chunks(
			get_post( $post_id ),
			array( $this->create_chunk( 'chunk', 'Stored content' ) ),
			array( array( 1.0, 0.0, 0.0 ) ),
			'expected-model',
			'hash'
		);
		update_post_meta( $post_id, Index_Manager::META_STATUS, Index_Manager::STATUS_CLEAN );

		$rows = $repo->search(
			array( 1.0, 0.0, 0.0 ),
			array(
				'limit' => 1,
				'model' => 'other-model',
			)
		);

		$this->assertSame( array(), $rows );
	}

	/**
	 * Tests deleting compact chunk records.
	 *
	 * @since 1.1.0
	 */
	public function test_delete_post_chunks_removes_memory_index_meta(): void {
		$repo    = new Memory_Index_Repository( 3 );
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$repo->replace_post_chunks(
			get_post( $post_id ),
			array( $this->create_chunk( 'chunk', 'Stored content' ) ),
			array( array( 1.0, 0.0, 0.0 ) ),
			'test-embedding',
			'hash'
		);

		$this->assertNotEmpty( get_post_meta( $post_id, Memory_Index_Repository::META_CHUNKS, true ) );

		$repo->delete_post_chunks( $post_id );

		$this->assertSame( '', get_post_meta( $post_id, Memory_Index_Repository::META_CHUNKS, true ) );
	}

	/**
	 * Creates a chunk record.
	 *
	 * @since 1.1.0
	 *
	 * @param string $id      Chunk ID.
	 * @param string $content Chunk content.
	 * @return array<string, mixed> Chunk.
	 */
	private function create_chunk( string $id, string $content ): array {
		return array(
			'chunk_id'       => $id,
			'chunk_index'    => 0,
			'chunk_offset'   => 0,
			'content'        => $content,
			'embedding_text' => 'Test title ' . $content,
		);
	}
}
