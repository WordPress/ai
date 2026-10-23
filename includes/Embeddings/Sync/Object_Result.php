<?php
/**
 * The outcome of processing one object.
 *
 * @package WordPress\AI\Embeddings\Sync
 */

declare( strict_types=1 );

namespace WordPress\AI\Embeddings\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * What happened to one object across every target that covers it.
 *
 * @since x.x.x
 */
final class Object_Result {

	/**
	 * At least one target was embedded and none failed or was deferred.
	 *
	 * @since x.x.x
	 */
	public const DONE = 'done';

	/**
	 * Nothing to do: every target's hash matched, or no target covers the object.
	 *
	 * @since x.x.x
	 */
	public const SKIPPED = 'skipped';

	/**
	 * The object should have no vectors, and they were deleted.
	 *
	 * @since x.x.x
	 */
	public const REMOVED = 'removed';

	/**
	 * A provider is paused; retry after `get_retry_at()` without charging an attempt.
	 *
	 * @since x.x.x
	 */
	public const DEFERRED = 'deferred';

	/**
	 * A target failed; see `get_error_class()`.
	 *
	 * @since x.x.x
	 */
	public const FAILED = 'failed';

	/**
	 * Status.
	 *
	 * @var string
	 */
	private string $status;

	/**
	 * Failure class for FAILED.
	 *
	 * @var string|null
	 */
	private ?string $error_class;

	/**
	 * Failure message.
	 *
	 * @var string
	 */
	private string $message;

	/**
	 * Retry time for DEFERRED.
	 *
	 * @var int|null
	 */
	private ?int $retry_at;

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param string      $status      Status.
	 * @param string|null $error_class Failure class.
	 * @param string      $message     Failure message.
	 * @param int|null    $retry_at    Retry time.
	 */
	private function __construct( string $status, ?string $error_class = null, string $message = '', ?int $retry_at = null ) {
		$this->status      = $status;
		$this->error_class = $error_class;
		$this->message     = $message;
		$this->retry_at    = $retry_at;
	}

	/**
	 * Creates a DONE result.
	 *
	 * @since x.x.x
	 *
	 * @return self The result.
	 */
	public static function done(): self {
		return new self( self::DONE );
	}

	/**
	 * Creates a SKIPPED result.
	 *
	 * @since x.x.x
	 *
	 * @return self The result.
	 */
	public static function skipped(): self {
		return new self( self::SKIPPED );
	}

	/**
	 * Creates a REMOVED result.
	 *
	 * @since x.x.x
	 *
	 * @return self The result.
	 */
	public static function removed(): self {
		return new self( self::REMOVED );
	}

	/**
	 * Creates a DEFERRED result.
	 *
	 * @since x.x.x
	 *
	 * @param int $retry_at Unix time to retry.
	 * @return self The result.
	 */
	public static function deferred( int $retry_at ): self {
		return new self( self::DEFERRED, null, '', $retry_at );
	}

	/**
	 * Creates a FAILED result.
	 *
	 * @since x.x.x
	 *
	 * @param string $error_class An Embedding_Client_Exception class constant.
	 * @param string $message     Failure message.
	 * @return self The result.
	 */
	public static function failed( string $error_class, string $message ): self {
		return new self( self::FAILED, $error_class, $message );
	}

	/**
	 * Returns the status.
	 *
	 * @since x.x.x
	 *
	 * @return string One of the class constants.
	 */
	public function get_status(): string {
		return $this->status;
	}

	/**
	 * Returns the failure class.
	 *
	 * @since x.x.x
	 *
	 * @return string|null The class, or null unless FAILED.
	 */
	public function get_error_class(): ?string {
		return $this->error_class;
	}

	/**
	 * Returns the failure message.
	 *
	 * @since x.x.x
	 *
	 * @return string The message, or an empty string.
	 */
	public function get_message(): string {
		return $this->message;
	}

	/**
	 * Returns the retry time.
	 *
	 * @since x.x.x
	 *
	 * @return int|null Unix time, or null unless DEFERRED.
	 */
	public function get_retry_at(): ?int {
		return $this->retry_at;
	}
}
