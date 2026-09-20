<?php
/**
 * Integration tests for the Embeddings_Command WP-CLI class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\CLI
 */

declare( strict_types=1 );

namespace WordPress\AI\Tests\Integration\Includes\CLI;

use WP_UnitTestCase;
use WordPress\AI\CLI\Embeddings_Command;
use WordPress\AI\Embeddings\Vector_Math;

require_once __DIR__ . '/wp-cli-stubs.php';

/**
 * Embeddings_Command test case.
 *
 * @covers \WordPress\AI\CLI\Embeddings_Command
 *
 * @since x.x.x
 */
class Embeddings_CommandTest extends WP_UnitTestCase {

	/**
	 * The command instance.
	 *
	 * @var \WordPress\AI\CLI\Embeddings_Command
	 */
	private $command;

	/**
	 * Set up test case.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->command = new Embeddings_Command();

		if ( ! class_exists( '\WP_CLI' ) || ! method_exists( '\WP_CLI', 'reset' ) ) {
			return;
		}

		\WP_CLI::reset();
	}

	/**
	 * Tear down test case.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );

		if ( class_exists( '\WP_CLI' ) && method_exists( '\WP_CLI', 'reset' ) ) {
			\WP_CLI::reset();
		}

		parent::tearDown();
	}

	/**
	 * Invokes a private method on the command instance via reflection.
	 *
	 * @param string $method_name The method name to invoke.
	 * @param array  $args        Arguments to pass to the method.
	 * @return mixed The method return value.
	 */
	private function invoke_private_method( string $method_name, array $args = array() ) {
		$reflection = new \ReflectionClass( $this->command );
		$method     = $reflection->getMethod( $method_name );

		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		return $method->invoke( $this->command, ...$args );
	}

	/**
	 * Gets captured WP_CLI messages at a given level.
	 *
	 * @param string|null $level The level to filter by, or null for all.
	 * @return array<int, string> The captured messages.
	 */
	private function get_cli_messages( ?string $level = null ): array {
		$messages = array();
		foreach ( \WP_CLI::$messages as $entry ) {
			if ( null !== $level && $entry['level'] !== $level ) {
				continue;
			}

			$messages[] = $entry['message'];
		}
		return $messages;
	}

	/**
	 * Data provider for test_metric_label_returns_expected_display_names.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function data_metric_label(): array {
		return array(
			'dot product'        => array( Vector_Math::METRIC_DOT_PRODUCT, 'Dot product' ),
			'euclidean distance' => array( Vector_Math::METRIC_EUCLIDEAN, 'Euclidean distance' ),
			'cosine similarity'  => array( Vector_Math::METRIC_COSINE, 'Cosine similarity' ),
			'unknown fallback'   => array( 'other_metric', 'Cosine similarity' ),
		);
	}

	/**
	 * Tests that metric_label returns expected human-readable labels.
	 *
	 * @dataProvider data_metric_label
	 *
	 * @param string $metric   The metric identifier.
	 * @param string $expected The expected human-readable label.
	 */
	public function test_metric_label_returns_expected_display_names( string $metric, string $expected ): void {
		$result = $this->invoke_private_method( 'metric_label', array( $metric ) );
		$this->assertSame( $expected, $result );
	}

	/**
	 * Data provider for test_format_score_formats_to_four_decimals.
	 *
	 * @return array<string, array{0: float, 1: string}>
	 */
	public function data_format_score(): array {
		return array(
			'zero'     => array( 0.0, '0.0000' ),
			'one'      => array( 1.0, '1.0000' ),
			'decimals' => array( 0.123456, '0.1235' ),
			'negative' => array( -0.5, '-0.5000' ),
			'rounding' => array( 0.99999, '1.0000' ),
		);
	}

	/**
	 * Tests that format_score formats float numbers to 4 decimal places.
	 *
	 * @dataProvider data_format_score
	 *
	 * @param float  $score    The input score.
	 * @param string $expected The expected formatted string.
	 */
	public function test_format_score_formats_to_four_decimals( float $score, string $expected ): void {
		$result = $this->invoke_private_method( 'format_score', array( $score ) );
		$this->assertSame( $expected, $result );
	}

	/**
	 * Tests preview_vector formatting for vectors with <= 5 and > 5 dimensions.
	 */
	public function test_preview_vector_formats_and_truncates_dimensions(): void {
		$short_vector = array( 0.1, 0.25, 0.5 );
		$result_short = $this->invoke_private_method( 'preview_vector', array( $short_vector ) );
		$this->assertSame( '[0.1, 0.25, 0.5, ...] (3 dims)', $result_short );

		$exact_vector = array( 1, 2, 3, 4, 5 );
		$result_exact = $this->invoke_private_method( 'preview_vector', array( $exact_vector ) );
		$this->assertSame( '[1, 2, 3, 4, 5, ...] (5 dims)', $result_exact );

		$long_vector = array( 0.123456, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7 );
		$result_long = $this->invoke_private_method( 'preview_vector', array( $long_vector ) );
		$this->assertSame( '[0.12346, 0.2, 0.3, 0.4, 0.5, ...] (7 dims)', $result_long );
	}

	/**
	 * Tests find_natural_break boundary handling for short window lengths (<= 1).
	 */
	public function test_find_natural_break_handles_short_windows(): void {
		$this->assertSame( 1, $this->invoke_private_method( 'find_natural_break', array( '' ) ) );
		$this->assertSame( 1, $this->invoke_private_method( 'find_natural_break', array( 'a' ) ) );
	}

	/**
	 * Tests find_natural_break finds sentence punctuation delimiters.
	 */
	public function test_find_natural_break_finds_sentence_punctuation(): void {
		// Window length 10: search_from is floor(10 * 0.75) = 7. Index 8 is punctuation.
		$window_dot = '1234567.90';
		$this->assertSame( 8, $this->invoke_private_method( 'find_natural_break', array( $window_dot ) ) );

		$window_exclamation = '1234567!90';
		$this->assertSame( 8, $this->invoke_private_method( 'find_natural_break', array( $window_exclamation ) ) );

		$window_question = '1234567?90';
		$this->assertSame( 8, $this->invoke_private_method( 'find_natural_break', array( $window_question ) ) );

		$window_semicolon = '1234567;90';
		$this->assertSame( 8, $this->invoke_private_method( 'find_natural_break', array( $window_semicolon ) ) );

		$window_colon = '1234567:90';
		$this->assertSame( 8, $this->invoke_private_method( 'find_natural_break', array( $window_colon ) ) );
	}

	/**
	 * Tests find_natural_break finds whitespace delimiters.
	 */
	public function test_find_natural_break_finds_whitespace(): void {
		$window_space = '1234567 90';
		$this->assertSame( 8, $this->invoke_private_method( 'find_natural_break', array( $window_space ) ) );

		$window_newline = "1234567\n90";
		$this->assertSame( 8, $this->invoke_private_method( 'find_natural_break', array( $window_newline ) ) );
	}

	/**
	 * Tests find_natural_break falls back to window length when no natural break exists in the search window.
	 */
	public function test_find_natural_break_falls_back_to_window_length_when_no_break(): void {
		$window = '0123456789';
		$this->assertSame( 10, $this->invoke_private_method( 'find_natural_break', array( $window ) ) );
	}

	/**
	 * Tests chunk_text returns an empty array for empty or whitespace-only input.
	 */
	public function test_chunk_text_returns_empty_array_for_empty_or_whitespace_input(): void {
		$this->assertSame( array(), $this->invoke_private_method( 'chunk_text', array( '' ) ) );
		$this->assertSame( array(), $this->invoke_private_method( 'chunk_text', array( "   \t\n  " ) ) );
	}

	/**
	 * Tests chunk_text returns a single chunk when input is within CHUNK_SIZE.
	 */
	public function test_chunk_text_returns_single_chunk_when_text_within_chunk_size(): void {
		$short_text = 'Hello world! This is a test sentence.';
		$result     = $this->invoke_private_method( 'chunk_text', array( $short_text ) );
		$this->assertSame( array( $short_text ), $result );

		$exact_text = str_repeat( 'a', 750 );
		$result     = $this->invoke_private_method( 'chunk_text', array( $exact_text ) );
		$this->assertSame( array( $exact_text ), $result );
	}

	/**
	 * Tests chunk_text splits text exceeding CHUNK_SIZE into overlapping chunks.
	 */
	public function test_chunk_text_splits_long_text_with_overlap_and_natural_breaks(): void {
		$paragraph = 'The quick brown fox jumps over the lazy dog. ';
		$long_text = str_repeat( $paragraph, 30 ); // ~1380 characters.

		$chunks = $this->invoke_private_method( 'chunk_text', array( $long_text ) );

		$this->assertIsArray( $chunks );
		$this->assertGreaterThan( 1, count( $chunks ) );

		foreach ( $chunks as $chunk ) {
			$this->assertLessThanOrEqual( 750, mb_strlen( $chunk ) );
			$this->assertNotEmpty( trim( $chunk ) );
		}
	}

	/**
	 * Tests chunk_text correctly handles multibyte UTF-8 characters without corruption.
	 */
	public function test_chunk_text_handles_multibyte_characters(): void {
		// Multibyte string with Bengali and emoji characters repeated to exceed 750 characters.
		$multibyte_unit = 'ওয়ার্ডপ্রেস এআই টেস্ট কন্টেন্ট! 🚀 ';
		$long_text      = str_repeat( $multibyte_unit, 40 );

		$chunks = $this->invoke_private_method( 'chunk_text', array( $long_text ) );

		$this->assertIsArray( $chunks );
		$this->assertGreaterThan( 1, count( $chunks ) );

		foreach ( $chunks as $chunk ) {
			$this->assertTrue( mb_check_encoding( $chunk, 'UTF-8' ) );
			$this->assertLessThanOrEqual( 750, mb_strlen( $chunk ) );
		}
	}

	/**
	 * Tests resolve_metric defaults to cosine when omitted.
	 */
	public function test_resolve_metric_defaults_to_cosine(): void {
		$metric = $this->invoke_private_method( 'resolve_metric', array( array() ) );
		$this->assertSame( Vector_Math::METRIC_COSINE, $metric );
	}

	/**
	 * Data provider for test_resolve_metric_accepts_valid_metrics.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function data_valid_metrics(): array {
		return array(
			'cosine'      => array( 'cosine', Vector_Math::METRIC_COSINE ),
			'dot product' => array( 'dot_product', Vector_Math::METRIC_DOT_PRODUCT ),
			'euclidean'   => array( 'euclidean', Vector_Math::METRIC_EUCLIDEAN ),
			'all'         => array( 'all', 'all' ),
			'uppercase'   => array( 'COSINE', Vector_Math::METRIC_COSINE ),
			'mixed case'  => array( 'Euclidean', Vector_Math::METRIC_EUCLIDEAN ),
		);
	}

	/**
	 * Tests resolve_metric accepts valid metric identifiers.
	 *
	 * @dataProvider data_valid_metrics
	 *
	 * @param string $input    The metric flag input.
	 * @param string $expected The expected resolved metric constant.
	 */
	public function test_resolve_metric_accepts_valid_metrics( string $input, string $expected ): void {
		$metric = $this->invoke_private_method( 'resolve_metric', array( array( 'metric' => $input ) ) );
		$this->assertSame( $expected, $metric );
	}

	/**
	 * Tests resolve_metric throws an error when an invalid metric is passed.
	 */
	public function test_resolve_metric_throws_error_for_invalid_metric(): void {
		$this->expectException( \WP_CLI_Test_Error_Exception::class );
		$this->expectExceptionMessageMatches( '/Invalid --metric "manhattan"/i' );

		$this->invoke_private_method( 'resolve_metric', array( array( 'metric' => 'manhattan' ) ) );
	}

	/**
	 * Data provider for test_resolve_provider_accepts_valid_providers.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function data_valid_providers(): array {
		return array(
			'openai'     => array( 'openai', 'openai' ),
			'google'     => array( 'google', 'google' ),
			'ollama'     => array( 'ollama', 'ollama' ),
			'uppercase'  => array( 'OPENAI', 'openai' ),
			'whitespace' => array( '  google  ', 'google' ),
		);
	}

	/**
	 * Tests resolve_provider accepts valid allowed providers.
	 *
	 * @dataProvider data_valid_providers
	 *
	 * @param string $input    The provider flag input.
	 * @param string $expected The expected resolved provider ID.
	 */
	public function test_resolve_provider_accepts_valid_providers( string $input, string $expected ): void {
		$provider = $this->invoke_private_method( 'resolve_provider', array( array( 'provider' => $input ) ) );
		$this->assertSame( $expected, $provider );
	}

	/**
	 * Tests resolve_provider throws an error when the provider flag is missing or empty.
	 */
	public function test_resolve_provider_throws_error_when_missing(): void {
		$this->expectException( \WP_CLI_Test_Error_Exception::class );
		$this->expectExceptionMessageMatches( '/A --provider is required/i' );

		$this->invoke_private_method( 'resolve_provider', array( array() ) );
	}

	/**
	 * Tests resolve_provider throws an error when an unrecognized provider is specified.
	 */
	public function test_resolve_provider_throws_error_when_invalid(): void {
		$this->expectException( \WP_CLI_Test_Error_Exception::class );
		$this->expectExceptionMessageMatches( '/Invalid --provider "anthropic"/i' );

		$this->invoke_private_method( 'resolve_provider', array( array( 'provider' => 'anthropic' ) ) );
	}

	/**
	 * Tests resolve_model returns trimmed model identifier.
	 */
	public function test_resolve_model_returns_trimmed_model_name(): void {
		$model = $this->invoke_private_method( 'resolve_model', array( array( 'model' => '  text-embedding-3-small  ' ) ) );
		$this->assertSame( 'text-embedding-3-small', $model );
	}

	/**
	 * Tests resolve_model throws an error when the model flag is missing or empty.
	 */
	public function test_resolve_model_throws_error_when_empty(): void {
		$this->expectException( \WP_CLI_Test_Error_Exception::class );
		$this->expectExceptionMessageMatches( '/A --model is required/i' );

		$this->invoke_private_method( 'resolve_model', array( array( 'model' => '   ' ) ) );
	}

	/**
	 * Tests resolve_post_id successfully resolves an existing post ID.
	 */
	public function test_resolve_post_id_accepts_valid_existing_post(): void {
		$post_id  = $this->factory->post->create( array( 'post_title' => 'Sample Post' ) );
		$resolved = $this->invoke_private_method( 'resolve_post_id', array( array( (string) $post_id ), 0 ) );
		$this->assertSame( $post_id, $resolved );
	}

	/**
	 * Tests resolve_post_id throws an error for non-numeric or zero post IDs.
	 */
	public function test_resolve_post_id_throws_for_non_numeric_or_zero(): void {
		$this->expectException( \WP_CLI_Test_Error_Exception::class );
		$this->expectExceptionMessageMatches( '/must be a positive post ID/i' );

		$this->invoke_private_method( 'resolve_post_id', array( array( 'not-a-number' ), 0 ) );
	}

	/**
	 * Tests resolve_post_id throws an error when the referenced post does not exist.
	 */
	public function test_resolve_post_id_throws_for_non_existent_post(): void {
		$this->expectException( \WP_CLI_Test_Error_Exception::class );
		$this->expectExceptionMessageMatches( '/Post 9999999 not found/i' );

		$this->invoke_private_method( 'resolve_post_id', array( array( '9999999' ), 0 ) );
	}

	/**
	 * Tests resolve_text accepts positional text input.
	 */
	public function test_resolve_text_accepts_positional_argument(): void {
		$text = $this->invoke_private_method( 'resolve_text', array( array( 'Direct text to embed' ), array() ) );
		$this->assertSame( 'Direct text to embed', $text );
	}

	/**
	 * Tests resolve_text retrieves and normalizes content from --post-id.
	 */
	public function test_resolve_text_accepts_valid_post_id_and_normalizes_content(): void {
		$post_id = $this->factory->post->create(
			array(
				'post_title'   => 'Article',
				'post_content' => '<p>Hello <strong>WordPress</strong> AI.</p>',
			)
		);

		$text = $this->invoke_private_method( 'resolve_text', array( array(), array( 'post-id' => $post_id ) ) );
		$this->assertSame( 'Hello WordPress AI.', $text );
	}

	/**
	 * Tests resolve_text throws when both positional text and --post-id are provided.
	 */
	public function test_resolve_text_throws_when_both_text_and_post_id_provided(): void {
		$post_id = $this->factory->post->create();

		$this->expectException( \WP_CLI_Test_Error_Exception::class );
		$this->expectExceptionMessageMatches( '/Provide either positional text or --post-id, not both/i' );

		$this->invoke_private_method( 'resolve_text', array( array( 'Text' ), array( 'post-id' => $post_id ) ) );
	}

	/**
	 * Tests resolve_text throws when neither positional text nor --post-id is provided.
	 */
	public function test_resolve_text_throws_when_neither_provided(): void {
		$this->expectException( \WP_CLI_Test_Error_Exception::class );
		$this->expectExceptionMessageMatches( '/Provide positional text or --post-id/i' );

		$this->invoke_private_method( 'resolve_text', array( array(), array() ) );
	}

	/**
	 * Tests resolve_text throws when the resolved post has empty content.
	 */
	public function test_resolve_text_throws_when_post_has_empty_content(): void {
		$post_id = $this->factory->post->create( array( 'post_content' => '' ) );

		$this->expectException( \WP_CLI_Test_Error_Exception::class );
		$this->expectExceptionMessageMatches( '/Resolved content is empty/i' );

		$this->invoke_private_method( 'resolve_text', array( array(), array( 'post-id' => $post_id ) ) );
	}

	/**
	 * Tests sort_pairs sorts descending for cosine similarity and dot product.
	 */
	public function test_sort_pairs_sorts_descending_for_cosine_and_dot(): void {
		$pairs = array(
			'0:1' => 0.45,
			'0:2' => 0.95,
			'0:3' => 0.12,
		);

		$sorted_cosine = $this->invoke_private_method( 'sort_pairs', array( $pairs, Vector_Math::METRIC_COSINE ) );
		$this->assertSame( array( '0:2', '0:1', '0:3' ), array_keys( $sorted_cosine ) );

		$sorted_dot = $this->invoke_private_method( 'sort_pairs', array( $pairs, Vector_Math::METRIC_DOT_PRODUCT ) );
		$this->assertSame( array( '0:2', '0:1', '0:3' ), array_keys( $sorted_dot ) );
	}

	/**
	 * Tests sort_pairs sorts ascending for Euclidean distance (lower distance is better).
	 */
	public function test_sort_pairs_sorts_ascending_for_euclidean(): void {
		$pairs = array(
			'0:1' => 0.45,
			'0:2' => 0.95,
			'0:3' => 0.12,
		);

		$sorted_euclidean = $this->invoke_private_method( 'sort_pairs', array( $pairs, Vector_Math::METRIC_EUCLIDEAN ) );
		$this->assertSame( array( '0:3', '0:1', '0:2' ), array_keys( $sorted_euclidean ) );
	}

	/**
	 * Tests score_pair computes similarity scores via Vector_Ranker.
	 */
	public function test_score_pair_computes_score_via_vector_ranker(): void {
		$vector_a = array( 1.0, 0.0, 0.0 );
		$vector_b = array( 1.0, 0.0, 0.0 );

		$score_cosine = $this->invoke_private_method( 'score_pair', array( $vector_a, $vector_b, Vector_Math::METRIC_COSINE ) );
		$this->assertSame( 1.0, $score_cosine );

		$score_dot = $this->invoke_private_method( 'score_pair', array( $vector_a, $vector_b, Vector_Math::METRIC_DOT_PRODUCT ) );
		$this->assertSame( 1.0, $score_dot );

		$score_euclidean = $this->invoke_private_method( 'score_pair', array( $vector_a, $vector_b, Vector_Math::METRIC_EUCLIDEAN ) );
		$this->assertSame( 0.0, $score_euclidean );
	}

	/**
	 * Tests log_post_summary outputs post title, type, chunk count, and dimension count.
	 */
	public function test_log_post_summary_outputs_post_metadata(): void {
		$post_id = $this->factory->post->create(
			array(
				'post_title' => 'Article One',
				'post_type'  => 'post',
			)
		);

		$vectors = array(
			0 => array( 0.1, 0.2, 0.3 ),
			1 => array( 0.4, 0.5, 0.6 ),
		);

		$this->invoke_private_method( 'log_post_summary', array( $post_id, $vectors ) );

		$messages = $this->get_cli_messages( 'log' );
		$this->assertNotEmpty( $messages );
		$this->assertStringContainsString( sprintf( 'Post %d: "Article One" (post, 2 chunk(s), 3 dims)', $post_id ), $messages[0] );
	}

	/**
	 * Tests log_post_summary outputs fallback when post has no title.
	 */
	public function test_log_post_summary_handles_post_without_title(): void {
		$post_id = $this->factory->post->create(
			array(
				'post_title' => '   ',
				'post_type'  => 'page',
			)
		);

		$vectors = array(
			0 => array( 0.5, 0.6 ),
		);

		$this->invoke_private_method( 'log_post_summary', array( $post_id, $vectors ) );

		$messages = $this->get_cli_messages( 'log' );
		$this->assertNotEmpty( $messages );
		$this->assertStringContainsString( sprintf( 'Post %d: "(no title)" (page, 1 chunk(s), 2 dims)', $post_id ), $messages[0] );
	}

	/**
	 * Tests generate command dry-run execution with positional text input.
	 */
	public function test_generate_dry_run_with_positional_text(): void {
		$this->command->generate(
			array( 'Embedding sample text' ),
			array(
				'provider' => 'openai',
				'model'    => 'text-embedding-3-small',
				'dry-run'  => true,
			)
		);

		$logs = $this->get_cli_messages( 'log' );
		$this->assertContains( 'Dry run: would use model "text-embedding-3-small" from provider "openai".', $logs );
		$this->assertContains( 'Dry run: would have generated embeddings for text: Embedding sample text', $logs );
	}

	/**
	 * Tests generate command dry-run execution with post ID and chunking enabled.
	 */
	public function test_generate_dry_run_with_post_id_and_chunking(): void {
		$post_id = $this->factory->post->create(
			array(
				'post_content' => str_repeat( 'Sentence for chunking. ', 45 ),
			)
		);

		$this->command->generate(
			array(),
			array(
				'post-id'  => $post_id,
				'provider' => 'google',
				'model'    => 'text-embedding-004',
				'chunk'    => true,
				'dry-run'  => true,
			)
		);

		$logs = $this->get_cli_messages( 'log' );
		$this->assertContains( 'Dry run: would use model "text-embedding-004" from provider "google".', $logs );
		$this->assertContains( sprintf( 'Dry run: would store the vectors against post %d.', $post_id ), $logs );

		$has_chunk_message = false;
		foreach ( $logs as $msg ) {
			if ( strpos( $msg, 'Dry run: would embed' ) !== false && strpos( $msg, 'chunk(s)' ) !== false ) {
				$has_chunk_message = true;
				break;
			}
		}
		$this->assertTrue( $has_chunk_message );
	}

	/**
	 * Tests compare command fails when positional post arguments are missing.
	 */
	public function test_compare_fails_when_post_arguments_missing(): void {
		$this->expectException( \WP_CLI_Test_Error_Exception::class );
		$this->expectExceptionMessageMatches( '/Argument 1 must be a positive post ID/i' );

		$this->command->compare( array(), array() );
	}

	/**
	 * Tests compare command fails when --pairs limit is less than 1.
	 */
	public function test_compare_fails_when_pairs_limit_is_invalid(): void {
		$post_a = $this->factory->post->create();
		$post_b = $this->factory->post->create();

		$this->expectException( \WP_CLI_Test_Error_Exception::class );
		$this->expectExceptionMessageMatches( '/--pairs must be a positive integer/i' );

		$this->command->compare(
			array( (string) $post_a, (string) $post_b ),
			array( 'pairs' => 0 )
		);
	}

	/**
	 * Tests compare command fails when the embeddings table does not exist.
	 */
	public function test_compare_fails_when_no_embeddings_table_exists(): void {
		$post_a = $this->factory->post->create();
		$post_b = $this->factory->post->create();

		$this->expectException( \WP_CLI_Test_Error_Exception::class );
		$this->expectExceptionMessageMatches( '/embeddings table does not exist/i' );

		$this->command->compare(
			array( (string) $post_a, (string) $post_b ),
			array()
		);
	}
}
