<?php
/**
 * Integration tests for the RAG index manager.
 *
 * @package WordPress\AI\Tests\Integration\Includes\RAG
 */

namespace WordPress\AI\Tests\Integration\Includes\RAG;

use WP_UnitTestCase;
use WordPress\AI\RAG\Index_Manager;

/**
 * Index manager test case.
 *
 * @since 1.1.0
 */
class Index_ManagerTest extends WP_UnitTestCase {
	/**
	 * Cleans up filters and post types.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wpai_rag_indexing_scope' );

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
}
