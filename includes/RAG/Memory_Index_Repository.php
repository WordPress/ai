<?php
/**
 * Compact in-memory exact-scan RAG repository.
 *
 * @package WordPress\AI\RAG
 */

declare( strict_types=1 );

namespace WordPress\AI\RAG;

use InvalidArgumentException;
use RuntimeException;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Stores compact vectors in post meta and scans them in memory at query time.
 *
 * @since 1.1.0
 */
class Memory_Index_Repository implements Index_Repository_Interface {
	/**
	 * Post meta key storing compact chunk records.
	 */
	public const META_CHUNKS = '_ai_rag_memory_chunks';

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
	 * @param int $dimensions Vector dimensions.
	 */
	public function __construct( int $dimensions = 1536 ) {
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
	 * @throws \RuntimeException When a vector cannot be packed.
	 */
	public function replace_post_chunks( WP_Post $post, array $chunks, array $embeddings, string $model, string $hash ): void {
		if ( count( $chunks ) !== count( $embeddings ) ) {
			throw new InvalidArgumentException( 'Chunk and embedding counts must match.' );
		}

		$records = array();
		foreach ( $chunks as $index => $chunk ) {
			$embedding = $embeddings[ $index ];
			$this->validate_embedding( $embedding );

			$records[] = array(
				'post_id'              => (int) $post->ID,
				'post_type'            => (string) $post->post_type,
				'post_status'          => (string) $post->post_status,
				'chunk_id'             => (string) $chunk['chunk_id'],
				'chunk_index'          => (int) $chunk['chunk_index'],
				'chunk_offset'         => (int) $chunk['chunk_offset'],
				'anchor'               => isset( $chunk['anchor'] ) ? (string) $chunk['anchor'] : null,
				'title'                => (string) $chunk['title'],
				'permalink'            => (string) $chunk['permalink'],
				'content'              => (string) $chunk['content'],
				'content_hash'         => $hash,
				'embedding'            => base64_encode( $this->pack_embedding( $embedding ) ),
				'embedding_norm'       => $this->calculate_norm( $embedding ),
				'embedding_model'      => $model,
				'embedding_dimensions' => $this->dimensions,
				'indexed_at'           => current_time( 'mysql', true ),
			);
		}

		if ( empty( $records ) ) {
			$this->delete_post_chunks( (int) $post->ID );
			return;
		}

		$result = update_post_meta( (int) $post->ID, self::META_CHUNKS, $records );
		if ( false === $result && get_post_meta( (int) $post->ID, self::META_CHUNKS, true ) !== $records ) {
			throw new RuntimeException( 'Failed to store RAG memory index chunks.' );
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
		delete_post_meta( $post_id, self::META_CHUNKS );
	}

	/**
	 * Searches the nearest chunks using an exact in-memory scan.
	 *
	 * @since 1.1.0
	 *
	 * @param list<int|float> $embedding Query vector.
	 * @param array{limit?:int, model?:string, post_type?:string|list<string>, post_status?:string|list<string>} $args Search args.
	 * @return list<array<string, mixed>> Matching rows.
	 */
	public function search( array $embedding, array $args = array() ): array {
		$this->validate_embedding( $embedding );

		$defaults   = array(
			'limit'       => 20,
			'model'       => 'text-embedding-3-small',
			'post_type'   => array(),
			'post_status' => array(),
		);
		$args       = wp_parse_args( $args, $defaults );
		$limit      = max( 1, min( 100, (int) $args['limit'] ) );
		$model      = (string) $args['model'];
		$query_norm = $this->calculate_norm( $embedding );

		if ( $query_norm <= 0.0 ) {
			return array();
		}

		$best = array();

		foreach ( $this->get_indexed_post_ids( $args ) as $post_id ) {
			$records = get_post_meta( $post_id, self::META_CHUNKS, true );
			if ( ! is_array( $records ) ) {
				continue;
			}

			foreach ( $records as $record ) {
				if ( ! is_array( $record ) || ! $this->record_matches( $record, $model ) ) {
					continue;
				}

				$distance = $this->distance_from_record( $embedding, $query_norm, $record );
				if ( null === $distance ) {
					continue;
				}

				$row             = $this->record_to_row( $record );
				$row['distance'] = $distance;

				$this->keep_best_row( $best, $row, $limit );
			}
		}

		usort(
			$best,
			static fn( array $a, array $b ): int => (float) $a['distance'] <=> (float) $b['distance']
		);

		return $best;
	}

	/**
	 * Returns indexed post IDs matching search args.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, mixed> $args Search args.
	 * @return iterable<int>
	 */
	private function get_indexed_post_ids( array $args ): iterable {
		$post_types    = $this->sanitize_slug_list( (array) $args['post_type'] );
		$post_statuses = $this->sanitize_slug_list( (array) $args['post_status'] );
		$page          = 1;
		$batch_size    = max( 50, min( 500, (int) apply_filters( 'wpai_rag_memory_scan_post_batch_size', 200 ) ) );

		do {
			$query = new WP_Query(
				array(
					'fields'                 => 'ids',
					'post_type'              => empty( $post_types ) ? 'any' : $post_types,
					'post_status'            => empty( $post_statuses ) ? 'any' : $post_statuses,
					'posts_per_page'         => $batch_size,
					'paged'                  => $page,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'ignore_sticky_posts'    => true,
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Fallback scans only posts that carry compact RAG metadata.
					'meta_query'             => array(
						'relation' => 'AND',
						array(
							'key'   => Index_Manager::META_STATUS,
							'value' => Index_Manager::STATUS_CLEAN,
						),
						array(
							'key'     => self::META_CHUNKS,
							'compare' => 'EXISTS',
						),
					),
				)
			);

			$post_ids = array();
			foreach ( $query->posts as $post ) {
				if ( $post instanceof WP_Post ) {
					$post_ids[] = (int) $post->ID;
					continue;
				}

				if ( ! is_numeric( $post ) ) {
					continue;
				}

				$post_ids[] = absint( $post );
			}

			$post_ids_count = count( $post_ids );

			foreach ( $post_ids as $post_id ) {
				if ( $post_id <= 0 ) {
					continue;
				}

				yield $post_id;
			}

			++$page;
		} while ( $post_ids_count === $batch_size );
	}

	/**
	 * Checks whether a compact chunk record is eligible for a query.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, mixed> $record Chunk record.
	 * @param string              $model  Embedding model.
	 * @return bool True when the record can be scanned.
	 */
	private function record_matches( array $record, string $model ): bool {
		return isset( $record['embedding'], $record['embedding_model'], $record['embedding_dimensions'] )
			&& is_string( $record['embedding'] )
			&& $model === (string) $record['embedding_model']
			&& $this->dimensions === (int) $record['embedding_dimensions'];
	}

	/**
	 * Calculates cosine distance for a compact chunk record.
	 *
	 * @since 1.1.0
	 *
	 * @param list<int|float>      $query      Query vector.
	 * @param float                $query_norm Query vector norm.
	 * @param array<string, mixed> $record     Chunk record.
	 * @return float|null Distance, or null when the record cannot be decoded.
	 */
	private function distance_from_record( array $query, float $query_norm, array $record ): ?float {
		$packed = base64_decode( (string) $record['embedding'], true );
		if ( ! is_string( $packed ) || strlen( $packed ) !== $this->dimensions * 4 ) {
			return null;
		}

		$values = unpack( 'g*', $packed );
		if ( ! is_array( $values ) || count( $values ) !== $this->dimensions ) {
			return null;
		}

		$dot = 0.0;
		for ( $i = 0; $i < $this->dimensions; ++$i ) {
			$dot += (float) $query[ $i ] * (float) $values[ $i + 1 ];
		}

		$record_norm = isset( $record['embedding_norm'] ) ? (float) $record['embedding_norm'] : $this->calculate_norm( array_values( $values ) );
		if ( $record_norm <= 0.0 ) {
			return null;
		}

		$similarity = $dot / ( $query_norm * $record_norm );
		$similarity = max( -1.0, min( 1.0, $similarity ) );

		return 1.0 - $similarity;
	}

	/**
	 * Converts a compact chunk record into the repository row shape.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, mixed> $record Chunk record.
	 * @return array<string, mixed> Row.
	 */
	private function record_to_row( array $record ): array {
		return array(
			'id'           => (string) ( (int) $record['post_id'] ) . ':' . (string) $record['chunk_id'],
			'post_id'      => (int) $record['post_id'],
			'post_type'    => (string) $record['post_type'],
			'post_status'  => (string) $record['post_status'],
			'chunk_id'     => (string) $record['chunk_id'],
			'chunk_index'  => (int) $record['chunk_index'],
			'chunk_offset' => (int) $record['chunk_offset'],
			'anchor'       => isset( $record['anchor'] ) ? (string) $record['anchor'] : null,
			'title'        => (string) $record['title'],
			'permalink'    => (string) $record['permalink'],
			'content'      => (string) $record['content'],
		);
	}

	/**
	 * Maintains the top rows by distance.
	 *
	 * @since 1.1.0
	 *
	 * @param list<array<string, mixed>> $best  Current best rows.
	 * @param array<string, mixed>       $row   Candidate row.
	 * @param int                        $limit Maximum rows.
	 */
	private function keep_best_row( array &$best, array $row, int $limit ): void {
		if ( count( $best ) < $limit ) {
			$best[] = $row;
			return;
		}

		$worst_index    = 0;
		$worst_distance = (float) $best[0]['distance'];

		foreach ( $best as $index => $candidate ) {
			$distance = (float) $candidate['distance'];
			if ( $distance <= $worst_distance ) {
				continue;
			}

			$worst_distance = $distance;
			$worst_index    = (int) $index;
		}

		if ( (float) $row['distance'] >= $worst_distance ) {
			return;
		}

		$best[ $worst_index ] = $row;
	}

	/**
	 * Packs an embedding into little-endian float32 bytes.
	 *
	 * @since 1.1.0
	 *
	 * @param list<int|float> $embedding Embedding vector.
	 * @return string Packed vector.
	 */
	private function pack_embedding( array $embedding ): string {
		$packed = '';

		foreach ( $embedding as $value ) {
			$packed .= pack( 'g', (float) $value );
		}

		if ( strlen( $packed ) !== $this->dimensions * 4 ) {
			throw new RuntimeException( 'Failed to pack embedding vector.' );
		}

		return $packed;
	}

	/**
	 * Calculates vector norm.
	 *
	 * @since 1.1.0
	 *
	 * @param list<int|float> $embedding Embedding vector.
	 * @return float Vector norm.
	 */
	private function calculate_norm( array $embedding ): float {
		$sum = 0.0;
		foreach ( $embedding as $value ) {
			$sum += (float) $value * (float) $value;
		}

		return sqrt( $sum );
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
}
