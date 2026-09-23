<?php
/**
 * Server-sent events for AI Workspace turns.
 *
 * @package WordPress\AI\Experiments\AI_Workspace
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\AI_Workspace\REST;

use WP_REST_Request;
use WP_REST_Response;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Streams a workspace turn to the browser as server-sent events.
 *
 * The turn route writes no output of its own; it exposes the
 * `wpai_workspace_stream_emitter` filter and this class is the consumer that
 * turns the filter's text callback into SSE frames. Nothing here decides how a
 * turn runs — it only decides how the turn's text reaches the browser.
 *
 * Three properties keep the emission safe:
 *
 * - **Streaming is opt in per request.** Only a request carrying the
 *   `X-WP-AI-Stream: 1` header is considered, so no existing consumer of the
 *   turn route changes shape.
 * - **Streaming never happens off a live HTTP connection.** The CLI SAPI is
 *   refused outright, which is what keeps this code away from PHPUnit's
 *   `beStrictAboutOutputDuringTests`: the test suite runs under `cli`, so no test
 *   path can reach an `echo` here even if one sets the header.
 * - **Headers are sent lazily, on the first delta.** A host that produced no
 *   streamed text — no streaming model, an unapproved connector, a transport
 *   that would not open, or a turn that failed before any text — has still sent
 *   nothing, so the REST server serves its ordinary JSON body and the client
 *   falls back to the buffered shape. Streaming degrades to a slower answer
 *   rather than to a broken one.
 *
 * The event protocol is: zero or more `delta` events carrying `{"text": "..."}`,
 * then exactly one `result` event carrying the turn route's normal response body
 * or one `error` event carrying its error body, then `done`.
 *
 * @since x.x.x
 */
final class Stream_Responder {

	/**
	 * Request header a client sends to ask for the streaming transport.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const STREAM_HEADER = 'x_wp_ai_stream';

	/**
	 * Whether SSE headers have been sent for the current request.
	 *
	 * @since x.x.x
	 *
	 * @var bool
	 */
	private $streaming = false;

	/**
	 * The request being streamed, when one is.
	 *
	 * @since x.x.x
	 *
	 * @var \WP_REST_Request|null
	 */
	private $request = null;

	/**
	 * Hooks the emitter filter.
	 *
	 * @since x.x.x
	 */
	public function init(): void {
		add_filter( 'wpai_workspace_stream_emitter', array( $this, 'maybe_stream' ), 10, 2 );
	}

	/**
	 * Supplies the text emitter for a request that asked to stream.
	 *
	 * @since x.x.x
	 *
	 * @param callable|null $emitter The emitter another consumer supplied, if any.
	 * @param mixed         $request The REST request.
	 * @return callable|null The emitter, or the incoming value.
	 */
	public function maybe_stream( $emitter, $request ) {
		if ( null !== $emitter || ! $this->can_stream( $request ) ) {
			return $emitter;
		}

		$this->request = $request;

		add_filter( 'rest_pre_serve_request', array( $this, 'serve' ), 10, 3 );
		add_filter( 'rest_post_dispatch', array( $this, 'strip_headers' ), 100, 3 );

		return array( $this, 'emit' );
	}

	/**
	 * Decides whether this request may be streamed.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $request The REST request.
	 * @return bool True when the request may be streamed.
	 */
	private function can_stream( $request ): bool {
		if ( ! $request instanceof WP_REST_Request ) {
			return false;
		}

		if ( '1' !== (string) $request->get_header( self::STREAM_HEADER ) ) {
			return false;
		}

		/*
		 * Server-sent events need a live HTTP connection. Under the CLI SAPI —
		 * WP-CLI and, importantly, PHPUnit — there is none, and writing output
		 * would trip the suite's strict no-output setting.
		 */
		if ( 'cli' === PHP_SAPI || 'phpdbg' === PHP_SAPI ) {
			return false;
		}

		return ! headers_sent();
	}

	/**
	 * Emits one assistant text delta.
	 *
	 * @since x.x.x
	 *
	 * @param string $text The text delta.
	 */
	public function emit( string $text ): void {
		if ( '' === $text ) {
			return;
		}

		$this->begin();
		$this->frame( 'delta', array( 'text' => $text ) );
	}

	/**
	 * Drops the response headers once the body has already begun.
	 *
	 * The REST server sends a response's headers after dispatch returns, which
	 * for a streamed turn is after the first frame has been written. Removing
	 * them keeps the server from calling `header()` on a response that is
	 * already on the wire.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $result  The response.
	 * @param mixed $server  The REST server.
	 * @param mixed $request The request.
	 * @return mixed The response.
	 */
	public function strip_headers( $result, $server, $request ) {
		if ( $this->streaming && $request === $this->request && $result instanceof WP_REST_Response ) {
			$result->set_headers( array() );
		}

		return $result;
	}

	/**
	 * Writes the terminating frames once the turn has finished.
	 *
	 * @since x.x.x
	 *
	 * @param bool  $served  Whether the request has already been served.
	 * @param mixed $result  The response to serve.
	 * @param mixed $request The request being served.
	 * @return bool True when this class served the request.
	 */
	public function serve( $served, $result, $request ): bool {
		if ( ! $this->streaming || $request !== $this->request ) {
			return (bool) $served;
		}

		if ( $result instanceof WP_REST_Response ) {
			$event = $result->get_status() >= 400 ? 'error' : 'result';
			$data  = $result->get_data();
		} else {
			$event = 'error';
			$data  = array(
				'code'    => 'workspace_stream_incomplete',
				'message' => __( 'The turn ended without a result.', 'ai' ),
			);
		}

		$this->frame( $event, is_array( $data ) ? $data : array( 'data' => $data ) );
		$this->frame( 'done', array() );

		return true;
	}

	/**
	 * Sends the SSE headers, once, on the first frame.
	 *
	 * @since x.x.x
	 */
	private function begin(): void {
		if ( $this->streaming ) {
			return;
		}

		$this->streaming = true;

		/*
		 * The REST server still sets a status code after dispatch returns, which
		 * is after this body has begun. Its no-cache headers are the largest part
		 * of that and can be switched off outright; the single status_header()
		 * call cannot, so PHP logs one "headers already sent" notice per streamed
		 * turn. The frame parser on the other end reads line by line and ignores
		 * anything that is not an `event:` or `data:` line, so a host that prints
		 * that notice into the response does not corrupt the stream.
		 */
		add_filter( 'rest_send_nocache_headers', '__return_false' );

		if ( ! headers_sent() ) {
			header( 'Content-Type: text/event-stream; charset=' . get_option( 'blog_charset' ) );
			header( 'Cache-Control: no-cache, no-store, must-revalidate' );
			header( 'Connection: keep-alive' );
			// Asks nginx not to buffer the response, which would defeat streaming.
			header( 'X-Accel-Buffering: no' );
		}

		// Frames have to leave PHP as they are written, not at the end of the request.
		$levels = ob_get_level();

		while ( $levels > 0 ) {
			ob_end_flush();
			--$levels;
		}
	}

	/**
	 * Writes one SSE frame.
	 *
	 * @since x.x.x
	 *
	 * @param string              $event The event name, always a literal from this class.
	 * @param array<string,mixed> $data  The payload, emitted as JSON.
	 */
	private function frame( string $event, array $data ): void {
		$payload = wp_json_encode( $data );

		if ( false === $payload ) {
			return;
		}

		/*
		 * Neither value is HTML: the event name is a literal defined here and the
		 * payload is JSON produced by wp_json_encode, written into a
		 * text/event-stream body. Escaping either for HTML would corrupt the frame.
		 */
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo 'event: ' . $event . "\n";
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo 'data: ' . $payload . "\n\n";

		flush();
	}
}
