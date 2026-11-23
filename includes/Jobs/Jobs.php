<?php
/**
 * Async job service: registry, queue and worker.
 *
 * @package WordPress\AI\Jobs
 */

declare( strict_types=1 );

namespace WordPress\AI\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Boots the async job subsystem.
 *
 * Registered job types are shared by the queue. Other plugins add theirs on
 * the `wpai_register_job_types` action.
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
	 * Deletes expired jobs.
	 *
	 * @since 1.6.0
	 */
	public function cleanup(): void {
		$this->store->delete_expired();
	}
}
