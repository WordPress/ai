<?php
/**
 * Contract for opening an HTTP response body that can be read incrementally.
 *
 * @package WordPress\AI\Experiments\AI_Workspace\Streaming
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\AI_Workspace\Streaming;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Performs an HTTP request and hands back a readable body stream.
 *
 * This is the single point where bytes leave the site on the streaming path.
 * Keeping it behind an interface lets the transporter's guards be tested
 * without any network access: a test double records that it was — or, more
 * usefully, was not — called.
 *
 * @since x.x.x
 */
interface Stream_Opener_Interface {

	/**
	 * Opens a request and returns the response status, headers, and body stream.
	 *
	 * Implementations must return as soon as the response headers are available,
	 * leaving the body to be read from the returned stream as it arrives.
	 *
	 * @since x.x.x
	 *
	 * @param string                      $method  HTTP method, uppercased.
	 * @param string                      $url     Fully-qualified request URL.
	 * @param array<string, list<string>> $headers Request headers.
	 * @param string|null                 $body    Request body, or null when there is none.
	 * @param float|null                  $timeout Timeout in seconds, or null for the default.
	 * @return array{status: int, headers: array<string, list<string>>, stream: \WordPress\AiClientDependencies\Psr\Http\Message\StreamInterface}
	 *     The response status code, headers, and body stream.
	 *
	 * @throws \WordPress\AI\Experiments\AI_Workspace\Streaming\Streaming_Exception If the request cannot be opened.
	 */
	public function open( string $method, string $url, array $headers, ?string $body, ?float $timeout ): array;
}
