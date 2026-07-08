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

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Generates embeddings for text.
 *
 * @since x.x.x
 */
class Embeddings_Command {

	/**
	 * Generates embeddings for text.
	 *
	 * ## OPTIONS
	 *
	 * [<text>]
	 * : Text to generate embeddings for.
	 *
	 * [--dry-run]
	 * : Show what would be processed without making changes.
	 *
	 * [--post_id=<id>]
	 * : Specific post ID to process post content for.
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
	 *     $ wp ai embeddings generate --post_id=42 --dry-run=false
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function generate( $args, $assoc_args ): void {
		$text    = $args[0] ?? '';
		$dry_run = filter_var( Utils\get_flag_value( $assoc_args, 'dry-run', true ), FILTER_VALIDATE_BOOLEAN );
		$post_id = (int) Utils\get_flag_value( $assoc_args, 'post_id', 0 );

		if ( empty( $text ) ) {
			WP_CLI::error( 'Text is required.' );
			return;
		}

		if ( ! $dry_run && ! has_valid_ai_credentials() ) {
			WP_CLI::error( 'No valid AI credentials found. Configure a provider in Settings > Connectors.' );
			return; // WP_CLI::error() exits, but this satisfies static analysis.
		}

		if ( $dry_run ) {
			WP_CLI::log( 'Dry run: would have generated embeddings for text: ' . $text );
			return;
		}

		WP_CLI::log( sprintf( 'Generating embeddings for text: %s', $text ) );

		try {
			$result    = AiClient::prompt( $text )->generateEmbeddingResult();
			$embedding = $result->getEmbedding();
		} catch ( \Exception $e ) {
			WP_CLI::error( 'Error generating embeddings: ' . $e->getMessage() );
			return;
		}

		WP_CLI::success( 'Embeddings generated successfully.' );

		WP_CLI::log( sprintf( 'Provider: %s', $result->getProviderMetadata()->getId() ) );
		WP_CLI::log( sprintf( 'Model: %s', $result->getModelMetadata()->getId() ) );
		WP_CLI::log( sprintf( 'Token Usage: %s', $result->getTokenUsage()->getTotalTokens() ) );
		WP_CLI::log( sprintf( 'Embedding: %s', $this->preview_vector( $embedding->getValues() ) ) );
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
