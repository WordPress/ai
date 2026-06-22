<?php
/**
 * Integration tests for semantic related posts Query Loop variation.
 *
 * @package WordPress\AI\Tests\Integration\Includes\RAG
 */

namespace WordPress\AI\Tests\Integration\Includes\RAG;

use WP_Query;
use WP_UnitTestCase;
use WordPress\AI\RAG\Related_Posts;
use WordPress\AI\RAG\Search_Service;

/**
 * Fake RAG search service for related posts tests.
 *
 * @since 1.1.0
 */
class Related_Posts_Test_Search_Service extends Search_Service {
	/**
	 * Search results to return.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $results;

	/**
	 * Search calls.
	 *
	 * @var list<array{query: string, args: array<string, mixed>}>
	 */
	public array $calls = array();

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 *
	 * @param list<array<string, mixed>> $results Search results.
	 */
	public function __construct( array $results ) {
		$this->results = $results;
	}

	/**
	 * Returns configured search results.
	 *
	 * @since 1.1.0
	 *
	 * @param string $query Search query.
	 * @param array  $args  Search args.
	 * @return array<string, mixed> Search results.
	 */
	public function search( string $query, array $args = array() ) {
		$this->calls[] = array(
			'query' => $query,
			'args'  => $args,
		);

		return array(
			'query'   => $query,
			'model'   => 'text-embedding-3-small',
			'results' => $this->results,
		);
	}
}

/**
 * Related posts Query Loop variation test case.
 *
 * @since 1.1.0
 */
class Related_PostsTest extends WP_UnitTestCase {
	/**
	 * Renderer under test.
	 *
	 * @var \WordPress\AI\RAG\Related_Posts|null
	 */
	private ?Related_Posts $renderer = null;

	/**
	 * Restores filters and post globals.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wpai_rag_related_posts_cache_ttl' );
		remove_all_filters( 'wpai_rag_related_posts_distance_threshold' );
		remove_all_filters( 'wpai_rag_related_posts_count' );
		remove_all_filters( 'wpai_rag_related_posts_min_results' );
		remove_all_filters( 'wpai_rag_related_posts_candidate_limit' );
		remove_all_filters( 'wpai_rag_related_posts_query_text' );
		remove_all_filters( 'wpai_rag_related_posts_results' );

		if ( $this->renderer instanceof Related_Posts ) {
			remove_filter( 'pre_render_block', array( $this->renderer, 'prepare_related_query' ), 10 );
			remove_filter( 'query_loop_block_query_vars', array( $this->renderer, 'filter_query_vars' ), 10 );
			remove_filter( 'render_block', array( $this->renderer, 'reset_related_query' ), 10 );
		}

		wp_reset_postdata();

		parent::tearDown();
	}

	/**
	 * Tests related Query Loop renders semantic posts in semantic order.
	 *
	 * @since 1.1.0
	 */
	public function test_related_query_loop_renders_semantic_posts_in_order(): void {
		$this->disable_cache();

		$category_id = $this->factory->category->create( array( 'name' => 'Shared Topic' ) );
		$source_id   = $this->create_post( 'Source Article', 'Source content about semantic discovery.', array( $category_id ) );
		$related_ids = array(
			$this->create_post( 'Related One', 'More semantic discovery notes.', array( $category_id ) ),
			$this->create_post( 'Related Two', 'Another semantic discovery article.', array( $category_id ) ),
			$this->create_post( 'Related Three', 'A third semantic discovery article.', array( $category_id ) ),
		);
		$service     = new Related_Posts_Test_Search_Service(
			array_merge(
				array(
					array(
						'post_id'  => $source_id,
						'distance' => 0.01,
					),
				),
				$this->create_results( $related_ids )
			)
		);

		$this->renderer = new Related_Posts( $service );
		$this->renderer->init();
		$this->go_to_post( $source_id );

		$markup = do_blocks( $this->get_related_query_block_markup( 3 ) );

		$this->assertStringContainsString( 'Related One', $markup );
		$this->assertStringContainsString( 'Related Two', $markup );
		$this->assertStringContainsString( 'Related Three', $markup );
		$this->assertStringNotContainsString( 'Source Article', $markup );
		$this->assertLessThan( strpos( $markup, 'Related Two' ), strpos( $markup, 'Related One' ) );
		$this->assertLessThan( strpos( $markup, 'Related Three' ), strpos( $markup, 'Related Two' ) );
		$this->assertStringContainsString( 'Shared Topic', $service->calls[0]['query'] );
		$this->assertSame( 'post', $service->calls[0]['args']['post_type'] );
		$this->assertSame( 'publish', $service->calls[0]['args']['post_status'] );
	}

	/**
	 * Tests related Query Loop suppresses itself when there are too few matches.
	 *
	 * @since 1.1.0
	 */
	public function test_related_query_loop_renders_nothing_when_not_enough_matches(): void {
		$this->disable_cache();

		$source_id   = $this->create_post( 'Source Article', 'Source content.' );
		$related_ids = array(
			$this->create_post( 'Related One', 'Related content one.' ),
			$this->create_post( 'Related Two', 'Related content two.' ),
		);

		$this->renderer = new Related_Posts( new Related_Posts_Test_Search_Service( $this->create_results( $related_ids ) ) );
		$this->renderer->init();
		$this->go_to_post( $source_id );

		$this->assertSame( '', do_blocks( $this->get_related_query_block_markup( 3 ) ) );
	}

	/**
	 * Tests related Query Loop skips distant semantic matches.
	 *
	 * @since 1.1.0
	 */
	public function test_related_query_loop_skips_results_above_distance_threshold(): void {
		$this->disable_cache();

		$source_id = $this->create_post( 'Source Article', 'Source content.' );
		$near_ids  = array(
			$this->create_post( 'Near One', 'Near content one.' ),
			$this->create_post( 'Near Two', 'Near content two.' ),
			$this->create_post( 'Near Three', 'Near content three.' ),
		);
		$far_id    = $this->create_post( 'Far Result', 'Far content.' );
		$results   = array(
			array(
				'post_id'  => $near_ids[0],
				'distance' => 0.2,
			),
			array(
				'post_id'  => $far_id,
				'distance' => 0.9,
			),
			array(
				'post_id'  => $near_ids[1],
				'distance' => 0.3,
			),
			array(
				'post_id'  => $near_ids[2],
				'distance' => 0.4,
			),
		);

		$this->renderer = new Related_Posts( new Related_Posts_Test_Search_Service( $results ) );
		$this->renderer->init();
		$this->go_to_post( $source_id );

		$markup = do_blocks( $this->get_related_query_block_markup( 3 ) );

		$this->assertStringContainsString( 'Near One', $markup );
		$this->assertStringContainsString( 'Near Two', $markup );
		$this->assertStringContainsString( 'Near Three', $markup );
		$this->assertStringNotContainsString( 'Far Result', $markup );
	}

	/**
	 * Tests normal Query Loops are not intercepted.
	 *
	 * @since 1.1.0
	 */
	public function test_plain_query_loop_is_not_suppressed(): void {
		$this->disable_cache();

		$source_id = $this->create_post( 'Source Article', 'Source content.' );
		$this->create_post( 'Ordinary Query Result', 'Ordinary query content.' );

		$this->renderer = new Related_Posts( new Related_Posts_Test_Search_Service( array() ) );
		$this->renderer->init();
		$this->go_to_post( $source_id );

		$markup = do_blocks(
			'<!-- wp:query {"query":{"perPage":1,"postType":"post","inherit":false}} -->' .
			'<div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --></div>' .
			'<!-- /wp:query -->'
		);

		$this->assertStringContainsString( 'Ordinary Query Result', $markup );
	}

	/**
	 * Disables transient caching for a deterministic test.
	 */
	private function disable_cache(): void {
		add_filter(
			'wpai_rag_related_posts_cache_ttl',
			static function (): int {
				return 0;
			}
		);
	}

	/**
	 * Creates a published post.
	 *
	 * @param string    $title        Post title.
	 * @param string    $content      Post content.
	 * @param list<int> $category_ids Category IDs.
	 * @return int Post ID.
	 */
	private function create_post( string $title, string $content, array $category_ids = array() ): int {
		return $this->factory->post->create(
			array(
				'post_title'    => $title,
				'post_content'  => $content,
				'post_status'   => 'publish',
				'post_category' => $category_ids,
			)
		);
	}

	/**
	 * Sets the main query to a single post.
	 *
	 * @param int $post_id Post ID.
	 */
	private function go_to_post( int $post_id ): void {
		$this->go_to( get_permalink( $post_id ) );

		global $wp_query;
		$this->assertInstanceOf( WP_Query::class, $wp_query );
		$wp_query->the_post();
	}

	/**
	 * Returns a serialized Related Posts Query Loop block.
	 *
	 * @param int $per_page Posts per page.
	 * @return string Serialized block markup.
	 */
	private function get_related_query_block_markup( int $per_page ): string {
		return sprintf(
			'<!-- wp:query {"namespace":"%1$s","query":{"perPage":%2$d,"postType":"post","inherit":false}} -->' .
			'<div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --></div>' .
			'<!-- /wp:query -->',
			Related_Posts::QUERY_NAMESPACE,
			$per_page
		);
	}

	/**
	 * Creates fake search result rows.
	 *
	 * @param list<int> $post_ids Post IDs.
	 * @return list<array{post_id: int, distance: float}> Results.
	 */
	private function create_results( array $post_ids ): array {
		return array_map(
			static function ( int $post_id ): array {
				return array(
					'post_id'  => $post_id,
					'distance' => 0.2,
				);
			},
			$post_ids
		);
	}
}
