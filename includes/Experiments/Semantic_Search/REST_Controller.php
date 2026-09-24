<?php
/**
 * REST endpoints for semantic search and content indexing.
 *
 * Routes:
 *   GET  /ai/v1/semantic-search               – consumed by the command palette JS
 *   POST /ai/v1/semantic-search/index         – index next batch (admin only)
 *   GET  /ai/v1/semantic-search/index/status  – indexing progress (admin only)
 *   GET  /ai/v1/semantic-search/index/test    – test API connectivity (admin only)
 *
 * @package WordPress\AI\Experiments\Semantic_Search
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Semantic_Search;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Registers and handles the semantic search REST API routes.
 *
 * All routes are registered under the `ai/v1` namespace to align with the
 * existing REST API surface of the AI plugin. Search requires `edit_posts`
 * capability; indexing operations require `manage_options`.
 *
 * @internal
 * @since x.x.x
 */
class REST_Controller {

	/**
	 * Registers all four REST routes for this experiment.
	 *
	 * Called on the rest_api_init action via Semantic_Search::register_rest_routes().
	 *
	 * @since x.x.x
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			'ai/v1',
			'/semantic-search',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_search' ),
				'permission_callback' => static fn() => current_user_can( 'edit_posts' ),
				'args'                => array(
					'q' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			'ai/v1',
			'/semantic-search/index',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_index_batch' ),
				'permission_callback' => static fn() => current_user_can( 'manage_options' ),
			)
		);

		register_rest_route(
			'ai/v1',
			'/semantic-search/index/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_index_status' ),
				'permission_callback' => static fn() => current_user_can( 'manage_options' ),
			)
		);

		register_rest_route(
			'ai/v1',
			'/semantic-search/index/test',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_test_connection' ),
				'permission_callback' => static fn() => current_user_can( 'manage_options' ),
			)
		);
	}

	/**
	 * Handles GET /ai/v1/semantic-search.
	 *
	 * Runs a semantic search for the `q` parameter and returns a ranked list of
	 * posts. Returns `available: false` when the embedding provider is not
	 * configured so the command palette JS can gracefully suppress the loader.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return \WP_REST_Response Response containing `available` flag and `results` array.
	 */
	public function handle_search( \WP_REST_Request $request ): \WP_REST_Response {
		$query  = $request->get_param( 'q' );
		$search = new Vector_Search();

		if ( ! $search->is_available() ) {
			return new \WP_REST_Response(
				array(
					'available' => false,
					'results'   => array(),
				),
				200
			);
		}

		$results = $search->search( $query, array( 'limit' => 10 ) );

		return new \WP_REST_Response(
			array(
				'available' => true,
				'results'   => $results,
			),
			200
		);
	}

	/**
	 * Handles POST /ai/v1/semantic-search/index.
	 *
	 * Retrieves the next batch of up to 5 unindexed posts and generates their
	 * embeddings. Returns progress counters (`indexed`, `total`) and a `done`
	 * flag so the JS loop on the Index_Page knows when to stop. If all attempts
	 * in the batch fail and an error string is present, `done` is forced to true
	 * to prevent the client from retrying with a broken API key or network.
	 *
	 * @since x.x.x
	 *
	 * @return \WP_REST_Response Response containing success/failed counts, progress stats, and done flag.
	 */
	public function handle_index_batch(): \WP_REST_Response {
		$store   = new Embedding_Store();
		$indexer = new Indexer();

		$ids    = $store->get_unindexed_ids( array( 'post', 'page' ), 5 );
		$result = $indexer->index_batch( $ids );
		$stats  = $store->get_stats();

		$payload = array_merge(
			$result,
			array(
				'indexed' => $stats['indexed'],
				'total'   => $stats['total'],
				'done'    => empty( $store->get_unindexed_ids( array( 'post', 'page' ), 1 ) ),
			)
		);

		if ( 0 === $result['success'] && '' !== $result['error'] ) {
			$payload['done'] = true;
		}

		return new \WP_REST_Response( $payload, 200 );
	}

	/**
	 * Handles GET /ai/v1/semantic-search/index/status.
	 *
	 * Returns the current indexed/total post counts without triggering any
	 * indexing work.
	 *
	 * @since x.x.x
	 *
	 * @return \WP_REST_Response Response containing `total` and `indexed` integer counts.
	 */
	public function handle_index_status(): \WP_REST_Response {
		return new \WP_REST_Response( ( new Embedding_Store() )->get_stats(), 200 );
	}

	/**
	 * Handles GET /ai/v1/semantic-search/index/test.
	 *
	 * Generates an embedding for the string "test" through the configured
	 * connector and returns the model preference and vector dimensions on
	 * success. On failure, returns the error reported by the AI Client.
	 *
	 * @since x.x.x
	 *
	 * @return \WP_REST_Response Response containing `ok`, and either `model`/`dimensions` or `error`.
	 */
	public function handle_test_connection(): \WP_REST_Response {
		$generator = new Embedding_Generator();

		if ( ! $generator->is_available() ) {
			return new \WP_REST_Response(
				array(
					'ok'    => false,
					'error' => __( 'No connector with embedding support is configured.', 'ai' ),
				),
				200
			);
		}

		$embedding = $generator->generate( 'test' );

		if ( null === $embedding ) {
			return new \WP_REST_Response(
				array(
					'ok'    => false,
					'error' => $generator->get_last_error(),
				),
				200
			);
		}

		return new \WP_REST_Response(
			array(
				'ok'         => true,
				'model'      => $generator->get_model(),
				'dimensions' => count( $embedding ),
			),
			200
		);
	}
}
