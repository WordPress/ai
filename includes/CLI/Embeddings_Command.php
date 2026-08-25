<?php
/**
 * WP-CLI command for generating embeddings for text.
 *
 * @package WordPress\AI
 *
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\CLI;

use WP_CLI;
use WP_CLI\Utils;

use function WordPress\AI\generate_embeddings;
use function WordPress\AI\normalize_content;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Generates embeddings for text.
 *
 * @since x.x.x
 */
class Embeddings_Command {

	/**
	 * Maximum characters per chunk.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	private const CHUNK_SIZE = 750;

	/**
	 * Characters of overlap between consecutive chunks.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	private const CHUNK_OVERLAP = 125;

	/**
	 * Allowed providers.
	 *
	 * @since x.x.x
	 *
	 * @var list<string>
	 */
	private const ALLOWED_PROVIDERS = array( 'openai', 'google', 'ollama' ); // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition.DisallowedMultiConstantDefinition

	/**
	 * Generates embeddings for text.
	 *
	 * A provider and model are always required. Embedding vectors are only comparable to other
	 * vectors from the same model, so nothing is chosen on your behalf — see
	 * {@see \WordPress\AI\generate_embeddings()}.
	 *
	 * ## OPTIONS
	 *
	 * [<text>]
	 * : Text to generate embeddings for. Required unless --post-id is set.
	 *
	 * --provider=<provider>
	 * : AI provider that offers the model. One of: openai, google, ollama.
	 *
	 * --model=<model>
	 * : Embedding model ID to generate with, for example text-embedding-3-small.
	 *
	 * [--dry-run]
	 * : Show what would be processed without making API calls.
	 *
	 * [--post-id=<id>]
	 * : Post ID whose content should be embedded.
	 *
	 * [--chunk]
	 * : Split content into overlapping chunks and embed each chunk.
	 *
	 * ## EXAMPLES
	 *
	 *     # Generate embeddings for specific text
	 *     $ wp ai embeddings generate 'This is some text' --provider=openai --model=text-embedding-3-small --dry-run=false
	 *
	 *     # Dry run to see what would be processed
	 *     $ wp ai embeddings generate 'This is some text' --provider=openai --model=text-embedding-3-small --dry-run=true
	 *
	 *     # Generate embeddings for specific post content
	 *     $ wp ai embeddings generate --post-id=42 --provider=openai --model=text-embedding-3-small --dry-run=false
	 *
	 *     # Chunk post content and generate embeddings for the chunks
	 *     $ wp ai embeddings generate --post-id=42 --chunk --provider=openai --model=text-embedding-3-small --dry-run=false
	 *
	 *     # Use a different provider and model
	 *     $ wp ai embeddings generate 'This is some text' --provider=google --model=gemini-embedding-001 --dry-run=false
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function generate( $args, $assoc_args ): void {
		$text     = $this->resolve_text( $args, $assoc_args );
		$dry_run  = filter_var( Utils\get_flag_value( $assoc_args, 'dry-run', true ), FILTER_VALIDATE_BOOLEAN );
		$chunk    = (bool) Utils\get_flag_value( $assoc_args, 'chunk', false );
		$provider = $this->resolve_provider( $assoc_args );
		$model    = $this->resolve_model( $assoc_args );

		$pieces = $chunk ? $this->chunk_text( $text ) : array( $text );

		if ( empty( $pieces ) ) {
			WP_CLI::error( 'No content to embed.' );
			return;
		}

		if ( $dry_run ) {
			WP_CLI::log( sprintf( 'Dry run: would use model "%s" from provider "%s".', $model, $provider ) );

			if ( $chunk ) {
				WP_CLI::log( sprintf( 'Dry run: would embed %d chunk(s).', count( $pieces ) ) );

				foreach ( $pieces as $index => $piece ) {
					$char_count = mb_strlen( $piece );
					$preview    = $char_count > 80 ? mb_substr( $piece, 0, 80 ) . '...' : $piece;
					WP_CLI::log( sprintf( '  [%d] (%d chars) %s', $index + 1, $char_count, $preview ) );
				}
			} else {
				WP_CLI::log( 'Dry run: would have generated embeddings for text: ' . $text );
			}

			return;
		}

		$total = count( $pieces );
		WP_CLI::log(
			$chunk
				? sprintf( 'Generating embeddings for %d chunk(s) using model "%s" from provider "%s".', $total, $model, $provider )
				: sprintf( 'Generating embeddings using model "%s" from provider "%s" for text: %s', $model, $provider, $text )
		);

		$result = generate_embeddings(
			$chunk ? $pieces : $pieces[0],
			array(
				'provider' => $provider,
				'model'    => $model,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( sprintf( 'Error generating embeddings: %s', $result->get_error_message() ) );
			return;
		}

		$embeddings = $result->getEmbeddings();

		WP_CLI::log( sprintf( 'Provider: %s', $result->getProviderMetadata()->getId() ) );
		WP_CLI::log( sprintf( 'Model: %s', $result->getModelMetadata()->getId() ) );
		WP_CLI::log( sprintf( 'Token Usage: %s', $result->getTokenUsage()->getTotalTokens() ) );

		foreach ( $embeddings as $embedding ) {
			WP_CLI::log( sprintf( 'Embedding: %s', $this->preview_vector( $embedding->getValues() ) ) );
		}

		WP_CLI::success(
			$chunk
				? sprintf( 'Embeddings generated successfully for %d chunk(s).', $total )
				: 'Embeddings generated successfully.'
		);
	}

	/**
	 * Resolves and validates the required --provider flag.
	 *
	 * WP-CLI enforces the flag's presence from the command synopsis; the empty check here covers
	 * the class being invoked directly.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return string Provider ID.
	 */
	private function resolve_provider( array $assoc_args ): string {
		$provider = (string) Utils\get_flag_value( $assoc_args, 'provider', '' );
		$provider = strtolower( trim( $provider ) );

		if ( '' === $provider ) {
			WP_CLI::error(
				sprintf(
					'A --provider is required. Allowed values: %s.',
					implode( ', ', self::ALLOWED_PROVIDERS )
				)
			);
			return ''; // WP_CLI::error() exits, but this satisfies static analysis.
		}

		if ( ! in_array( $provider, self::ALLOWED_PROVIDERS, true ) ) {
			WP_CLI::error(
				sprintf(
					'Invalid --provider "%s". Allowed values: %s.',
					$provider,
					implode( ', ', self::ALLOWED_PROVIDERS )
				)
			);
			return ''; // WP_CLI::error() exits, but this satisfies static analysis.
		}

		return $provider;
	}

	/**
	 * Resolves the required --model flag.
	 *
	 * Model IDs are not validated against a list here: which models a provider offers changes
	 * independently of this plugin, so the provider is left to reject an unknown ID.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return string Embedding model ID.
	 */
	private function resolve_model( array $assoc_args ): string {
		$model = trim( (string) Utils\get_flag_value( $assoc_args, 'model', '' ) );

		if ( '' === $model ) {
			WP_CLI::error(
				'A --model is required. Embedding vectors are only comparable to other vectors from '
				. 'the same model, so no model is selected automatically.'
			);
			return ''; // WP_CLI::error() exits, but this satisfies static analysis.
		}

		return $model;
	}

	/**
	 * Resolves the text to embed from positional args or --post-id.
	 *
	 * Exactly one source is required. Post content is normalized to plain text.
	 *
	 * @since x.x.x
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return string Non-empty text to embed.
	 */
	private function resolve_text( array $args, array $assoc_args ): string {
		$text     = isset( $args[0] ) ? (string) $args[0] : '';
		$post_id  = (int) Utils\get_flag_value( $assoc_args, 'post-id', 0 );
		$has_text = '' !== trim( $text );
		$has_post = $post_id > 0;

		if ( $has_text && $has_post ) {
			WP_CLI::error( 'Provide either positional text or --post-id, not both.' );
			return ''; // WP_CLI::error() exits, but this satisfies static analysis.
		}

		if ( ! $has_text && ! $has_post ) {
			WP_CLI::error( 'Provide positional text or --post-id.' );
			return ''; // WP_CLI::error() exits, but this satisfies static analysis.
		}

		if ( $has_post ) {
			$post = get_post( $post_id );

			if ( ! $post instanceof \WP_Post ) {
				WP_CLI::error( sprintf( 'Post %d not found.', $post_id ) );
				return ''; // WP_CLI::error() exits, but this satisfies static analysis.
			}

			$text = normalize_content( (string) $post->post_content );
		}

		$text = trim( $text );
		if ( '' === $text ) {
			WP_CLI::error( 'Resolved content is empty.' );
			return ''; // WP_CLI::error() exits, but this satisfies static analysis.
		}

		return $text;
	}

	/**
	 * Splits text into overlapping character chunks.
	 *
	 * Prefers ending a chunk at whitespace or sentence punctuation near the
	 * window end. Consecutive chunks overlap by CHUNK_OVERLAP characters.
	 *
	 * @since x.x.x
	 *
	 * @param string $text Text to chunk.
	 * @return list<string> Chunks (empty if input is empty/whitespace-only).
	 */
	private function chunk_text( string $text ): array {
		$text = trim( $text );
		if ( '' === $text ) {
			return array();
		}

		$length = mb_strlen( $text );
		if ( $length <= self::CHUNK_SIZE ) {
			return array( $text );
		}

		$chunks = array();
		$start  = 0;

		while ( $start < $length ) {
			$remaining = $length - $start;
			if ( $remaining <= self::CHUNK_SIZE ) {
				$chunk = trim( mb_substr( $text, $start ) );
				if ( '' !== $chunk ) {
					$chunks[] = $chunk;
				}
				break;
			}

			$window     = mb_substr( $text, $start, self::CHUNK_SIZE );
			$end_offset = $this->find_natural_break( $window );
			$chunk      = trim( mb_substr( $text, $start, $end_offset ) );
			if ( '' !== $chunk ) {
				$chunks[] = $chunk;
			}

			$advance = $end_offset - self::CHUNK_OVERLAP;
			if ( $advance < 1 ) {
				$advance = 1;
			}
			$start += $advance;
		}

		return $chunks;
	}

	/**
	 * Finds a preferred end offset within a chunk window.
	 *
	 * Looks in the last ~25% of the window for whitespace or sentence
	 * punctuation. Falls back to the full window length.
	 *
	 * @since x.x.x
	 *
	 * @param string $window Candidate chunk window (length <= CHUNK_SIZE).
	 * @return int End offset relative to the window start (1..mb_strlen( $window )).
	 */
	private function find_natural_break( string $window ): int {
		$window_length = mb_strlen( $window );
		if ( $window_length <= 1 ) {
			return max( 1, $window_length );
		}

		$search_from = (int) floor( $window_length * 0.75 );
		$best        = 0;

		for ( $i = $window_length - 1; $i >= $search_from; $i-- ) {
			$char = mb_substr( $window, $i, 1 );
			if ( ctype_space( $char ) || in_array( $char, array( '.', '!', '?', ';', ':' ), true ) ) {
				$best = $i + 1;
				break;
			}
		}

		return $best > 0 ? $best : $window_length;
	}

	/**
	 * Formats an embedding vector for concise display.
	 *
	 * @param array<int, float|int> $values Vector values.
	 * @return string The formatted preview.
	 */
	private function preview_vector( array $values ): string {
		$head = array_slice( $values, 0, 5 );
		$head = array_map(
			static function ( $value ): string {
				return rtrim( rtrim( sprintf( '%.5f', $value ), '0' ), '.' );
			},
			$head
		);
		return '[' . implode( ', ', $head ) . ', ...] (' . count( $values ) . ' dims)';
	}
}
