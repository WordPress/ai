<?php
/**
 * Minimal OpenAI embeddings client for RAG search.
 *
 * @package WordPress\AI\RAG
 */

declare( strict_types=1 );

namespace WordPress\AI\RAG;

use Throwable;
use WP_Error;
use WordPress\AiClient\AiClient;
use function WordPress\AI\has_connector_authentication;

defined( 'ABSPATH' ) || exit;

/**
 * Calls OpenAI embeddings directly until AI Client exposes embedding execution.
 *
 * @since 1.1.0
 */
class OpenAI_Embedding_Client {
	/**
	 * OpenAI connector identifier.
	 */
	public const CONNECTOR_ID = 'openai';

	/**
	 * Default supported embedding model.
	 */
	public const DEFAULT_MODEL = 'text-embedding-3-small';

	/**
	 * OpenAI embeddings endpoint.
	 */
	private const ENDPOINT = 'https://api.openai.com/v1/embeddings';

	/**
	 * Embedding dimensions for the supported model.
	 */
	private const DIMENSIONS = 1536;

	/**
	 * Embeds text inputs.
	 *
	 * @since 1.1.0
	 *
	 * @param list<string> $texts Text inputs.
	 * @return list<list<float>>|\WP_Error Embedding vectors or error.
	 */
	public function embed( array $texts ) {
		$texts = array_values(
			array_filter(
				array_map( 'strval', $texts ),
				static fn( string $text ): bool => '' !== trim( $text )
			)
		);

		if ( empty( $texts ) ) {
			return array();
		}

		$api_key = $this->get_api_key();
		if ( '' === $api_key ) {
			return new WP_Error( 'wpai_rag_missing_openai_key', __( 'OpenAI API key is not available.', 'ai' ) );
		}

		$batch_size = max( 1, min( 64, (int) apply_filters( 'wpai_rag_embedding_batch_size', 32 ) ) );
		$vectors    = array();

		foreach ( array_chunk( $texts, $batch_size ) as $batch ) {
			$response = $this->request_batch_with_retries( array_values( $batch ), $api_key );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$vectors = array_merge( $vectors, $response );
		}

		return $vectors;
	}

	/**
	 * Checks whether OpenAI embeddings can be generated.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True when supported and authenticated.
	 */
	public function is_available(): bool {
		return $this->has_connector_authentication() && $this->supports_configured_model();
	}

	/**
	 * Returns the unavailable reason.
	 *
	 * @since 1.1.0
	 *
	 * @return string Reason.
	 */
	public function get_unavailable_reason(): string {
		if ( ! $this->has_connector_authentication() ) {
			return __( 'The OpenAI connector must be active and authenticated to generate embeddings.', 'ai' );
		}

		if ( ! $this->supports_configured_model() ) {
			return __( 'RAG Search currently supports OpenAI text-embedding-3-small embeddings only.', 'ai' );
		}

		return '';
	}

	/**
	 * Returns the configured embedding model.
	 *
	 * @since 1.1.0
	 *
	 * @return string Embedding model ID.
	 */
	public function get_model(): string {
		/**
		 * Filters the OpenAI embedding model used for RAG indexing.
		 *
		 * The table schema in this release is fixed to 1536 dimensions.
		 *
		 * @since 1.1.0
		 *
		 * @param string $model OpenAI embedding model.
		 */
		return (string) apply_filters( 'wpai_rag_embedding_model', self::DEFAULT_MODEL );
	}

	/**
	 * Returns the embedding vector dimension count.
	 *
	 * @since 1.1.0
	 *
	 * @return int Dimension count.
	 */
	public function get_dimensions(): int {
		return self::DIMENSIONS;
	}

	/**
	 * Requests one batch with retry for transient failures.
	 *
	 * @since 1.1.0
	 *
	 * @param list<string> $texts   Text inputs.
	 * @param string       $api_key API key.
	 * @return list<list<float>>|\WP_Error Embedding vectors or error.
	 */
	private function request_batch_with_retries( array $texts, string $api_key ) {
		$attempts = max( 1, min( 5, (int) apply_filters( 'wpai_rag_embedding_retry_attempts', 3 ) ) );
		$last     = null;

		for ( $attempt = 1; $attempt <= $attempts; ++$attempt ) {
			$result = $this->request_batch( $texts, $api_key );

			if ( ! is_wp_error( $result ) ) {
				return $result;
			}

			$last        = $result;
			$status_code = (int) $result->get_error_data( 'status' );
			$retryable   = 429 === $status_code || $status_code >= 500 || 0 === $status_code;

			if ( ! $retryable || $attempt === $attempts ) {
				break;
			}

			$delay = max( 0, (int) apply_filters( 'wpai_rag_embedding_retry_delay', $attempt ) );
			if ( $delay <= 0 ) {
				continue;
			}

			sleep( $delay );
		}

		return $last instanceof WP_Error ? $last : new WP_Error( 'wpai_rag_embedding_failed', __( 'Embedding request failed.', 'ai' ) );
	}

	/**
	 * Requests one OpenAI embeddings batch.
	 *
	 * @since 1.1.0
	 *
	 * @param list<string> $texts   Text inputs.
	 * @param string       $api_key API key.
	 * @return list<list<float>>|\WP_Error Embedding vectors or error.
	 */
	private function request_batch( array $texts, string $api_key ) {
		$body = array(
			'model' => $this->get_model(),
			'input' => $texts,
		);
		$json = wp_json_encode( $body );

		if ( ! is_string( $json ) || '' === $json ) {
			return new WP_Error( 'wpai_rag_embedding_invalid_request', __( 'Failed to encode the embeddings request.', 'ai' ), array( 'status' => 0 ) );
		}

		// TODO: Replace this direct OpenAI request once AI Client exposes embedding execution.
		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'timeout' => max( 5, (int) apply_filters( 'wpai_rag_embedding_request_timeout', 30 ) ),
				'body'    => $json,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wpai_rag_embedding_http_error',
				$response->get_error_message(),
				array( 'status' => 0 )
			);
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$raw_body    = wp_remote_retrieve_body( $response );

		if ( $status_code < 200 || $status_code >= 300 ) {
			return new WP_Error(
				'wpai_rag_embedding_http_error',
				$this->extract_error_message( $raw_body ),
				array( 'status' => $status_code )
			);
		}

		$data = json_decode( $raw_body, true );
		if ( ! is_array( $data ) || empty( $data['data'] ) || ! is_array( $data['data'] ) ) {
			return new WP_Error( 'wpai_rag_embedding_invalid_response', __( 'OpenAI returned an invalid embeddings response.', 'ai' ), array( 'status' => $status_code ) );
		}

		$ordered = array();
		foreach ( $data['data'] as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['index'], $item['embedding'] ) || ! is_array( $item['embedding'] ) ) {
				return new WP_Error( 'wpai_rag_embedding_invalid_response', __( 'OpenAI returned an invalid embedding item.', 'ai' ), array( 'status' => $status_code ) );
			}

			$embedding = array_values( array_map( 'floatval', $item['embedding'] ) );
			if ( count( $embedding ) !== $this->get_dimensions() ) {
				return new WP_Error( 'wpai_rag_embedding_dimensions_mismatch', __( 'OpenAI returned an embedding with unexpected dimensions.', 'ai' ), array( 'status' => $status_code ) );
			}

			$ordered[ (int) $item['index'] ] = $embedding;
		}

		ksort( $ordered );

		return array_values( $ordered );
	}

	/**
	 * Resolves the OpenAI API key from the connector.
	 *
	 * @since 1.1.0
	 *
	 * @return string API key or empty string.
	 */
	private function get_api_key(): string {
		/**
		 * Filters the API key used by the RAG OpenAI embeddings client.
		 *
		 * Useful for tests and alternate secret storage.
		 *
		 * @since 1.1.0
		 *
		 * @param string $api_key API key.
		 */
		$filtered = apply_filters( 'wpai_rag_openai_embedding_api_key', '' );
		if ( is_string( $filtered ) && '' !== $filtered ) {
			return $filtered;
		}

		if ( class_exists( AiClient::class ) ) {
			try {
				$auth = AiClient::defaultRegistry()->getProviderRequestAuthentication( self::CONNECTOR_ID );
				if ( is_object( $auth ) && method_exists( $auth, 'getApiKey' ) ) {
					$key = $auth->getApiKey();
					if ( is_string( $key ) && '' !== $key ) {
						return $key;
					}
				}
			} catch ( Throwable $e ) {
				unset( $e );
				// Fall back to connector metadata below.
			}
		}

		if ( ! function_exists( 'wp_get_connector' ) ) {
			return '';
		}

		$connector = wp_get_connector( self::CONNECTOR_ID );
		if ( ! is_array( $connector ) || empty( $connector['authentication'] ) || ! is_array( $connector['authentication'] ) ) {
			return '';
		}

		$auth          = $connector['authentication'];
		$env_var_name  = isset( $auth['env_var_name'] ) && is_string( $auth['env_var_name'] ) ? $auth['env_var_name'] : '';
		$constant_name = isset( $auth['constant_name'] ) && is_string( $auth['constant_name'] ) ? $auth['constant_name'] : '';
		$setting_name  = isset( $auth['setting_name'] ) && is_string( $auth['setting_name'] ) ? $auth['setting_name'] : '';

		if ( '' !== $env_var_name ) {
			$value = getenv( $env_var_name );
			if ( false !== $value && '' !== $value ) {
				return $value;
			}
		}

		if ( '' !== $constant_name && defined( $constant_name ) ) {
			$value = constant( $constant_name );
			if ( is_string( $value ) && '' !== $value ) {
				return $value;
			}
		}

		if ( '' !== $setting_name ) {
			$value = get_option( $setting_name, '' );
			if ( is_string( $value ) && '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Extracts an API error message.
	 *
	 * @since 1.1.0
	 *
	 * @param string $raw_body Raw response body.
	 * @return string Error message.
	 */
	private function extract_error_message( string $raw_body ): string {
		$data = json_decode( $raw_body, true );

		if ( is_array( $data ) && isset( $data['error']['message'] ) && is_string( $data['error']['message'] ) ) {
			return $data['error']['message'];
		}

		return __( 'OpenAI embeddings request failed.', 'ai' );
	}

	/**
	 * Checks that the OpenAI connector is active and has credentials.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True when OpenAI can be authenticated.
	 */
	private function has_connector_authentication(): bool {
		try {
			return function_exists( 'wp_is_connector_registered' )
				&& wp_is_connector_registered( self::CONNECTOR_ID )
				&& has_connector_authentication( self::CONNECTOR_ID );
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/**
	 * Checks whether the configured model matches the fixed schema.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True when supported.
	 */
	private function supports_configured_model(): bool {
		return self::DEFAULT_MODEL === $this->get_model();
	}
}
