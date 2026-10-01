<?php
/**
 * Exception raised by the AI Workspace streaming transport and mappers.
 *
 * @package WordPress\AI\Experiments\AI_Workspace\Streaming
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\AI_Workspace\Streaming;

use RuntimeException;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Signals a streaming request that could not be started, or a stream that ended badly.
 *
 * A single exception type covers both the transport and the provider mapper so a
 * caller can catch one thing and fall back to a buffered request. The two cases
 * are distinguished by the error code.
 *
 * @since x.x.x
 */
class Streaming_Exception extends RuntimeException {

	/**
	 * The caller is not approved to use the connector the request carries a credential for.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	public const CODE_NOT_APPROVED = 1;

	/**
	 * The streaming transport could not be started.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	public const CODE_TRANSPORT = 2;

	/**
	 * The provider reported an error inside the stream.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	public const CODE_PROVIDER_ERROR = 3;

	/**
	 * The stream ended before the provider signalled completion.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	public const CODE_TRUNCATED = 4;

	/**
	 * The stream carried an event this mapper cannot interpret.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	public const CODE_MALFORMED = 5;

	/**
	 * The upstream response was not successful.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	public const CODE_HTTP_STATUS = 6;
}
