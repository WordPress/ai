<?php
/**
 * Extracts structured log data from AI requests and responses.
 *
 * @package WordPress\AI\Logging
 */

declare( strict_types=1 );

namespace WordPress\AI\Logging;

defined( 'ABSPATH' ) || exit;

/**
 * Extracts and normalizes log data from AI HTTP requests and responses.
 *
 * This class is responsible for parsing various AI provider response formats
 * and extracting relevant metadata for logging purposes.
 *
 * @since 1.0.0
 */
class Log_Data_Extractor {

	/**
	 * Maximum characters to retain for input/output previews.
	 */
	public const PAYLOAD_PREVIEW_LIMIT = 1200;

	/**
	 * Maximum number of media samples to retain per response.
	 */
	public const MAX_MEDIA_SAMPLES = 3;

	/**
	 * Provider detection patterns.
	 *
	 * Maps provider names to URL patterns.
	 * Filterable via 'wpai_request_log_providers'.
	 *
	 * @var array<string, array<string>>
	 */
	private array $provider_patterns;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->provider_patterns = $this->get_default_provider_patterns();
	}

	/**
	 * Returns the provider detection patterns, derived from the connectors registry.
	 *
	 * @return array<string, array<string>> Provider name => URL patterns.
	 */
	private function get_default_provider_patterns(): array {
		// Slugs whose API host doesn't contain the slug itself. Google's API runs at googleapis.com.
		$overrides = array( 'google' => 'googleapis' );
		$patterns  = array();

		foreach ( wp_get_connectors() as $slug => $connector_data ) {
			if ( 'ai_provider' !== ( $connector_data['type'] ?? '' ) ) {
				continue;
			}

			$slug              = strtolower( (string) $slug );
			$patterns[ $slug ] = array( $overrides[ $slug ] ?? $slug );
		}

		/**
		 * Filters the provider detection patterns used to label log entries.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, array<string>> $patterns Provider name => URL patterns map.
		 */
		return (array) apply_filters( 'wpai_request_log_providers', $patterns );
	}

	/**
	 * Extracts log data from a request.
	 *
	 * @param string      $uri         Request URI.
	 * @param string      $method      HTTP method.
	 * @param string|null $body   Request body (JSON).
	 * @return array<string, mixed> Extracted log data.
	 */
	public function extract_request_data( string $uri, string $method, ?string $body ): array {
		$provider = $this->detect_provider( $uri );
		$model    = null;
		$decoded  = null;

		if ( $body ) {
			$decoded = json_decode( $body, true );
			if ( is_array( $decoded ) && isset( $decoded['model'] ) ) {
				$model = (string) $decoded['model'];
			}
		}

		$parsed_url = wp_parse_url( $uri );
		$path       = $parsed_url['path'] ?? '';
		$operation  = $provider ? $provider . ':' . basename( $path ) : basename( $path );

		$context = array(
			'url'          => $uri,
			'method'       => $method,
			'request_kind' => $this->detect_request_kind( $provider, $path, $decoded ),
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
	 * Extracts additional data from a response.
	 *
	 * @param string|null          $body     Response body (JSON).
	 * @param array<string, mixed> $log_data Existing log data to augment.
	 * @return array<string, mixed> Augmented log data.
	 */
	public function extract_response_data( ?string $body, array $log_data ): array {
		if ( ! $body ) {
			return $log_data;
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return $log_data;
		}

		// Extract model if not already set.
		if ( empty( $log_data['model'] ) && isset( $decoded['model'] ) ) {
			$log_data['model'] = (string) $decoded['model'];
		}

		// Extract token usage.
		$tokens = $this->extract_token_usage( $decoded );
		if ( null !== $tokens['input'] ) {
			$log_data['tokens_input'] = $tokens['input'];
		}
		if ( null !== $tokens['output'] ) {
			$log_data['tokens_output'] = $tokens['output'];
		}

		// Build context.
		$context = $this->normalize_context( $log_data['context'] ?? array() );

		$output_preview = $this->extract_output_preview( $decoded );
		if ( $output_preview ) {
			$context['output_preview'] = $output_preview;
		}

		$media_context = $this->extract_media_metadata( $decoded );
		if ( ! empty( $media_context ) ) {
			$context = array_merge( $context, $media_context );
		}

		/**
		 * Filters the log context data.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $context  The context data.
		 * @param array<string, mixed> $decoded  The decoded response.
		 * @param array<string, mixed> $log_data The full log data.
		 */
		$context = (array) apply_filters( 'wpai_request_log_context', $context, $decoded, $log_data );

		if ( ! empty( $context ) ) {
			$log_data['context'] = $context;
		}

		return $log_data;
	}

	/**
	 * Detects the AI provider from the request URL.
	 *
	 * @param string $url The request URL.
	 * @return string|null The detected provider name or null.
	 */
	public function detect_provider( string $url ): ?string {
		$parsed = wp_parse_url( $url );
		$host   = $parsed['host'] ?? '';

		if ( ! $host ) {
			return null;
		}

		$host_lower = strtolower( $host );

		foreach ( $this->provider_patterns as $name => $patterns ) {
			foreach ( $patterns as $pattern ) {
				if ( strpos( $host_lower, $pattern ) !== false ) {
					return $name;
				}
			}
		}

		return null;
	}

	/**
	 * Extracts token usage from various provider response formats.
	 *
	 * @param array<string, mixed> $response Decoded response data.
	 * @return array{input: int|null, output: int|null} Token counts.
	 */
	public function extract_token_usage( array $response ): array {
		$input  = null;
		$output = null;

		// OpenAI format.
		if ( isset( $response['usage'] ) && is_array( $response['usage'] ) ) {
			$usage  = $response['usage'];
			$input  = $usage['prompt_tokens'] ?? $usage['input_tokens'] ?? null;
			$output = $usage['completion_tokens'] ?? $usage['output_tokens'] ?? null;
		}

		// Anthropic format (also uses 'usage' but different keys).
		if ( isset( $response['usage']['input_tokens'] ) ) {
			$input  = $response['usage']['input_tokens'];
			$output = $response['usage']['output_tokens'] ?? null;
		}

		// Google format.
		if ( isset( $response['usageMetadata'] ) && is_array( $response['usageMetadata'] ) ) {
			$usage  = $response['usageMetadata'];
			$input  = $usage['promptTokenCount'] ?? null;
			$output = $usage['candidatesTokenCount'] ?? null;
		}

		/**
		 * Filters the extracted token usage.
		 *
		 * Allows custom providers to supply their own token extraction logic.
		 *
		 * @since 1.0.0
		 *
		 * @param array{input: int|null, output: int|null} $tokens   Extracted token counts.
		 * @param array<string, mixed>                     $response The full response data.
		 */
		return (array) apply_filters(
			'wpai_request_log_tokens',
			array(
				'input'  => $input,
				'output' => $output,
			),
			$response
		);
	}

	/**
	 * Determines the high-level request kind.
	 *
	 * @param string|null               $provider Provider identifier.
	 * @param string                    $path     Request path.
	 * @param array<string, mixed>|null $payload  Request payload.
	 * @return string Request kind: 'text', 'image', 'embeddings', etc.
	 */
	public function detect_request_kind( ?string $provider, string $path, ?array $payload ): string {
		$path_lower = strtolower( $path );

		// Provider-specific detection.
		if ( 'fal' === $provider ) {
			return 'image';
		}

		// Path-based detection.
		if (
			'models' === basename( $path_lower ) ||
			false !== strpos( $path_lower, '/models?' ) ||
			false !== strpos( $path_lower, '/models/' )
		) {
			return 'metadata';
		}

		if ( false !== strpos( $path_lower, 'embeddings' ) ) {
			return 'embeddings';
		}

		if (
			false !== strpos( $path_lower, '/images' ) ||
			false !== strpos( $path_lower, 'imagegeneration' ) ||
			false !== strpos( $path_lower, 'image-generation' )
		) {
			return 'image';
		}

		if ( false !== strpos( $path_lower, 'audio' ) || false !== strpos( $path_lower, 'speech' ) ) {
			return 'audio';
		}

		/**
		 * Filters the detected request kind.
		 *
		 * @since 1.0.0
		 *
		 * @param string                    $kind     Detected request kind.
		 * @param string|null               $provider Provider identifier.
		 * @param string                    $path     Request path.
		 * @param array<string, mixed>|null $payload  Request payload.
		 */
		return (string) apply_filters( 'wpai_request_log_kind', 'text', $provider, $path, $payload );
	}

	/**
	 * Extracts a human-readable preview of the prompt/input payload.
	 *
	 * @param array<string, mixed> $payload Request payload.
	 * @return string|null Truncated preview or null.
	 */
	public function extract_input_preview( array $payload ): ?string {
		// Chat messages format (OpenAI, Anthropic).
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

		// Alternative input formats.
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
	 * @param array<string, mixed> $payload Response payload.
	 * @return string|null Truncated preview or null.
	 */
	public function extract_output_preview( array $payload ): ?string {
		// OpenAI choices format.
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

				if ( ! isset( $choice['text'] ) ) {
					continue;
				}

				$content = $this->stringify_content( $choice['text'] );
				if ( '' !== $content ) {
					return $this->truncate_string( $content );
				}
			}
		}

		// Anthropic content format.
		if ( isset( $payload['content'] ) && is_array( $payload['content'] ) ) {
			$content = $this->stringify_content( $payload['content'] );
			if ( '' !== $content ) {
				return $this->truncate_string( $content );
			}
		}

		// Direct output field.
		if ( isset( $payload['output'] ) ) {
			$content = $this->stringify_content( $payload['output'] );
			if ( '' !== $content ) {
				return $this->truncate_string( $content );
			}
		}

		// Google candidates format.
		if ( isset( $payload['candidates'] ) && is_array( $payload['candidates'] ) ) {
			foreach ( $payload['candidates'] as $candidate ) {
				if ( ! is_array( $candidate ) ) {
					continue;
				}

				if ( ! isset( $candidate['content'] ) ) {
					continue;
				}

				$content = $this->stringify_content( $candidate['content'] );
				if ( '' !== $content ) {
					return $this->truncate_string( $content );
				}
			}
		}

		return null;
	}

	/**
	 * Extracts media metadata from a response (without storing raw data).
	 *
	 * For production use, we store only metadata about generated media
	 * rather than the full base64 data to prevent database bloat.
	 *
	 * @param array<string, mixed> $payload Response data.
	 * @return array<string, mixed> Media metadata.
	 */
	public function extract_media_metadata( array $payload ): array {
		$context     = array();
		$image_count = 0;
		$image_urls  = array();
		$image_metas = array();

		// OpenAI DALL-E format.
		if ( isset( $payload['data'] ) && is_array( $payload['data'] ) ) {
			foreach ( $payload['data'] as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}

				if ( isset( $entry['url'] ) && is_string( $entry['url'] ) ) {
					$image_urls[] = $entry['url'];
					++$image_count;
				} elseif ( isset( $entry['b64_json'] ) && is_string( $entry['b64_json'] ) ) {
					// Store metadata only, not the actual data.
					$image_metas[] = array(
						'format' => 'base64',
						'size'   => strlen( $entry['b64_json'] ),
					);
					++$image_count;
				}
			}
		}

		// Alternative images array format.
		if ( isset( $payload['images'] ) && is_array( $payload['images'] ) ) {
			foreach ( $payload['images'] as $image ) {
				if ( ! is_array( $image ) ) {
					continue;
				}

				if ( isset( $image['url'] ) && is_string( $image['url'] ) ) {
					$image_urls[] = $image['url'];
					++$image_count;
				} elseif ( isset( $image['b64_json'] ) || isset( $image['image_base64'] ) ) {
					$encoded       = $image['b64_json'] ?? $image['image_base64'];
					$image_metas[] = array(
						'format'    => 'base64',
						'mime_type' => $image['content_type'] ?? 'image/png',
						'size'      => is_string( $encoded ) ? strlen( $encoded ) : 0,
					);
					++$image_count;
				}
			}
		}

		if ( $image_count > 0 ) {
			$context['media_type']  = 'image';
			$context['media_count'] = $image_count;

			if ( ! empty( $image_urls ) ) {
				$context['image_urls'] = array_slice( $image_urls, 0, self::MAX_MEDIA_SAMPLES );
			}

			if ( ! empty( $image_metas ) ) {
				$context['image_metadata'] = array_slice( $image_metas, 0, self::MAX_MEDIA_SAMPLES );
			}

			$context['output_preview'] = sprintf(
				'Generated %d image(s).',
				$image_count
			);
		}

		return $context;
	}

	/**
	 * Normalizes context data to ensure it's an array.
	 *
	 * @param mixed $context Raw context data.
	 * @return array<string, mixed> Normalized context array.
	 */
	private function normalize_context( $context ): array {
		if ( is_array( $context ) ) {
			return $context;
		}

		if ( is_string( $context ) ) {
			$decoded = json_decode( $context, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return array();
	}

	/**
	 * Converts structured content into a plain string.
	 *
	 * @param mixed $content Structured content (string|array).
	 * @return string Plain text content.
	 */
	public function stringify_content( $content ): string {
		if ( is_string( $content ) ) {
			return trim( $content );
		}

		if ( is_array( $content ) ) {
			// Handle base64 image markers.
			if ( isset( $content['b64_json'] ) || isset( $content['image_base64'] ) ) {
				return '[base64 image]';
			}

			$parts = array();

			foreach ( $content as $chunk ) {
				if ( is_array( $chunk ) ) {
					if ( isset( $chunk['text'] ) ) {
						$parts[] = (string) $chunk['text'];
					} elseif ( isset( $chunk['content'] ) ) {
						$parts[] = $this->stringify_content( $chunk['content'] );
					} elseif ( isset( $chunk['type'] ) && 'text' === $chunk['type'] && isset( $chunk['text'] ) ) {
						$parts[] = (string) $chunk['text'];
					} else {
						$nested = $this->stringify_content( $chunk );
						if ( '' !== $nested ) {
							$parts[] = $nested;
						}
					}
				} elseif ( is_scalar( $chunk ) ) {
					$parts[] = (string) $chunk;
				}
			}

			if ( $parts ) {
				return trim( implode( "\n", array_filter( $parts ) ) );
			}

			// Fallback to JSON for unrecognized structures.
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
	 * @return string Truncated string.
	 */
	public function truncate_string( string $value, int $limit = self::PAYLOAD_PREVIEW_LIMIT ): string {
		$value = trim( $value );

		if ( '' === $value ) {
			return $value;
		}

		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			if ( mb_strlen( $value, 'UTF-8' ) <= $limit ) {
				return $value;
			}

			return mb_substr( $value, 0, $limit, 'UTF-8' ) . '…';
		}

		if ( strlen( $value ) <= $limit ) {
			return $value;
		}

		return substr( $value, 0, $limit ) . '...';
	}
}
