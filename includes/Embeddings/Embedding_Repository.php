<?php
/**
 * Database-backed storage for embedding vectors.
 *
 * @package WordPress\AI\Embeddings
 */

declare( strict_types=1 );

namespace WordPress\AI\Embeddings;

use InvalidArgumentException;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Stores embedding vectors in the plugin's embeddings table.
 *
 * Works on any database WordPress supports; vectors are kept as packed float32 bytes rather than a
 * native vector column, so similarity search over this store is done in PHP by higher-level code.
 *
 * @since x.x.x
 */
class Embedding_Repository implements Embedding_Repository_Interface {
	// Direct queries are intentional in this repository because it owns a dedicated table. The only
	// interpolated value in any query is that table's name, built from $wpdb->prefix and a constant.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	/**
	 * Upper bound on rows fetched per query when iterating.
	 */
	private const MAX_BATCH_SIZE = 1000;

	/**
	 * The schema manager.
	 *
	 * @var \WordPress\AI\Embeddings\Embedding_Schema
	 */
	private Embedding_Schema $schema;

	/**
	 * Blog IDs whose table has been seen to exist during this request.
	 *
	 * Keyed by blog ID because `Embedding_Schema::get_table_name()` reads `$wpdb->prefix`, so one
	 * repository instance reused across `switch_to_blog()` addresses a different table on each
	 * site.
	 *
	 * @var array<int, bool>
	 */
	private array $table_exists_for = array();

	/**
	 * Blog IDs whose schema has been brought up to date during this request.
	 *
	 * Deliberately separate from {@see self::$table_exists_for}: "the table exists" is not "the
	 * schema is current".
	 *
	 * @var array<int, bool>
	 */
	private array $schema_current_for = array();

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Embeddings\Embedding_Schema|null $schema Optional. The schema manager. Default a new instance.
	 */
	public function __construct( ?Embedding_Schema $schema = null ) {
		$this->schema = $schema ?? new Embedding_Schema();
	}

	/**
	 * Returns the schema manager.
	 *
	 * @since x.x.x
	 *
	 * @return \WordPress\AI\Embeddings\Embedding_Schema The schema manager.
	 */
	public function get_schema(): Embedding_Schema {
		return $this->schema;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function save( Embedding_Record $record ): Embedding_Record {
		global $wpdb;

		$this->ensure_table();

		$vector = $record->get_vector();
		$now    = current_time( 'mysql', true );
		$table  = $this->schema->get_table_name();

		$coarse = Vector_Codec::pack_coarse( $vector );

		if ( strlen( $coarse ) > Vector_Codec::MAX_COARSE_BYTES ) {
			$coarse = '';
		}

		// An upsert keyed on the unique (object_type, object_id, provider, model, chunk_index) index, so
		// re-indexing an object replaces its vector in place instead of accumulating stale rows.
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table}
					(object_type, object_id, chunk_index, provider, model, object_subtype, dimensions, embedding, embedding_norm, embedding_coarse, content_hash, created_at, updated_at)
				VALUES (%s, %d, %d, %s, %s, %s, %d, %s, %s, %s, %s, %s, %s)
				ON DUPLICATE KEY UPDATE
					object_subtype = VALUES(object_subtype),
					dimensions = VALUES(dimensions),
					embedding = VALUES(embedding),
					embedding_norm = VALUES(embedding_norm),
					embedding_coarse = VALUES(embedding_coarse),
					content_hash = VALUES(content_hash),
					updated_at = VALUES(updated_at),
					id = LAST_INSERT_ID(id)",
				$record->get_object_type(),
				$record->get_object_id(),
				$record->get_chunk_index(),
				$record->get_provider(),
				$record->get_model(),
				$record->get_object_subtype(),
				$record->get_dimensions(),
				Vector_Codec::pack( $vector ),
				(string) Vector_Codec::norm( $vector ),
				$coarse,
				$record->get_content_hash(),
				$now,
				$now
			)
		);

		if ( false === $result ) {
			throw new RuntimeException(
				esc_html(
					sprintf(
						'Failed to store embedding for %s %d: %s',
						$record->get_object_type(),
						$record->get_object_id(),
						(string) $wpdb->last_error
					)
				)
			);
		}

		return $record->with_id( (int) $wpdb->insert_id );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 *
	 * @throws \InvalidArgumentException If any entry is not an Embedding_Record.
	 */
	public function save_many( array $records ): array {
		if ( empty( $records ) ) {
			return array();
		}

		// Require the whole batch to be valid before writing any of it.
		foreach ( $records as $index => $record ) {
			if ( ! $record instanceof Embedding_Record ) {
				throw new InvalidArgumentException(
					esc_html(
						sprintf(
							'Embedding record at index %s is not an Embedding_Record instance.',
							(string) $index
						)
					)
				);
			}
		}

		$saved = array();

		foreach ( $records as $record ) {
			$saved[] = $this->save( $record );
		}

		return $saved;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function get( string $object_type, int $object_id, string $provider, string $model ): array {
		global $wpdb;

		if ( ! $this->table_available() ) {
			return array();
		}

		$table = $this->schema->get_table_name();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE object_type = %s AND object_id = %d AND provider = %s AND model = %s
				ORDER BY chunk_index ASC",
				trim( $object_type ),
				$object_id,
				trim( $provider ),
				trim( $model )
			),
			ARRAY_A
		);

		return $this->hydrate_rows( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function get_by_id( int $id ): ?Embedding_Record {
		global $wpdb;

		if ( $id <= 0 || ! $this->table_available() ) {
			return null;
		}

		$table = $this->schema->get_table_name();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d",
				$id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		$records = $this->hydrate_rows( array( $row ) );

		return $records[0] ?? null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function get_content_hash( string $object_type, int $object_id, string $provider, string $model ): ?string {
		global $wpdb;

		if ( ! $this->table_available() ) {
			return null;
		}

		$table = $this->schema->get_table_name();

		$hash = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT content_hash FROM {$table}
				WHERE object_type = %s AND object_id = %d AND provider = %s AND model = %s
				ORDER BY chunk_index ASC
				LIMIT 1",
				trim( $object_type ),
				$object_id,
				trim( $provider ),
				trim( $model )
			)
		);

		return is_string( $hash ) ? $hash : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function get_object_ids( string $object_type, string $provider, string $model, int $limit, int $offset = 0 ): array {
		global $wpdb;

		if ( $limit <= 0 || ! $this->table_available() ) {
			return array();
		}

		$table = $this->schema->get_table_name();

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT object_id FROM {$table}
				WHERE object_type = %s AND provider = %s AND model = %s
				ORDER BY object_id DESC
				LIMIT %d OFFSET %d",
				trim( $object_type ),
				trim( $provider ),
				trim( $model ),
				$limit,
				max( 0, $offset )
			)
		);

		return array_values( array_map( 'intval', is_array( $ids ) ? $ids : array() ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function count_objects( string $object_type, string $provider, string $model ): int {
		global $wpdb;

		if ( ! $this->table_available() ) {
			return 0;
		}

		$table = $this->schema->get_table_name();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT object_id) FROM {$table}
				WHERE object_type = %s AND provider = %s AND model = %s",
				trim( $object_type ),
				trim( $provider ),
				trim( $model )
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 *
	 * @throws \RuntimeException If a batch could not be read.
	 */
	public function iterate( string $provider, string $model, ?string $object_type = null, int $batch_size = 200 ): iterable {
		global $wpdb;

		if ( ! $this->table_available() ) {
			return;
		}

		$table      = $this->schema->get_table_name();
		$batch_size = max( 1, min( self::MAX_BATCH_SIZE, $batch_size ) );
		$last_id    = 0;

		// Keyset pagination on the primary key: stable under concurrent inserts and deletes, and
		// cheaper than OFFSET as the table grows.
		do {
			if ( null === $object_type ) {
				$sql = $wpdb->prepare(
					"SELECT * FROM {$table}
					WHERE provider = %s AND model = %s AND id > %d
					ORDER BY id ASC
					LIMIT %d",
					trim( $provider ),
					trim( $model ),
					$last_id,
					$batch_size
				);
			} else {
				$sql = $wpdb->prepare(
					"SELECT * FROM {$table}
					WHERE provider = %s AND model = %s AND object_type = %s AND id > %d
					ORDER BY id ASC
					LIMIT %d",
					trim( $provider ),
					trim( $model ),
					trim( $object_type ),
					$last_id,
					$batch_size
				);
			}

			$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			if ( '' !== (string) $wpdb->last_error ) {
				throw new RuntimeException(
					esc_html( 'Failed to read embedding records while iterating: ' . (string) $wpdb->last_error )
				);
			}

			$rows    = is_array( $rows ) ? $rows : array();
			$fetched = count( $rows );

			// Always advance cursor past this batch, even if all rows are corrupt.
			// Otherwise hydrate_rows() skipping all rows leaves $last_id unchanged, causing infinite loop.
			if ( $fetched > 0 ) {
				$last_id = (int) $rows[ $fetched - 1 ]['id'];
			}

			foreach ( $this->hydrate_rows( $rows ) as $record ) {
				yield $record;
			}
		} while ( $fetched === $batch_size );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 *
	 * @throws \RuntimeException If deletion failed.
	 */
	public function delete_for_object( string $object_type, int $object_id, ?string $provider = null, ?string $model = null ): int {
		global $wpdb;

		if ( ! $this->table_available() ) {
			return 0;
		}

		$where  = array(
			'object_type' => trim( $object_type ),
			'object_id'   => $object_id,
		);
		$format = array( '%s', '%d' );

		if ( null !== $provider ) {
			$where['provider'] = trim( $provider );
			$format[]          = '%s';
		}

		if ( null !== $model ) {
			$where['model'] = trim( $model );
			$format[]       = '%s';
		}

		$deleted = $wpdb->delete( $this->schema->get_table_name(), $where, $format );

		if ( false === $deleted ) {
			throw new RuntimeException( esc_html( 'Failed to delete embedding records: ' . (string) $wpdb->last_error ) );
		}

		return (int) $deleted;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 *
	 * @throws \RuntimeException If deletion failed.
	 */
	public function delete_for_model( string $provider, string $model ): int {
		global $wpdb;

		if ( ! $this->table_available() ) {
			return 0;
		}

		$deleted = $wpdb->delete(
			$this->schema->get_table_name(),
			array(
				'provider' => trim( $provider ),
				'model'    => trim( $model ),
			),
			array( '%s', '%s' )
		);

		if ( false === $deleted ) {
			throw new RuntimeException( esc_html( 'Failed to delete embedding records: ' . (string) $wpdb->last_error ) );
		}

		return (int) $deleted;
	}

	/**
	 * Makes sure the table exists before a write.
	 *
	 * @since x.x.x
	 *
	 * @throws \RuntimeException If the table could not be created.
	 */
	private function ensure_table(): void {
		$blog_id = get_current_blog_id();

		if ( isset( $this->schema_current_for[ $blog_id ] ) ) {
			return;
		}

		$this->schema->maybe_upgrade_table();

		if ( ! $this->schema->table_exists() ) {
			throw new RuntimeException( 'The embeddings table could not be created.' );
		}

		$this->schema_current_for[ $blog_id ] = true;
		$this->table_exists_for[ $blog_id ]   = true;
	}

	/**
	 * Returns whether the table exists, without trying to create it.
	 *
	 * Reads never create the table, so that a site that has never stored an embedding pays no
	 * schema cost for checking.
	 *
	 * @since x.x.x
	 *
	 * @return bool True when the table exists.
	 */
	private function table_available(): bool {
		$blog_id = get_current_blog_id();

		if ( isset( $this->table_exists_for[ $blog_id ] ) ) {
			return true;
		}

		if ( ! $this->schema->table_exists() ) {
			return false;
		}

		$this->table_exists_for[ $blog_id ] = true;

		return true;
	}

	/**
	 * Converts database rows into records, skipping rows whose vector bytes are unreadable.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $rows Database rows, as returned by `$wpdb->get_results()` with `ARRAY_A`.
	 * @return list<\WordPress\AI\Embeddings\Embedding_Record> The records.
	 */
	private function hydrate_rows( array $rows ): array {
		$records = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$dimensions = (int) ( $row['dimensions'] ?? 0 );
			$packed     = $row['embedding'] ?? '';

			if ( ! is_string( $packed ) || $dimensions <= 0 ) {
				continue;
			}

			try {
				$vector = Vector_Codec::unpack( $packed, $dimensions );

				$records[] = new Embedding_Record(
					(string) $row['object_type'],
					(int) $row['object_id'],
					(string) $row['provider'],
					(string) $row['model'],
					$vector,
					(int) $row['chunk_index'],
					(string) ( $row['content_hash'] ?? '' ),
					(int) $row['id'],
					(string) ( $row['object_subtype'] ?? '' )
				);
			} catch ( \InvalidArgumentException $e ) {
				// A corrupt row should not take the whole result set down with it.
				continue;
			}
		}

		return $records;
	}

	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
