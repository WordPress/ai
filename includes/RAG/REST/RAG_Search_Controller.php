<?php
/**
 * REST controller for RAG semantic search.
 *
 * @package WordPress\AI\RAG\REST
 */

declare( strict_types=1 );

namespace WordPress\AI\RAG\REST;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WordPress\AI\RAG\Availability;
use WordPress\AI\RAG\Search_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Handles GET /ai/v1/rag/search.
 *
 * @since 1.1.0
 */
class RAG_Search_Controller {
	/**
	 * API namespace.
	 */
	private const API_NAMESPACE = 'ai/v1';

	/**
	 * API route.
	 */
	private const ROUTE = '/rag/search';

	/**
	 * Availability service.
	 *
	 * @var \WordPress\AI\RAG\Availability
	 */
	private Availability $availability;

	/**
	 * Search service.
	 *
	 * @var \WordPress\AI\RAG\Search_Service
	 */
	private Search_Service $search_service;

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 *
	 * @param \WordPress\AI\RAG\Availability|null    $availability   Availability service.
	 * @param \WordPress\AI\RAG\Search_Service|null $search_service Search service.
	 */
	public function __construct( ?Availability $availability = null, ?Search_Service $search_service = null ) {
		$this->availability   = $availability ?? new Availability();
		$this->search_service = $search_service ?? new Search_Service( $this->availability );
	}

	/**
	 * Initializes routes.
	 *
	 * @since 1.1.0
	 */
	public function init(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers routes.
	 *
	 * @since 1.1.0
	 */
	public function register_routes(): void {
		register_rest_route(
			self::API_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'search' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'q'           => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'per_page'    => array(
						'type'              => 'integer',
						'default'           => 10,
						'minimum'           => 1,
						'maximum'           => 20,
						'sanitize_callback' => 'absint',
					),
					'post_type'   => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'post_status' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Checks endpoint permissions.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True when current user may search.
	 */
	public function check_permission(): bool {
		/**
		 * Filters whether the current request may use the RAG search REST endpoint.
		 *
		 * @since 1.1.0
		 *
		 * @param bool $allowed Whether access is allowed.
		 */
		return (bool) apply_filters( 'wpai_rag_search_rest_permission', current_user_can( 'read' ) );
	}

	/**
	 * Runs semantic search.
	 *
	 * @since 1.1.0
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error Response or error.
	 */
	public function search( WP_REST_Request $request ) {
		if ( ! $this->availability->is_available() ) {
			return new WP_Error(
				'wpai_rag_unavailable',
				$this->availability->get_unavailable_reason(),
				array( 'status' => 503 )
			);
		}

		$results = $this->search_service->search(
			(string) $request->get_param( 'q' ),
			array(
				'per_page'    => (int) $request->get_param( 'per_page' ),
				'post_type'   => (string) $request->get_param( 'post_type' ),
				'post_status' => (string) $request->get_param( 'post_status' ),
			)
		);

		if ( is_wp_error( $results ) ) {
			return $results;
		}

		return new WP_REST_Response( $results, 200 );
	}
}
