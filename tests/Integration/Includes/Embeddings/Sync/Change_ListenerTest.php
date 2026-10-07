<?php
/**
 * Tests for change detection.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Embeddings\Sync
 */

namespace WordPress\AI\Tests\Integration\Includes\Embeddings\Sync;

use WP_UnitTestCase;
use WordPress\AI\Embeddings\Embedding_Record;
use WordPress\AI\Embeddings\Embedding_Repository;
use WordPress\AI\Embeddings\Sync\Change_Listener;
use WordPress\AI\Embeddings\Sync\Consumer_Registry;
use WordPress\AI\Embeddings\Sync\Post_Source;
use WordPress\AI\Embeddings\Sync\Sync_Queue;
use WordPress\AI\Embeddings\Sync\Sync_Worker;
use WordPress\AI\Embeddings\Sync\Term_Source;

/**
 * Change_Listener test case.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Embeddings\Sync\Change_Listener
 * @covers \WordPress\AI\Embeddings\Sync\Post_Source
 * @covers \WordPress\AI\Embeddings\Sync\Term_Source
 */
class Change_ListenerTest extends WP_UnitTestCase {

	use Captures_Warnings_Trait;
	use Sync_Tables_Trait;

	/**
	 * Queue.
	 *
	 * @var \WordPress\AI\Embeddings\Sync\Sync_Queue
	 */
	private Sync_Queue $queue;

	/**
	 * Repository.
	 *
	 * @var \WordPress\AI\Embeddings\Embedding_Repository
	 */
	private Embedding_Repository $repository;

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
	 * Wires a listener covering posts and tags; pages and categories are uncovered.
	 *
	 * @since x.x.x
	 */
	protected function setUp(): void {
		parent::setUp();

		$registry = new Consumer_Registry();
		$registry->register(
			'test',
			array(
				'provider' => 'openai',
				'model'    => 'text-embedding-3-small',
				'objects'  => array(
					'post' => array( 'post' ),
					'term' => array( 'post_tag' ),
				),
			)
		);

		$this->queue      = new Sync_Queue();
		$this->repository = new Embedding_Repository();
		$sources          = array(
			'post' => new Post_Source(),
			'term' => new Term_Source(),
		);
		$listener         = new Change_Listener( $registry, $sources, $this->queue, $this->repository );

		foreach ( $sources as $source ) {
			$source->register_hooks( $listener );
		}
	}

	/**
	 * Returns the queued object IDs of a type.
	 *
	 * @since x.x.x
	 *
	 * @param string $object_type Object type.
	 * @return list<int> Object IDs.
	 */
	private function queued( string $object_type = 'post' ): array {
		$ids = array();

		foreach ( $this->queue->claim_due( 100 ) as $item ) {
			if ( $item->get_object_type() !== $object_type ) {
				continue;
			}

			$ids[] = $item->get_object_id();
		}

		return $ids;
	}

	/**
	 * Stores a vector for an object, as a previous sync would have.
	 *
	 * @since x.x.x
	 *
	 * @param int    $object_id   Object ID.
	 * @param string $object_type Object type.
	 */
	private function seed_vector( int $object_id, string $object_type = 'post' ): void {
		$this->repository->save( new Embedding_Record( $object_type, $object_id, 'openai', 'text-embedding-3-small', array( 0.1, 0.2 ), 0, 'h' ) );
	}

	/**
	 * Returns whether an object has any stored vector.
	 *
	 * @since x.x.x
	 *
	 * @param int    $object_id   Object ID.
	 * @param string $object_type Object type.
	 * @return bool True when vectors exist.
	 */
	private function has_vectors( int $object_id, string $object_type = 'post' ): bool {
		return array() !== $this->repository->get( $object_type, $object_id, 'openai', 'text-embedding-3-small' );
	}

	/**
	 * Tests that publishing queues and wakes the worker, repeated saves keep one row, and drafts are not queued.
	 *
	 * @since x.x.x
	 */
	public function test_publishing_queues_once_and_wakes_the_worker(): void {
		self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$post_id = self::factory()->post->create();

		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Edited',
			)
		);
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Edited again',
			)
		);

		$this->assertSame( array( $post_id ), $this->queued() );
		$this->assertNotFalse( wp_next_scheduled( Sync_Worker::CRON_HOOK ) );
	}

	/**
	 * Tests the "covered but not indexable ⇒ delete" rule for every way out of publish, and the delete cascade.
	 *
	 * @since x.x.x
	 *
	 * @dataProvider data_ways_out_of_publish
	 *
	 * @param callable(int): void $leave     Moves the post out of the indexable set.
	 * @param string              $post_type Optional. Post type to create. Default 'post'.
	 */
	public function test_leaving_publish_deletes_vectors_and_dequeues( callable $leave, string $post_type = 'post' ): void {
		$post_id = self::factory()->post->create( array( 'post_type' => $post_type ) );
		$this->seed_vector( $post_id );

		$leave( $post_id );

		$this->assertFalse( $this->has_vectors( $post_id ) );
		$this->assertNotContains( $post_id, $this->queued() );
	}

	/**
	 * Data provider for the ways a post stops being indexable.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{0: callable(int): void, 1?: string}> Cases.
	 */
	public function data_ways_out_of_publish(): array {
		return array(
			'unpublish'                   => array(
				static function ( int $id ): void {
					wp_update_post(
						array(
							'ID'          => $id,
							'post_status' => 'draft',
						)
					);
				},
			),
			'private'                     => array(
				static function ( int $id ): void {
					wp_update_post(
						array(
							'ID'          => $id,
							'post_status' => 'private',
						)
					);
				},
			),
			'trash'                       => array(
				static function ( int $id ): void {
					wp_trash_post( $id );
				},
			),
			'password'                    => array(
				static function ( int $id ): void {
					wp_update_post(
						array(
							'ID'            => $id,
							'post_password' => 'secret',
						)
					);
				},
			),
			'force delete'                => array(
				static function ( int $id ): void {
					wp_delete_post( $id, true );
				},
			),
			// Deleting an uncovered object still cascades: the object is gone.
			'force delete uncovered type' => array(
				static function ( int $id ): void {
					wp_delete_post( $id, true );
				},
				'page',
			),
		);
	}

	/**
	 * Tests that an uncovered post type is ignored and its vectors are left alone.
	 *
	 * @since x.x.x
	 */
	public function test_uncovered_post_type_is_ignored(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
			)
		);
		$this->seed_vector( $page_id );

		wp_update_post(
			array(
				'ID'          => $page_id,
				'post_status' => 'publish',
			)
		);

		$this->assertSame( array(), $this->queued() );
		$this->assertTrue( $this->has_vectors( $page_id ) );
	}

	/**
	 * Review focus 3: changing a covered post into an uncovered type removes its vectors.
	 *
	 * @since x.x.x
	 */
	public function test_post_moved_to_uncovered_type_loses_its_vectors(): void {
		$post_id = self::factory()->post->create();
		$this->seed_vector( $post_id );

		wp_update_post(
			array(
				'ID'        => $post_id,
				'post_type' => 'page',
			)
		);

		$this->assertFalse( $this->has_vectors( $post_id ) );
		$this->assertNotContains( $post_id, $this->queued() );
	}

	/**
	 * Review focus 1: a scheduled post published by cron is queued.
	 *
	 * @since x.x.x
	 */
	public function test_scheduled_post_published_by_cron_is_queued(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'future',
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			)
		);

		$this->assertSame( array(), $this->queued() );

		wp_publish_post( $post_id );

		$this->assertSame( array( $post_id ), $this->queued() );
	}

	/**
	 * Review focus 2: untrashing restores a draft, which stays unindexed until republished.
	 *
	 * @since x.x.x
	 */
	public function test_untrashed_post_is_not_queued_until_republished(): void {
		$post_id = self::factory()->post->create();
		wp_trash_post( $post_id );
		$this->queue->remove_object( 'post', $post_id );

		wp_untrash_post( $post_id );

		$this->assertSame( array(), $this->queued() );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		$this->assertSame( array( $post_id ), $this->queued() );
	}

	/**
	 * Tests that revisions and autosaves never reach the queue.
	 *
	 * @since x.x.x
	 */
	public function test_revisions_are_ignored(): void {
		$post_id = self::factory()->post->create();
		$this->queue->remove_object( 'post', $post_id );

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'Changed',
			)
		);

		$revisions = wp_get_post_revisions( $post_id );

		$this->assertNotEmpty( $revisions );
		$this->assertSame( array( $post_id ), $this->queued() );
	}

	/**
	 * Tests term create, edit and delete.
	 *
	 * @since x.x.x
	 */
	public function test_term_lifecycle(): void {
		$tag_id = self::factory()->tag->create( array( 'name' => 'Cats' ) );
		$cat_id = self::factory()->category->create( array( 'name' => 'News' ) );

		wp_update_term( $tag_id, 'post_tag', array( 'description' => 'Felines' ) );

		$this->assertSame( array( $tag_id ), $this->queued( 'term' ) );
		$this->assertNotContains( $cat_id, $this->queued( 'term' ) );

		$this->seed_vector( $tag_id, 'term' );
		wp_delete_term( $tag_id, 'post_tag' );

		$this->assertFalse( $this->has_vectors( $tag_id, 'term' ) );
		$this->assertSame( array(), $this->queued( 'term' ) );
	}

	/**
	 * Tests that a failed queue write is reported and never escapes into the save.
	 *
	 * @since x.x.x
	 */
	public function test_failed_queue_write_does_not_break_the_save(): void {
		global $wpdb;

		$table = $this->queue->get_schema()->get_table_name();
		$break = static function ( string $query ) use ( $table ): string {
			if ( false !== strpos( $query, 'INSERT INTO' ) && false !== strpos( $query, $table ) ) {
				return 'SELECT * FROM wpai_missing_table_for_test';
			}

			return $query;
		};

		add_filter( 'query', $break );
		$suppress = $wpdb->suppress_errors();
		$post_id  = null;

		try {
			$warnings = $this->capture_warnings(
				static function () use ( &$post_id ): void {
					$post_id = wp_insert_post(
						array(
							'post_title'  => 'Queue write fails',
							'post_status' => 'publish',
						),
						true
					);
				}
			);
		} finally {
			$wpdb->suppress_errors( $suppress );
			remove_filter( 'query', $break );
		}

		$this->assertIsInt( $post_id );
		$this->assertGreaterThan( 0, $post_id );
		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$this->assertNotEmpty( $warnings );
		$this->assertSame( E_USER_WARNING, $warnings[0][2] );
		$this->assertStringContainsString( 'Failed to queue object for embedding', $warnings[0][1] );
		$this->assertNotContains( $post_id, $this->queued() );
	}

	/**
	 * Tests that the skip filter skips enqueues but never prevents a delete.
	 *
	 * @since x.x.x
	 */
	public function test_skip_enqueue_filter_never_skips_deletes(): void {
		$post_id = self::factory()->post->create();
		$this->seed_vector( $post_id );

		add_filter( 'wpai_embedding_sync_skip_enqueue', '__return_true' );

		$skipped = self::factory()->post->create();

		$this->assertNotContains( $skipped, $this->queued() );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			)
		);

		$this->assertFalse( $this->has_vectors( $post_id ) );
	}

	/**
	 * Tests that changes made while switched to another site are ignored.
	 *
	 * The other site's own requests handle its content; queueing it here would create that
	 * site's queue table and leave rows nothing on it processes.
	 *
	 * @since x.x.x
	 */
	public function test_changes_on_a_switched_site_are_ignored(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires a multisite installation.' );
		}

		$seen = 0;

		// Count the listener reaching the enqueue decision, and skip the write so a failing run
		// creates no queue table on the other site.
		add_filter(
			'wpai_embedding_sync_skip_enqueue',
			static function () use ( &$seen ): bool {
				++$seen;
				return true;
			}
		);

		$blog_id = self::factory()->blog->create();

		switch_to_blog( $blog_id );
		$post_id = self::factory()->post->create();
		wp_delete_post( $post_id, true );
		restore_current_blog();

		$this->assertSame( 0, $seen, 'The listener must ignore a save on another site.' );
		$this->assertSame( array(), $this->queued() );
	}
}
