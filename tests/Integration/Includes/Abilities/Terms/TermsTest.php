<?php
/**
 * Integration tests for the core/terms-query Ability provided by the plugin.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Terms
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Terms;

use WP_Ability;
use WP_Error;
use WP_UnitTestCase;
use WP_UnitTest_Factory;
use WordPress\AI\Abilities\Terms\Terms;

/**
 * Terms ability test case.
 *
 * @since x.x.x
 */
class TermsTest extends WP_UnitTestCase {

	/**
	 * Hidden (non-viewable) taxonomy exposed to REST.
	 *
	 * @since x.x.x
	 * @var string
	 */
	private const HIDDEN_TAXONOMY = 'terms_ability_hidden';

	/**
	 * Taxonomy not exposed to REST.
	 *
	 * @since x.x.x
	 * @var string
	 */
	private const NO_REST_TAXONOMY = 'terms_ability_no_rest';

	/**
	 * Shared fixture IDs.
	 *
	 * @since x.x.x
	 * @var array<string, int>
	 */
	private static $fixture_ids = array();

	/**
	 * Set up shared test fixtures.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_UnitTest_Factory $factory The WordPress unit test factory.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ): void {
		self::$fixture_ids['administrator'] = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$fixture_ids['editor']        = $factory->user->create( array( 'role' => 'editor' ) );
		self::$fixture_ids['subscriber']    = $factory->user->create( array( 'role' => 'subscriber' ) );

		self::$fixture_ids['parent_cat'] = $factory->category->create(
			array(
				'name' => 'Terms Ability Parent',
				'slug' => 'terms-ability-parent',
			)
		);
		self::$fixture_ids['child_cat']  = $factory->category->create(
			array(
				'name'   => 'Terms Ability Child',
				'slug'   => 'terms-ability-child',
				'parent' => self::$fixture_ids['parent_cat'],
			)
		);

		foreach ( array( 'alpha', 'beta', 'gamma' ) as $name ) {
			self::$fixture_ids[ 'tag_' . $name ] = $factory->tag->create(
				array(
					'name' => 'Terms Ability ' . ucfirst( $name ),
					'slug' => 'terms-ability-' . $name,
				)
			);
		}

		self::$fixture_ids['public_post'] = $factory->post->create(
			array(
				'post_status'   => 'publish',
				'post_category' => array( self::$fixture_ids['child_cat'] ),
				'tags_input'    => array( 'terms-ability-alpha' ),
			)
		);

		self::$fixture_ids['private_post'] = $factory->post->create(
			array(
				'post_status' => 'private',
				'post_author' => self::$fixture_ids['administrator'],
				'tags_input'  => array( 'terms-ability-beta' ),
			)
		);
	}

	/**
	 * Tear down shared test fixtures.
	 *
	 * @since x.x.x
	 */
	public static function wpTearDownAfterClass(): void {
		foreach ( array( 'public_post', 'private_post' ) as $post ) {
			wp_delete_post( self::$fixture_ids[ $post ], true );
		}

		foreach ( array( 'administrator', 'editor', 'subscriber' ) as $user ) {
			wp_delete_user( self::$fixture_ids[ $user ] );
		}

		self::$fixture_ids = array();
	}

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		/*
		 * The plugin registers its other abilities on the same abilities-init hook, so
		 * make sure every category they use exists; a missing one emits an "incorrect
		 * usage" notice that fails these tests.
		 */
		foreach ( array( 'content', 'site', 'user' ) as $category ) {
			$this->ensure_ability_category( $category );
		}

		register_taxonomy(
			self::HIDDEN_TAXONOMY,
			'post',
			array(
				'public'       => false,
				'show_in_rest' => true,
			)
		);
		register_taxonomy(
			self::NO_REST_TAXONOMY,
			'post',
			array(
				'public'       => true,
				'show_in_rest' => false,
			)
		);
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		if ( wp_has_ability( 'core/terms-query' ) ) {
			wp_unregister_ability( 'core/terms-query' );
		}

		unregister_taxonomy( self::HIDDEN_TAXONOMY );
		unregister_taxonomy( self::NO_REST_TAXONOMY );
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Registers an ability category if it is not already registered.
	 *
	 * @since x.x.x
	 *
	 * @param string $slug The category slug.
	 */
	private function ensure_ability_category( string $slug ): void {
		if ( wp_has_ability_category( $slug ) ) {
			return;
		}

		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_categories_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			wp_register_ability_category(
				$slug,
				array(
					'label'       => ucfirst( $slug ),
					'description' => ucfirst( $slug ) . '.',
				)
			);
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Registers the plugin's core/terms-query ability inside a faked init action.
	 *
	 * @since x.x.x
	 */
	private function register_ability(): void {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			( new Terms() )->register();
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Registers the ability as the given fixture user and executes it.
	 *
	 * @since x.x.x
	 *
	 * @param string       $user  Fixture user key, or an empty string for a logged-out request.
	 * @param array<mixed> $input The ability input.
	 * @return mixed The ability result.
	 */
	private function execute_as( string $user, array $input ) {
		wp_set_current_user( '' === $user ? 0 : self::$fixture_ids[ $user ] );
		$this->register_ability();

		return wp_get_ability( 'core/terms-query' )->execute( $input );
	}

	/**
	 * Returns the term IDs from a collection result.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $result The ability result.
	 * @return int[] Term IDs.
	 */
	private function collection_ids( $result ): array {
		$this->assertIsArray( $result, 'The collection request should succeed.' );

		return wp_list_pluck( $result['terms'], 'id' );
	}

	/**
	 * The ability is registered in the `content` category and flagged read-only.
	 *
	 * @since x.x.x
	 */
	public function test_ability_is_registered(): void {
		$this->register_ability();

		$ability = wp_get_ability( 'core/terms-query' );

		$this->assertInstanceOf( WP_Ability::class, $ability, 'The terms ability should be registered.' );
		$this->assertSame( 'content', $ability->get_category(), 'The terms ability should use the content category.' );
		$this->assertTrue( $ability->get_meta_item( 'show_in_rest', false ), 'The terms ability should be exposed over REST.' );

		$annotations = $ability->get_meta_item( 'annotations', array() );
		$this->assertTrue( $annotations['readonly'], 'The terms ability should be marked read-only.' );
		$this->assertFalse( $annotations['destructive'], 'The terms ability should not be marked destructive.' );
		$this->assertTrue( $annotations['idempotent'], 'The terms ability should be marked idempotent.' );
	}

	/**
	 * When core already provides core/terms-query, the plugin's version replaces it.
	 *
	 * @since x.x.x
	 */
	public function test_override_replaces_existing_core_terms_query(): void {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			wp_register_ability(
				'core/terms-query',
				array(
					'label'               => 'Core Provided',
					'description'         => 'Core provided terms ability.',
					'category'            => 'content',
					'execute_callback'    => static function (): array {
						return array();
					},
					'permission_callback' => '__return_true',
				)
			);
		} finally {
			array_pop( $wp_current_filter );
		}

		$this->register_ability();

		$this->assertSame( 'Terms Query', wp_get_ability( 'core/terms-query' )->get_label() );
	}

	/**
	 * Only taxonomies exposed to REST are offered in the input schema.
	 *
	 * @since x.x.x
	 */
	public function test_input_schema_lists_rest_taxonomies_only(): void {
		$this->register_ability();

		$schema     = wp_get_ability( 'core/terms-query' )->get_input_schema();
		$taxonomies = $schema['oneOf'][2]['properties']['taxonomy']['enum'];

		$this->assertContains( 'category', $taxonomies );
		$this->assertContains( 'post_tag', $taxonomies );
		$this->assertContains( self::HIDDEN_TAXONOMY, $taxonomies );
		$this->assertNotContains( self::NO_REST_TAXONOMY, $taxonomies );
	}

	/**
	 * Logged-out requests are denied.
	 *
	 * @since x.x.x
	 */
	public function test_logged_out_request_is_denied(): void {
		$result = $this->execute_as( '', array( 'taxonomy' => 'category' ) );

		$this->assertWPError( $result );
	}

	/**
	 * A single term by ID returns the lean default fields.
	 *
	 * @since x.x.x
	 */
	public function test_single_term_by_id_returns_default_fields(): void {
		$result = $this->execute_as( 'subscriber', array( 'id' => self::$fixture_ids['child_cat'] ) );

		$this->assertSame(
			array(
				'id'       => self::$fixture_ids['child_cat'],
				'name'     => 'Terms Ability Child',
				'slug'     => 'terms-ability-child',
				'taxonomy' => 'category',
				'parent'   => self::$fixture_ids['parent_cat'],
				'count'    => 1,
			),
			$result
		);
	}

	/**
	 * Non-hierarchical terms omit `parent`, matching the REST controller.
	 *
	 * @since x.x.x
	 */
	public function test_non_hierarchical_term_omits_parent(): void {
		$result = $this->execute_as( 'subscriber', array( 'id' => self::$fixture_ids['tag_alpha'] ) );

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 'parent', $result );
		$this->assertSame( 'post_tag', $result['taxonomy'] );
	}

	/**
	 * A single term can be fetched by taxonomy and slug.
	 *
	 * @since x.x.x
	 */
	public function test_single_term_by_slug(): void {
		$result = $this->execute_as(
			'subscriber',
			array(
				'taxonomy' => 'post_tag',
				'slug'     => 'terms-ability-beta',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( self::$fixture_ids['tag_beta'], $result['id'] );
	}

	/**
	 * A slug that does not exist in the taxonomy is denied.
	 *
	 * @since x.x.x
	 */
	public function test_unknown_slug_is_denied(): void {
		$result = $this->execute_as(
			'subscriber',
			array(
				'taxonomy' => 'category',
				'slug'     => 'terms-ability-beta',
			)
		);

		$this->assertWPError( $result );
	}

	/**
	 * An ID lookup with a mismatched taxonomy is denied.
	 *
	 * @since x.x.x
	 */
	public function test_id_with_mismatched_taxonomy_is_denied(): void {
		$result = $this->execute_as(
			'subscriber',
			array(
				'id'       => self::$fixture_ids['tag_alpha'],
				'taxonomy' => 'category',
			)
		);

		$this->assertWPError( $result );
	}

	/**
	 * Terms of a taxonomy not exposed to REST cannot be read.
	 *
	 * @since x.x.x
	 */
	public function test_taxonomy_without_show_in_rest_is_denied(): void {
		$term_id = self::factory()->term->create( array( 'taxonomy' => self::NO_REST_TAXONOMY ) );

		$this->assertWPError( $this->execute_as( 'administrator', array( 'id' => $term_id ) ), 'A single term should be denied.' );
	}

	/**
	 * Terms of a non-viewable taxonomy require the capability to assign them.
	 *
	 * @since x.x.x
	 */
	public function test_hidden_taxonomy_requires_assign_terms(): void {
		$term_id = self::factory()->term->create( array( 'taxonomy' => self::HIDDEN_TAXONOMY ) );

		$this->assertWPError(
			$this->execute_as( 'subscriber', array( 'taxonomy' => self::HIDDEN_TAXONOMY ) ),
			'A subscriber should not list hidden taxonomy terms.'
		);
		$this->assertWPError(
			$this->execute_as( 'subscriber', array( 'id' => $term_id ) ),
			'A subscriber should not read a hidden taxonomy term.'
		);

		$this->assertSame(
			array( $term_id ),
			$this->collection_ids( $this->execute_as( 'editor', array( 'taxonomy' => self::HIDDEN_TAXONOMY ) ) ),
			'An editor can assign the terms, so can list them.'
		);
	}

	/**
	 * Collections report totals across pages.
	 *
	 * @since x.x.x
	 */
	public function test_collection_pagination_totals(): void {
		$input = array(
			'taxonomy' => 'post_tag',
			'search'   => 'Terms Ability',
			'per_page' => 2,
		);

		$first = $this->execute_as( 'subscriber', $input );
		$this->assertSame(
			array( self::$fixture_ids['tag_alpha'], self::$fixture_ids['tag_beta'] ),
			$this->collection_ids( $first ),
			'The first page should hold the first two tags by name.'
		);
		$this->assertSame( 3, $first['total'] );
		$this->assertSame( 2, $first['total_pages'] );

		$second = $this->execute_as( 'subscriber', $input + array( 'page' => 2 ) );
		$this->assertSame( array( self::$fixture_ids['tag_gamma'] ), $this->collection_ids( $second ) );
	}

	/**
	 * Collections accept the string forms REST `GET` delivers.
	 *
	 * @since x.x.x
	 */
	public function test_collection_accepts_rest_string_input(): void {
		$result = $this->execute_as(
			'subscriber',
			array(
				'taxonomy'   => 'post_tag',
				'search'     => 'Terms Ability',
				'hide_empty' => 'true',
				'orderby'    => 'name',
				'order'      => 'desc',
			)
		);

		$this->assertSame( array( self::$fixture_ids['tag_alpha'] ), $this->collection_ids( $result ), 'Only the tag on a published post is non-empty.' );
	}

	/**
	 * Collections can be filtered by parent in hierarchical taxonomies.
	 *
	 * @since x.x.x
	 */
	public function test_collection_parent_filter(): void {
		$children = $this->execute_as(
			'subscriber',
			array(
				'taxonomy' => 'category',
				'parent'   => self::$fixture_ids['parent_cat'],
			)
		);
		$this->assertSame( array( self::$fixture_ids['child_cat'] ), $this->collection_ids( $children ) );

		$top_level = $this->collection_ids(
			$this->execute_as(
				'subscriber',
				array(
					'taxonomy' => 'category',
					'parent'   => 0,
				)
			)
		);
		$this->assertContains( self::$fixture_ids['parent_cat'], $top_level );
		$this->assertNotContains( self::$fixture_ids['child_cat'], $top_level );
	}

	/**
	 * Collections can list the terms attached to a post.
	 *
	 * @since x.x.x
	 */
	public function test_collection_terms_for_public_post(): void {
		$result = $this->execute_as(
			'subscriber',
			array(
				'taxonomy' => 'post_tag',
				'post'     => self::$fixture_ids['public_post'],
			)
		);

		$this->assertSame( array( self::$fixture_ids['tag_alpha'] ), $this->collection_ids( $result ) );
		$this->assertSame( 1, $result['total'] );
	}

	/**
	 * Terms attached to a private post are only readable by users who can read the post.
	 *
	 * @since x.x.x
	 */
	public function test_collection_terms_for_private_post_require_read_access(): void {
		$input = array(
			'taxonomy' => 'post_tag',
			'post'     => self::$fixture_ids['private_post'],
		);

		$this->assertWPError( $this->execute_as( 'subscriber', $input ), 'A subscriber cannot read a private post.' );
		$this->assertSame(
			array( self::$fixture_ids['tag_beta'] ),
			$this->collection_ids( $this->execute_as( 'editor', $input ) ),
			'An editor can read private posts.'
		);
	}

	/**
	 * A post outside the taxonomy's object types is denied.
	 *
	 * @since x.x.x
	 */
	public function test_collection_post_outside_taxonomy_is_denied(): void {
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->assertWPError(
			$this->execute_as(
				'administrator',
				array(
					'taxonomy' => 'category',
					'post'     => $page_id,
				)
			)
		);
	}

	/**
	 * Requested fields are returned, and meta is limited to show_in_rest keys.
	 *
	 * @since x.x.x
	 */
	public function test_fields_subset_and_rest_meta_only(): void {
		register_term_meta(
			'category',
			'terms_ability_public',
			array(
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => true,
			)
		);
		update_term_meta( self::$fixture_ids['parent_cat'], 'terms_ability_public', 'shown' );
		update_term_meta( self::$fixture_ids['parent_cat'], 'terms_ability_private', 'hidden' );

		$result = $this->execute_as(
			'subscriber',
			array(
				'id'     => self::$fixture_ids['parent_cat'],
				'fields' => array( 'name', 'link', 'meta' ),
			)
		);

		unregister_term_meta( 'category', 'terms_ability_public' );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'id', 'name', 'link', 'meta' ), array_keys( $result ), 'id is always included.' );
		$this->assertSame( get_term_link( self::$fixture_ids['parent_cat'] ), $result['link'] );

		$meta = (array) $result['meta'];
		$this->assertSame( 'shown', $meta['terms_ability_public'] ?? null );
		$this->assertArrayNotHasKey( 'terms_ability_private', $meta );
	}

	/**
	 * Mixing single-term and collection arguments is rejected by the input schema.
	 *
	 * @since x.x.x
	 */
	public function test_mixed_modes_are_rejected(): void {
		$result = $this->execute_as(
			'administrator',
			array(
				'id'     => self::$fixture_ids['tag_alpha'],
				'search' => 'alpha',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
	}
}
