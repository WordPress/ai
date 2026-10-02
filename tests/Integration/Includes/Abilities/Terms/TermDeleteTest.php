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
}
