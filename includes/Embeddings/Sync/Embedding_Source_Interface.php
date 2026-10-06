<?php
/**
 * Contract for an object type that can be kept in sync.
 *
 * @package WordPress\AI\Embeddings\Sync
 */

declare( strict_types=1 );

namespace WordPress\AI\Embeddings\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Adapts one WordPress object type (posts, terms, …) to the sync layer.
 *
 * @since x.x.x
 */
interface Embedding_Source_Interface {

	/**
	 * Returns the object type this source handles, as stored in the embeddings table.
	 *
	 * @since x.x.x
	 *
	 * @return string The object type.
	 */
	public function get_object_type(): string;

	/**
	 * Checks whether a subtype (post type, taxonomy, …) is registered.
	 *
	 * @since x.x.x
	 *
	 * @param string $subtype Subtype.
	 * @return bool True when registered.
	 */
	public function subtype_exists( string $subtype ): bool;

	/**
	 * Returns an object's subtype.
	 *
	 * @since x.x.x
	 *
	 * @param int $object_id Object ID.
	 * @return string|null The subtype, or null when the object does not exist.
	 */
	public function get_subtype( int $object_id ): ?string;

	/**
	 * Checks whether an object should have vectors.
	 *
	 * @since x.x.x
	 *
	 * @param int $object_id Object ID.
	 * @return bool True when the object should be embedded.
	 */
	public function is_indexable( int $object_id ): bool;

	/**
	 * Returns the text that represents an object.
	 *
	 * @since x.x.x
	 *
	 * @param int $object_id Object ID.
	 * @return string The text, or an empty string when there is nothing to embed.
	 */
	public function get_text( int $object_id ): string;

	/**
	 * Returns indexable object IDs greater than a cursor, ascending.
	 *
	 * @since x.x.x
	 *
	 * @param int          $cursor   Return IDs greater than this.
	 * @param list<string> $subtypes Subtypes to include.
	 * @param int          $limit    Maximum IDs.
	 * @return list<int> Object IDs.
	 */
	public function get_ids_after( int $cursor, array $subtypes, int $limit ): array;

	/**
	 * Counts indexable objects per subtype.
	 *
	 * @since x.x.x
	 *
	 * @param list<string> $subtypes Subtypes to count.
	 * @return array<string, int> Count per subtype; every requested subtype is present.
	 */
	public function count_indexable( array $subtypes ): array;

	/**
	 * Hooks the source's change events to a listener.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Embeddings\Sync\Change_Listener $listener The listener.
	 */
	public function register_hooks( Change_Listener $listener ): void;
}
