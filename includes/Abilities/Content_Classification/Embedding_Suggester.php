<?php
/**
 * Suggests existing terms for a post by embedding similarity.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Content_Classification;

use InvalidArgumentException;
use RuntimeException;
use WP_Error;
use WP_Post;
use WP_Term;
use WordPress\AI\Embeddings\Embedding_Record;
use WordPress\AI\Embeddings\Embedding_Repository_Interface;
use WordPress\AI\Embeddings\Similarity_Search;
use WordPress\AI\Embeddings\Sync\Embedding_Client_Exception;
use WordPress\AI\Embeddings\Sync\Embedding_Client_Interface;
use WordPress\AI\Embeddings\Sync\Embedding_Sync;
use WordPress\AI\Embeddings\Sync\Embedding_Target;
use WordPress\AI\Embeddings\Sync\Object_Processor;
use WordPress\AI\Embeddings\Sync\Post_Source;
use WordPress\AI\Embeddings\Text_Chunker;
use WordPress\AI\Embeddings\Vector_Math;

use function WordPress\AI\normalize_content;

defined( 'ABSPATH' ) || exit;

/**
 * Embeds the post being edited and ranks stored term embeddings against it.
 *
 * @since x.x.x
 */
final class Embedding_Suggester {

	/**
	 * Default minimum cosine similarity for a term to be suggested.
	 *
	 * @since x.x.x
	 *
	 * @var float
	 */
	public const DEFAULT_MIN_SCORE = 0.3;

	/**
	 * Extra candidates fetched beyond the assigned terms.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	private const SEARCH_HEADROOM = 5;

	/**
	 * Embedding client.
	 *
	 * @var \WordPress\AI\Embeddings\Sync\Embedding_Client_Interface
	 */
	private Embedding_Client_Interface $client;

	/**
	 * Embeddings repository.
	 *
	 * @var \WordPress\AI\Embeddings\Embedding_Repository_Interface
	 */
	private Embedding_Repository_Interface $repository;

	/**
	 * Similarity search.
	 *
	 * @var \WordPress\AI\Embeddings\Similarity_Search
	 */
	private Similarity_Search $search;

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Embeddings\Sync\Embedding_Client_Interface|null $client     Optional. Client. Default the sync client.
	 * @param \WordPress\AI\Embeddings\Embedding_Repository_Interface|null  $repository Optional. Repository. Default the sync repository.
	 * @param \WordPress\AI\Embeddings\Similarity_Search|null              $search     Optional. Search. Default one over the repository.
	 */
	public function __construct( ?Embedding_Client_Interface $client = null, ?Embedding_Repository_Interface $repository = null, ?Similarity_Search $search = null ) {
		$this->client     = $client ?? Embedding_Sync::instance()->get_client();
		$this->repository = $repository ?? Embedding_Sync::instance()->get_repository();
		$this->search     = $search ?? new Similarity_Search( $this->repository );
	}

	/**
	 * Returns the existing terms closest to a post's content.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post|null $post            The post, or null to embed `$content` alone.
	 * @param string        $content         Editor content; empty uses the saved post content.
	 * @param string        $taxonomy        Taxonomy to suggest from.
	 * @param int           $max_suggestions Maximum number of suggestions.
	 * @param list<string>  $assigned_terms  Names already on the post.
	 * @param string        $provider        Embedding provider ID.
	 * @param string        $model           Embedding model ID.
	 * @return list<array{term: string, confidence: float, is_new: false, parent?: string}>|\WP_Error The suggestions, best first, or an error.
	 */
	public function suggest( ?WP_Post $post, string $content, string $taxonomy, int $max_suggestions, array $assigned_terms, string $provider, string $model ) {
		$vectors = $this->get_post_vectors( $post, $content, $provider, $model );

		if ( is_wp_error( $vectors ) ) {
			return $vectors;
		}

		try {
			$scores = $this->search->search(
				Vector_Math::centroid( $vectors ),
				$provider,
				$model,
				'term',
				array( $taxonomy ),
				$max_suggestions + count( $assigned_terms ) + self::SEARCH_HEADROOM
			);
		} catch ( InvalidArgumentException | RuntimeException $e ) {
			return self::failed( $e->getMessage() );
		}

		$assigned    = array_map( 'mb_strtolower', $assigned_terms );
		$min_score   = self::get_min_score( $provider, $model, $taxonomy );
		$suggestions = array();

		foreach ( $scores as $term_id => $score ) {
			if ( count( $suggestions ) >= $max_suggestions ) {
				break;
			}

			// Scores arrive best first, so nothing after this one clears the cutoff either.
			if ( $score < $min_score ) {
				break;
			}

			// The vector may outlive its term until sync catches up.
			$term = get_term( (int) $term_id, $taxonomy );

			if ( ! $term instanceof WP_Term || in_array( mb_strtolower( $term->name ), $assigned, true ) ) {
				continue;
			}

			$suggestion = array(
				'term'       => $term->name,
				'confidence' => max( 0.0, min( 1.0, (float) $score ) ),
				'is_new'     => false,
			);

			if ( $term->parent > 0 ) {
				$parent = get_term( $term->parent, $taxonomy );

				if ( $parent instanceof WP_Term ) {
					$suggestion['parent'] = $parent->name;
				}
			}

			$suggestions[] = $suggestion;
		}

		return $suggestions;
	}

	/**
	 * Returns the chunk vectors for a post, reusing stored ones when the text is unchanged.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post|null $post     The post, or null to embed `$content` alone.
	 * @param string        $content  Editor content; empty uses the saved post content.
	 * @param string        $provider Embedding provider ID.
	 * @param string        $model    Embedding model ID.
	 * @return list<list<float>>|\WP_Error Chunk vectors for the post (cached or freshly embedded).
	 */
	public function get_post_vectors( ?WP_Post $post, string $content, string $provider, string $model ) {
		$text = $post
			? ( new Post_Source() )->build_text( $post, '' !== $content ? $content : null )
			: trim( normalize_content( $content ) );

		$max_chunks = Object_Processor::max_chunks();
		$chunks     = array_slice( ( new Text_Chunker() )->chunk( $text ), 0, $max_chunks );

		if ( array() === $chunks ) {
			return new WP_Error(
				'content_not_provided',
				esc_html__( 'Content is required to generate suggestions.', 'ai' )
			);
		}

		$hash      = Object_Processor::hash_text( $text, $max_chunks, null );
		$cacheable = $post instanceof WP_Post && '' === $post->post_password;

		if ( $cacheable && $this->repository->get_content_hash( Post_Source::OBJECT_TYPE, $post->ID, $provider, $model ) === $hash ) {
			$stored = $this->get_complete_vectors(
				$this->repository->get( Post_Source::OBJECT_TYPE, $post->ID, $provider, $model ),
				count( $chunks ),
				$hash
			);

			if ( null !== $stored ) {
				return $stored;
			}
		}

		try {
			$vectors = array_values( $this->client->embed( new Embedding_Target( $provider, $model ), $chunks ) );
		} catch ( Embedding_Client_Exception $e ) {
			return self::failed( $e->get_raw_message() );
		}

		if ( count( $vectors ) !== count( $chunks ) ) {
			return self::failed( __( 'The embedding provider returned an unexpected number of vectors.', 'ai' ) );
		}

		if ( $cacheable ) {
			$this->store( $post, $provider, $model, $vectors, $hash );
		}

		return $vectors;
	}

	/**
	 * Returns the minimum similarity for a term to be suggested.
	 *
	 * @since x.x.x
	 *
	 * @param string $provider Embedding provider ID.
	 * @param string $model    Embedding model ID.
	 * @param string $taxonomy Taxonomy.
	 * @return float The minimum cosine similarity.
	 */
	public static function get_min_score( string $provider, string $model, string $taxonomy ): float {
		/**
		 * Filters the minimum cosine similarity for an embedding term suggestion.
		 *
		 * @since x.x.x
		 *
		 * @param float  $min_score Minimum similarity. Default 0.3.
		 * @param string $provider  Embedding provider ID.
		 * @param string $model     Embedding model ID.
		 * @param string $taxonomy  Taxonomy.
		 */
		return (float) apply_filters( 'wpai_content_classification_embedding_min_score', self::DEFAULT_MIN_SCORE, $provider, $model, $taxonomy );
	}

	/**
	 * Returns stored vectors only when they form one complete set for the current text.
	 *
	 * @since x.x.x
	 *
	 * @param list<\WordPress\AI\Embeddings\Embedding_Record> $records The stored records, in chunk order.
	 * @param int                                             $count   Expected number of chunks.
	 * @param string                                          $hash    Expected content hash.
	 * @return list<list<float>>|null The vectors, or null when the set is partial or stale.
	 */
	private function get_complete_vectors( array $records, int $count, string $hash ): ?array {
		if ( count( $records ) !== $count ) {
			return null;
		}

		$vectors = array();

		foreach ( array_values( $records ) as $i => $record ) {
			if ( $record->get_chunk_index() !== $i || $record->get_content_hash() !== $hash ) {
				return null;
			}

			$vectors[] = $record->get_vector();
		}

		return $vectors;
	}

	/**
	 * Saves a post's vectors the way sync does.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post          $post     The post.
	 * @param string            $provider Embedding provider ID.
	 * @param string            $model    Embedding model ID.
	 * @param list<list<float>> $vectors  One vector per chunk.
	 * @param string            $hash     Content hash.
	 */
	private function store( WP_Post $post, string $provider, string $model, array $vectors, string $hash ): void {
		$records = array();

		foreach ( $vectors as $chunk_index => $vector ) {
			$records[] = new Embedding_Record( Post_Source::OBJECT_TYPE, $post->ID, $provider, $model, $vector, $chunk_index, $hash, 0, $post->post_type );
		}

		try {
			$this->repository->replace_for_object( Post_Source::OBJECT_TYPE, $post->ID, $provider, $model, $records );
		} catch ( InvalidArgumentException | RuntimeException $e ) {
			wp_trigger_error( __METHOD__, esc_html( $e->getMessage() ) );
		}
	}

	/**
	 * Returns the error for a failed embedding request.
	 *
	 * @since x.x.x
	 *
	 * @param string $message The failure message.
	 * @return \WP_Error The error.
	 */
	private static function failed( string $message ): WP_Error {
		return new WP_Error( 'ai_embeddings_failed', esc_html( $message ) );
	}
}
