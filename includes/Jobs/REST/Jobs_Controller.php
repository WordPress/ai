<?php
/**
 * REST routes for async AI jobs.
 *
 * @package WordPress\AI\Jobs\REST
 */

declare( strict_types=1 );

namespace WordPress\AI\Jobs\REST;

use WP_Error;
use WP_REST_Request;
use WP_REST_Server;
use WordPress\AI\Jobs\Job;
use WordPress\AI\Jobs\Job_Queue;
use WordPress\AI\Jobs\Job_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes the async job queue over REST.
 *
 * `POST /ai/v1/jobs` stores and schedules a job and answers `202 Accepted`
 * immediately. Clients then poll `GET /ai/v1/jobs/{job_id}` until the status
 * is `completed` or `failed`.
 *
 * @since 1.6.0
 */
class Jobs_Controller {

	/**
	 * REST namespace.
	 *
	 * @since 1.6.0
	 *
	 * @var string
	 */
	public const REST_NAMESPACE = 'ai/v1';

	/**
	 * REST route base.
	 *
	 * @since 1.6.0
	 *
	 * @var string
	 */
	public const REST_BASE = '/jobs';

	/**
	 * Job queue.
	 *
	 * @var \WordPress\AI\Jobs\Job_Queue
	 */
	private Job_Queue $queue;

	/**
	 * Job store.
	 *
	 * @var \WordPress\AI\Jobs\Job_Store
	 */
	private Job_Store $store;

	/**
	 * Constructor.
	 *
	 * @since 1.6.0
	 *
	 * @param \WordPress\AI\Jobs\Job_Queue $queue Job queue.
	 * @param \WordPress\AI\Jobs\Job_Store $store Job store.
	 */
	public function __construct( Job_Queue $queue, Job_Store $store ) {
		$this->queue = $queue;
		$this->store = $store;
	}

	/**
	 * Registers the routes.
	 *
	 * @since 1.6.0
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_BASE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_job' ),
				'permission_callback' => array( $this, 'can_enqueue' ),
				'args'                => $this->get_create_args(),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_BASE . '/(?P<job_id>[a-zA-Z0-9_-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_job' ),
				'permission_callback' => array( $this, 'can_view' ),
				'args'                => array(
					'job_id' => array(
						'description'       => esc_html__( 'The job ID returned when the job was enqueued.', 'ai' ),
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Returns the create route arguments.
	 *
	 * @since 1.6.0
	 *
	 * @return array<string, array<string, mixed>> The arguments.
	 */
	private function get_create_args(): array {
		return array(
			'type'            => array(
				'description'       => esc_html__( 'Registered job type, such as ai/ability.', 'ai' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'args'            => array(
				'description' => esc_html__( 'Arguments for the job type.', 'ai' ),
				'type'        => 'object',
				'default'     => array(),
			),
			'idempotency_key' => array(
				'description'       => esc_html__( 'Optional key that makes repeated requests return the same job.', 'ai' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * Checks whether the current user can enqueue a job.
	 *
	 * The job type re-checks its own permission before the job runs.
	 *
	 * @since 1.6.0
	 *
	 * @return true|\WP_Error True when signed in.
	 */
	public function can_enqueue() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_forbidden',
				esc_html__( 'You must be logged in to enqueue a job.', 'ai' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Enqueues a job and answers 202 Accepted.
	 *
	 * @since 1.6.0
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error The response.
	 */
	public function create_job( WP_REST_Request $request ) {
		$idempotency_key = (string) $request->get_param( 'idempotency_key' );

		if ( '' === $idempotency_key ) {
			$header = $request->get_header( 'X-WP-AI-Idempotency-Key' );

			if ( is_string( $header ) ) {
				$idempotency_key = sanitize_text_field( $header );
			}
		}

		$args = $request->get_param( 'args' );

		$job_id = $this->queue->enqueue(
			(string) $request->get_param( 'type' ),
			is_array( $args ) ? $args : array(),
			array(
				'user_id'         => get_current_user_id(),
				'idempotency_key' => $idempotency_key,
			)
		);

		if ( is_wp_error( $job_id ) ) {
			return $job_id;
		}

		$job = $this->store->find( $job_id );

		if ( null === $job ) {
			return new WP_Error(
				'wpai_job_not_found',
				esc_html__( 'The job was enqueued but could not be read back.', 'ai' ),
				array( 'status' => 500 )
			);
		}

		$response = rest_ensure_response( $this->prepare_job( $job ) );
		$response->set_status( 202 );
		$response->header( 'Location', rest_url( self::REST_NAMESPACE . self::REST_BASE . '/' . rawurlencode( $job->get_job_id() ) ) );

		return $response;
	}

	/**
	 * Returns a job's status and, once finished, its result.
	 *
	 * @since 1.6.0
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error The response.
	 */
	public function get_job( WP_REST_Request $request ) {
		$job = $this->store->find( (string) $request->get_param( 'job_id' ) );

		if ( null === $job ) {
			return new WP_Error(
				'wpai_job_not_found',
				esc_html__( 'No job exists with that ID.', 'ai' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response( $this->prepare_job( $job ) );
	}

	/**
	 * Checks whether the current user can view a job.
	 *
	 * @since 1.6.0
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return true|\WP_Error True when the user owns the job or can manage the site.
	 */
	public function can_view( WP_REST_Request $request ) {
		$job = $this->store->find( (string) $request->get_param( 'job_id' ) );

		if ( null === $job ) {
			return new WP_Error(
				'wpai_job_not_found',
				esc_html__( 'No job exists with that ID.', 'ai' ),
				array( 'status' => 404 )
			);
		}

		if ( $job->get_user_id() === get_current_user_id() || current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			esc_html__( 'You do not have permission to view this job.', 'ai' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Builds the response payload for a job.
	 *
	 * @since 1.6.0
	 *
	 * @param \WordPress\AI\Jobs\Job $job The job.
	 * @return array<string, mixed> The payload.
	 */
	private function prepare_job( Job $job ): array {
		$data               = $job->to_array();
		$data['status_url'] = rest_url( self::REST_NAMESPACE . self::REST_BASE . '/' . rawurlencode( $job->get_job_id() ) );

		return $data;
	}
}
