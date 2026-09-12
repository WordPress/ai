<?php
/**
 * HTTP transporter decorator that can deliver a response body incrementally.
 *
 * @package WordPress\AI\Experiments\AI_Workspace\Streaming
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\AI_Workspace\Streaming;

use Throwable;
use WordPress\AI\Connector_Approval\Approvals_Store;
use WordPress\AI\Connector_Approval\Caller_Identifier;
use WordPress\AI\Connector_Approval\Connector_Key_Index;
use WordPress\AI\Logging\Log_Data_Extractor;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;

use function WordPress\AI\log_ai_request;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Decorates an HTTP transporter with a streaming path.
 *
 * A request that does not ask for a stream is handed to the wrapped
 * transporter untouched — same `Request`, same `RequestOptions`, same
 * `Response` object back. Everything the site already does keeps going through
 * the existing stack, including `Logging_Http_Transporter` and WordPress's own
 * HTTP API.
 *
 * A request that does ask for a stream cannot use that stack: WordPress's HTTP
 * API buffers the entire body before returning. It is issued through a
 * `Stream_Opener_Interface` instead, which returns as soon as the response
 * headers arrive.
 *
 * That detour bypasses two protections the buffered path gets for free, and
 * this class restores both explicitly:
 *
 * - **Connector approval.** `Connector_Approval\Http_Guard` hooks
 *   `pre_http_request`, which the streaming path never reaches. The same
 *   decision is made here, from the same collaborators and in the same order,
 *   *before* the opener is called — so an unapproved caller is refused with no
 *   bytes on the wire.
 * - **Request logging.** `Logging_Http_Transporter` records around the wrapped
 *   `send()`, which the streaming path also skips. A log entry is written here
 *   instead, in a `finally`, so a refusal and a failed connection are recorded
 *   as well as a successful one.
 *
 * A streamed entry's `duration_ms` measures time to response headers rather
 * than to the last byte — the body has not been read when the entry is
 * written — and it carries no token counts, since those arrive inside the
 * stream. `context.streaming` marks the entry so the two shapes are
 * distinguishable in the log.
 *
 * @since x.x.x
 */
final class Streaming_Http_Transporter implements HttpTransporterInterface {

	/**
	 * The wrapped HTTP transporter.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface
	 */
	private HttpTransporterInterface $transporter;

	/**
	 * Approvals store.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Connector_Approval\Approvals_Store
	 */
	private Approvals_Store $store;

	/**
	 * Caller identifier.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Connector_Approval\Caller_Identifier
	 */
	private Caller_Identifier $identifier;

	/**
	 * Connector credential index.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Connector_Approval\Connector_Key_Index
	 */
	private Connector_Key_Index $key_index;

	/**
	 * Streaming transport.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Experiments\AI_Workspace\Streaming\Stream_Opener_Interface
	 */
	private Stream_Opener_Interface $opener;

	/**
	 * Log data extractor.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Logging\Log_Data_Extractor
	 */
	private Log_Data_Extractor $extractor;

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface       $transporter The transporter to wrap.
	 * @param \WordPress\AI\Connector_Approval\Approvals_Store|null                       $store       Approvals store.
	 * @param \WordPress\AI\Connector_Approval\Caller_Identifier|null                     $identifier  Caller identifier.
	 * @param \WordPress\AI\Connector_Approval\Connector_Key_Index|null                   $key_index   Connector credential index.
	 * @param \WordPress\AI\Experiments\AI_Workspace\Streaming\Stream_Opener_Interface|null $opener    Streaming transport.
	 * @param \WordPress\AI\Logging\Log_Data_Extractor|null                               $extractor   Log data extractor.
	 */
	public function __construct(
		HttpTransporterInterface $transporter,
		?Approvals_Store $store = null,
		?Caller_Identifier $identifier = null,
		?Connector_Key_Index $key_index = null,
		?Stream_Opener_Interface $opener = null,
		?Log_Data_Extractor $extractor = null
	) {
		$this->transporter = $transporter;
		$this->store       = null === $store ? new Approvals_Store() : $store;
		$this->identifier  = null === $identifier ? new Caller_Identifier() : $identifier;
		$this->key_index   = null === $key_index ? new Connector_Key_Index() : $key_index;
		$this->opener      = null === $opener ? new Fopen_Stream_Opener() : $opener;
		$this->extractor   = null === $extractor ? new Log_Data_Extractor() : $extractor;
	}

	/**
	 * Sends an HTTP request, streaming the response body when one was asked for.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AiClient\Providers\Http\DTO\Request             $request The request to send.
	 * @param \WordPress\AiClient\Providers\Http\DTO\RequestOptions|null $options Optional transport options.
	 * @return \WordPress\AiClient\Providers\Http\DTO\Response The response received.
	 *
	 * @throws \WordPress\AI\Experiments\AI_Workspace\Streaming\Streaming_Exception If the request is refused or cannot be opened.
	 */
	public function send( Request $request, ?RequestOptions $options = null ): Response {
		if ( ! $this->wants_stream( $request, $options ) ) {
			return $this->transporter->send( $request, $options );
		}

		return $this->send_streaming( $request, $options );
	}

	/**
	 * Reports whether a stream was requested.
	 *
	 * The flag may arrive either as an explicit `$options` argument or on the
	 * request's own options, because the SDK's model classes pass it both ways.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AiClient\Providers\Http\DTO\Request             $request The request.
	 * @param \WordPress\AiClient\Providers\Http\DTO\RequestOptions|null $options Explicit options, if any.
	 * @return bool True when the response body should be streamed.
	 */
	private function wants_stream( Request $request, ?RequestOptions $options ): bool {
		if ( null !== $options && true === $options->isStream() ) {
			return true;
		}

		$request_options = $request->getOptions();

		return null !== $request_options && true === $request_options->isStream();
	}

	/**
	 * Runs the streaming path, with the approval and logging guards restored.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AiClient\Providers\Http\DTO\Request             $request The request.
	 * @param \WordPress\AiClient\Providers\Http\DTO\RequestOptions|null $options Explicit options, if any.
	 * @return \WordPress\AiClient\Providers\Http\DTO\Response The streamed response.
	 *
	 * @throws \WordPress\AI\Experiments\AI_Workspace\Streaming\Streaming_Exception If the request is refused or cannot be opened.
	 */
	private function send_streaming( Request $request, ?RequestOptions $options ): Response {
		$started   = microtime( true );
		$log_data  = $this->extract_request_data( $request );
		$status    = 'success';
		$error_msg = null;

		try {
			// Approval is decided before the opener runs, so a refusal costs no egress.
			$this->assert_connector_approved( $request );

			$opened = $this->opener->open(
				$request->getMethod()->value,
				$request->getUri(),
				$request->getHeaders(),
				$request->getBody(),
				$this->resolve_timeout( $request, $options )
			);

			$log_data['context']['status_code'] = $opened['status'];

			if ( $opened['status'] < 200 || $opened['status'] >= 300 ) {
				$status    = 'error';
				$error_msg = sprintf( 'HTTP %d', $opened['status'] );
			}

			return new Response( $opened['status'], $opened['headers'], $opened['stream'] );
		} catch ( Throwable $e ) {
			$status    = 'error';
			$error_msg = $e->getMessage();
			throw $e;
		} finally {
			$log_data['duration_ms']   = (int) round( ( microtime( true ) - $started ) * 1000 );
			$log_data['status']        = $status;
			$log_data['error_message'] = $error_msg;

			log_ai_request( $log_data );
		}
	}

	/**
	 * Refuses the request when its caller is not approved for the connector it uses.
	 *
	 * This mirrors `Connector_Approval\Http_Guard::maybe_block_request()`: the
	 * connector is identified from the credential the request actually carries,
	 * the caller is resolved from the call stack, and a request that matches no
	 * connector or no identifiable extension is allowed through so unrelated
	 * traffic and core-originated calls are unaffected.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AiClient\Providers\Http\DTO\Request $request The request about to be sent.
	 *
	 * @throws \WordPress\AI\Experiments\AI_Workspace\Streaming\Streaming_Exception If the caller is not approved.
	 */
	private function assert_connector_approved( Request $request ): void {
		$connector_id = $this->key_index->lookup(
			array( 'headers' => $request->getHeaders() ),
			$request->getUri()
		);

		if ( null === $connector_id ) {
			return;
		}

		$caller = $this->identifier->identify();

		if ( null === $caller ) {
			return;
		}

		if ( $this->store->is_approved( $caller['basename'], $connector_id ) ) {
			return;
		}

		$this->store->record_pending( $caller, $connector_id );

		throw new Streaming_Exception(
			sprintf(
				/* translators: 1: Connector ID. 2: Calling plugin/theme basename. */
				esc_html__( 'The "%1$s" AI connector has not been approved for use by "%2$s".', 'ai' ),
				esc_html( $connector_id ),
				esc_html( $caller['basename'] )
			),
			Streaming_Exception::CODE_NOT_APPROVED // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the flagged argument is the integer error code, which is branched on rather than rendered.
		);
	}

	/**
	 * Builds the initial log entry for a streaming request.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AiClient\Providers\Http\DTO\Request $request The request.
	 * @return array<string, mixed> Log data.
	 */
	private function extract_request_data( Request $request ): array {
		$log_data = $this->extractor->extract_request_data(
			$request->getUri(),
			$request->getMethod()->value,
			$request->getBody()
		);

		$context = isset( $log_data['context'] ) && is_array( $log_data['context'] ) ? $log_data['context'] : array();

		$context['streaming'] = true;

		$caller = $this->identifier->identify();
		if ( null !== $caller ) {
			$context['source'] = array(
				'type' => $caller['type'],
				'slug' => $caller['basename'],
				'name' => $caller['name'],
			);
		}

		$log_data['context'] = $context;

		return $log_data;
	}

	/**
	 * Resolves the timeout to apply to the streaming connection.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AiClient\Providers\Http\DTO\Request             $request The request.
	 * @param \WordPress\AiClient\Providers\Http\DTO\RequestOptions|null $options Explicit options, if any.
	 * @return float|null The timeout in seconds, or null for the transport default.
	 */
	private function resolve_timeout( Request $request, ?RequestOptions $options ): ?float {
		$candidates = array( $options, $request->getOptions() );

		foreach ( $candidates as $candidate ) {
			if ( null === $candidate ) {
				continue;
			}

			$timeout = $candidate->getTimeout();

			if ( null !== $timeout ) {
				return (float) $timeout;
			}
		}

		return null;
	}
}
