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
 * @since x.x.x
 */
class Embedding_Client_Exception extends RuntimeException {

	/**
	 * The provider is rate limiting: pause the provider, charge nothing.
	 *
	 * @since x.x.x
	 */
	public const RATE_LIMITED = 'rate_limited';

	/**
	 * A temporary failure: retry the objects later.
	 *
	 * @since x.x.x
	 */
	public const TRANSIENT = 'transient';

	/**
	 * Every request to the provider would fail (credentials, unknown model): pause the provider.
	 *
	 * @since x.x.x
	 */
	public const PROVIDER = 'provider';

	/**
	 * The input itself was rejected: fail the object.
	 *
	 * @since x.x.x
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
	 * @since x.x.x
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
	 * @since x.x.x
	 *
	 * @return string One of the class constants.
	 */
	public function get_error_class(): string {
		return $this->error_class;
	}
}
