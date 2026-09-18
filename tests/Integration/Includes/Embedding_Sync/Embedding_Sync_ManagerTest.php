<?php
/**
 * Integration tests for Embedding_Sync_Manager.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Embedding_Sync
 */

namespace WordPress\AI\Tests\Integration\Includes\Embedding_Sync;

use WP_Error;
use WP_UnitTestCase;
use WordPress\AI\Embedding_Sync\Embedding_Sync_Manager;
use WordPress\AI\Embeddings\Embedding_Repository;
use WordPress\AI\Embeddings\Embedding_Schema;

/**
 * Embedding_Sync_Manager test case.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Embedding_Sync\Embedding_Sync_Manager
 */
class Embedding_Sync_ManagerTest extends WP_UnitTestCase {

	private const FEATURE_ID     = 'test-embedding-sync';
	private const DEVELOPER_META = 'wpai_feature_test-embedding-sync_field_developer';
	private const PROVIDER       = 'ollama';
	private const MODEL          = 'nomic-embed-text:latest';

	/**
	 * Schema instance, shared so it can be reset between tests.
	 *
	 * @var \WordPress\AI\Embeddings\Embedding_Schema
	 */
	private Embedding_Schema $schema;

	/**
	 * Repository under test.
	 *
	 * @var \WordPress\AI\Embeddings\Embedding_Repository
	 */
	private Embedding_Repository $repository;

	/**
	 * Manager under test.
	 *
	 * @var \WordPress\AI\Tests\Integration\Includes\Embedding_Sync\Testable_Embedding_Sync_Manager
	 */
	private Testable_Embedding_Sync_Manager $manager;

	/**
	 * Set up test case.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->schema     = new Embedding_Schema();
		$this->repository = new Embedding_Repository( $this->schema );
		$this->manager    = new Testable_Embedding_Sync_Manager( self::FEATURE_ID, $this->repository );

		delete_option( self::DEVELOPER_META );
		delete_option( Embedding_Sync_Manager::QUEUE_OPTION );
	}

	/**
	 * Tear down test case.
	 */
	protected function tearDown(): void {
		// CREATE/DROP TABLE force an implicit commit, so the storage layer's own
		// state is reset explicitly rather than relying on transaction rollback,
		// matching Embedding_RepositoryTest.
		$this->schema->drop_table();
		delete_option( Embedding_Schema::SCHEMA_VERSION_OPTION );

		delete_option( self::DEVELOPER_META );
		delete_option( Embedding_Sync_Manager::QUEUE_OPTION );
		wp_clear_scheduled_hook( Embedding_Sync_Manager::QUEUE_HOOK );
		remove_all_filters( 'wpai_embedding_sync_post_types' );
		remove_all_filters( 'wpai_embedding_sync_post_statuses' );
		remove_all_filters( 'wpai_embedding_sync_batch_size' );

		parent::tearDown();
	}

	/**
	 * Configures a provider and model, as if set via Developer Options.
	 */
	private function configure_provider_model(): void {
		update_option(
			self::DEVELOPER_META,
			array(
				'provider' => self::PROVIDER,
				'model'    => self::MODEL,
			)
		);
	}

	/**
	 * Returns the raw queue option value.
	 *
	 * @return array<int, mixed>
	 */
	private function get_queue_option(): array {
		$queue = get_option( Embedding_Sync_Manager::QUEUE_OPTION, array() );
		return is_array( $queue ) ? $queue : array();
	}

	/**
	 * Tests that init() schedules the queue-processing cron event.
	 *
	 * @since x.x.x
	 */
	public function test_init_schedules_cron_event(): void {
		$this->assertFalse( wp_next_scheduled( Embedding_Sync_Manager::QUEUE_HOOK ) );

		$this->manager->init();

		$this->assertNotFalse( wp_next_scheduled( Embedding_Sync_Manager::QUEUE_HOOK ) );
	}

	/**
	 * Tests that saving a configured post type and status queues it.
	 *
	 * @since x.x.x
	 */
	public function test_maybe_queue_post_queues_published_post(): void {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'publish' ) );

		$this->manager->maybe_queue_post( $post->ID, $post, true );

		$this->assertArrayHasKey( $post->ID, $this->get_queue_option() );
	}

	/**
	 * Tests that a disallowed post type is not queued.
	 *
	 * @since x.x.x
	 */
	public function test_maybe_queue_post_ignores_disallowed_post_type(): void {
		$post = self::factory()->post->create_and_get(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'publish',
			)
		);

		$this->manager->maybe_queue_post( $post->ID, $post, true );

		$this->assertArrayNotHasKey( $post->ID, $this->get_queue_option() );
	}

	/**
	 * Tests that a disallowed post status is not queued.
	 *
	 * @since x.x.x
	 */
	public function test_maybe_queue_post_ignores_disallowed_post_status(): void {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'draft' ) );

		$this->manager->maybe_queue_post( $post->ID, $post, true );

		$this->assertArrayNotHasKey( $post->ID, $this->get_queue_option() );
	}

	/**
	 * Tests that the post-type filter can widen what gets queued.
	 *
	 * @since x.x.x
	 */
	public function test_maybe_queue_post_respects_post_types_filter(): void {
		add_filter(
			'wpai_embedding_sync_post_types',
			static function () {
				return array( 'attachment' );
			}
		);
		add_filter(
			'wpai_embedding_sync_post_statuses',
			static function () {
				// Core forces every attachment to 'inherit', 'private', 'trash', or
				// 'auto-draft' regardless of the status it is created with.
				return array( 'inherit' );
			}
		);

		$post = self::factory()->post->create_and_get( array( 'post_type' => 'attachment' ) );

		$this->manager->maybe_queue_post( $post->ID, $post, true );

		$this->assertArrayHasKey( $post->ID, $this->get_queue_option() );
	}

	/**
	 * Tests that deleting a post removes its queue entry and stored embedding.
	 *
	 * @since x.x.x
	 */
	public function test_handle_post_deleted_clears_queue_and_storage(): void {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'publish' ) );

		$this->manager->maybe_queue_post( $post->ID, $post, true );
		$this->assertArrayHasKey( $post->ID, $this->get_queue_option() );

		$this->repository->save(
			new \WordPress\AI\Embeddings\Embedding_Record(
				'post',
				$post->ID,
				self::PROVIDER,
				self::MODEL,
				array( 0.1, 0.2, 0.3 ),
				0,
				'somehash'
			)
		);

		$this->manager->handle_post_deleted( $post->ID );

		$this->assertArrayNotHasKey( $post->ID, $this->get_queue_option() );
		$this->assertSame( array(), $this->repository->get( 'post', $post->ID, self::PROVIDER, self::MODEL ) );
	}

	/**
	 * Tests that the queue is left untouched when no provider/model is configured.
	 *
	 * @since x.x.x
	 */
	public function test_process_queue_does_nothing_without_configured_model(): void {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'publish' ) );
		$this->manager->maybe_queue_post( $post->ID, $post, true );

		$this->manager->process_queue();

		$this->assertSame( 0, $this->manager->generate_calls );
		$this->assertArrayHasKey( $post->ID, $this->get_queue_option() );
	}

	/**
	 * Tests that a post whose stored hash already matches its content is skipped without a
	 * provider request.
	 *
	 * @since x.x.x
	 */
	public function test_process_queue_skips_post_with_unchanged_content(): void {
		$this->configure_provider_model();

		$post = self::factory()->post->create_and_get(
			array(
				'post_status'  => 'publish',
				'post_content' => 'Some content.',
			)
		);

		$this->repository->save(
			new \WordPress\AI\Embeddings\Embedding_Record(
				'post',
				$post->ID,
				self::PROVIDER,
				self::MODEL,
				array( 0.1, 0.2, 0.3 ),
				0,
				hash( 'sha256', \WordPress\AI\normalize_content( $post->post_content ) )
			)
		);

		$this->manager->maybe_queue_post( $post->ID, $post, true );
		$this->manager->process_queue();

		$this->assertSame( 0, $this->manager->generate_calls, 'A provider request should not be made for unchanged content.' );
		$this->assertArrayNotHasKey( $post->ID, $this->get_queue_option(), 'The post should be drained from the queue.' );
	}

	/**
	 * Tests that a dirty post is embedded and stored, and removed from the queue.
	 *
	 * @since x.x.x
	 */
	public function test_process_queue_embeds_and_stores_dirty_post(): void {
		$this->configure_provider_model();

		$post = self::factory()->post->create_and_get(
			array(
				'post_status'  => 'publish',
				'post_content' => 'Some content.',
			)
		);

		$this->manager->vector_to_return = array( 0.4, 0.5, 0.6 );
		$this->manager->maybe_queue_post( $post->ID, $post, true );
		$this->manager->process_queue();

		$this->assertSame( 1, $this->manager->generate_calls );
		$this->assertArrayNotHasKey( $post->ID, $this->get_queue_option() );

		$stored = $this->repository->get( 'post', $post->ID, self::PROVIDER, self::MODEL );
		$this->assertCount( 1, $stored );

		// Vectors round-trip through packed float32 bytes, so compare with a tolerance
		// rather than exact equality; see Vector_Codec.
		$vector = $stored[0]->get_vector();
		$this->assertCount( 3, $vector );
		foreach ( array( 0.4, 0.5, 0.6 ) as $index => $expected ) {
			$this->assertEqualsWithDelta( $expected, $vector[ $index ], 0.0001 );
		}
	}

	/**
	 * Tests that a provider error re-queues the batch instead of storing anything.
	 *
	 * @since x.x.x
	 */
	public function test_process_queue_requeues_batch_on_provider_error(): void {
		$this->configure_provider_model();

		$post = self::factory()->post->create_and_get(
			array(
				'post_status'  => 'publish',
				'post_content' => 'Some content.',
			)
		);

		$this->manager->error_to_return = new WP_Error( 'ai_embeddings_failed', 'Provider unavailable' );
		$this->manager->maybe_queue_post( $post->ID, $post, true );
		$this->manager->process_queue();

		$this->assertSame( 1, $this->manager->generate_calls );
		$this->assertArrayHasKey( $post->ID, $this->get_queue_option(), 'A failed batch should be re-queued for retry.' );
		$this->assertSame( array(), $this->repository->get( 'post', $post->ID, self::PROVIDER, self::MODEL ) );
	}

	/**
	 * Tests that the batch size filter bounds how many posts are processed per run.
	 *
	 * @since x.x.x
	 */
	public function test_process_queue_respects_batch_size_filter(): void {
		$this->configure_provider_model();

		add_filter(
			'wpai_embedding_sync_batch_size',
			static function () {
				return 1;
			}
		);

		$posts = self::factory()->post->create_many(
			2,
			array(
				'post_status'  => 'publish',
				'post_content' => 'Some content.',
			)
		);

		foreach ( $posts as $post_id ) {
			$post = get_post( $post_id );
			$this->manager->maybe_queue_post( $post_id, $post, true );
		}

		$this->manager->process_queue();

		$this->assertSame( 1, $this->manager->generate_calls );
		$this->assertCount( 1, $this->get_queue_option(), 'Exactly one post should remain queued for the next run.' );
	}
}
