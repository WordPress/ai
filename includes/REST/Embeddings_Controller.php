<?php
/**
 * REST controller for embedding sync status and backfills.
 *
 * @package WordPress\AI\REST
 */

declare( strict_types=1 );

namespace WordPress\AI\REST;

use WordPress\AI\Embeddings\Sync\Embedding_Sync;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Handles the /ai/v1/embeddings/consumers REST endpoints.
 *
 * Reports sync status for any registered embedding consumer and starts or
 * cancels its backfill.
 *
 * @since x.x.x
 */
final class Embeddings_Controller {

	/**
	 * The REST API namespace.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const API_NAMESPACE = 'ai/v1';

	/**
	 * The consumer status route.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const ROUTE = '/embeddings/consumers/(?P<consumer>[a-z0-9_-]+)';

	/**
	 * Initializes the REST routes.
	 *
	 * @since x.x.x
	 */
	public function init(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the REST routes.
	 *
	 * @since x.x.x
	 */
	public function register_routes(): void {
		register_rest_route(
			self::API_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			self::ROUTE . '/backfill',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'start_backfill' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'cancel_backfill' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
	}

	/**
	 * Checks whether the current user can access these endpoints.
	 *
	 * @since x.x.x
	 *
	 * @return bool True if the user has permission.
	 */
	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Returns the sync status for a consumer.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response|\WP_Error The response.
	 */
	public function get_status( \WP_REST_Request $request ) {
		return $this->status_response( (string) $request->get_param( 'consumer' ) );
	}

	/**
	 * Starts (or resumes) a consumer's backfill and returns the fresh status.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response|\WP_Error The response.
	 */
	public function start_backfill( \WP_REST_Request $request ) {
		$consumer_id = (string) $request->get_param( 'consumer' );

		Embedding_Sync::start_backfill( $consumer_id );

		return $this->status_response( $consumer_id );
	}

	/**
	 * Cancels a consumer's running backfill and returns the fresh status.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response|\WP_Error The response.
	 */
	public function cancel_backfill( \WP_REST_Request $request ) {
		$consumer_id = (string) $request->get_param( 'consumer' );

		// False also means nothing was running, so the status is returned either way.
		Embedding_Sync::cancel_backfill( $consumer_id );

		return $this->status_response( $consumer_id );
	}

	/**
	 * Builds the status response, adding totals and the WP-Cron flag.
	 *
	 * @since x.x.x
	 *
	 * @param string $consumer_id Consumer ID.
	 * @return \WP_REST_Response|\WP_Error The response, or a 404 error for an unknown consumer.
	 */
	private function status_response( string $consumer_id ) {
		$status = Embedding_Sync::get_status( $consumer_id );

		if ( null === $status ) {
			return new \WP_Error(
				'ai_embeddings_unknown_consumer',
				__( 'Unknown embedding consumer.', 'ai' ),
				array( 'status' => 404 )
			);
		}

		$totals = array(
			'indexed'   => 0,
			'indexable' => 0,
		);

		foreach ( $status['coverage'] as $subtypes ) {
			foreach ( $subtypes as $counts ) {
				$totals['indexed']   += $counts['indexed'];
				$totals['indexable'] += $counts['indexable'];
			}
		}

		$status['totals']        = $totals;
		$status['cron_disabled'] = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

		return new \WP_REST_Response( $status, 200 );
	}
}
