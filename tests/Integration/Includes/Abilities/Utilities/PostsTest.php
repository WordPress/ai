<?php
/**
 * Integration tests for the Posts utility ability data methods.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Utilities
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Utilities;

use WP_Error;
use WP_UnitTestCase;
use WordPress\AI\Abilities\Utilities\Posts;

/**
 * Posts data-method test case.
 *
 * Exercises the shared `get_post_details()` / `get_post_terms()` methods that
 * back both the abilities and internal context building, independent of ability
 * registration.
 *
 * @covers \WordPress\AI\Abilities\Utilities\Posts
 *
 * @since x.x.x
 */
class PostsTest extends WP_UnitTestCase {

	/**
	 * Tests that get_post_details() returns an error for a missing post.
	 *
	 * @since x.x.x
	 */
	public function test_get_post_details_returns_error_for_missing_post(): void {
		$result = Posts::get_post_details( 999999 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'post_not_found', $result->get_error_code() );
	}

	/**
	 * Tests that get_post_details() returns all supported fields by default.
	 *
	 * @since x.x.x
	 */
	public function test_get_post_details_returns_all_fields_by_default(): void {
		$user_id = $this->factory->user->create( array( 'display_name' => 'Jane Doe' ) );
		$post_id = $this->factory->post->create(
			array(
				'post_title'   => 'The Title',
				'post_content' => 'The body.',
				'post_name'    => 'the-slug',
				'post_excerpt' => 'The excerpt.',
				'post_author'  => $user_id,
			)
		);

		$details = Posts::get_post_details( $post_id );

		$this->assertSame( 'The body.', $details['content'] );
		$this->assertSame( 'The Title', $details['title'] );
		$this->assertSame( 'the-slug', $details['slug'] );
		$this->assertSame( 'Jane Doe', $details['author'] );
		$this->assertSame( 'post', $details['type'] );
		$this->assertSame( 'The excerpt.', $details['excerpt'] );
	}

	/**
	 * Tests that get_post_details() returns only the requested fields.
	 *
	 * @since x.x.x
	 */
	public function test_get_post_details_returns_requested_fields_only(): void {
		$post_id = $this->factory->post->create( array( 'post_title' => 'Only Title' ) );

		$details = Posts::get_post_details( $post_id, array( 'title' ) );

		$this->assertSame( array( 'title' => 'Only Title' ), $details );
	}

	/**
	 * Tests that get_post_details() returns an empty author when the user is gone.
	 *
	 * @since x.x.x
	 */
	public function test_get_post_details_author_is_empty_when_user_missing(): void {
		$post_id = $this->factory->post->create( array( 'post_author' => 999999 ) );

		$details = Posts::get_post_details( $post_id, array( 'author' ) );

		$this->assertSame( array( 'author' => '' ), $details );
	}

	/**
	 * Tests that get_post_terms() returns an error for a missing post.
	 *
	 * @since x.x.x
	 */
	public function test_get_post_terms_returns_error_for_missing_post(): void {
		$result = Posts::get_post_terms( 999999 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'post_not_found', $result->get_error_code() );
	}

	/**
	 * Tests that get_post_terms() returns the post's assigned terms.
	 *
	 * @since x.x.x
	 */
	public function test_get_post_terms_returns_assigned_terms(): void {
		$post_id = $this->factory->post->create();
		$cat_id  = $this->factory->category->create( array( 'name' => 'News' ) );
		wp_set_post_categories( $post_id, array( $cat_id ) );

		$terms = Posts::get_post_terms( $post_id );

		$this->assertIsArray( $terms );
		$this->assertContains( 'News', wp_list_pluck( $terms, 'name' ) );
	}

	/**
	 * Tests that get_post_terms() can filter by a single taxonomy.
	 *
	 * @since x.x.x
	 */
	public function test_get_post_terms_filters_by_taxonomy(): void {
		$post_id = $this->factory->post->create();
		$cat_id  = $this->factory->category->create( array( 'name' => 'News' ) );
		wp_set_post_categories( $post_id, array( $cat_id ) );
		wp_set_post_tags( $post_id, array( 'Breaking' ) );

		$terms      = Posts::get_post_terms( $post_id, 'post_tag' );
		$taxonomies = array_values( array_unique( wp_list_pluck( $terms, 'taxonomy' ) ) );

		$this->assertSame( array( 'post_tag' ), $taxonomies );
	}

	/**
	 * Tests that get_post_terms() errors for an unknown taxonomy.
	 *
	 * @since x.x.x
	 */
	public function test_get_post_terms_returns_error_for_unknown_taxonomy(): void {
		$post_id = $this->factory->post->create();

		$result = Posts::get_post_terms( $post_id, 'does_not_exist' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'taxonomy_not_found', $result->get_error_code() );
	}

	/**
	 * Tests that get_post_terms() skips taxonomies that are not REST-accessible.
	 *
	 * @since x.x.x
	 */
	public function test_get_post_terms_skips_non_rest_taxonomies(): void {
		register_taxonomy( 'private_tax', 'post', array( 'show_in_rest' => false ) );
		$post_id = $this->factory->post->create();
		$term    = wp_insert_term( 'Hidden', 'private_tax' );
		wp_set_object_terms( $post_id, array( (int) $term['term_id'] ), 'private_tax' );

		$terms      = Posts::get_post_terms( $post_id );
		$taxonomies = wp_list_pluck( $terms, 'taxonomy' );

		unregister_taxonomy( 'private_tax' );

		$this->assertNotContains( 'private_tax', $taxonomies );
	}

	/**
	 * Tests that get_post_terms() wraps an underlying term-query error.
	 *
	 * @since x.x.x
	 */
	public function test_get_post_terms_wraps_term_query_errors(): void {
		$post_id = $this->factory->post->create();

		// The return value of wp_get_object_terms() is the value returned by this
		// filter, so use it to simulate a term-query failure.
		$callback = static function () {
			return new WP_Error( 'term_query_failed', 'Simulated failure.' );
		};

		add_filter( 'wp_get_object_terms', $callback );
		$result = Posts::get_post_terms( $post_id );
		remove_filter( 'wp_get_object_terms', $callback );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'get_terms_error', $result->get_error_code() );
	}

	/**
	 * Tests that get_post_terms() skips a taxonomy not registered for the post type.
	 *
	 * @since x.x.x
	 */
	public function test_get_post_terms_skips_taxonomy_not_registered_for_post_type(): void {
		// A REST-enabled taxonomy registered for pages, not posts.
		register_taxonomy( 'page_only_tax', 'page', array( 'show_in_rest' => true ) );
		$post_id = $this->factory->post->create();

		// Requesting it for a 'post' skips it: it passes the show_in_rest check but
		// is not associated with the post's post type.
		$terms = Posts::get_post_terms( $post_id, 'page_only_tax' );

		unregister_taxonomy( 'page_only_tax' );

		$this->assertIsArray( $terms );
		$this->assertEmpty( $terms );
	}
}
