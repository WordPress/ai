<?php
/**
 * Integration tests for the core/terms-query Ability provided by the plugin.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Terms
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Terms;

use WP_REST_Request;
use WordPress\AI\Abilities\Terms\Terms;

/**
 * Terms ability test case.
 *
 * Covers what the ability adds to the REST terms endpoints: its modes, fields, exposure,
 * permissions, and string inputs, plus parity checks against the REST responses.
 *
 * @since x.x.x
 */
class TermsTest extends Terms_Ability_TestCase {

	/**
	 * Shared term IDs keyed by fixture name.
	 *
	 * @since x.x.x
	 *
	 * @var array<string, int>
	 */
	private static array $term_ids = array();

	/**
	 * Shared post IDs keyed by fixture name.
	 *
	 * @since x.x.x
	 *
	 * @var array<string, int>
	 */
	private static array $post_ids = array();

	/**
	 * Creates the shared users, terms, and posts for the terms ability tests.
	 *
	 * Fruit is an empty category with a child, Apple, that the published post uses. The
	 * private post and the author's draft use Apple too, and the page uses no category.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_UnitTest_Factory $factory The unit test factory.
	 */
	public static function wpSetUpBeforeClass( $factory ): void {
		parent::wpSetUpBeforeClass( $factory );

		$fruit          = $factory->category->create( array( 'name' => 'Fruit' ) );
		self::$term_ids = array(
			'fruit' => $fruit,
			'apple' => $factory->category->create(
				array(
					'name'        => 'Apple',
					'parent'      => $fruit,
					'description' => 'A <em>crisp</em> fruit.',
				)
			),
			'empty' => $factory->category->create( array( 'name' => 'Empty' ) ),
			'red'   => $factory->tag->create( array( 'name' => 'Red' ) ),
		);

		self::$post_ids = array(
			'published' => $factory->post->create(
				array(
					'post_author'   => self::$user_ids['administrator'],
					'post_category' => array( self::$term_ids['apple'] ),
				)
			),
			'private'   => $factory->post->create(
				array(
					'post_author'   => self::$user_ids['administrator'],
					'post_status'   => 'private',
					'post_category' => array( self::$term_ids['apple'] ),
				)
			),
			'draft'     => $factory->post->create(
				array(
					'post_author'   => self::$user_ids['author'],
					'post_status'   => 'draft',
					'post_category' => array( self::$term_ids['apple'] ),
				)
			),
			'page'      => $factory->post->create( array( 'post_type' => 'page' ) ),
		);

		wp_set_object_terms( self::$post_ids['published'], array( self::$term_ids['red'] ), 'post_tag' );
	}

	/**
	 * The ability is registered in the `content` category and flagged read-only.
	 *
	 * @since x.x.x
	 */
	public function test_registers_core_terms_query_ability(): void {
		$this->register_ability();

		$ability = wp_get_ability( 'core/terms-query' );

		$this->assertNotNull( $ability, 'The core/terms-query ability should be registered.' );
		$this->assertSame( 'Terms Query', $ability->get_label(), 'The registered ability should use the expected label.' );
		$this->assertSame( 'content', $ability->get_category(), 'The registered ability should use the content category.' );
		$this->assertTrue( $ability->get_meta_item( 'show_in_rest', false ), 'The ability should be exposed in REST.' );

		$annotations = $ability->get_meta_item( 'annotations', array() );
		$this->assertTrue( $annotations['readonly'], 'The ability should be marked read-only.' );
		$this->assertFalse( $annotations['destructive'], 'The ability should be marked non-destructive.' );
		$this->assertTrue( $annotations['idempotent'], 'The ability should be marked idempotent.' );
		$this->assertFalse( $annotations['open_world'], 'The ability should be marked closed-world; it only reads the local database.' );
	}

	/**
	 * The ability is not registered when no taxonomies are exposed to it.
	 *
	 * @since x.x.x
	 */
	public function test_does_not_register_core_terms_query_ability_without_exposed_taxonomies(): void {
		foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
			get_taxonomy( $taxonomy )->show_in_abilities = false;
		}

		$this->register_ability();

		$this->assertFalse( wp_has_ability( 'core/terms-query' ), 'The ability should not register without any exposed taxonomies.' );
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
					'execute_callback'    => '__return_empty_array',
					'permission_callback' => '__return_true',
				)
			);
		} finally {
			array_pop( $wp_current_filter );
		}

		$this->register_ability();

		$this->assertSame( 'Terms Query', wp_get_ability( 'core/terms-query' )->get_label(), 'The plugin-provided ability should replace the existing one.' );
	}

	/**
	 * The input schema models mutually exclusive ID, slug, and query modes, each rejecting
	 * the other modes' properties and accepting only exposed taxonomies.
	 *
	 * @since x.x.x
	 */
	public function test_input_schema_models_mutually_exclusive_modes(): void {
		$this->register_ability();

		$ability = wp_get_ability( 'core/terms-query' );
		$schema  = $ability->get_input_schema();

		$this->assertSame( 'object', $schema['type'], 'The input schema should describe an object.' );
		$this->assertCount( 3, $schema['oneOf'], 'The input schema should expose exactly three modes.' );

		[ $by_id, $by_slug, $query ] = $schema['oneOf'];

		$this->assertSame( array( 'id' ), $by_id['required'], 'The by-ID mode should require an ID.' );
		$this->assertSame( array( 'taxonomy', 'slug' ), $by_slug['required'], 'The slug mode should require a taxonomy and a slug.' );
		$this->assertSame( array( 'taxonomy' ), $query['required'], 'The query mode should require a taxonomy.' );
		$this->assertSame( array( 'id', 'taxonomy', 'fields' ), array_keys( $by_id['properties'] ), 'The by-ID mode should take an ID, a taxonomy guard, and fields.' );
		$this->assertSame( array( 'taxonomy', 'slug', 'fields' ), array_keys( $by_slug['properties'] ), 'The slug mode should take a taxonomy, a slug, and fields.' );
		$this->assertArrayNotHasKey( 'slug', $query['properties'], 'The query mode should not take a slug; a slug is a single-term mode.' );

		foreach ( $schema['oneOf'] as $mode ) {
			$this->assertFalse( $mode['additionalProperties'], "The {$mode['title']} mode should reject unrelated properties." );
			$this->assertSame( array( 'category', 'post_tag' ), $mode['properties']['taxonomy']['enum'], "The {$mode['title']} mode should take only the exposed taxonomies." );
			$this->assertSame(
				array_keys( $ability->get_output_schema()['oneOf'][0]['properties'] ),
				$mode['properties']['fields']['items']['enum'],
				"The {$mode['title']} mode should take the fields of the term schema."
			);
		}
	}

	/**
	 * Branch-local defaults are omitted so the schema can compile in the client-side
	 * Abilities API validator. The ability applies the defaults itself.
	 *
	 * @since x.x.x
	 */
	public function test_input_schema_omits_oneof_branch_defaults(): void {
		$this->register_ability();

		foreach ( wp_get_ability( 'core/terms-query' )->get_input_schema()['oneOf'] as $mode ) {
			foreach ( $mode['properties'] as $name => $property ) {
				$this->assertArrayNotHasKey( 'default', $property, "The {$name} property of the {$mode['title']} mode should rely on runtime defaults." );
			}
		}
	}

	/**
	 * The output schema describes single-term and query response shapes.
	 *
	 * @since x.x.x
	 */
	public function test_output_schema_describes_single_term_and_query_responses(): void {
		$this->register_ability();

		$schema = wp_get_ability( 'core/terms-query' )->get_output_schema();

		$this->assertSame( 'object', $schema['type'], 'The output schema should describe object responses.' );
		$this->assertCount( 2, $schema['oneOf'], 'The output schema should describe single-term and query responses.' );

		[ $term, $query ] = $schema['oneOf'];

		$this->assertSame( array( 'id' ), $term['required'], 'Every term includes its ID, which also keeps a term from matching the query shape.' );
		$this->assertArrayNotHasKey( 'additionalProperties', $term, 'The term schema should not reject unknown properties.' );
		$this->assertArrayNotHasKey( 'format', $term['properties']['link'], 'The link should have no format, which the client-side validator cannot compile.' );
		$this->assertSame( array( 'terms', 'total', 'total_pages' ), $query['required'], 'The query wrapper should require all top-level properties.' );
		$this->assertSame( $term, $query['properties']['terms']['items'], 'The query wrapper should list terms.' );
	}

	/**
	 * A term is returned directly by ID, without a taxonomy.
	 *
	 * @since x.x.x
	 */
	public function test_get_term_by_id_without_taxonomy(): void {
		$this->login_as( 'subscriber' );
		$this->register_ability();

		$this->assertSame(
			array(
				'id'       => self::$term_ids['apple'],
				'count'    => 1,
				'name'     => 'Apple',
				'slug'     => 'apple',
				'taxonomy' => 'category',
				'parent'   => self::$term_ids['fruit'],
			),
			$this->query_terms( array( 'id' => self::$term_ids['apple'] ) ),
			'The term should be returned directly.'
		);
	}

	/**
	 * `taxonomy` is accepted alongside `id` as a guard that the term must match.
	 *
	 * @since x.x.x
	 */
	public function test_id_mode_accepts_taxonomy_guard(): void {
		$this->login_as( 'subscriber' );
		$this->register_ability();

		$matching = $this->query_terms(
			array(
				'id'       => self::$term_ids['apple'],
				'taxonomy' => 'category',
			)
		);
		$this->assertSame( self::$term_ids['apple'], $matching['id'], 'A matching taxonomy guard should return the term.' );

		$mismatched = $this->query_terms(
			array(
				'id'       => self::$term_ids['apple'],
				'taxonomy' => 'post_tag',
			)
		);
		$this->assertAbilityDenied( $mismatched, 'A mismatched taxonomy guard should deny the term.' );
	}

	/**
	 * A slug is looked up in the requested taxonomy only.
	 *
	 * @since x.x.x
	 */
	public function test_get_term_by_slug_is_scoped_to_the_taxonomy(): void {
		$tag = self::factory()->tag->create(
			array(
				'name' => 'Apple',
				'slug' => 'apple',
			)
		);
		$this->login_as( 'subscriber' );
		$this->register_ability();

		$category = $this->query_terms(
			array(
				'taxonomy' => 'category',
				'slug'     => 'apple',
			)
		);
		$this->assertSame( self::$term_ids['apple'], $category['id'], 'The category with the slug should be returned.' );

		$post_tag = $this->query_terms(
			array(
				'taxonomy' => 'post_tag',
				'slug'     => 'apple',
			)
		);
		$this->assertSame( $tag, $post_tag['id'], 'The tag with the slug should be returned.' );
	}

	/**
	 * A missing slug is denied, and a direct call reports it as invalid.
	 *
	 * @since x.x.x
	 */
	public function test_get_term_by_missing_slug_is_denied(): void {
		$this->login_as( 'subscriber' );
		$this->register_ability();

		$input = array(
			'taxonomy' => 'category',
			'slug'     => 'missing',
		);

		$this->assertAbilityDenied( $this->query_terms( $input ), 'A missing slug should be denied.' );
		$this->assertAbilityError( ( new Terms() )->execute_terms_query( $input ), 'terms_term_invalid', 'A direct call should report the missing slug.' );
	}

	/**
	 * Query-mode filters cannot be combined with a by-ID lookup.
	 *
	 * @since x.x.x
	 */
	public function test_id_mode_rejects_query_only_params(): void {
		$this->login_as( 'subscriber' );
		$this->register_ability();

		$result = $this->query_terms(
			array(
				'id'       => self::$term_ids['apple'],
				'per_page' => 10,
			)
		);
		$this->assertAbilityError( $result, 'ability_invalid_input', 'Combining the by-ID mode with query-only params should fail validation.' );
	}

	/**
	 * Query-mode filters cannot be combined with a slug lookup.
	 *
	 * @since x.x.x
	 */
	public function test_slug_mode_rejects_query_only_params(): void {
		$this->login_as( 'subscriber' );
		$this->register_ability();

		$result = $this->query_terms(
			array(
				'taxonomy' => 'category',
				'slug'     => 'apple',
				'search'   => 'App',
			)
		);
		$this->assertAbilityError( $result, 'ability_invalid_input', 'Combining the slug mode with query-only params should fail validation.' );
	}

	/**
	 * A slug lookup needs a taxonomy.
	 *
	 * @since x.x.x
	 */
	public function test_slug_mode_requires_taxonomy(): void {
		$this->login_as( 'subscriber' );
		$this->register_ability();

		$this->assertAbilityError( $this->query_terms( array( 'slug' => 'apple' ) ), 'ability_invalid_input', 'A slug without a taxonomy should fail validation.' );
	}

	/**
	 * An ID and a slug cannot be combined.
	 *
	 * @since x.x.x
	 */
	public function test_modes_cannot_be_mixed(): void {
		$this->login_as( 'subscriber' );
		$this->register_ability();

		$result = $this->query_terms(
			array(
				'id'       => self::$term_ids['apple'],
				'taxonomy' => 'category',
				'slug'     => 'apple',
			)
		);
		$this->assertAbilityError( $result, 'ability_invalid_input', 'Combining an ID and a slug should fail validation.' );
	}

	/**
	 * A query needs a taxonomy.
	 *
	 * @since x.x.x
	 */
	public function test_query_mode_requires_taxonomy(): void {
		$this->login_as( 'subscriber' );
		$this->register_ability();

		$this->assertAbilityError( $this->query_terms( array() ), 'ability_invalid_input', 'An empty input should fail validation.' );
		$this->assertAbilityError( $this->query_terms( array( 'search' => 'App' ) ), 'ability_invalid_input', 'A query without a taxonomy should fail validation.' );
	}

	/**
	 * Returns REST parameters and fields the ability does not take.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{0: array<string, mixed>}> Input fragments.
	 */
	public function data_rest_only_params(): array {
		return array(
			'context'       => array( array( 'context' => 'edit' ) ),
			'offset'        => array( array( 'offset' => 1 ) ),
			'_fields'       => array( array( '_fields' => 'id' ) ),
			'slug list'     => array( array( 'slug' => array( 'apple', 'fruit' ) ) ),
			'include_slugs' => array( array( 'orderby' => 'include_slugs' ) ),
			'meta field'    => array( array( 'fields' => array( 'meta' ) ) ),
		);
	}

	/**
	 * REST parameters and fields the ability leaves out are rejected, not ignored.
	 *
	 * @dataProvider data_rest_only_params
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $params The REST-only input.
	 */
	public function test_query_mode_rejects_rest_only_params( array $params ): void {
		$this->login_as( 'subscriber' );
		$this->register_ability();

		$result = $this->query_terms( array_merge( array( 'taxonomy' => 'category' ), $params ) );
		$this->assertAbilityError( $result, 'ability_invalid_input', 'A REST-only parameter should fail validation.' );
	}

	/**
	 * `per_page` is limited to 100, like REST.
	 *
	 * @since x.x.x
	 */
	public function test_per_page_is_limited_to_100(): void {
		$this->login_as( 'subscriber' );
		$this->register_ability();

		$this->assertIsArray(
			$this->query_terms(
				array(
					'taxonomy' => 'category',
					'per_page' => 100,
				)
			),
			'A page size of 100 should be accepted.'
		);
		$this->assertAbilityError(
			$this->query_terms(
				array(
					'taxonomy' => 'category',
					'per_page' => 101,
				)
			),
			'ability_invalid_input',
			'A page size above 100 should fail validation.'
		);
	}

	/**
	 * Without `fields`, terms carry a lean default set, and `parent` only for hierarchical
	 * taxonomies.
	 *
	 * @since x.x.x
	 */
	public function test_default_fields(): void {
		$this->login_as( 'subscriber' );
		$this->register_ability();

		$category_fields = array( 'id', 'count', 'name', 'slug', 'taxonomy', 'parent' );
		$this->assertSame( $category_fields, array_keys( $this->query_terms( array( 'id' => self::$term_ids['apple'] ) ) ), 'A category should have the default fields.' );
		$this->assertSame( $category_fields, array_keys( $this->query_terms( array( 'taxonomy' => 'category' ) )['terms'][0] ), 'A queried category should have the default fields.' );
		$this->assertSame( array( 'id', 'count', 'name', 'slug', 'taxonomy' ), array_keys( $this->query_terms( array( 'id' => self::$term_ids['red'] ) ) ), 'A tag should have the default fields except the parent.' );
	}

	/**
	 * `fields` limits every term to the requested fields, always with the ID.
	 *
	 * @since x.x.x
	 */
	public function test_fields_always_include_id(): void {
		$this->login_as( 'subscriber' );
		$this->register_ability();

		$this->assertSame(
			array(
				'id'   => self::$term_ids['apple'],
				'name' => 'Apple',
			),
			$this->query_terms(
				array(
					'taxonomy' => 'category',
					'slug'     => 'apple',
					'fields'   => array( 'name' ),
				)
			),
			'A single term should carry the requested fields and its ID.'
		);

		$result = $this->query_terms(
			array(
				'taxonomy' => 'category',
				'fields'   => array( 'slug' ),
			)
		);
		foreach ( $result['terms'] as $term ) {
			$this->assertSame( array( 'id', 'slug' ), array_keys( $term ), 'Each queried term should carry the requested fields and its ID.' );
		}
	}

	/**
	 * Unknown and repeated fields are rejected.
	 *
	 * @since x.x.x
	 */
	public function test_fields_are_validated(): void {
		$this->login_as( 'subscriber' );
		$this->register_ability();

		$input = array( 'id' => self::$term_ids['apple'] );

		$this->assertAbilityError( $this->query_terms( $input + array( 'fields' => array( 'bogus' ) ) ), 'ability_invalid_input', 'An unknown field should fail validation.' );
		$this->assertAbilityError( $this->query_terms( $input + array( 'fields' => array( 'name', 'name' ) ) ), 'ability_invalid_input', 'A repeated field should fail validation.' );
	}

	/**
	 * Terms of a non-hierarchical taxonomy have no parent, even when it is requested.
	 *
	 * @since x.x.x
	 */
	public function test_parent_field_is_omitted_for_non_hierarchical_taxonomies(): void {
		$this->login_as( 'subscriber' );
		$this->register_ability();

		$result = $this->query_terms(
			array(
				'id'     => self::$term_ids['red'],
				'fields' => array( 'parent' ),
			)
		);
		$this->assertSame( array( 'id' => self::$term_ids['red'] ), $result, 'A tag should have no parent.' );
	}

	/**
	 * The description is returned as stored, and the link is the term's archive link.
	 *
	 * @since x.x.x
	 */
	public function test_description_and_link_fields(): void {
		$this->login_as( 'subscriber' );
		$this->register_ability();

		$result = $this->query_terms(
			array(
				'id'     => self::$term_ids['apple'],
				'fields' => array( 'description', 'link' ),
			)
		);
		$this->assertSame( 'A <em>crisp</em> fruit.', $result['description'], 'The description should be returned as stored.' );
		$this->assertSame( get_term_link( self::$term_ids['apple'], 'category' ), $result['link'], 'The link should be the term archive link.' );
	}

	/**
	 * `parent` is ignored for taxonomies that are not hierarchical, like REST.
	 *
	 * @since x.x.x
	 */
	public function test_parent_filter_is_ignored_for_non_hierarchical_taxonomies(): void {
		$this->login_as( 'subscriber' );
		$this->register_ability();

		$this->assertSame(
			$this->query_terms( array( 'taxonomy' => 'post_tag' ) ),
			$this->query_terms(
				array(
					'taxonomy' => 'post_tag',
					'parent'   => self::$term_ids['fruit'],
				)
			),
			'A parent filter should not change the tags returned.'
		);
	}

	/**
	 * Like REST, `hide_empty` keeps an empty parent of a term with posts, but the total
	 * leaves it out.
	 *
	 * @since x.x.x
	 */
	public function test_hide_empty_keeps_empty_parents_of_non_empty_terms(): void {
		$this->login_as( 'subscriber' );
		$this->register_ability();

		$result = $this->query_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => true,
			)
		);
		$this->assertSame( array( self::$term_ids['apple'], self::$term_ids['fruit'] ), wp_list_pluck( $result['terms'], 'id' ), 'The category with posts and its empty parent should be returned.' );
		$this->assertSame( 1, $result['total'], 'The total should count only the category with posts.' );
	}

	/**
	 * String inputs, as a GET request delivers them on WordPress 7.0, give the same results
	 * as typed inputs.
	 *
	 * @since x.x.x
	 */
	public function test_string_inputs_match_typed_inputs(): void {
		$this->login_as( 'subscriber' );
		$this->register_ability();

		$apple = self::$term_ids['apple'];
		$fruit = self::$term_ids['fruit'];
		$post  = self::$post_ids['published'];

		$cases = array(
			'id'               => array( array( 'id' => (string) $apple ), array( 'id' => $apple ) ),
			'page and size'    => array(
				array(
					'taxonomy' => 'category',
					'page'     => '2',
					'per_page' => '1',
				),
				array(
					'taxonomy' => 'category',
					'page'     => 2,
					'per_page' => 1,
				),
			),
			'hide_empty true'  => array(
				array(
					'taxonomy'   => 'category',
					'hide_empty' => 'true',
				),
				array(
					'taxonomy'   => 'category',
					'hide_empty' => true,
				),
			),
			'hide_empty 1'     => array(
				array(
					'taxonomy'   => 'category',
					'hide_empty' => '1',
				),
				array(
					'taxonomy'   => 'category',
					'hide_empty' => true,
				),
			),
			'hide_empty false' => array(
				array(
					'taxonomy'   => 'category',
					'hide_empty' => 'false',
				),
				array(
					'taxonomy'   => 'category',
					'hide_empty' => false,
				),
			),
			'hide_empty 0'     => array(
				array(
					'taxonomy'   => 'category',
					'hide_empty' => '0',
				),
				array(
					'taxonomy'   => 'category',
					'hide_empty' => false,
				),
			),
			'include'          => array(
				array(
					'taxonomy' => 'category',
					'include'  => "{$fruit},{$apple}",
					'orderby'  => 'include',
				),
				array(
					'taxonomy' => 'category',
					'include'  => array( $fruit, $apple ),
					'orderby'  => 'include',
				),
			),
			'excluded IDs'     => array(
				array(
					'taxonomy' => 'category',
					'exclude'  => (string) $apple, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Term query argument.
				),
				array(
					'taxonomy' => 'category',
					'exclude'  => array( $apple ), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Term query argument.
				),
			),
			'parent'           => array(
				array(
					'taxonomy' => 'category',
					'parent'   => (string) $fruit,
				),
				array(
					'taxonomy' => 'category',
					'parent'   => $fruit,
				),
			),
			'parent 0'         => array(
				array(
					'taxonomy' => 'category',
					'parent'   => '0',
				),
				array(
					'taxonomy' => 'category',
					'parent'   => 0,
				),
			),
			'post'             => array(
				array(
					'taxonomy' => 'category',
					'post'     => (string) $post,
				),
				array(
					'taxonomy' => 'category',
					'post'     => $post,
				),
			),
			'fields'           => array(
				array(
					'id'     => $apple,
					'fields' => 'id,name',
				),
				array(
					'id'     => $apple,
					'fields' => array( 'id', 'name' ),
				),
			),
		);

		foreach ( $cases as $name => [ $string_input, $typed_input ] ) {
			$expected = $this->query_terms( $typed_input );
			$this->assertIsArray( $expected, "The typed {$name} input should succeed." );
			$this->assertSame( $expected, $this->query_terms( $string_input ), "The string {$name} input should give the same result as the typed input." );
		}

		// The string inputs take effect, rather than matching an unfiltered query.
		$this->assertSame( array( $fruit, $apple ), wp_list_pluck( $this->query_terms( $cases['include'][0] )['terms'], 'id' ), 'The string include should limit and order the terms.' );
		$this->assertNotContains( self::$term_ids['empty'], wp_list_pluck( $this->query_terms( $cases['hide_empty true'][0] )['terms'], 'id' ), 'The string hide_empty should hide empty terms.' );
		$this->assertCount( 1, $this->query_terms( $cases['page and size'][0] )['terms'], 'The string page size should limit the terms.' );
	}

	/**
	 * Returns the roles from administrator to a logged-out visitor, with whether they may
	 * read terms.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{0: string, 1: bool}> The role, or an empty string for a logged-out visitor, and whether it may read terms.
	 */
	public function data_roles(): array {
		return array(
			'administrator' => array( 'administrator', true ),
			'editor'        => array( 'editor', true ),
			'author'        => array( 'author', true ),
			'contributor'   => array( 'contributor', true ),
			'subscriber'    => array( 'subscriber', true ),
			'logged out'    => array( '', false ),
		);
	}

	/**
	 * Every logged-in role can read terms in every mode, and a logged-out visitor cannot.
	 *
	 * @dataProvider data_roles
	 *
	 * @since x.x.x
	 *
	 * @param string $role    The role, or an empty string for a logged-out visitor.
	 * @param bool   $allowed Whether the role may read terms.
	 */
	public function test_roles_reading_terms( string $role, bool $allowed ): void {
		if ( '' !== $role ) {
			$this->login_as( $role );
		}
		$this->register_ability();

		$inputs = array(
			'by ID'   => array( 'id' => self::$term_ids['apple'] ),
			'by slug' => array(
				'taxonomy' => 'category',
				'slug'     => 'apple',
			),
			'query'   => array( 'taxonomy' => 'post_tag' ),
		);

		foreach ( $inputs as $mode => $input ) {
			$result = $this->query_terms( $input );
			if ( $allowed ) {
				$this->assertIsArray( $result, "The role should read terms {$mode}." );
			} else {
				$this->assertAbilityDenied( $result, "A logged-out visitor should not read terms {$mode}." );
			}
		}
	}

	/**
	 * A taxonomy that opts in is exposed even when it is not shown in REST.
	 *
	 * @since x.x.x
	 */
	public function test_custom_taxonomy_without_rest_is_exposed_by_opt_in(): void {
		$this->register_test_taxonomy(
			'wpai_genre',
			'post',
			array(
				'show_in_abilities' => true,
				'show_in_rest'      => false,
			)
		);
		$term = self::factory()->term->create( array( 'taxonomy' => 'wpai_genre' ) );
		$this->login_as( 'subscriber' );
		$this->register_ability();

		$this->assertContains( 'wpai_genre', wp_get_ability( 'core/terms-query' )->get_input_schema()['oneOf'][2]['properties']['taxonomy']['enum'], 'The taxonomy should be accepted.' );
		$this->assertSame( array( $term ), wp_list_pluck( $this->query_terms( array( 'taxonomy' => 'wpai_genre' ) )['terms'], 'id' ), 'The terms of the taxonomy should be returned.' );
		$this->assertSame( 'wpai_genre', $this->query_terms( array( 'id' => $term ) )['taxonomy'], 'A term of the taxonomy should be returned by ID.' );
	}

	/**
	 * The ability names a taxonomy by its key, whatever its REST base and namespace.
	 *
	 * @since x.x.x
	 */
	public function test_custom_taxonomy_is_named_by_its_key_not_its_rest_route(): void {
		$this->register_test_taxonomy(
			'wpai_genre',
			'post',
			array(
				'show_in_abilities' => true,
				'show_in_rest'      => true,
				'rest_base'         => 'genres',
				'rest_namespace'    => 'wpai/v1',
			)
		);
		$term = self::factory()->term->create( array( 'taxonomy' => 'wpai_genre' ) );
		$this->login_as( 'subscriber' );
		$this->register_ability();

		$this->assertSame( array( $term ), wp_list_pluck( $this->query_terms( array( 'taxonomy' => 'wpai_genre' ) )['terms'], 'id' ), 'The taxonomy key should query the terms.' );
		$this->assertAbilityError( $this->query_terms( array( 'taxonomy' => 'genres' ) ), 'ability_invalid_input', 'The REST base should not name the taxonomy.' );
	}

	/**
	 * A non-public taxonomy that opts in is exposed.
	 *
	 * @since x.x.x
	 */
	public function test_non_public_taxonomy_is_exposed_by_opt_in(): void {
		$this->register_test_taxonomy(
			'wpai_internal',
			'post',
			array(
				'public'            => false,
				'show_in_abilities' => true,
			)
		);
		$term = self::factory()->term->create( array( 'taxonomy' => 'wpai_internal' ) );
		$this->login_as( 'subscriber' );
		$this->register_ability();

		$this->assertSame( $term, $this->query_terms( array( 'id' => $term ) )['id'], 'A term of the taxonomy should be returned.' );
	}

	/**
	 * Returns the registration arguments of taxonomies that are not exposed to abilities.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{0: array<string, mixed>}> Taxonomy registration arguments.
	 */
	public function data_unexposed_taxonomy_args(): array {
		return array(
			'shown in REST only' => array( array( 'show_in_rest' => true ) ),
			'opted out'          => array(
				array(
					'show_in_rest'      => true,
					'show_in_abilities' => false,
				),
			),
		);
	}

	/**
	 * A taxonomy that does not opt in is not exposed, and its terms are denied like missing ones.
	 *
	 * @dataProvider data_unexposed_taxonomy_args
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $args The taxonomy registration arguments.
	 */
	public function test_taxonomy_without_opt_in_is_not_exposed( array $args ): void {
		$this->register_test_taxonomy( 'wpai_genre', 'post', $args );
		$term = self::factory()->term->create( array( 'taxonomy' => 'wpai_genre' ) );
		$this->login_as( 'administrator' );
		$this->register_ability();

		$this->assertAbilityError( $this->query_terms( array( 'taxonomy' => 'wpai_genre' ) ), 'ability_invalid_input', 'The taxonomy should not be accepted.' );
		$this->assertAbilityDenied( $this->query_terms( array( 'id' => $term ) ), 'A term of the taxonomy should be denied.' );
	}

	/**
	 * Menus are terms of `nav_menu`, which is not exposed.
	 *
	 * @since x.x.x
	 */
	public function test_nav_menu_terms_are_not_exposed(): void {
		$menu = wp_create_nav_menu( 'Main' );
		$this->login_as( 'administrator' );
		$this->register_ability();

		$this->assertAbilityDenied( $this->query_terms( array( 'id' => $menu ) ), 'A menu should be denied.' );
		$this->assertAbilityError( $this->query_terms( array( 'taxonomy' => 'nav_menu' ) ), 'ability_invalid_input', 'Menus should not be queryable.' );
	}

	/**
	 * A term of a hidden taxonomy is denied exactly like a missing term, so its existence
	 * cannot be probed.
	 *
	 * @since x.x.x
	 */
	public function test_hidden_and_missing_terms_are_denied_alike(): void {
		$this->register_test_taxonomy( 'wpai_secret', 'post', array( 'show_in_rest' => true ) );
		$hidden = self::factory()->term->create( array( 'taxonomy' => 'wpai_secret' ) );
		$this->login_as( 'administrator' );
		$this->register_ability();

		$missing_result = $this->query_terms( array( 'id' => REST_TESTS_IMPOSSIBLY_HIGH_NUMBER ) );
		$hidden_result  = $this->query_terms( array( 'id' => $hidden ) );
		$this->assertAbilityDenied( $missing_result, 'A missing term should be denied.' );
		$this->assertAbilityDenied( $hidden_result, 'A hidden term should be denied.' );
		$this->assertSame( $missing_result->get_error_message(), $hidden_result->get_error_message(), 'A hidden term should be denied like a missing one.' );

		$terms = new Terms();
		$this->assertEquals(
			$terms->execute_terms_query( array( 'id' => REST_TESTS_IMPOSSIBLY_HIGH_NUMBER ) ),
			$terms->execute_terms_query( array( 'id' => $hidden ) ),
			'A direct call should report a hidden term exactly like a missing one.'
		);
	}

	/**
	 * The terms of a private post need read access to the post.
	 *
	 * @since x.x.x
	 */
	public function test_post_filter_on_private_post_requires_read_access(): void {
		$this->register_ability();

		$input = array(
			'taxonomy' => 'category',
			'post'     => self::$post_ids['private'],
		);

		$this->login_as( 'subscriber' );
		$result = $this->query_terms( $input );
		$this->assertAbilityError( $result, 'terms_forbidden_context', 'A subscriber should not read the terms of a private post.' );
		$this->assertSame( 403, $result->get_error_data()['status'], 'The error should be forbidden.' );

		$this->login_as( 'editor' );
		$this->assertSame( array( self::$term_ids['apple'] ), wp_list_pluck( $this->query_terms( $input )['terms'], 'id' ), 'An editor should read the terms of a private post.' );
	}

	/**
	 * The terms of a draft need read access to the draft.
	 *
	 * @since x.x.x
	 */
	public function test_post_filter_on_draft_requires_read_access(): void {
		$this->register_ability();

		$input = array(
			'taxonomy' => 'category',
			'post'     => self::$post_ids['draft'],
		);

		foreach ( array( 'subscriber', 'contributor' ) as $role ) {
			$this->login_as( $role );
			$this->assertAbilityError( $this->query_terms( $input ), 'terms_forbidden_context', "A {$role} should not read the terms of another user's draft." );
		}

		foreach ( array( 'author', 'editor' ) as $role ) {
			$this->login_as( $role );
			$this->assertSame( array( self::$term_ids['apple'] ), wp_list_pluck( $this->query_terms( $input )['terms'], 'id' ), "The {$role} should read the terms of the draft." );
		}
	}

	/**
	 * The terms of a post whose type does not use the taxonomy are forbidden.
	 *
	 * @since x.x.x
	 */
	public function test_post_filter_on_post_type_without_the_taxonomy_is_forbidden(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$result = $this->query_terms(
			array(
				'taxonomy' => 'category',
				'post'     => self::$post_ids['page'],
			)
		);
		$this->assertAbilityError( $result, 'terms_forbidden_context', 'Pages do not use categories.' );
	}

	/**
	 * A missing post is reported as invalid.
	 *
	 * @since x.x.x
	 */
	public function test_post_filter_on_missing_post_is_invalid(): void {
		$this->login_as( 'subscriber' );
		$this->register_ability();

		$result = $this->query_terms(
			array(
				'taxonomy' => 'category',
				'post'     => REST_TESTS_IMPOSSIBLY_HIGH_NUMBER,
			)
		);
		$this->assertAbilityError( $result, 'terms_post_invalid_id', 'A missing post should be reported as invalid.' );
		$this->assertSame( 400, $result->get_error_data()['status'], 'The error should be a bad request.' );
	}

	/**
	 * Like REST, the post's type does not need to be exposed: only the post's own read
	 * access counts.
	 *
	 * @since x.x.x
	 */
	public function test_post_filter_does_not_require_the_post_type_to_be_exposed(): void {
		register_post_type(
			'wpai_book',
			array(
				'public'     => true,
				'taxonomies' => array( 'category' ),
			)
		);

		try {
			$book = self::factory()->post->create(
				array(
					'post_type'     => 'wpai_book',
					'post_category' => array( self::$term_ids['apple'] ),
				)
			);
			$this->login_as( 'subscriber' );
			$this->register_ability();

			$result = $this->query_terms(
				array(
					'taxonomy' => 'category',
					'post'     => $book,
				)
			);
			$this->assertSame( array( self::$term_ids['apple'] ), wp_list_pluck( $result['terms'], 'id' ), 'The terms of a readable post should be returned.' );
		} finally {
			unregister_post_type( 'wpai_book' );
		}
	}

	/**
	 * The permission callback requires a logged-in user and leaves `post` errors to the
	 * execute callback, which can report them.
	 *
	 * @since x.x.x
	 */
	public function test_permission_callback_requires_a_logged_in_user(): void {
		$terms = new Terms();
		$input = array(
			'taxonomy' => 'category',
			'post'     => REST_TESTS_IMPOSSIBLY_HIGH_NUMBER,
		);

		$this->assertFalse( $terms->check_permission( $input ), 'A logged-out visitor should be denied.' );

		$this->login_as( 'subscriber' );
		$this->assertTrue( $terms->check_permission( $input ), 'A post error should be left to the execute callback.' );
	}

	/**
	 * A direct call for a hidden taxonomy fails closed.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_reports_a_hidden_taxonomy_as_forbidden(): void {
		$this->login_as( 'administrator' );

		$terms = new Terms();
		$input = array( 'taxonomy' => 'nav_menu' );

		$this->assertFalse( $terms->check_permission( $input ), 'A hidden taxonomy should be denied.' );

		$result = $terms->execute_terms_query( $input );
		$this->assertAbilityError( $result, 'terms_forbidden', 'A direct call should fail closed.' );
		$this->assertSame( 403, $result->get_error_data()['status'], 'The error should be forbidden.' );
	}

	/**
	 * The run endpoint takes the input from the query string of a GET request, and rejects
	 * other methods.
	 *
	 * @since x.x.x
	 */
	public function test_run_endpoint_takes_query_string_input(): void {
		$this->login_as( 'subscriber' );
		$this->register_ability();

		$route   = '/wp-abilities/v1/abilities/core/terms-query/run';
		$request = new WP_REST_Request( 'GET', $route );
		$request->set_query_params(
			array(
				'input' => array(
					'taxonomy'   => 'category',
					'include'    => self::$term_ids['apple'] . ',' . self::$term_ids['fruit'],
					'orderby'    => 'include',
					'hide_empty' => 'false',
					'per_page'   => '1',
					'fields'     => 'id,name',
				),
			)
		);
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status(), 'A GET request should run the ability.' );
		$this->assertSame(
			array(
				'terms'       => array(
					array(
						'id'   => self::$term_ids['apple'],
						'name' => 'Apple',
					),
				),
				'total'       => 2,
				'total_pages' => 2,
			),
			$response->get_data(),
			'The query string input should be applied.'
		);

		$request = new WP_REST_Request( 'POST', $route );
		$request->set_body_params( array( 'input' => array( 'taxonomy' => 'category' ) ) );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 405, $response->get_status(), 'A POST request should be rejected.' );
		$this->assertSame( 'rest_ability_invalid_method', $response->as_error()->get_error_code(), 'A read-only ability should require GET.' );
	}

	/**
	 * Returns the curated taxonomies with their REST routes.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{0: string, 1: string}> The taxonomy and its REST route.
	 */
	public function data_rest_routes(): array {
		return array(
			'categories' => array( 'category', '/wp/v2/categories' ),
			'tags'       => array( 'post_tag', '/wp/v2/tags' ),
		);
	}

	/**
	 * Every term field is a REST field of the same type. Tags, like REST, have no parent.
	 *
	 * @dataProvider data_rest_routes
	 *
	 * @since x.x.x
	 *
	 * @param string $taxonomy The taxonomy.
	 * @param string $route    The taxonomy's REST route.
	 */
	public function test_term_fields_are_rest_fields( string $taxonomy, string $route ): void {
		$this->register_ability();

		$rest_fields = get_taxonomy( $taxonomy )->get_rest_controller()->get_public_item_schema()['properties'];
		$fields      = wp_get_ability( 'core/terms-query' )->get_output_schema()['oneOf'][0]['properties'];
		if ( ! is_taxonomy_hierarchical( $taxonomy ) ) {
			unset( $fields['parent'] );
		}

		foreach ( $fields as $name => $schema ) {
			$this->assertArrayHasKey( $name, $rest_fields, "The {$name} field should be a REST field." );
			$this->assertSame( $rest_fields[ $name ]['type'], $schema['type'], "The {$name} field should have the REST type." );
		}
	}

	/**
	 * A term has the values and order of the REST response, without `meta`.
	 *
	 * @dataProvider data_rest_routes
	 *
	 * @since x.x.x
	 *
	 * @param string $taxonomy The taxonomy.
	 * @param string $route    The taxonomy's REST route.
	 */
	public function test_term_matches_the_rest_response( string $taxonomy, string $route ): void {
		$this->login_as( 'subscriber' );
		$this->register_ability();

		$id     = 'category' === $taxonomy ? self::$term_ids['apple'] : self::$term_ids['red'];
		$result = $this->query_terms(
			array(
				'id'     => $id,
				'fields' => self::ALL_FIELDS,
			)
		);
		$rest   = rest_get_server()->dispatch( new WP_REST_Request( 'GET', "{$route}/{$id}" ) )->get_data();

		$this->assertSame( array_diff_key( $rest, array( 'meta' => true ) ), $result, 'The term should match the REST response.' );
	}

	/**
	 * Returns collection queries that need no fixture IDs.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}> The taxonomy, its REST route, and the query.
	 */
	public function data_rest_collection_queries(): array {
		$queries = array(
			'defaults'            => array(),
			'search'              => array( 'search' => 'e' ),
			'hide_empty'          => array( 'hide_empty' => true ),
			'orderby count desc'  => array(
				'orderby' => 'count',
				'order'   => 'desc',
			),
			'orderby slug'        => array( 'orderby' => 'slug' ),
			'orderby description' => array( 'orderby' => 'description' ),
			'second page'         => array(
				'orderby'  => 'id',
				'order'    => 'desc',
				'page'     => 2,
				'per_page' => 2,
			),
			'page past the end'   => array( 'page' => 99 ),
			'top level'           => array( 'parent' => 0 ),
			'post zero'           => array( 'post' => 0 ),
		);

		$cases = array();
		foreach ( $this->data_rest_routes() as $route_name => $route ) {
			foreach ( $queries as $query_name => $query ) {
				$cases[ "{$route_name}, {$query_name}" ] = array( $route[0], $route[1], $query );
			}
		}

		return $cases;
	}

	/**
	 * A query returns the terms and totals of the REST response.
	 *
	 * @dataProvider data_rest_collection_queries
	 *
	 * @since x.x.x
	 *
	 * @param string               $taxonomy The taxonomy.
	 * @param string               $route    The taxonomy's REST route.
	 * @param array<string, mixed> $query    The query.
	 */
	public function test_collection_matches_the_rest_response( string $taxonomy, string $route, array $query ): void {
		$this->login_as( 'subscriber' );
		$this->register_ability();

		$this->assert_query_matches_rest( $taxonomy, $route, $query );
	}

	/**
	 * Queries by term and post IDs return the terms and totals of the REST response.
	 *
	 * @since x.x.x
	 */
	public function test_collection_filters_match_the_rest_response(): void {
		$this->login_as( 'subscriber' );
		$this->register_ability();

		$queries = array(
			array(
				'include' => array( self::$term_ids['apple'], self::$term_ids['fruit'], self::$term_ids['red'] ),
				'orderby' => 'include',
			),
			array( 'exclude' => array( self::$term_ids['apple'], self::$term_ids['red'] ) ), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Term query argument.
			array( 'parent' => self::$term_ids['fruit'] ),
			array( 'post' => self::$post_ids['published'] ),
			array(
				'post'       => self::$post_ids['published'],
				'hide_empty' => true,
				'orderby'    => 'id',
				'order'      => 'desc',
			),
		);

		foreach ( $this->data_rest_routes() as [ $taxonomy, $route ] ) {
			foreach ( $queries as $query ) {
				$this->assert_query_matches_rest( $taxonomy, $route, $query );
			}
		}
	}

	/**
	 * Asserts that a query returns the terms and totals of the same REST request.
	 *
	 * @since x.x.x
	 *
	 * @param string               $taxonomy The taxonomy.
	 * @param string               $route    The taxonomy's REST route.
	 * @param array<string, mixed> $query    The query.
	 */
	private function assert_query_matches_rest( string $taxonomy, string $route, array $query ): void {
		$result = $this->query_terms(
			array_merge(
				array(
					'taxonomy' => $taxonomy,
					'fields'   => self::ALL_FIELDS,
				),
				$query
			)
		);

		$request = new WP_REST_Request( 'GET', $route );
		$request->set_query_params( $query );
		$response = rest_get_server()->dispatch( $request );
		$headers  = $response->get_headers();
		$message  = sprintf( 'The %s query %s should match REST.', $taxonomy, wp_json_encode( $query ) );

		$this->assertSame( 200, $response->get_status(), $message );
		$this->assertIsArray( $result, $message );
		$this->assertSame(
			array_map(
				static function ( array $term ): array {
					return array_diff_key(
						$term,
						array(
							'meta'   => true,
							'_links' => true,
						)
					);
				},
				$response->get_data()
			),
			$result['terms'],
			$message
		);
		$this->assertSame( $headers['X-WP-Total'], $result['total'], $message );
		$this->assertSame( $headers['X-WP-TotalPages'], $result['total_pages'], $message );
	}
}
