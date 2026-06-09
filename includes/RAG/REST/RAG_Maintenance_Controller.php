<?php
/**
 * REST controller for RAG status and maintenance.
 *
 * @package WordPress\AI\RAG\REST
 */

declare( strict_types=1 );

namespace WordPress\AI\RAG\REST;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WordPress\AI\RAG\Availability;
use WordPress\AI\RAG\Index_Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Handles RAG maintenance routes.
 *
 * @since 1.1.0
 */
class RAG_Maintenance_Controller {
	/**
	 * API namespace.
	 */
	private const API_NAMESPACE = 'ai/v1';

	/**
	 * Availability service.
	 *
	 * @var \WordPress\AI\RAG\Availability
	 */
	private Availability $availability;

	/**
	 * Index manager.
	 *
	 * @var \WordPress\AI\RAG\Index_Manager|null
	 */
	private ?Index_Manager $index_manager;

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 *
	 * @param \WordPress\AI\RAG\Availability|null  $availability  Availability service.
	 * @param \WordPress\AI\RAG\Index_Manager|null $index_manager Index manager.
	 */
	public function __construct( ?Availability $availability = null, ?Index_Manager $index_manager = null ) {
		$this->availability  = $availability ?? new Availability();
		$this->index_manager = $index_manager;
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
			'/rag/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/rag/reindex',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'reindex' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/rag/index',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_index' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Checks endpoint permissions.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True when current user may manage RAG maintenance.
	 */
	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Returns RAG status.
	 *
	 * @since 1.1.0
	 *
	 * @return \WP_REST_Response Response.
	 */
	public function get_status(): WP_REST_Response {
		return new WP_REST_Response( $this->build_status(), 200 );
	}

	/**
	 * Marks eligible posts dirty and schedules indexing.
	 *
	 * @since 1.1.0
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error Response or error.
	 */
	public function reindex( WP_REST_Request $request ) {
		unset( $request );

		if ( ! $this->availability->is_available() ) {
			return new WP_Error(
				'wpai_rag_unavailable',
				$this->availability->get_unavailable_reason(),
				array( 'status' => 503 )
			);
		}

		$index_manager = $this->get_index_manager();

		if ( ! $index_manager->ensure_index_storage() ) {
			return new WP_Error(
				'wpai_rag_storage_unavailable',
				__( 'The RAG index storage could not be prepared.', 'ai' ),
				array( 'status' => 500 )
			);
		}

		$stats = $index_manager->mark_posts_dirty_for_indexing();
		$index_manager->schedule_indexing();

		return new WP_REST_Response(
			array_merge(
				$this->build_status(),
				array( 'marking' => $stats )
			),
			200
		);
	}

	/**
	 * Deletes RAG index data.
	 *
	 * @since 1.1.0
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response Response.
	 */
	public function delete_index( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$this->get_index_manager()->cleanup_index_data();

		return new WP_REST_Response( $this->build_status(), 200 );
	}

	/**
	 * Builds a status payload.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string, mixed> Status.
	 */
	private function build_status(): array {
		$available = $this->availability->is_available();
		$manager   = $this->get_index_manager();

		return array(
			'available'            => $available,
			'unavailable_reason'   => $available ? '' : $this->availability->get_unavailable_reason(),
			'backend'              => $this->availability->get_index_backend(),
			'backend_label'        => $this->availability->get_index_backend_label(),
			'available_backends'   => $this->availability->get_available_index_backends(),
			'backend_labels'       => $this->availability->get_index_backend_labels(),
			'storage_ready'        => $available && $manager->ensure_index_storage(),
			'has_index_data'       => $manager->has_index_data(),
			'counts'               => $manager->get_status_counts(),
			'next_scheduled_run'   => $manager->get_next_scheduled_indexing(),
			'embedding_model'      => $this->availability->get_embedding_model(),
			'embedding_dimensions' => $this->availability->get_embedding_dimensions(),
		);
	}

	/**
	 * Returns the index manager.
	 *
	 * @since 1.1.0
	 *
	 * @return \WordPress\AI\RAG\Index_Manager Index manager.
	 */
	private function get_index_manager(): Index_Manager {
		if ( $this->index_manager instanceof Index_Manager ) {
			return $this->index_manager;
		}

		$this->index_manager = new Index_Manager( $this->availability );

		return $this->index_manager;
	}
}
