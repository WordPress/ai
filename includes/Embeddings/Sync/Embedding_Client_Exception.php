<?php
/**
 * A classified embedding request failure.
 *
 * @package WordPress\AI\Embeddings\Sync
 */

declare( strict_types=1 );

namespace WordPress\AI\Embeddings\Sync;

use RuntimeException;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Carries which kind of failure happened, because each kind needs a different response.
 *
 * @since 1.5.0
 */
class Embedding_Client_Exception extends RuntimeException {

	/**
	 * The provider is rate limiting: pause the provider, charge nothing.
	 *
	 * @since 1.5.0
	 */
	public const RATE_LIMITED = 'rate_limited';

	/**
	 * A temporary failure: retry the objects later.
	 *
	 * @since 1.5.0
	 */
	public const TRANSIENT = 'transient';

	/**
	 * Every request to the provider would fail (credentials, unknown model): pause the provider.
	 *
	 * @since 1.5.0
	 */
	public const PROVIDER = 'provider';

	/**
	 * The input itself was rejected: fail the object.
	 *
	 * @since 1.5.0
	 */
	public const ITEM = 'item';

	/**
	 * Failure class.
	 *
	 * @var string
	 */
	private string $error_class;

	/**
	 * Constructor.
	 *
	 * @since 1.5.0
	 *
	 * @param string          $message     Error message.
	 * @param string          $error_class One of the class constants.
	 * @param \Throwable|null $previous    Optional. The original failure. Default null.
	 */
	public function __construct( string $message, string $error_class, ?Throwable $previous = null ) {
		parent::__construct( $message, 0, $previous );

		$this->error_class = $error_class;
	}

	/**
	 * Returns the failure class.
	 *
	 * @since 1.5.0
	 *
	 * @return string One of the class constants.
	 */
	public function get_error_class(): string {
		return $this->error_class;
	}

	/**
	 * Returns the failure text unescaped, for storage.
	 *
	 * @since 1.5.0
	 *
	 * @return string The raw message.
	 */
	public function get_raw_message(): string {
		$previous = $this->getPrevious();

		return null !== $previous ? $previous->getMessage() : wp_specialchars_decode( $this->getMessage(), ENT_QUOTES );
	}
}
