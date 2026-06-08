<?php
/**
 * Repository for MariaDB vector RAG index rows.
 *
 * @package WordPress\AI\RAG
 */

declare( strict_types=1 );

namespace WordPress\AI\RAG;

use InvalidArgumentException;
use RuntimeException;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Stores and queries RAG chunks.
 *
 * @since 1.1.0
 */
class Index_Repository {
	// Direct queries are intentional because this repository owns a dedicated vector table.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	/**
	 * Schema manager.
	 *
	 * @var \WordPress\AI\RAG\Index_Schema
	 */
	private Index_Schema $schema;

	/**
	 * Vector dimensions.
	 *
	 * @var int
	 */
	private int $dimensions;

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 *
	 * @param \WordPress\AI\RAG\Index_Schema $schema     Schema manager.
	 * @param int                            $dimensions Vector dimensions.
	 */
	public function __construct( Index_Schema $schema, int $dimensions = 1536 ) {
		$this->schema     = $schema;
		$this->dimensions = $dimensions;
	}

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
	 * @throws \RuntimeException When a database write fails.
	 */
	public function replace_post_chunks( WP_Post $post, array $chunks, array $embeddings, string $model, string $hash ): void {
		if ( count( $chunks ) !== count( $embeddings ) ) {
			throw new InvalidArgumentException( 'Chunk and embedding counts must match.' );
		}

		foreach ( $embeddings as $embedding ) {
			$this->validate_embedding( $embedding );
		}

		global $wpdb;

		$wpdb->query( 'START TRANSACTION' );

		try {
			$this->delete_post_chunks( (int) $post->ID );

			foreach ( $chunks as $index => $chunk ) {
				$this->insert_chunk( $post, $chunk, $embeddings[ $index ], $model, $hash );
			}

			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Previous exception is preserved for internal debugging, not rendered.
			throw new RuntimeException( 'Failed to replace RAG index chunks.', 0, $e );
		}
	}

	/**
	 * Deletes all indexed chunks for a post.
	 *
	 * @since 1.1.0
	 *
	 * @param int $post_id Post ID.
	 */
	public function delete_post_chunks( int $post_id ): void {
		global $wpdb;

		$table_name = $this->schema->get_table_name();
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table_name} WHERE post_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$post_id
			)
		);
	}

	/**
	 * Searches the nearest chunks.
	 *
	 * @since 1.1.0
	 *
	 * @param list<int|float> $embedding Query vector.
	 * @param array{limit?:int, model?:string, post_type?:string|list<string>, post_status?:string|list<string>} $args Search args.
	 * @return list<array<string, mixed>> Matching rows.
	 */
	public function search( array $embedding, array $args = array() ): array {
		$this->validate_embedding( $embedding );

		global $wpdb;

		$defaults = array(
			'limit'       => 20,
			'model'       => 'text-embedding-3-small',
			'post_type'   => array(),
			'post_status' => array(),
		);
		$args     = wp_parse_args( $args, $defaults );
		$limit    = max( 1, min( 100, (int) $args['limit'] ) );

		$table_name = $this->schema->get_table_name();
		$where      = array( 'embedding_model = %s' );
		$values     = array( (string) $args['model'] );

		$post_types = $this->sanitize_slug_list( (array) $args['post_type'] );
		if ( ! empty( $post_types ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
			$where[]      = "post_type IN ({$placeholders})";
			$values       = array_merge( $values, $post_types );
		}

		$post_statuses = $this->sanitize_slug_list( (array) $args['post_status'] );
		if ( ! empty( $post_statuses ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $post_statuses ), '%s' ) );
			$where[]      = "post_status IN ({$placeholders})";
			$values       = array_merge( $values, $post_statuses );
		}

		$vector_text = wp_json_encode( array_values( $embedding ) );
		if ( ! is_string( $vector_text ) ) {
			return array();
		}

		array_unshift( $values, $vector_text );
		$values[] = $limit;

		$where_clause = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic clauses are sanitized and target the owned vector index table.
		$sql = $wpdb->prepare(
			"SELECT id, post_id, post_type, post_status, chunk_id, chunk_index, chunk_offset, anchor, title, permalink, content,
				VEC_DISTANCE_COSINE(embedding, VEC_FromText(%s)) AS distance
			FROM {$table_name}
			WHERE {$where_clause}
			ORDER BY distance ASC
			LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$values
		);
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return $rows ? $rows : array();
	}

	/**
	 * Inserts one chunk row.
	 *
	 * @since 1.1.0
	 *
	 * @param \WP_Post $post Post object.
	 * @param array{chunk_id:string, chunk_index:int, chunk_offset:int, anchor?:string|null, title:string, permalink:string, content:string} $chunk Chunk data.
	 * @param list<int|float> $embedding Embedding vector.
	 * @param string $model Embedding model.
	 * @param string $hash Content hash.
	 * @throws \RuntimeException When insert fails.
	 */
	private function insert_chunk( WP_Post $post, array $chunk, array $embedding, string $model, string $hash ): void {
		global $wpdb;

		$table_name  = $this->schema->get_table_name();
		$vector_text = wp_json_encode( array_values( $embedding ) );
		if ( ! is_string( $vector_text ) ) {
			throw new RuntimeException( 'Failed to encode vector.' );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic table name targets the owned vector index table.
		$sql = $wpdb->prepare(
			"INSERT INTO {$table_name}
				(post_id, post_type, post_status, chunk_id, chunk_index, chunk_offset, anchor, title, permalink, content, content_hash, embedding, embedding_model, embedding_dimensions, indexed_at)
			VALUES
				(%d, %s, %s, %s, %d, %d, %s, %s, %s, %s, %s, VEC_FromText(%s), %s, %d, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			(int) $post->ID,
			(string) $post->post_type,
			(string) $post->post_status,
			(string) $chunk['chunk_id'],
			(int) $chunk['chunk_index'],
			(int) $chunk['chunk_offset'],
			isset( $chunk['anchor'] ) ? (string) $chunk['anchor'] : null,
			(string) $chunk['title'],
			(string) $chunk['permalink'],
			(string) $chunk['content'],
			$hash,
			$vector_text,
			$model,
			$this->dimensions,
			current_time( 'mysql', true )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( false === $result ) {
			throw new RuntimeException( 'Failed to insert RAG index chunk.' );
		}
	}

	/**
	 * Validates a vector.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $embedding Candidate vector.
	 */
	private function validate_embedding( $embedding ): void {
		if ( ! is_array( $embedding ) || count( $embedding ) !== $this->dimensions ) {
			throw new InvalidArgumentException( 'Embedding vector has the wrong dimensions.' );
		}

		foreach ( $embedding as $value ) {
			if ( ! is_int( $value ) && ! is_float( $value ) ) {
				throw new InvalidArgumentException( 'Embedding vector contains a non-numeric value.' );
			}
		}
	}

	/**
	 * Sanitizes a list of slugs.
	 *
	 * @since 1.1.0
	 *
	 * @param array<mixed> $values Raw values.
	 * @return list<string> Sanitized values.
	 */
	private function sanitize_slug_list( array $values ): array {
		$slugs = array();

		foreach ( $values as $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$value = sanitize_key( (string) $value );
			if ( '' === $value ) {
				continue;
			}

			$slugs[] = $value;
		}

		return array_values( array_unique( $slugs ) );
	}

	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}
