<?php
/**
 * Canonical WordPress abilities: `core/content-delete` ability.
 *
 * Ported from WordPress/ai PR #1025 (https://github.com/WordPress/ai/pull/1025) by @jorgefilipecosta.
 *
 * @package WordPress\Abilities
 */
declare( strict_types = 1 );

namespace WordPress\Abilities\Content;

use WP_Error;

/**
 * Registers the `core/content-delete` ability, which trashes a post by ID, or deletes
 * it permanently when `force` is true. All logic is shared with the sibling write
 * abilities in {@see Abstract_Content_Write}, which this class only wires into the
 * authoring surface.
 *
 * @package WordPress\Abilities
 */
final class Content_Delete extends Abstract_Content_Write {
	/**
	 * The canonical ability name, as registered in the PR this is ported from.
	 *
	 * @return string
	 */
	protected function ability_name(): string {
		return 'core/content-delete';
	}

	/**
	 * The ability label.
	 *
	 * @return string
	 */
	protected function label(): string {
		return __( 'Delete Content' );
	}

	/**
	 * The ability description.
	 *
	 * @return string
	 */
	protected function description(): string {
		return __( 'Moves a post to the trash by ID, or deletes it permanently when `force` is true. Trashing a post that is already in the trash is an error, as is trashing when the site has the trash disabled; set `force` to delete permanently in that case. Returns the trashed post, or the deleted post as it was before the deletion; use `fields` to choose which post fields are returned. Requires an authenticated user who can delete the post.' );
	}

	/**
	 * The ability category, the shared CATEGORY constant.
	 *
	 * @return string
	 */
	protected function category_slug(): string {
		return self::CATEGORY;
	}

	/**
	 * The input JSON Schema, as the PR builds it when registering.
	 *
	 * The PR registers nothing when no post type is exposed; the package, as for
	 * `core/content-query`, registers whenever the API is present, with an empty
	 * `type` enum when no post type is exposed.
	 *
	 * @return array<string, mixed> The input JSON Schema.
	 */
	protected function input_schema(): array {
		/*
		 * Post types must be registered with `show_in_abilities` before the ability is
		 * registered so they are included in its input schema.
		 */
		$post_types = array_values( get_post_types( array( 'show_in_abilities' => true ) ) );

		return $this->get_content_delete_input_schema( $post_types );
	}

	/**
	 * The output JSON Schema: a single post, as `core/content-query` returns.
	 *
	 * @return array<string, mixed> The output JSON Schema.
	 */
	protected function output_schema(): array {
		return $this->get_content_output_schema();
	}

	/**
	 * The execute callback, as the PR registers it.
	 *
	 * @return callable(mixed): (array<string, mixed>|WP_Error) The execute callback.
	 */
	protected function make_execute_callback(): callable {
		return function ( $input = array() ) {
			return $this->execute_content_delete( $input );
		};
	}

	/**
	 * The permission callback, as the PR registers it.
	 *
	 * @return callable(mixed): bool The permission callback.
	 */
	protected function make_permission_callback(): callable {
		return function ( $input = array() ): bool {
			return $this->check_delete_permission( $input );
		};
	}

	/**
	 * The authored ability meta, exactly as the PR registers it.
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
	 * No request-level capability floor beyond the permission callback's checks.
	 *
	 * The PR registers this ability without `required_capabilities`; the permission
	 * callback owns authorization, as the base class documents.
	 *
	 * @return list<string> The empty capability floor.
	 */
	protected function required_capabilities(): array {
		return array();
	}
}
