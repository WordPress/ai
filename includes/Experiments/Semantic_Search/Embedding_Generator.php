<?php
/**
 * Embedding generation via the PHP AI Client.
 *
 * Provider selection, authentication and transport are all handled by the
 * registered connectors, so this class contains no provider-specific code.
 *
 * @package WordPress\AI\Experiments\Semantic_Search
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Semantic_Search;

use function WordPress\AI\generate_embeddings;
use function WordPress\AI\has_ai_credentials;
use function WordPress\AI\supports_embedding_generation;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Turns text into embedding vectors using the PHP AI Client.
 *
 * The experiment only stores an optional model preference and a similarity
 * threshold. Credentials and endpoints belong to the connector configuration
 * under Settings → AI, not to this experiment.
 *
 * @internal
 * @since x.x.x
 */
class Embedding_Generator {

	/**
	 * Similarity score cut-off used when the user has not configured one.
	 *
	 * @since x.x.x
	 * @var float
	 */
	public const DEFAULT_SCORE_THRESHOLD = 0.5;

	/**
	 * Human-readable description of the last failure, or empty string if none.
	 *
	 * @since x.x.x
	 * @var string
	 */
	private string $last_error = '';

	/**
	 * Returns the configured embedding model preference.
	 *
	 * An empty string means "let the AI Client pick", which is the default.
	 *
	 * @since x.x.x
	 *
	 * @return string Model identifier, or empty string when unset.
	 */
	public function get_model(): string {
		return trim( (string) get_option( Semantic_Search::get_field_option_name( 'model' ), '' ) );
	}

	/**
	 * Returns the human-readable error from the most recent generate() call.
	 *
	 * @since x.x.x
	 *
	 * @return string Error description, or empty string on success.
	 */
	public function get_last_error(): string {
		return $this->last_error;
	}

	/**
	 * Returns whether embeddings can currently be generated.
	 *
	 * Requires both an environment that exposes the embedding API and at least
	 * one connector with credentials. When this returns false, callers should
	 * fall back to default WordPress search rather than attempting a request.
	 *
	 * @since x.x.x
	 *
	 * @return bool True when embedding generation is possible.
	 */
	public function is_available(): bool {
		return supports_embedding_generation() && has_ai_credentials();
	}

	/**
	 * Returns the cosine similarity threshold below which results are discarded.
	 *
	 * Reads the user-saved option and falls back to DEFAULT_SCORE_THRESHOLD when
	 * it is empty or out of the [0, 1] range.
	 *
	 * @since x.x.x
	 *
	 * @return float Cosine similarity cut-off in the range [0, 1].
	 */
	public function get_score_threshold(): float {
		$saved = (string) get_option( Semantic_Search::get_field_option_name( 'score_threshold' ), '' );

		if ( '' === trim( $saved ) || ! is_numeric( $saved ) ) {
			return self::DEFAULT_SCORE_THRESHOLD;
		}

		$threshold = (float) $saved;

		if ( $threshold < 0.0 || $threshold > 1.0 ) {
			return self::DEFAULT_SCORE_THRESHOLD;
		}

		return $threshold;
	}

	/**
	 * Generates an embedding vector for a single string of text.
	 *
	 * Returns null on any failure; call get_last_error() for the reason.
	 *
	 * @since x.x.x
	 *
	 * @param string $text The text to embed.
	 * @return float[]|null Float vector on success, null on failure.
	 */
	public function generate( string $text ): ?array {
		$this->last_error = '';

		$model = $this->get_model();
		$args  = '' !== $model ? array( 'model_preference' => array( $model ) ) : array();

		$result = generate_embeddings( $text, $args );

		if ( is_wp_error( $result ) ) {
			$this->last_error = $result->get_error_message();
			return null;
		}

		$values = $result->getEmbedding()->getValues();

		if ( empty( $values ) ) {
			$this->last_error = __( 'The embedding provider returned an empty vector.', 'ai' );
			return null;
		}

		return array_map( 'floatval', $values );
	}
}
