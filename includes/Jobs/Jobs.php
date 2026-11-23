<?php
/**
 * Async job service: registry, queue, REST route and ability.
 *
 * @package WordPress\AI\Jobs
 */

declare( strict_types=1 );

namespace WordPress\AI\Jobs;

use WordPress\AI\Jobs\Job_Types\Ability_Job_Type;
use WordPress\AI\Jobs\REST\Jobs_Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Boots the async job subsystem.
 *
 * Registered job types are shared by the queue, the REST route and the
 * `ai/enqueue-job` ability. Built-in types are available immediately; other
 * plugins add theirs on the `wpai_register_job_types` action.
 *
 * @since 1.6.0
 */
final class Jobs {

	/**
	 * Shared instance.
	 *
	 * @since 1.6.0
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Job type registry.
	 *
	 * @var \WordPress\AI\Jobs\Job_Registry
	 */
	private Job_Registry $registry;

	/**
	 * Job store.
	 *
	 * @var \WordPress\AI\Jobs\Job_Store
	 */
	private Job_Store $store;

	/**
	 * Job queue.
	 *
	 * @var \WordPress\AI\Jobs\Job_Queue
	 */
	private Job_Queue $queue;

	/**
	 * Whether hooks have been registered.
	 *
	 * @var bool
	 */
	private bool $initialized = false;

	/**
	 * Constructor.
	 *
	 * @since 1.6.0
	 *
	 * @param \WordPress\AI\Jobs\Job_Registry|null $registry Optional. Registry. Default a new instance.
	 * @param \WordPress\AI\Jobs\Job_Store|null    $store    Optional. Store. Default a new instance.
	 */
	public function __construct( ?Job_Registry $registry = null, ?Job_Store $store = null ) {
		$this->registry = $registry ?? new Job_Registry();
		$this->store    = $store ?? new Job_Store();
		$this->queue    = new Job_Queue( $this->registry, $this->store );

		// The generic ability job is always available.
		$this->registry->register( new Ability_Job_Type() );
	}

	/**
	 * Returns the shared instance.
	 *
	 * @since 1.6.0
	 *
	 * @return self The instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers hooks.
	 *
	 * @since 1.6.0
	 */
	public function init(): void {
		if ( $this->initialized ) {
			return;
		}

		add_action( 'init', array( $this, 'register_types' ), 5 );
		add_action( Job_Queue::RUN_HOOK, array( $this->queue, 'run' ) );
		add_action( Job_Queue::CLEANUP_HOOK, array( $this, 'cleanup' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_ability' ) );

		if ( ! wp_next_scheduled( Job_Queue::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Job_Queue::CLEANUP_HOOK );
		}

		$this->initialized = true;
	}

	/**
	 * Returns the registry.
	 *
	 * @since 1.6.0
	 *
	 * @return \WordPress\AI\Jobs\Job_Registry The registry.
	 */
	public function get_registry(): Job_Registry {
		return $this->registry;
	}

	/**
	 * Returns the store.
	 *
	 * @since 1.6.0
	 *
	 * @return \WordPress\AI\Jobs\Job_Store The store.
	 */
	public function get_store(): Job_Store {
		return $this->store;
	}

	/**
	 * Returns the queue.
	 *
	 * @since 1.6.0
	 *
	 * @return \WordPress\AI\Jobs\Job_Queue The queue.
	 */
	public function get_queue(): Job_Queue {
		return $this->queue;
	}

	/**
	 * Lets other plugins register job types.
	 *
	 * @since 1.6.0
	 */
	public function register_types(): void {
		/**
		 * Fires so plugins can register async job types.
		 *
		 * @since 1.6.0
		 *
		 * @param \WordPress\AI\Jobs\Job_Registry $registry The job type registry.
		 */
		do_action( 'wpai_register_job_types', $this->registry );
	}

	/**
	 * Registers the REST route.
	 *
	 * @since 1.6.0
	 */
	public function register_rest_routes(): void {
		( new Jobs_Controller( $this->queue, $this->store ) )->register_routes();
	}

	/**
	 * Registers the `ai/enqueue-job` ability.
	 *
	 * @since 1.6.0
	 */
	public function register_ability(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'ai/enqueue-job',
			array(
				'label'               => __( 'Enqueue AI Job', 'ai' ),
				'description'         => __( 'Queues a registered AI job and returns immediately with a job ID to poll.', 'ai' ),
				'category'            => WPAI_DEFAULT_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'type' ),
					'properties' => array(
						'type'            => array(
							'type'        => 'string',
							'description' => __( 'Registered job type, such as ai/ability.', 'ai' ),
						),
						'args'            => array(
							'type'        => 'object',
							'description' => __( 'Arguments for the job type.', 'ai' ),
							'default'     => array(),
						),
						'idempotency_key' => array(
							'type'        => 'string',
							'description' => __( 'Optional key that makes repeated requests return the same job.', 'ai' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'job_id'     => array( 'type' => 'string' ),
						'status'     => array( 'type' => 'string' ),
						'status_url' => array( 'type' => 'string' ),
					),
				),
				'permission_callback' => static function () {
					if ( ! is_user_logged_in() ) {
						return new \WP_Error( 'wpai_not_logged_in', __( 'You must be logged in to enqueue a job.', 'ai' ) );
					}

					return true;
				},
				'execute_callback'    => static function ( $input ) {
					$input = is_array( $input ) ? $input : array();
					$type  = isset( $input['type'] ) && is_string( $input['type'] ) ? $input['type'] : '';
					$args  = isset( $input['args'] ) && is_array( $input['args'] ) ? $input['args'] : array();
					$key   = isset( $input['idempotency_key'] ) && is_string( $input['idempotency_key'] ) ? $input['idempotency_key'] : '';

					$job_id = self::instance()->get_queue()->enqueue(
						$type,
						$args,
						array(
							'user_id'         => get_current_user_id(),
							'idempotency_key' => $key,
						)
					);

					if ( is_wp_error( $job_id ) ) {
						return $job_id;
					}

					return array(
						'job_id'     => $job_id,
						'status'     => Job_Status::ENQUEUED,
						'status_url' => rest_url( 'ai/v1/jobs/' . rawurlencode( $job_id ) ),
					);
				},
				'meta'                => array(
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Deletes expired jobs.
	 *
	 * @since 1.6.0
	 */
	public function cleanup(): void {
		$this->store->delete_expired();
	}
}
