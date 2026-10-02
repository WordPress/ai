<?php
/**
 * The REST categories controller read tests, ported to the core/terms-query ability.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Terms
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Terms;

use WordPress\AI\Abilities\Terms\Terms;

/**
 * Categories test case for the core/terms-query ability.
 *
 * Each test keeps the name of the WP_Test_REST_Categories_Controller test it ports.
 *
 * @since x.x.x
 */
class TermsCategoriesTest extends Terms_Ability_TestCase {

	/**
	 * The number of categories, including Uncategorized.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	protected static int $total_categories = 30;

	/**
	 * A page size that returns every category.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	protected static int $per_page = 50;

	/**
	 * Creates the shared users and the categories for the pagination tests.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_UnitTest_Factory $factory The unit test factory.
	 */
	public static function wpSetUpBeforeClass( $factory ): void {
		parent::wpSetUpBeforeClass( $factory );

		// Set up categories for pagination tests.
		for ( $i = 0; $i < self::$total_categories - 1; $i++ ) {
			$factory->category->create(
				array(
					'name' => "Category {$i}",
				)
			);
		}
	}

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		// REST serves terms to anyone; the ability needs a logged-in user, so use the lowest role.
		$this->login_as( 'subscriber' );
		$this->register_ability();
	}

	/**
	 * The query mode takes REST's collection parameters except `context` and the `slug`
	 * list, plus the taxonomy and the fields.
	 *
	 * @since x.x.x
	 */
	public function test_registered_query_params(): void {
		$keys = array_keys( wp_get_ability( 'core/terms-query' )->get_input_schema()['oneOf'][2]['properties'] );
		sort( $keys );
		$this->assertSame(
			array(
				'exclude',
				'fields',
				'hide_empty',
				'include',
				'order',
				'orderby',
				'page',
				'parent',
				'per_page',
				'post',
				'search',
				'taxonomy',
			),
			$keys,
			'The query mode should take the expected parameters.'
		);
	}

	/**
	 * Every category is returned.
	 *
	 * @since x.x.x
	 */
	public function test_get_items(): void {
		$result = $this->query_terms(
			array(
				'taxonomy' => 'category',
				'per_page' => self::$per_page,
				'fields'   => self::ALL_FIELDS,
			)
		);
		$this->check_get_taxonomy_terms_response( $result, 'category' );
	}

	/**
	 * `hide_empty` leaves out categories without posts.
	 *
	 * @since x.x.x
	 */
	public function test_get_items_hide_empty_arg(): void {
		$post_id   = self::factory()->post->create();
		$category1 = self::factory()->category->create( array( 'name' => 'Season 5' ) );
		$category2 = self::factory()->category->create( array( 'name' => 'The Be Sharps' ) );

		$total_categories = self::$total_categories + 2;

		wp_set_object_terms( $post_id, array( $category1, $category2 ), 'category' );

		$input  = array(
			'taxonomy'   => 'category',
			'per_page'   => self::$per_page,
			'hide_empty' => true,
		);
		$result = $this->query_terms( $input );
		$data   = $result['terms'];
		$this->assertCount( 2, $data, 'Only the categories with posts should be returned.' );
		$this->assertSame( 'Season 5', $data[0]['name'], 'The categories should be ordered by name.' );
		$this->assertSame( 'The Be Sharps', $data[1]['name'], 'The categories should be ordered by name.' );

		// Confirm the empty category "Uncategorized" category appears.
		$input['hide_empty'] = 'false';
		$result              = $this->query_terms( $input );
		$this->assertCount( $total_categories, $result['terms'], 'Every category should be returned.' );
	}

	/**
	 * A `parent` of 0 returns the top-level categories.
	 *
	 * @since x.x.x
	 */
	public function test_get_items_parent_zero_arg(): void {
		$parent1 = self::factory()->category->create( array( 'name' => 'Homer' ) );
		$parent2 = self::factory()->category->create( array( 'name' => 'Marge' ) );
		self::factory()->category->create(
			array(
				'name'   => 'Bart',
				'parent' => $parent1,
			)
		);
		self::factory()->category->create(
			array(
				'name'   => 'Lisa',
				'parent' => $parent2,
			)
		);

		$result = $this->query_terms(
			array(
				'taxonomy' => 'category',
				'per_page' => self::$per_page,
				'parent'   => 0,
			)
		);
		$this->assertIsArray( $result, 'Querying the top-level categories should succeed.' );

		$args       = array(
			'taxonomy'   => 'category',
			'hide_empty' => false,
			'parent'     => 0,
		);
		$categories = get_terms( $args );
		$this->assertCount( count( $categories ), $result['terms'], 'Only the top-level categories should be returned.' );
	}

	/**
	 * A `parent` of "0" returns the top-level categories.
	 *
	 * @since x.x.x
	 */
	public function test_get_items_parent_zero_arg_string(): void {
		$parent1 = self::factory()->category->create( array( 'name' => 'Homer' ) );
		$parent2 = self::factory()->category->create( array( 'name' => 'Marge' ) );
		self::factory()->category->create(
			array(
				'name'   => 'Bart',
				'parent' => $parent1,
			)
		);
		self::factory()->category->create(
			array(
				'name'   => 'Lisa',
				'parent' => $parent2,
			)
		);

		$result = $this->query_terms(
			array(
				'taxonomy' => 'category',
				'per_page' => self::$per_page,
				'parent'   => '0',
			)
		);
		$this->assertIsArray( $result, 'Querying the top-level categories should succeed.' );

		$args       = array(
			'taxonomy'   => 'category',
			'hide_empty' => false,
			'parent'     => 0,
		);
		$categories = get_terms( $args );
		$this->assertCount( count( $categories ), $result['terms'], 'Only the top-level categories should be returned.' );
	}

	/**
	 * A parent without children returns no categories.
	 *
	 * @since x.x.x
	 */
	public function test_get_items_by_parent_non_found(): void {
		$parent1 = self::factory()->category->create( array( 'name' => 'Homer' ) );

		$result = $this->query_terms(
			array(
				'taxonomy' => 'category',
				'parent'   => $parent1,
			)
		);
		$this->assertIsArray( $result, 'Querying the children should succeed.' );
		$this->assertSame( array(), $result['terms'], 'No categories should be returned.' );
	}

	/**
	 * A page below 1 is rejected.
	 *
	 * @since x.x.x
	 */
	public function test_get_items_invalid_page(): void {
		$result = $this->query_terms(
			array(
				'taxonomy' => 'category',
				'page'     => 0,
			)
		);
		$this->assertAbilityError( $result, 'ability_invalid_input', 'A page below 1 should be rejected.' );
		$this->assertStringContainsString( 'page] must be greater than or equal to 1', $result->get_error_message(), 'The error should name the page minimum.' );
	}

	/**
	 * `include` limits the categories, ordered by name or by the given IDs.
	 *
	 * @since x.x.x
	 */
	public function test_get_items_include_query(): void {
		$id1 = self::factory()->category->create();
		$id2 = self::factory()->category->create();

		$input = array( 'taxonomy' => 'category' );

		// Ordered by name by default.
		$input['include'] = array( $id2, $id1 );
		$result           = $this->query_terms( $input );
		$this->assertCount( 2, $result['terms'], 'Only the included categories should be returned.' );
		$this->assertSame( $id1, $result['terms'][0]['id'], 'The categories should be ordered by name.' );

		// Ordered by the included IDs.
		$input['orderby'] = 'include';
		$result           = $this->query_terms( $input );
		$this->assertCount( 2, $result['terms'], 'Only the included categories should be returned.' );
		$this->assertSame( $id2, $result['terms'][0]['id'], 'The categories should be in the included order.' );
	}

	/**
	 * `exclude` leaves out the given categories.
	 *
	 * @since x.x.x
	 */
	public function test_get_items_exclude_query(): void {
		$id1 = self::factory()->category->create();
		$id2 = self::factory()->category->create();

		$input  = array(
			'taxonomy' => 'category',
			'per_page' => self::$per_page,
		);
		$result = $this->query_terms( $input );
		$ids    = wp_list_pluck( $result['terms'], 'id' );
		$this->assertContains( $id1, $ids, 'The first category should be returned.' );
		$this->assertContains( $id2, $ids, 'The second category should be returned.' );

		$input['exclude'] = array( $id2 ); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Term query argument.
		$result           = $this->query_terms( $input );
		$ids              = wp_list_pluck( $result['terms'], 'id' );
		$this->assertContains( $id1, $ids, 'The first category should still be returned.' );
		$this->assertNotContains( $id2, $ids, 'The excluded category should not be returned.' );
	}

	/**
	 * `orderby`, `order`, and `per_page` sort and limit the categories.
	 *
	 * @since x.x.x
	 */
	public function test_get_items_orderby_args(): void {
		self::factory()->category->create( array( 'name' => 'Apple' ) );
		self::factory()->category->create( array( 'name' => 'Banana' ) );

		/*
		 * Tests:
		 * - orderby
		 * - order
		 * - per_page
		 */
		$result = $this->query_terms(
			array(
				'taxonomy' => 'category',
				'orderby'  => 'name',
				'order'    => 'desc',
				'per_page' => 1,
			)
		);
		$this->assertIsArray( $result, 'Querying the categories should succeed.' );
		$this->assertCount( 1, $result['terms'], 'One category should be returned.' );
		$this->assertSame( 'Uncategorized', $result['terms'][0]['name'], 'The last category by name should come first.' );

		$result = $this->query_terms(
			array(
				'taxonomy' => 'category',
				'orderby'  => 'name',
				'order'    => 'asc',
				'per_page' => 2,
			)
		);
		$this->assertIsArray( $result, 'Querying the categories should succeed.' );
		$this->assertCount( 2, $result['terms'], 'Two categories should be returned.' );
		$this->assertSame( 'Apple', $result['terms'][0]['name'], 'The first category by name should come first.' );
	}

	/**
	 * Categories are ordered by name by default, and by ID when asked.
	 *
	 * @since x.x.x
	 */
	public function test_get_items_orderby_id(): void {
		self::factory()->category->create( array( 'name' => 'Cantaloupe' ) );
		self::factory()->category->create( array( 'name' => 'Apple' ) );
		self::factory()->category->create( array( 'name' => 'Banana' ) );

		// Ordered by name, ascending, by default.
		$result = $this->query_terms( array( 'taxonomy' => 'category' ) );
		$this->assertIsArray( $result, 'Querying the categories should succeed.' );
		$data = $result['terms'];
		$this->assertSame( 'Apple', $data[0]['name'], 'The categories should be ordered by name.' );
		$this->assertSame( 'Banana', $data[1]['name'], 'The categories should be ordered by name.' );
		$this->assertSame( 'Cantaloupe', $data[2]['name'], 'The categories should be ordered by name.' );

		// Ordered by ID, ascending by default.
		$result = $this->query_terms(
			array(
				'taxonomy' => 'category',
				'orderby'  => 'id',
			)
		);
		$this->assertIsArray( $result, 'Querying the categories should succeed.' );
		$data = $result['terms'];
		$this->assertSame( 'Category 0', $data[1]['name'], 'The categories should be ordered by ID.' );
		$this->assertSame( 'Category 1', $data[2]['name'], 'The categories should be ordered by ID.' );
		$this->assertSame( 'Category 2', $data[3]['name'], 'The categories should be ordered by ID.' );

		// Ordered by ID, descending.
		$result = $this->query_terms(
			array(
				'taxonomy' => 'category',
				'orderby'  => 'id',
				'order'    => 'desc',
			)
		);
		$this->assertIsArray( $result, 'Querying the categories should succeed.' );
		$data = $result['terms'];
		$this->assertSame( 'Banana', $data[0]['name'], 'The categories should be ordered by descending ID.' );
		$this->assertSame( 'Apple', $data[1]['name'], 'The categories should be ordered by descending ID.' );
		$this->assertSame( 'Cantaloupe', $data[2]['name'], 'The categories should be ordered by descending ID.' );
	}

	/**
	 * Creates a post with three described categories.
	 *
	 * @since x.x.x
	 *
	 * @return int The post ID.
	 */
	protected function post_with_categories(): int {
		$post_id   = self::factory()->post->create();
		$category1 = self::factory()->category->create(
			array(
				'name'        => 'DC',
				'description' => 'Purveyor of fine detective comics',
			)
		);
		$category2 = self::factory()->category->create(
			array(
				'name'        => 'Marvel',
				'description' => 'Home of the Marvel Universe',
			)
		);
		$category3 = self::factory()->category->create(
			array(
				'name'        => 'Image',
				'description' => 'American independent comic publisher',
			)
		);
		wp_set_object_terms( $post_id, array( $category1, $category2, $category3 ), 'category' );

		return $post_id;
	}

	/**
	 * `post` returns the post's categories, ordered by name.
	 *
	 * @since x.x.x
	 */
	public function test_get_items_post_args(): void {
		$post_id = $this->post_with_categories();

		$result = $this->query_terms(
			array(
				'taxonomy' => 'category',
				'post'     => $post_id,
			)
		);
		$this->assertIsArray( $result, 'Querying the post categories should succeed.' );
		$this->assertCount( 3, $result['terms'], 'The post categories should be returned.' );

		// Check ordered by name by default.
		$names = wp_list_pluck( $result['terms'], 'name' );
		$this->assertSame( array( 'DC', 'Image', 'Marvel' ), $names, 'The post categories should be ordered by name.' );
	}

	/**
	 * The post's categories can be ordered by description.
	 *
	 * @since x.x.x
	 */
	public function test_get_items_post_ordered_by_description(): void {
		$post_id = $this->post_with_categories();

		// Regular request.
		$input  = array(
			'taxonomy' => 'category',
			'post'     => $post_id,
			'orderby'  => 'description',
		);
		$result = $this->query_terms( $input );
		$this->assertIsArray( $result, 'Querying the post categories should succeed.' );
		$this->assertCount( 3, $result['terms'], 'The post categories should be returned.' );
		$names = wp_list_pluck( $result['terms'], 'name' );
		$this->assertSame( array( 'Image', 'Marvel', 'DC' ), $names, 'Terms should be ordered by description' );

		// Flip the order.
		$input['order'] = 'desc';
		$result         = $this->query_terms( $input );
		$this->assertIsArray( $result, 'Querying the post categories should succeed.' );
		$this->assertCount( 3, $result['terms'], 'The post categories should be returned.' );
		$names = wp_list_pluck( $result['terms'], 'name' );
		$this->assertSame( array( 'DC', 'Marvel', 'Image' ), $names, 'Terms should be reverse-ordered by description' );
	}

	/**
	 * The post's categories can be ordered by ID.
	 *
	 * @since x.x.x
	 */
	public function test_get_items_post_ordered_by_id(): void {
		$post_id = $this->post_with_categories();

		$result = $this->query_terms(
			array(
				'taxonomy' => 'category',
				'post'     => $post_id,
				'orderby'  => 'id',
			)
		);
		$this->assertIsArray( $result, 'Querying the post categories should succeed.' );
		$this->assertCount( 3, $result['terms'], 'The post categories should be returned.' );
		$names = wp_list_pluck( $result['terms'], 'name' );
		$this->assertSame( array( 'DC', 'Marvel', 'Image' ), $names, 'The post categories should be ordered by ID.' );
	}

	/**
	 * `post` returns a post's terms in a custom taxonomy exposed to abilities.
	 *
	 * @since x.x.x
	 */
	public function test_get_items_custom_tax_post_args(): void {
		$this->register_test_taxonomy( 'batman', 'post', array( 'show_in_abilities' => true ) );
		$this->register_ability();
		$term1 = self::factory()->term->create(
			array(
				'name'     => 'Cape',
				'taxonomy' => 'batman',
			)
		);
		$term2 = self::factory()->term->create(
			array(
				'name'     => 'Mask',
				'taxonomy' => 'batman',
			)
		);
		self::factory()->term->create(
			array(
				'name'     => 'Car',
				'taxonomy' => 'batman',
			)
		);
		$post_id = self::factory()->post->create();
		wp_set_object_terms( $post_id, array( $term1, $term2 ), 'batman' );

		$result = $this->query_terms(
			array(
				'taxonomy' => 'batman',
				'post'     => $post_id,
			)
		);
		$this->assertIsArray( $result, 'Querying the post terms should succeed.' );
		$this->assertCount( 2, $result['terms'], 'The post terms should be returned.' );
		$this->assertSame( 'Cape', $result['terms'][0]['name'], 'The post terms should be ordered by name.' );
	}

	/**
	 * `search` limits the categories to those matching a string.
	 *
	 * @since x.x.x
	 */
	public function test_get_items_search_args(): void {
		self::factory()->category->create( array( 'name' => 'Apple' ) );
		self::factory()->category->create( array( 'name' => 'Banana' ) );

		/*
		 * Tests:
		 * - search
		 */
		$result = $this->query_terms(
			array(
				'taxonomy' => 'category',
				'search'   => 'App',
			)
		);
		$this->assertIsArray( $result, 'Searching the categories should succeed.' );
		$this->assertCount( 1, $result['terms'], 'One category should match.' );
		$this->assertSame( 'Apple', $result['terms'][0]['name'], 'The matching category should be returned.' );

		$result = $this->query_terms(
			array(
				'taxonomy' => 'category',
				'search'   => 'Garbage',
			)
		);
		$this->assertIsArray( $result, 'Searching the categories should succeed.' );
		$this->assertCount( 0, $result['terms'], 'No category should match.' );
	}

	/**
	 * A slug returns its category, through the single-term mode.
	 *
	 * @since x.x.x
	 */
	public function test_get_items_slug_arg(): void {
		self::factory()->category->create( array( 'name' => 'Apple' ) );
		self::factory()->category->create( array( 'name' => 'Banana' ) );

		$result = $this->query_terms(
			array(
				'taxonomy' => 'category',
				'slug'     => 'apple',
			)
		);
		$this->assertIsArray( $result, 'Getting the category by slug should succeed.' );
		$this->assertSame( 'Apple', $result['name'], 'The category with the slug should be returned.' );
	}

	/**
	 * `parent` returns the children of a category.
	 *
	 * @since x.x.x
	 */
	public function test_get_terms_parent_arg(): void {
		$category1 = self::factory()->category->create( array( 'name' => 'Parent' ) );
		self::factory()->category->create(
			array(
				'name'   => 'Child',
				'parent' => $category1,
			)
		);

		$result = $this->query_terms(
			array(
				'taxonomy' => 'category',
				'parent'   => $category1,
			)
		);
		$this->assertCount( 1, $result['terms'], 'One child should be returned.' );
		$this->assertSame( 'Child', $result['terms'][0]['name'], 'The child should be returned.' );
	}

	/**
	 * A `parent` that is not an integer is rejected.
	 *
	 * @since x.x.x
	 */
	public function test_get_terms_invalid_parent_arg(): void {
		$result = $this->query_terms(
			array(
				'taxonomy' => 'category',
				'parent'   => 'invalid-parent',
			)
		);
		$this->assertAbilityError( $result, 'ability_invalid_input', 'A parent that is not an integer should be rejected.' );
	}

	/**
	 * The terms of a private taxonomy cannot be queried.
	 *
	 * @since x.x.x
	 */
	public function test_get_terms_private_taxonomy(): void {
		$this->register_test_taxonomy( 'robin', 'post', array( 'public' => false ) );
		$this->register_ability();
		self::factory()->term->create(
			array(
				'name'     => 'Cape',
				'taxonomy' => 'robin',
			)
		);
		self::factory()->term->create(
			array(
				'name'     => 'Mask',
				'taxonomy' => 'robin',
			)
		);

		$result = $this->query_terms( array( 'taxonomy' => 'robin' ) );
		$this->assertAbilityError( $result, 'ability_invalid_input', 'A taxonomy not exposed to abilities should be rejected.' );
	}

	/**
	 * The terms of a taxonomy that does not exist cannot be queried.
	 *
	 * @since x.x.x
	 */
	public function test_get_terms_invalid_taxonomy(): void {
		$result = $this->query_terms( array( 'taxonomy' => 'invalid-taxonomy' ) );
		$this->assertAbilityError( $result, 'ability_invalid_input', 'A taxonomy that does not exist should be rejected.' );
	}

	/**
	 * The totals count every category on every page, including pages past the end.
	 *
	 * @since x.x.x
	 */
	public function test_get_terms_pagination_headers(): void {
		$total_categories = self::$total_categories;
		$total_pages      = (int) ceil( $total_categories / 10 );

		// Start of the index + Uncategorized default term.
		$result = $this->query_terms( array( 'taxonomy' => 'category' ) );
		$this->assertSame( $total_categories, $result['total'], 'The total should count every category.' );
		$this->assertSame( $total_pages, $result['total_pages'], 'The total pages should match.' );
		$this->assertCount( 10, $result['terms'], 'A full first page should be returned.' );

		// 3rd page.
		self::factory()->category->create();
		++$total_categories;
		++$total_pages;
		$result = $this->query_terms(
			array(
				'taxonomy' => 'category',
				'page'     => 3,
			)
		);
		$this->assertSame( $total_categories, $result['total'], 'The total should count the new category.' );
		$this->assertSame( $total_pages, $result['total_pages'], 'The total pages should grow.' );
		$this->assertCount( 10, $result['terms'], 'A full third page should be returned.' );

		// Last page.
		$result = $this->query_terms(
			array(
				'taxonomy' => 'category',
				'page'     => $total_pages,
			)
		);
		$this->assertSame( $total_categories, $result['total'], 'The total should be the same on the last page.' );
		$this->assertSame( $total_pages, $result['total_pages'], 'The total pages should be the same on the last page.' );
		$this->assertCount( 1, $result['terms'], 'The last page should hold the remaining category.' );

		// Out of bounds.
		$result = $this->query_terms(
			array(
				'taxonomy' => 'category',
				'page'     => 100,
			)
		);
		$this->assertSame( $total_categories, $result['total'], 'The total should be the same past the last page.' );
		$this->assertSame( $total_pages, $result['total_pages'], 'The total pages should be the same past the last page.' );
		$this->assertCount( 0, $result['terms'], 'A page past the end should be empty.' );
	}

	/**
	 * A page size above the number of categories returns them all on one page.
	 *
	 * @since x.x.x
	 */
	public function test_get_items_per_page_exceeds_number_of_items(): void {
		// Start of the index + Uncategorized default term.
		$result = $this->query_terms(
			array(
				'taxonomy' => 'category',
				'page'     => 1,
				'per_page' => 100,
			)
		);
		$this->assertSame( self::$total_categories, $result['total'], 'The total should count every category.' );
		$this->assertSame( 1, $result['total_pages'], 'Every category should fit on one page.' );
		$this->assertCount( self::$total_categories, $result['terms'], 'Every category should be returned.' );

		$result = $this->query_terms(
			array(
				'taxonomy' => 'category',
				'page'     => 2,
				'per_page' => 100,
			)
		);
		$this->assertSame( self::$total_categories, $result['total'], 'The total should be the same on the second page.' );
		$this->assertSame( 1, $result['total_pages'], 'The total pages should be the same on the second page.' );
		$this->assertCount( 0, $result['terms'], 'The second page should be empty.' );
	}

	/**
	 * A category is returned by ID.
	 *
	 * @since x.x.x
	 */
	public function test_get_item(): void {
		$result = $this->query_terms(
			array(
				'id'       => 1,
				'taxonomy' => 'category',
				'fields'   => self::ALL_FIELDS,
			)
		);
		$this->check_taxonomy_term( get_term( 1, 'category' ), $result );
	}

	/**
	 * A term cannot be read through a taxonomy that does not exist.
	 *
	 * @since x.x.x
	 */
	public function test_get_term_invalid_taxonomy(): void {
		$result = $this->query_terms(
			array(
				'id'       => 1,
				'taxonomy' => 'invalid-taxonomy',
			)
		);
		$this->assertAbilityError( $result, 'ability_invalid_input', 'A taxonomy that does not exist should be rejected.' );
	}

	/**
	 * A missing category is denied, and a direct call reports it as invalid.
	 *
	 * @since x.x.x
	 */
	public function test_get_term_invalid_term(): void {
		$input = array(
			'id'       => REST_TESTS_IMPOSSIBLY_HIGH_NUMBER,
			'taxonomy' => 'category',
		);

		$this->assertAbilityDenied( $this->query_terms( $input ), 'A missing category should be denied.' );

		$direct = ( new Terms() )->execute_terms_query( $input );
		$this->assertAbilityError( $direct, 'terms_term_invalid', 'A direct call should report the missing category.' );
		$this->assertSame( 404, $direct->get_error_data()['status'], 'A missing category should be not found.' );
	}

	/**
	 * A term of a private taxonomy cannot be read.
	 *
	 * @since x.x.x
	 */
	public function test_get_term_private_taxonomy(): void {
		$this->register_test_taxonomy( 'robin', 'post', array( 'public' => false ) );
		$this->register_ability();
		$term1 = self::factory()->term->create(
			array(
				'name'     => 'Cape',
				'taxonomy' => 'robin',
			)
		);

		$result = $this->query_terms(
			array(
				'id'       => $term1,
				'taxonomy' => 'robin',
			)
		);
		$this->assertAbilityError( $result, 'ability_invalid_input', 'A taxonomy not exposed to abilities should be rejected.' );
	}

	/**
	 * A term of another taxonomy is denied as a category, and a direct call reports it as invalid.
	 *
	 * @since x.x.x
	 */
	public function test_get_item_incorrect_taxonomy(): void {
		$this->register_test_taxonomy( 'robin', 'post' );
		$term1 = self::factory()->term->create(
			array(
				'name'     => 'Cape',
				'taxonomy' => 'robin',
			)
		);

		$input = array(
			'id'       => $term1,
			'taxonomy' => 'category',
		);

		$this->assertAbilityDenied( $this->query_terms( $input ), 'A term of another taxonomy should be denied.' );

		$direct = ( new Terms() )->execute_terms_query( $input );
		$this->assertAbilityError( $direct, 'terms_term_invalid', 'A direct call should report the term as invalid.' );
		$this->assertSame( 404, $direct->get_error_data()['status'], 'A term of another taxonomy should be not found.' );
	}

	/**
	 * Every field of a queried category matches the category.
	 *
	 * @since x.x.x
	 */
	public function test_prepare_item(): void {
		$term = get_term( 1, 'category' );

		$result = $this->query_terms(
			array(
				'taxonomy' => 'category',
				'include'  => array( 1 ),
				'fields'   => self::ALL_FIELDS,
			)
		);
		$this->check_taxonomy_term( $term, $result['terms'][0] );
	}

	/**
	 * `fields` limits the returned fields.
	 *
	 * @since x.x.x
	 */
	public function test_prepare_item_limit_fields(): void {
		$result = $this->query_terms(
			array(
				'id'       => 1,
				'taxonomy' => 'category',
				'fields'   => 'id,name',
			)
		);
		$this->assertSame(
			array(
				'id',
				'name',
			),
			array_keys( $result ),
			'Only the requested fields should be returned.'
		);
	}

	/**
	 * A child category returns its parent.
	 *
	 * @since x.x.x
	 */
	public function test_prepare_taxonomy_term_child(): void {
		$child = self::factory()->category->create(
			array(
				'parent' => 1,
			)
		);
		$term  = get_term( $child, 'category' );

		$result = $this->query_terms(
			array(
				'id'       => $child,
				'taxonomy' => 'category',
				'fields'   => self::ALL_FIELDS,
			)
		);
		$this->check_taxonomy_term( $term, $result );

		$this->assertSame( 1, $result['parent'], 'The parent should be returned.' );
	}

	/**
	 * The term schema has REST's fields except `meta`.
	 *
	 * @since x.x.x
	 */
	public function test_get_item_schema(): void {
		$properties = wp_get_ability( 'core/terms-query' )->get_output_schema()['oneOf'][0]['properties'];
		$this->assertCount( 8, $properties, 'The term schema should have eight fields.' );
		$this->assertArrayHasKey( 'id', $properties, 'The term schema should have the ID.' );
		$this->assertArrayHasKey( 'count', $properties, 'The term schema should have the count.' );
		$this->assertArrayHasKey( 'description', $properties, 'The term schema should have the description.' );
		$this->assertArrayHasKey( 'link', $properties, 'The term schema should have the link.' );
		$this->assertArrayHasKey( 'name', $properties, 'The term schema should have the name.' );
		$this->assertArrayHasKey( 'parent', $properties, 'The term schema should have the parent.' );
		$this->assertArrayHasKey( 'slug', $properties, 'The term schema should have the slug.' );
		$this->assertArrayHasKey( 'taxonomy', $properties, 'The term schema should have the taxonomy.' );
	}
}
