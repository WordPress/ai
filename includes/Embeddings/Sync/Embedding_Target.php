<?php
/**
 * A model that synchronized vectors are kept current for.
 *
 * @package WordPress\AI\Embeddings\Sync
 */

declare( strict_types=1 );

namespace WordPress\AI\Embeddings\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * One `(provider, model)` pair and the subtypes kept current for it.
 *
 * Consumers on the same model share a target, and so share rows and API calls.
 *
 * @since x.x.x
 */
final class Embedding_Target {

	/**
	 * Provider ID.
	 *
	 * @var string
	 */
	private string $provider;

	/**
	 * Model ID.
	 *
	 * @var string
	 */
	private string $model;

	/**
	 * Requested vector dimensions, or null for the model default.
	 *
	 * @var int|null
	 */
	private ?int $dimensions;

	/**
	 * Covered subtypes keyed by object type.
	 *
	 * @var array<string, list<string>>
	 */
	private array $subtypes;

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param string                      $provider   Provider ID.
	 * @param string                      $model      Model ID.
	 * @param int|null                    $dimensions Optional. Requested dimensions. Default null.
	 * @param array<string, list<string>> $subtypes   Optional. Covered subtypes keyed by object type. Default none.
	 */
	public function __construct( string $provider, string $model, ?int $dimensions = null, array $subtypes = array() ) {
		$this->provider   = $provider;
		$this->model      = $model;
		$this->dimensions = $dimensions;
		$this->subtypes   = $subtypes;
	}

	/**
	 * Returns the stable key for a provider and model.
	 *
	 * @since x.x.x
	 *
	 * @param string $provider Provider ID.
	 * @param string $model    Model ID.
	 * @return string The 12-character key.
	 */
	public static function key_for( string $provider, string $model ): string {
		return substr( md5( $provider . "\0" . $model ), 0, 12 );
	}

	/**
	 * Returns this target's key.
	 *
	 * @since x.x.x
	 *
	 * @return string The key.
	 */
	public function get_key(): string {
		return self::key_for( $this->provider, $this->model );
	}

	/**
	 * Returns the provider ID.
	 *
	 * @since x.x.x
	 *
	 * @return string The provider ID.
	 */
	public function get_provider(): string {
		return $this->provider;
	}

	/**
	 * Returns the model ID.
	 *
	 * @since x.x.x
	 *
	 * @return string The model ID.
	 */
	public function get_model(): string {
		return $this->model;
	}

	/**
	 * Returns the requested dimensions.
	 *
	 * @since x.x.x
	 *
	 * @return int|null The dimensions, or null for the model default.
	 */
	public function get_dimensions(): ?int {
		return $this->dimensions;
	}

	/**
	 * Returns all covered subtypes keyed by object type.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, list<string>> The subtypes.
	 */
	public function get_subtypes(): array {
		return $this->subtypes;
	}

	/**
	 * Returns the covered object types.
	 *
	 * @since x.x.x
	 *
	 * @return list<string> The object types.
	 */
	public function get_object_types(): array {
		return array_keys( $this->subtypes );
	}

	/**
	 * Returns the covered subtypes of one object type.
	 *
	 * @since x.x.x
	 *
	 * @param string $object_type Object type.
	 * @return list<string> The subtypes.
	 */
	public function get_subtypes_for( string $object_type ): array {
		return $this->subtypes[ $object_type ] ?? array();
	}

	/**
	 * Checks whether a subtype is covered.
	 *
	 * @since x.x.x
	 *
	 * @param string $object_type Object type.
	 * @param string $subtype     Subtype.
	 * @return bool True when covered.
	 */
	public function covers( string $object_type, string $subtype ): bool {
		return in_array( $subtype, $this->get_subtypes_for( $object_type ), true );
	}

	/**
	 * Returns a copy also covering the given subtypes.
	 *
	 * @since x.x.x
	 *
	 * @param string       $object_type Object type.
	 * @param list<string> $subtypes    Subtypes to add.
	 * @return self The merged target.
	 */
	public function with_subtypes( string $object_type, array $subtypes ): self {
		$merged = array_values( array_unique( array_merge( $this->get_subtypes_for( $object_type ), $subtypes ) ) );
		sort( $merged );

		$clone                           = clone $this;
		$clone->subtypes[ $object_type ] = $merged;

		return $clone;
	}
}
