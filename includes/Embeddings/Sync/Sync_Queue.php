<?php
/**
 * Database-backed queue of objects waiting for embedding.
 *
 * @package WordPress\AI\Embeddings\Sync
 */

declare( strict_types=1 );

namespace WordPress\AI\Embeddings\Sync;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Stores and hands out queued objects.
 *
 * @since x.x.x
 */
class Sync_Queue {
	// The only interpolated value in any query is the table name, built from $wpdb->prefix and a constant.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	/**
	 * Status of a row waiting to be processed.
	 *
	 * @since x.x.x
	 */
	public const STATUS_PENDING = 'pending';

	/**
	 * Status of a row that ran out of attempts or failed permanently.
	 *
	 * @since x.x.x
	 */
	public const STATUS_FAILED = 'failed';

	/**
	 * The schema manager.
	 *
	 * @var \WordPress\AI\Embeddings\Sync\Sync_Queue_Schema
	 */
	private Sync_Queue_Schema $schema;

	/**
	 * Blog IDs whose table is known to exist during this request.
	 *
	 * @var array<int, bool>
	 */
	private array $table_exists_for = array();

	/**
	 * Blog IDs whose schema was brought up to date during this request.
	 *
	 * @var array<int, bool>
	 */
	private array $schema_current_for = array();

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Embeddings\Sync\Sync_Queue_Schema|null $schema Optional. The schema manager. Default a new instance.
	 */
	public function __construct( ?Sync_Queue_Schema $schema = null ) {
		$this->schema = $schema ?? new Sync_Queue_Schema();
	}

	/**
	 * Returns the schema manager.
	 *
	 * @since x.x.x
	 *
	 * @return \WordPress\AI\Embeddings\Sync\Sync_Queue_Schema The schema manager.
	 */
	public function get_schema(): Sync_Queue_Schema {
		return $this->schema;
	}

	/**
	 * Queues an object, or refreshes its existing row.
	 *
	 * @since x.x.x
	 *
	 * @param string   $object_type Object type.
	 * @param int      $object_id   Object ID.
	 * @param int|null $now         Optional. Unix time the row becomes due. Default now.
	 *
	 * @throws \RuntimeException If the write failed.
	 */
	public function enqueue( string $object_type, int $object_id, ?int $now = null ): void {
		global $wpdb;

		$this->ensure_table();

		$table = $this->schema->get_table_name();
		$time  = $this->to_datetime( $now ?? time() );

		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table}
					(object_type, object_id, status, version, attempts, available_at, last_error, created_at, updated_at)
				VALUES (%s, %d, %s, 1, 0, %s, NULL, %s, %s)
				ON DUPLICATE KEY UPDATE
					version = version + 1,
					status = VALUES(status),
					attempts = 0,
					available_at = VALUES(available_at),
					last_error = NULL,
					updated_at = VALUES(updated_at)",
				$object_type,
				$object_id,
				self::STATUS_PENDING,
				$time,
				$time,
				$time
			)
		);

		if ( false === $result ) {
			throw new RuntimeException( esc_html( 'Failed to queue object for embedding: ' . (string) $wpdb->last_error ) );
		}
	}

	/**
	 * Returns pending rows that are due, oldest first.
	 *
	 * @since x.x.x
	 *
	 * @param int      $limit Maximum rows.
	 * @param int|null $now   Optional. Unix time to compare against. Default now.
	 * @return list<\WordPress\AI\Embeddings\Sync\Sync_Queue_Item> The due rows.
	 */
	public function claim_due( int $limit, ?int $now = null ): array {
		global $wpdb;

		if ( $limit <= 0 || ! $this->table_available() ) {
			return array();
		}

		$table = $this->schema->get_table_name();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, object_type, object_id, version, attempts FROM {$table}
				WHERE status = %s AND available_at <= %s
				ORDER BY available_at ASC, id ASC
				LIMIT %d",
				self::STATUS_PENDING,
				$this->to_datetime( $now ?? time() ),
				$limit
			),
			ARRAY_A
		);

		$items = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$items[] = Sync_Queue_Item::from_row( $row );
		}

		return $items;
	}

	/**
	 * Deletes a processed row, unless the object was re-queued since it was read.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Embeddings\Sync\Sync_Queue_Item $item The claimed row.
	 * @return bool True when the row was deleted.
	 */
	public function complete( Sync_Queue_Item $item ): bool {
		global $wpdb;

		$table = $this->schema->get_table_name();

		return 1 === (int) $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE id = %d AND version = %d", $item->get_id(), $item->get_version() )
		);
	}

	/**
	 * Charges an attempt and delays the row.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Embeddings\Sync\Sync_Queue_Item $item         The claimed row.
	 * @param int                                           $available_at Unix time the row is due again.
	 * @param string                                        $error        Error message to record.
	 * @return bool True when the row was updated.
	 */
	public function defer( Sync_Queue_Item $item, int $available_at, string $error ): bool {
		global $wpdb;

		$table = $this->schema->get_table_name();

		return 1 === (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET attempts = attempts + 1, available_at = %s, last_error = %s, updated_at = %s
				WHERE id = %d AND version = %d",
				$this->to_datetime( $available_at ),
				$error,
				$this->to_datetime( time() ),
				$item->get_id(),
				$item->get_version()
			)
		);
	}

	/**
	 * Delays the row without charging an attempt.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Embeddings\Sync\Sync_Queue_Item $item         The claimed row.
	 * @param int                                           $available_at Unix time the row is due again.
	 * @return bool True when the row was updated.
	 */
	public function postpone( Sync_Queue_Item $item, int $available_at ): bool {
		global $wpdb;

		$table = $this->schema->get_table_name();

		return 1 === (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET available_at = %s, updated_at = %s WHERE id = %d AND version = %d",
				$this->to_datetime( $available_at ),
				$this->to_datetime( time() ),
				$item->get_id(),
				$item->get_version()
			)
		);
	}

	/**
	 * Marks the row failed; it is kept, counted and skipped until retried or re-queued.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Embeddings\Sync\Sync_Queue_Item $item  The claimed row.
	 * @param string                                        $error Error message to record.
	 * @return bool True when the row was updated.
	 */
	public function fail( Sync_Queue_Item $item, string $error ): bool {
		global $wpdb;

		$table = $this->schema->get_table_name();

		return 1 === (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, attempts = attempts + 1, last_error = %s, updated_at = %s
				WHERE id = %d AND version = %d",
				self::STATUS_FAILED,
				$error,
				$this->to_datetime( time() ),
				$item->get_id(),
				$item->get_version()
			)
		);
	}

	/**
	 * Removes an object's row whatever its state.
	 *
	 * @since x.x.x
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 *
	 * @throws \RuntimeException If the delete failed.
	 */
	public function remove_object( string $object_type, int $object_id ): void {
		global $wpdb;

		if ( ! $this->table_available() ) {
			return;
		}

		$deleted = $wpdb->delete(
			$this->schema->get_table_name(),
			array(
				'object_type' => $object_type,
				'object_id'   => $object_id,
			),
			array( '%s', '%d' )
		);

		if ( false === $deleted ) {
			throw new RuntimeException( esc_html( 'Failed to remove queued object: ' . (string) $wpdb->last_error ) );
		}
	}

	/**
	 * Returns every failed row to pending with attempts reset.
	 *
	 * @since x.x.x
	 *
	 * @param int|null $now Optional. Unix time the rows become due. Default now.
	 * @return int Rows requeued.
	 */
	public function retry_failed( ?int $now = null ): int {
		global $wpdb;

		if ( ! $this->table_available() ) {
			return 0;
		}

		$table = $this->schema->get_table_name();
		$time  = $this->to_datetime( $now ?? time() );

		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, attempts = 0, available_at = %s, last_error = NULL, updated_at = %s WHERE status = %s",
				self::STATUS_PENDING,
				$time,
				$time,
				self::STATUS_FAILED
			)
		);
	}

	/**
	 * Counts rows by status.
	 *
	 * @since x.x.x
	 *
	 * @return array{pending: int, failed: int} Row counts.
	 */
	public function count_by_status(): array {
		global $wpdb;

		$counts = array(
			'pending' => 0,
			'failed'  => 0,
		);

		if ( ! $this->table_available() ) {
			return $counts;
		}

		$table = $this->schema->get_table_name();
		$rows  = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status", ARRAY_A );

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( self::STATUS_PENDING === $row['status'] ) {
				$counts['pending'] = (int) $row['total'];
			} elseif ( self::STATUS_FAILED === $row['status'] ) {
				$counts['failed'] = (int) $row['total'];
			}
		}

		return $counts;
	}

	/**
	 * Returns when the earliest pending row is due.
	 *
	 * @since x.x.x
	 *
	 * @return int|null Unix time, or null when nothing is pending.
	 */
	public function get_next_available_at(): ?int {
		global $wpdb;

		if ( ! $this->table_available() ) {
			return null;
		}

		$table = $this->schema->get_table_name();
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT MIN(available_at) FROM {$table} WHERE status = %s", self::STATUS_PENDING ) );

		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}

		$timestamp = strtotime( $value . ' UTC' );

		return false === $timestamp ? null : $timestamp;
	}

	/**
	 * Formats a Unix time as a UTC DATETIME string.
	 *
	 * @since x.x.x
	 *
	 * @param int $timestamp Unix time.
	 * @return string The DATETIME value.
	 */
	private function to_datetime( int $timestamp ): string {
		return gmdate( 'Y-m-d H:i:s', $timestamp );
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
			throw new RuntimeException( 'The embedding sync queue table could not be created.' );
		}

		$this->schema_current_for[ $blog_id ] = true;
		$this->table_exists_for[ $blog_id ]   = true;
	}

	/**
	 * Returns whether the table exists, without creating it.
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
}
