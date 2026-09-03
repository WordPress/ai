<?php
/**
 * Value object describing one stored embedding vector.
 *
 * @package WordPress\AI\Embeddings
 */

declare( strict_types=1 );

namespace WordPress\AI\Embeddings;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * An embedding vector together with the identity of what it embeds and the model that produced it.
 *
 * Vectors are only comparable to other vectors produced by the same model, so the provider and
 * model are part of every record's identity rather than a detail of the configuration that
 * happened to be active when it was generated.
 *
 * @since x.x.x
 */
final class Embedding_Record {

	/**
	 * Row ID, or 0 when the record has not been persisted.
	 *
	 * @var int
	 */
	private int $id;

	/**
	 * Type of the embedded object, e.g. `post`.
	 *
	 * @var string
	 */
	private string $object_type;

	/**
	 * Subtype of the embedded object, e.g. `page` (for object_type `post`), or empty.
	 *
	 * @var string
	 */
	private string $object_subtype;

	/**
	 * ID of the embedded object.
	 *
	 * @var int
	 */
	private int $object_id;

	/**
	 * Position of this vector among the object's chunks. 0 for whole-object embeddings.
	 *
	 * @var int
	 */
	private int $chunk_index;

	/**
	 * Provider ID of the model that produced the vector.
	 *
	 * @var string
	 */
	private string $provider;

	/**
	 * Model ID that produced the vector.
	 *
	 * @var string
	 */
	private string $model;

	/**
	 * The vector.
	 *
	 * @var list<float>
	 */
	private array $vector;

	/**
	 * Hash of the source content the vector was generated from, or empty.
	 *
	 * @var string
	 */
	private string $content_hash;

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param string          $object_type    Type of the embedded object, e.g. `post`.
	 * @param int             $object_id      ID of the embedded object.
	 * @param string          $provider       Provider ID of the model that produced the vector.
	 * @param string          $model          Model ID that produced the vector.
	 * @param list<int|float> $vector         The vector.
	 * @param int             $chunk_index    Optional. Position among the object's chunks. Default 0.
	 * @param string          $content_hash   Optional. Hash of the source content. Default empty.
	 * @param int             $id             Optional. Row ID when hydrated from storage. Default 0.
	 * @param string          $object_subtype Optional. Subtype of the object, e.g. `page`. Default empty.
	 *
	 * @throws \InvalidArgumentException If any identity field or the vector is invalid.
	 */
	public function __construct(
		string $object_type,
		int $object_id,
		string $provider,
		string $model,
		array $vector,
		int $chunk_index = 0,
		string $content_hash = '',
		int $id = 0,
		string $object_subtype = ''
	) {
		$object_type    = trim( $object_type );
		$object_subtype = trim( $object_subtype );
		$provider       = trim( $provider );
		$model          = trim( $model );

		if ( '' === $object_type || strlen( $object_type ) > 32 ) {
			throw new InvalidArgumentException( 'Object type must be a non-empty string of at most 32 characters.' );
		}

		if ( '' !== $object_subtype && strlen( $object_subtype ) > 32 ) {
			throw new InvalidArgumentException( 'Object subtype must be at most 32 characters.' );
		}

		if ( $object_id <= 0 ) {
			throw new InvalidArgumentException( 'Object ID must be a positive integer.' );
		}

		if ( '' === $provider || strlen( $provider ) > 64 ) {
			throw new InvalidArgumentException( 'Provider ID must be a non-empty string of at most 64 characters.' );
		}

		if ( '' === $model || strlen( $model ) > 128 ) {
			throw new InvalidArgumentException( 'Model ID must be a non-empty string of at most 128 characters.' );
		}

		if ( $chunk_index < 0 ) {
			throw new InvalidArgumentException( 'Chunk index must be zero or a positive integer.' );
		}

		if ( '' !== $content_hash && strlen( $content_hash ) > 64 ) {
			throw new InvalidArgumentException( 'Content hash must be at most 64 characters.' );
		}

		Vector_Codec::validate( $vector );

		$this->id             = max( 0, $id );
		$this->object_type    = $object_type;
		$this->object_subtype = $object_subtype;
		$this->object_id      = $object_id;
		$this->chunk_index    = $chunk_index;
		$this->provider       = $provider;
		$this->model          = $model;
		$this->vector         = array_map( 'floatval', $vector );
		$this->content_hash   = $content_hash;
	}

	/**
	 * Returns the row ID, or 0 when the record has not been persisted.
	 *
	 * @since x.x.x
	 *
	 * @return int Row ID.
	 */
	public function get_id(): int {
		return $this->id;
	}

	/**
	 * Returns the type of the embedded object.
	 *
	 * @since x.x.x
	 *
	 * @return string Object type.
	 */
	public function get_object_type(): string {
		return $this->object_type;
	}

	/**
	 * Returns the subtype of the embedded object, or an empty string.
	 *
	 * @since x.x.x
	 *
	 * @return string Object subtype.
	 */
	public function get_object_subtype(): string {
		return $this->object_subtype;
	}

	/**
	 * Returns the ID of the embedded object.
	 *
	 * @since x.x.x
	 *
	 * @return int Object ID.
	 */
	public function get_object_id(): int {
		return $this->object_id;
	}

	/**
	 * Returns the position of this vector among the object's chunks.
	 *
	 * @since x.x.x
	 *
	 * @return int Chunk index.
	 */
	public function get_chunk_index(): int {
		return $this->chunk_index;
	}

	/**
	 * Returns the provider ID of the model that produced the vector.
	 *
	 * @since x.x.x
	 *
	 * @return string Provider ID.
	 */
	public function get_provider(): string {
		return $this->provider;
	}

	/**
	 * Returns the model ID that produced the vector.
	 *
	 * @since x.x.x
	 *
	 * @return string Model ID.
	 */
	public function get_model(): string {
		return $this->model;
	}

	/**
	 * Returns the vector.
	 *
	 * @since x.x.x
	 *
	 * @return list<float> The vector.
	 */
	public function get_vector(): array {
		return $this->vector;
	}

	/**
	 * Returns the number of components in the vector.
	 *
	 * @since x.x.x
	 *
	 * @return int Dimensions.
	 */
	public function get_dimensions(): int {
		return count( $this->vector );
	}

	/**
	 * Returns the hash of the source content, or an empty string.
	 *
	 * @since x.x.x
	 *
	 * @return string Content hash.
	 */
	public function get_content_hash(): string {
		return $this->content_hash;
	}

	/**
	 * Returns whether this record was produced by the given model.
	 *
	 * @since x.x.x
	 *
	 * @param string $provider Provider ID.
	 * @param string $model    Model ID.
	 * @return bool True when provider and model both match.
	 */
	public function is_from_model( string $provider, string $model ): bool {
		return trim( $provider ) === $this->provider && trim( $model ) === $this->model;
	}

	/**
	 * Returns a copy of this record carrying the given row ID.
	 *
	 * @since x.x.x
	 *
	 * @param int $id Row ID.
	 * @return self The copy.
	 */
	public function with_id( int $id ): self {
		$copy     = clone $this;
		$copy->id = max( 0, $id );

		return $copy;
	}
}
