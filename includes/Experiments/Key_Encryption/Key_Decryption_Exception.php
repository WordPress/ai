<?php
/**
 * Exception thrown when connector API keys cannot be decrypted.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Key_Encryption;

use RuntimeException;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Reports the connectors whose encrypted API key could not be restored to plaintext.
 *
 * @since x.x.x
 */
final class Key_Decryption_Exception extends RuntimeException {

	/**
	 * IDs of the connectors whose key could not be decrypted.
	 *
	 * @since x.x.x
	 * @var list<string>
	 */
	private array $connector_ids;

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param list<string> $connector_ids IDs of the connectors whose key could not be decrypted.
	 */
	public function __construct( array $connector_ids ) {
		$this->connector_ids = $connector_ids;

		parent::__construct(
			sprintf( 'Could not decrypt the API key of these connectors: %s.', implode( ', ', $connector_ids ) )
		);
	}

	/**
	 * Returns the IDs of the connectors whose key could not be decrypted.
	 *
	 * @since x.x.x
	 *
	 * @return list<string> The connector IDs.
	 */
	public function get_connector_ids(): array {
		return $this->connector_ids;
	}
}
