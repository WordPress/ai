<?php
/**
 * Turns content changes into sync work.
 *
 * @package WordPress\AI\Embeddings\Sync
 */

declare( strict_types=1 );

namespace WordPress\AI\Embeddings\Sync;

use RuntimeException;
use WordPress\AI\Embeddings\Embedding_Repository_Interface;

defined( 'ABSPATH' ) || exit;

/**
 * Decides, inside the request that changed an object,
 * whether to delete its vectors now or queue it.
 *
 * @since x.x.x
 */
class Change_Listener {

	/**
	 * Consumer registry.
	 *
	 * @var \WordPress\AI\Embeddings\Sync\Consumer_Registry
	 */
	private Consumer_Registry $registry;

	/**
	 * Sources keyed by object type.
	 *
	 * @var array<string, \WordPress\AI\Embeddings\Sync\Embedding_Source_Interface>
	 */
	private array $sources;

	/**
	 * Queue.
	 *
	 * @var \WordPress\AI\Embeddings\Sync\Sync_Queue
	 */
	private Sync_Queue $queue;

	/**
	 * Embeddings repository.
	 *
	 * @var \WordPress\AI\Embeddings\Embedding_Repository_Interface
	 */
	private Embedding_Repository_Interface $repository;

	/**
	 * Site this listener was built for.
	 *
	 * @var int
	 */
	private int $blog_id;

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Embeddings\Sync\Consumer_Registry                              $registry   Consumer registry.
	 * @param array<string, \WordPress\AI\Embeddings\Sync\Embedding_Source_Interface>      $sources    Sources keyed by object type.
	 * @param \WordPress\AI\Embeddings\Sync\Sync_Queue                                     $queue      Queue.
	 * @param \WordPress\AI\Embeddings\Embedding_Repository_Interface                      $repository Embeddings repository.
	 */
	public function __construct( Consumer_Registry $registry, array $sources, Sync_Queue $queue, Embedding_Repository_Interface $repository ) {
		$this->registry   = $registry;
		$this->sources    = $sources;
		$this->queue      = $queue;
		$this->repository = $repository;
		$this->blog_id    = get_current_blog_id();
	}

	/**
	 * Handles a created or updated object.
	 *
	 * @since x.x.x
	 *
	 * @param string      $object_type      Object type.
	 * @param int         $object_id        Object ID.
	 * @param string|null $previous_subtype Optional. The subtype before this change, when known. Default null.
	 */
	public function object_changed( string $object_type, int $object_id, ?string $previous_subtype = null ): void {
		if ( $this->is_switched_away() ) {
			return;
		}

		$source = $this->sources[ $object_type ] ?? null;

		if ( null === $source ) {
			return;
		}

		$subtype = $source->get_subtype( $object_id );

		if ( null === $subtype || ! $this->registry->covers( $object_type, $subtype ) ) {
			// Moving out of a covered subtype strands the vectors filed under the old one.
			if ( null !== $previous_subtype && $this->registry->covers( $object_type, $previous_subtype ) ) {
				$this->remove( $object_type, $object_id );
			}

			return;
		}

		if ( ! $source->is_indexable( $object_id ) ) {
			$this->remove( $object_type, $object_id );
			return;
		}

		/**
		 * Filters whether to skip queueing a changed object.
		 *
		 * @since x.x.x
		 *
		 * @param bool   $skip        Whether to skip. Default false.
		 * @param string $object_type Object type.
		 * @param int    $object_id   Object ID.
		 */
		if ( (bool) apply_filters( 'wpai_embedding_sync_skip_enqueue', false, $object_type, $object_id ) ) {
			return;
		}

		$this->enqueue( $object_type, $object_id );
	}

	/**
	 * Handles a deleted object, whatever its subtype.
	 *
	 * @since x.x.x
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 */
	public function object_deleted( string $object_type, int $object_id ): void {
		if ( $this->is_switched_away() ) {
			return;
		}

		$this->remove( $object_type, $object_id );
	}

	/**
	 * Checks whether the request is switched to a site other than the listener's own.
	 *
	 * @since x.x.x
	 *
	 * @return bool True when the current site differs from the one the listener was built for.
	 */
	private function is_switched_away(): bool {
		return get_current_blog_id() !== $this->blog_id;
	}

	/**
	 * Queues an object and wakes the worker.
	 *
	 * @since x.x.x
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 */
	private function enqueue( string $object_type, int $object_id ): void {
		try {
			$this->queue->enqueue( $object_type, $object_id );
		} catch ( RuntimeException $e ) {
			wp_trigger_error( __METHOD__, esc_html( $e->getMessage() ), E_USER_WARNING );
			return;
		}

		Sync_Worker::wake();
	}

	/**
	 * Deletes an object's vectors for every model and drops its queue row.
	 *
	 * @since x.x.x
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 */
	private function remove( string $object_type, int $object_id ): void {
		try {
			$this->repository->delete_for_object( $object_type, $object_id );
			$this->queue->remove_object( $object_type, $object_id );
		} catch ( RuntimeException $e ) {
			wp_trigger_error( __METHOD__, esc_html( $e->getMessage() ), E_USER_WARNING );
			$this->enqueue( $object_type, $object_id );
		}
	}
}
