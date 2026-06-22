<?php
/**
 * Integration tests for search page RAG augmentation.
 *
 * @package WordPress\AI\Tests\Integration\Includes\RAG
 */

namespace WordPress\AI\Tests\Integration\Includes\RAG;

use WP_Query;
use WP_UnitTestCase;
use WordPress\AI\RAG\Search_Augmenter;
use WordPress\AI\RAG\Search_Service;

/**
 * Fake RAG search service for augmentation tests.
 *
 * @since 1.1.0
 */
class Search_Augmenter_Test_Search_Service extends Search_Service {
	/**
	 * Search results to return.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $results;

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
		unset( $args );

		return array(
			'query'   => $query,
			'model'   => 'text-embedding-3-small',
			'results' => $this->results,
		);
	}
}

/**
 * Search augmenter test case.
 *
 * @since 1.1.0
 */
class Search_AugmenterTest extends WP_UnitTestCase {
	/**
	 * Restores global query and filters.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wpai_rag_search_distance_threshold' );

		parent::tearDown();
	}

	/**
	 * Tests default promotion threshold against a real-world OpenAI distance.
	 *
	 * @since 1.1.0
	 */
	public function test_default_threshold_promotes_clear_semantic_match(): void {
		global $wpdb, $wp_query;

		$this->go_to( '/?s=how+do+I+stop+furniture+from+shaking' );
		$this->assertInstanceOf( WP_Query::class, $wp_query );

		$wp_query->set( 's', 'how do I stop furniture from shaking' );
		$wp_query->is_search = true;
		$wp_query->is_feed   = false;

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Required to exercise main-query-only augmentation.
		$GLOBALS['wp_the_query'] = $wp_query;

		$this->assertTrue( $wp_query->is_main_query() );
		$this->assertTrue( $wp_query->is_search() );

		$augmenter = new Search_Augmenter(
			new Search_Augmenter_Test_Search_Service(
				array(
					array(
						'post_id'  => 123,
						'distance' => 0.53003317313708,
					),
					array(
						'post_id'  => 456,
						'distance' => 0.67827118224785,
					),
				)
			)
		);

		$augmenter->prepare_semantic_candidates( $wp_query );
		$orderby = $augmenter->promote_semantic_results( "{$wpdb->posts}.post_date DESC", $wp_query );

		$this->assertStringContainsString( "CASE WHEN {$wpdb->posts}.ID IN (123)", $orderby );
		$this->assertStringContainsString( "FIELD({$wpdb->posts}.ID, 123)", $orderby );
		$this->assertStringNotContainsString( "FIELD({$wpdb->posts}.ID, 123,456)", $orderby );
	}
}
