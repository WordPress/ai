<?php
/**
 * Stream opener backed by the HTTP stream wrapper.
 *
 * @package WordPress\AI\Experiments\AI_Workspace\Streaming
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\AI_Workspace\Streaming;

use WordPress\AiClientDependencies\Nyholm\Psr7\Stream;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Opens a request with `fopen()` over a configured HTTP stream context.
 *
 * `wp_safe_remote_request()` cannot stream: WordPress's HTTP API buffers the
 * whole body before returning, and the SDK transporter that core ships is
 * built on it. PHP's own HTTP stream wrapper is the smallest mechanism that
 * gives a genuinely lazy body: `fopen()` returns once the response headers
 * have arrived and the body is read on demand from the returned handle, with
 * no polling loop or userland buffer of our own.
 *
 * The cost is a dependency on the `allow_url_fopen` INI setting and on the
 * `https` stream wrapper being registered. Both are checked up front and
 * reported as a `Streaming_Exception`, which callers treat as "this site
 * cannot stream" and answer with a buffered request.
 *
 * Because this path does not go through `wp_safe_remote_request()`, it also
 * loses that function's SSRF protections. `wp_http_validate_url()` — the same
 * validator `wp_safe_remote_request()` uses — is applied to the URL before the
 * connection is opened, and redirects are refused rather than followed so that
 * the only host ever contacted is the one that validator approved.
 *
 * @since x.x.x
 */
final class Fopen_Stream_Opener implements Stream_Opener_Interface {

	/**
	 * Default request timeout in seconds.
	 *
	 * @since x.x.x
	 *
	 * @var float
	 */
	private const DEFAULT_TIMEOUT = 60.0;

	/**
	 * {@inheritDoc}
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
	public function open( string $method, string $url, array $headers, ?string $body, ?float $timeout ): array {
		self::assert_supported();

		if ( ! wp_http_validate_url( $url ) ) {
			throw new Streaming_Exception(
				esc_html__( 'The streaming request URL was rejected as unsafe.', 'ai' ),
				Streaming_Exception::CODE_TRANSPORT // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the flagged argument is the integer error code, which is branched on rather than rendered.
			);
		}

		$context = stream_context_create( self::build_context_options( $method, $headers, $body, $timeout ) );

		/*
		 * The WordPress HTTP API buffers the whole body and cannot stream, so this is
		 * a network read through the HTTP stream wrapper rather than a filesystem
		 * operation. Errors are silenced because a failed connection is reported as an
		 * exception below; a raw PHP warning would also break output-strict callers.
		 */
		$handle = @fopen( $url, 'rb', false, $context ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Forbidden, WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( false === $handle ) {
			throw new Streaming_Exception(
				esc_html__( 'The streaming connection could not be opened.', 'ai' ),
				Streaming_Exception::CODE_TRANSPORT // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the flagged argument is the integer error code, which is branched on rather than rendered.
			);
		}

		$meta         = stream_get_meta_data( $handle );
		$wrapper_data = isset( $meta['wrapper_data'] ) && is_array( $meta['wrapper_data'] ) ? $meta['wrapper_data'] : array();

		[ $status, $response_headers ] = self::parse_wrapper_data( $wrapper_data );

		if ( self::is_redirect( $status ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- this closes the network stream opened above, not a file.

			throw new Streaming_Exception(
				esc_html__( 'The streaming request was redirected, which is not followed.', 'ai' ),
				Streaming_Exception::CODE_TRANSPORT // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the flagged argument is the integer error code, which is branched on rather than rendered.
			);
		}

		return array(
			'status'  => $status,
			'headers' => $response_headers,
			'stream'  => Stream::create( $handle ),
		);
	}

	/**
	 * Reports whether this environment can open a streaming HTTP connection.
	 *
	 * @since x.x.x
	 *
	 * @return bool True when `allow_url_fopen` is on and the `https` wrapper is registered.
	 */
	public static function is_supported(): bool {
		if ( ! filter_var( ini_get( 'allow_url_fopen' ), FILTER_VALIDATE_BOOLEAN ) ) {
			return false;
		}

		return in_array( 'https', stream_get_wrappers(), true );
	}

	/**
	 * Throws when the environment cannot open a streaming HTTP connection.
	 *
	 * @since x.x.x
	 *
	 * @throws \WordPress\AI\Experiments\AI_Workspace\Streaming\Streaming_Exception If streaming is unsupported.
	 */
	private static function assert_supported(): void {
		if ( self::is_supported() ) {
			return;
		}

		throw new Streaming_Exception(
			esc_html__( 'This server cannot stream AI responses: the HTTPS stream wrapper is unavailable.', 'ai' ),
			Streaming_Exception::CODE_TRANSPORT // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the flagged argument is the integer error code, which is branched on rather than rendered.
		);
	}

	/**
	 * Builds the stream context options for one request.
	 *
	 * Redirects are deliberately not followed. The `header` string built here carries
	 * the provider credential, and PHP replays the whole context header block to every
	 * redirect target, while `wp_http_validate_url()` in `open()` only ever saw the
	 * first URL. Following a redirect would therefore hand the API key to a host
	 * WordPress never vetted — the SSRF and credential-egress hole that
	 * `wp_safe_remote_request()` exists to close.
	 *
	 * Not following is preferred over re-validating each hop because the provider's
	 * messages endpoint does not redirect in normal operation: a 3xx here is a
	 * misconfigured host or an interception attempt, and either way the caller is
	 * better served by falling back to a buffered request than by chasing the hop.
	 *
	 * @since x.x.x
	 *
	 * @param string                      $method  HTTP method, uppercased.
	 * @param array<string, list<string>> $headers Request headers.
	 * @param string|null                 $body    Request body, or null when there is none.
	 * @param float|null                  $timeout Timeout in seconds, or null for the default.
	 * @return array{http: array<string, mixed>} The stream context options.
	 */
	private static function build_context_options( string $method, array $headers, ?string $body, ?float $timeout ): array {
		return array(
			'http' => array(
				'method'           => $method,
				'header'           => self::format_headers( $headers ),
				'content'          => null === $body ? '' : $body,
				'protocol_version' => 1.1,
				'ignore_errors'    => true,
				'follow_location'  => 0,
				// PHP counts the original request, so 1 means "this request and nothing after it".
				'max_redirects'    => 1,
				'timeout'          => null === $timeout ? self::DEFAULT_TIMEOUT : $timeout,
			),
		);
	}

	/**
	 * Reports whether a status code is a redirect this opener refuses to follow.
	 *
	 * @since x.x.x
	 *
	 * @param int $status The HTTP status code.
	 * @return bool True when the response is a 3xx.
	 */
	private static function is_redirect( int $status ): bool {
		return $status >= 300 && $status < 400;
	}

	/**
	 * Formats a header map into the wrapper's CRLF-joined header string.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, list<string>> $headers Request headers.
	 * @return string The formatted headers.
	 */
	private static function format_headers( array $headers ): string {
		$lines = array();

		foreach ( $headers as $name => $values ) {
			$value = implode( ', ', $values );

			// A header value containing a line break would let a caller inject extra headers.
			if ( 1 === preg_match( '/[\r\n]/', $name . $value ) ) {
				continue;
			}

			$lines[] = $name . ': ' . $value;
		}

		return implode( "\r\n", $lines );
	}

	/**
	 * Reads the status code and headers out of the wrapper's raw header lines.
	 *
	 * `wrapper_data` can hold more than one status line — an interim `100 Continue`
	 * precedes the real one — so the last status line and the headers that follow it
	 * are the ones that describe the response actually being read.
	 *
	 * @since x.x.x
	 *
	 * @param array<int, mixed> $wrapper_data Raw header lines from the stream metadata.
	 * @return array{0: int, 1: array<string, list<string>>} The status code and headers.
	 */
	private static function parse_wrapper_data( array $wrapper_data ): array {
		$status  = 0;
		$headers = array();

		foreach ( $wrapper_data as $line ) {
			if ( ! is_string( $line ) ) {
				continue;
			}

			if ( 1 === preg_match( '#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $line, $matches ) ) {
				$status  = (int) $matches[1];
				$headers = array();
				continue;
			}

			$colon = strpos( $line, ':' );
			if ( false === $colon ) {
				continue;
			}

			$name  = trim( substr( $line, 0, $colon ) );
			$value = trim( substr( $line, $colon + 1 ) );

			if ( '' === $name ) {
				continue;
			}

			if ( ! isset( $headers[ $name ] ) ) {
				$headers[ $name ] = array();
			}

			$headers[ $name ][] = $value;
		}

		if ( 0 === $status ) {
			// No status line at all means the wrapper handed back something we cannot describe.
			throw new Streaming_Exception(
				esc_html__( 'The streaming response did not include an HTTP status line.', 'ai' ),
				Streaming_Exception::CODE_TRANSPORT // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the flagged argument is the integer error code, which is branched on rather than rendered.
			);
		}

		return array( $status, $headers );
	}
}
