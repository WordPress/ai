<?php
/**
 * Registry of features that need synchronized embeddings.
 *
 * @package WordPress\AI\Embeddings\Sync
 */

declare( strict_types=1 );

namespace WordPress\AI\Embeddings\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Holds registered consumers and derives the targets sync keeps current.
 *
 * @since x.x.x
 */
class Consumer_Registry {

	/**
	 * Object types a consumer may ask for.
	 *
	 * @var list<string>
	 */
	private array $object_types;

	/**
	 * Registered consumers keyed by ID.
	 *
	 * @var array<string, array{provider: string, model: string, dimensions: int|null, objects: array<string, list<string>>}>
	 */
	private array $consumers = array();

	/**
	 * Derived targets, or null when they need recomputing.
	 *
	 * @var array<string, \WordPress\AI\Embeddings\Sync\Embedding_Target>|null
	 */
	private ?array $targets = null;

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param list<string> $object_types Optional. Supported object types. Default post and term.
	 */
	public function __construct( array $object_types = array( 'post', 'term' ) ) {
		$this->object_types = $object_types;
	}

	/**
	 * Registers a consumer.
	 *
	 * @since x.x.x
	 *
	 * @param string               $id   Unique consumer ID, such as a feature ID.
	 * @param array<string, mixed> $args {
	 *     Consumer configuration.
	 *
	 *     @type string                      $provider   Required. Provider ID.
	 *     @type string                      $model      Required. Embedding model ID.
	 *     @type int|null                    $dimensions Optional. Requested dimensions. Default null.
	 *     @type array<string, list<string>> $objects    Required. Subtypes keyed by object type, for example
	 *                                                   `array( 'post' => array( 'post' ), 'term' => array( 'post_tag' ) )`.
	 * }
	 * @return bool True when registered; false (with a `_doing_it_wrong()` notice) when rejected.
	 */
	public function register( string $id, array $args ): bool {
		$id = trim( $id );

		if ( '' === $id ) {
			return $this->reject( __( 'An embedding consumer ID cannot be empty.', 'ai' ) );
		}

		if ( isset( $this->consumers[ $id ] ) ) {
			/* translators: %s: Consumer ID. */
			return $this->reject( sprintf( __( 'The embedding consumer "%s" is already registered.', 'ai' ), $id ) );
		}

		$provider = isset( $args['provider'] ) && is_string( $args['provider'] ) ? trim( $args['provider'] ) : '';
		$model    = isset( $args['model'] ) && is_string( $args['model'] ) ? trim( $args['model'] ) : '';

		if ( '' === $provider || '' === $model ) {
			/* translators: %s: Consumer ID. */
			return $this->reject( sprintf( __( 'The embedding consumer "%s" must name a provider and a model.', 'ai' ), $id ) );
		}

		$dimensions = $args['dimensions'] ?? null;

		if ( null !== $dimensions && ( ! is_int( $dimensions ) || $dimensions < 1 ) ) {
			/* translators: %s: Consumer ID. */
			return $this->reject( sprintf( __( 'The embedding consumer "%s" must request a positive number of dimensions, or none.', 'ai' ), $id ) );
		}

		$objects = $this->normalize_objects( $id, $args['objects'] ?? null );

		if ( null === $objects ) {
			return false;
		}

		foreach ( $this->consumers as $other_id => $other ) {
			if ( $other['provider'] !== $provider || $other['model'] !== $model || $other['dimensions'] === $dimensions ) {
				continue;
			}

			// Dimensions are not part of the embeddings table's unique key, so two lengths under one
			// model would overwrite each other's rows.
			return $this->reject(
				sprintf(
					/* translators: 1: Consumer ID, 2: Provider ID, 3: Model ID, 4: Other consumer ID. */
					__( 'The embedding consumer "%1$s" requests different dimensions from %2$s/%3$s than "%4$s". Consumers sharing a model must request the same dimensions.', 'ai' ),
					$id,
					$provider,
					$model,
					$other_id
				)
			);
		}

		$this->consumers[ $id ] = array(
			'provider'   => $provider,
			'model'      => $model,
			'dimensions' => $dimensions,
			'objects'    => $objects,
		);

		$this->targets = null;

		return true;
	}

	/**
	 * Removes a consumer.
	 *
	 * @since x.x.x
	 *
	 * @param string $id Consumer ID.
	 */
	public function unregister( string $id ): void {
		unset( $this->consumers[ $id ] );
		$this->targets = null;
	}

	/**
	 * Checks whether any consumer is registered.
	 *
	 * @since x.x.x
	 *
	 * @return bool True when at least one consumer is registered.
	 */
	public function has_consumers(): bool {
		return array() !== $this->consumers;
	}

	/**
	 * Returns the registered consumer IDs.
	 *
	 * @since x.x.x
	 *
	 * @return list<string> The IDs.
	 */
	public function get_consumer_ids(): array {
		return array_keys( $this->consumers );
	}

	/**
	 * Returns one consumer's configuration.
	 *
	 * @since x.x.x
	 *
	 * @param string $id Consumer ID.
	 * @return array{provider: string, model: string, dimensions: int|null, objects: array<string, list<string>>}|null The configuration, or null.
	 */
	public function get_consumer( string $id ): ?array {
		return $this->consumers[ $id ] ?? null;
	}

	/**
	 * Returns the targets derived from all consumers.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, \WordPress\AI\Embeddings\Sync\Embedding_Target> Targets keyed by target key.
	 */
	public function get_targets(): array {
		if ( null !== $this->targets ) {
			return $this->targets;
		}

		$targets = array();

		foreach ( $this->consumers as $consumer ) {
			$key    = Embedding_Target::key_for( $consumer['provider'], $consumer['model'] );
			$target = $targets[ $key ] ?? new Embedding_Target( $consumer['provider'], $consumer['model'], $consumer['dimensions'] );

			foreach ( $consumer['objects'] as $object_type => $subtypes ) {
				$target = $target->with_subtypes( $object_type, $subtypes );
			}

			$targets[ $key ] = $target;
		}

		$this->targets = $targets;

		return $targets;
	}

	/**
	 * Returns a target by key.
	 *
	 * @since x.x.x
	 *
	 * @param string $key Target key.
	 * @return \WordPress\AI\Embeddings\Sync\Embedding_Target|null The target, or null.
	 */
	public function get_target( string $key ): ?Embedding_Target {
		return $this->get_targets()[ $key ] ?? null;
	}

	/**
	 * Returns the target a consumer's vectors belong to.
	 *
	 * @since x.x.x
	 *
	 * @param string $id Consumer ID.
	 * @return \WordPress\AI\Embeddings\Sync\Embedding_Target|null The target, or null for an unknown consumer.
	 */
	public function get_target_for_consumer( string $id ): ?Embedding_Target {
		$consumer = $this->get_consumer( $id );

		return null === $consumer ? null : $this->get_target( Embedding_Target::key_for( $consumer['provider'], $consumer['model'] ) );
	}

	/**
	 * Returns the targets that cover a subtype.
	 *
	 * @since x.x.x
	 *
	 * @param string $object_type Object type.
	 * @param string $subtype     Subtype.
	 * @return list<\WordPress\AI\Embeddings\Sync\Embedding_Target> The covering targets.
	 */
	public function get_targets_covering( string $object_type, string $subtype ): array {
		return array_values(
			array_filter(
				$this->get_targets(),
				static fn( Embedding_Target $target ): bool => $target->covers( $object_type, $subtype )
			)
		);
	}

	/**
	 * Checks whether any target covers a subtype.
	 *
	 * @since x.x.x
	 *
	 * @param string $object_type Object type.
	 * @param string $subtype     Subtype.
	 * @return bool True when covered.
	 */
	public function covers( string $object_type, string $subtype ): bool {
		return array() !== $this->get_targets_covering( $object_type, $subtype );
	}

	/**
	 * Drops subtypes that are not registered, removing consumers left with none.
	 *
	 * @since x.x.x
	 *
	 * @param callable(string, string): bool $exists Receives an object type and a subtype; returns whether the subtype exists.
	 */
	public function remove_unknown_subtypes( callable $exists ): void {
		foreach ( $this->consumers as $id => $consumer ) {
			$objects = array();

			foreach ( $consumer['objects'] as $object_type => $subtypes ) {
				$known = array();

				foreach ( $subtypes as $subtype ) {
					if ( $exists( $object_type, $subtype ) ) {
						$known[] = $subtype;
						continue;
					}

					$this->reject(
						sprintf(
							/* translators: 1: Consumer ID, 2: Object type, 3: Subtype. */
							__( 'The embedding consumer "%1$s" lists the %2$s subtype "%3$s", which is not registered. It is ignored.', 'ai' ),
							$id,
							$object_type,
							$subtype
						)
					);
				}

				if ( array() === $known ) {
					continue;
				}

				$objects[ $object_type ] = $known;
			}

			if ( array() === $objects ) {
				unset( $this->consumers[ $id ] );
				continue;
			}

			$consumer['objects']    = $objects;
			$this->consumers[ $id ] = $consumer;
		}

		$this->targets = null;
	}

	/**
	 * Validates and normalizes a consumer's `objects` argument.
	 *
	 * @since x.x.x
	 *
	 * @param string $id      Consumer ID, for messages.
	 * @param mixed  $objects The raw argument.
	 * @return array<string, list<string>>|null The normalized map, or null when rejected.
	 */
	private function normalize_objects( string $id, $objects ): ?array {
		if ( ! is_array( $objects ) || array() === $objects ) {
			/* translators: %s: Consumer ID. */
			$this->reject( sprintf( __( 'The embedding consumer "%s" must list at least one object type.', 'ai' ), $id ) );

			return null;
		}

		$normalized = array();

		foreach ( $objects as $object_type => $subtypes ) {
			if ( ! is_string( $object_type ) || ! in_array( $object_type, $this->object_types, true ) ) {
				$this->reject(
					sprintf(
						/* translators: 1: Consumer ID, 2: Object type, 3: Comma-separated supported object types. */
						__( 'The embedding consumer "%1$s" asks for the unsupported object type "%2$s". Supported: %3$s.', 'ai' ),
						$id,
						(string) $object_type,
						implode( ', ', $this->object_types )
					)
				);

				return null;
			}

			$clean = is_array( $subtypes )
				? array_values( array_unique( array_filter( array_map( static fn( $subtype ): string => is_string( $subtype ) ? trim( $subtype ) : '', $subtypes ), static fn( string $subtype ): bool => '' !== $subtype ) ) )
				: array();

			if ( array() === $clean ) {
				/* translators: 1: Consumer ID, 2: Object type. */
				$this->reject( sprintf( __( 'The embedding consumer "%1$s" must list at least one %2$s subtype.', 'ai' ), $id, $object_type ) );

				return null;
			}

			$normalized[ $object_type ] = $clean;
		}

		return $normalized;
	}

	/**
	 * Emits a developer notice and returns false.
	 *
	 * @since x.x.x
	 *
	 * @param string $message The notice.
	 * @return bool Always false.
	 */
	private function reject( string $message ): bool {
		_doing_it_wrong( self::class . '::register', esc_html( $message ), 'x.x.x' );

		return false;
	}
}
