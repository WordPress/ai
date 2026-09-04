<?php
/**
 * Integration tests for the Semantic_Search experiment.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Semantic_Search
 */

namespace WordPress\AI\Tests\Integration\Experiments\Semantic_Search;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Experiments\Semantic_Search\Embedding_Generator;
use WordPress\AI\Experiments\Semantic_Search\Embedding_Store;
use WordPress\AI\Experiments\Semantic_Search\Semantic_Search;
use WordPress\AI\Experiments\Semantic_Search\Vector_Search;

/**
 * Semantic_Search experiment test case.
 *
 * @since x.x.x
 */
class Semantic_SearchTest extends WP_UnitTestCase {

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( 'wp_ai_client_provider_credentials', array( 'openai' => 'test-api-key' ) );
		add_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );

		update_option( 'wpai_features_enabled', true );
		update_option( 'wpai_feature_semantic-search_enabled', true );
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_semantic-search_enabled' );
		delete_option( 'wp_ai_client_provider_credentials' );
		delete_option( Semantic_Search::get_field_option_name( 'model' ) );
		delete_option( Semantic_Search::get_field_option_name( 'score_threshold' ) );
		remove_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );
		parent::tearDown();
	}

	/**
	 * Invokes a non-public method using reflection.
	 *
	 * @since x.x.x
	 *
	 * @param object $instance    Object to invoke the method on.
	 * @param string $method_name Name of the method to invoke.
	 * @param array  $args        Arguments to pass to the method.
	 * @return mixed The value returned by the invoked method.
	 */
	private function invoke_method( object $instance, string $method_name, array $args = array() ) {
		$reflection = new \ReflectionClass( $instance );
		$method     = $reflection->getMethod( $method_name );
		$method->setAccessible( true );

		return $method->invokeArgs( $instance, $args );
	}

	/**
	 * Test that the experiment exposes the expected identity metadata.
	 *
	 * @since x.x.x
	 */
	public function test_experiment_metadata() {
		$experiment = new Semantic_Search();

		$this->assertSame( 'semantic-search', $experiment->get_id() );
		$this->assertSame( 'Semantic Search', $experiment->get_label() );
		$this->assertSame( Experiment_Category::ADMIN, $experiment->get_category() );
		$this->assertSame( 'embedding_generation', $experiment->get_capability() );
	}

	/**
	 * Test that the settings fields no longer expose provider credentials.
	 *
	 * Provider selection and authentication belong to the connector settings,
	 * so this experiment must only own the model preference and threshold.
	 *
	 * @since x.x.x
	 */
	public function test_settings_fields_contain_no_provider_configuration() {
		$fields = ( new Semantic_Search() )->get_settings_fields();
		$ids    = wp_list_pluck( $fields, 'id' );

		$this->assertSame( array( 'model', 'score_threshold' ), $ids );
		$this->assertNotContains( 'api_key', $ids );
		$this->assertNotContains( 'provider', $ids );
		$this->assertNotContains( 'base_url', $ids );
	}

	/**
	 * Test that the score threshold falls back to the default when unset.
	 *
	 * @since x.x.x
	 */
	public function test_score_threshold_defaults_when_option_is_empty() {
		$generator = new Embedding_Generator();

		$this->assertSame(
			Embedding_Generator::DEFAULT_SCORE_THRESHOLD,
			$generator->get_score_threshold()
		);
	}

	/**
	 * Test that a saved score threshold is used when it is valid.
	 *
	 * @since x.x.x
	 */
	public function test_score_threshold_uses_saved_value() {
		update_option( Semantic_Search::get_field_option_name( 'score_threshold' ), '0.72' );

		$this->assertSame( 0.72, ( new Embedding_Generator() )->get_score_threshold() );
	}

	/**
	 * Test that out-of-range and non-numeric thresholds fall back to the default.
	 *
	 * @since x.x.x
	 */
	public function test_score_threshold_rejects_invalid_values() {
		$generator = new Embedding_Generator();
		$option    = Semantic_Search::get_field_option_name( 'score_threshold' );

		foreach ( array( '-0.2', '1.4', 'not-a-number' ) as $invalid ) {
			update_option( $option, $invalid );

			$this->assertSame(
				Embedding_Generator::DEFAULT_SCORE_THRESHOLD,
				$generator->get_score_threshold(),
				sprintf( 'Value "%s" should fall back to the default threshold.', $invalid )
			);
		}
	}

	/**
	 * Test that the model preference is read from the saved option.
	 *
	 * @since x.x.x
	 */
	public function test_get_model_reads_saved_option() {
		$generator = new Embedding_Generator();

		$this->assertSame( '', $generator->get_model() );

		update_option( Semantic_Search::get_field_option_name( 'model' ), '  text-embedding-3-small  ' );

		$this->assertSame( 'text-embedding-3-small', $generator->get_model() );
	}

	/**
	 * Test that the embedding store round-trips a vector for a post.
	 *
	 * @since x.x.x
	 */
	public function test_embedding_store_saves_and_retrieves_vector() {
		$post_id = self::factory()->post->create();
		$store   = new Embedding_Store();

		$this->assertNull( $store->get( $post_id ), 'An unindexed post should have no vector.' );

		$store->save( $post_id, array( 0.1, 0.2, 0.3 ), 'test-model' );

		$this->assertSame( array( 0.1, 0.2, 0.3 ), $store->get( $post_id ) );

		$store->delete( $post_id );

		$this->assertNull( $store->get( $post_id ), 'A deleted vector should not be returned.' );
	}

	/**
	 * Test that stats report published posts and how many are indexed.
	 *
	 * @since x.x.x
	 */
	public function test_embedding_store_reports_stats() {
		$store    = new Embedding_Store();
		$baseline = $store->get_stats();

		$indexed   = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$unindexed = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$store->save( $indexed, array( 0.5, 0.5 ), 'test-model' );

		$stats = $store->get_stats();

		$this->assertSame( $baseline['total'] + 2, $stats['total'] );
		$this->assertSame( $baseline['indexed'] + 1, $stats['indexed'] );
		$this->assertNotContains( $indexed, $store->get_unindexed_ids( array( 'post', 'page' ), 50 ) );
		$this->assertContains( $unindexed, $store->get_unindexed_ids( array( 'post', 'page' ), 50 ) );
	}

	/**
	 * Test that unindexed ID lookups honour the requested limit.
	 *
	 * @since x.x.x
	 */
	public function test_get_unindexed_ids_respects_limit() {
		self::factory()->post->create_many( 3, array( 'post_status' => 'publish' ) );

		$ids = ( new Embedding_Store() )->get_unindexed_ids( array( 'post', 'page' ), 2 );

		$this->assertCount( 2, $ids );
		$this->assertContainsOnly( 'int', $ids );
	}

	/**
	 * Test that indexed ID lookups return only posts that have a stored embedding.
	 *
	 * @since x.x.x
	 */
	public function test_get_indexed_ids_returns_only_indexed_posts() {
		$store   = new Embedding_Store();
		$indexed = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$other   = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$store->save( $indexed, array( 0.1, 0.2, 0.3 ), 'test-model' );

		$ids = $store->get_indexed_ids( array( 'post', 'page' ), 50 );

		$this->assertContains( $indexed, $ids, 'A post with an embedding should be returned.' );
		$this->assertNotContains( $other, $ids, 'A post without an embedding should be excluded.' );
		$this->assertContainsOnly( 'int', $ids );
	}

	/**
	 * Test that indexed ID lookups honour the requested limit.
	 *
	 * @since x.x.x
	 */
	public function test_get_indexed_ids_respects_limit() {
		$store = new Embedding_Store();

		foreach ( self::factory()->post->create_many( 3, array( 'post_status' => 'publish' ) ) as $post_id ) {
			$store->save( $post_id, array( 0.1, 0.2 ), 'test-model' );
		}

		$ids = $store->get_indexed_ids( array( 'post', 'page' ), 2 );

		$this->assertCount( 2, $ids );
		$this->assertContainsOnly( 'int', $ids );
	}

	/**
	 * Test that cosine similarity scores identical and orthogonal vectors correctly.
	 *
	 * @since x.x.x
	 */
	public function test_cosine_similarity_scores_known_vectors() {
		$search = new Vector_Search();

		$this->assertEqualsWithDelta(
			1.0,
			$this->invoke_method( $search, 'cosine_similarity', array( array( 1.0, 0.0 ), array( 1.0, 0.0 ) ) ),
			0.0001,
			'Identical vectors should score 1.'
		);

		$this->assertEqualsWithDelta(
			0.0,
			$this->invoke_method( $search, 'cosine_similarity', array( array( 1.0, 0.0 ), array( 0.0, 1.0 ) ) ),
			0.0001,
			'Orthogonal vectors should score 0.'
		);

		$this->assertEqualsWithDelta(
			-1.0,
			$this->invoke_method( $search, 'cosine_similarity', array( array( 1.0, 0.0 ), array( -1.0, 0.0 ) ) ),
			0.0001,
			'Opposing vectors should score -1.'
		);
	}

	/**
	 * Test that a zero-magnitude vector scores 0 rather than dividing by zero.
	 *
	 * @since x.x.x
	 */
	public function test_cosine_similarity_handles_zero_vector() {
		$score = $this->invoke_method(
			new Vector_Search(),
			'cosine_similarity',
			array( array( 0.0, 0.0 ), array( 1.0, 1.0 ) )
		);

		$this->assertSame( 0.0, $score );
	}

	/**
	 * Test that mismatched vector lengths compare over the shorter length.
	 *
	 * @since x.x.x
	 */
	public function test_cosine_similarity_handles_mismatched_dimensions() {
		$score = $this->invoke_method(
			new Vector_Search(),
			'cosine_similarity',
			array( array( 1.0, 0.0, 0.0 ), array( 1.0, 0.0 ) )
		);

		$this->assertEqualsWithDelta( 1.0, $score, 0.0001 );
	}
}
