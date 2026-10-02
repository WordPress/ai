<?php
/**
 * Integration tests for the core/term-delete Ability provided by the plugin.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Terms
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Terms;

use WP_Term;
use WordPress\AI\Abilities\Terms\Terms;

/**
 * Term delete ability test case.
 *
 * A test named like a WP_Test_REST_Categories_Controller or WP_Test_REST_Tags_Controller
 * test ports it; a test both controllers have runs for each of their taxonomies.
 *
 * @since x.x.x
 */
class TermDeleteTest extends Terms_Ability_TestCase {

	/**
	 * Deletes a term through the ability and returns the result.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $input The ability input.
	 * @return mixed The ability result.
	 */
	private function delete( array $input ) {
		return $this->execute_ability( 'core/term-delete', $input );
	}

	/**
	 * Maps the `delete_term` capability to `read`, granting it to every user.
	 *
	 * @since x.x.x
	 *
	 * @param array<string> $caps The primitive capabilities the capability maps to.
	 * @param string        $cap  The capability being checked.
	 * @return array<string> The primitive capabilities.
	 */
	public function grant_delete_term( $caps, $cap ) {
		if ( 'delete_term' === $cap ) {
			$caps = array( 'read' );
		}
		return $caps;
	}

	/**
	 * Maps the `delete_term` capability to `do_not_allow`, revoking it from every user.
	 *
	 * @since x.x.x
	 *
	 * @param array<string> $caps The primitive capabilities the capability maps to.
	 * @param string        $cap  The capability being checked.
	 * @return array<string> The primitive capabilities.
	 */
	public function revoke_delete_term( $caps, $cap ) {
		if ( 'delete_term' === $cap ) {
			$caps = array( 'do_not_allow' );
		}
		return $caps;
	}

	/**
	 * The ability is registered as a closed-world, idempotent destructive write, so it runs
	 * over DELETE. It takes an ID, an optional taxonomy guard, the force flag, and a field
	 * selection, and returns the deleted term under `previous`.
	 *
	 * @since x.x.x
	 */
	public function test_registers_core_term_delete_ability(): void {
		$this->register_ability();

		$ability     = wp_get_ability( 'core/term-delete' );
		$annotations = $ability->get_meta_item( 'annotations', array() );
		$schema      = $ability->get_input_schema();
		$output      = $ability->get_output_schema();

		$this->assertSame( 'Term Delete', $ability->get_label(), 'The registered ability should use the expected label.' );
		$this->assertSame( 'content', $ability->get_category(), 'The registered ability should use the content category.' );
		$this->assertTrue( $ability->get_meta_item( 'show_in_rest', false ), 'The ability should be exposed in REST.' );
		$this->assertFalse( $annotations['readonly'], 'The ability should not be marked read-only.' );
		$this->assertTrue( $annotations['destructive'], 'Deleting a term is destructive.' );
		$this->assertTrue( $annotations['idempotent'], 'Repeating a deletion has no further effect.' );
		$this->assertFalse( $annotations['open_world'], 'The ability only writes to the local database.' );
		$this->assertSame( array( 'id' ), $schema['required'], 'Only the ID should be required.' );
		$this->assertFalse( $schema['additionalProperties'], 'Unknown properties should be rejected.' );
		$this->assertSame( array( 'id', 'taxonomy', 'force', 'fields' ), array_keys( $schema['properties'] ), 'The input should take the ID, a taxonomy guard, the force flag, and the field selection.' );
		$this->assertSame( array( 'deleted', 'previous' ), $output['required'], 'The output should report the deletion and the previous term.' );
		$this->assertFalse( $output['additionalProperties'], 'The output should have no other properties.' );
		$this->assertSame( wp_get_ability( 'core/terms-query' )->get_output_schema()['oneOf'][0], $output['properties']['previous'], 'The previous term should have the shape of a queried term.' );
	}

	/**
	 * A forced deletion removes the term and returns it under `previous`.
	 *
	 * @dataProvider data_core_taxonomies
	 *
	 * @since x.x.x
	 *
	 * @param string $taxonomy The taxonomy.
	 */
	public function test_delete_item( string $taxonomy ): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$term = get_term_by(
			'id',
			self::factory()->term->create(
				array(
					'taxonomy' => $taxonomy,
					'name'     => 'Deleted Term',
				)
			),
			$taxonomy
		);

		$result = $this->delete(
			array(
				'id'    => $term->term_id,
				'force' => true,
			)
		);

		$this->assertIsArray( $result, 'The term should be deleted.' );
		$this->assertTrue( $result['deleted'], 'The term should be reported as deleted.' );
		$this->assertSame( 'Deleted Term', $result['previous']['name'], 'The previous term should carry its name.' );
		$this->assertNull( get_term( $term->term_id ), 'The term should no longer exist.' );
	}

	/**
	 * Terms cannot be trashed, so a deletion without `force` is refused.
	 *
	 * @dataProvider data_core_taxonomies
	 *
	 * @since x.x.x
	 *
	 * @param string $taxonomy The taxonomy.
	 */
	public function test_delete_item_no_trash( string $taxonomy ): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$term = get_term_by(
			'id',
			self::factory()->term->create(
				array(
					'taxonomy' => $taxonomy,
					'name'     => 'Deleted Term',
				)
			),
			$taxonomy
		);

		$result = $this->delete( array( 'id' => $term->term_id ) );
		$this->assertAbilityError( $result, 'terms_trash_not_supported', 'A deletion without force should be refused.' );
		$this->assertSame( 501, $result->get_error_data()['status'], 'Trashing should be reported as not supported.' );

		$result = $this->delete(
			array(
				'id'    => $term->term_id,
				'force' => 'false',
			)
		);
		$this->assertAbilityError( $result, 'terms_trash_not_supported', 'A false force should be refused too.' );
		$this->assertSame( 501, $result->get_error_data()['status'], 'Trashing should be reported as not supported.' );

		$this->assertInstanceOf( WP_Term::class, get_term( $term->term_id ), 'The term should still exist.' );
	}

	/**
	 * A taxonomy guard that does not exist is rejected.
	 *
	 * @since x.x.x
	 */
	public function test_delete_item_invalid_taxonomy(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$result = $this->delete(
			array(
				'id'       => REST_TESTS_IMPOSSIBLY_HIGH_NUMBER,
				'taxonomy' => 'invalid-taxonomy',
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
	public function test_delete_item_invalid_term( string $taxonomy ): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$input = array(
			'id'       => REST_TESTS_IMPOSSIBLY_HIGH_NUMBER,
			'taxonomy' => $taxonomy,
		);

		$this->assertAbilityDenied( $this->delete( $input ), 'A missing term should be denied.' );

		$direct = ( new Terms() )->execute_term_delete( $input );
		$this->assertAbilityError( $direct, 'terms_term_invalid', 'A direct call should report the missing term.' );
		$this->assertSame( 404, $direct->get_error_data()['status'], 'A missing term should not be found.' );
	}

	/**
	 * A subscriber cannot delete terms.
	 *
	 * @dataProvider data_core_taxonomies
	 *
	 * @since x.x.x
	 *
	 * @param string $taxonomy The taxonomy.
	 */
	public function test_delete_item_incorrect_permissions( string $taxonomy ): void {
		$this->login_as( 'subscriber' );
		$this->register_ability();

		$term = get_term_by( 'id', self::factory()->term->create( array( 'taxonomy' => $taxonomy ) ), $taxonomy );

		$this->assertAbilityDenied( $this->delete( array( 'id' => $term->term_id ) ), 'A subscriber should not delete terms.' );
		$this->assertInstanceOf( WP_Term::class, get_term( $term->term_id ), 'The term should still exist.' );
	}

	/**
	 * A user granted `delete_term` can delete a tag.
	 *
	 * @ticket 38505
	 *
	 * @since x.x.x
	 */
	public function test_delete_item_with_delete_term_cap_granted(): void {
		$this->login_as( 'subscriber' );
		$this->register_ability();

		$term = get_term_by( 'id', self::factory()->tag->create( array( 'name' => 'Deleted Tag' ) ), 'post_tag' );

		add_filter( 'map_meta_cap', array( $this, 'grant_delete_term' ), 10, 2 );
		$result = $this->delete(
			array(
				'id'    => $term->term_id,
				'force' => true,
			)
		);

		$this->assertIsArray( $result, 'The tag should be deleted.' );
		$this->assertTrue( $result['deleted'], 'The tag should be reported as deleted.' );
		$this->assertSame( 'Deleted Tag', $result['previous']['name'], 'The previous tag should carry its name.' );
	}

	/**
	 * A user denied `delete_term` cannot delete a tag, even an administrator.
	 *
	 * @ticket 38505
	 *
	 * @since x.x.x
	 */
	public function test_delete_item_with_delete_term_cap_revoked(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$term = get_term_by( 'id', self::factory()->tag->create( array( 'name' => 'Deleted Tag' ) ), 'post_tag' );

		add_filter( 'map_meta_cap', array( $this, 'revoke_delete_term' ), 10, 2 );
		$result = $this->delete(
			array(
				'id'    => $term->term_id,
				'force' => true,
			)
		);

		$this->assertAbilityDenied( $result, 'A user without delete_term should not delete the tag.' );
		$this->assertInstanceOf( WP_Term::class, get_term( $term->term_id ), 'The tag should still exist.' );
	}

	/**
	 * Deleting a term needs the capability to delete it, which administrators and editors
	 * have by default, and no role can delete a term of a taxonomy that is not exposed.
	 *
	 * @dataProvider data_term_roles
	 *
	 * @since x.x.x
	 *
	 * @param string $role       The role, or an empty string for a logged-out visitor.
	 * @param bool   $can_manage Whether the role can manage terms.
	 */
	public function test_roles_deleting_terms( string $role, bool $can_manage ): void {
		$this->register_write_test_taxonomies();

		$term_ids = array();
		foreach ( array( 'category', 'post_tag', 'wpai_genre', 'wpai_mood', 'wpai_secret' ) as $taxonomy ) {
			$term_ids[ $taxonomy ] = self::factory()->term->create( array( 'taxonomy' => $taxonomy ) );
		}

		if ( '' !== $role ) {
			$this->login_as( $role );
		}
		$this->register_ability();

		foreach ( $term_ids as $taxonomy => $term_id ) {
			$result = $this->delete(
				array(
					'id'    => $term_id,
					'force' => true,
				)
			);

			if ( $can_manage && 'wpai_secret' !== $taxonomy ) {
				$this->assertIsArray( $result, "The role should delete a {$taxonomy} term." );
				$this->assertNull( get_term( $term_id ), "The {$taxonomy} term should no longer exist." );
			} else {
				$this->assertAbilityDenied( $result, "The role should not delete a {$taxonomy} term." );
				$this->assertInstanceOf( WP_Term::class, get_term( $term_id ), "The {$taxonomy} term should still exist." );
			}
		}
	}

	/**
	 * The default category cannot be deleted, not even by a user granted the capability.
	 *
	 * @since x.x.x
	 */
	public function test_default_category_cannot_be_deleted(): void {
		$this->login_as( 'administrator' );
		$this->register_ability();

		$default = (int) get_option( 'default_category' );
		$input   = array(
			'id'    => $default,
			'force' => true,
		);

		$this->assertAbilityDenied( $this->delete( $input ), 'The default category should not be deleted.' );

		$direct = ( new Terms() )->execute_term_delete( $input );
		$this->assertAbilityError( $direct, 'terms_cannot_delete', 'A direct call should not delete the default category either.' );
		$this->assertSame( 403, $direct->get_error_data()['status'], 'The denial should be forbidden.' );

		add_filter( 'map_meta_cap', array( $this, 'grant_delete_term' ), 10, 2 );
		$granted = $this->delete( $input );
		$this->assertAbilityError( $granted, 'terms_cannot_delete', 'A granted capability should still not delete the default category.' );
		$this->assertSame( 500, $granted->get_error_data()['status'], 'The refused deletion should be a server error.' );

		$this->assertInstanceOf( WP_Term::class, get_term( $default, 'category' ), 'The default category should still exist.' );
	}

	/**
	 * Deleting a category moves its children under its own parent.
	 *
	 * @since x.x.x
	 */
	public function test_deleting_a_parent_moves_its_children_up(): void {
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
		$lime  = self::factory()->category->create(
			array(
				'name'   => 'Lime',
				'parent' => $apple,
			)
		);

		$result = $this->delete(
			array(
				'id'     => $apple,
				'force'  => true,
				'fields' => array( 'parent' ),
			)
		);

		$this->assertSame(
			array(
				'deleted'  => true,
				'previous' => array(
					'id'     => $apple,
					'parent' => $fruit,
				),
			),
			$result,
			'The category should be deleted.'
		);
		$this->assertSame( $fruit, get_term( $kiwi )->parent, 'A child should move under the deleted category\'s parent.' );
		$this->assertSame( $fruit, get_term( $lime )->parent, 'Every child should move under the deleted category\'s parent.' );
	}

	/**
	 * The `taxonomy` guard must match the taxonomy of the term.
	 *
	 * @since x.x.x
	 */
	public function test_delete_item_with_a_taxonomy_guard(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$term_id = self::factory()->category->create();

		$mismatched = $this->delete(
			array(
				'id'       => $term_id,
				'taxonomy' => 'post_tag',
				'force'    => true,
			)
		);
		$this->assertAbilityDenied( $mismatched, 'A mismatched taxonomy guard should deny the deletion.' );
		$this->assertInstanceOf( WP_Term::class, get_term( $term_id ), 'The category should still exist.' );

		$matching = $this->delete(
			array(
				'id'       => $term_id,
				'taxonomy' => 'category',
				'force'    => true,
			)
		);
		$this->assertIsArray( $matching, 'A matching taxonomy guard should allow the deletion.' );
		$this->assertNull( get_term( $term_id ), 'The category should no longer exist.' );
	}

	/**
	 * Without `fields`, the deleted term carries the lean default set, and `parent` only for
	 * hierarchical taxonomies.
	 *
	 * @since x.x.x
	 */
	public function test_default_fields(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$category = $this->delete(
			array(
				'id'    => self::factory()->category->create(),
				'force' => true,
			)
		);
		$tag      = $this->delete(
			array(
				'id'    => self::factory()->tag->create(),
				'force' => true,
			)
		);

		$this->assertSame( array( 'id', 'count', 'name', 'slug', 'taxonomy', 'parent' ), array_keys( $category['previous'] ), 'A deleted category should have the default fields.' );
		$this->assertSame( array( 'id', 'count', 'name', 'slug', 'taxonomy' ), array_keys( $tag['previous'] ), 'A deleted tag should have the default fields except the parent.' );
	}

	/**
	 * Inputs the ability does not take, and unknown or repeated fields, are rejected before
	 * anything is deleted.
	 *
	 * @since x.x.x
	 */
	public function test_unknown_inputs_are_rejected(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$term_id = self::factory()->category->create();
		$input   = array(
			'id'    => $term_id,
			'force' => true,
		);
		$extras  = array(
			'a name'           => array( 'name' => 'Renamed' ),
			'a slug'           => array( 'slug' => 'renamed' ),
			'a parent'         => array( 'parent' => 0 ),
			'a context'        => array( 'context' => 'edit' ),
			'an unknown field' => array( 'fields' => array( 'meta' ) ),
			'a repeated field' => array( 'fields' => array( 'name', 'name' ) ),
		);

		foreach ( $extras as $label => $extra ) {
			$this->assertAbilityError( $this->delete( $input + $extra ), 'ability_invalid_input', "Input with {$label} should fail validation." );
		}

		$this->assertInstanceOf( WP_Term::class, get_term( $term_id ), 'The category should still exist.' );
	}

	/**
	 * An ID, a force flag, and a field list sent as strings, as a DELETE request delivers
	 * them on WordPress 7.0, are cast like typed inputs.
	 *
	 * @since x.x.x
	 */
	public function test_string_inputs_are_cast(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$kept = self::factory()->tag->create();
		foreach ( array( 'false', '0' ) as $force ) {
			$result = $this->delete(
				array(
					'id'    => (string) $kept,
					'force' => $force,
				)
			);
			$this->assertAbilityError( $result, 'terms_trash_not_supported', "A force of '{$force}' should be refused." );
		}
		$this->assertInstanceOf( WP_Term::class, get_term( $kept ), 'The tag should still exist.' );

		foreach ( array( 'true', '1' ) as $force ) {
			$term_id = self::factory()->tag->create( array( 'name' => "Forced {$force}" ) );

			$this->assertSame(
				array(
					'deleted'  => true,
					'previous' => array(
						'id'   => $term_id,
						'name' => "Forced {$force}",
					),
				),
				$this->delete(
					array(
						'id'     => (string) $term_id,
						'force'  => $force,
						'fields' => 'id,name',
					)
				),
				"A force of '{$force}' should delete the tag."
			);
			$this->assertNull( get_term( $term_id ), "The tag forced with '{$force}' should no longer exist." );
		}
	}

	/**
	 * A term of a taxonomy that is not exposed, like a menu, is denied exactly like a
	 * missing term, so its existence cannot be probed.
	 *
	 * @since x.x.x
	 */
	public function test_hidden_and_missing_terms_are_denied_alike(): void {
		$this->register_write_test_taxonomies();
		$hidden = array(
			'wpai_secret' => self::factory()->term->create( array( 'taxonomy' => 'wpai_secret' ) ),
			'nav_menu'    => wp_create_nav_menu( 'Secret' ),
		);
		$this->login_as( 'administrator' );
		$this->register_ability();

		$terms          = new Terms();
		$missing        = array(
			'id'    => REST_TESTS_IMPOSSIBLY_HIGH_NUMBER,
			'force' => true,
		);
		$missing_result = $this->delete( $missing );
		$this->assertAbilityDenied( $missing_result, 'A missing term should be denied.' );

		foreach ( $hidden as $taxonomy => $term_id ) {
			$input  = array( 'id' => $term_id ) + $missing;
			$result = $this->delete( $input );

			$this->assertAbilityDenied( $result, "A {$taxonomy} term should be denied." );
			$this->assertSame( $missing_result->get_error_message(), $result->get_error_message(), "A {$taxonomy} term should be denied like a missing one." );
			$this->assertFalse( $terms->check_delete_permission( $input ), "A direct permission check should deny the {$taxonomy} term." );
			$this->assertEquals( $terms->execute_term_delete( $missing ), $terms->execute_term_delete( $input ), "A direct call should report the {$taxonomy} term like a missing one." );
			$this->assertInstanceOf( WP_Term::class, get_term( $term_id ), "The {$taxonomy} term should still exist." );
		}
	}

	/**
	 * A direct call fails closed when the input does not name a term by a well-formed ID and
	 * guard, rather than finding a term some other way: by its slug, by casting a malformed
	 * ID, or by ignoring the guard.
	 *
	 * @since x.x.x
	 */
	public function test_direct_call_with_a_malformed_lookup_fails_closed(): void {
		$this->login_as( 'administrator' );

		$term_id = self::factory()->category->create( array( 'slug' => 'fruit' ) );
		$terms   = new Terms();
		$inputs  = array(
			'no ID'                      => array(
				'taxonomy' => 'category',
				'slug'     => 'fruit',
			),
			'a guard that is not a name' => array(
				'id'       => $term_id,
				'taxonomy' => array( 'post_tag' ),
			),
			'an ID with a suffix'        => array( 'id' => "{$term_id}abc" ),
			'a decimal ID'               => array( 'id' => "{$term_id}.0" ),
			'a float ID'                 => array( 'id' => (float) $term_id ),
			'a boolean ID'               => array( 'id' => true ),
		);

		foreach ( $inputs as $label => $input ) {
			$input['force'] = true;

			$this->assertFalse( $terms->check_delete_permission( $input ), "A permission check with {$label} should deny." );

			$result = $terms->execute_term_delete( $input );
			$this->assertAbilityError( $result, 'terms_term_invalid', "A direct call with {$label} should report an invalid term." );
			$this->assertSame( 404, $result->get_error_data()['status'], "With {$label}, the term should not be found." );
		}

		$this->assertInstanceOf( WP_Term::class, get_term( $term_id ), 'The category should still exist.' );
	}

	/**
	 * A direct call checks the capability to delete the term once more before deleting it.
	 *
	 * @since x.x.x
	 */
	public function test_direct_call_rechecks_the_capability(): void {
		$term_id = self::factory()->category->create();
		$terms   = new Terms();
		$input   = array(
			'id'    => $term_id,
			'force' => true,
		);

		$this->login_as( 'subscriber' );
		$result = $terms->execute_term_delete( $input );
		$this->assertAbilityError( $result, 'terms_cannot_delete', 'A subscriber should not delete the category.' );
		$this->assertSame( 403, $result->get_error_data()['status'], 'A logged-in user should be forbidden.' );

		wp_set_current_user( 0 );
		$result = $terms->execute_term_delete( $input );
		$this->assertAbilityError( $result, 'terms_cannot_delete', 'A logged-out visitor should not delete the category.' );
		$this->assertSame( 401, $result->get_error_data()['status'], 'A logged-out visitor should be unauthorized.' );

		$this->assertInstanceOf( WP_Term::class, get_term( $term_id ), 'The category should still exist.' );
	}

	/**
	 * The run endpoint takes the input from the query string of a DELETE request, and
	 * rejects other methods.
	 *
	 * @since x.x.x
	 */
	public function test_run_endpoint_takes_query_string_input(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$term_id = self::factory()->category->create( array( 'name' => 'Fruit' ) );
		$input   = array(
			'id'     => (string) $term_id,
			'force'  => 'true',
			'fields' => 'name',
		);

		foreach ( array( 'GET', 'POST' ) as $method ) {
			$response = $this->run_ability( $method, 'core/term-delete', $input );
			$this->assertSame( 405, $response->get_status(), "A {$method} request should be rejected." );
			$this->assertSame( 'rest_ability_invalid_method', $response->as_error()->get_error_code(), "A {$method} request should need DELETE." );
		}
		$this->assertInstanceOf( WP_Term::class, get_term( $term_id ), 'A rejected request should delete nothing.' );

		$response = $this->run_ability( 'DELETE', 'core/term-delete', $input );
		$this->assertSame( 200, $response->get_status(), 'A DELETE request should run the ability.' );
		$this->assertSame(
			array(
				'deleted'  => true,
				'previous' => array(
					'id'   => $term_id,
					'name' => 'Fruit',
				),
			),
			$response->get_data(),
			'The deleted category should be returned.'
		);
		$this->assertNull( get_term( $term_id ), 'The category should no longer exist.' );
	}

	/**
	 * Every input but the taxonomy guard and the field selection is an argument of the
	 * endpoint that deletes terms, with the same type.
	 *
	 * @dataProvider data_core_taxonomies
	 *
	 * @since x.x.x
	 *
	 * @param string $taxonomy The taxonomy.
	 */
	public function test_inputs_are_rest_arguments( string $taxonomy ): void {
		$this->register_ability();

		$rest_args = $this->get_rest_route_args( rest_get_route_for_taxonomy_items( $taxonomy ) . '/(?P<id>[\d]+)', 'DELETE' );
		$inputs    = array_diff_key( wp_get_ability( 'core/term-delete' )->get_input_schema()['properties'], array_flip( array( 'taxonomy', 'fields' ) ) );

		foreach ( $inputs as $name => $schema ) {
			$this->assertArrayHasKey( $name, $rest_args, "The {$name} input should be a REST argument." );
			$this->assertSame( $rest_args[ $name ]['type'], $schema['type'], "The {$name} input should have the REST type." );
		}
	}

	/**
	 * The deleted term is returned as `core/terms-query` and the REST endpoint read it last.
	 *
	 * @dataProvider data_core_taxonomies
	 *
	 * @since x.x.x
	 *
	 * @param string $taxonomy The taxonomy.
	 */
	public function test_deleted_term_matches_its_last_read( string $taxonomy ): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$term_id = self::factory()->term->create(
			array(
				'taxonomy'    => $taxonomy,
				'description' => 'A <em>fresh</em> term.',
			)
		);
		$query   = $this->query_terms(
			array(
				'id'     => $term_id,
				'fields' => self::ALL_FIELDS,
			)
		);
		$rest    = $this->get_rest_term( $term_id );

		$result = $this->delete(
			array(
				'id'     => $term_id,
				'force'  => true,
				'fields' => self::ALL_FIELDS,
			)
		);

		$this->assertIsArray( $result, 'The term should be deleted.' );
		$this->assertSame( $query, $result['previous'], 'The deleted term should match its last read.' );
		$this->assertSame( $rest, $result['previous'], 'The deleted term should match the REST response.' );
	}
}
