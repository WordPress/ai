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
	 * Maximum characters to retain for input/output previews.
	 */
	private const PAYLOAD_PREVIEW_LIMIT = 1200;

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
		$decoded  = null;

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

		$context = array(
			'url'    => $uri,
			'method' => $request->getMethod(),
		);

		if ( is_array( $decoded ) ) {
			$input_preview = $this->extract_input_preview( $decoded );
			if ( $input_preview ) {
				$context['input_preview'] = $input_preview;
			}
		}

		return array(
			'type'      => 'ai_client',
			'operation' => $operation,
			'provider'  => $provider,
			'model'     => $model,
			'context'   => $context,
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

		$context = array();
		if ( isset( $log_data['context'] ) && is_array( $log_data['context'] ) ) {
			$context = $log_data['context'];
		} elseif ( isset( $log_data['context'] ) && is_string( $log_data['context'] ) ) {
			$maybe_context = json_decode( $log_data['context'], true );
			if ( is_array( $maybe_context ) ) {
				$context = $maybe_context;
			}
		}

		$output_preview = $this->extract_output_preview( $decoded );
		if ( $output_preview ) {
			$context['output_preview'] = $output_preview;
		}

		if ( ! empty( $context ) ) {
			$log_data['context'] = $context;
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

	/**
	 * Extracts a human-readable preview of the prompt/input payload.
	 *
	 * @param array<string, mixed>|null $payload Request payload.
	 * @return string|null
	 */
	private function extract_input_preview( ?array $payload ): ?string {
		if ( empty( $payload ) ) {
			return null;
		}

		if ( isset( $payload['messages'] ) && is_array( $payload['messages'] ) ) {
			$segments = array();

			foreach ( $payload['messages'] as $message ) {
				if ( ! is_array( $message ) ) {
					continue;
				}

				$role    = $message['role'] ?? 'user';
				$content = $this->stringify_content( $message['content'] ?? '' );

				if ( '' === $content ) {
					continue;
				}

				$segments[] = sprintf( '[%s] %s', $role, $content );

				if ( strlen( implode( "\n", $segments ) ) >= self::PAYLOAD_PREVIEW_LIMIT ) {
					break;
				}
			}

			if ( $segments ) {
				return $this->truncate_string( implode( "\n", $segments ) );
			}
		}

		foreach ( array( 'prompt', 'input', 'contents' ) as $field ) {
			if ( ! isset( $payload[ $field ] ) ) {
				continue;
			}

			$content = $this->stringify_content( $payload[ $field ] );
			if ( '' !== $content ) {
				return $this->truncate_string( $content );
			}
		}

		return null;
	}

	/**
	 * Extracts a human-readable preview of the response payload.
	 *
	 * @param array<string, mixed>|null $payload Response payload.
	 * @return string|null
	 */
	private function extract_output_preview( ?array $payload ): ?string {
		if ( empty( $payload ) ) {
			return null;
		}

		if ( isset( $payload['choices'] ) && is_array( $payload['choices'] ) ) {
			foreach ( $payload['choices'] as $choice ) {
				if ( ! is_array( $choice ) ) {
					continue;
				}

				if ( isset( $choice['message']['content'] ) ) {
					$content = $this->stringify_content( $choice['message']['content'] );
					if ( '' !== $content ) {
						return $this->truncate_string( $content );
					}
				}

				if ( isset( $choice['text'] ) ) {
					$content = $this->stringify_content( $choice['text'] );
					if ( '' !== $content ) {
						return $this->truncate_string( $content );
					}
				}
			}
		}

		if ( isset( $payload['output'] ) ) {
			$content = $this->stringify_content( $payload['output'] );
			if ( '' !== $content ) {
				return $this->truncate_string( $content );
			}
		}

		if ( isset( $payload['candidates'] ) && is_array( $payload['candidates'] ) ) {
			foreach ( $payload['candidates'] as $candidate ) {
				if ( ! is_array( $candidate ) ) {
					continue;
				}

				if ( isset( $candidate['content'] ) ) {
					$content = $this->stringify_content( $candidate['content'] );
					if ( '' !== $content ) {
						return $this->truncate_string( $content );
					}
				}

				if ( isset( $candidate['output'] ) ) {
					$content = $this->stringify_content( $candidate['output'] );
					if ( '' !== $content ) {
						return $this->truncate_string( $content );
					}
				}
			}
		}

		return null;
	}

	/**
	 * Converts structured content into a plain string.
	 *
	 * @param mixed $content Structured content (string|array).
	 * @return string
	 */
	private function stringify_content( $content ): string {
		if ( is_string( $content ) ) {
			return trim( $content );
		}

		if ( is_array( $content ) ) {
			$parts = array();

			foreach ( $content as $chunk ) {
				if ( is_array( $chunk ) && isset( $chunk['text'] ) ) {
					$parts[] = (string) $chunk['text'];
					continue;
				}

				if ( is_array( $chunk ) && isset( $chunk['content'] ) ) {
					$parts[] = $this->stringify_content( $chunk['content'] );
					continue;
				}

				if ( is_scalar( $chunk ) ) {
					$parts[] = (string) $chunk;
					continue;
				}

				if ( is_array( $chunk ) ) {
					$parts[] = $this->stringify_content( $chunk );
				}
			}

			if ( $parts ) {
				return trim( implode( "\n", array_filter( $parts ) ) );
			}

			return trim( (string) wp_json_encode( $content ) );
		}

		if ( is_scalar( $content ) ) {
			return trim( (string) $content );
		}

		return '';
	}

	/**
	 * Truncates a string to the configured preview limit.
	 *
	 * @param string $value The string to truncate.
	 * @param int    $limit Maximum length.
	 * @return string
	 */
	private function truncate_string( string $value, int $limit = self::PAYLOAD_PREVIEW_LIMIT ): string {
		$value = trim( $value );

		if ( '' === $value ) {
			return $value;
		}

		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			if ( mb_strlen( $value, 'UTF-8' ) <= $limit ) {
				return $value;
			}

			return mb_substr( $value, 0, $limit, 'UTF-8' ) . '...';
		}

		if ( strlen( $value ) <= $limit ) {
			return $value;
		}

		return substr( $value, 0, $limit ) . '...';
	}
}
