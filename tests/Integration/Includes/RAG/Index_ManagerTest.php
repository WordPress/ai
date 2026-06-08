<?php
/**
 * Integration tests for the RAG index manager.
 *
 * @package WordPress\AI\Tests\Integration\Includes\RAG
 */

namespace WordPress\AI\Tests\Integration\Includes\RAG;

use WP_UnitTestCase;
use WordPress\AI\RAG\Availability;
use WordPress\AI\RAG\Index_Manager;
use WordPress\AI\RAG\Memory_Index_Repository;
use WordPress\AI\RAG\OpenAI_Embedding_Client;

/**
 * Index manager test case.
 *
 * @since 1.1.0
 */
class Index_ManagerTest extends WP_UnitTestCase {
	/**
	 * Set up test case.
	 */
	public function setUp(): void {
		parent::setUp();

		delete_transient( 'wpai_rag_indexing_lock' );
	}

	/**
	 * Cleans up filters and post types.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wpai_rag_indexing_scope' );
		delete_transient( 'wpai_rag_indexing_lock' );
		wp_clear_scheduled_hook( Index_Manager::CRON_HOOK );

		if ( post_type_exists( 'book' ) ) {
			unregister_post_type( 'book' );
		}

		parent::tearDown();
	}

	/**
	 * Tests default indexing scope.
	 *
	 * @since 1.1.0
	 */
	public function test_get_indexing_scope_defaults_to_published_posts_and_pages(): void {
		$manager = new Index_Manager();

		$this->assertSame(
			array(
				'post' => array( 'publish' ),
				'page' => array( 'publish' ),
			),
			$manager->get_indexing_scope()
		);
	}

	/**
	 * Tests scope filter.
	 *
	 * @since 1.1.0
	 */
	public function test_get_indexing_scope_can_include_custom_post_types_and_statuses(): void {
		register_post_type( 'book', array( 'public' => true ) );

		add_filter(
			'wpai_rag_indexing_scope',
			static function () {
				return array(
					'book' => array( 'draft', 'publish' ),
				);
			}
		);

		$manager = new Index_Manager();

		$this->assertSame(
			array(
				'book' => array( 'draft', 'publish' ),
			),
			$manager->get_indexing_scope()
		);
	}

	/**
	 * Tests post eligibility.
	 *
	 * @since 1.1.0
	 */
	public function test_should_index_post_uses_scope(): void {
		$manager = new Index_Manager();
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$draft   = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$this->assertTrue( $manager->should_index_post( get_post( $post_id ) ) );
		$this->assertFalse( $manager->should_index_post( get_post( $draft ) ) );
	}

	/**
	 * Tests indexing through the compact memory repository.
	 *
	 * @since 1.1.0
	 */
	public function test_run_indexing_batch_can_use_memory_repository(): void {
		$repository = new Memory_Index_Repository( 3 );
		$manager    = new Index_Manager(
			$this->create_memory_availability(),
			null,
			$repository,
			null,
			new class() extends OpenAI_Embedding_Client {
				/**
				 * Constructor.
				 */
				public function __construct() {}

				/**
				 * {@inheritDoc}
				 */
				public function embed( array $texts ) {
					return array_map(
						static function ( string $text ) {
							unset( $text );

							return array( 1.0, 0.0, 0.0 );
						},
						$texts
					);
				}
			}
		);
		$post_id    = self::factory()->post->create(
			array(
				'post_title'   => 'Memory Search',
				'post_content' => '<p>This post should be indexed with compact memory vectors.</p>',
				'post_status'  => 'publish',
			)
		);

		$manager->handle_save_post( $post_id, get_post( $post_id ), true );
		$stats = $manager->run_indexing_batch( 1, false );

		$this->assertSame( 1, $stats['processed'] );
		$this->assertSame( 1, $stats['clean'] );
		$this->assertSame( Index_Manager::STATUS_CLEAN, get_post_meta( $post_id, Index_Manager::META_STATUS, true ) );
		$this->assertNotEmpty( get_post_meta( $post_id, Memory_Index_Repository::META_CHUNKS, true ) );

		$rows = $repository->search(
			array( 1.0, 0.0, 0.0 ),
			array(
				'limit' => 1,
				'model' => 'test-embedding',
			)
		);

		$this->assertCount( 1, $rows );
		$this->assertSame( $post_id, (int) $rows[0]['post_id'] );
	}

	/**
	 * Creates a memory-backend availability service.
	 *
	 * @since 1.1.0
	 *
	 * @return \WordPress\AI\RAG\Availability Availability service.
	 */
	private function create_memory_availability(): Availability {
		return new class() extends Availability {
			/**
			 * {@inheritDoc}
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * {@inheritDoc}
			 */
			public function get_index_backend(): string {
				return Availability::BACKEND_MEMORY;
			}

			/**
			 * {@inheritDoc}
			 */
			public function get_embedding_model(): string {
				return 'test-embedding';
			}

			/**
			 * {@inheritDoc}
			 */
			public function get_embedding_dimensions(): int {
				return 3;
			}
		};
	}
}
