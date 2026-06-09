<?php
/**
 * Integration tests for the RAG post chunker.
 *
 * @package WordPress\AI\Tests\Integration\Includes\RAG
 */

namespace WordPress\AI\Tests\Integration\Includes\RAG;

use WP_UnitTestCase;
use WordPress\AI\RAG\Post_Chunker;

/**
 * Post chunker test case.
 *
 * @since 1.1.0
 */
class Post_ChunkerTest extends WP_UnitTestCase {
	/**
	 * Tests chunk generation.
	 *
	 * @since 1.1.0
	 */
	public function test_chunk_post_returns_deterministic_chunks(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Semantic Search',
				'post_content' => '<p>This is the first paragraph. This is the second paragraph.</p>',
				'post_status'  => 'publish',
			)
		);

		$post    = get_post( $post_id );
		$chunker = new Post_Chunker();

		$first  = $chunker->chunk_post( $post );
		$second = $chunker->chunk_post( $post );

		$this->assertNotEmpty( $first );
		$this->assertSame( $first, $second );
		$this->assertSame( 0, $first[0]['chunk_index'] );
		$this->assertSame( 0, $first[0]['chunk_offset'] );
		$this->assertStringNotContainsString( 'Semantic Search', $first[0]['content'] );
		$this->assertStringContainsString( 'Semantic Search', $first[0]['embedding_text'] );
		$this->assertStringContainsString( 'first paragraph', $first[0]['content'] );
	}

	/**
	 * Tests empty content handling.
	 *
	 * @since 1.1.0
	 */
	public function test_chunk_post_handles_empty_content(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => '',
				'post_content' => '',
				'post_status'  => 'publish',
			)
		);

		$chunker = new Post_Chunker();

		$this->assertSame( array(), $chunker->chunk_post( get_post( $post_id ) ) );
	}
}
