<?php
/**
 * Tests for the post and term sources.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Embeddings\Sync
 */

namespace WordPress\AI\Tests\Integration\Includes\Embeddings\Sync;

use WP_UnitTestCase;
use WordPress\AI\Embeddings\Sync\Post_Source;
use WordPress\AI\Embeddings\Sync\Term_Source;

/**
 * Post_Source and Term_Source test case.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Embeddings\Sync\Post_Source
 * @covers \WordPress\AI\Embeddings\Sync\Term_Source
 */
class SourcesTest extends WP_UnitTestCase {

	/**
	 * Tests post indexability across statuses, passwords and the filter.
	 *
	 * @since x.x.x
	 */
	public function test_post_indexability(): void {
		$source    = new Post_Source();
		$published = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$draft     = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$protected = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_password' => 'secret',
			)
		);

		$this->assertTrue( $source->is_indexable( $published ) );
		$this->assertFalse( $source->is_indexable( $draft ) );
		$this->assertFalse( $source->is_indexable( $protected ) );
		$this->assertFalse( $source->is_indexable( 999999 ) );

		add_filter( 'wpai_embedding_sync_indexable_post_statuses', static fn(): array => array( 'publish', 'draft' ) );
		$this->assertTrue( $source->is_indexable( $draft ) );

		add_filter( 'wpai_embedding_sync_is_indexable', '__return_false' );
		$this->assertFalse( $source->is_indexable( $published ) );
	}

	/**
	 * Tests the post text representation and its filter.
	 *
	 * @since x.x.x
	 */
	public function test_post_text_is_title_and_normalized_content(): void {
		$source  = new Post_Source();
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Tom &amp; Jerry',
				'post_content' => '<!-- wp:paragraph --><p>Cat <strong>and</strong> mouse.</p><!-- /wp:paragraph -->',
			)
		);

		$this->assertSame( "Tom & Jerry\n\nCat and mouse.", $source->get_text( $post_id ) );

		add_filter( 'wpai_embedding_sync_post_text', static fn( string $text ): string => $text . ' Extra.' );

		$this->assertStringEndsWith( 'Extra.', $source->get_text( $post_id ) );
	}

	/**
	 * Tests keyset paging over indexable posts of the given types.
	 *
	 * @since x.x.x
	 */
	public function test_post_ids_after_pages_indexable_posts_in_id_order(): void {
		$source = new Post_Source();
		$a      = self::factory()->post->create();
		$page   = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$b      = self::factory()->post->create();
		self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$c = self::factory()->post->create();

		// Cursor just below this test's first post, so fixtures left by other classes cannot interfere.
		$cursor = $a - 1;

		$this->assertSame( array( $a, $b ), $source->get_ids_after( $cursor, array( 'post' ), 2 ) );
		$this->assertSame( array( $c ), $source->get_ids_after( $b, array( 'post' ), 2 ) );
		$this->assertSame( array( $a, $page, $b, $c ), $source->get_ids_after( $cursor, array( 'post', 'page' ), 10 ) );
		$this->assertSame( array(), $source->get_ids_after( $cursor, array(), 10 ) );
	}

	/**
	 * Tests indexable counts per post type.
	 *
	 * @since x.x.x
	 */
	public function test_post_count_indexable(): void {
		$source = new Post_Source();
		$before = $source->count_indexable( array( 'post', 'page' ) );

		self::factory()->post->create_many( 2 );
		self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$after = $source->count_indexable( array( 'post', 'page' ) );

		$this->assertSame( 2, $after['post'] - $before['post'] );
		$this->assertSame( 0, $after['page'] - $before['page'] );
	}

	/**
	 * Tests the term source end to end.
	 *
	 * @since x.x.x
	 */
	public function test_term_source(): void {
		$source = new Term_Source();
		$tag    = self::factory()->tag->create(
			array(
				'name'        => 'Cats',
				'description' => '<p>Feline friends</p>',
			)
		);
		$cat    = self::factory()->category->create( array( 'name' => 'News' ) );

		$this->assertSame( 'post_tag', $source->get_subtype( $tag ) );
		$this->assertNull( $source->get_subtype( 999999 ) );
		$this->assertTrue( $source->is_indexable( $tag ) );
		$this->assertSame( "Cats\n\nFeline friends", $source->get_text( $tag ) );
		$this->assertSame( array( $tag ), $source->get_ids_after( $tag - 1, array( 'post_tag' ), 10 ) );
		$this->assertContains( $cat, $source->get_ids_after( $cat - 1, array( 'category' ), 10 ) );
		$this->assertGreaterThanOrEqual( 1, $source->count_indexable( array( 'post_tag' ) )['post_tag'] );
		$this->assertTrue( $source->subtype_exists( 'post_tag' ) );
		$this->assertFalse( $source->subtype_exists( 'nope' ) );
	}

	/**
	 * Tests that priming loads posts into the object cache, so later reads cost no query.
	 *
	 * @since x.x.x
	 */
	public function test_post_prime_loads_the_cache(): void {
		global $wpdb;

		$ids = self::factory()->post->create_many( 3 );
		wp_cache_flush_runtime();

		( new Post_Source() )->prime( $ids );

		$before = $wpdb->num_queries;

		foreach ( $ids as $id ) {
			( new Post_Source() )->get_text( $id );
		}

		$this->assertSame( $before, $wpdb->num_queries );
	}

	/**
	 * Tests that priming loads terms into the object cache.
	 *
	 * @since x.x.x
	 */
	public function test_term_prime_loads_the_cache(): void {
		global $wpdb;

		$ids = self::factory()->tag->create_many( 3 );
		wp_cache_flush_runtime();

		( new Term_Source() )->prime( $ids );

		$before = $wpdb->num_queries;

		foreach ( $ids as $id ) {
			( new Term_Source() )->get_text( $id );
		}

		$this->assertSame( $before, $wpdb->num_queries );
	}
}
