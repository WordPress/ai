<?php
/**
 * Repository contract for RAG index backends.
 *
 * @package WordPress\AI\RAG
 */

declare( strict_types=1 );

namespace WordPress\AI\RAG;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Stores and queries RAG chunks.
 *
 * @since 1.1.0
 */
interface Index_Repository_Interface {
	/**
	 * Replaces all chunks for a post.
	 *
	 * @since 1.1.0
	 *
	 * @param \WP_Post $post       Post object.
	 * @param list<array{chunk_id:string, chunk_index:int, chunk_offset:int, anchor?:string|null, title:string, permalink:string, content:string}> $chunks Chunk data.
	 * @param list<list<int|float>> $embeddings Embedding vectors in chunk order.
	 * @param string $model Embedding model.
	 * @param string $hash Content hash.
	 */
	public function replace_post_chunks( WP_Post $post, array $chunks, array $embeddings, string $model, string $hash ): void;

	/**
	 * Deletes all indexed chunks for a post.
	 *
	 * @since 1.1.0
	 *
	 * @param int $post_id Post ID.
	 */
	public function delete_post_chunks( int $post_id ): void;

	/**
	 * Searches the nearest chunks.
	 *
	 * @since 1.1.0
	 *
	 * @param list<int|float> $embedding Query vector.
	 * @param array{limit?:int, model?:string, post_type?:string|list<string>, post_status?:string|list<string>} $args Search args.
	 * @return list<array<string, mixed>> Matching rows.
	 */
	public function search( array $embedding, array $args = array() ): array;
}
