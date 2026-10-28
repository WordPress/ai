<?php
/**
 * Integration tests for the embedding branch of the Content_Classification ability.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities;

use WP_Error;
use WP_Post;
use WP_UnitTestCase;
use WordPress\AI\Abilities\Content_Classification\Content_Classification;
use WordPress\AI\Embeddings\Embedding_Record;
use WordPress\AI\Embeddings\Sync\Embedding_Sync;
use WordPress\AI\Embeddings\Sync\Object_Processor;
use WordPress\AI\Embeddings\Sync\Post_Source;
use WordPress\AI\Experiments\Content_Classification\Content_Classification as Content_Classification_Experiment;
use WordPress\AI\Tests\Integration\Includes\Embeddings\Sync\Fake_Embedding_Client;
use WordPress\AI\Tests\Integration\Includes\Embeddings\Sync\Sync_Tables_Trait;

/**
 * Content_Classification ability embedding-mode test case.
 *
 * @since x.x.x
 */
class Content_Classification_EmbeddingsTest extends WP_UnitTestCase {

	use Sync_Tables_Trait;

	private const METHOD_OPTION = 'wpai_feature_content-classification_field_method';

	private const MODEL_OPTION = 'wpai_feature_content-classification_field_embedding_model';

	private const PROVIDER = 'openai';

	private const MODEL = 'text-embedding-3-small';

	/**
	 * A vector orthogonal to every vector the fake client returns.
	 *
	 * @var list<float>
	 */
	private const ORTHOGONAL = array( 0.0, 0.25, -0.5 ); // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is a single array constant.

	/**
	 * Fake embedding client.
	 *
	 * @var \WordPress\AI\Tests\Integration\Includes\Embeddings\Sync\Fake_Embedding_Client
	 */
	private Fake_Embedding_Client $fake;

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
	 * Injects the fake client.
	 *
	 * @since x.x.x
	 */
	public function set_up(): void {
		parent::set_up();

		$this->fake = new Fake_Embedding_Client();
		Embedding_Sync::set_instance( new Embedding_Sync( null, $this->fake ) );
	}

	/**
	 * Resets the shared state.
	 *
	 * @since x.x.x
	 */
	public function tear_down(): void {
		delete_option( self::METHOD_OPTION );
		delete_option( self::MODEL_OPTION );
		Embedding_Sync::set_instance( null );
		parent::tear_down();
	}

	/**
	 * Saves the method and embedding model, then registers a fresh experiment.
	 *
	 * @since x.x.x
	 *
	 * @param string $method Suggestion method.
	 */
	private function configure( string $method ): void {
		update_option( self::METHOD_OPTION, $method );
		update_option(
			self::MODEL_OPTION,
			array(
				'provider' => self::PROVIDER,
				'model'    => self::MODEL,
			)
		);

		$experiment = new Content_Classification_Experiment();
		$experiment->register_settings();
		$experiment->register();
	}

	/**
	 * Saves a term vector.
	 *
	 * @since x.x.x
	 *
	 * @param int         $term_id  Term ID.
	 * @param string      $taxonomy Taxonomy.
	 * @param list<float> $vector   Vector.
	 */
	private function seed_term( int $term_id, string $taxonomy, array $vector ): void {
		Embedding_Sync::instance()->get_repository()->save(
			new Embedding_Record( 'term', $term_id, self::PROVIDER, self::MODEL, $vector, 0, '', 0, $taxonomy )
		);
	}

	/**
	 * Makes every term eligible for suggestions, whatever its use count.
	 *
	 * @since x.x.x
	 */
	private function allow_unused_terms(): void {
		add_filter( 'wpai_content_classification_embedding_min_term_count', static fn() => 1 );
	}

	/**
	 * Creates a term.
	 *
	 * @since x.x.x
	 *
	 * @param string $name      Term name.
	 * @param string $taxonomy  Taxonomy.
	 * @param int    $parent_id Optional. Parent term ID. Default 0.
	 * @return int The term ID.
	 */
	private function make_term( string $name, string $taxonomy, int $parent_id = 0 ): int {
		return self::factory()->term->create(
			array(
				'taxonomy' => $taxonomy,
				'name'     => $name,
				'parent'   => $parent_id,
			)
		);
	}

	/**
	 * Seeds the default category far from every post so both taxonomies count as embedded.
	 *
	 * @since x.x.x
	 */
	private function seed_default_category(): void {
		$this->seed_term( (int) get_option( 'default_category' ), 'category', self::ORTHOGONAL );
	}

	/**
	 * Returns the vector the fake client gives a post's text.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post $post The post.
	 * @return list<float> The vector.
	 */
	private function post_vector( WP_Post $post ): array {
		return Fake_Embedding_Client::vector_for( ( new Post_Source() )->build_text( $post ) );
	}

	/**
	 * Runs the ability.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $input Input.
	 * @return mixed The result.
	 */
	private function execute( array $input ) {
		$ability = new Content_Classification(
			'ai/content-classification',
			array(
				'label'       => 'Content Classification',
				'description' => 'AI-powered suggestions for post tags and categories.',
			)
		);

		$method = ( new \ReflectionClass( $ability ) )->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		return $method->invoke( $ability, $input );
	}

	/**
	 * Returns the suggested term names.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $result The ability result.
	 * @return list<string> Names.
	 */
	private function names( $result ): array {
		$this->assertIsArray( $result );

		return array_column( $result['suggestions'], 'term' );
	}

	/**
	 * Creates a published post.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $args Optional. Post arguments.
	 * @return \WP_Post The post.
	 */
	private function make_post( array $args = array() ): WP_Post {
		return self::factory()->post->create_and_get(
			array_merge(
				array(
					'post_title'   => 'Weeknight pasta',
					'post_content' => 'Boil the water, salt it, and cook the pasta.',
					'post_status'  => 'publish',
				),
				$args
			)
		);
	}

	/**
	 * Tests that the closest existing terms above the cutoff are suggested.
	 *
	 * @since x.x.x
	 */
	public function test_returns_closest_existing_terms_above_cutoff(): void {
		$this->configure( 'embeddings' );
		$this->allow_unused_terms();
		$post    = $this->make_post();
		$cooking = $this->make_term( 'Cooking', 'post_tag' );
		$finance = $this->make_term( 'Finance', 'post_tag' );
		$this->seed_term( $cooking, 'post_tag', $this->post_vector( $post ) );
		$this->seed_term( $finance, 'post_tag', self::ORTHOGONAL );
		$this->seed_default_category();

		$result = $this->execute(
			array(
				'post_id'  => $post->ID,
				'taxonomy' => 'post_tag',
			)
		);

		$this->assertSame( array( 'Cooking' ), $this->names( $result ) );
		$this->assertFalse( $result['suggestions'][0]['is_new'] );
	}

	/**
	 * Tests that terms already on the post are not suggested.
	 *
	 * @since x.x.x
	 */
	public function test_excludes_assigned_terms(): void {
		$this->configure( 'embeddings' );
		$this->allow_unused_terms();
		$post    = $this->make_post();
		$cooking = $this->make_term( 'Cooking', 'post_tag' );
		$pasta   = $this->make_term( 'Pasta', 'post_tag' );
		$this->seed_term( $cooking, 'post_tag', $this->post_vector( $post ) );
		$this->seed_term( $pasta, 'post_tag', $this->post_vector( $post ) );
		$this->seed_default_category();
		wp_set_post_terms( $post->ID, array( 'Cooking' ), 'post_tag' );

		$result = $this->execute(
			array(
				'post_id'  => $post->ID,
				'taxonomy' => 'post_tag',
			)
		);

		$this->assertSame( array( 'Pasta' ), $this->names( $result ) );
	}

	/**
	 * Tests that a term deleted after it was embedded is not suggested.
	 *
	 * @since x.x.x
	 */
	public function test_drops_terms_deleted_after_embedding(): void {
		$this->configure( 'embeddings' );
		$this->allow_unused_terms();
		$post    = $this->make_post();
		$cooking = $this->make_term( 'Cooking', 'post_tag' );
		$pasta   = $this->make_term( 'Pasta', 'post_tag' );
		$this->seed_term( $cooking, 'post_tag', $this->post_vector( $post ) );
		$this->seed_term( $pasta, 'post_tag', $this->post_vector( $post ) );
		$this->seed_default_category();
		wp_delete_term( $cooking, 'post_tag' );

		$this->assertSame( 1, count( Embedding_Sync::instance()->get_repository()->get( 'term', $cooking, self::PROVIDER, self::MODEL ) ) );

		$result = $this->execute(
			array(
				'post_id'  => $post->ID,
				'taxonomy' => 'post_tag',
			)
		);

		$this->assertSame( array( 'Pasta' ), $this->names( $result ) );
	}

	/**
	 * Tests that unchanged content reuses the stored post vectors.
	 *
	 * @since x.x.x
	 */
	public function test_reuses_cached_post_vectors_when_content_unchanged(): void {
		$this->configure( 'embeddings' );
		$post    = $this->make_post();
		$cooking = $this->make_term( 'Cooking', 'post_tag' );
		$this->seed_term( $cooking, 'post_tag', $this->post_vector( $post ) );
		$this->seed_default_category();

		$input = array(
			'post_id'  => $post->ID,
			'taxonomy' => 'post_tag',
		);
		$this->execute( $input );
		$this->execute( $input );

		$this->assertSame( 1, count( $this->fake->calls ) );
	}

	/**
	 * Tests that changed editor content is embedded again and replaces the stored hash.
	 *
	 * @since x.x.x
	 */
	public function test_reembeds_when_editor_content_changes(): void {
		$this->configure( 'embeddings' );
		$post    = $this->make_post();
		$cooking = $this->make_term( 'Cooking', 'post_tag' );
		$this->seed_term( $cooking, 'post_tag', $this->post_vector( $post ) );
		$this->seed_default_category();

		$this->execute(
			array(
				'post_id'  => $post->ID,
				'content'  => 'First draft about pasta.',
				'taxonomy' => 'post_tag',
			)
		);
		$this->execute(
			array(
				'post_id'  => $post->ID,
				'content'  => 'Second draft about risotto.',
				'taxonomy' => 'post_tag',
			)
		);

		$this->assertSame( 2, count( $this->fake->calls ) );
		$this->assertSame(
			Object_Processor::hash_text( ( new Post_Source() )->build_text( $post, 'Second draft about risotto.' ), Object_Processor::max_chunks(), null ),
			Embedding_Sync::instance()->get_repository()->get_content_hash( 'post', $post->ID, self::PROVIDER, self::MODEL )
		);
	}

	/**
	 * Tests that the stored post rows carry the hash sync would compute, so sync skips them.
	 *
	 * @since x.x.x
	 */
	public function test_stored_post_rows_match_sync_hash(): void {
		$this->configure( 'embeddings' );
		$post    = $this->make_post();
		$cooking = $this->make_term( 'Cooking', 'post_tag' );
		$this->seed_term( $cooking, 'post_tag', $this->post_vector( $post ) );
		$this->seed_default_category();

		$this->execute(
			array(
				'post_id'  => $post->ID,
				'taxonomy' => 'post_tag',
			)
		);

		$this->assertSame(
			Object_Processor::hash_text( ( new Post_Source() )->build_text( $post ), Object_Processor::max_chunks(), null ),
			Embedding_Sync::instance()->get_repository()->get_content_hash( 'post', $post->ID, self::PROVIDER, self::MODEL )
		);
	}

	/**
	 * Tests that a password-protected post is embedded but its vectors are not stored.
	 *
	 * @since x.x.x
	 */
	public function test_password_protected_post_is_embedded_but_not_stored(): void {
		$this->configure( 'embeddings' );
		$this->allow_unused_terms();
		$post    = $this->make_post( array( 'post_password' => 'secret' ) );
		$cooking = $this->make_term( 'Cooking', 'post_tag' );
		$this->seed_term( $cooking, 'post_tag', $this->post_vector( $post ) );
		$this->seed_default_category();

		$result = $this->execute(
			array(
				'post_id'  => $post->ID,
				'taxonomy' => 'post_tag',
			)
		);

		$this->assertSame( array( 'Cooking' ), $this->names( $result ) );
		$this->assertSame( 1, count( $this->fake->calls ) );
		$this->assertSame( 0, Embedding_Sync::instance()->get_repository()->count_objects( 'post', self::PROVIDER, self::MODEL ) );
	}

	/**
	 * Tests that missing term vectors return a not-ready error.
	 *
	 * @since x.x.x
	 */
	public function test_not_ready_returns_error(): void {
		$this->configure( 'embeddings' );
		$post = $this->make_post();

		$result = $this->execute(
			array(
				'post_id'  => $post->ID,
				'taxonomy' => 'post_tag',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ai_embeddings_not_ready', $result->get_error_code() );
		$this->assertSame( array(), $this->fake->calls );
	}

	/**
	 * Tests that the llm method never calls the embedding client.
	 *
	 * @since x.x.x
	 */
	public function test_llm_mode_unchanged(): void {
		$this->configure( 'llm' );
		$post    = $this->make_post();
		$cooking = $this->make_term( 'Cooking', 'post_tag' );
		$this->seed_term( $cooking, 'post_tag', $this->post_vector( $post ) );
		$this->seed_default_category();

		$this->execute(
			array(
				'post_id'  => $post->ID,
				'taxonomy' => 'post_tag',
			)
		);

		$this->assertSame( array(), $this->fake->calls );
	}

	/**
	 * Tests that a child category suggestion names its parent.
	 *
	 * @since x.x.x
	 */
	public function test_category_suggestion_includes_parent_name(): void {
		$this->configure( 'embeddings' );
		$this->allow_unused_terms();
		$post   = $this->make_post();
		$food   = $this->make_term( 'Food', 'category' );
		$baking = $this->make_term( 'Baking', 'category', $food );
		$this->seed_term( $baking, 'category', $this->post_vector( $post ) );
		$this->seed_term( $food, 'category', self::ORTHOGONAL );
		$this->seed_default_category();

		$result = $this->execute(
			array(
				'post_id'  => $post->ID,
				'taxonomy' => 'category',
			)
		);

		$this->assertSame( array( 'Baking' ), $this->names( $result ) );
		$this->assertSame( 'Food', $result['suggestions'][0]['parent'] );
	}

	/**
	 * Tests that terms used on fewer posts than the minimum count are not suggested.
	 *
	 * @since x.x.x
	 */
	public function test_skips_terms_used_fewer_than_min_count(): void {
		$this->configure( 'embeddings' );
		$post    = $this->make_post();
		$vector  = $this->post_vector( $post );
		$cooking = $this->make_term( 'Cooking', 'post_tag' );
		$baking  = $this->make_term( 'Baking', 'post_tag' );
		$this->seed_term( $cooking, 'post_tag', $vector );
		$this->seed_term( $baking, 'post_tag', array( $vector[0], $vector[1], $vector[2] + 0.1 ) );
		$this->seed_default_category();

		wp_set_post_terms( $this->make_post( array( 'post_title' => 'Soup' ) )->ID, array( $cooking ), 'post_tag' );
		wp_set_post_terms( $this->make_post( array( 'post_title' => 'Bread' ) )->ID, array( $baking ), 'post_tag' );
		wp_set_post_terms( $this->make_post( array( 'post_title' => 'Cake' ) )->ID, array( $baking ), 'post_tag' );

		$input = array(
			'post_id'  => $post->ID,
			'taxonomy' => 'post_tag',
		);

		$this->assertSame( array( 'Baking' ), $this->names( $this->execute( $input ) ) );

		$this->allow_unused_terms();

		$this->assertSame( array( 'Cooking', 'Baking' ), $this->names( $this->execute( $input ) ) );
	}
}
