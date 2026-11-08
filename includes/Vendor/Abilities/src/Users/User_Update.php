<?php
/**
 * Canonical WordPress abilities: `wp/user-update` ability.
 *
 * Ported from WordPress/ai PR #1105 (https://github.com/WordPress/ai/pull/1105) by @jorgefilipecosta.
 *
 * @package WordPress\Abilities
 */
declare( strict_types = 1 );

namespace WordPress\Abilities\Users;

use WP_Error;

/**
 * Registers the `wp/user-update` ability, which updates a user by ID. All
 * logic is shared with the sibling user write abilities in
 * {@see Abstract_User_Write} (which also reuses the `core/users-query` field
 * projection), and this class only wires it into the authoring surface.
 *
 * @package WordPress\Abilities
 */
final class User_Update extends Abstract_User_Write {
	/**
	 * The canonical ability name, under the experimental tier prefix.
	 *
	 * @return string
	 */
	protected function ability_name(): string {
		return 'wp/user-update';
	}

	/**
	 * The ability label, as the source PR registers it.
	 *
	 * @return string
	 */
	protected function label(): string {
		return __( 'Update User' );
	}

	/**
	 * The ability description, as the source PR registers it.
	 *
	 * @return string
	 */
	protected function description(): string {
		return __( 'Updates a user by ID. Accepts a display name, first and last name, email address, URL, description, locale, nickname, slug, roles, and password; the username cannot be changed. Returns the updated user; use `fields` to choose which user fields are returned. Requires an authenticated user who can edit the user, or who can promote the user when only the roles are given.' );
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
	 * The input JSON Schema, as the source PR builds it when registering.
	 *
	 * @return array<string, mixed> The input JSON Schema.
	 */
	protected function input_schema(): array {
		return $this->get_user_update_input_schema();
	}

	/**
	 * The output JSON Schema: the written user through the `core/users-query`
	 * field projection.
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
			return $this->execute_user_update( $input );
		};
	}

	/**
	 * The permission callback, as the source PR registers it.
	 *
	 * @return callable(mixed): bool The permission callback.
	 */
	protected function make_permission_callback(): callable {
		return function ( $input = array() ): bool {
			return $this->check_update_permission( $input );
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
				// Overwritten values are not kept.
				'destructive' => true,
				/*
				 * Destructive idempotent abilities are served over DELETE, which would put
				 * the password in the query string.
				 */
				'idempotent'  => false,
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
