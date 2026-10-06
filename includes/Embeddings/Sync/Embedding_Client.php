<?php
/**
 * Generates embeddings through the AI client SDK.
 *
 * @package WordPress\AI\Embeddings\Sync
 */

declare( strict_types=1 );

namespace WordPress\AI\Embeddings\Sync;

use Throwable;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Builders\EmbeddingBuilder;
use WordPress\AiClient\Common\Exception\InvalidArgumentException as Ai_Invalid_Argument_Exception;
use WordPress\AiClient\Common\Exception\TokenLimitReachedException;
use WordPress\AiClient\Providers\Http\Exception\ClientException;
use WordPress\AiClient\Providers\Http\Exception\NetworkException;
use WordPress\AiClient\Providers\Http\Exception\ServerException;

use function WordPress\AI\supports_embedding_generation;

defined( 'ABSPATH' ) || exit;

/**
 * Calls `EmbeddingBuilder` directly rather than `generate_embeddings()`, which flattens every
 * failure into one `WP_Error` and so hides the HTTP status the sync layer needs.
 *
 * @since x.x.x
 */
class Embedding_Client implements Embedding_Client_Interface {

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function embed( Embedding_Target $target, array $inputs ): array {
		if ( array() === $inputs ) {
			return array();
		}

		if ( ! supports_embedding_generation() ) {
			throw new Embedding_Client_Exception( 'Embedding generation is not available in this environment.', Embedding_Client_Exception::PROVIDER );
		}

		try {
			$builder = new EmbeddingBuilder( AiClient::defaultRegistry(), $inputs );
			$builder->usingProviderModel( $target->get_provider(), $target->get_model() );

			if ( null !== $target->get_dimensions() ) {
				$builder->usingDimensions( $target->get_dimensions() );
			}

			$result = $builder->generateEmbeddingResult();
		} catch ( Throwable $e ) {
			throw new Embedding_Client_Exception( esc_html( $e->getMessage() ), self::classify( $e ), $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$vectors = array();

		foreach ( $result->getEmbeddings() as $embedding ) {
			$vectors[] = array_map( 'floatval', array_values( $embedding->getValues() ) );
		}

		// Defensive guard for model paths that bypass the builder's own count check.
		if ( count( $vectors ) !== count( $inputs ) ) {
			throw new Embedding_Client_Exception(
				esc_html( sprintf( 'Expected %d embeddings but received %d.', count( $inputs ), count( $vectors ) ) ),
				Embedding_Client_Exception::TRANSIENT
			);
		}

		return $vectors;
	}

	/**
	 * Classifies an SDK failure.
	 *
	 * HTTP client and server exceptions carry the status code as their exception code. Retry-After
	 * is not exposed by the SDK, so rate-limit delays are computed by {@see Provider_Backoff}.
	 *
	 * @since x.x.x
	 *
	 * @param \Throwable $error The failure.
	 * @return string One of the Embedding_Client_Exception class constants.
	 */
	public static function classify( Throwable $error ): string {
		// ClientException extends the SDK's InvalidArgumentException, so it must be checked first.
		if ( $error instanceof ClientException ) {
			$status = (int) $error->getCode();

			if ( 429 === $status ) {
				return Embedding_Client_Exception::RATE_LIMITED;
			}

			if ( 408 === $status ) {
				return Embedding_Client_Exception::TRANSIENT;
			}

			if ( in_array( $status, array( 401, 403, 404 ), true ) ) {
				return Embedding_Client_Exception::PROVIDER;
			}

			return Embedding_Client_Exception::ITEM;
		}

		if ( $error instanceof TokenLimitReachedException ) {
			return Embedding_Client_Exception::ITEM;
		}

		if ( $error instanceof ServerException || $error instanceof NetworkException ) {
			return Embedding_Client_Exception::TRANSIENT;
		}

		// Unregistered provider, unknown model, unsupported capability: no request can succeed.
		if ( $error instanceof Ai_Invalid_Argument_Exception ) {
			return Embedding_Client_Exception::PROVIDER;
		}

		return Embedding_Client_Exception::TRANSIENT;
	}
}
