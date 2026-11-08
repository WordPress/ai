<?php
/**
 * Canonical WordPress abilities: `wp/user-delete` ability.
 *
 * Ported from WordPress/ai PR #1105 (https://github.com/WordPress/ai/pull/1105) by @jorgefilipecosta.
 *
 * @package WordPress\Abilities
 */
declare( strict_types = 1 );

namespace WordPress\Abilities\Users;

use WP_Error;

/**
 * Registers the `wp/user-delete` ability, which deletes a user permanently (the deleted posts are reassigned or trashed). All
 * logic is shared with the sibling user write abilities in
 * {@see Abstract_User_Write} (which also reuses the `core/users-query` field
 * projection), and this class only wires it into the authoring surface.
 *
 * @package WordPress\Abilities
 */
final class User_Delete extends Abstract_User_Write {
	/**
	 * The canonical ability name, under the experimental tier prefix.
	 *
	 * @return string
	 */
	protected function ability_name(): string {
		return 'wp/user-delete';
	}

	/**
	 * The ability label, as the source PR registers it.
	 *
	 * @return string
	 */
	protected function label(): string {
		return __( 'Delete User' );
	}

	/**
	 * The ability description, as the source PR registers it.
	 *
	 * @return string
	 */
	protected function description(): string {
		return __( "Permanently deletes a user by ID; users cannot be trashed. `reassign` takes the ID of the user who receives the deleted user's posts and links, or false to delete them, which moves posts and pages to the trash. Returns the deleted user as it was before the deletion; use `fields` to choose which user fields are returned. Not supported on multisite, and users cannot delete their own account. Requires an authenticated user who can delete the user." );
	}

	/**
	 * The ability category, the user category.
	 *
	 * @return string
	 */
	protected function category_slug(): string {
		return self::CATEGORY;
	}

	/**
	 * The input JSON Schema, as the source PR builds it when registering:
	 * `reassign` is required and takes the ID of the reassigning user, or false
	 * to delete the deleted user's posts.
	 *
	 * @return array<string, mixed> The input JSON Schema.
	 */
	protected function input_schema(): array {
		return $this->get_user_delete_input_schema();
	}

	/**
	 * The output JSON Schema: the deleted user as it was, through the
	 * `core/users-query` field projection.
	 *
	 * @return array<string, mixed> The output JSON Schema.
	 */
	protected function output_schema(): array {
		return $this->get_user_output_schema();
	}

	/**
	 * The execute callback, as the source PR registers it.
	 *
	 * @return callable(mixed): (array<string, mixed>|WP_Error) The execute callback.
	 */
	protected function make_execute_callback(): callable {
		return function ( $input = array() ) {
			return $this->execute_user_delete( $input );
		};
	}

	/**
	 * The permission callback, as the source PR registers it.
	 *
	 * @return callable(mixed): bool The permission callback.
	 */
	protected function make_permission_callback(): callable {
		return function ( $input = array() ): bool {
			return $this->check_delete_permission( $input );
		};
	}

	/**
	 * The authored ability meta, exactly as the source PR registers it.
	 *
	 * @return array<string, mixed> The ability meta.
	 */
	protected function ability_meta(): array {
		return array(
			'annotations'  => array(
				'readonly'    => false,
				'destructive' => true,
				/*
				 * Repeating a deletion has no further effect; the Abilities API serves
				 * destructive idempotent abilities over the DELETE method.
				 */
				'idempotent'  => true,
				'open_world'  => false,
			),
			'public'       => true,
			'show_in_rest' => true,
		);
	}

	/**
	 * No request-level capability floor beyond the permission's own checks.
	 *
	 * The source PR authorizes through the permission callback only.
	 *
	 * @return list<string> The empty capability floor.
	 */
	protected function required_capabilities(): array {
		return array();
	}
}
