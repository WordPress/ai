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

use WordPress\AiClient\AiClient;
use WP_CLI;
use WP_CLI\Utils;

use function WordPress\AI\has_valid_ai_credentials;
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
	private const ALLOWED_PROVIDERS = array( 'openai', 'google', 'ollama' );

	/**
	 * Generates embeddings for text.
	 *
	 * ## OPTIONS
	 *
	 * [<text>]
	 * : Text to generate embeddings for. Required unless --post_id is set.
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
	 * [--provider=<provider>]
	 * : AI provider to use. One of: openai, google, ollama.
	 *
	 * ## EXAMPLES
	 *
	 *     # Generate embeddings for specific text
	 *     $ wp ai embeddings generate 'This is some text' --dry-run=false
	 *
	 *     # Dry run to see what would be processed
	 *     $ wp ai embeddings generate 'This is some text' --dry-run=true
	 *
	 *     # Generate embeddings for specific post content
	 *     $ wp ai embeddings generate --post-id=42 --dry-run=false
	 *
	 *     # Chunk post content and generate embeddings for the chunks
	 *     $ wp ai embeddings generate --post-id=42 --chunk --dry-run=false
	 *
	 *     # Force a specific provider
	 *     $ wp ai embeddings generate 'This is some text' --provider=openai --dry-run=false
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

		if ( ! $dry_run && ! has_valid_ai_credentials() ) {
			WP_CLI::error( 'No valid AI credentials found. Configure a provider in Settings > Connectors.' );
			return; // WP_CLI::error() exits, but this satisfies static analysis.
		}

		$pieces = $chunk ? $this->chunk_text( $text ) : array( $text );

		if ( empty( $pieces ) ) {
			WP_CLI::error( 'No content to embed.' );
			return;
		}

		if ( $dry_run ) {
			if ( null !== $provider ) {
				WP_CLI::log( sprintf( 'Dry run: would use provider "%s".', $provider ) );
			}

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
				? sprintf( 'Generating embeddings for %d chunk(s).', $total )
				: sprintf( 'Generating embeddings for text: %s', $text )
		);

		try {
			if ( $chunk ) {
				$builder = AiClient::prompt()->withInputs( $pieces );
				if ( null !== $provider ) {
					$builder->usingProvider( $provider );
				}
				$result     = $builder->generateEmbeddingResult();
				$embeddings = $result->getEmbeddings();
			} else {
				$builder = AiClient::prompt( $pieces[0] );
				if ( null !== $provider ) {
					$builder->usingProvider( $provider );
				}
				$result     = $builder->generateEmbeddingResult();
				$embeddings = array( $result->getEmbedding() );
			}
		} catch ( \Throwable $e ) {
			WP_CLI::error(
				sprintf(
					'Error generating embeddings: %s',
					$e->getMessage()
				)
			);
			return;
		}

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
	 * Resolves and validates an optional --provider flag.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return string|null Provider ID, or null when not specified.
	 */
	private function resolve_provider( array $assoc_args ): ?string {
		$provider = (string) Utils\get_flag_value( $assoc_args, 'provider', '' );
		$provider = strtolower( trim( $provider ) );

		if ( '' === $provider ) {
			return null;
		}

		if ( ! in_array( $provider, self::ALLOWED_PROVIDERS, true ) ) {
			WP_CLI::error(
				sprintf(
					'Invalid --provider "%s". Allowed values: %s.',
					$provider,
					implode( ', ', self::ALLOWED_PROVIDERS )
				)
			);
			return null; // WP_CLI::error() exits, but this satisfies static analysis.
		}

		return $provider;
	}

	/**
	 * Resolves the text to embed from positional args or --post_id.
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
