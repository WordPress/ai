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

	use Captures_Warnings_Trait;
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
	 * Tests that a new post is embedded and stored with its hash, and that a term is embedded too.
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

		$results = $this->processor()->process( 'post', array( $post_id ) );

		$this->assertSame( Object_Result::DONE, $results[ $post_id ]->get_status() );
		$this->assertCount( 1, $this->client->calls );
		$this->assertSame( array( "Hello\n\nWorld." ), $this->client->calls[0]['inputs'] );

		$stored = $this->repository->get( 'post', $post_id, 'openai', self::MODEL );

		$this->assertCount( 1, $stored );
		$this->assertSame( 'post', $stored[0]->get_object_subtype() );
		$this->assertSame( Object_Processor::hash_text( "Hello\n\nWorld.", Object_Processor::DEFAULT_MAX_CHUNKS, null ), $stored[0]->get_content_hash() );

		// Terms go through the same path.
		$tag = self::factory()->tag->create( array( 'name' => 'Cats' ) );

		$this->assertSame( Object_Result::DONE, $this->processor()->process( 'term', array( $tag ) )[ $tag ]->get_status() );
		$this->assertCount( 1, $this->repository->get( 'term', $tag, 'openai', self::MODEL ) );
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
	 * Tests that unchanged text is re-embedded when the target's dimensions change.
	 *
	 * @since x.x.x
	 */
	public function test_changed_dimensions_re_embed_unchanged_text(): void {
		$post_id = self::factory()->post->create();

		$this->processor()->process( 'post', array( $post_id ) );

		$this->registry->unregister( 'test' );
		$this->registry->register(
			'test',
			array(
				'provider'   => 'openai',
				'model'      => self::MODEL,
				'dimensions' => 512,
				'objects'    => array( 'post' => array( 'post' ) ),
			)
		);

		$results = $this->processor()->process( 'post', array( $post_id ) );

		$this->assertSame( Object_Result::DONE, $results[ $post_id ]->get_status() );
		$this->assertCount( 2, $this->client->calls );

		// Unchanged again under the new dimensions: skipped.
		$results = $this->processor()->process( 'post', array( $post_id ) );

		$this->assertSame( Object_Result::SKIPPED, $results[ $post_id ]->get_status() );
		$this->assertCount( 2, $this->client->calls );
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
	 * Tests that an object whose text preparation throws fails transiently without stopping the others.
	 *
	 * @since x.x.x
	 */
	public function test_throwing_preparation_fails_only_that_object(): void {
		$bad    = self::factory()->post->create( array( 'post_title' => 'Bad' ) );
		$good   = self::factory()->post->create( array( 'post_title' => 'Good' ) );
		$filter = static function ( string $text, \WP_Post $post ) use ( $bad ): string {
			if ( $bad === $post->ID ) {
				throw new \LogicException( 'Broken text filter.' );
			}

			return $text;
		};

		add_filter( 'wpai_embedding_sync_post_text', $filter, 10, 2 );
		$results = $this->processor()->process( 'post', array( $bad, $good ) );

		$this->assertSame( Object_Result::FAILED, $results[ $bad ]->get_status() );
		$this->assertSame( Embedding_Client_Exception::TRANSIENT, $results[ $bad ]->get_error_class() );
		$this->assertSame( 'Broken text filter.', $results[ $bad ]->get_message() );
		$this->assertSame( Object_Result::DONE, $results[ $good ]->get_status() );
		$this->assertCount( 1, $this->repository->get( 'post', $good, 'openai', self::MODEL ) );
	}

	/**
	 * Tests that an unexpected error from the client fails the group transiently.
	 *
	 * @since x.x.x
	 */
	public function test_unexpected_client_error_fails_the_group_transiently(): void {
		$post_id                 = self::factory()->post->create();
		$this->client->fail_when = static function () {
			throw new \Error( 'Unexpected client error.' );
		};

		$result = $this->processor()->process( 'post', array( $post_id ) )[ $post_id ];

		$this->assertSame( Object_Result::FAILED, $result->get_status() );
		$this->assertSame( Embedding_Client_Exception::TRANSIENT, $result->get_error_class() );
		$this->assertSame( 'Unexpected client error.', $result->get_message() );
	}

	/**
	 * Tests that a throwing object_indexed subscriber is reported and the object stays done.
	 *
	 * @since x.x.x
	 */
	public function test_throwing_indexed_subscriber_is_reported(): void {
		$post_id    = self::factory()->post->create();
		$subscriber = static function (): void {
			throw new \RuntimeException( 'Broken subscriber.' );
		};

		add_action( 'wpai_embedding_sync_object_indexed', $subscriber );
		$result   = null;
		$warnings = $this->capture_warnings(
			function () use ( $post_id, &$result ): void {
				$result = $this->processor()->process( 'post', array( $post_id ) )[ $post_id ];
			}
		);

		$this->assertSame( Object_Result::DONE, $result->get_status() );
		$this->assertCount( 1, $this->repository->get( 'post', $post_id, 'openai', self::MODEL ) );
		$this->assertCount( 1, $warnings );
		$this->assertSame( E_USER_WARNING, $warnings[0][2] );
		$this->assertStringContainsString( 'Broken subscriber.', $warnings[0][1] );
	}

	/**
	 * Tests removals: not indexable, empty text and missing objects lose their stored vectors, without calls.
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
		$this->repository->save( new Embedding_Record( 'post', $draft, 'openai', self::MODEL, array( 0.1, 0.2, 0.3 ), 0, 'old-hash', 0, 'post' ) );

		$results = $this->processor()->process( 'post', array( $draft, $empty, 999999 ) );

		$this->assertSame( Object_Result::REMOVED, $results[ $draft ]->get_status() );
		$this->assertSame( Object_Result::REMOVED, $results[ $empty ]->get_status() );
		$this->assertSame( Object_Result::REMOVED, $results[999999]->get_status() );
		$this->assertSame( array(), $this->repository->get( 'post', $draft, 'openai', self::MODEL ) );
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
	 * Tests that requests stop once the deadline passes, after at least one request.
	 *
	 * @since x.x.x
	 */
	public function test_requests_stop_at_the_deadline_after_the_first(): void {
		add_filter( 'wpai_embedding_sync_request_max_inputs', static fn(): int => 1 );
		$ids = self::factory()->post->create_many( 3 );

		$results = $this->processor()->process( 'post', $ids, null, microtime( true ) - 1 );

		$this->assertCount( 1, $this->client->calls, 'The first request always runs.' );
		$this->assertSame( Object_Result::DONE, $results[ $ids[0] ]->get_status() );
		$this->assertSame( Object_Result::DEFERRED, $results[ $ids[1] ]->get_status() );
		$this->assertSame( Object_Result::DEFERRED, $results[ $ids[2] ]->get_status() );
		$this->assertLessThanOrEqual( time(), $results[ $ids[1] ]->get_retry_at() );
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

	/**
	 * Tests that a transient failure is reported as such, with its message unescaped.
	 *
	 * @since x.x.x
	 */
	public function test_failure_messages_are_raw(): void {
		$original               = new \RuntimeException( 'Input "too" long & <bad>' );
		$this->client->failures = array( new Embedding_Client_Exception( esc_html( $original->getMessage() ), Embedding_Client_Exception::TRANSIENT, $original ) );
		$post_id                = self::factory()->post->create();

		$result = $this->processor()->process( 'post', array( $post_id ) )[ $post_id ];

		$this->assertSame( Object_Result::FAILED, $result->get_status() );
		$this->assertSame( Embedding_Client_Exception::TRANSIENT, $result->get_error_class() );
		$this->assertSame( 'Input "too" long & <bad>', $result->get_message() );
	}

	/**
	 * Tests that a provider-level failure defers the object, records the class and pauses only the failing model.
	 *
	 * @since x.x.x
	 */
	public function test_provider_error_pauses_only_that_model(): void {
		$this->registry->register(
			'other-model',
			array(
				'provider' => 'openai',
				'model'    => 'text-embedding-3-large',
				'objects'  => array( 'post' => array( 'post' ) ),
			)
		);
		$this->client->fail_when = static function ( array $inputs, $target ) {
			return self::MODEL === $target->get_model()
				? new Embedding_Client_Exception( 'Not Found (404)', Embedding_Client_Exception::PROVIDER )
				: null;
		};
		$post_id                 = self::factory()->post->create();

		$result = $this->processor()->process( 'post', array( $post_id ) )[ $post_id ];

		$this->assertSame( Object_Result::DEFERRED, $result->get_status() );
		$this->assertSame( Embedding_Client_Exception::PROVIDER, $this->backoff->get( 'openai', self::MODEL )['error_class'] );
		$this->assertFalse( $this->backoff->is_paused( 'openai' ) );
		$this->assertCount( 1, $this->repository->get( 'post', $post_id, 'openai', 'text-embedding-3-large' ) );
	}

	/**
	 * Tests that three single retries rejected in a row trip the breaker and pause the model.
	 *
	 * The streak is charged as a transient failure, so the attempt cap ends a run of genuinely
	 * bad inputs; the objects not yet tried are deferred until the pause ends.
	 *
	 * @since x.x.x
	 */
	public function test_circuit_breaker_pauses_after_three_rejections(): void {
		$ids                     = self::factory()->post->create_many( 6 );
		$this->client->fail_when = static fn() => new Embedding_Client_Exception( 'Bad Request (400)', Embedding_Client_Exception::ITEM );

		$results = $this->processor()->process( 'post', $ids );
		$streak  = array_slice( $ids, 0, Object_Processor::CIRCUIT_BREAKER_THRESHOLD );
		$untried = array_slice( $ids, Object_Processor::CIRCUIT_BREAKER_THRESHOLD );

		$this->assertCount( 1 + Object_Processor::CIRCUIT_BREAKER_THRESHOLD, $this->client->calls, 'One shared request, then three singles.' );

		foreach ( $streak as $id ) {
			$this->assertSame( Object_Result::FAILED, $results[ $id ]->get_status() );
			$this->assertSame( Embedding_Client_Exception::TRANSIENT, $results[ $id ]->get_error_class() );
			$this->assertStringContainsString( 'pausing this model as likely misconfigured', $results[ $id ]->get_message() );
		}

		foreach ( $untried as $id ) {
			$this->assertSame( Object_Result::DEFERRED, $results[ $id ]->get_status() );
			$this->assertGreaterThan( time(), $results[ $id ]->get_retry_at() );
		}

		$state = $this->backoff->get( 'openai', self::MODEL );

		$this->assertSame( Embedding_Client_Exception::PROVIDER, $state['error_class'] );
		$this->assertStringContainsString( 'Bad Request (400)', $state['error'] );
	}

	/**
	 * Tests that a tripped breaker charges only the rejection streak and defers the untried rest.
	 *
	 * Rejections before a success keep their own item failures, and the success resets the streak.
	 *
	 * @since x.x.x
	 */
	public function test_circuit_breaker_charges_only_the_streak(): void {
		$early = array(
			self::factory()->post->create( array( 'post_title' => 'BAD early one' ) ),
			self::factory()->post->create( array( 'post_title' => 'BAD early two' ) ),
		);
		$good  = self::factory()->post->create( array( 'post_title' => 'Good' ) );
		$late  = array(
			self::factory()->post->create( array( 'post_title' => 'BAD one' ) ),
			self::factory()->post->create( array( 'post_title' => 'BAD two' ) ),
			self::factory()->post->create( array( 'post_title' => 'BAD three' ) ),
		);
		$after = self::factory()->post->create( array( 'post_title' => 'Good after' ) );

		$this->client->fail_when = static function ( array $inputs ) {
			foreach ( $inputs as $input ) {
				if ( false !== strpos( $input, 'BAD' ) ) {
					return new Embedding_Client_Exception( 'Bad Request (400)', Embedding_Client_Exception::ITEM );
				}
			}

			return null;
		};

		$results = $this->processor()->process( 'post', array_merge( $early, array( $good ), $late, array( $after ) ) );

		foreach ( $early as $id ) {
			$this->assertSame( Object_Result::FAILED, $results[ $id ]->get_status() );
			$this->assertSame( Embedding_Client_Exception::ITEM, $results[ $id ]->get_error_class() );
		}

		$this->assertSame( Object_Result::DONE, $results[ $good ]->get_status() );

		foreach ( $late as $id ) {
			$this->assertSame( Object_Result::FAILED, $results[ $id ]->get_status() );
			$this->assertSame( Embedding_Client_Exception::TRANSIENT, $results[ $id ]->get_error_class() );
		}

		$this->assertSame( Object_Result::DEFERRED, $results[ $after ]->get_status() );

		$this->assertNotNull( $this->backoff->get_until_for( new Embedding_Target( 'openai', self::MODEL ) ) );
	}

	/**
	 * Tests that single retries after a rejected shared request stop at the deadline.
	 *
	 * The first single retry is exempt, so every split settles at least one object.
	 *
	 * @since x.x.x
	 */
	public function test_single_retries_stop_at_the_deadline(): void {
		$ids = array(
			self::factory()->post->create( array( 'post_title' => 'BAD one' ) ),
			self::factory()->post->create( array( 'post_title' => 'Good one' ) ),
			self::factory()->post->create( array( 'post_title' => 'BAD two' ) ),
			self::factory()->post->create( array( 'post_title' => 'Good two' ) ),
		);

		$this->client->fail_when = static function ( array $inputs ) {
			foreach ( $inputs as $input ) {
				if ( false !== strpos( $input, 'BAD' ) ) {
					return new Embedding_Client_Exception( 'Bad Request (400)', Embedding_Client_Exception::ITEM );
				}
			}

			return null;
		};

		$results = $this->processor()->process( 'post', $ids, null, microtime( true ) - 1 );

		$this->assertCount( 2, $this->client->calls, 'The shared request and one single retry; no further retry past the deadline.' );
		$this->assertSame( Object_Result::FAILED, $results[ $ids[0] ]->get_status() );
		$this->assertSame( Embedding_Client_Exception::ITEM, $results[ $ids[0] ]->get_error_class() );

		foreach ( array_slice( $ids, 1 ) as $id ) {
			$this->assertSame( Object_Result::DEFERRED, $results[ $id ]->get_status() );
			$this->assertLessThanOrEqual( time(), $results[ $id ]->get_retry_at() );
		}

		$this->assertNull( $this->backoff->get( 'openai', self::MODEL ) );
	}

	/**
	 * Tests that a split past the deadline still settles its first object.
	 *
	 * Otherwise a shared request that spends the budget and fails on an item would defer the whole
	 * group on every run.
	 *
	 * @since x.x.x
	 */
	public function test_first_single_retry_is_exempt_from_the_deadline(): void {
		$ids = array(
			self::factory()->post->create( array( 'post_title' => 'Good one' ) ),
			self::factory()->post->create( array( 'post_title' => 'BAD one' ) ),
			self::factory()->post->create( array( 'post_title' => 'Good two' ) ),
		);

		$this->client->fail_when = static function ( array $inputs ) {
			foreach ( $inputs as $input ) {
				if ( false !== strpos( $input, 'BAD' ) ) {
					return new Embedding_Client_Exception( 'Bad Request (400)', Embedding_Client_Exception::ITEM );
				}
			}

			return null;
		};

		$results = $this->processor()->process( 'post', $ids, null, microtime( true ) - 1 );

		$this->assertCount( 2, $this->client->calls, 'The shared request, then one single retry.' );
		$this->assertCount( 3, $this->client->calls[0]['inputs'], 'The posts share one request.' );
		$this->assertSame( Object_Result::DONE, $results[ $ids[0] ]->get_status() );

		foreach ( array_slice( $ids, 1 ) as $id ) {
			$this->assertSame( Object_Result::DEFERRED, $results[ $id ]->get_status() );
		}
	}
}
