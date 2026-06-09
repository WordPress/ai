<?php
/**
 * Integration tests for RAG search across index backends.
 *
 * @package WordPress\AI\Tests\Integration\Includes\RAG
 */

namespace WordPress\AI\Tests\Integration\Includes\RAG;

use WP_UnitTestCase;
use WordPress\AI\RAG\Availability;
use WordPress\AI\RAG\Index_Manager;
use WordPress\AI\RAG\Index_Repository_Interface;
use WordPress\AI\RAG\MariaDB_Index_Repository;
use WordPress\AI\RAG\MariaDB_Index_Schema;
use WordPress\AI\RAG\Memory_Index_Repository;
use WordPress\AI\RAG\OpenAI_Embedding_Client;
use WordPress\AI\RAG\Search_Service;

/**
 * Deterministic embedding client for backend corpus tests.
 *
 * @since 1.1.0
 */
class Backend_Search_Test_Embedding_Client extends OpenAI_Embedding_Client {
	/**
	 * Vector dimensions.
	 *
	 * @var int
	 */
	private int $dimensions;

	/**
	 * Constructor.
	 *
	 * @param int $dimensions Vector dimensions.
	 */
	public function __construct( int $dimensions ) {
		$this->dimensions = $dimensions;
	}

	/**
	 * Embeds text inputs.
	 *
	 * @param list<string> $texts Text inputs.
	 * @return list<list<float>> Embedding vectors.
	 */
	public function embed( array $texts ) {
		return array_map(
			function ( string $text ): array {
				return $this->vector_for_text( $text );
			},
			$texts
		);
	}

	/**
	 * Returns a deterministic vector for text.
	 *
	 * @param string $text Text.
	 * @return list<float> Embedding vector.
	 */
	private function vector_for_text( string $text ): array {
		$tokens = array(
			Backend_SearchTest::TARGET_TOKEN,
			'decoy-alpha',
			'decoy-beta',
			'decoy-gamma',
			'decoy-delta',
		);
		$text   = strtolower( $text );
		$vector = array_fill( 0, $this->dimensions, 0.0 );

		foreach ( $tokens as $index => $token ) {
			if ( false !== strpos( $text, $token ) ) {
				$vector[ $index ] = 1.0;
				return $vector;
			}
		}

		$vector[ count( $tokens ) ] = 1.0;
		return $vector;
	}
}

/**
 * Fixed backend availability service for backend corpus tests.
 *
 * @since 1.1.0
 */
class Backend_Search_Test_Availability extends Availability {
	/**
	 * Backend.
	 *
	 * @var string
	 */
	private string $backend;

	/**
	 * Vector dimensions.
	 *
	 * @var int
	 */
	private int $dimensions;

	/**
	 * Embedding model.
	 *
	 * @var string
	 */
	private string $model;

	/**
	 * Constructor.
	 *
	 * @param string $backend    Backend identifier.
	 * @param int    $dimensions Vector dimensions.
	 * @param string $model      Embedding model.
	 */
	public function __construct( string $backend, int $dimensions, string $model ) {
		$this->backend    = $backend;
		$this->dimensions = $dimensions;
		$this->model      = $model;
	}

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
		return $this->backend;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_embedding_model(): string {
		return $this->model;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_embedding_dimensions(): int {
		return $this->dimensions;
	}

	/**
	 * {@inheritDoc}
	 */
	public function ensure_index_storage(): bool {
		return true;
	}
}

/**
 * Backend search test case.
 *
 * @since 1.1.0
 */
class Backend_SearchTest extends WP_UnitTestCase {
	/**
	 * Token that should dominate semantic search results.
	 */
	public const TARGET_TOKEN = 'needle-rag-topic';

	/**
	 * Test embedding model.
	 */
	private const MODEL = 'test-embedding-backend-corpus';

	/**
	 * Total seeded posts.
	 */
	private const POST_COUNT = 80;

	/**
	 * Target post count.
	 */
	private const TARGET_COUNT = 7;

	/**
	 * Set up test case.
	 */
	public function setUp(): void {
		parent::setUp();

		delete_transient( 'wpai_rag_indexing_lock' );
	}

	/**
	 * Cleans up scheduled RAG events.
	 */
	public function tearDown(): void {
		delete_transient( 'wpai_rag_indexing_lock' );
		wp_clear_scheduled_hook( Index_Manager::CRON_HOOK );

		parent::tearDown();
	}

	/**
	 * Tests indexing and semantic search over a larger corpus.
	 *
	 * @dataProvider data_backends
	 *
	 * @since 1.1.0
	 *
	 * @param string $backend    Backend identifier.
	 * @param int    $dimensions Vector dimensions.
	 */
	public function test_seed_many_posts_and_search_across_backends( string $backend, int $dimensions ): void {
		$repository   = $this->create_repository( $backend, $dimensions );
		$availability = new Backend_Search_Test_Availability( $backend, $dimensions, self::MODEL );
		$client       = new Backend_Search_Test_Embedding_Client( $dimensions );
		$manager      = new Index_Manager( $availability, null, $repository, null, $client );

		$this->assertTrue( $manager->ensure_index_storage() );

		$target_ids = $this->seed_posts_and_mark_dirty( $manager );
		$stats      = $manager->run_indexing_batch( self::POST_COUNT, false );

		$this->assertSame( self::POST_COUNT, $stats['processed'] );
		$this->assertSame( self::POST_COUNT, $stats['clean'] );
		$this->assertSame( 0, $stats['error'] );

		$search  = new Search_Service( $availability, $repository, $client );
		$results = $search->search(
			'Find the ' . self::TARGET_TOKEN . ' posts',
			array(
				'per_page'                => 5,
				'candidate_limit'         => 20,
				'enforce_read_permission' => false,
			)
		);

		$this->assertFalse( is_wp_error( $results ) );
		$this->assertIsArray( $results );
		$this->assertCount( 5, $results['results'] );

		$result_ids = array_map(
			static function ( array $result ): int {
				return (int) $result['post_id'];
			},
			$results['results']
		);

		foreach ( $result_ids as $post_id ) {
			$this->assertContains( $post_id, $target_ids );
		}
	}

	/**
	 * Backend data provider.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string, array{string, int}>
	 */
	public function data_backends(): array {
		return array(
			'memory'  => array( Availability::BACKEND_MEMORY, 16 ),
			'mariadb' => array( Availability::BACKEND_MARIADB, 1536 ),
		);
	}

	/**
	 * Creates the repository under test.
	 *
	 * @since 1.1.0
	 *
	 * @param string $backend    Backend identifier.
	 * @param int    $dimensions Vector dimensions.
	 * @return \WordPress\AI\RAG\Index_Repository_Interface Repository.
	 */
	private function create_repository( string $backend, int $dimensions ): Index_Repository_Interface {
		if ( Availability::BACKEND_MEMORY === $backend ) {
			return new Memory_Index_Repository( $dimensions );
		}

		$availability = new Availability();
		if ( ! $availability->is_mariadb_vector_index_supported() ) {
			$this->markTestSkipped( 'MariaDB VECTOR indexes are not available in this test environment.' );
		}

		return new MariaDB_Index_Repository( new MariaDB_Index_Schema(), $dimensions );
	}

	/**
	 * Seeds posts and marks them dirty for indexing.
	 *
	 * @since 1.1.0
	 *
	 * @param \WordPress\AI\RAG\Index_Manager $manager Index manager.
	 * @return list<int> Target post IDs.
	 */
	private function seed_posts_and_mark_dirty( Index_Manager $manager ): array {
		$target_ids = array();
		$decoys     = array( 'decoy-alpha', 'decoy-beta', 'decoy-gamma', 'decoy-delta' );

		for ( $i = 0; $i < self::POST_COUNT; ++$i ) {
			$is_target = $i > 0 && 0 === $i % 11 && count( $target_ids ) < self::TARGET_COUNT;
			$token     = $is_target ? self::TARGET_TOKEN : $decoys[ $i % count( $decoys ) ];
			$post_id   = self::factory()->post->create(
				array(
					'post_title'   => sprintf( 'Backend corpus article %02d', $i ),
					'post_content' => sprintf( '<p>Seeded backend corpus content %02d about %s.</p>', $i, $token ),
					'post_status'  => 'publish',
				)
			);

			if ( $is_target ) {
				$target_ids[] = $post_id;
			}

			$manager->handle_save_post( $post_id, get_post( $post_id ), true );
		}

		$this->assertCount( self::TARGET_COUNT, $target_ids );

		return $target_ids;
	}
}
