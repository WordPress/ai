<?php
/**
 * Removes stored vectors that no longer belong to the index.
 *
 * @package WordPress\AI\Embeddings\Sync
 */

declare( strict_types=1 );

namespace WordPress\AI\Embeddings\Sync;

use Throwable;
use WordPress\AI\Embeddings\Embedding_Repository_Interface;

defined( 'ABSPATH' ) || exit;

/**
 * Walks a target's stored objects and deletes vectors that should not exist.
 *
 * @since x.x.x
 */
class Orphan_Sweeper {

	/**
	 * Sources keyed by object type.
	 *
	 * @var array<string, \WordPress\AI\Embeddings\Sync\Embedding_Source_Interface>
	 */
	private array $sources;

	/**
	 * Embeddings repository.
	 *
	 * @var \WordPress\AI\Embeddings\Embedding_Repository_Interface
	 */
	private Embedding_Repository_Interface $repository;

	/**
	 * Consumer registry, for whether any target covers an object.
	 *
	 * @var \WordPress\AI\Embeddings\Sync\Consumer_Registry
	 */
	private Consumer_Registry $registry;

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, \WordPress\AI\Embeddings\Sync\Embedding_Source_Interface> $sources    Sources keyed by object type.
	 * @param \WordPress\AI\Embeddings\Embedding_Repository_Interface                 $repository Embeddings repository.
	 * @param \WordPress\AI\Embeddings\Sync\Consumer_Registry                         $registry   Consumer registry.
	 */
	public function __construct( array $sources, Embedding_Repository_Interface $repository, Consumer_Registry $registry ) {
		$this->sources    = $sources;
		$this->repository = $repository;
		$this->registry   = $registry;
	}

	/**
	 * Sweeps one page of a target's stored objects of one type.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Embeddings\Sync\Embedding_Target $target      The target.
	 * @param string                                         $object_type Object type with a source.
	 * @param int                                            $cursor      Sweep IDs greater than this.
	 * @param int                                            $limit       Maximum objects to check.
	 * @return array{last_id: int|null, checked: int, removed: int} The page's last ID (null when nothing is left), and counts.
	 */
	public function sweep( Embedding_Target $target, string $object_type, int $cursor, int $limit ): array {
		$source = $this->sources[ $object_type ] ?? null;
		$ids    = null === $source ? array() : $this->repository->get_object_ids_after( $object_type, $target->get_provider(), $target->get_model(), $cursor, $limit );

		if ( null === $source || array() === $ids ) {
			return array(
				'last_id' => null,
				'checked' => 0,
				'removed' => 0,
			);
		}

		$source->prime( $ids );
		$removed = 0;

		foreach ( $ids as $object_id ) {
			try {
				$subtype = $source->get_subtype( $object_id );
				$covered = null !== $subtype && $this->registry->covers( $object_type, $subtype );

				if ( $covered && ! $source->is_indexable( $object_id ) ) {
					$this->repository->delete_for_object( $object_type, $object_id );
					++$removed;
					continue;
				}

				if ( null !== $subtype && $target->covers( $object_type, $subtype ) ) {
					continue;
				}

				$this->repository->delete_for_object( $object_type, $object_id, $target->get_provider(), $target->get_model() );
				++$removed;
			} catch ( Throwable $e ) {
				// One unreadable object must not stop the sweep; it is retried by the next backfill.
				wp_trigger_error( __METHOD__, esc_html( $e->getMessage() ), E_USER_WARNING );
			}
		}

		return array(
			'last_id' => (int) max( $ids ),
			'checked' => count( $ids ),
			'removed' => $removed,
		);
	}
}
