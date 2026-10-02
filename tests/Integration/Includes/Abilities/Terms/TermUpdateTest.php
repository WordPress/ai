<?php
/**
 * Integration tests for the core/term-update Ability provided by the plugin.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Terms
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Terms;

use WordPress\AI\Abilities\Terms\Terms;

/**
 * Term update ability test case.
 *
 * A test named like a WP_Test_REST_Categories_Controller or WP_Test_REST_Tags_Controller
 * test ports it; a test both controllers have runs for each of their taxonomies.
 *
 * @since x.x.x
 */
class TermUpdateTest extends Terms_Ability_TestCase {

	/**
	 * Updates a term through the ability and returns the result.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $input The ability input.
	 * @return mixed The ability result.
	 */
	private function update( array $input ) {
		return $this->execute_ability( 'core/term-update', $input );
	}

	/**
	 * Maps the `edit_term` capability to `read`, granting it to every user.
	 *
	 * @since x.x.x
	 *
	 * @param array<string> $caps The primitive capabilities the capability maps to.
	 * @param string        $cap  The capability being checked.
	 * @return array<string> The primitive capabilities.
	 */
	public function grant_edit_term( $caps, $cap ) {
		if ( 'edit_term' === $cap ) {
			$caps = array( 'read' );
		}
		return $caps;
	}

	/**
	 * Maps the `edit_term` capability to `do_not_allow`, revoking it from every user.
	 *
	 * @since x.x.x
	 *
	 * @param array<string> $caps The primitive capabilities the capability maps to.
	 * @param string        $cap  The capability being checked.
	 * @return array<string> The primitive capabilities.
	 */
	public function revoke_edit_term( $caps, $cap ) {
		if ( 'edit_term' === $cap ) {
			$caps = array( 'do_not_allow' );
		}
		return $caps;
	}

	/**
	 * The ability is registered as a closed-world, destructive write that is not idempotent,
	 * so it runs over POST. It takes an ID, an optional taxonomy guard, the term fields, and
	 * a field selection, and returns a term like `core/terms-query` does.
	 *
	 * @since x.x.x
	 */
	public function test_registers_core_term_update_ability(): void {
		$this->register_ability();

		$ability     = wp_get_ability( 'core/term-update' );
		$annotations = $ability->get_meta_item( 'annotations', array() );
		$schema      = $ability->get_input_schema();

		$this->assertSame( 'Term Update', $ability->get_label(), 'The registered ability should use the expected label.' );
		$this->assertSame( 'content', $ability->get_category(), 'The registered ability should use the content category.' );
		$this->assertTrue( $ability->get_meta_item( 'show_in_rest', false ), 'The ability should be exposed in REST.' );
		$this->assertFalse( $annotations['readonly'], 'The ability should not be marked read-only.' );
		$this->assertTrue( $annotations['destructive'], 'Updating a term overwrites its values.' );
		$this->assertFalse( $annotations['idempotent'], 'The ability should run over POST, not DELETE.' );
		$this->assertFalse( $annotations['open_world'], 'The ability only writes to the local database.' );
		$this->assertSame( array( 'id' ), $schema['required'], 'Only the ID should be required.' );
		$this->assertFalse( $schema['additionalProperties'], 'Unknown properties should be rejected.' );
		$this->assertSame( array( 'id', 'taxonomy', 'name', 'slug', 'description', 'parent', 'fields' ), array_keys( $schema['properties'] ), 'The input should take the ID, a taxonomy guard, the term fields, and the field selection.' );
		$this->assertSame( array( 'category', 'post_tag' ), $schema['properties']['taxonomy']['enum'], 'Only the exposed taxonomies should be accepted.' );
		$this->assertSame( wp_get_ability( 'core/terms-query' )->get_output_schema()['oneOf'][0], $ability->get_output_schema(), 'The updated term should have the shape of a queried term.' );
	}

	/**
	 * A term's name, description, and slug are updated.
	 *
	 * @dataProvider data_core_taxonomies
	 *
	 * @since x.x.x
	 *
	 * @param string $taxonomy The taxonomy.
	 */
	public function test_update_item( string $taxonomy ): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$orig_args = array(
			'taxonomy'    => $taxonomy,
			'name'        => 'Original Name',
			'description' => 'Original Description',
			'slug'        => 'original-slug',
		);

		$term = get_term_by( 'id', self::factory()->term->create( $orig_args ), $taxonomy );

		$result = $this->update(
			array(
				'id'          => $term->term_id,
				'name'        => 'New Name',
				'description' => 'New Description',
				'slug'        => 'new-slug',
				'fields'      => array( 'name', 'description', 'slug' ),
			)
		);

		$this->assertIsArray( $result, 'The term should be updated.' );
		$this->assertSame( 'New Name', $result['name'], 'The new name should be returned.' );
		$this->assertSame( 'New Description', $result['description'], 'The new description should be returned.' );
		$this->assertSame( 'new-slug', $result['slug'], 'The new slug should be returned.' );
	}

	/**
	 * A taxonomy guard that does not exist is rejected.
	 *
	 * @since x.x.x
	 */
	public function test_update_item_invalid_taxonomy(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$result = $this->update(
			array(
				'id'       => REST_TESTS_IMPOSSIBLY_HIGH_NUMBER,
				'taxonomy' => 'invalid-taxonomy',
				'name'     => 'Invalid Taxonomy',
			)
		);

		$this->assertAbilityError( $result, 'ability_invalid_input', 'An unknown taxonomy should fail validation.' );
	}

	/**
	 * A term that does not exist is denied, and a direct call reports it as invalid.
	 *
	 * @dataProvider data_core_taxonomies
	 *
	 * @since x.x.x
	 *
	 * @param string $taxonomy The taxonomy.
	 */
	public function test_update_item_invalid_term( string $taxonomy ): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$input = array(
			'id'       => REST_TESTS_IMPOSSIBLY_HIGH_NUMBER,
			'taxonomy' => $taxonomy,
			'name'     => 'Invalid Term',
		);

		$this->assertAbilityDenied( $this->update( $input ), 'A missing term should be denied.' );

		$direct = ( new Terms() )->execute_term_update( $input );
		$this->assertAbilityError( $direct, 'terms_term_invalid', 'A direct call should report the missing term.' );
		$this->assertSame( 404, $direct->get_error_data()['status'], 'A missing term should not be found.' );
	}

	/**
	 * A subscriber cannot update terms.
	 *
	 * @dataProvider data_core_taxonomies
	 *
	 * @since x.x.x
	 *
	 * @param string $taxonomy The taxonomy.
	 */
	public function test_update_item_incorrect_permissions( string $taxonomy ): void {
		$this->login_as( 'subscriber' );
		$this->register_ability();

		$term = get_term_by( 'id', self::factory()->term->create( array( 'taxonomy' => $taxonomy ) ), $taxonomy );

		$result = $this->update(
			array(
				'id'   => $term->term_id,
				'name' => 'Incorrect permissions',
			)
		);

		$this->assertAbilityDenied( $result, 'A subscriber should not update terms.' );
		$this->assertSame( $term->name, get_term( $term->term_id )->name, 'The term should be untouched.' );
	}

	/**
	 * A category is moved under a parent.
	 *
	 * @since x.x.x
	 */
	public function test_update_item_parent(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$parent = get_term_by( 'id', self::factory()->category->create(), 'category' );
		$term   = get_term_by( 'id', self::factory()->category->create(), 'category' );

		$result = $this->update(
			array(
				'id'     => $term->term_id,
				'parent' => $parent->term_id,
				'fields' => array( 'parent' ),
			)
		);

		$this->assertIsArray( $result, 'The category should be updated.' );
		$this->assertSame( $parent->term_id, $result['parent'], 'The category should have the parent.' );
	}

	/**
	 * A parent of 0 moves a category to the top level.
	 *
	 * @since x.x.x
	 */
	public function test_update_item_remove_parent(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$old_parent_term = get_term_by( 'id', self::factory()->category->create(), 'category' );
		$new_parent_id   = 0;

		$term = get_term_by(
			'id',
			self::factory()->category->create(
				array(
					'parent' => $old_parent_term->term_id,
				)
			),
			'category'
		);

		$this->assertSame( $old_parent_term->term_id, $term->parent, 'The category should start under the parent.' );

		$result = $this->update(
			array(
				'id'     => $term->term_id,
				'parent' => $new_parent_id,
				'fields' => array( 'parent' ),
			)
		);

		$this->assertIsArray( $result, 'The category should be updated.' );
		$this->assertSame( $new_parent_id, $result['parent'], 'The category should be top-level.' );
	}

	/**
	 * A parent that does not exist is reported, and the term is left alone.
	 *
	 * @since x.x.x
	 */
	public function test_update_item_invalid_parent(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$term = get_term_by( 'id', self::factory()->category->create(), 'category' );

		$result = $this->update(
			array(
				'id'     => $term->term_id,
				'parent' => REST_TESTS_IMPOSSIBLY_HIGH_NUMBER,
			)
		);

		$this->assertAbilityError( $result, 'terms_term_invalid', 'A missing parent should be reported.' );
		$this->assertSame( 400, $result->get_error_data()['status'], 'A missing parent should be a bad request.' );
		$this->assertSame( $term->parent, get_term( $term->term_id )->parent, 'The category should keep its parent.' );
	}

	/**
	 * An update that changes nothing still succeeds, also when run twice.
	 *
	 * @since x.x.x
	 */
	public function test_update_item_no_change(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$term = get_term_by( 'id', self::factory()->tag->create(), 'post_tag' );

		$this->assertIsArray( $this->update( array( 'id' => $term->term_id ) ), 'An empty update should succeed.' );

		$input = array(
			'id'   => $term->term_id,
			'slug' => $term->slug,
		);

		// Run twice to make sure that the update still succeeds
		// even if no DB rows are updated.
		$this->assertIsArray( $this->update( $input ), 'Keeping the slug should succeed.' );
		$this->assertIsArray( $this->update( $input ), 'Keeping the slug again should succeed.' );
	}

	/**
	 * A user granted `edit_term` can update a tag.
	 *
	 * @ticket 38505
	 *
	 * @since x.x.x
	 */
	public function test_update_item_with_edit_term_cap_granted(): void {
		$this->login_as( 'subscriber' );
		$this->register_ability();

		$term = self::factory()->tag->create_and_get();

		add_filter( 'map_meta_cap', array( $this, 'grant_edit_term' ), 10, 2 );
		$result = $this->update(
			array(
				'id'     => $term->term_id,
				'name'   => 'New Name',
				'fields' => array( 'name' ),
			)
		);

		$this->assertIsArray( $result, 'The tag should be updated.' );
		$this->assertSame( 'New Name', $result['name'], 'The new name should be returned.' );
	}

	/**
	 * A user denied `edit_term` cannot update a tag, even an administrator.
	 *
	 * @ticket 38505
	 *
	 * @since x.x.x
	 */
	public function test_update_item_with_edit_term_cap_revoked(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$term = self::factory()->tag->create_and_get();

		add_filter( 'map_meta_cap', array( $this, 'revoke_edit_term' ), 10, 2 );
		$result = $this->update(
			array(
				'id'   => $term->term_id,
				'name' => 'New Name',
			)
		);

		$this->assertAbilityDenied( $result, 'A user without edit_term should not update the tag.' );
	}

	/**
	 * A parent cannot be set in a taxonomy that is not hierarchical.
	 *
	 * @since x.x.x
	 */
	public function test_update_item_parent_non_hierarchical_taxonomy(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$term = get_term_by( 'id', self::factory()->tag->create(), 'post_tag' );

		$result = $this->update(
			array(
				'id'     => $term->term_id,
				'parent' => REST_TESTS_IMPOSSIBLY_HIGH_NUMBER,
			)
		);

		$this->assertAbilityError( $result, 'terms_taxonomy_not_hierarchical', 'A parent should not be set on a tag.' );
		$this->assertSame( 400, $result->get_error_data()['status'], 'A parent on a tag should be a bad request.' );
	}

	/**
	 * Updating a term needs the capability to edit it, which administrators and editors
	 * have by default, and no role can update a term of a taxonomy that is not exposed.
	 *
	 * @dataProvider data_term_roles
	 *
	 * @since x.x.x
	 *
	 * @param string $role       The role, or an empty string for a logged-out visitor.
	 * @param bool   $can_manage Whether the role can manage terms.
	 */
	public function test_roles_updating_terms( string $role, bool $can_manage ): void {
		$this->register_write_test_taxonomies();

		$term_ids = array();
		foreach ( array( 'category', 'post_tag', 'wpai_genre', 'wpai_mood', 'wpai_secret' ) as $taxonomy ) {
			$term_ids[ $taxonomy ] = self::factory()->term->create(
				array(
					'taxonomy' => $taxonomy,
					'name'     => 'Original',
				)
			);
		}

		if ( '' !== $role ) {
			$this->login_as( $role );
		}
		$this->register_ability();

		foreach ( $term_ids as $taxonomy => $term_id ) {
			$result = $this->update(
				array(
					'id'   => $term_id,
					'name' => 'Updated by a role',
				)
			);

			if ( $can_manage && 'wpai_secret' !== $taxonomy ) {
				$this->assertIsArray( $result, "The role should update a {$taxonomy} term." );
				$this->assertSame( 'Updated by a role', get_term( $term_id )->name, "The {$taxonomy} term should be renamed." );
			} else {
				$this->assertAbilityDenied( $result, "The role should not update a {$taxonomy} term." );
				$this->assertSame( 'Original', get_term( $term_id )->name, "The {$taxonomy} term should be untouched." );
			}
		}
	}

	/**
	 * Renaming a term keeps its slug, and clearing the slug makes one from the name.
	 *
	 * @since x.x.x
	 */
	public function test_renaming_keeps_the_slug_until_it_is_cleared(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$term_id = self::factory()->tag->create(
			array(
				'name' => 'Red',
				'slug' => 'red',
			)
		);
		$fields  = array( 'name', 'slug' );

		$this->assertSame(
			array(
				'id'   => $term_id,
				'name' => 'Crimson',
				'slug' => 'red',
			),
			$this->update(
				array(
					'id'     => $term_id,
					'name'   => 'Crimson',
					'fields' => $fields,
				)
			),
			'Renaming should keep the slug.'
		);

		$this->assertSame(
			array(
				'id'   => $term_id,
				'name' => 'Crimson',
				'slug' => 'crimson',
			),
			$this->update(
				array(
					'id'     => $term_id,
					'slug'   => '',
					'fields' => $fields,
				)
			),
			'Clearing the slug should make one from the name.'
		);
	}

	/**
	 * A slug taken by another term is refused, while a taken name is accepted.
	 *
	 * @since x.x.x
	 */
	public function test_update_item_slug_must_be_unique_but_the_name_need_not_be(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		self::factory()->category->create( array( 'name' => 'Fruit' ) );
		$veg = self::factory()->category->create( array( 'name' => 'Veg' ) );

		$taken_slug = $this->update(
			array(
				'id'   => $veg,
				'slug' => 'fruit',
			)
		);
		$this->assertAbilityError( $taken_slug, 'duplicate_term_slug', 'A taken slug should be refused.' );
		$this->assertSame( 'veg', get_term( $veg )->slug, 'The slug should be untouched.' );

		$this->assertSame(
			array(
				'id'   => $veg,
				'name' => 'Fruit',
				'slug' => 'veg',
			),
			$this->update(
				array(
					'id'     => $veg,
					'name'   => 'Fruit',
					'fields' => array( 'name', 'slug' ),
				)
			),
			'A taken name should be accepted.'
		);
	}

	/**
	 * Moving a term under one of its descendants, or under itself, moves it to the top
	 * level instead.
	 *
	 * @since x.x.x
	 */
	public function test_reparenting_under_a_descendant_moves_the_term_to_the_top_level(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$fruit = self::factory()->category->create( array( 'name' => 'Fruit' ) );
		$apple = self::factory()->category->create(
			array(
				'name'   => 'Apple',
				'parent' => $fruit,
			)
		);
		$kiwi  = self::factory()->category->create(
			array(
				'name'   => 'Kiwi',
				'parent' => $apple,
			)
		);

		$this->assertSame(
			array(
				'id'     => $fruit,
				'parent' => 0,
			),
			$this->update(
				array(
					'id'     => $fruit,
					'parent' => $kiwi,
					'fields' => array( 'parent' ),
				)
			),
			'A term moved under its own descendant should stay at the top level.'
		);
		$this->assertSame( $fruit, get_term( $apple )->parent, 'The child should keep its parent.' );
		$this->assertSame( $apple, get_term( $kiwi )->parent, 'The grandchild should keep its parent.' );

		$this->assertSame(
			array(
				'id'     => $apple,
				'parent' => 0,
			),
			$this->update(
				array(
					'id'     => $apple,
					'parent' => $apple,
					'fields' => array( 'parent' ),
				)
			),
			'A term moved under itself should move to the top level.'
		);
	}

	/**
	 * A parent must be a term of the same taxonomy. A tag, a term of a taxonomy that is not
	 * exposed, and a missing term are all reported alike.
	 *
	 * @since x.x.x
	 */
	public function test_update_item_parent_must_be_in_the_taxonomy(): void {
		$this->register_write_test_taxonomies();
		$this->login_as( 'editor' );
		$this->register_ability();

		$term_id = self::factory()->category->create();
		$parents = array(
			'a tag'              => self::factory()->tag->create(),
			'a term not exposed' => self::factory()->term->create( array( 'taxonomy' => 'wpai_secret' ) ),
			'a missing term'     => REST_TESTS_IMPOSSIBLY_HIGH_NUMBER,
		);

		foreach ( $parents as $label => $parent ) {
			$result = $this->update(
				array(
					'id'     => $term_id,
					'parent' => $parent,
				)
			);

			$this->assertAbilityError( $result, 'terms_term_invalid', "A parent that is {$label} should be reported." );
			$this->assertSame( 'Parent term does not exist.', $result->get_error_message(), "A parent that is {$label} should be reported like a missing one." );
		}

		$this->assertSame( 0, get_term( $term_id )->parent, 'The category should stay at the top level.' );
	}

	/**
	 * The `taxonomy` guard must match the taxonomy of the term.
	 *
	 * @since x.x.x
	 */
	public function test_update_item_with_a_taxonomy_guard(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$term_id = self::factory()->category->create( array( 'name' => 'Fruit' ) );

		$mismatched = $this->update(
			array(
				'id'       => $term_id,
				'taxonomy' => 'post_tag',
				'name'     => 'Renamed',
			)
		);
		$this->assertAbilityDenied( $mismatched, 'A mismatched taxonomy guard should deny the update.' );
		$this->assertSame( 'Fruit', get_term( $term_id )->name, 'The category should be untouched.' );

		$this->assertSame(
			array(
				'id'   => $term_id,
				'name' => 'Renamed',
			),
			$this->update(
				array(
					'id'       => $term_id,
					'taxonomy' => 'category',
					'name'     => 'Renamed',
					'fields'   => array( 'name' ),
				)
			),
			'A matching taxonomy guard should allow the update.'
		);
	}

	/**
	 * An update saves the term even when nothing changes, so the term update hooks fire.
	 *
	 * @since x.x.x
	 */
	public function test_update_saves_the_term_even_without_changes(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$term_id  = self::factory()->tag->create();
		$calls    = array();
		$callback = static function ( $edited_term_id ) use ( &$calls ): void {
			$calls[] = $edited_term_id;
		};

		add_action( 'edited_term', $callback );

		$this->assertIsArray( $this->update( array( 'id' => $term_id ) ), 'An empty update should succeed.' );
		$this->assertSame( array( $term_id ), $calls, 'The term should be saved once.' );
	}

	/**
	 * Without `fields`, the updated term carries the lean default set, and `parent` only for
	 * hierarchical taxonomies.
	 *
	 * @since x.x.x
	 */
	public function test_default_fields(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$category = $this->update( array( 'id' => self::factory()->category->create() ) );
		$tag      = $this->update( array( 'id' => self::factory()->tag->create() ) );

		$this->assertSame( array( 'id', 'count', 'name', 'slug', 'taxonomy', 'parent' ), array_keys( $category ), 'An updated category should have the default fields.' );
		$this->assertSame( array( 'id', 'count', 'name', 'slug', 'taxonomy' ), array_keys( $tag ), 'An updated tag should have the default fields except the parent.' );
	}

	/**
	 * `fields` limits the updated term to the requested fields, always with the ID, and a
	 * tag has no parent even when it is requested.
	 *
	 * @since x.x.x
	 */
	public function test_fields_always_include_id(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$term_id = self::factory()->category->create();
		$tag_id  = self::factory()->tag->create();

		$this->assertSame(
			array(
				'id'          => $term_id,
				'description' => 'A <em>sweet</em> category.',
				'link'        => get_term_link( $term_id, 'category' ),
			),
			$this->update(
				array(
					'id'          => $term_id,
					'description' => 'A <em>sweet</em> category.',
					'fields'      => array( 'description', 'link' ),
				)
			),
			'The updated category should carry the requested fields and its ID.'
		);

		$this->assertSame(
			array( 'id' => $tag_id ),
			$this->update(
				array(
					'id'     => $tag_id,
					'fields' => array( 'parent' ),
				)
			),
			'An updated tag should have no parent.'
		);
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

		$term_id = self::factory()->category->create( array( 'name' => 'Fruit' ) );
		$input   = array(
			'id'   => $term_id,
			'name' => 'Renamed',
		);
		$extras  = array(
			'a count'          => array( 'count' => 3 ),
			'a link'           => array( 'link' => 'https://example.org/fruit/' ),
			'meta'             => array( 'meta' => array( 'color' => 'red' ) ),
			'a context'        => array( 'context' => 'edit' ),
			'a force flag'     => array( 'force' => true ),
			'an unknown field' => array( 'fields' => array( 'meta' ) ),
			'a repeated field' => array( 'fields' => array( 'name', 'name' ) ),
		);

		foreach ( $extras as $label => $extra ) {
			$this->assertAbilityError( $this->update( $input + $extra ), 'ability_invalid_input', "Input with {$label} should fail validation." );
		}

		$this->assertSame( 'Fruit', get_term( $term_id )->name, 'The category should be untouched.' );
	}

	/**
	 * An ID, a parent, and a field list sent as strings are cast like typed inputs.
	 *
	 * @since x.x.x
	 */
	public function test_string_inputs_are_cast(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$fruit = self::factory()->category->create( array( 'name' => 'Fruit' ) );
		$apple = self::factory()->category->create( array( 'name' => 'Apple' ) );

		$this->assertSame(
			array(
				'id'     => $apple,
				'name'   => 'Apple',
				'parent' => $fruit,
			),
			$this->update(
				array(
					'id'     => (string) $apple,
					'parent' => (string) $fruit,
					'fields' => 'name,parent',
				)
			),
			'A string ID, parent, and field list should be applied.'
		);

		$this->assertSame(
			array(
				'id'     => $apple,
				'parent' => 0,
			),
			$this->update(
				array(
					'id'     => (string) $apple,
					'parent' => '0',
					'fields' => 'parent',
				)
			),
			'A string parent of 0 should move the category to the top level.'
		);
	}
}
