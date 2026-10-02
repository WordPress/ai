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
}
