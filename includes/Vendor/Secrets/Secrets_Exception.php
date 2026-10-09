<?php
/**
 * Secrets Exception
 *
 * @package Displace_Secrets_Manager
 */

namespace WordPress\AI\Vendor\Secrets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exception thrown by secrets providers on backend errors.
 *
 * Extends RuntimeException so callers can catch either this specific
 * type or the broader RuntimeException family.
 */
class Secrets_Exception extends \RuntimeException {

	/**
	 * The secret key that triggered the error, if applicable.
	 *
	 * @var string
	 */
	protected $secret_key;

	/**
	 * The provider ID where the error occurred, if applicable.
	 *
	 * @var string
	 */
	protected $provider_id;

	/**
	 * Constructor.
	 *
	 * @param string          $message     Error message (must never contain secret values).
	 * @param int             $code        Error code.
	 * @param Throwable|null  $previous    Previous exception for chaining.
	 * @param string          $secret_key  The key involved, if applicable.
	 * @param string          $provider_id The provider where the error occurred.
	 */
	public function __construct(
		string $message = '',
		int $code = 0,
		?\Throwable $previous = null,
		string $secret_key = '',
		string $provider_id = ''
	) {
		parent::__construct( $message, $code, $previous );
		$this->secret_key  = $secret_key;
		$this->provider_id = $provider_id;
	}

	/**
	 * Get the secret key that triggered the error.
	 *
	 * @return string
	 */
	public function get_secret_key(): string {
		return $this->secret_key;
	}

	/**
	 * Get the provider ID where the error occurred.
	 *
	 * @return string
	 */
	public function get_provider_id(): string {
		return $this->provider_id;
	}
}
