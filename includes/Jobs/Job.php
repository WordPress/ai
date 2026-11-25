<?php
/**
 * A queued AI job.
 *
 * @package WordPress\AI\Jobs
 */

declare( strict_types=1 );

namespace WordPress\AI\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Value object for one row of the jobs table.
 *
 * @since 1.6.0
 */
final class Job {

	/**
	 * Unique job identifier.
	 *
	 * @var string
	 */
	private string $job_id;

	/**
	 * Registered job type.
	 *
	 * @var string
	 */
	private string $type;

	/**
	 * Arguments passed to the job type.
	 *
	 * @var array<string, mixed>
	 */
	private array $args;

	/**
	 * Current status.
	 *
	 * @var string
	 */
	private string $status;

	/**
	 * Result produced by the job type.
	 *
	 * @var mixed
	 */
	private $result;

	/**
	 * Error message when the job failed.
	 *
	 * @var string
	 */
	private string $error = '';

	/**
	 * Error code when the job failed.
	 *
	 * @var string
	 */
	private string $error_code = '';

	/**
	 * User who enqueued the job, and whose permissions are re-checked at run time.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * Number of times a runner has started the job.
	 *
	 * @var int
	 */
	private int $attempts;

	/**
	 * Optional client-supplied idempotency key.
	 *
	 * @var string
	 */
	private string $idempotency_key = '';

	/**
	 * Creation time, as a Unix timestamp.
	 *
	 * @var int
	 */
	private int $created_at;

	/**
	 * Last update time, as a Unix timestamp.
	 *
	 * @var int
	 */
	private int $updated_at;

	/**
	 * Time the current run started, as a Unix timestamp, or 0.
	 *
	 * @var int
	 */
	private int $started_at = 0;

	/**
	 * Time the job reached a final status, as a Unix timestamp, or 0.
	 *
	 * @var int
	 */
	private int $finished_at = 0;

	/**
	 * Time after which the row may be purged, as a Unix timestamp.
	 *
	 * @var int
	 */
	private int $expires_at;

	/**
	 * Constructor.
	 *
	 * @since 1.6.0
	 *
	 * @param string               $job_id  Unique job identifier.
	 * @param string               $type    Registered job type.
	 * @param array<string, mixed> $args    Arguments passed to the job type.
	 * @param int                  $user_id User who enqueued the job.
	 * @param int                  $now     Optional. Current Unix time. Default now.
	 */
	public function __construct( string $job_id, string $type, array $args, int $user_id, ?int $now = null ) {
		$now = $now ?? time();

		$this->job_id     = $job_id;
		$this->type       = $type;
		$this->args       = $args;
		$this->status     = Job_Status::ENQUEUED;
		$this->result     = null;
		$this->user_id    = $user_id;
		$this->attempts   = 0;
		$this->created_at = $now;
		$this->updated_at = $now;
		$this->expires_at = $now + (int) DAY_IN_SECONDS;
	}

	/**
	 * Creates a job from a database row.
	 *
	 * @since 1.6.0
	 *
	 * @param array<string, mixed> $row The database row.
	 * @return self The job.
	 */
	public static function from_row( array $row ): self {
		$job = new self(
			(string) $row['job_id'],
			(string) $row['type'],
			self::decode_array( (string) ( $row['args'] ?? '' ) ),
			(int) ( $row['user_id'] ?? 0 ),
			self::to_timestamp( (string) ( $row['created_at'] ?? '' ) )
		);

		$job->status          = (string) ( $row['status'] ?? Job_Status::ENQUEUED );
		$job->result          = self::decode_json( (string) ( $row['result'] ?? '' ) );
		$job->error           = (string) ( $row['error'] ?? '' );
		$job->error_code      = (string) ( $row['error_code'] ?? '' );
		$job->attempts        = (int) ( $row['attempts'] ?? 0 );
		$job->idempotency_key = (string) ( $row['idempotency_key'] ?? '' );
		$job->updated_at      = self::to_timestamp( (string) ( $row['updated_at'] ?? '' ) );
		$job->started_at      = self::to_timestamp( (string) ( $row['started_at'] ?? '' ) );
		$job->finished_at     = self::to_timestamp( (string) ( $row['finished_at'] ?? '' ) );
		$job->expires_at      = self::to_timestamp( (string) ( $row['expires_at'] ?? '' ) );

		return $job;
	}

	/**
	 * Returns the job ID.
	 *
	 * @since 1.6.0
	 *
	 * @return string The job ID.
	 */
	public function get_job_id(): string {
		return $this->job_id;
	}

	/**
	 * Returns the job type.
	 *
	 * @since 1.6.0
	 *
	 * @return string The job type.
	 */
	public function get_type(): string {
		return $this->type;
	}

	/**
	 * Returns the job arguments.
	 *
	 * @since 1.6.0
	 *
	 * @return array<string, mixed> The arguments.
	 */
	public function get_args(): array {
		return $this->args;
	}

	/**
	 * Returns the status.
	 *
	 * @since 1.6.0
	 *
	 * @return string The status.
	 */
	public function get_status(): string {
		return $this->status;
	}

	/**
	 * Returns the result.
	 *
	 * @since 1.6.0
	 *
	 * @return mixed The result, or null.
	 */
	public function get_result() {
		return $this->result;
	}

	/**
	 * Returns the error message.
	 *
	 * @since 1.6.0
	 *
	 * @return string The error message, or an empty string.
	 */
	public function get_error(): string {
		return $this->error;
	}

	/**
	 * Returns the error code.
	 *
	 * @since 1.6.0
	 *
	 * @return string The error code, or an empty string.
	 */
	public function get_error_code(): string {
		return $this->error_code;
	}

	/**
	 * Returns the user ID.
	 *
	 * @since 1.6.0
	 *
	 * @return int The user ID.
	 */
	public function get_user_id(): int {
		return $this->user_id;
	}

	/**
	 * Returns the attempt count.
	 *
	 * @since 1.6.0
	 *
	 * @return int The attempts.
	 */
	public function get_attempts(): int {
		return $this->attempts;
	}

	/**
	 * Returns the idempotency key.
	 *
	 * @since 1.6.0
	 *
	 * @return string The key, or an empty string.
	 */
	public function get_idempotency_key(): string {
		return $this->idempotency_key;
	}

	/**
	 * Returns the creation time.
	 *
	 * @since 1.6.0
	 *
	 * @return int Unix timestamp.
	 */
	public function get_created_at(): int {
		return $this->created_at;
	}

	/**
	 * Returns the last update time.
	 *
	 * @since 1.6.0
	 *
	 * @return int Unix timestamp.
	 */
	public function get_updated_at(): int {
		return $this->updated_at;
	}

	/**
	 * Returns the time the current run started.
	 *
	 * @since 1.6.0
	 *
	 * @return int Unix timestamp, or 0.
	 */
	public function get_started_at(): int {
		return $this->started_at;
	}

	/**
	 * Returns the time the job reached a final status.
	 *
	 * @since 1.6.0
	 *
	 * @return int Unix timestamp, or 0.
	 */
	public function get_finished_at(): int {
		return $this->finished_at;
	}

	/**
	 * Returns the time the row may be purged.
	 *
	 * @since 1.6.0
	 *
	 * @return int Unix timestamp.
	 */
	public function get_expires_at(): int {
		return $this->expires_at;
	}

	/**
	 * Returns whether the job has reached a final status.
	 *
	 * @since 1.6.0
	 *
	 * @return bool True when final.
	 */
	public function is_terminal(): bool {
		return Job_Status::is_terminal( $this->status );
	}

	/**
	 * Sets the idempotency key.
	 *
	 * @since 1.6.0
	 *
	 * @param string $key The key.
	 */
	public function set_idempotency_key( string $key ): void {
		$this->idempotency_key = $key;
	}

	/**
	 * Sets the time the row may be purged.
	 *
	 * @since 1.6.0
	 *
	 * @param int $expires_at Unix timestamp.
	 */
	public function set_expires_at( int $expires_at ): void {
		$this->expires_at = $expires_at;
	}

	/**
	 * Marks the job as running.
	 *
	 * @since 1.6.0
	 *
	 * @param int|null $now Optional. Unix time. Default now.
	 */
	public function mark_running( ?int $now = null ): void {
		$now = $now ?? time();

		++$this->attempts;
		$this->status     = Job_Status::RUNNING;
		$this->started_at = $now;
		$this->updated_at = $now;
	}

	/**
	 * Marks the job as completed.
	 *
	 * @since 1.6.0
	 *
	 * @param mixed    $result The result.
	 * @param int|null $now    Optional. Unix time. Default now.
	 */
	public function mark_completed( $result, ?int $now = null ): void {
		$now = $now ?? time();

		$this->status      = Job_Status::COMPLETED;
		$this->result      = $result;
		$this->error       = '';
		$this->error_code  = '';
		$this->finished_at = $now;
		$this->updated_at  = $now;
	}

	/**
	 * Marks the job as failed.
	 *
	 * @since 1.6.0
	 *
	 * @param string   $code    The error code.
	 * @param string   $message The error message.
	 * @param int|null $now     Optional. Unix time. Default now.
	 */
	public function mark_failed( string $code, string $message, ?int $now = null ): void {
		$now = $now ?? time();

		$this->status      = Job_Status::FAILED;
		$this->error       = $message;
		$this->error_code  = $code;
		$this->finished_at = $now;
		$this->updated_at  = $now;
	}

	/**
	 * Returns the row shape written to the database.
	 *
	 * @since 1.6.0
	 *
	 * @return array<string, mixed> The row.
	 */
	public function to_row(): array {
		return array(
			'job_id'          => $this->job_id,
			'type'            => $this->type,
			'args'            => (string) wp_json_encode( $this->args ),
			'status'          => $this->status,
			'result'          => null === $this->result ? null : (string) wp_json_encode( $this->result ),
			'error'           => '' === $this->error ? null : $this->error,
			'error_code'      => '' === $this->error_code ? null : $this->error_code,
			'user_id'         => $this->user_id,
			'attempts'        => $this->attempts,
			'idempotency_key' => '' === $this->idempotency_key ? null : $this->idempotency_key,
			'created_at'      => self::to_datetime( $this->created_at ),
			'updated_at'      => self::to_datetime( $this->updated_at ),
			'started_at'      => 0 === $this->started_at ? null : self::to_datetime( $this->started_at ),
			'finished_at'     => 0 === $this->finished_at ? null : self::to_datetime( $this->finished_at ),
			'expires_at'      => self::to_datetime( $this->expires_at ),
		);
	}

	/**
	 * Returns the public REST representation of the job.
	 *
	 * Arguments are excluded on purpose: they may contain post content or
	 * other data the polling client already has.
	 *
	 * @since 1.6.0
	 *
	 * @return array<string, mixed> The response payload.
	 */
	public function to_array(): array {
		return array(
			'job_id'      => $this->job_id,
			'type'        => $this->type,
			'status'      => $this->status,
			'result'      => $this->result,
			'error'       => '' === $this->error ? null : array(
				'code'    => $this->error_code,
				'message' => $this->error,
			),
			'attempts'    => $this->attempts,
			'created_at'  => gmdate( 'c', $this->created_at ),
			'updated_at'  => gmdate( 'c', $this->updated_at ),
			'started_at'  => 0 === $this->started_at ? null : gmdate( 'c', $this->started_at ),
			'finished_at' => 0 === $this->finished_at ? null : gmdate( 'c', $this->finished_at ),
		);
	}

	/**
	 * Converts a MySQL datetime into a Unix timestamp.
	 *
	 * @since 1.6.0
	 *
	 * @param string $value The datetime, in UTC.
	 * @return int Unix timestamp, or 0 when empty or invalid.
	 */
	private static function to_timestamp( string $value ): int {
		if ( '' === $value ) {
			return 0;
		}

		$timestamp = strtotime( $value . ' UTC' );

		return false === $timestamp ? 0 : $timestamp;
	}

	/**
	 * Converts a Unix timestamp into a MySQL datetime.
	 *
	 * @since 1.6.0
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string The datetime, in UTC.
	 */
	private static function to_datetime( int $timestamp ): string {
		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Decodes a JSON value without assuming its type.
	 *
	 * @since 1.6.0
	 *
	 * @param string $value The JSON string.
	 * @return mixed The decoded value.
	 */
	private static function decode_json( string $value ) {
		if ( '' === $value ) {
			return null;
		}

		$decoded = json_decode( $value, true );

		return JSON_ERROR_NONE === json_last_error() ? $decoded : null;
	}

	/**
	 * Decodes a JSON object into an array.
	 *
	 * @since 1.6.0
	 *
	 * @param string $value The JSON string.
	 * @return array<string, mixed> The decoded array.
	 */
	private static function decode_array( string $value ): array {
		$decoded = self::decode_json( $value );

		return is_array( $decoded ) ? $decoded : array();
	}
}
