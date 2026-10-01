<?php
/**
 * The REST tags controller read tests, ported to the core/terms-query ability.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Terms
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Terms;

use WordPress\AI\Abilities\Terms\Terms;

/**
 * Tags test case for the core/terms-query ability.
 *
 * Each test keeps the name of the WP_Test_REST_Tags_Controller test it ports.
 *
 * @since x.x.x
 */
class TermsTagsTest extends Terms_Ability_TestCase {

	/**
	 * Tag IDs for the pagination tests.
	 *
	 * @since x.x.x
	 *
	 * @var list<int>
	 */
	protected static array $tag_ids = array();

	/**
	 * The number of tags.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	protected static int $total_tags = 30;

	/**
	 * A page size that returns every tag.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	protected static int $per_page = 50;

	/**
	 * Creates the shared users and the tags for the pagination tests.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_UnitTest_Factory $factory The unit test factory.
	 */
	public static function wpSetUpBeforeClass( $factory ): void {
		parent::wpSetUpBeforeClass( $factory );

		// Set up tags for pagination tests.
		for ( $i = 0; $i < self::$total_tags; $i++ ) {
			self::$tag_ids[] = $factory->tag->create(
				array(
					'name' => "Tag {$i}",
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
	 * The query mode takes REST's collection parameters except `context`, `offset`, and the
	 * `slug` list, plus the taxonomy, the fields, and the `parent` filter that tags ignore.
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
	 * Every tag is returned.
	 *
	 * @since x.x.x
	 */
	public function test_get_items(): void {
		self::factory()->tag->create();

		$result = $this->query_terms(
			array(
				'taxonomy' => 'post_tag',
				'per_page' => self::$per_page,
				'fields'   => self::ALL_FIELDS,
			)
		);
		$this->check_get_taxonomy_terms_response( $result, 'post_tag' );
	}

	/**
	 * `hide_empty` leaves out tags without posts, and must be a boolean.
	 *
	 * @since x.x.x
	 */
	public function test_get_items_hide_empty_arg(): void {
		$post_id = self::factory()->post->create();
		$tag1    = self::factory()->tag->create( array( 'name' => 'Season 5' ) );
		$tag2    = self::factory()->tag->create( array( 'name' => 'The Be Sharps' ) );

		wp_set_object_terms( $post_id, array( $tag1, $tag2 ), 'post_tag' );

		$input  = array(
			'taxonomy'   => 'post_tag',
			'hide_empty' => true,
		);
		$result = $this->query_terms( $input );
		$data   = $result['terms'];
		$this->assertCount( 2, $data, 'Only the tags with posts should be returned.' );
		$this->assertSame( 'Season 5', $data[0]['name'], 'The tags should be ordered by name.' );
		$this->assertSame( 'The Be Sharps', $data[1]['name'], 'The tags should be ordered by name.' );

		// Invalid 'hide_empty' should error.
		$input['hide_empty'] = 'nothanks';
		$this->assertAbilityError( $this->query_terms( $input ), 'ability_invalid_input', 'A hide_empty that is not a boolean should be rejected.' );
	}

	/**
	 * `include` limits the tags, ordered by name or by the given IDs, and takes only IDs.
	 *
	 * @since x.x.x
	 */
	public function test_get_items_include_query(): void {
		$id1 = self::factory()->tag->create();
		$id2 = self::factory()->tag->create();

		$input = array( 'taxonomy' => 'post_tag' );

		// Ordered by name by default.
		$input['include'] = array( $id2, $id1 );
		$result           = $this->query_terms( $input );
		$this->assertCount( 2, $result['terms'], 'Only the included tags should be returned.' );
		$this->assertSame( $id1, $result['terms'][0]['id'], 'The tags should be ordered by name.' );

		// Ordered by the included IDs.
		$input['orderby'] = 'include';
		$result           = $this->query_terms( $input );
		$this->assertCount( 2, $result['terms'], 'Only the included tags should be returned.' );
		$this->assertSame( $id2, $result['terms'][0]['id'], 'The tags should be in the included order.' );

		// Invalid 'include' should error.
		$input['include'] = array( 'myterm' );
		$this->assertAbilityError( $this->query_terms( $input ), 'ability_invalid_input', 'An include that is not an ID should be rejected.' );
	}

	/**
	 * `exclude` leaves out the given tags, and takes only IDs.
	 *
	 * @since x.x.x
	 */
	public function test_get_items_exclude_query(): void {
		$id1 = self::factory()->tag->create();
		$id2 = self::factory()->tag->create();

		$input  = array(
			'taxonomy' => 'post_tag',
			'per_page' => self::$per_page,
		);
		$result = $this->query_terms( $input );
		$ids    = wp_list_pluck( $result['terms'], 'id' );
		$this->assertContains( $id1, $ids, 'The first tag should be returned.' );
		$this->assertContains( $id2, $ids, 'The second tag should be returned.' );

		$input['exclude'] = array( $id2 ); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Term query argument.
		$result           = $this->query_terms( $input );
		$ids              = wp_list_pluck( $result['terms'], 'id' );
		$this->assertContains( $id1, $ids, 'The first tag should still be returned.' );
		$this->assertNotContains( $id2, $ids, 'The excluded tag should not be returned.' );

		// Invalid 'exclude' should error.
		$input['exclude'] = array( 'invalid' ); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Term query argument.
		$this->assertAbilityError( $this->query_terms( $input ), 'ability_invalid_input', 'An exclude that is not an ID should be rejected.' );
	}

	/**
	 * `orderby`, `order`, and `per_page` sort and limit the tags, and `orderby` must be known.
	 *
	 * @since x.x.x
	 */
	public function test_get_items_orderby_args(): void {
		self::factory()->tag->create( array( 'name' => 'Apple' ) );
		self::factory()->tag->create( array( 'name' => 'Zucchini' ) );

		/*
		 * Tests:
		 * - orderby
		 * - order
		 * - per_page
		 */
		$result = $this->query_terms(
			array(
				'taxonomy' => 'post_tag',
				'orderby'  => 'name',
				'order'    => 'desc',
				'per_page' => 1,
			)
		);
		$this->assertIsArray( $result, 'Querying the tags should succeed.' );
		$this->assertCount( 1, $result['terms'], 'One tag should be returned.' );
		$this->assertSame( 'Zucchini', $result['terms'][0]['name'], 'The last tag by name should come first.' );

		$input  = array(
			'taxonomy' => 'post_tag',
			'orderby'  => 'name',
			'order'    => 'asc',
			'per_page' => 2,
		);
		$result = $this->query_terms( $input );
		$this->assertIsArray( $result, 'Querying the tags should succeed.' );
		$this->assertCount( 2, $result['terms'], 'Two tags should be returned.' );
		$this->assertSame( 'Apple', $result['terms'][0]['name'], 'The first tag by name should come first.' );

		// Invalid 'orderby' should error.
		$input['orderby'] = 'invalid';
		$this->assertAbilityError( $this->query_terms( $input ), 'ability_invalid_input', 'An unknown orderby should be rejected.' );
	}

	/**
	 * Tags are ordered by name by default, and by ID when asked.
	 *
	 * @since x.x.x
	 */
	public function test_get_items_orderby_id(): void {
		self::factory()->tag->create( array( 'name' => 'Cantaloupe' ) );
		self::factory()->tag->create( array( 'name' => 'Apple' ) );
		self::factory()->tag->create( array( 'name' => 'Banana' ) );

		// Ordered by name, ascending, by default.
		$result = $this->query_terms( array( 'taxonomy' => 'post_tag' ) );
		$this->assertIsArray( $result, 'Querying the tags should succeed.' );
		$data = $result['terms'];
		$this->assertSame( 'Apple', $data[0]['name'], 'The tags should be ordered by name.' );
		$this->assertSame( 'Banana', $data[1]['name'], 'The tags should be ordered by name.' );
		$this->assertSame( 'Cantaloupe', $data[2]['name'], 'The tags should be ordered by name.' );

		// Ordered by ID, ascending by default.
		$result = $this->query_terms(
			array(
				'taxonomy' => 'post_tag',
				'orderby'  => 'id',
			)
		);
		$this->assertIsArray( $result, 'Querying the tags should succeed.' );
		$data = $result['terms'];
		$this->assertSame( 'Tag 0', $data[0]['name'], 'The tags should be ordered by ID.' );
		$this->assertSame( 'Tag 1', $data[1]['name'], 'The tags should be ordered by ID.' );
		$this->assertSame( 'Tag 2', $data[2]['name'], 'The tags should be ordered by ID.' );

		// Ordered by ID, descending.
		$result = $this->query_terms(
			array(
				'taxonomy' => 'post_tag',
				'orderby'  => 'id',
				'order'    => 'desc',
			)
		);
		$this->assertIsArray( $result, 'Querying the tags should succeed.' );
		$data = $result['terms'];
		$this->assertSame( 'Banana', $data[0]['name'], 'The tags should be ordered by descending ID.' );
		$this->assertSame( 'Apple', $data[1]['name'], 'The tags should be ordered by descending ID.' );
		$this->assertSame( 'Cantaloupe', $data[2]['name'], 'The tags should be ordered by descending ID.' );
	}

	/**
	 * `post` returns the post's tags, and must be an ID.
	 *
	 * @since x.x.x
	 */
	public function test_get_items_post_args(): void {
		$post_id = self::factory()->post->create();
		$tag1    = self::factory()->tag->create( array( 'name' => 'DC' ) );
		$tag2    = self::factory()->tag->create( array( 'name' => 'Marvel' ) );
		self::factory()->tag->create( array( 'name' => 'Dark Horse' ) );

		wp_set_object_terms( $post_id, array( $tag1, $tag2 ), 'post_tag' );

		$result = $this->query_terms(
			array(
				'taxonomy' => 'post_tag',
				'post'     => $post_id,
			)
		);
		$this->assertIsArray( $result, 'Querying the post tags should succeed.' );
		$this->assertCount( 2, $result['terms'], 'The post tags should be returned.' );
		$this->assertSame( 'DC', $result['terms'][0]['name'], 'The post tags should be ordered by name.' );

		// Invalid 'post' should error.
		$result = $this->query_terms(
			array(
				'taxonomy' => 'post_tag',
				'post'     => 'invalid-post',
			)
		);
		$this->assertAbilityError( $result, 'ability_invalid_input', 'A post that is not an ID should be rejected.' );
	}

	/**
	 * The post's tags can be paged.
	 *
	 * @since x.x.x
	 */
	public function test_get_terms_post_args_paging(): void {
		$post_id = self::factory()->post->create();

		wp_set_object_terms( $post_id, self::$tag_ids, 'post_tag' );

		$input = array(
			'taxonomy' => 'post_tag',
			'post'     => $post_id,
			'page'     => 1,
			'per_page' => 15,
			'orderby'  => 'id',
		);
		$tags  = $this->query_terms( $input )['terms'];

		$this->assertNotEmpty( $tags, 'The first page should hold tags.' );

		$i = 0;
		foreach ( $tags as $tag ) {
			$this->assertSame( $tag['name'], "Tag {$i}", 'The first page should hold the first tags.' );
			++$i;
		}

		$input['page'] = 2;
		$tags          = $this->query_terms( $input )['terms'];

		$this->assertNotEmpty( $tags, 'The second page should hold tags.' );

		foreach ( $tags as $tag ) {
			$this->assertSame( $tag['name'], "Tag {$i}", 'The second page should hold the next tags.' );
			++$i;
		}
	}

	/**
	 * A post without tags returns none.
	 *
	 * @since x.x.x
	 */
	public function test_get_items_post_empty(): void {
		$post_id = self::factory()->post->create();

		$result = $this->query_terms(
			array(
				'taxonomy' => 'post_tag',
				'post'     => $post_id,
			)
		);
		$this->assertIsArray( $result, 'Querying the post tags should succeed.' );
		$this->assertCount( 0, $result['terms'], 'No tags should be returned.' );
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
	 * Without `post`, the query arguments a taxonomy registers override the ability's.
	 *
	 * @ticket 62500
	 *
	 * @since x.x.x
	 */
	public function test_get_items_custom_tax_without_post_arg_respects_tax_query_args(): void {
		$this->register_test_taxonomy(
			'batman',
			'post',
			array(
				'show_in_abilities' => true,
				'sort'              => true,
				'args'              => array(
					'order'   => 'DESC',
					'orderby' => 'name',
				),
			)
		);
		$this->register_ability();
		self::factory()->term->create(
			array(
				'name'     => 'Cycle',
				'taxonomy' => 'batman',
			)
		);
		self::factory()->term->create(
			array(
				'name'     => 'Pod',
				'taxonomy' => 'batman',
			)
		);
		self::factory()->term->create(
			array(
				'name'     => 'Cave',
				'taxonomy' => 'batman',
			)
		);

		$result = $this->query_terms( array( 'taxonomy' => 'batman' ) );
		$this->assertIsArray( $result, 'Querying the terms should succeed.' );
		$this->assertCount( 3, $result['terms'], 'Every term should be returned.' );
		$this->assertSame(
			array( 'Pod', 'Cycle', 'Cave' ),
			array_column( $result['terms'], 'name' ),
			'The taxonomy query arguments should order the terms.'
		);
	}

	/**
	 * `search` limits the tags to those matching a string.
	 *
	 * @since x.x.x
	 */
	public function test_get_items_search_args(): void {
		self::factory()->tag->create( array( 'name' => 'Apple' ) );
		self::factory()->tag->create( array( 'name' => 'Banana' ) );

		/*
		 * Tests:
		 * - search
		 */
		$result = $this->query_terms(
			array(
				'taxonomy' => 'post_tag',
				'search'   => 'App',
			)
		);
		$this->assertIsArray( $result, 'Searching the tags should succeed.' );
		$this->assertCount( 1, $result['terms'], 'One tag should match.' );
		$this->assertSame( 'Apple', $result['terms'][0]['name'], 'The matching tag should be returned.' );

		$result = $this->query_terms(
			array(
				'taxonomy' => 'post_tag',
				'search'   => 'Garbage',
			)
		);
		$this->assertIsArray( $result, 'Searching the tags should succeed.' );
		$this->assertCount( 0, $result['terms'], 'No tag should match.' );
	}

	/**
	 * A slug returns its tag, through the single-term mode.
	 *
	 * @since x.x.x
	 */
	public function test_get_items_slug_arg(): void {
		self::factory()->tag->create( array( 'name' => 'Apple' ) );
		self::factory()->tag->create( array( 'name' => 'Banana' ) );

		$result = $this->query_terms(
			array(
				'taxonomy' => 'post_tag',
				'slug'     => 'apple',
			)
		);
		$this->assertIsArray( $result, 'Getting the tag by slug should succeed.' );
		$this->assertSame( 'Apple', $result['name'], 'The tag with the slug should be returned.' );
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
	 * The totals count every tag on every page, including pages past the end.
	 *
	 * @since x.x.x
	 */
	public function test_get_terms_pagination_headers(): void {
		$total_tags  = self::$total_tags;
		$total_pages = (int) ceil( $total_tags / 10 );

		// Start of the index.
		$result = $this->query_terms( array( 'taxonomy' => 'post_tag' ) );
		$this->assertSame( $total_tags, $result['total'], 'The total should count every tag.' );
		$this->assertSame( $total_pages, $result['total_pages'], 'The total pages should match.' );

		// 3rd page.
		self::factory()->tag->create();
		++$total_tags;
		++$total_pages;
		$result = $this->query_terms(
			array(
				'taxonomy' => 'post_tag',
				'page'     => 3,
			)
		);
		$this->assertSame( $total_tags, $result['total'], 'The total should count the new tag.' );
		$this->assertSame( $total_pages, $result['total_pages'], 'The total pages should grow.' );

		// Last page.
		$result = $this->query_terms(
			array(
				'taxonomy' => 'post_tag',
				'page'     => $total_pages,
			)
		);
		$this->assertSame( $total_tags, $result['total'], 'The total should be the same on the last page.' );
		$this->assertSame( $total_pages, $result['total_pages'], 'The total pages should be the same on the last page.' );

		// Out of bounds.
		$result = $this->query_terms(
			array(
				'taxonomy' => 'post_tag',
				'page'     => 100,
			)
		);
		$this->assertSame( $total_tags, $result['total'], 'The total should be the same past the last page.' );
		$this->assertSame( $total_pages, $result['total_pages'], 'The total pages should be the same past the last page.' );
	}

	/**
	 * A tag is returned by ID.
	 *
	 * @since x.x.x
	 */
	public function test_get_item(): void {
		$id = self::factory()->tag->create();

		$result = $this->query_terms(
			array(
				'id'       => $id,
				'taxonomy' => 'post_tag',
				'fields'   => self::ALL_FIELDS,
			)
		);
		$this->check_taxonomy_term( get_term( $id, 'post_tag' ), $result );
	}

	/**
	 * A missing tag is denied, and a direct call reports it as invalid.
	 *
	 * @since x.x.x
	 */
	public function test_get_term_invalid_term(): void {
		$input = array(
			'id'       => REST_TESTS_IMPOSSIBLY_HIGH_NUMBER,
			'taxonomy' => 'post_tag',
		);

		$this->assertAbilityDenied( $this->query_terms( $input ), 'A missing tag should be denied.' );

		$direct = ( new Terms() )->execute_terms_query( $input );
		$this->assertAbilityError( $direct, 'terms_term_invalid', 'A direct call should report the missing tag.' );
		$this->assertSame( 404, $direct->get_error_data()['status'], 'A missing tag should be not found.' );
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
	 * A term of another taxonomy cannot be read as a tag.
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

		$result = $this->query_terms(
			array(
				'id'       => $term1,
				'taxonomy' => 'post_tag',
			)
		);
		$this->assertAbilityDenied( $result, 'A term of another taxonomy should be denied.' );
	}

	/**
	 * Every field of a queried tag matches the tag.
	 *
	 * @since x.x.x
	 */
	public function test_prepare_item(): void {
		$term = get_term_by( 'id', self::factory()->tag->create(), 'post_tag' );

		$result = $this->query_terms(
			array(
				'taxonomy' => 'post_tag',
				'include'  => array( $term->term_id ),
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
				'id'       => self::factory()->tag->create(),
				'taxonomy' => 'post_tag',
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
	 * The term schema has REST's tag fields except `meta`, plus a `parent` described as
	 * present only for hierarchical taxonomies.
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
		$this->assertArrayHasKey( 'slug', $properties, 'The term schema should have the slug.' );
		$this->assertArrayHasKey( 'taxonomy', $properties, 'The term schema should have the taxonomy.' );
		$this->assertStringContainsString( 'hierarchical', $properties['parent']['description'], 'The parent should be described as present only for hierarchical taxonomies.' );
	}

	/**
	 * A tag never returns a parent, even when every field is requested.
	 *
	 * @since x.x.x
	 */
	public function test_get_item_schema_non_hierarchical(): void {
		$result = $this->query_terms(
			array(
				'id'       => self::factory()->tag->create(),
				'taxonomy' => 'post_tag',
				'fields'   => self::ALL_FIELDS,
			)
		);
		$this->assertArrayHasKey( 'id', $result, 'The tag should have its ID.' );
		$this->assertArrayNotHasKey( 'parent', $result, 'The tag should have no parent.' );
	}

	/**
	 * Querying a post's tags again does not query the database again.
	 *
	 * @since x.x.x
	 */
	public function test_object_term_queries_are_cached(): void {
		$tags = self::factory()->tag->create_many( 2 );
		$p    = self::factory()->post->create();
		wp_set_object_terms( $p, $tags[0], 'post_tag' );

		$input   = array(
			'taxonomy' => 'post_tag',
			'post'     => $p,
		);
		$found_1 = wp_list_pluck( $this->query_terms( $input )['terms'], 'id' );

		$num_queries = get_num_queries();

		$found_2 = wp_list_pluck( $this->query_terms( $input )['terms'], 'id' );

		$this->assertSameSets( $found_1, $found_2, 'The same tags should be returned.' );
		$this->assertSame( $num_queries, get_num_queries(), 'The second query should be served from the cache.' );
	}
}
