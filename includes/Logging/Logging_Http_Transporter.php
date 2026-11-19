<?php
/**
 * HTTP Transporter decorator that logs AI requests.
 *
 * @package WordPress\AI\Logging
 */

declare( strict_types=1 );

namespace WordPress\AI\Logging;

use Throwable;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;

defined( 'ABSPATH' ) || exit;

/**
 * Decorates an HTTP transporter to add logging for AI requests.
 *
 * This class wraps the SDK's HttpTransporterInterface rather than the lower-level
 * PSR-18 ClientInterface, providing cleaner integration with the AI Client SDK.
 *
 * Extraction logic is delegated to Log_Data_Extractor for better separation of
 * concerns and extensibility via WordPress filter hooks.
 *
 * @since 1.0.0
 */
class Logging_Http_Transporter implements HttpTransporterInterface {

	/**
	 * The wrapped HTTP transporter.
	 *
	 * @var \WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface
	 */
	private HttpTransporterInterface $transporter;

	/**
	 * The log manager instance.
	 *
	 * @var \WordPress\AI\Logging\AI_Request_Log_Manager
	 */
	private AI_Request_Log_Manager $log_manager;

	/**
	 * The data extractor instance.
	 *
	 * @var \WordPress\AI\Logging\Log_Data_Extractor
	 */
	private Log_Data_Extractor $extractor;

	/**
	 * The request source detector.
	 *
	 * @var \WordPress\AI\Logging\Request_Source_Detector
	 */
	private Request_Source_Detector $source_detector;

	/**
	 * Constructor.
	 *
	 * @param \WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface $transporter The HTTP transporter to wrap.
	 * @param \WordPress\AI\Logging\AI_Request_Log_Manager   $log_manager The log manager.
	 * @param \WordPress\AI\Logging\Log_Data_Extractor|null  $extractor   Optional data extractor (created if not provided).
	 * @param \WordPress\AI\Logging\Request_Source_Detector|null $source_detector Optional source detector (created if not provided).
	 */
	public function __construct(
		HttpTransporterInterface $transporter,
		AI_Request_Log_Manager $log_manager,
		?Log_Data_Extractor $extractor = null,
		?Request_Source_Detector $source_detector = null
	) {
		$this->transporter     = $transporter;
		$this->log_manager     = $log_manager;
		$this->extractor       = $extractor ?? new Log_Data_Extractor();
		$this->source_detector = $source_detector ?? new Request_Source_Detector();
	}

	/**
	 * Sends an HTTP request and returns the response, logging the request.
	 *
	 * @param \WordPress\AiClient\Providers\Http\DTO\Request             $request The request to send.
	 * @param \WordPress\AiClient\Providers\Http\DTO\RequestOptions|null $options Optional transport options.
	 * @return \WordPress\AiClient\Providers\Http\DTO\Response The response received.
	 */
	public function send( Request $request, ?RequestOptions $options = null ): Response {
		$timer     = $this->log_manager->start_timer();
		$log_data  = $this->extract_request_data( $request );
		$error_msg = null;
		$status    = 'success';

		try {
			$response = $this->transporter->send( $request, $options );

			$log_data = $this->extract_response_data( $response, $log_data );

			if ( ! $response->isSuccessful() ) {
				$status    = 'error';
				$error_msg = $this->describe_error_response( $response );

				$context                = is_array( $log_data['context'] ?? null ) ? $log_data['context'] : array();
				$context['http_status'] = $response->getStatusCode();
				$log_data['context']    = $context;
			}

			return $response;
		} catch ( Throwable $e ) {
			$status    = 'error';
			$error_msg = $e->getMessage();
			throw $e;
		} finally {
			$log_data['duration_ms']   = $this->log_manager->end_timer( $timer );
			$log_data['status']        = $status;
			$log_data['error_message'] = $error_msg;

			// @phpstan-ignore argument.type (array shape is built incrementally)
			$this->log_manager->log( $log_data );
		}
	}

	/**
	 * Extracts logging data from the request.
	 *
	 * @param \WordPress\AiClient\Providers\Http\DTO\Request $request The SDK request.
	 * @return array<string, mixed> Initial log data.
	 */
	private function extract_request_data( Request $request ): array {
		$log_data = $this->extractor->extract_request_data(
			$request->getUri(),
			$request->getMethod()->value,
			$request->getBody()
		);

		$source = $this->source_detector->detect();

		if ( null !== $source ) {
			$context = $log_data['context'] ?? array();

			if ( ! is_array( $context ) ) {
				$context = array();
			}

			$context['source']   = $source;
			$log_data['context'] = $context;
		}

		return $log_data;
	}

	/**
	 * Extracts token usage and other data from the response.
	 *
	 * @param \WordPress\AiClient\Providers\Http\DTO\Response             $response The SDK response.
	 * @param array<string, mixed> $log_data Log data to augment.
	 * @return array<string, mixed> Augmented log data.
	 */
	private function extract_response_data( Response $response, array $log_data ): array {
		return $this->extractor->extract_response_data(
			$response->getBody(),
			$log_data
		);
	}

	/**
	 * Builds a human-readable error message for a non-2xx response.
	 *
	 * @since 1.4.0
	 *
	 * @param \WordPress\AiClient\Providers\Http\DTO\Response $response The SDK response.
	 * @return string Error message including the HTTP status code.
	 */
	private function describe_error_response( Response $response ): string {
		$message = sprintf( 'HTTP %d', $response->getStatusCode() );

		$data = $response->getData();
		if ( isset( $data['error']['message'] ) && is_string( $data['error']['message'] ) ) {
			$message .= ': ' . $data['error']['message'];
		}

		return $message;
	}
}
