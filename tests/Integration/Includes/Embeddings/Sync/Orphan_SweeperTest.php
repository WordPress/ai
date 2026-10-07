<?php
/**
 * Tests for the orphan sweeper.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Embeddings\Sync
 */

namespace WordPress\AI\Tests\Integration\Includes\Embeddings\Sync;

use WP_UnitTestCase;
use WordPress\AI\Embeddings\Embedding_Record;
use WordPress\AI\Embeddings\Embedding_Repository;
use WordPress\AI\Embeddings\Sync\Consumer_Registry;
use WordPress\AI\Embeddings\Sync\Embedding_Target;
use WordPress\AI\Embeddings\Sync\Orphan_Sweeper;
use WordPress\AI\Embeddings\Sync\Post_Source;
use WordPress\AI\Embeddings\Sync\Term_Source;

/**
 * Orphan_Sweeper test case.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Embeddings\Sync\Orphan_Sweeper
 */
class Orphan_SweeperTest extends WP_UnitTestCase {

	use Sync_Tables_Trait;

	private const PROVIDER = 'openai';
	private const MODEL    = 'text-embedding-3-small';
	private const OTHER    = 'other-model';

	/**
	 * Repository.
	 *
	 * @var \WordPress\AI\Embeddings\Embedding_Repository
	 */
	private Embedding_Repository $repository;

	/**
	 * Sweeper under test.
	 *
	 * @var \WordPress\AI\Embeddings\Sync\Orphan_Sweeper
	 */
	private Orphan_Sweeper $sweeper;

	/**
	 * Target covering post `post`.
	 *
	 * @var \WordPress\AI\Embeddings\Sync\Embedding_Target
	 */
	private Embedding_Target $target;

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

		$registry = new Consumer_Registry();
		$registry->register(
			'test',
			array(
				'provider' => self::PROVIDER,
				'model'    => self::MODEL,
				'objects'  => array( 'post' => array( 'post' ) ),
			)
		);

		$this->repository = new Embedding_Repository();
		$this->target     = $registry->get_target_for_consumer( 'test' );
		$this->sweeper    = new Orphan_Sweeper(
			array(
				'post' => new Post_Source(),
				'term' => new Term_Source(),
			),
			$this->repository,
			$registry
		);
	}

	/**
	 * Stores a one-chunk vector for an object.
	 *
	 * @since x.x.x
	 *
	 * @param int    $id      Object ID.
	 * @param string $model   Optional. Model. Default the target's model.
	 * @param string $type    Optional. Object type. Default 'post'.
	 * @param string $subtype Optional. Object subtype. Default 'post'.
	 */
	private function seed( int $id, string $model = self::MODEL, string $type = 'post', string $subtype = 'post' ): void {
		$this->repository->save( new Embedding_Record( $type, $id, self::PROVIDER, $model, array( 0.1, 0.2, 0.3 ), 0, 'hash', 0, $subtype ) );
	}

	/**
	 * Tests that covered, indexable objects keep their vectors.
	 *
	 * @since x.x.x
	 */
	public function test_sweep_keeps_covered_indexable_objects(): void {
		$post_id = self::factory()->post->create();
		$this->seed( $post_id );

		$page = $this->sweeper->sweep( $this->target, 'post', 0, 10 );

		$this->assertSame( 0, $page['removed'] );
		$this->assertSame( 1, $page['checked'] );
		$this->assertSame( $post_id, $page['last_id'] );
		$this->assertCount( 1, $this->repository->get( 'post', $post_id, self::PROVIDER, self::MODEL ) );
	}

	/**
	 * Tests that a non-indexable object of a covered subtype loses its vectors for every model.
	 *
	 * @since x.x.x
	 */
	public function test_sweep_deletes_covered_draft_for_every_model(): void {
		$draft_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$this->seed( $draft_id );
		$this->seed( $draft_id, self::OTHER );

		$page = $this->sweeper->sweep( $this->target, 'post', 0, 10 );

		$this->assertSame( 1, $page['removed'] );
		$this->assertSame( array(), $this->repository->get( 'post', $draft_id, self::PROVIDER, self::MODEL ) );
		$this->assertSame( array(), $this->repository->get( 'post', $draft_id, self::PROVIDER, self::OTHER ) );
	}

	/**
	 * Tests that a missing object loses only this target's vectors.
	 *
	 * Without a subtype no registered target can be shown to cover it, so other models' rows are
	 * left to their own targets' sweeps.
	 *
	 * @since x.x.x
	 */
	public function test_sweep_deletes_missing_object_for_this_model_only(): void {
		$missing_id = 999999;
		$this->seed( $missing_id );
		$this->seed( $missing_id, self::OTHER );

		$page = $this->sweeper->sweep( $this->target, 'post', 0, 10 );

		$this->assertSame( 1, $page['removed'] );
		$this->assertSame( array(), $this->repository->get( 'post', $missing_id, self::PROVIDER, self::MODEL ) );
		$this->assertCount( 1, $this->repository->get( 'post', $missing_id, self::PROVIDER, self::OTHER ) );
	}

	/**
	 * Tests that a non-indexable object of a subtype no target covers loses only this target's vectors.
	 *
	 * @since x.x.x
	 */
	public function test_sweep_deletes_uncovered_draft_for_this_model_only(): void {
		$draft_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
			)
		);
		$this->seed( $draft_id, self::MODEL, 'post', 'page' );
		$this->seed( $draft_id, self::OTHER, 'post', 'page' );

		$page = $this->sweeper->sweep( $this->target, 'post', 0, 10 );

		$this->assertSame( 1, $page['removed'] );
		$this->assertSame( array(), $this->repository->get( 'post', $draft_id, self::PROVIDER, self::MODEL ) );
		$this->assertCount( 1, $this->repository->get( 'post', $draft_id, self::PROVIDER, self::OTHER ) );
	}

	/**
	 * Tests that a draft kept indexable by the filter keeps its vectors.
	 *
	 * @since x.x.x
	 */
	public function test_sweep_keeps_draft_made_indexable_by_filter(): void {
		$draft_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$this->seed( $draft_id );
		add_filter( 'wpai_embedding_sync_is_indexable', '__return_true' );

		$page = $this->sweeper->sweep( $this->target, 'post', 0, 10 );

		$this->assertSame( 0, $page['removed'] );
		$this->assertCount( 1, $this->repository->get( 'post', $draft_id, self::PROVIDER, self::MODEL ) );
	}

	/**
	 * Tests that an indexable object of an uncovered subtype loses only this target's vectors.
	 *
	 * @since x.x.x
	 */
	public function test_sweep_deletes_uncovered_subtype_for_this_model_only(): void {
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->seed( $page_id, self::MODEL, 'post', 'page' );
		$this->seed( $page_id, self::OTHER, 'post', 'page' );

		$page = $this->sweeper->sweep( $this->target, 'post', 0, 10 );

		$this->assertSame( 1, $page['removed'] );
		$this->assertSame( array(), $this->repository->get( 'post', $page_id, self::PROVIDER, self::MODEL ) );
		$this->assertCount( 1, $this->repository->get( 'post', $page_id, self::PROVIDER, self::OTHER ) );
	}

	/**
	 * Tests that the sweep pages through stored objects by cursor.
	 *
	 * @since x.x.x
	 */
	public function test_sweep_pages_by_cursor(): void {
		$ids = self::factory()->post->create_many( 3 );
		sort( $ids );

		foreach ( $ids as $id ) {
			$this->seed( $id );
		}

		$cursor = min( $ids ) - 1;
		$first  = $this->sweeper->sweep( $this->target, 'post', $cursor, 2 );

		$this->assertSame( $ids[1], $first['last_id'] );
		$this->assertSame( 2, $first['checked'] );

		$second = $this->sweeper->sweep( $this->target, 'post', $first['last_id'], 2 );

		$this->assertSame( $ids[2], $second['last_id'] );
		$this->assertSame( 1, $second['checked'] );

		$this->assertSame(
			array(
				'last_id' => null,
				'checked' => 0,
				'removed' => 0,
			),
			$this->sweeper->sweep( $this->target, 'post', $second['last_id'], 2 )
		);
	}

	/**
	 * Tests that a type without a source sweeps nothing.
	 *
	 * @since x.x.x
	 */
	public function test_sweep_of_an_unknown_type_is_empty(): void {
		$this->seed( 5, self::MODEL, 'comment', 'comment' );

		$this->assertNull( $this->sweeper->sweep( $this->target, 'comment', 0, 10 )['last_id'] );
		$this->assertCount( 1, $this->repository->get( 'comment', 5, self::PROVIDER, self::MODEL ) );
	}
}
