<?php
/**
 * Integration tests for the core/term-create Ability provided by the plugin.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Terms
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Terms;

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
	 * A super admin's tag has its entities and backslashes escaped.
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
}
