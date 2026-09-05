<?php
/**
 * WP-CLI commands for generating and comparing embeddings.
 *
 * @package WordPress\AI
 *
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\CLI;

use WP_CLI;
use WP_CLI\Utils;
use WordPress\AI\Embeddings\Embedding_Record;
use WordPress\AI\Embeddings\Embedding_Repository;
use WordPress\AI\Embeddings\Embedding_Schema;
use WordPress\AI\Embeddings\Vector_Math;
use WordPress\AI\Embeddings\Vector_Ranker;
use WordPress\AiClient\Results\DTO\EmbeddingResult;

use function WordPress\AI\generate_embeddings;
use function WordPress\AI\normalize_content;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Generates embeddings for text and compares stored embedding vectors.
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
	 * Metric value that runs every comparison.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const METRIC_ALL = 'all';

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
	 * : Post ID whose content should be embedded. The generated vectors are stored in the
	 * embeddings table against this post, one row per chunk, and read back for inspection.
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
		$post_id  = (int) Utils\get_flag_value( $assoc_args, 'post-id', 0 );

		$pieces = $chunk ? $this->chunk_text( $text ) : array( $text );

		if ( empty( $pieces ) ) {
			WP_CLI::error( 'No content to embed.' );
			return;
		}

		if ( $dry_run ) {
			WP_CLI::log( sprintf( 'Dry run: would use model "%s" from provider "%s".', $model, $provider ) );

			if ( $post_id > 0 ) {
				WP_CLI::log( sprintf( 'Dry run: would store the vectors against post %d.', $post_id ) );
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

		// Only a post can be stored: a row is keyed by the object it describes.
		if ( $post_id > 0 ) {
			$content_hash = hash( 'sha256', $text );

			try {
				$stored = $this->save_embeddings( $post_id, $content_hash, $result );
			} catch ( \InvalidArgumentException | \RuntimeException $e ) {
				WP_CLI::error( sprintf( 'Generated the embeddings but could not store them: %s', $e->getMessage() ) );
				return;
			}

			WP_CLI::log( sprintf( 'Stored %d row(s) for post %d:', count( $stored ), $post_id ) );

			$this->log_stored_rows(
				$post_id,
				$result->getProviderMetadata()->getId(),
				$result->getModelMetadata()->getId()
			);
		} else {
			WP_CLI::log( 'Not stored: pass --post-id to file the vectors against a post.' );
		}

		WP_CLI::success(
			$chunk
				? sprintf( 'Embeddings generated successfully for %d chunk(s).', $total )
				: 'Embeddings generated successfully.'
		);
	}

	/**
	 * Compares the stored embedding vectors of two posts.
	 *
	 * Both posts must already have vectors stored for the same provider and model, because vectors
	 * from different models are not comparable. Run `wp ai embeddings generate --post-id=<id>` first
	 * if a post has none.
	 *
	 * Posts stored as several chunks are compared at two levels: the centroid of each post's chunks
	 * gives one post-level score, and the individual chunk pairs are ranked beneath it.
	 *
	 * ## OPTIONS
	 *
	 * <post-a>
	 * : ID of the first post.
	 *
	 * <post-b>
	 * : ID of the second post. May be the same as <post-a>, which is a quick way to check the whole
	 * path end to end: cosine similarity comes back as 1, Euclidean distance as 0.
	 *
	 * [--metric=<metric>]
	 * : Comparison to run.
	 * ---
	 * default: cosine
	 * options:
	 *   - cosine
	 *   - dot_product
	 *   - euclidean
	 *   - all
	 * ---
	 *
	 * [--pairs=<count>]
	 * : Number of chunk pairs to list when either post has more than one chunk.
	 * ---
	 * default: 3
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Compare two posts using the model they share
	 *     $ wp ai embeddings compare 42 99
	 *
	 *     # Run every metric
	 *     $ wp ai embeddings compare 42 99 --metric=all
	 *
	 *     # Check the path end to end against a post itself
	 *     $ wp ai embeddings compare 42 42
	 *
	 *     # List more chunk pairs
	 *     $ wp ai embeddings compare 42 99 --pairs=10
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function compare( $args, $assoc_args ): void {
		$post_a      = $this->resolve_post_id( $args, 0 );
		$post_b      = $this->resolve_post_id( $args, 1 );
		$metric      = $this->resolve_metric( $assoc_args );
		$pairs_limit = (int) Utils\get_flag_value( $assoc_args, 'pairs', 3 );

		if ( $pairs_limit < 1 ) {
			WP_CLI::error( '--pairs must be a positive integer.' );
			return;
		}

		[ $provider, $model ] = $this->resolve_stored_model( $post_a, $post_b );

		$repository = new Embedding_Repository();
		$vectors_a  = $this->load_vectors( $repository, $post_a, $provider, $model );
		$vectors_b  = $this->load_vectors( $repository, $post_b, $provider, $model );

		// One (provider, model) pair may legitimately hold two vector lengths, so the mismatch is
		// reported here rather than surfacing as an exception from the arithmetic.
		$dimensions_a = array_values( array_unique( array_map( 'count', $vectors_a ) ) );
		$dimensions_b = array_values( array_unique( array_map( 'count', $vectors_b ) ) );

		if ( count( $dimensions_a ) > 1 || count( $dimensions_b ) > 1 || $dimensions_a[0] !== $dimensions_b[0] ) {
			WP_CLI::error(
				sprintf(
					'Cannot compare: post %d stores %s-dimension vectors and post %d stores %s-dimension vectors. A comparison needs one dimension count for both.',
					$post_a,
					implode( '/', $dimensions_a ),
					$post_b,
					implode( '/', $dimensions_b )
				)
			);
			return;
		}

		WP_CLI::log( sprintf( 'Provider: %s / Model: %s', $provider, $model ) );
		$this->log_post_summary( $post_a, $vectors_a );
		$this->log_post_summary( $post_b, $vectors_b );
		WP_CLI::log( '' );

		$chunked    = count( $vectors_a ) > 1 || count( $vectors_b ) > 1;
		$centroid_a = Vector_Math::centroid( $vectors_a );
		$centroid_b = Vector_Math::centroid( $vectors_b );

		$metrics = self::METRIC_ALL === $metric
			? array( Vector_Math::METRIC_COSINE, Vector_Math::METRIC_DOT_PRODUCT, Vector_Math::METRIC_EUCLIDEAN )
			: array( $metric );

		foreach ( $metrics as $one ) {
			try {
				$score = $this->score_pair( $centroid_a, $centroid_b, $one );
			} catch ( \InvalidArgumentException $e ) {
				WP_CLI::error( sprintf( 'Could not compare the vectors: %s', $e->getMessage() ) );
				return;
			}

			WP_CLI::log(
				sprintf(
					'%s%s: %s%s',
					$this->metric_label( $one ),
					$chunked ? ' (centroid)' : '',
					$this->format_score( $score ),
					Vector_Math::METRIC_EUCLIDEAN === $one ? ' — lower is closer' : ''
				)
			);
		}

		if ( ! $chunked ) {
			WP_CLI::success( 'Comparison complete.' );
			return;
		}

		$this->log_chunk_pairs(
			$vectors_a,
			$vectors_b,
			self::METRIC_ALL === $metric ? Vector_Math::METRIC_COSINE : $metric,
			$pairs_limit
		);

		WP_CLI::success( 'Comparison complete.' );
	}

	/**
	 * Stores the generated vectors for a post, one row per chunk.
	 *
	 * @since x.x.x
	 *
	 * @param int                                                     $post_id      Post whose content was embedded.
	 * @param string                                                  $content_hash Hash of the whole source content.
	 * @param \WordPress\AiClient\Results\DTO\EmbeddingResult          $result       The generation result.
	 * @return list<\WordPress\AI\Embeddings\Embedding_Record> The stored records, carrying their row IDs.
	 *
	 * @throws \InvalidArgumentException If a record is rejected before anything is written.
	 * @throws \RuntimeException         If a record could not be written.
	 */
	private function save_embeddings( int $post_id, string $content_hash, EmbeddingResult $result ): array {
		// Provider and model come off the result rather than the flags, so the row records what
		// actually produced these vectors instead of what was asked for.
		$provider = $result->getProviderMetadata()->getId();
		$model    = $result->getModelMetadata()->getId();

		// The post type is the object subtype.
		$subtype = (string) get_post_type( $post_id );

		$records     = array();
		$chunk_index = 0;

		foreach ( $result->getEmbeddings() as $embedding ) {
			$records[] = new Embedding_Record(
				'post',
				$post_id,
				$provider,
				$model,
				$embedding->getValues(),
				$chunk_index,
				$content_hash,
				0,
				$subtype
			);

			++$chunk_index;
		}

		return ( new Embedding_Repository() )->save_many( $records );
	}

	/**
	 * Logs the rows as the database actually holds them.
	 *
	 * @since x.x.x
	 *
	 * @param int    $post_id  Post whose rows to show.
	 * @param string $provider Provider ID.
	 * @param string $model    Model ID.
	 */
	private function log_stored_rows( int $post_id, string $provider, string $model ): void {
		global $wpdb;

		$table = ( new Embedding_Schema() )->get_table_name();

		// A direct read is the point of this method: it exists to show what the row really contains,
		// so it deliberately bypasses the repository's hydration.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, chunk_index, object_type, object_subtype, dimensions,
					LENGTH( embedding ) AS embedding_bytes,
					LENGTH( embedding_coarse ) AS coarse_bytes,
					content_hash, created_at, updated_at
				FROM {$table}
				WHERE object_type = 'post' AND object_id = %d AND provider = %s AND model = %s
				ORDER BY chunk_index ASC",
				$post_id,
				$provider,
				$model
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_array( $rows ) || array() === $rows ) {
			WP_CLI::warning( 'No stored rows could be read back.' );
			return;
		}

		Utils\format_items(
			'table',
			$rows,
			array(
				'id',
				'chunk_index',
				'object_type',
				'object_subtype',
				'dimensions',
				'embedding_bytes',
				'coarse_bytes',
				'content_hash',
				'updated_at',
			)
		);
	}

	/**
	 * Ranks every chunk of one post against every chunk of the other and logs the closest pairs.
	 *
	 * @since x.x.x
	 *
	 * @param array<int, list<float>> $vectors_a Chunk vectors of the first post, keyed by chunk index.
	 * @param array<int, list<float>> $vectors_b Chunk vectors of the second post, keyed by chunk index.
	 * @param string                  $metric    A `Vector_Math::METRIC_*` constant.
	 * @param int                     $limit     Maximum number of pairs to list.
	 */
	private function log_chunk_pairs( array $vectors_a, array $vectors_b, string $metric, int $limit ): void {
		$pairs = array();

		foreach ( $vectors_a as $index_a => $vector_a ) {
			try {
				$ranked = Vector_Ranker::rank( $vector_a, $vectors_b, $metric );
			} catch ( \InvalidArgumentException $e ) {
				WP_CLI::error( sprintf( 'Could not compare the chunk vectors: %s', $e->getMessage() ) );
				return;
			}

			foreach ( $ranked as $index_b => $score ) {
				$pairs[ $index_a . ':' . $index_b ] = $score;
			}
		}

		$pairs = $this->sort_pairs( $pairs, $metric );
		$total = count( $pairs );
		$shown = array_slice( $pairs, 0, $limit, true );

		WP_CLI::log( '' );
		WP_CLI::log( sprintf( 'Closest chunk pairs (%s):', strtolower( $this->metric_label( $metric ) ) ) );

		$rows = array();
		foreach ( $shown as $key => $score ) {
			$indexes = explode( ':', (string) $key );
			$rows[]  = array(
				'chunk_a' => (int) $indexes[0],
				'chunk_b' => (int) $indexes[1],
				'score'   => $this->format_score( $score ),
			);
		}

		Utils\format_items( 'table', $rows, array( 'chunk_a', 'chunk_b', 'score' ) );

		if ( $total <= count( $shown ) ) {
			return;
		}

		WP_CLI::log( sprintf( '(showing %d of %d pairs; --pairs=%d for all)', count( $shown ), $total, $total ) );
	}

	/**
	 * Orders scored chunk pairs best first.
	 *
	 * `Vector_Ranker` orders the candidates within a single query vector; this mirrors its direction
	 * rule so that pairs collected across several query vectors share one order.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, float> $pairs  Scores keyed by "chunk_a:chunk_b".
	 * @param string               $metric A `Vector_Math::METRIC_*` constant.
	 * @return array<string, float> The same scores, best first.
	 */
	private function sort_pairs( array $pairs, string $metric ): array {
		if ( Vector_Math::METRIC_EUCLIDEAN === $metric ) {
			asort( $pairs );
		} else {
			arsort( $pairs );
		}

		return $pairs;
	}

	/**
	 * Scores one vector against another with the given metric.
	 *
	 * Routed through `Vector_Ranker` so that the metric dispatch lives in exactly one place.
	 *
	 * @since x.x.x
	 *
	 * @param list<float> $a      First vector.
	 * @param list<float> $b      Second vector.
	 * @param string      $metric A `Vector_Math::METRIC_*` constant.
	 * @return float The score.
	 *
	 * @throws \InvalidArgumentException If the vectors cannot be compared.
	 */
	private function score_pair( array $a, array $b, string $metric ): float {
		$ranked = Vector_Ranker::rank( $a, array( 0 => $b ), $metric );
		$scores = array_values( $ranked );

		return $scores[0];
	}

	/**
	 * Returns the display name of a metric.
	 *
	 * @since x.x.x
	 *
	 * @param string $metric A `Vector_Math::METRIC_*` constant.
	 * @return string Human-readable label.
	 */
	private function metric_label( string $metric ): string {
		switch ( $metric ) {
			case Vector_Math::METRIC_DOT_PRODUCT:
				return 'Dot product';
			case Vector_Math::METRIC_EUCLIDEAN:
				return 'Euclidean distance';
			default:
				return 'Cosine similarity';
		}
	}

	/**
	 * Formats a score for display.
	 *
	 * @since x.x.x
	 *
	 * @param float $score The score.
	 * @return string The formatted score.
	 */
	private function format_score( float $score ): string {
		return sprintf( '%.4f', $score );
	}

	/**
	 * Logs what was found for one post.
	 *
	 * @since x.x.x
	 *
	 * @param int                     $post_id Post whose vectors were loaded.
	 * @param array<int, list<float>> $vectors Chunk vectors, keyed by chunk index.
	 */
	private function log_post_summary( int $post_id, array $vectors ): void {
		$post  = get_post( $post_id );
		$title = '(no title)';
		$type  = 'unknown';

		if ( $post instanceof \WP_Post ) {
			$title = '' === trim( $post->post_title ) ? '(no title)' : $post->post_title;
			$type  = $post->post_type;
		}

		$first = array_values( $vectors );

		WP_CLI::log(
			sprintf(
				'Post %d: "%s" (%s, %d chunk(s), %d dims)',
				$post_id,
				$title,
				$type,
				count( $vectors ),
				count( $first[0] )
			)
		);
	}

	/**
	 * Reads a positional post ID and confirms the post exists.
	 *
	 * @since x.x.x
	 *
	 * @param array<int, string> $args     Positional arguments.
	 * @param int                $position Zero-based position to read.
	 * @return int The post ID.
	 */
	private function resolve_post_id( array $args, int $position ): int {
		$raw = isset( $args[ $position ] ) ? trim( (string) $args[ $position ] ) : '';

		if ( '' === $raw || ! ctype_digit( $raw ) || 0 === (int) $raw ) {
			WP_CLI::error( sprintf( 'Argument %d must be a positive post ID; got "%s".', $position + 1, $raw ) );
			return 0; // WP_CLI::error() exits, but this satisfies static analysis.
		}

		$post_id = (int) $raw;

		if ( ! get_post( $post_id ) instanceof \WP_Post ) {
			WP_CLI::error( sprintf( 'Post %d not found.', $post_id ) );
			return 0; // WP_CLI::error() exits, but this satisfies static analysis.
		}

		return $post_id;
	}

	/**
	 * Resolves and validates the --metric flag.
	 *
	 * WP-CLI validates the value against the command synopsis; the check here covers the class being
	 * invoked directly.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return string A `Vector_Math::METRIC_*` constant, or `all`.
	 */
	private function resolve_metric( array $assoc_args ): string {
		$metric  = strtolower( trim( (string) Utils\get_flag_value( $assoc_args, 'metric', Vector_Math::METRIC_COSINE ) ) );
		$allowed = array(
			Vector_Math::METRIC_COSINE,
			Vector_Math::METRIC_DOT_PRODUCT,
			Vector_Math::METRIC_EUCLIDEAN,
			self::METRIC_ALL,
		);

		if ( ! in_array( $metric, $allowed, true ) ) {
			WP_CLI::error(
				sprintf(
					'Invalid --metric "%s". Allowed values: %s.',
					$metric,
					implode( ', ', $allowed )
				)
			);
			return ''; // WP_CLI::error() exits, but this satisfies static analysis.
		}

		return $metric;
	}

	/**
	 * Decides which provider and model to compare with.
	 *
	 * @since x.x.x
	 *
	 * @param int $post_a     First post ID.
	 * @param int $post_b     Second post ID.
	 * @return array{0: string, 1: string} Provider ID and model ID.
	 */
	private function resolve_stored_model( int $post_a, int $post_b ): array {
		$shared = $this->find_shared_models( $post_a, $post_b );

		if ( array() === $shared ) {
			foreach ( array_unique( array( $post_a, $post_b ) ) as $post_id ) {
				$models = $this->find_models_for_post( $post_id );

				WP_CLI::log(
					array() === $models
						? sprintf( 'Post %d has no stored embeddings.', $post_id )
						: sprintf( 'Post %d has: %s.', $post_id, implode( ', ', $models ) )
				);
			}

			WP_CLI::error(
				sprintf(
					'Posts %d and %d have no provider and model in common. Embed both with the same model, then compare.',
					$post_a,
					$post_b
				)
			);
			return array( '', '' ); // WP_CLI::error() exits, but this satisfies static analysis.
		}

		if ( count( $shared ) > 1 ) {
			WP_CLI::log( sprintf( 'Posts %d and %d share more than one model:', $post_a, $post_b ) );

			foreach ( $shared as $pair ) {
				WP_CLI::log( sprintf( '  --provider=%s --model=%s', $pair['provider'], $pair['model'] ) );
			}

			WP_CLI::error( 'Posts have more than one model in common. Embed both with the same model, then compare.' );
			return array( '', '' ); // WP_CLI::error() exits, but this satisfies static analysis.
		}

		WP_CLI::log(
			sprintf(
				'Using provider "%s" and model "%s" — the only one both posts share.',
				$shared[0]['provider'],
				$shared[0]['model']
			)
		);

		return array( $shared[0]['provider'], $shared[0]['model'] );
	}

	/**
	 * Returns the provider and model pairs that both posts have vectors for.
	 *
	 * @since x.x.x
	 *
	 * @param int $post_a First post ID.
	 * @param int $post_b Second post ID.
	 * @return list<array{provider: string, model: string}> The shared pairs.
	 */
	private function find_shared_models( int $post_a, int $post_b ): array {
		global $wpdb;

		$schema = new Embedding_Schema();

		if ( ! $schema->table_exists() ) {
			WP_CLI::error(
				'No embeddings have been stored yet: the embeddings table does not exist. Run '
				. '`wp ai embeddings generate --post-id=<id> --provider=<provider> --model=<model>` first.'
			);
			return array(); // WP_CLI::error() exits, but this satisfies static analysis.
		}

		$table = $schema->get_table_name();

		// Comparing a post with itself only needs the one row to exist.
		$required = $post_a === $post_b ? 1 : 2;

		// A direct read: the repository's reads are all scoped to a known provider and model, and the
		// question here is which of those a pair of posts have in common.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT provider, model
				FROM {$table}
				WHERE object_type = 'post' AND object_id IN ( %d, %d )
				GROUP BY provider, model
				HAVING COUNT( DISTINCT object_id ) = %d
				ORDER BY provider ASC, model ASC",
				$post_a,
				$post_b,
				$required
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$shared = array();

		foreach ( $rows as $row ) {
			$shared[] = array(
				'provider' => (string) $row['provider'],
				'model'    => (string) $row['model'],
			);
		}

		return $shared;
	}

	/**
	 * Returns the provider and model pairs one post has vectors for.
	 *
	 * @since x.x.x
	 *
	 * @param int $post_id Post ID.
	 * @return list<string> Pairs formatted as "provider/model".
	 */
	private function find_models_for_post( int $post_id ): array {
		global $wpdb;

		$table = ( new Embedding_Schema() )->get_table_name();

		// A direct read, for the same reason as find_shared_models().
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT provider, model
				FROM {$table}
				WHERE object_type = 'post' AND object_id = %d
				ORDER BY provider ASC, model ASC",
				$post_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$models = array();

		foreach ( $rows as $row ) {
			$models[] = sprintf( '%s/%s', (string) $row['provider'], (string) $row['model'] );
		}

		return $models;
	}

	/**
	 * Loads a post's stored chunk vectors.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Embeddings\Embedding_Repository $repository The repository to read from.
	 * @param int                                           $post_id    Post ID.
	 * @param string                                        $provider   Provider ID.
	 * @param string                                        $model      Model ID.
	 * @return array<int, list<float>> Chunk vectors, keyed by chunk index.
	 */
	private function load_vectors( Embedding_Repository $repository, int $post_id, string $provider, string $model ): array {
		$records = $repository->get( 'post', $post_id, $provider, $model );

		if ( array() === $records ) {
			WP_CLI::error(
				sprintf(
					'No stored embeddings for post %1$d with model "%2$s" from provider "%3$s". Run: wp ai embeddings generate --post-id=%1$d --provider=%3$s --model=%2$s',
					$post_id,
					$model,
					$provider
				)
			);
			return array(); // WP_CLI::error() exits, but this satisfies static analysis.
		}

		$vectors = array();

		foreach ( $records as $record ) {
			$vectors[ $record->get_chunk_index() ] = $record->get_vector();
		}

		return $vectors;
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
