<?php
/**
 * Tests for the object processor.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Embeddings\Sync
 */

namespace WordPress\AI\Tests\Integration\Includes\Embeddings\Sync;

use WP_UnitTestCase;
use WordPress\AI\Embeddings\Embedding_Record;
use WordPress\AI\Embeddings\Embedding_Repository;
use WordPress\AI\Embeddings\Sync\Consumer_Registry;
use WordPress\AI\Embeddings\Sync\Embedding_Client_Exception;
use WordPress\AI\Embeddings\Sync\Embedding_Client_Interface;
use WordPress\AI\Embeddings\Sync\Embedding_Target;
use WordPress\AI\Embeddings\Sync\Object_Processor;
use WordPress\AI\Embeddings\Sync\Object_Result;
use WordPress\AI\Embeddings\Sync\Post_Source;
use WordPress\AI\Embeddings\Sync\Provider_Backoff;
use WordPress\AI\Embeddings\Sync\Term_Source;
use WordPress\AI\Embeddings\Text_Chunker;

/**
 * Object_Processor test case.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Embeddings\Sync\Object_Processor
 * @covers \WordPress\AI\Embeddings\Sync\Object_Result
 */
class Object_ProcessorTest extends WP_UnitTestCase {

	use Sync_Tables_Trait;

	private const MODEL = 'text-embedding-3-small';

	/**
	 * Registry.
	 *
	 * @var \WordPress\AI\Embeddings\Sync\Consumer_Registry
	 */
	private Consumer_Registry $registry;

	/**
	 * Fake client.
	 *
	 * @var \WordPress\AI\Tests\Integration\Includes\Embeddings\Sync\Fake_Embedding_Client
	 */
	private Fake_Embedding_Client $client;

	/**
	 * Repository.
	 *
	 * @var \WordPress\AI\Embeddings\Embedding_Repository
	 */
	private Embedding_Repository $repository;

	/**
	 * Backoff.
	 *
	 * @var \WordPress\AI\Embeddings\Sync\Provider_Backoff
	 */
	private Provider_Backoff $backoff;

	/**
	 * Creates the tables once for the class.
	 *
	 * @since x.x.x
	 */
	public static function wpSetUpBeforeClass(): void {
		self::create_sync_tables();
	}

	/**
	 * Drops the tables after the class.
	 *
	 * @since x.x.x
	 */
	public static function wpTearDownAfterClass(): void {
		self::drop_sync_tables();
	}

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->registry = new Consumer_Registry();
		$this->registry->register(
			'test',
			array(
				'provider' => 'openai',
				'model'    => self::MODEL,
				'objects'  => array(
					'post' => array( 'post' ),
					'term' => array( 'post_tag' ),
				),
			)
		);

		$this->client     = new Fake_Embedding_Client();
		$this->repository = new Embedding_Repository();
		$this->backoff    = new Provider_Backoff();
	}

	/**
	 * Builds the processor under test.
	 *
	 * @since x.x.x
	 *
	 * @return \WordPress\AI\Embeddings\Sync\Object_Processor The processor.
	 */
	private function processor(): Object_Processor {
		return new Object_Processor(
			$this->registry,
			array(
				'post' => new Post_Source(),
				'term' => new Term_Source(),
			),
			$this->repository,
			$this->client,
			$this->backoff
		);
	}

	/**
	 * Tests that a new post is embedded, stored with its hash and announced.
	 *
	 * @since x.x.x
	 */
	public function test_embeds_and_stores_a_new_post(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Hello',
				'post_content' => 'World.',
			)
		);
		$indexed = array();

		add_action(
			'wpai_embedding_sync_object_indexed',
			static function ( string $type, int $id ) use ( &$indexed ): void {
				$indexed[] = array( $type, $id );
			},
			10,
			2
		);

		$results = $this->processor()->process( 'post', array( $post_id ) );

		$this->assertSame( Object_Result::DONE, $results[ $post_id ]->get_status() );
		$this->assertCount( 1, $this->client->calls );
		$this->assertSame( array( "Hello\n\nWorld." ), $this->client->calls[0]['inputs'] );

		$stored = $this->repository->get( 'post', $post_id, 'openai', self::MODEL );

		$this->assertCount( 1, $stored );
		$this->assertSame( 'post', $stored[0]->get_object_subtype() );
		$this->assertSame( Object_Processor::hash_text( "Hello\n\nWorld.", Object_Processor::DEFAULT_MAX_CHUNKS ), $stored[0]->get_content_hash() );
		$this->assertSame( array( array( 'post', $post_id ) ), $indexed );
	}

	/**
	 * Tests that unchanged content makes no API call.
	 *
	 * @since x.x.x
	 */
	public function test_unchanged_post_is_skipped_without_an_api_call(): void {
		$post_id = self::factory()->post->create();

		$this->processor()->process( 'post', array( $post_id ) );
		$results = $this->processor()->process( 'post', array( $post_id ) );

		$this->assertSame( Object_Result::SKIPPED, $results[ $post_id ]->get_status() );
		$this->assertCount( 1, $this->client->calls );
	}

	/**
	 * Unchanged multibyte content is not re-embedded.
	 *
	 * @since x.x.x
	 */
	public function test_unchanged_multibyte_post_is_not_re_embedded(): void {
		$post_id = self::factory()->post->create( array( 'post_content' => str_repeat( '日本語のテキスト🙂。', 200 ) ) );

		$this->processor()->process( 'post', array( $post_id ) );
		$calls = count( $this->client->calls );

		$this->processor()->process( 'post', array( $post_id ) );

		$this->assertGreaterThan( 1, count( $this->repository->get( 'post', $post_id, 'openai', self::MODEL ) ) );
		$this->assertCount( $calls, $this->client->calls );
	}

	/**
	 * Tests that shrinking content leaves no stranded chunks.
	 *
	 * @since x.x.x
	 */
	public function test_shorter_content_replaces_all_chunks(): void {
		$post_id = self::factory()->post->create( array( 'post_content' => str_repeat( 'word ', 600 ) ) );
		$this->processor()->process( 'post', array( $post_id ) );
		$this->assertGreaterThan( 1, count( $this->repository->get( 'post', $post_id, 'openai', self::MODEL ) ) );

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'Short now.',
			)
		);
		$this->processor()->process( 'post', array( $post_id ) );

		$this->assertCount( 1, $this->repository->get( 'post', $post_id, 'openai', self::MODEL ) );
	}

	/**
	 * Tests that two consumers on one model cost one call, and two models cost one call each.
	 *
	 * @since x.x.x
	 */
	public function test_calls_once_per_model(): void {
		$this->registry->register(
			'same-model',
			array(
				'provider' => 'openai',
				'model'    => self::MODEL,
				'objects'  => array( 'post' => array( 'post' ) ),
			)
		);
		$this->registry->register(
			'other-model',
			array(
				'provider' => 'google',
				'model'    => 'gemini-embedding-001',
				'objects'  => array( 'post' => array( 'post' ) ),
			)
		);

		$post_id = self::factory()->post->create();

		$this->processor()->process( 'post', array( $post_id ) );

		$this->assertCount( 2, $this->client->calls );
		$this->assertCount( 1, $this->repository->get( 'post', $post_id, 'google', 'gemini-embedding-001' ) );
	}

	/**
	 * Tests that a rate limit defers the object, pauses only that provider, and charges nothing.
	 *
	 * @since x.x.x
	 */
	public function test_rate_limit_defers_and_pauses_only_that_provider(): void {
		$this->registry->register(
			'other-model',
			array(
				'provider' => 'google',
				'model'    => 'gemini-embedding-001',
				'objects'  => array( 'post' => array( 'post' ) ),
			)
		);
		$this->client->fail_when = static function ( array $inputs, $target ) {
			return 'openai' === $target->get_provider()
				? new Embedding_Client_Exception( 'Too Many Requests (429)', Embedding_Client_Exception::RATE_LIMITED )
				: null;
		};

		$post_id = self::factory()->post->create();
		$results = $this->processor()->process( 'post', array( $post_id ) );

		$this->assertSame( Object_Result::DEFERRED, $results[ $post_id ]->get_status() );
		$this->assertTrue( $this->backoff->is_paused( 'openai' ) );
		$this->assertFalse( $this->backoff->is_paused( 'google' ) );
		$this->assertCount( 1, $this->repository->get( 'post', $post_id, 'google', 'gemini-embedding-001' ) );
	}

	/**
	 * Tests that a paused provider gets no calls at all.
	 *
	 * @since x.x.x
	 */
	public function test_paused_provider_is_not_called(): void {
		$this->backoff->record_rate_limit( 'openai', 'Too Many Requests (429)' );
		$post_id = self::factory()->post->create();

		$results = $this->processor()->process( 'post', array( $post_id ) );

		$this->assertSame( Object_Result::DEFERRED, $results[ $post_id ]->get_status() );
		$this->assertNotNull( $results[ $post_id ]->get_retry_at() );
		$this->assertSame( array(), $this->client->calls );
	}

	/**
	 * Tests that one bad object in a shared request fails alone.
	 *
	 * @since x.x.x
	 */
	public function test_item_error_in_a_shared_request_fails_only_the_bad_object(): void {
		$good = self::factory()->post->create(
			array(
				'post_title'   => 'Good',
				'post_content' => 'Fine.',
			)
		);
		$bad  = self::factory()->post->create(
			array(
				'post_title'   => 'BAD',
				'post_content' => 'Broken.',
			)
		);

		$this->client->fail_when = static function ( array $inputs ) {
			foreach ( $inputs as $input ) {
				if ( false !== strpos( $input, 'BAD' ) ) {
					return new Embedding_Client_Exception( 'Bad Request (400)', Embedding_Client_Exception::ITEM );
				}
			}

			return null;
		};

		$results = $this->processor()->process( 'post', array( $good, $bad ) );

		$this->assertSame( Object_Result::DONE, $results[ $good ]->get_status() );
		$this->assertSame( Object_Result::FAILED, $results[ $bad ]->get_status() );
		$this->assertSame( Embedding_Client_Exception::ITEM, $results[ $bad ]->get_error_class() );
		$this->assertCount( 1, $this->repository->get( 'post', $good, 'openai', self::MODEL ) );
		$this->assertSame( array(), $this->repository->get( 'post', $bad, 'openai', self::MODEL ) );
		$this->assertCount( 3, $this->client->calls );
	}

	/**
	 * Tests that a transient failure is reported as such.
	 *
	 * @since x.x.x
	 */
	public function test_transient_failure(): void {
		$this->client->failures = array( new Embedding_Client_Exception( 'Server error (503)', Embedding_Client_Exception::TRANSIENT ) );
		$post_id                = self::factory()->post->create();

		$result = $this->processor()->process( 'post', array( $post_id ) )[ $post_id ];

		$this->assertSame( Object_Result::FAILED, $result->get_status() );
		$this->assertSame( Embedding_Client_Exception::TRANSIENT, $result->get_error_class() );
		$this->assertSame( 'Server error (503)', $result->get_message() );
	}

	/**
	 * Tests removals: not indexable, empty text and missing objects lose their vectors, without calls.
	 *
	 * @since x.x.x
	 */
	public function test_removes_objects_that_should_have_no_vectors(): void {
		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$empty = self::factory()->post->create(
			array(
				'post_title'   => '',
				'post_content' => '<!-- wp:spacer /-->',
			)
		);

		$results = $this->processor()->process( 'post', array( $draft, $empty, 999999 ) );

		$this->assertSame( Object_Result::REMOVED, $results[ $draft ]->get_status() );
		$this->assertSame( Object_Result::REMOVED, $results[ $empty ]->get_status() );
		$this->assertSame( Object_Result::REMOVED, $results[999999]->get_status() );
		$this->assertSame( array(), $this->client->calls );
	}

	/**
	 * Tests that an uncovered post type is skipped.
	 *
	 * @since x.x.x
	 */
	public function test_uncovered_type_is_skipped(): void {
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->assertSame( Object_Result::SKIPPED, $this->processor()->process( 'post', array( $page ) )[ $page ]->get_status() );
		$this->assertSame( array(), $this->client->calls );
	}

	/**
	 * Tests the chunk cap.
	 *
	 * @since x.x.x
	 */
	public function test_max_chunks_filter_caps_chunks(): void {
		add_filter( 'wpai_embedding_sync_max_chunks', static fn(): int => 2 );
		$post_id = self::factory()->post->create( array( 'post_content' => str_repeat( 'word ', 2000 ) ) );

		$this->processor()->process( 'post', array( $post_id ) );

		$this->assertSame( 2, $this->client->input_count() );
		$this->assertCount( 2, $this->repository->get( 'post', $post_id, 'openai', self::MODEL ) );
	}

	/**
	 * Tests request packing by input count.
	 *
	 * @since x.x.x
	 */
	public function test_requests_respect_the_input_cap(): void {
		add_filter( 'wpai_embedding_sync_request_max_inputs', static fn(): int => 2 );
		$ids = self::factory()->post->create_many( 3 );

		$this->processor()->process( 'post', $ids );

		$this->assertCount( 2, $this->client->calls );
		$this->assertSame( 3, $this->client->input_count() );
	}

	/**
	 * Tests terms.
	 *
	 * @since x.x.x
	 */
	public function test_embeds_a_term(): void {
		$tag = self::factory()->tag->create( array( 'name' => 'Cats' ) );

		$this->assertSame( Object_Result::DONE, $this->processor()->process( 'term', array( $tag ) )[ $tag ]->get_status() );
		$this->assertCount( 1, $this->repository->get( 'term', $tag, 'openai', self::MODEL ) );
	}

	/**
	 * Tests that each object's vectors are stored against that object, in chunk order.
	 *
	 * @since x.x.x
	 */
	public function test_vectors_land_on_the_right_object(): void {
		$ids = array(
			self::factory()->post->create( array( 'post_content' => str_repeat( 'alpha ', 600 ) ) ),
			self::factory()->post->create( array( 'post_content' => str_repeat( 'beta ', 600 ) ) ),
			self::factory()->post->create( array( 'post_content' => 'Gamma.' ) ),
		);

		$this->processor()->process( 'post', $ids );

		$this->assertCount( 1, $this->client->calls );

		$source  = new Post_Source();
		$chunker = new Text_Chunker();

		foreach ( $ids as $index => $post_id ) {
			$chunks = $chunker->chunk( $source->get_text( $post_id ) );
			$stored = $this->repository->get( 'post', $post_id, 'openai', self::MODEL );

			if ( $index < 2 ) {
				$this->assertGreaterThan( 1, count( $chunks ) );
			}

			$this->assertCount( count( $chunks ), $stored );

			foreach ( $chunks as $chunk_index => $chunk ) {
				$this->assertSame( $chunk_index, $stored[ $chunk_index ]->get_chunk_index() );
				$this->assertEqualsWithDelta( Fake_Embedding_Client::vector_for( $chunk ), $stored[ $chunk_index ]->get_vector(), 1e-6 );
			}
		}
	}

	/**
	 * Tests that a short response fails every object in the shared request, stores nothing and keeps the backoff.
	 *
	 * @since x.x.x
	 */
	public function test_short_response_fails_the_whole_group_without_storing(): void {
		$seeded = self::factory()->post->create( array( 'post_content' => str_repeat( 'word ', 600 ) ) );
		$fresh  = self::factory()->post->create( array( 'post_content' => 'Fresh.' ) );
		$this->repository->save( new Embedding_Record( 'post', $seeded, 'openai', self::MODEL, array( 0.1, 0.2, 0.3 ), 0, 'old-hash', 0, 'post' ) );
		$this->backoff->record_provider_error( 'openai', 'Unauthorized (401)', time() - 4000 );

		// Drops the last vector, so every slice but the final object's would look complete.
		$short = new class() implements Embedding_Client_Interface {
			/**
			 * Number of calls made.
			 *
			 * @var int
			 */
			public int $calls = 0;

			/**
			 * {@inheritDoc}
			 *
			 * @since x.x.x
			 */
			public function embed( Embedding_Target $target, array $inputs ): array {
				++$this->calls;

				return array_map( array( Fake_Embedding_Client::class, 'vector_for' ), array_slice( $inputs, 0, -1 ) );
			}
		};

		$processor = new Object_Processor(
			$this->registry,
			array( 'post' => new Post_Source() ),
			$this->repository,
			$short,
			$this->backoff
		);

		$results = $processor->process( 'post', array( $seeded, $fresh ) );
		$stored  = $this->repository->get( 'post', $seeded, 'openai', self::MODEL );

		$this->assertSame( 1, $short->calls );

		foreach ( array( $seeded, $fresh ) as $post_id ) {
			$this->assertSame( Object_Result::FAILED, $results[ $post_id ]->get_status() );
			$this->assertSame( Embedding_Client_Exception::TRANSIENT, $results[ $post_id ]->get_error_class() );
		}

		$this->assertCount( 1, $stored );
		$this->assertSame( 'old-hash', $stored[0]->get_content_hash() );
		$this->assertSame( array(), $this->repository->get( 'post', $fresh, 'openai', self::MODEL ) );
		$this->assertNotNull( $this->backoff->get( 'openai' ) );
	}

	/**
	 * Tests that a provider-level error defers the object and records the class.
	 *
	 * @since x.x.x
	 */
	public function test_provider_error_defers_and_records_the_class(): void {
		$this->client->failures = array( new Embedding_Client_Exception( 'Unauthorized (401)', Embedding_Client_Exception::PROVIDER ) );
		$post_id                = self::factory()->post->create();

		$result = $this->processor()->process( 'post', array( $post_id ) )[ $post_id ];

		$this->assertSame( Object_Result::DEFERRED, $result->get_status() );
		$this->assertSame( Embedding_Client_Exception::PROVIDER, $this->backoff->get( 'openai' )['error_class'] );
	}

	/**
	 * Tests that a pause mid-batch defers the later request groups without calling them.
	 *
	 * @since x.x.x
	 */
	public function test_pause_defers_later_groups(): void {
		add_filter( 'wpai_embedding_sync_request_max_inputs', static fn(): int => 1 );
		$ids = self::factory()->post->create_many( 3 );

		$this->client->failures = array(
			null,
			new Embedding_Client_Exception( 'Too Many Requests (429)', Embedding_Client_Exception::RATE_LIMITED ),
		);

		$results = $this->processor()->process( 'post', $ids );

		$this->assertSame( Object_Result::DONE, $results[ $ids[0] ]->get_status() );
		$this->assertSame( Object_Result::DEFERRED, $results[ $ids[1] ]->get_status() );
		$this->assertSame( Object_Result::DEFERRED, $results[ $ids[2] ]->get_status() );
		$this->assertCount( 2, $this->client->calls );
	}

	/**
	 * Tests that only the given target is processed.
	 *
	 * @since x.x.x
	 */
	public function test_only_target_is_respected(): void {
		$this->registry->register(
			'other-model',
			array(
				'provider' => 'google',
				'model'    => 'gemini-embedding-001',
				'objects'  => array( 'post' => array( 'post' ) ),
			)
		);
		$post_id = self::factory()->post->create();

		$this->processor()->process( 'post', array( $post_id ), $this->registry->get_target_for_consumer( 'test' ) );

		$this->assertNotSame( array(), $this->client->calls );
		$this->assertSame( array( 'openai' ), array_values( array_unique( array_column( $this->client->calls, 'provider' ) ) ) );
		$this->assertSame( array(), $this->repository->get( 'post', $post_id, 'google', 'gemini-embedding-001' ) );
		$this->assertCount( 1, $this->repository->get( 'post', $post_id, 'openai', self::MODEL ) );
	}

	/**
	 * Tests that removing an object deletes its stored vectors.
	 *
	 * @since x.x.x
	 */
	public function test_removal_deletes_stored_vectors(): void {
		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$this->repository->save( new Embedding_Record( 'post', $draft, 'openai', self::MODEL, array( 0.1, 0.2, 0.3 ), 0, 'old-hash', 0, 'post' ) );
		$this->assertCount( 1, $this->repository->get( 'post', $draft, 'openai', self::MODEL ) );

		$result = $this->processor()->process( 'post', array( $draft ) )[ $draft ];

		$this->assertSame( Object_Result::REMOVED, $result->get_status() );
		$this->assertSame( array(), $this->repository->get( 'post', $draft, 'openai', self::MODEL ) );
	}

	/**
	 * Tests that a successful embed clears an expired-but-stored backoff.
	 *
	 * @since x.x.x
	 */
	public function test_success_clears_the_backoff(): void {
		// Pause ended 400 s ago; the transient lives PROVIDER_PAUSE + MAX_DELAY from now, so the state is still stored.
		$this->backoff->record_provider_error( 'openai', 'Unauthorized (401)', time() - 4000 );
		$this->assertNotNull( $this->backoff->get( 'openai' ) );
		$this->assertFalse( $this->backoff->is_paused( 'openai' ) );

		$post_id = self::factory()->post->create();
		$result  = $this->processor()->process( 'post', array( $post_id ) )[ $post_id ];

		$this->assertSame( Object_Result::DONE, $result->get_status() );
		$this->assertNull( $this->backoff->get( 'openai' ) );
	}
}
