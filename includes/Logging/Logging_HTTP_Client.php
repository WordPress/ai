<?php
/**
 * HTTP Client decorator that logs AI requests.
 *
 * @package WordPress\AI\Logging
 */

declare( strict_types=1 );

namespace WordPress\AI\Logging;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use WordPress\AiClient\Providers\Http\Contracts\ClientWithOptionsInterface;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use Throwable;

/**
 * Decorates an HTTP client to add logging for AI requests.
 *
 * @since 0.1.0
 */
class Logging_HTTP_Client implements ClientInterface, ClientWithOptionsInterface {

	/**
	 * The wrapped HTTP client.
	 */
	private ClientInterface $client;

	/**
	 * The log manager instance.
	 */
	private AI_Request_Log_Manager $log_manager;

	/**
	 * Constructor.
	 *
	 * @param ClientInterface        $client      The HTTP client to wrap.
	 * @param AI_Request_Log_Manager $log_manager The log manager.
	 */
	public function __construct( ClientInterface $client, AI_Request_Log_Manager $log_manager ) {
		$this->client      = $client;
		$this->log_manager = $log_manager;
	}

	/**
	 * Sends a PSR-7 request and returns a PSR-7 response, logging the request.
	 *
	 * @param RequestInterface $request The PSR-7 request.
	 * @return ResponseInterface The PSR-7 response.
	 * @throws \Psr\Http\Client\ClientExceptionInterface If an error happens during processing.
	 */
	public function sendRequest( RequestInterface $request ): ResponseInterface {
		$timer      = $this->log_manager->start_timer();
		$log_data   = $this->extract_request_data( $request );
		$error_msg  = null;
		$status     = 'success';

		try {
			$response = $this->client->sendRequest( $request );

			$this->extract_response_data( $response, $log_data );

			return $response;
		} catch ( Throwable $e ) {
			$status    = 'error';
			$error_msg = $e->getMessage();
			throw $e;
		} finally {
			$log_data['duration_ms']   = $this->log_manager->end_timer( $timer );
			$log_data['status']        = $status;
			$log_data['error_message'] = $error_msg;

			$this->log_manager->log( $log_data );
		}
	}

	/**
	 * Sends a PSR-7 request with options and returns a PSR-7 response, logging the request.
	 *
	 * @param RequestInterface $request The PSR-7 request.
	 * @param RequestOptions   $options Transport options for the request.
	 * @return ResponseInterface The PSR-7 response.
	 * @throws \Psr\Http\Client\ClientExceptionInterface If an error happens during processing.
	 */
	public function sendRequestWithOptions( RequestInterface $request, RequestOptions $options ): ResponseInterface {
		// If the wrapped client doesn't support options, fall back to regular send.
		if ( ! $this->client instanceof ClientWithOptionsInterface ) {
			return $this->sendRequest( $request );
		}

		$timer      = $this->log_manager->start_timer();
		$log_data   = $this->extract_request_data( $request );
		$error_msg  = null;
		$status     = 'success';

		try {
			$response = $this->client->sendRequestWithOptions( $request, $options );

			$this->extract_response_data( $response, $log_data );

			return $response;
		} catch ( Throwable $e ) {
			$status    = 'error';
			$error_msg = $e->getMessage();
			throw $e;
		} finally {
			$log_data['duration_ms']   = $this->log_manager->end_timer( $timer );
			$log_data['status']        = $status;
			$log_data['error_message'] = $error_msg;

			$this->log_manager->log( $log_data );
		}
	}

	/**
	 * Extracts logging data from the request.
	 *
	 * @param RequestInterface $request The PSR-7 request.
	 * @return array<string, mixed> Initial log data.
	 */
	private function extract_request_data( RequestInterface $request ): array {
		$uri      = (string) $request->getUri();
		$provider = $this->detect_provider( $uri );
		$model    = null;

		// Try to extract model from request body.
		$body = (string) $request->getBody();
		if ( $body && $request->getBody()->isSeekable() ) {
			$request->getBody()->rewind();
		}

		if ( $body ) {
			$decoded = json_decode( $body, true );
			if ( is_array( $decoded ) && isset( $decoded['model'] ) ) {
				$model = (string) $decoded['model'];
			}
		}

		// Build operation name from URL path.
		$path      = $request->getUri()->getPath();
		$operation = $provider ? $provider . ':' . basename( $path ) : basename( $path );

		return array(
			'type'      => 'ai_client',
			'operation' => $operation,
			'provider'  => $provider,
			'model'     => $model,
			'context'   => array(
				'url'    => $uri,
				'method' => $request->getMethod(),
			),
		);
	}

	/**
	 * Extracts token usage and other data from the response.
	 *
	 * @param ResponseInterface    $response The PSR-7 response.
	 * @param array<string, mixed> $log_data Log data to update (passed by reference).
	 */
	private function extract_response_data( ResponseInterface $response, array &$log_data ): void {
		$body = (string) $response->getBody();
		if ( $response->getBody()->isSeekable() ) {
			$response->getBody()->rewind();
		}

		if ( ! $body ) {
			return;
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return;
		}

		// Extract model if not already set.
		if ( empty( $log_data['model'] ) && isset( $decoded['model'] ) ) {
			$log_data['model'] = (string) $decoded['model'];
		}

		// Extract token usage - OpenAI format.
		if ( isset( $decoded['usage'] ) && is_array( $decoded['usage'] ) ) {
			$usage = $decoded['usage'];
			$log_data['tokens_input']  = $usage['prompt_tokens'] ?? $usage['input_tokens'] ?? null;
			$log_data['tokens_output'] = $usage['completion_tokens'] ?? $usage['output_tokens'] ?? null;
		}

		// Anthropic format.
		if ( isset( $decoded['usage']['input_tokens'] ) ) {
			$log_data['tokens_input']  = $decoded['usage']['input_tokens'];
			$log_data['tokens_output'] = $decoded['usage']['output_tokens'] ?? null;
		}

		// Google format.
		if ( isset( $decoded['usageMetadata'] ) && is_array( $decoded['usageMetadata'] ) ) {
			$usage = $decoded['usageMetadata'];
			$log_data['tokens_input']  = $usage['promptTokenCount'] ?? null;
			$log_data['tokens_output'] = $usage['candidatesTokenCount'] ?? null;
		}
	}

	/**
	 * Detects the AI provider from the request URL.
	 *
	 * @param string $url The request URL.
	 * @return string|null The detected provider name or null.
	 */
	private function detect_provider( string $url ): ?string {
		$host = parse_url( $url, PHP_URL_HOST );

		if ( ! $host ) {
			return null;
		}

		$host_lower = strtolower( $host );

		if ( strpos( $host_lower, 'openai' ) !== false ) {
			return 'openai';
		}

		if ( strpos( $host_lower, 'anthropic' ) !== false ) {
			return 'anthropic';
		}

		if ( strpos( $host_lower, 'googleapis' ) !== false || strpos( $host_lower, 'google' ) !== false ) {
			return 'google';
		}

		if ( strpos( $host_lower, 'azure' ) !== false ) {
			return 'azure';
		}

		if ( strpos( $host_lower, 'cohere' ) !== false ) {
			return 'cohere';
		}

		if ( strpos( $host_lower, 'mistral' ) !== false ) {
			return 'mistral';
		}

		return null;
	}
}
