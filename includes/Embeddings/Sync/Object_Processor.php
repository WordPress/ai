<?php
/**
 * Embeds and stores a batch of objects.
 *
 * @package WordPress\AI\Embeddings\Sync
 */

declare( strict_types=1 );

namespace WordPress\AI\Embeddings\Sync;

use InvalidArgumentException;
use RuntimeException;
use WordPress\AI\Embeddings\Embedding_Record;
use WordPress\AI\Embeddings\Embedding_Repository_Interface;
use WordPress\AI\Embeddings\Text_Chunker;

defined( 'ABSPATH' ) || exit;

/**
 * Text → chunks → hash → skip or embed → store, for every target covering each object.
 *
 * @since x.x.x
 */
class Object_Processor {

	/**
	 * Default cap on chunks embedded per object.
	 *
	 * @since x.x.x
	 */
	public const DEFAULT_MAX_CHUNKS = 50;

	/**
	 * Default cap on inputs per API request.
	 *
	 * @since x.x.x
	 */
	public const DEFAULT_MAX_INPUTS = 100;

	/**
	 * Cap on characters per API request.
	 *
	 * @since x.x.x
	 */
	public const MAX_REQUEST_CHARS = 200000;

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
	 * Embeddings repository.
	 *
	 * @var \WordPress\AI\Embeddings\Embedding_Repository_Interface
	 */
	private Embedding_Repository_Interface $repository;

	/**
	 * Embedding client.
	 *
	 * @var \WordPress\AI\Embeddings\Sync\Embedding_Client_Interface
	 */
	private Embedding_Client_Interface $client;

	/**
	 * Provider backoff.
	 *
	 * @var \WordPress\AI\Embeddings\Sync\Provider_Backoff
	 */
	private Provider_Backoff $backoff;

	/**
	 * Chunker.
	 *
	 * @var \WordPress\AI\Embeddings\Text_Chunker
	 */
	private Text_Chunker $chunker;

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Embeddings\Sync\Consumer_Registry                         $registry   Consumer registry.
	 * @param array<string, \WordPress\AI\Embeddings\Sync\Embedding_Source_Interface> $sources    Sources keyed by object type.
	 * @param \WordPress\AI\Embeddings\Embedding_Repository_Interface                 $repository Embeddings repository.
	 * @param \WordPress\AI\Embeddings\Sync\Embedding_Client_Interface                $client     Embedding client.
	 * @param \WordPress\AI\Embeddings\Sync\Provider_Backoff                          $backoff    Provider backoff.
	 * @param \WordPress\AI\Embeddings\Text_Chunker|null                              $chunker    Optional. Chunker. Default a new instance.
	 */
	public function __construct(
		Consumer_Registry $registry,
		array $sources,
		Embedding_Repository_Interface $repository,
		Embedding_Client_Interface $client,
		Provider_Backoff $backoff,
		?Text_Chunker $chunker = null
	) {
		$this->registry   = $registry;
		$this->sources    = $sources;
		$this->repository = $repository;
		$this->client     = $client;
		$this->backoff    = $backoff;
		$this->chunker    = $chunker ?? new Text_Chunker();
	}

	/**
	 * Returns the content hash stored for a text.
	 *
	 * @since x.x.x
	 *
	 * @param string $text       The object's full text.
	 * @param int    $max_chunks The chunk cap in force.
	 * @return string The sha256 hash.
	 */
	public static function hash_text( string $text, int $max_chunks ): string {
		return hash( 'sha256', Text_Chunker::VERSION . "\0" . $max_chunks . "\0" . $text );
	}

	/**
	 * Processes a batch of objects of one type.
	 *
	 * @since x.x.x
	 *
	 * @param string                                              $object_type Object type.
	 * @param list<int>                                           $object_ids  Object IDs.
	 * @param \WordPress\AI\Embeddings\Sync\Embedding_Target|null $only_target Optional. Limit work to one target, as a backfill does. Default every target.
	 * @return array<int, \WordPress\AI\Embeddings\Sync\Object_Result> Results keyed by object ID.
	 */
	public function process( string $object_type, array $object_ids, ?Embedding_Target $only_target = null ): array {
		$results = array();
		$source  = $this->sources[ $object_type ] ?? null;

		if ( null === $source ) {
			foreach ( $object_ids as $object_id ) {
				$results[ $object_id ] = Object_Result::skipped();
			}

			return $results;
		}

		$max_chunks = $this->get_max_chunks();
		$prepared   = array();

		foreach ( $object_ids as $object_id ) {
			$subtype = $source->get_subtype( $object_id );

			if ( null === $subtype ) {
				$results[ $object_id ] = $this->remove( $object_type, $object_id );
				continue;
			}

			if ( ! $this->registry->covers( $object_type, $subtype ) ) {
				$results[ $object_id ] = Object_Result::skipped();
				continue;
			}

			if ( ! $source->is_indexable( $object_id ) ) {
				$results[ $object_id ] = $this->remove( $object_type, $object_id );
				continue;
			}

			$text   = $source->get_text( $object_id );
			$chunks = array_slice( $this->chunker->chunk( $text ), 0, $max_chunks );

			if ( array() === $chunks ) {
				$results[ $object_id ] = $this->remove( $object_type, $object_id );
				continue;
			}

			$prepared[ $object_id ] = array(
				'subtype' => $subtype,
				'chunks'  => $chunks,
				'hash'    => self::hash_text( $text, $max_chunks ),
			);
		}

		$targets  = null !== $only_target ? array( $only_target ) : array_values( $this->registry->get_targets() );
		$outcomes = array();

		foreach ( $targets as $target ) {
			$covered = array_filter(
				$prepared,
				static fn( array $item ): bool => $target->covers( $object_type, $item['subtype'] )
			);

			if ( array() === $covered ) {
				continue;
			}

			foreach ( $this->process_target( $object_type, $covered, $target ) as $object_id => $outcome ) {
				$outcomes[ $object_id ][] = $outcome;
			}
		}

		foreach ( array_keys( $prepared ) as $object_id ) {
			$results[ $object_id ] = $this->combine( $outcomes[ $object_id ] ?? array() );
		}

		return $results;
	}

	/**
	 * Processes covered objects for one target.
	 *
	 * @since x.x.x
	 *
	 * @param string                                                             $object_type Object type.
	 * @param array<int, array{subtype: string, chunks: list<string>, hash: string}> $prepared    Prepared objects keyed by ID.
	 * @param \WordPress\AI\Embeddings\Sync\Embedding_Target                     $target      The target.
	 * @return array<int, \WordPress\AI\Embeddings\Sync\Object_Result> Results keyed by object ID.
	 */
	private function process_target( string $object_type, array $prepared, Embedding_Target $target ): array {
		$results      = array();
		$paused_until = $this->backoff->get_until( $target->get_provider() );

		if ( null !== $paused_until ) {
			foreach ( array_keys( $prepared ) as $object_id ) {
				$results[ $object_id ] = Object_Result::deferred( $paused_until );
			}

			return $results;
		}

		$stored  = $this->repository->get_indexed_hashes( $object_type, array_keys( $prepared ), $target->get_provider(), $target->get_model() );
		$pending = array();

		foreach ( $prepared as $object_id => $item ) {
			if ( ( $stored[ $object_id ] ?? null ) === $item['hash'] ) {
				$results[ $object_id ] = Object_Result::skipped();
				continue;
			}

			$pending[ $object_id ] = $item;
		}

		$groups = $this->group_requests( $pending );

		foreach ( $groups as $index => $group ) {
			$outcome = $this->embed_group( $object_type, $group, $target );
			$results = $results + $outcome['results'];

			if ( null === $outcome['paused_until'] ) {
				continue;
			}

			// Nothing else on this provider can succeed until the pause ends.
			foreach ( array_slice( $groups, $index + 1 ) as $rest ) {
				foreach ( array_keys( $rest ) as $object_id ) {
					$results[ $object_id ] = Object_Result::deferred( $outcome['paused_until'] );
				}
			}

			break;
		}

		return $results;
	}

	/**
	 * Packs whole objects into requests capped by input count and characters.
	 *
	 * @since x.x.x
	 *
	 * @param array<int, array{subtype: string, chunks: list<string>, hash: string}> $pending Objects to embed.
	 * @return list<array<int, array{subtype: string, chunks: list<string>, hash: string}>> Request groups.
	 */
	private function group_requests( array $pending ): array {
		/**
		 * Filters the maximum number of inputs sent in one embedding request.
		 *
		 * @since x.x.x
		 *
		 * @param int $max_inputs Maximum inputs. Default 100.
		 */
		$max_inputs = max( 1, (int) apply_filters( 'wpai_embedding_sync_request_max_inputs', self::DEFAULT_MAX_INPUTS ) );
		$groups     = array();
		$group      = array();
		$inputs     = 0;
		$chars      = 0;

		foreach ( $pending as $object_id => $item ) {
			$count  = count( $item['chunks'] );
			$length = array_sum( array_map( 'mb_strlen', $item['chunks'] ) );

			if ( array() !== $group && ( $inputs + $count > $max_inputs || $chars + $length > self::MAX_REQUEST_CHARS ) ) {
				$groups[] = $group;
				$group    = array();
				$inputs   = 0;
				$chars    = 0;
			}

			$group[ $object_id ] = $item;
			$inputs             += $count;
			$chars              += $length;
		}

		if ( array() !== $group ) {
			$groups[] = $group;
		}

		return $groups;
	}

	/**
	 * Embeds one request group and stores the results.
	 *
	 * @since x.x.x
	 *
	 * @param string                                                             $object_type Object type.
	 * @param array<int, array{subtype: string, chunks: list<string>, hash: string}> $group       Objects in the request.
	 * @param \WordPress\AI\Embeddings\Sync\Embedding_Target                     $target      The target.
	 * @return array{results: array<int, \WordPress\AI\Embeddings\Sync\Object_Result>, paused_until: int|null} Results, and the pause end when the provider was paused.
	 */
	private function embed_group( string $object_type, array $group, Embedding_Target $target ): array {
		$inputs = array();

		foreach ( $group as $item ) {
			foreach ( $item['chunks'] as $chunk ) {
				$inputs[] = $chunk;
			}
		}

		try {
			$vectors = $this->client->embed( $target, $inputs );
		} catch ( Embedding_Client_Exception $e ) {
			return $this->handle_failure( $object_type, $group, $target, $e );
		}

		// With any vector missing, none can be matched to its chunk, so store nothing and retry.
		if ( count( $vectors ) !== count( $inputs ) ) {
			$results = array();

			foreach ( array_keys( $group ) as $object_id ) {
				$results[ $object_id ] = Object_Result::failed( Embedding_Client_Exception::TRANSIENT, 'The provider returned fewer embeddings than requested.' );
			}

			return array(
				'results'      => $results,
				'paused_until' => null,
			);
		}

		$this->backoff->clear( $target->get_provider() );

		$results = array();
		$offset  = 0;

		foreach ( $group as $object_id => $item ) {
			$count                 = count( $item['chunks'] );
			$results[ $object_id ] = $this->store( $object_type, $object_id, $item, $target, array_slice( $vectors, $offset, $count ) );
			$offset               += $count;
		}

		return array(
			'results'      => $results,
			'paused_until' => null,
		);
	}

	/**
	 * Turns a classified failure into results.
	 *
	 * @since x.x.x
	 *
	 * @param string                                                             $object_type Object type.
	 * @param array<int, array{subtype: string, chunks: list<string>, hash: string}> $group       Objects in the failed request.
	 * @param \WordPress\AI\Embeddings\Sync\Embedding_Target                     $target      The target.
	 * @param \WordPress\AI\Embeddings\Sync\Embedding_Client_Exception           $error       The failure.
	 * @return array{results: array<int, \WordPress\AI\Embeddings\Sync\Object_Result>, paused_until: int|null} Results, and the pause end when the provider was paused.
	 */
	private function handle_failure( string $object_type, array $group, Embedding_Target $target, Embedding_Client_Exception $error ): array {
		$provider = $target->get_provider();
		$class    = $error->get_error_class();

		if ( Embedding_Client_Exception::RATE_LIMITED === $class || Embedding_Client_Exception::PROVIDER === $class ) {
			$until = Embedding_Client_Exception::RATE_LIMITED === $class
				? $this->backoff->record_rate_limit( $provider, $error->getMessage() )
				: $this->backoff->record_provider_error( $provider, $error->getMessage() );

			$results = array();

			foreach ( array_keys( $group ) as $object_id ) {
				$results[ $object_id ] = Object_Result::deferred( $until );
			}

			return array(
				'results'      => $results,
				'paused_until' => $until,
			);
		}

		// One bad input fails a whole request, so retry each object alone to find the culprit.
		if ( Embedding_Client_Exception::ITEM === $class && count( $group ) > 1 ) {
			$results = array();

			foreach ( $group as $object_id => $item ) {
				$single  = $this->embed_group( $object_type, array( $object_id => $item ), $target );
				$results = $results + $single['results'];

				if ( null === $single['paused_until'] ) {
					continue;
				}

				foreach ( array_keys( $group ) as $remaining_id ) {
					if ( isset( $results[ $remaining_id ] ) ) {
						continue;
					}

					$results[ $remaining_id ] = Object_Result::deferred( $single['paused_until'] );
				}

				return array(
					'results'      => $results,
					'paused_until' => $single['paused_until'],
				);
			}

			return array(
				'results'      => $results,
				'paused_until' => null,
			);
		}

		$results = array();

		foreach ( array_keys( $group ) as $object_id ) {
			$results[ $object_id ] = Object_Result::failed( $class, $error->getMessage() );
		}

		return array(
			'results'      => $results,
			'paused_until' => null,
		);
	}

	/**
	 * Stores one object's vectors.
	 *
	 * @since x.x.x
	 *
	 * @param string                                                   $object_type Object type.
	 * @param int                                                      $object_id   Object ID.
	 * @param array{subtype: string, chunks: list<string>, hash: string} $item        The prepared object.
	 * @param \WordPress\AI\Embeddings\Sync\Embedding_Target           $target      The target.
	 * @param list<list<float>>                                        $vectors     One vector per chunk.
	 * @return \WordPress\AI\Embeddings\Sync\Object_Result The result.
	 */
	private function store( string $object_type, int $object_id, array $item, Embedding_Target $target, array $vectors ): Object_Result {
		try {
			$records = array();

			foreach ( array_values( $vectors ) as $chunk_index => $vector ) {
				$records[] = new Embedding_Record(
					$object_type,
					$object_id,
					$target->get_provider(),
					$target->get_model(),
					$vector,
					$chunk_index,
					$item['hash'],
					0,
					$item['subtype']
				);
			}

			$this->repository->replace_for_object( $object_type, $object_id, $target->get_provider(), $target->get_model(), $records );
		} catch ( InvalidArgumentException $e ) {
			return Object_Result::failed( Embedding_Client_Exception::ITEM, $e->getMessage() );
		} catch ( RuntimeException $e ) {
			return Object_Result::failed( Embedding_Client_Exception::TRANSIENT, $e->getMessage() );
		}

		/**
		 * Fires after an object's vectors are stored for a target.
		 *
		 * @since x.x.x
		 *
		 * @param string                                         $object_type Object type.
		 * @param int                                            $object_id   Object ID.
		 * @param \WordPress\AI\Embeddings\Sync\Embedding_Target $target      The target.
		 */
		do_action( 'wpai_embedding_sync_object_indexed', $object_type, $object_id, $target );

		return Object_Result::done();
	}

	/**
	 * Deletes an object's vectors for every model.
	 *
	 * @since x.x.x
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @return \WordPress\AI\Embeddings\Sync\Object_Result REMOVED, or a transient failure.
	 */
	private function remove( string $object_type, int $object_id ): Object_Result {
		try {
			$this->repository->delete_for_object( $object_type, $object_id );
		} catch ( RuntimeException $e ) {
			return Object_Result::failed( Embedding_Client_Exception::TRANSIENT, $e->getMessage() );
		}

		return Object_Result::removed();
	}

	/**
	 * Combines per-target outcomes into one result for the object.
	 *
	 * @since x.x.x
	 *
	 * @param list<\WordPress\AI\Embeddings\Sync\Object_Result> $outcomes Per-target outcomes.
	 * @return \WordPress\AI\Embeddings\Sync\Object_Result The combined result.
	 */
	private function combine( array $outcomes ): Object_Result {
		$failed   = null;
		$retry_at = null;
		$done     = false;

		foreach ( $outcomes as $outcome ) {
			switch ( $outcome->get_status() ) {
				case Object_Result::FAILED:
					if ( null === $failed || Embedding_Client_Exception::TRANSIENT === $outcome->get_error_class() ) {
						$failed = $outcome;
					}
					break;
				case Object_Result::DEFERRED:
					$retry_at = max( (int) $retry_at, (int) $outcome->get_retry_at() );
					break;
				case Object_Result::DONE:
					$done = true;
					break;
			}
		}

		if ( null !== $failed ) {
			return $failed;
		}

		if ( null !== $retry_at ) {
			return Object_Result::deferred( $retry_at );
		}

		return $done ? Object_Result::done() : Object_Result::skipped();
	}

	/**
	 * Returns the chunk cap.
	 *
	 * @since x.x.x
	 *
	 * @return int Maximum chunks embedded per object.
	 */
	private function get_max_chunks(): int {
		/**
		 * Filters the maximum number of chunks embedded per object.
		 *
		 * @since x.x.x
		 *
		 * @param int $max_chunks Maximum chunks. Default 50.
		 */
		return max( 1, (int) apply_filters( 'wpai_embedding_sync_max_chunks', self::DEFAULT_MAX_CHUNKS ) );
	}
}
