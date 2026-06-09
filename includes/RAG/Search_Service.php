<?php
/**
 * Semantic RAG search service.
 *
 * @package WordPress\AI\RAG
 */

declare( strict_types=1 );

namespace WordPress\AI\RAG;

use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Runs semantic search against indexed chunks.
 *
 * @since 1.1.0
 */
class Search_Service {
	/**
	 * Availability service.
	 *
	 * @var \WordPress\AI\RAG\Availability
	 */
	private Availability $availability;

	/**
	 * Repository.
	 *
	 * @var \WordPress\AI\RAG\Index_Repository_Interface
	 */
	private Index_Repository_Interface $repository;

	/**
	 * Embedding client.
	 *
	 * @var \WordPress\AI\RAG\OpenAI_Embedding_Client
	 */
	private OpenAI_Embedding_Client $embedding_client;

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 *
	 * @param \WordPress\AI\RAG\Availability|null             $availability     Availability service.
	 * @param \WordPress\AI\RAG\Index_Repository_Interface|null $repository       Repository.
	 * @param \WordPress\AI\RAG\OpenAI_Embedding_Client|null $embedding_client Embedding client.
	 */
	public function __construct(
		?Availability $availability = null,
		?Index_Repository_Interface $repository = null,
		?OpenAI_Embedding_Client $embedding_client = null
	) {
		$this->availability     = $availability ?? new Availability();
		$this->repository       = $repository ?? $this->availability->create_index_repository();
		$this->embedding_client = $embedding_client ?? new OpenAI_Embedding_Client();
	}

	/**
	 * Searches posts semantically.
	 *
	 * @since 1.1.0
	 *
	 * @param string $query Search query.
	 * @param array{per_page?:int, max_per_page?:int, candidate_limit?:int, post_type?:string|list<string>, post_status?:string|list<string>, enforce_read_permission?:bool} $args Search args.
	 * @return array<string, mixed>|\WP_Error Search results or error.
	 */
	public function search( string $query, array $args = array() ) {
		$query = trim( $query );
		if ( '' === $query ) {
			return new WP_Error( 'wpai_rag_empty_query', __( 'Search query cannot be empty.', 'ai' ), array( 'status' => 400 ) );
		}

		$defaults = array(
			'per_page'                => 10,
			'max_per_page'            => 20,
			'candidate_limit'         => (int) apply_filters( 'wpai_rag_search_candidate_limit', 50 ),
			'post_type'               => array(),
			'post_status'             => array(),
			'enforce_read_permission' => true,
		);
		$args     = wp_parse_args( $args, $defaults );
		$per_page = max( 1, min( max( 1, (int) $args['max_per_page'] ), (int) $args['per_page'] ) );
		$limit    = max( $per_page, min( 100, (int) $args['candidate_limit'] ) );

		$embedding = $this->embedding_client->embed( array( $this->prepare_query_text( $query ) ) );
		if ( is_wp_error( $embedding ) ) {
			return $embedding;
		}

		if ( empty( $embedding[0] ) || ! is_array( $embedding[0] ) ) {
			return new WP_Error( 'wpai_rag_query_embedding_failed', __( 'Failed to generate a query embedding.', 'ai' ), array( 'status' => 500 ) );
		}

		$query_embedding = array_values( $embedding[0] );

		$rows = $this->repository->search(
			$query_embedding,
			array(
				'limit'       => $limit,
				'model'       => $this->availability->get_embedding_model(),
				'post_type'   => $this->sanitize_list_arg( $args['post_type'] ),
				'post_status' => $this->sanitize_list_arg( $args['post_status'] ),
			)
		);

		$results = $this->group_rows_by_post( $rows, $per_page, (bool) $args['enforce_read_permission'] );

		/**
		 * Filters semantic RAG search results.
		 *
		 * @since 1.1.0
		 *
		 * @param list<array<string, mixed>> $results Results.
		 * @param string                     $query   Search query.
		 * @param array<string, mixed>       $args    Search args.
		 */
		$results = (array) apply_filters( 'wpai_rag_search_results', $results, $query, $args );

		return array(
			'query'   => $query,
			'model'   => $this->availability->get_embedding_model(),
			'results' => array_values( $results ),
		);
	}

	/**
	 * Groups chunk rows by post.
	 *
	 * @since 1.1.0
	 *
	 * @param list<array<string, mixed>> $rows                    Chunk rows.
	 * @param int                        $per_page                Number of posts to return.
	 * @param bool                       $enforce_read_permission Whether to enforce read_post.
	 * @return list<array<string, mixed>> Results.
	 */
	private function group_rows_by_post( array $rows, int $per_page, bool $enforce_read_permission ): array {
		$grouped = array();

		foreach ( $rows as $row ) {
			$post_id = isset( $row['post_id'] ) ? (int) $row['post_id'] : 0;
			if ( $post_id <= 0 ) {
				continue;
			}

			if ( $enforce_read_permission && ! current_user_can( 'read_post', $post_id ) ) {
				continue;
			}

			$post = get_post( $post_id );
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$distance = isset( $row['distance'] ) ? (float) $row['distance'] : 1.0;

			if ( ! isset( $grouped[ $post_id ] ) ) {
				$permalink           = get_permalink( $post );
				$grouped[ $post_id ] = array(
					'post_id'     => $post_id,
					'post_type'   => (string) $post->post_type,
					'post_status' => (string) $post->post_status,
					'title'       => get_the_title( $post ),
					'permalink'   => is_string( $permalink ) ? $permalink : '',
					'score'       => max( 0.0, 1.0 - $distance ),
					'distance'    => $distance,
					'chunks'      => array(),
				);
			}

			if ( $distance < (float) $grouped[ $post_id ]['distance'] ) {
				$grouped[ $post_id ]['distance'] = $distance;
				$grouped[ $post_id ]['score']    = max( 0.0, 1.0 - $distance );
			}

			if ( count( $grouped[ $post_id ]['chunks'] ) < 3 ) {
				$grouped[ $post_id ]['chunks'][] = array(
					'chunk_id'    => (string) $row['chunk_id'],
					'chunk_index' => (int) $row['chunk_index'],
					'excerpt'     => wp_html_excerpt( (string) $row['content'], 280, '...' ),
					'anchor'      => '',
					'permalink'   => (string) $grouped[ $post_id ]['permalink'],
					'distance'    => $distance,
				);
			}

			if ( count( $grouped ) >= $per_page ) {
				break;
			}
		}

		return array_values( $grouped );
	}

	/**
	 * Prepares query text for embedding.
	 *
	 * @since 1.1.0
	 *
	 * @param string $query Raw query.
	 * @return string Query text.
	 */
	private function prepare_query_text( string $query ): string {
		/**
		 * Filters the prefix applied to semantic query embedding inputs.
		 *
		 * @since 1.1.0
		 *
		 * @param string $prefix Query prefix.
		 */
		$prefix = (string) apply_filters( 'wpai_rag_query_embedding_input_prefix', '' );

		return $prefix . $query;
	}

	/**
	 * Sanitizes comma-separated or array list arguments.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $value Raw value.
	 * @return list<string> Sanitized list.
	 */
	private function sanitize_list_arg( $value ): array {
		if ( is_string( $value ) ) {
			$value = explode( ',', $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$items = array();
		foreach ( $value as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}

			$item = sanitize_key( (string) $item );
			if ( '' === $item ) {
				continue;
			}

			$items[] = $item;
		}

		return array_values( array_unique( $items ) );
	}
}
