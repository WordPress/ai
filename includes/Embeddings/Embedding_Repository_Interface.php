<?php
/**
 * Storage contract for embedding vectors.
 *
 * @package WordPress\AI\Embeddings
 */

declare( strict_types=1 );

namespace WordPress\AI\Embeddings;

defined( 'ABSPATH' ) || exit;

/**
 * Stores and retrieves embedding vectors.
 *
 * This contract covers persistence only. Similarity search is deliberately not part of it, so that
 * a portable implementation can store vectors on any database WordPress supports while an
 * implementation backed by a native vector index can add search on top.
 *
 * Every operation is scoped to a provider and model, because vectors from different models are not
 * comparable and must never be mixed. Also worth noting that vectors from the same model but with
 * different dimension counts are not comparable.
 *
 * @since x.x.x
 */
interface Embedding_Repository_Interface {

	/**
	 * Stores a record, replacing any existing vector for the same object, model and chunk.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Embeddings\Embedding_Record $record The record to store.
	 * @return \WordPress\AI\Embeddings\Embedding_Record The stored record, carrying its row ID.
	 *
	 * @throws \RuntimeException If the record could not be written.
	 */
	public function save( Embedding_Record $record ): Embedding_Record;

	/**
	 * Stores several records, replacing existing vectors for the same object, model and chunk.
	 *
	 * @since x.x.x
	 *
	 * @param list<\WordPress\AI\Embeddings\Embedding_Record> $records The records to store.
	 * @return list<\WordPress\AI\Embeddings\Embedding_Record> The stored records, carrying their row IDs.
	 *
	 * The returned list is positionally aligned with $records, so callers may pair them by index.
	 *
	 * @throws \InvalidArgumentException If any entry is not an Embedding_Record. The batch is
	 *                                   validated in full before anything is written, so a rejected
	 *                                   batch writes nothing.
	 * @throws \RuntimeException         If a record could not be written. Records are written one
	 *                                   at a time and are not rolled back, so a failure part-way
	 *                                   through leaves the earlier records stored.
	 */
	public function save_many( array $records ): array;

	/**
	 * Returns the stored vectors for an object, in chunk order.
	 *
	 * @since x.x.x
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @param string $provider    Provider ID.
	 * @param string $model       Model ID.
	 * @return list<\WordPress\AI\Embeddings\Embedding_Record> Records, empty if the object is not indexed for this model.
	 */
	public function get( string $object_type, int $object_id, string $provider, string $model ): array;

	/**
	 * Returns a record by its row ID.
	 *
	 * @since x.x.x
	 *
	 * @param int $id Row ID.
	 * @return \WordPress\AI\Embeddings\Embedding_Record|null The record, or null if it does not exist.
	 */
	public function get_by_id( int $id ): ?Embedding_Record;

	/**
	 * Returns the content hash stored for an object, if any.
	 *
	 * Lets callers decide whether an object needs re-embedding without loading its vectors.
	 * The hash is object-level: every chunk of an object stores the same hash, covering the whole
	 * source content.
	 *
	 * @since x.x.x
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @param string $provider    Provider ID.
	 * @param string $model       Model ID.
	 * @return string|null The hash, or null if the object is not indexed for this model.
	 */
	public function get_content_hash( string $object_type, int $object_id, string $provider, string $model ): ?string;

	/**
	 * Returns the IDs of objects that have stored vectors for a model, newest first.
	 *
	 * @since x.x.x
	 *
	 * @param string $object_type Object type.
	 * @param string $provider    Provider ID.
	 * @param string $model       Model ID.
	 * @param int    $limit       Maximum number of IDs to return.
	 * @param int    $offset      Optional. Number of IDs to skip. Default 0.
	 * @return list<int> Distinct object IDs.
	 */
	public function get_object_ids( string $object_type, string $provider, string $model, int $limit, int $offset = 0 ): array;

	/**
	 * Counts the objects that have stored vectors for a model.
	 *
	 * @since x.x.x
	 *
	 * @param string $object_type Object type.
	 * @param string $provider    Provider ID.
	 * @param string $model       Model ID.
	 * @return int Number of distinct objects.
	 */
	public function count_objects( string $object_type, string $provider, string $model ): int;

	/**
	 * Iterates over every record for a model, in batches.
	 *
	 * @since x.x.x
	 *
	 * @param string      $provider    Provider ID.
	 * @param string      $model       Model ID.
	 * @param string|null $object_type Optional. Restrict to one object type. Default null.
	 * @param int         $batch_size  Optional. Rows fetched per query. Default 200.
	 * @return iterable<\WordPress\AI\Embeddings\Embedding_Record> The records.
	 *
	 * @throws \RuntimeException If a batch could not be read. Normal completion therefore means
	 *                           every matching record was yielded, which is what lets a caller
	 *                           rebuilding an index over the whole corpus trust the scan.
	 */
	public function iterate( string $provider, string $model, ?string $object_type = null, int $batch_size = 200 ): iterable;

	/**
	 * Deletes the stored vectors for an object.
	 *
	 * @since x.x.x
	 *
	 * @param string      $object_type Object type.
	 * @param int         $object_id   Object ID.
	 * @param string|null $provider    Optional. Restrict to one provider. Default null, all providers.
	 * @param string|null $model       Optional. Restrict to one model. Default null, all models.
	 * @return int Number of rows deleted.
	 */
	public function delete_for_object( string $object_type, int $object_id, ?string $provider = null, ?string $model = null ): int;

	/**
	 * Deletes every stored vector produced by a model.
	 *
	 * @since x.x.x
	 *
	 * @param string $provider Provider ID.
	 * @param string $model    Model ID.
	 * @return int Number of rows deleted.
	 */
	public function delete_for_model( string $provider, string $model ): int;
}
