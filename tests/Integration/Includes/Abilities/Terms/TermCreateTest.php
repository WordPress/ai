<?php
/**
 * Integration tests for the core/term-create Ability provided by the plugin.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Terms
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Terms;

use WordPress\AI\Abilities\Terms\Terms;

/**
 * Term create ability test case.
 *
 * A test named like a WP_Test_REST_Categories_Controller or WP_Test_REST_Tags_Controller
 * test ports it; a test both controllers have runs for each of their taxonomies.
 *
 * @since x.x.x
 */
class TermCreateTest extends Terms_Ability_TestCase {

	/**
	 * Creates a term through the ability and returns the result.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $input The ability input.
	 * @return mixed The ability result.
	 */
	private function create( array $input ) {
		return $this->execute_ability( 'core/term-create', $input );
	}

	/**
	 * The ability is registered as a closed-world, non-idempotent write that takes a
	 * taxonomy, the term fields, and a field selection, and returns a term like
	 * `core/terms-query` does.
	 *
	 * @since x.x.x
	 */
	public function test_registers_core_term_create_ability(): void {
		$this->register_ability();

		$ability     = wp_get_ability( 'core/term-create' );
		$annotations = $ability->get_meta_item( 'annotations', array() );
		$schema      = $ability->get_input_schema();

		$this->assertSame( 'Term Create', $ability->get_label(), 'The registered ability should use the expected label.' );
		$this->assertSame( 'content', $ability->get_category(), 'The registered ability should use the content category.' );
		$this->assertTrue( $ability->get_meta_item( 'show_in_rest', false ), 'The ability should be exposed in REST.' );
		$this->assertFalse( $annotations['readonly'], 'The ability should not be marked read-only.' );
		$this->assertFalse( $annotations['destructive'], 'Creating a term overwrites nothing.' );
		$this->assertFalse( $annotations['idempotent'], 'Every call creates a new term.' );
		$this->assertFalse( $annotations['open_world'], 'The ability only writes to the local database.' );
		$this->assertSame( array( 'taxonomy', 'name' ), $schema['required'], 'The taxonomy and the name should be required.' );
		$this->assertFalse( $schema['additionalProperties'], 'Unknown properties should be rejected.' );
		$this->assertSame( array( 'taxonomy', 'name', 'slug', 'description', 'parent', 'fields' ), array_keys( $schema['properties'] ), 'The input should take the taxonomy, the term fields, and the field selection.' );
		$this->assertSame( array( 'category', 'post_tag' ), $schema['properties']['taxonomy']['enum'], 'Only the exposed taxonomies should be accepted.' );
		$this->assertSame( wp_get_ability( 'core/terms-query' )->get_output_schema()['oneOf'][0], $ability->get_output_schema(), 'The created term should have the shape of a queried term.' );
	}

	/**
	 * When core already provides core/term-create, the plugin's version replaces it.
	 *
	 * @since x.x.x
	 */
	public function test_override_replaces_existing_core_term_create(): void {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			wp_register_ability(
				'core/term-create',
				array(
					'label'               => 'Core Provided',
					'description'         => 'Core provided term ability.',
					'category'            => 'content',
					'execute_callback'    => '__return_empty_array',
					'permission_callback' => '__return_true',
				)
			);
		} finally {
			array_pop( $wp_current_filter );
		}

		$this->register_ability();

		$this->assertSame( 'Term Create', wp_get_ability( 'core/term-create' )->get_label(), 'The plugin-provided ability should replace the existing one.' );
	}

	/**
	 * The write abilities are not registered when no taxonomies are exposed to them.
	 *
	 * @since x.x.x
	 */
	public function test_does_not_register_term_write_abilities_without_exposed_taxonomies(): void {
		foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
			get_taxonomy( $taxonomy )->show_in_abilities = false;
		}

		$this->register_ability();

		foreach ( array( 'core/term-create', 'core/term-update', 'core/term-delete' ) as $ability_name ) {
			$this->assertFalse( wp_has_ability( $ability_name ), "The {$ability_name} ability should not register without any exposed taxonomies." );
		}
	}

	/**
	 * A term is created with its name, description, and slug.
	 *
	 * @dataProvider data_core_taxonomies
	 *
	 * @since x.x.x
	 *
	 * @param string $taxonomy The taxonomy.
	 */
	public function test_create_item( string $taxonomy ): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$result = $this->create(
			array(
				'taxonomy'    => $taxonomy,
				'name'        => 'My Awesome Term',
				'description' => 'This term is so awesome.',
				'slug'        => 'so-awesome',
				'fields'      => array( 'name', 'description', 'slug', 'taxonomy' ),
			)
		);

		$this->assertIsArray( $result, 'The term should be created.' );
		$this->assertSame( 'My Awesome Term', $result['name'], 'The name should be stored.' );
		$this->assertSame( 'This term is so awesome.', $result['description'], 'The description should be stored.' );
		$this->assertSame( 'so-awesome', $result['slug'], 'The slug should be stored.' );
		$this->assertSame( $taxonomy, get_term( $result['id'] )->taxonomy, 'The term should be created in the taxonomy.' );
	}

	/**
	 * A name already taken in the taxonomy is reported with the ID of the existing term.
	 *
	 * @ticket 41370
	 *
	 * @since x.x.x
	 */
	public function test_create_item_term_already_exists(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$existing_id = self::factory()->category->create( array( 'name' => 'Existing' ) );

		$result = $this->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Existing',
			)
		);

		$this->assertAbilityError( $result, 'term_exists', 'A taken name should be reported.' );
		$this->assertSame( 400, $result->get_error_data()['status'], 'A taken name should be a bad request.' );
		$this->assertSame( $existing_id, (int) $result->get_error_data()['term_id'], 'The existing term should be identified.' );
	}

	/**
	 * A taxonomy that does not exist is rejected.
	 *
	 * @since x.x.x
	 */
	public function test_create_item_invalid_taxonomy(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$result = $this->create(
			array(
				'taxonomy' => 'invalid-taxonomy',
				'name'     => 'Invalid Taxonomy',
			)
		);

		$this->assertAbilityError( $result, 'ability_invalid_input', 'An unknown taxonomy should fail validation.' );
	}

	/**
	 * A subscriber cannot create terms.
	 *
	 * @dataProvider data_core_taxonomies
	 *
	 * @since x.x.x
	 *
	 * @param string $taxonomy The taxonomy.
	 */
	public function test_create_item_incorrect_permissions( string $taxonomy ): void {
		$this->login_as( 'subscriber' );
		$this->register_ability();

		$result = $this->create(
			array(
				'taxonomy' => $taxonomy,
				'name'     => 'Incorrect permissions',
			)
		);

		$this->assertAbilityDenied( $result, 'A subscriber should not create terms.' );
		$this->assertFalse( get_term_by( 'name', 'Incorrect permissions', $taxonomy ), 'No term should be created.' );
	}

	/**
	 * A contributor cannot create categories.
	 *
	 * @since x.x.x
	 */
	public function test_create_item_incorrect_permissions_contributor(): void {
		$this->login_as( 'contributor' );
		$this->register_ability();

		$result = $this->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Incorrect permissions',
			)
		);

		$this->assertAbilityDenied( $result, 'A contributor should not create categories.' );
	}

	/**
	 * A contributor can create tags, which only needs the capability to assign them.
	 *
	 * @since x.x.x
	 */
	public function test_create_item_contributor(): void {
		$this->login_as( 'contributor' );
		$this->register_ability();

		$result = $this->create(
			array(
				'taxonomy'    => 'post_tag',
				'name'        => 'My Awesome Term',
				'description' => 'This term is so awesome.',
				'slug'        => 'so-awesome',
				'fields'      => array( 'name', 'description', 'slug' ),
			)
		);

		$this->assertIsArray( $result, 'A contributor should create a tag.' );
		$this->assertSame( 'My Awesome Term', $result['name'], 'The name should be stored.' );
		$this->assertSame( 'This term is so awesome.', $result['description'], 'The description should be stored.' );
		$this->assertSame( 'so-awesome', $result['slug'], 'The slug should be stored.' );
	}

	/**
	 * A name is required.
	 *
	 * @dataProvider data_core_taxonomies
	 *
	 * @since x.x.x
	 *
	 * @param string $taxonomy The taxonomy.
	 */
	public function test_create_item_missing_arguments( string $taxonomy ): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$this->assertAbilityError( $this->create( array( 'taxonomy' => $taxonomy ) ), 'ability_invalid_input', 'A term without a name should fail validation.' );
	}

	/**
	 * A category is created under a parent.
	 *
	 * @since x.x.x
	 */
	public function test_create_item_with_parent(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$parent = wp_insert_term( 'test-category', 'category' );

		$result = $this->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'My Awesome Term',
				'parent'   => $parent['term_id'],
				'fields'   => array( 'parent' ),
			)
		);

		$this->assertIsArray( $result, 'The category should be created.' );
		$this->assertSame( $parent['term_id'], $result['parent'], 'The category should have the parent.' );
	}

	/**
	 * A parent that does not exist is reported, and no term is created.
	 *
	 * @since x.x.x
	 */
	public function test_create_item_invalid_parent(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$result = $this->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'My Awesome Term',
				'parent'   => REST_TESTS_IMPOSSIBLY_HIGH_NUMBER,
			)
		);

		$this->assertAbilityError( $result, 'terms_term_invalid', 'A missing parent should be reported.' );
		$this->assertSame( 400, $result->get_error_data()['status'], 'A missing parent should be a bad request.' );
		$this->assertFalse( get_term_by( 'name', 'My Awesome Term', 'category' ), 'No term should be created.' );
	}

	/**
	 * A parent of 0 creates a top-level category.
	 *
	 * @since x.x.x
	 */
	public function test_create_item_with_no_parent(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$parent = 0;

		$result = $this->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'My Awesome Term',
				'parent'   => $parent,
				'fields'   => array( 'parent' ),
			)
		);

		$this->assertIsArray( $result, 'The category should be created.' );
		$this->assertSame( $parent, $result['parent'], 'The category should be top-level.' );
	}

	/**
	 * A parent cannot be set in a taxonomy that is not hierarchical.
	 *
	 * @since x.x.x
	 */
	public function test_create_item_parent_non_hierarchical_taxonomy(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$result = $this->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'My Awesome Term',
				'parent'   => REST_TESTS_IMPOSSIBLY_HIGH_NUMBER,
			)
		);

		$this->assertAbilityError( $result, 'terms_taxonomy_not_hierarchical', 'A parent should not be set on a tag.' );
		$this->assertSame( 400, $result->get_error_data()['status'], 'A parent on a tag should be a bad request.' );
	}

	/**
	 * Creates a tag and updates it with the same input, checking the name and description
	 * the abilities return and store.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, string> $input           The name and description to write.
	 * @param array<string, string> $expected_output The name and description expected back.
	 */
	private function verify_tag_roundtrip( array $input, array $expected_output ): void {
		$fields = array( 'name', 'description' );

		// Create the tag.
		$actual_output = $this->create(
			array(
				'taxonomy' => 'post_tag',
				'fields'   => $fields,
			) + $input
		);
		$this->assertIsArray( $actual_output, 'The tag should be created.' );

		// Compare expected API output to actual API output.
		$this->assertSame( $expected_output['name'], $actual_output['name'], 'The created name should be returned as expected.' );
		$this->assertSame( $expected_output['description'], $actual_output['description'], 'The created description should be returned as expected.' );

		// Compare expected API output to WP internal values.
		$tag = get_term_by( 'id', $actual_output['id'], 'post_tag' );
		$this->assertSame( $expected_output['name'], $tag->name, 'The created name should be stored as expected.' );
		$this->assertSame( $expected_output['description'], $tag->description, 'The created description should be stored as expected.' );

		// Update the tag.
		$actual_output = $this->execute_ability(
			'core/term-update',
			array(
				'id'     => $actual_output['id'],
				'fields' => $fields,
			) + $input
		);
		$this->assertIsArray( $actual_output, 'The tag should be updated.' );

		// Compare expected API output to actual API output.
		$this->assertSame( $expected_output['name'], $actual_output['name'], 'The updated name should be returned as expected.' );
		$this->assertSame( $expected_output['description'], $actual_output['description'], 'The updated description should be returned as expected.' );

		// Compare expected API output to WP internal values.
		$tag = get_term_by( 'id', $actual_output['id'], 'post_tag' );
		$this->assertSame( $expected_output['name'], $tag->name, 'The updated name should be stored as expected.' );
		$this->assertSame( $expected_output['description'], $tag->description, 'The updated description should be stored as expected.' );
	}

	/**
	 * Logs in as an administrator who is also a super admin on multisite.
	 *
	 * @since x.x.x
	 */
	private function login_as_super_admin(): void {
		$user_id = $this->login_as( 'administrator' );

		if ( ! is_multisite() ) {
			return;
		}

		grant_super_admin( $user_id );
	}

	/**
	 * An editor's tag keeps backslashes and special characters.
	 *
	 * @since x.x.x
	 */
	public function test_tag_roundtrip_as_editor(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$this->assertSame( ! is_multisite(), current_user_can( 'unfiltered_html' ), 'Only a single-site editor should have unfiltered HTML.' );
		$this->verify_tag_roundtrip(
			array(
				'name'        => '\o/ ¯\_(ツ)_/¯',
				'description' => '\o/ ¯\_(ツ)_/¯',
			),
			array(
				'name'        => '\o/ ¯\_(ツ)_/¯',
				'description' => '\o/ ¯\_(ツ)_/¯',
			)
		);
	}

	/**
	 * An editor's tag name loses its HTML and its description keeps only allowed HTML.
	 *
	 * @since x.x.x
	 */
	public function test_tag_roundtrip_as_editor_html(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		if ( is_multisite() ) {
			$this->assertFalse( current_user_can( 'unfiltered_html' ), 'A multisite editor should not have unfiltered HTML.' );
			$this->verify_tag_roundtrip(
				array(
					'name'        => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
					'description' => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
				),
				array(
					'name'        => 'div strong',
					'description' => 'div <strong>strong</strong> oh noes',
				)
			);
		} else {
			$this->assertTrue( current_user_can( 'unfiltered_html' ), 'A single-site editor should have unfiltered HTML.' );
			$this->verify_tag_roundtrip(
				array(
					'name'        => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
					'description' => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
				),
				array(
					'name'        => 'div strong',
					'description' => 'div <strong>strong</strong> oh noes',
				)
			);
		}
	}

	/**
	 * A super admin's tag keeps its backslashes and has its bare ampersands, invalid
	 * entities, and less-than signs escaped.
	 *
	 * @since x.x.x
	 */
	public function test_tag_roundtrip_as_superadmin(): void {
		$this->login_as_super_admin();
		$this->register_ability();

		$this->assertTrue( current_user_can( 'unfiltered_html' ), 'A super admin should have unfiltered HTML.' );
		$this->verify_tag_roundtrip(
			array(
				'name'        => '\\\&\\\ &amp; &invalid; < &lt; &amp;lt;',
				'description' => '\\\&\\\ &amp; &invalid; < &lt; &amp;lt;',
			),
			array(
				'name'        => '\\\&amp;\\\ &amp; &amp;invalid; &lt; &lt; &amp;lt;',
				'description' => '\\\&amp;\\\ &amp; &amp;invalid; &lt; &lt; &amp;lt;',
			)
		);
	}

	/**
	 * A super admin's tag name loses its HTML and its description keeps only allowed HTML.
	 *
	 * @since x.x.x
	 */
	public function test_tag_roundtrip_as_superadmin_html(): void {
		$this->login_as_super_admin();
		$this->register_ability();

		$this->assertTrue( current_user_can( 'unfiltered_html' ), 'A super admin should have unfiltered HTML.' );
		$this->verify_tag_roundtrip(
			array(
				'name'        => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
				'description' => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
			),
			array(
				'name'        => 'div strong',
				'description' => 'div <strong>strong</strong> oh noes',
			)
		);
	}

	/**
	 * Creating a term needs the capability to edit the terms of a hierarchical taxonomy, or
	 * to assign the terms of a flat one, and no role can create a term in a taxonomy that is
	 * not exposed.
	 *
	 * @dataProvider data_term_roles
	 *
	 * @since x.x.x
	 *
	 * @param string $role       The role, or an empty string for a logged-out visitor.
	 * @param bool   $can_manage Whether the role can manage terms.
	 * @param bool   $can_assign Whether the role can assign terms.
	 */
	public function test_roles_creating_terms( string $role, bool $can_manage, bool $can_assign ): void {
		$this->register_write_test_taxonomies();
		if ( '' !== $role ) {
			$this->login_as( $role );
		}
		$this->register_ability();

		$allowed_by_taxonomy = array(
			'category'   => $can_manage,
			'post_tag'   => $can_assign,
			'wpai_genre' => $can_manage,
			'wpai_mood'  => $can_assign,
		);

		foreach ( $allowed_by_taxonomy as $taxonomy => $allowed ) {
			$result = $this->create(
				array(
					'taxonomy' => $taxonomy,
					'name'     => 'Created by a role',
					'fields'   => array( 'taxonomy' ),
				)
			);

			if ( $allowed ) {
				$this->assertIsArray( $result, "The role should create a {$taxonomy} term." );
				$this->assertSame( $taxonomy, $result['taxonomy'], "The term should be created in {$taxonomy}." );
			} else {
				$this->assertAbilityDenied( $result, "The role should not create a {$taxonomy} term." );
				$this->assertFalse( get_term_by( 'name', 'Created by a role', $taxonomy ), "No {$taxonomy} term should be created." );
			}
		}

		$result = $this->create(
			array(
				'taxonomy' => 'wpai_secret',
				'name'     => 'Created by a role',
			)
		);
		$this->assertAbilityError( $result, 'ability_invalid_input', 'No role should create a term in a taxonomy that is not exposed.' );
		$this->assertFalse( get_term_by( 'name', 'Created by a role', 'wpai_secret' ), 'No term should be created in the taxonomy that is not exposed.' );
	}

	/**
	 * A category name is taken only under the same parent. Under another parent or at the
	 * top level the name is accepted with a unique slug, and under the same parent with a
	 * slug of its own.
	 *
	 * @since x.x.x
	 */
	public function test_create_term_name_is_unique_under_its_parent(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$fruit  = self::factory()->category->create( array( 'name' => 'Fruit' ) );
		$veg    = self::factory()->category->create( array( 'name' => 'Veg' ) );
		$apple  = self::factory()->category->create(
			array(
				'name'   => 'Apple',
				'parent' => $fruit,
			)
		);
		$fields = array( 'name', 'slug', 'parent' );

		$taken = $this->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Apple',
				'parent'   => $fruit,
			)
		);
		$this->assertAbilityError( $taken, 'term_exists', 'A name taken under the same parent should be reported.' );
		$this->assertSame(
			array(
				'status'  => 400,
				'term_id' => $apple,
			),
			$taken->get_error_data(),
			'The existing term should be identified.'
		);

		$under_veg = $this->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Apple',
				'parent'   => $veg,
				'fields'   => $fields,
			)
		);
		$this->assertSame( 'apple-veg', $under_veg['slug'], 'Under another parent the name should be accepted with a slug naming the parent.' );
		$this->assertSame( $veg, $under_veg['parent'], 'The term should be created under the other parent.' );

		$top_level = $this->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Apple',
				'fields'   => $fields,
			)
		);
		$this->assertSame( 'apple-2', $top_level['slug'], 'At the top level the name should be accepted with a numbered slug.' );
		$this->assertSame( 0, $top_level['parent'], 'The term should be created at the top level.' );

		$own_slug = $this->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Apple',
				'slug'     => 'green-apple',
				'parent'   => $fruit,
				'fields'   => $fields,
			)
		);
		$this->assertSame( 'green-apple', $own_slug['slug'], 'Under the same parent the name should be accepted with a slug of its own.' );
		$this->assertSame( $fruit, $own_slug['parent'], 'The term should be created under the same parent.' );
	}

	/**
	 * A tag name is taken anywhere in its taxonomy.
	 *
	 * @since x.x.x
	 */
	public function test_create_tag_with_a_taken_name_is_reported(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$red = self::factory()->tag->create( array( 'name' => 'Red' ) );

		$result = $this->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Red',
			)
		);

		$this->assertAbilityError( $result, 'term_exists', 'A taken tag name should be reported.' );
		$this->assertSame( 'A term with the name provided already exists in this taxonomy.', $result->get_error_message(), 'The whole taxonomy should be named.' );
		$this->assertSame(
			array(
				'status'  => 400,
				'term_id' => $red,
			),
			$result->get_error_data(),
			'The existing tag should be identified.'
		);
	}

	/**
	 * A name that is empty once sanitized is refused like an empty name. The error has no
	 * status, so the run endpoint reports it as a server error.
	 *
	 * @since x.x.x
	 */
	public function test_create_item_with_an_empty_name(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		foreach ( array( '', '<b></b>' ) as $name ) {
			$result = $this->create(
				array(
					'taxonomy' => 'post_tag',
					'name'     => $name,
				)
			);
			$this->assertAbilityError( $result, 'empty_term_name', "The name '{$name}' should be refused." );
		}

		$response = $this->run_ability(
			'POST',
			'core/term-create',
			array(
				'taxonomy' => 'post_tag',
				'name'     => '<b></b>',
			)
		);
		$this->assertSame( 500, $response->get_status(), 'The refused name should be a server error.' );
		$this->assertSame( 'empty_term_name', $response->as_error()->get_error_code(), 'The refused name should be reported.' );
	}

	/**
	 * A slug taken by a term with another name is made unique rather than refused.
	 *
	 * @since x.x.x
	 */
	public function test_create_term_with_a_taken_slug_gets_a_unique_slug(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		self::factory()->tag->create(
			array(
				'name' => 'Red',
				'slug' => 'red',
			)
		);

		$result = $this->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Crimson',
				'slug'     => 'red',
				'fields'   => array( 'name', 'slug' ),
			)
		);

		$this->assertIsArray( $result, 'The tag should be created.' );
		$this->assertSame( 'Crimson', $result['name'], 'The name should be stored.' );
		$this->assertSame( 'red-2', $result['slug'], 'The taken slug should be made unique.' );
	}

	/**
	 * A parent must be a term of the same taxonomy. A tag, a term of a taxonomy that is not
	 * exposed, a missing term, and a negative ID are all reported alike.
	 *
	 * @since x.x.x
	 */
	public function test_create_item_parent_must_be_in_the_taxonomy(): void {
		$this->register_write_test_taxonomies();
		$this->login_as( 'administrator' );
		$this->register_ability();

		$parents = array(
			'a tag'                => self::factory()->tag->create(),
			'a term not exposed'   => self::factory()->term->create( array( 'taxonomy' => 'wpai_secret' ) ),
			'a missing term'       => REST_TESTS_IMPOSSIBLY_HIGH_NUMBER,
			'a negative parent ID' => -1,
		);

		foreach ( $parents as $label => $parent ) {
			$result = $this->create(
				array(
					'taxonomy' => 'category',
					'name'     => "Child of {$label}",
					'parent'   => $parent,
				)
			);

			$this->assertAbilityError( $result, 'terms_term_invalid', "A parent that is {$label} should be reported." );
			$this->assertSame( 'Parent term does not exist.', $result->get_error_message(), "A parent that is {$label} should be reported like a missing one." );
			$this->assertFalse( get_term_by( 'name', "Child of {$label}", 'category' ), "No category should be created under {$label}." );
		}
	}

	/**
	 * Any parent, even 0, is refused in a taxonomy that is not hierarchical.
	 *
	 * @since x.x.x
	 */
	public function test_create_tag_with_a_parent_of_zero_is_refused(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$result = $this->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Top-level tag',
				'parent'   => 0,
			)
		);

		$this->assertAbilityError( $result, 'terms_taxonomy_not_hierarchical', 'A parent of 0 should be refused for a tag too.' );
	}

	/**
	 * Without `fields`, the created term carries the lean default set, and `parent` only for
	 * hierarchical taxonomies.
	 *
	 * @since x.x.x
	 */
	public function test_default_fields(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$category = $this->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Fruit',
			)
		);
		$tag      = $this->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Red',
			)
		);

		$this->assertSame( array( 'id', 'count', 'name', 'slug', 'taxonomy', 'parent' ), array_keys( $category ), 'A created category should have the default fields.' );
		$this->assertSame( array( 'id', 'count', 'name', 'slug', 'taxonomy' ), array_keys( $tag ), 'A created tag should have the default fields except the parent.' );
	}

	/**
	 * Inputs the ability does not take, and unknown or repeated fields, are rejected before
	 * anything is written.
	 *
	 * @since x.x.x
	 */
	public function test_unknown_inputs_are_rejected(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$input  = array(
			'taxonomy' => 'category',
			'name'     => 'Fruit',
		);
		$extras = array(
			'an ID'            => array( 'id' => self::factory()->category->create() ),
			'a count'          => array( 'count' => 3 ),
			'a link'           => array( 'link' => 'https://example.org/fruit/' ),
			'meta'             => array( 'meta' => array( 'color' => 'red' ) ),
			'a context'        => array( 'context' => 'edit' ),
			'an unknown field' => array( 'fields' => array( 'meta' ) ),
			'a repeated field' => array( 'fields' => array( 'name', 'name' ) ),
		);

		foreach ( $extras as $label => $extra ) {
			$this->assertAbilityError( $this->create( $input + $extra ), 'ability_invalid_input', "Input with {$label} should fail validation." );
		}

		$this->assertFalse( get_term_by( 'name', 'Fruit', 'category' ), 'No category should be created.' );
	}

	/**
	 * A parent and a field list sent as strings are cast like typed inputs.
	 *
	 * @since x.x.x
	 */
	public function test_string_inputs_are_cast(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$fruit = self::factory()->category->create( array( 'name' => 'Fruit' ) );

		$apple = $this->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Apple',
				'parent'   => (string) $fruit,
				'fields'   => 'name,parent',
			)
		);
		$this->assertSame(
			array(
				'id'     => get_term_by( 'name', 'Apple', 'category' )->term_id,
				'name'   => 'Apple',
				'parent' => $fruit,
			),
			$apple,
			'A string parent and field list should be applied.'
		);

		$veg = $this->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Veg',
				'parent'   => '0',
				'fields'   => 'parent',
			)
		);
		$this->assertSame(
			array(
				'id'     => get_term_by( 'name', 'Veg', 'category' )->term_id,
				'parent' => 0,
			),
			$veg,
			'A string parent of 0 should create a top-level category.'
		);
	}

	/**
	 * A taxonomy that is not exposed, like menus, takes no new terms, and a direct call
	 * refuses it exactly like a taxonomy that does not exist.
	 *
	 * @since x.x.x
	 */
	public function test_hidden_and_missing_taxonomies_are_refused_alike(): void {
		$this->register_write_test_taxonomies();
		$this->login_as( 'administrator' );
		$this->register_ability();

		$terms   = new Terms();
		$missing = array(
			'taxonomy' => 'wpai_missing',
			'name'     => 'Hidden term',
		);

		foreach ( array( 'wpai_secret', 'nav_menu' ) as $taxonomy ) {
			$input = array( 'taxonomy' => $taxonomy ) + $missing;

			$this->assertAbilityError( $this->create( $input ), 'ability_invalid_input', "The {$taxonomy} taxonomy should not be accepted." );
			$this->assertFalse( $terms->check_create_permission( $input ), "A direct permission check should deny the {$taxonomy} taxonomy." );
			$this->assertEquals( $terms->execute_term_create( $missing ), $terms->execute_term_create( $input ), "A direct call should refuse the {$taxonomy} taxonomy like a missing one." );
			$this->assertFalse( get_term_by( 'name', 'Hidden term', $taxonomy ), "No {$taxonomy} term should be created." );
		}

		$result = $terms->execute_term_create( $missing );
		$this->assertAbilityError( $result, 'terms_forbidden', 'A direct call should fail closed.' );
		$this->assertSame( 403, $result->get_error_data()['status'], 'The error should be forbidden.' );
	}

	/**
	 * A direct call without a name is refused like an empty name, without PHP warnings.
	 *
	 * @since x.x.x
	 */
	public function test_direct_call_without_a_name_is_refused(): void {
		$this->login_as( 'administrator' );

		$result = ( new Terms() )->execute_term_create( array( 'taxonomy' => 'category' ) );

		$this->assertAbilityError( $result, 'empty_term_name', 'A direct call without a name should be refused.' );
	}

	/**
	 * The run endpoint takes the input from the JSON body of a POST request, passes errors
	 * through with their status, and rejects other methods.
	 *
	 * @since x.x.x
	 */
	public function test_run_endpoint_takes_a_json_body(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$fruit = self::factory()->category->create( array( 'name' => 'Fruit' ) );
		$input = array(
			'taxonomy' => 'category',
			'name'     => 'Apple',
			'parent'   => $fruit,
			'fields'   => array( 'name', 'parent' ),
		);

		foreach ( array( 'GET', 'DELETE' ) as $method ) {
			$response = $this->run_ability( $method, 'core/term-create', $input );
			$this->assertSame( 405, $response->get_status(), "A {$method} request should be rejected." );
			$this->assertSame( 'rest_ability_invalid_method', $response->as_error()->get_error_code(), "A {$method} request should need POST." );
		}
		$this->assertFalse( get_term_by( 'name', 'Apple', 'category' ), 'A rejected request should create nothing.' );

		$response = $this->run_ability( 'POST', 'core/term-create', $input );
		$this->assertSame( 200, $response->get_status(), 'A POST request should run the ability.' );
		$this->assertSame(
			array(
				'id'     => get_term_by( 'name', 'Apple', 'category' )->term_id,
				'name'   => 'Apple',
				'parent' => $fruit,
			),
			$response->get_data(),
			'The created category should be returned.'
		);

		$response = $this->run_ability( 'POST', 'core/term-create', $input );
		$this->assertSame( 400, $response->get_status(), 'A taken name should be a client error.' );
		$this->assertSame( 'term_exists', $response->as_error()->get_error_code(), 'A taken name should be reported.' );
	}

	/**
	 * Every input but the taxonomy and the field selection is an argument of the endpoint
	 * that creates terms, with the same type. Tags take no parent.
	 *
	 * @dataProvider data_core_taxonomies
	 *
	 * @since x.x.x
	 *
	 * @param string $taxonomy The taxonomy.
	 */
	public function test_inputs_are_rest_arguments( string $taxonomy ): void {
		$this->register_ability();

		$rest_args = $this->get_rest_route_args( rest_get_route_for_taxonomy_items( $taxonomy ), 'POST' );
		$inputs    = array_diff_key( wp_get_ability( 'core/term-create' )->get_input_schema()['properties'], array_flip( array( 'taxonomy', 'fields' ) ) );

		foreach ( $inputs as $name => $schema ) {
			if ( 'parent' === $name && ! is_taxonomy_hierarchical( $taxonomy ) ) {
				$this->assertArrayNotHasKey( $name, $rest_args, 'A tag should take no parent.' );
				continue;
			}

			$this->assertArrayHasKey( $name, $rest_args, "The {$name} input should be a REST argument." );
			$this->assertSame( $rest_args[ $name ]['type'], $schema['type'], "The {$name} input should have the REST type." );
		}
	}

	/**
	 * The created term reads back the same through `core/terms-query` and the REST endpoint.
	 *
	 * @dataProvider data_core_taxonomies
	 *
	 * @since x.x.x
	 *
	 * @param string $taxonomy The taxonomy.
	 */
	public function test_created_term_reads_back_the_same( string $taxonomy ): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$result = $this->create(
			array(
				'taxonomy'    => $taxonomy,
				'name'        => 'Fruit & Veg',
				'description' => 'A <em>fresh</em> term.',
				'fields'      => self::ALL_FIELDS,
			)
		);

		$this->assertIsArray( $result, 'The term should be created.' );
		$this->assertSame(
			$this->query_terms(
				array(
					'id'     => $result['id'],
					'fields' => self::ALL_FIELDS,
				)
			),
			$result,
			'The created term should read back the same.'
		);
		$this->assertSame( $this->get_rest_term( $result['id'] ), $result, 'The created term should match the REST response.' );
	}
}
