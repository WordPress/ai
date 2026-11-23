<?php
/**
 * Database-backed job store.
 *
 * @package WordPress\AI\Jobs
 */

declare( strict_types=1 );

namespace WordPress\AI\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes job rows.
 *
 * @since 1.6.0
 */
class Job_Store {
	// The only interpolated value in any query is the table name, built from $wpdb->prefix and a constant.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	/**
	 * Schema manager.
	 *
	 * @var \WordPress\AI\Jobs\Job_Schema
	 */
	private Job_Schema $schema;

	/**
	 * Whether the table is known to be available during this request.
	 *
	 * @var bool|null
	 */
	private ?bool $table_available = null;

	/**
	 * Constructor.
	 *
	 * @since 1.6.0
	 *
	 * @param \WordPress\AI\Jobs\Job_Schema|null $schema Optional. Schema manager. Default a new instance.
	 */
	public function __construct( ?Job_Schema $schema = null ) {
		$this->schema = $schema ?? new Job_Schema();
	}

	/**
	 * Returns the schema manager.
	 *
	 * @since 1.6.0
	 *
	 * @return \WordPress\AI\Jobs\Job_Schema The schema manager.
	 */
	public function get_schema(): Job_Schema {
		return $this->schema;
	}

	/**
	 * Stores a new job.
	 *
	 * @since 1.6.0
	 *
	 * @param \WordPress\AI\Jobs\Job $job The job.
	 * @return bool True when inserted.
	 */
	public function create( Job $job ): bool {
		global $wpdb;

		if ( ! $this->ensure_table() ) {
			return false;
		}

		$row = $job->to_row();

		$result = $wpdb->insert( $this->schema->get_table_name(), $row );

		return false !== $result;
	}

	/**
	 * Finds a job by ID.
	 *
	 * @since 1.6.0
	 *
	 * @param string $job_id The job ID.
	 * @return \WordPress\AI\Jobs\Job|null The job, or null when missing.
	 */
	public function find( string $job_id ): ?Job {
		global $wpdb;

		if ( ! $this->ensure_table() ) {
			return null;
		}

		$table = $this->schema->get_table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE job_id = %s LIMIT 1", $job_id ),
			ARRAY_A
		);

		return is_array( $row ) ? Job::from_row( $row ) : null;
	}

	/**
	 * Finds a job by idempotency key.
	 *
	 * @since 1.6.0
	 *
	 * @param string $type    The job type.
	 * @param string $key     The idempotency key.
	 * @param int    $user_id The user who enqueued the job.
	 * @return \WordPress\AI\Jobs\Job|null The job, or null when missing.
	 */
	public function find_by_idempotency_key( string $type, string $key, int $user_id ): ?Job {
		global $wpdb;

		if ( '' === $key || ! $this->ensure_table() ) {
			return null;
		}

		$table = $this->schema->get_table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE type = %s AND idempotency_key = %s AND user_id = %d ORDER BY id ASC LIMIT 1",
				$type,
				$key,
				$user_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? Job::from_row( $row ) : null;
	}

	/**
	 * Updates an existing job.
	 *
	 * @since 1.6.0
	 *
	 * @param \WordPress\AI\Jobs\Job $job             The job.
	 * @param string|null            $expected_status Optional. Only update when the stored status still matches.
	 * @return bool True when a row was updated.
	 */
	public function update( Job $job, ?string $expected_status = null ): bool {
		global $wpdb;

		if ( ! $this->ensure_table() ) {
			return false;
		}

		$where = array( 'job_id' => $job->get_job_id() );

		if ( null !== $expected_status ) {
			$where['status'] = $expected_status;
		}

		$result = $wpdb->update(
			$this->schema->get_table_name(),
			$job->to_row(),
			$where
		);

		return false !== $result && $result > 0;
	}

	/**
	 * Deletes a job.
	 *
	 * @since 1.6.0
	 *
	 * @param string $job_id The job ID.
	 * @return bool True when a row was deleted.
	 */
	public function delete( string $job_id ): bool {
		global $wpdb;

		if ( ! $this->ensure_table() ) {
			return false;
		}

		$result = $wpdb->delete( $this->schema->get_table_name(), array( 'job_id' => $job_id ) );

		return false !== $result && $result > 0;
	}

	/**
	 * Deletes expired jobs.
	 *
	 * @since 1.6.0
	 *
	 * @param int      $limit Maximum rows to delete.
	 * @param int|null $now   Optional. Unix time. Default now.
	 * @return int Number of rows deleted.
	 */
	public function delete_expired( int $limit = 200, ?int $now = null ): int {
		global $wpdb;

		if ( $limit <= 0 || ! $this->ensure_table() ) {
			return 0;
		}

		$table = $this->schema->get_table_name();

		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE expires_at <= %s ORDER BY id ASC LIMIT %d",
				gmdate( 'Y-m-d H:i:s', $now ?? time() ),
				$limit
			)
		);

		return false === $deleted ? 0 : (int) $deleted;
	}

	/**
	 * Makes sure the table exists before it is used.
	 *
	 * @since 1.6.0
	 *
	 * @return bool True when the table can be used.
	 */
	private function ensure_table(): bool {
		if ( null !== $this->table_available ) {
			return $this->table_available;
		}

		$this->schema->maybe_upgrade_table();
		$this->table_available = $this->schema->table_exists();

		return $this->table_available;
	}
}
