<?php
/**
 * Integration tests for the Content_Classification class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments
 */

namespace WordPress\AI\Tests\Integration\Experiments\Content_Classification;

use WP_UnitTestCase;
use WordPress\AI\Embeddings\Embedding_Record;
use WordPress\AI\Embeddings\Sync\Embedding_Sync;
use WordPress\AI\Experiments\Content_Classification\Content_Classification;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Features\Loader;
use WordPress\AI\Features\Registry;
use WordPress\AI\Tests\Integration\Includes\Embeddings\Sync\Fake_Embedding_Client;
use WordPress\AI\Tests\Integration\Includes\Embeddings\Sync\Sync_Tables_Trait;

use function WordPress\AI\register_embedding_consumer;

/**
 * Content_Classification test case.
 *
 * @since 0.7.0
 */
class Content_ClassificationTest extends WP_UnitTestCase {

	use Sync_Tables_Trait;

	private const METHOD_OPTION = 'wpai_feature_content-classification_field_method';

	private const MODEL_OPTION = 'wpai_feature_content-classification_field_embedding_model';

	/**
	 * Creates the sync tables once for the class.
	 *
	 * @since x.x.x
	 */
	public static function wpSetUpBeforeClass(): void {
		self::create_sync_tables();
	}

	/**
	 * Drops the sync tables after the class.
	 *
	 * @since x.x.x
	 */
	public static function wpTearDownAfterClass(): void {
		self::drop_sync_tables();
	}

	/**
	 * Set up test case.
	 *
	 * @since 0.7.0
	 */
	public function setUp(): void {
		parent::setUp();

		// Set up mock AI credentials so has_ai_credentials() returns true.
		update_option( 'wp_ai_client_provider_credentials', array( 'openai' => 'test-api-key' ) );

		// Mock has_valid_ai_credentials to return true for tests.
		add_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );

		// Enable the experiment.
		update_option( 'wpai_feature_content-classification_enabled', true );

		$registry = new Registry();
		$loader   = new Loader( $registry );
		$loader->init();

		$experiment = $registry->get_feature( 'content-classification' );
		$this->assertInstanceOf( Content_Classification::class, $experiment, 'Content classification experiment should be registered in the registry.' );
	}

	/**
	 * Tear down test case.
	 *
	 * @since 0.7.0
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( 'wpai_feature_content-classification_enabled' );
		delete_option( 'wpai_feature_content-classification_field_strategy' );
		delete_option( 'wpai_feature_content-classification_field_max_suggestions' );
		delete_option( self::METHOD_OPTION );
		delete_option( self::MODEL_OPTION );
		Embedding_Sync::set_instance( null );
		delete_option( 'wp_ai_client_provider_credentials' );
		remove_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );
		remove_all_filters( 'wpai_content_classification_strategy' );
		remove_all_filters( 'wpai_content_classification_max_suggestions' );
		parent::tearDown();
	}

	/**
	 * Test that the experiment is registered correctly.
	 *
	 * @since 0.7.0
	 */
	public function test_experiment_registration() {
		$experiment = new Content_Classification();

		$this->assertEquals( 'content-classification', $experiment->get_id() );
		$this->assertEquals( 'Content Classification', $experiment->get_label() );
		$this->assertEquals( Experiment_Category::EDITOR, $experiment->get_category() );
		$this->assertTrue( $experiment->is_enabled() );
	}

	/**
	 * Test that experiment settings are registered.
	 *
	 * @since 0.7.0
	 */
	public function test_experiment_settings_registration() {
		$experiment = new Content_Classification();
		$experiment->register_settings();

		// Verify the settings are registered by checking they can be retrieved.
		$strategy = get_option( 'wpai_feature_content-classification_field_strategy', 'existing_only' );
		$this->assertEquals( 'existing_only', $strategy );

		$max_suggestions = get_option( 'wpai_feature_content-classification_field_max_suggestions', 5 );
		$this->assertEquals( 5, $max_suggestions );
	}

	/**
	 * Test that strategy sanitization works correctly.
	 *
	 * @since 0.7.0
	 */
	public function test_sanitize_strategy() {
		$experiment = new Content_Classification();

		$this->assertEquals( 'existing_only', $experiment->sanitize_strategy( 'existing_only' ) );
		$this->assertEquals( 'allow_new', $experiment->sanitize_strategy( 'allow_new' ) );
		$this->assertEquals( 'existing_only', $experiment->sanitize_strategy( 'invalid_value' ) );
		$this->assertEquals( 'existing_only', $experiment->sanitize_strategy( '' ) );
	}

	/**
	 * Test that max suggestions sanitization works correctly.
	 *
	 * @since 0.7.0
	 */
	public function test_sanitize_max_suggestions() {
		$experiment = new Content_Classification();

		$this->assertEquals( 5, $experiment->sanitize_max_suggestions( 5 ) );
		$this->assertEquals( 1, $experiment->sanitize_max_suggestions( 0 ) );
		$this->assertEquals( 1, $experiment->sanitize_max_suggestions( -1 ) );
		$this->assertEquals( 10, $experiment->sanitize_max_suggestions( 15 ) );
		$this->assertEquals( 7, $experiment->sanitize_max_suggestions( '7' ) );
	}

	/**
	 * Test that get_strategy() returns the default value.
	 *
	 * @since 0.7.0
	 */
	public function test_get_strategy_returns_default() {
		$experiment = new Content_Classification();

		$this->assertEquals( 'existing_only', $experiment->get_strategy() );
	}

	/**
	 * Test that get_strategy() returns the saved option value.
	 *
	 * @since 0.7.0
	 */
	public function test_get_strategy_returns_saved_option() {
		update_option( 'wpai_feature_content-classification_field_strategy', 'allow_new' );
		$experiment = new Content_Classification();

		$this->assertEquals( 'allow_new', $experiment->get_strategy() );
	}

	/**
	 * Test that get_strategy() is filterable.
	 *
	 * @since 0.7.0
	 */
	public function test_get_strategy_is_filterable() {
		$experiment = new Content_Classification();

		add_filter(
			'wpai_content_classification_strategy',
			static function () {
				return 'allow_new';
			}
		);

		$this->assertEquals( 'allow_new', $experiment->get_strategy() );
	}

	/**
	 * Test that get_strategy() sanitizes filtered value.
	 *
	 * @since 0.7.0
	 */
	public function test_get_strategy_sanitizes_filtered_value() {
		$experiment = new Content_Classification();

		add_filter(
			'wpai_content_classification_strategy',
			static function () {
				return 'malicious_value';
			}
		);

		$this->assertEquals( 'existing_only', $experiment->get_strategy(), 'Invalid filtered value should fall back to default' );
	}

	/**
	 * Test that get_max_suggestions() returns the default value.
	 *
	 * @since 0.7.0
	 */
	public function test_get_max_suggestions_returns_default() {
		$experiment = new Content_Classification();

		$this->assertEquals( 5, $experiment->get_max_suggestions() );
	}

	/**
	 * Test that get_max_suggestions() returns the saved option value.
	 *
	 * @since 0.7.0
	 */
	public function test_get_max_suggestions_returns_saved_option() {
		update_option( 'wpai_feature_content-classification_field_max_suggestions', 8 );
		$experiment = new Content_Classification();

		$this->assertEquals( 8, $experiment->get_max_suggestions() );
	}

	/**
	 * Test that get_max_suggestions() is filterable.
	 *
	 * @since 0.7.0
	 */
	public function test_get_max_suggestions_is_filterable() {
		$experiment = new Content_Classification();

		add_filter(
			'wpai_content_classification_max_suggestions',
			static function () {
				return 3;
			}
		);

		$this->assertEquals( 3, $experiment->get_max_suggestions() );
	}

	/**
	 * Test that get_max_suggestions() sanitizes filtered value that exceeds maximum.
	 *
	 * @since 0.7.0
	 */
	public function test_get_max_suggestions_sanitizes_filtered_value_above_max() {
		$experiment = new Content_Classification();

		add_filter(
			'wpai_content_classification_max_suggestions',
			static function () {
				return 50;
			}
		);

		$this->assertEquals( 10, $experiment->get_max_suggestions(), 'Filtered value above 10 should be clamped to 10' );
	}

	/**
	 * Test that get_max_suggestions() sanitizes filtered value below minimum.
	 *
	 * @since 0.7.0
	 */
	public function test_get_max_suggestions_sanitizes_filtered_value_below_min() {
		$experiment = new Content_Classification();

		add_filter(
			'wpai_content_classification_max_suggestions',
			static function () {
				return 0;
			}
		);

		$this->assertEquals( 1, $experiment->get_max_suggestions(), 'Filtered value of 0 should be clamped to 1' );
	}

	/**
	 * Test that get_settings_fields() returns strategy and max_suggestions.
	 *
	 * @since 0.7.0
	 */
	public function test_get_settings_fields_returns_expected_fields() {
		$experiment = new Content_Classification();
		$fields     = array_values(
			array_filter(
				$experiment->get_settings_fields(),
				static function ( array $field ): bool {
					return in_array( $field['id'], array( 'strategy', 'max_suggestions' ), true );
				}
			)
		);

		$this->assertCount( 2, $fields, 'Should declare the strategy and max suggestions fields' );

		// Verify strategy field.
		$this->assertSame( 'strategy', $fields[0]['id'] );
		$this->assertSame( 'text', $fields[0]['type'] );
		$this->assertSame( 'existing_only', $fields[0]['default'] );
		$this->assertCount( 2, $fields[0]['elements'], 'Strategy field should have two element options' );
		$this->assertSame( 'existing_only', $fields[0]['elements'][0]['value'] );
		$this->assertSame( 'allow_new', $fields[0]['elements'][1]['value'] );

		// Verify max_suggestions field.
		$this->assertSame( 'max_suggestions', $fields[1]['id'] );
		$this->assertSame( 'integer', $fields[1]['type'] );
		$this->assertSame( 5, $fields[1]['default'] );
	}

	/**
	 * Test that get_settings_fields_metadata() resolves IDs to full option names.
	 *
	 * @since 0.7.0
	 */
	public function test_get_settings_fields_metadata_resolves_ids() {
		$experiment = new Content_Classification();
		$fields     = array_combine(
			array_column( $experiment->get_settings_fields(), 'id' ),
			$experiment->get_settings_fields_metadata()
		);

		$this->assertArrayHasKey( 'strategy', $fields );
		$this->assertArrayHasKey( 'max_suggestions', $fields );

		foreach ( $fields as $short_id => $field ) {
			$this->assertSame(
				Content_Classification::get_field_option_name( $short_id ),
				$field['id'],
				"The {$short_id} field id should be resolved to its full option name"
			);
		}
	}


	/**
	 * Test that register_settings() registers options with show_in_rest.
	 *
	 * @since 0.7.0
	 */
	public function test_register_settings_has_show_in_rest() {
		$experiment = new Content_Classification();
		$experiment->register_settings();

		$registered = get_registered_settings();

		$strategy_key        = 'wpai_feature_content-classification_field_strategy';
		$max_suggestions_key = 'wpai_feature_content-classification_field_max_suggestions';

		$this->assertArrayHasKey( $strategy_key, $registered, 'Strategy setting should be registered' );
		$this->assertArrayHasKey( $max_suggestions_key, $registered, 'Max suggestions setting should be registered' );

		$this->assertNotEmpty( $registered[ $strategy_key ]['show_in_rest'], 'Strategy should have show_in_rest' );
		$this->assertNotEmpty( $registered[ $max_suggestions_key ]['show_in_rest'], 'Max suggestions should have show_in_rest' );
	}

	/**
	 * Tests that enqueue_assets() localizes the default minimum content length.
	 *
	 * @since 1.1.0
	 */
	public function test_enqueue_assets_localizes_default_min_content_length() {
		set_current_screen( 'post' );

		// Set up an admin user to ensure assets are enqueued.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$experiment = new Content_Classification();
		$experiment->enqueue_assets( 'post.php' );

		$this->assertTrue( wp_script_is( 'ai_content_classification', 'enqueued' ) );
		$this->assertStringContainsString(
			'"minContentLength":"250"',
			(string) wp_scripts()->get_data( 'ai_content_classification', 'data' )
		);
	}

	/**
	 * Tests that enqueue_assets() localizes the filtered minimum content length.
	 *
	 * @since 1.1.0
	 */
	public function test_enqueue_assets_localizes_filtered_min_content_length() {
		set_current_screen( 'post' );

		// Set up an admin user to ensure assets are enqueued.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$filter = static function () {
			return 250;
		};

		add_filter( 'wpai_min_content_length', $filter );

		$experiment = new Content_Classification();
		$experiment->enqueue_assets( 'post.php' );

		remove_filter( 'wpai_min_content_length', $filter );

		$this->assertStringContainsString(
			'"minContentLength":"250"',
			(string) wp_scripts()->get_data( 'ai_content_classification', 'data' )
		);
	}

	/**
	 * Uses a fresh sync facade so consumer registrations don't leak between tests.
	 *
	 * @since x.x.x
	 *
	 * @return \WordPress\AI\Embeddings\Sync\Embedding_Sync The facade.
	 */
	private function fresh_sync(): Embedding_Sync {
		$sync = new Embedding_Sync( null, new Fake_Embedding_Client() );
		Embedding_Sync::set_instance( $sync );

		return $sync;
	}

	/**
	 * Saves the method and embedding model, then registers a fresh experiment instance.
	 *
	 * @since x.x.x
	 *
	 * @param string $method   Suggestion method.
	 * @param string $provider Provider ID.
	 * @param string $model    Model ID.
	 * @return \WordPress\AI\Experiments\Content_Classification\Content_Classification The registered experiment.
	 */
	private function configure( string $method, string $provider, string $model ): Content_Classification {
		update_option( self::METHOD_OPTION, $method );
		update_option(
			self::MODEL_OPTION,
			array(
				'provider' => $provider,
				'model'    => $model,
			)
		);

		$experiment = new Content_Classification();
		$experiment->register_settings();
		$experiment->register();

		return $experiment;
	}

	/**
	 * Saves a term vector.
	 *
	 * @since x.x.x
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy.
	 * @param string $model    Model ID.
	 */
	private function seed_term_vector( int $term_id, string $taxonomy, string $model ): void {
		Embedding_Sync::instance()->get_repository()->save(
			new Embedding_Record( 'term', $term_id, 'openai', $model, array( 0.1, 0.2, 0.3 ), 0, '', 0, $taxonomy )
		);
	}

	/**
	 * Tests that embedding mode forces existing terms only, without touching the saved strategy.
	 *
	 * @since x.x.x
	 */
	public function test_strategy_forced_to_existing_only_in_embedding_mode(): void {
		update_option( 'wpai_feature_content-classification_field_strategy', 'allow_new' );
		update_option( self::METHOD_OPTION, 'embeddings' );

		$experiment = new Content_Classification();

		$this->assertSame( 'existing_only', $experiment->get_strategy() );
		$this->assertSame( 'allow_new', get_option( 'wpai_feature_content-classification_field_strategy' ) );

		update_option( self::METHOD_OPTION, 'llm' );

		$this->assertSame( 'allow_new', $experiment->get_strategy() );
	}

	/**
	 * Tests that the consumer registers only with the embeddings method and a model.
	 *
	 * @since x.x.x
	 */
	public function test_registers_consumer_only_when_method_and_model_set(): void {
		$sync = $this->fresh_sync();
		$this->configure( 'llm', 'openai', 'text-embedding-3-small' );
		$this->assertNull( $sync->get_registry()->get_consumer( 'content-classification' ), 'The llm method registers no consumer.' );

		$sync = $this->fresh_sync();
		$this->configure( 'embeddings', '', '' );
		$this->assertNull( $sync->get_registry()->get_consumer( 'content-classification' ), 'An empty model registers no consumer.' );

		$sync = $this->fresh_sync();
		$this->configure( 'embeddings', 'openai', 'text-embedding-3-small' );
		$consumer = $sync->get_registry()->get_consumer( 'content-classification' );

		$this->assertNotNull( $consumer );
		$this->assertSame( array( 'term' => array( 'category', 'post_tag' ) ), $consumer['objects'] );
	}

	/**
	 * Tests that changing the embedding model deletes the old model's vectors.
	 *
	 * @since x.x.x
	 */
	public function test_model_change_prunes_old_target(): void {
		$this->fresh_sync();
		$this->configure( 'embeddings', 'openai', 'old' );

		$term_id = self::factory()->term->create( array( 'taxonomy' => 'post_tag' ) );
		$this->seed_term_vector( $term_id, 'post_tag', 'old' );

		update_option(
			self::MODEL_OPTION,
			array(
				'provider' => 'openai',
				'model'    => 'new',
			)
		);

		$this->assertSame( 0, Embedding_Sync::instance()->get_repository()->count_objects( 'term', 'openai', 'old' ) );
	}

	/**
	 * Tests that the old model's vectors survive while another consumer still uses them.
	 *
	 * @since x.x.x
	 */
	public function test_model_change_keeps_target_used_by_another_consumer(): void {
		$this->fresh_sync();
		register_embedding_consumer(
			'other',
			array(
				'provider' => 'openai',
				'model'    => 'old',
				'objects'  => array( 'term' => array( 'post_tag' ) ),
			)
		);
		$this->configure( 'embeddings', 'openai', 'old' );

		$term_id = self::factory()->term->create( array( 'taxonomy' => 'post_tag' ) );
		$this->seed_term_vector( $term_id, 'post_tag', 'old' );

		update_option(
			self::MODEL_OPTION,
			array(
				'provider' => 'openai',
				'model'    => 'new',
			)
		);

		$this->assertSame( 1, Embedding_Sync::instance()->get_repository()->count_objects( 'term', 'openai', 'old' ) );
	}

	/**
	 * Tests that re-saving the same model with its keys reordered keeps the vectors.
	 *
	 * @since x.x.x
	 */
	public function test_resaving_same_model_with_reordered_keys_does_not_prune(): void {
		$this->fresh_sync();
		$this->configure( 'embeddings', 'openai', 'old' );

		$term_id = self::factory()->term->create( array( 'taxonomy' => 'post_tag' ) );
		$this->seed_term_vector( $term_id, 'post_tag', 'old' );

		// The registered sanitize callback would normalize the value and short-circuit update_option(), so fire the hook with the raw values.
		do_action(
			'update_option_' . self::MODEL_OPTION, // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's option update hook.
			array(
				'provider' => 'openai',
				'model'    => 'old',
			),
			array(
				'model'    => ' old ',
				'provider' => 'openai',
			),
			self::MODEL_OPTION
		);

		$this->assertSame( 1, Embedding_Sync::instance()->get_repository()->count_objects( 'term', 'openai', 'old' ) );
	}

	/**
	 * Tests each readiness reason.
	 *
	 * @since x.x.x
	 */
	public function test_readiness_reasons(): void {
		$this->fresh_sync();
		$experiment = $this->configure( 'llm', '', '' );

		$this->assertSame(
			array(
				'ready'  => false,
				'reason' => 'not_configured',
			),
			$experiment->get_embedding_readiness()
		);

		$this->fresh_sync();
		$experiment = $this->configure( 'embeddings', 'openai', 'model-a' );
		$tag_id     = self::factory()->term->create( array( 'taxonomy' => 'post_tag' ) );

		$this->assertSame(
			array(
				'ready'  => false,
				'reason' => 'not_embedded',
			),
			$experiment->get_embedding_readiness()
		);

		Embedding_Sync::start_backfill( 'content-classification' );

		$this->assertSame(
			array(
				'ready'  => false,
				'reason' => 'running',
			),
			$experiment->get_embedding_readiness()
		);

		Embedding_Sync::reset_backfill( 'content-classification' );

		// Only "Uncategorized" exists in category, and it is indexable too.
		$this->seed_term_vector( $tag_id, 'post_tag', 'model-a' );
		$this->seed_term_vector( (int) get_option( 'default_category' ), 'category', 'model-a' );

		$this->assertSame(
			array(
				'ready'  => true,
				'reason' => '',
			),
			$experiment->get_embedding_readiness()
		);
	}

	/**
	 * Tests that readiness is false when no in-scope taxonomy has any term.
	 *
	 * @since x.x.x
	 */
	public function test_readiness_false_when_taxonomies_have_no_indexable_terms(): void {
		global $wpdb;

		$this->fresh_sync();
		$experiment = $this->configure( 'embeddings', 'openai', 'model-a' );

		// The default category can't be deleted through the API, so remove the rows directly; the test transaction restores them.
		$wpdb->query( "DELETE FROM {$wpdb->term_taxonomy} WHERE taxonomy IN ( 'category', 'post_tag' )" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$this->assertSame(
			array(
				'ready'  => false,
				'reason' => 'not_embedded',
			),
			$experiment->get_embedding_readiness()
		);
	}
}
