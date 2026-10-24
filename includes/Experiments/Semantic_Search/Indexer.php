<?php
/**
 * Orchestrates embedding generation and storage for a batch of posts.
 *
 * @package WordPress\AI\Experiments\Semantic_Search
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Semantic_Search;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Generates and stores embeddings for individual posts and batches.
 *
 * Coordinates between Embedding_Generator (which calls the PHP AI Client) and
 * Embedding_Store (which persists the resulting vectors). On the first API
 * error within a batch, indexing stops immediately rather than continuing
 * through a broken connector or network connection.
 *
 * @internal
 * @since x.x.x
 */
class Indexer {

	/**
	 * Embedding generator instance used to generate vectors.
	 *
	 * @since x.x.x
	 * @var \WordPress\AI\Experiments\Semantic_Search\Embedding_Generator
	 */
	private Embedding_Generator $api;

	/**
	 * Embedding store instance used to persist vectors.
	 *
	 * @since x.x.x
	 * @var \WordPress\AI\Experiments\Semantic_Search\Embedding_Store
	 */
	private Embedding_Store $store;

	/**
	 * Initialises the API and store dependencies.
	 *
	 * @since x.x.x
	 */
	public function __construct() {
		$this->api   = new Embedding_Generator();
		$this->store = new Embedding_Store();
	}

	/**
	 * Generates and persists an embedding for a single post.
	 *
	 * Builds the post text from title + stripped content, requests an embedding
	 * from the configured provider, and writes the result to post meta via the
	 * embedding store. Returns false if the post does not exist or the API call fails.
	 *
	 * @since x.x.x
	 *
	 * @param int $post_id Post ID to index.
	 * @return bool True on success, false if the post is missing or the API fails.
	 */
	public function index_post( int $post_id ): bool {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return false;
		}

		$text      = $this->get_post_text( $post );
		$embedding = $this->api->generate( $text );

		if ( null === $embedding ) {
			return false;
		}

		$this->store->save( $post_id, $embedding, $this->api->get_model() );

		return true;
	}

	/**
	 * Indexes a batch of posts, stopping on the first API error.
	 *
	 * Iterates over the supplied post IDs and calls index_post() for each.
	 * If any call fails, the loop breaks immediately and the error string from
	 * Embedding_Generator::get_last_error() is included in the return value so
	 * the caller can surface it to the user instead of silently continuing.
	 *
	 * @since x.x.x
	 *
	 * @param int[] $ids Post IDs to index.
	 * @return array{success:int, failed:int, error:string} Counts and the first error message, if any.
	 */
	public function index_batch( array $ids ): array {
		$success = 0;
		$failed  = 0;
		$error   = '';

		foreach ( $ids as $id ) {
			if ( ! $this->index_post( $id ) ) {
				++$failed;
				$error = $this->api->get_last_error();
				break;
			}

			++$success;
		}

		return compact( 'success', 'failed', 'error' );
	}

	/**
	 * Builds the text string to embed for a post.
	 *
	 * Combines the post title and stripped post content, separated by two
	 * newlines, so that the embedding captures both the title semantics and
	 * the body content.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post $post Post object to extract text from.
	 * @return string Concatenated title and stripped content.
	 */
	private function get_post_text( \WP_Post $post ): string {
		return trim( $post->post_title . "\n\n" . wp_strip_all_tags( $post->post_content ) );
	}
}
