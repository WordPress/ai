<?php
/**
 * Canonical WordPress abilities: `wp/media-delete` ability.
 *
 * Ported from WordPress/ai PR #1104 (https://github.com/WordPress/ai/pull/1104) by @jorgefilipecosta.
 *
 * @package WordPress\Abilities
 */
declare( strict_types = 1 );

namespace WordPress\Abilities\Media;

use WP_Error;

/**
 * Registers the `wp/media-delete` ability, which deletes a media item by ID.
 * All logic is shared with the sibling media write abilities in
 * {@see Abstract_Media_Write} (which also reuses the `wp/media-query` field
 * projection), and this class only wires it into the authoring surface.
 *
 * @package WordPress\Abilities
 */
final class Media_Delete extends Abstract_Media_Write {
	/**
	 * The canonical ability name, under the experimental tier prefix.
	 *
	 * @return string
	 */
	protected function ability_name(): string {
		return 'wp/media-delete';
	}

	/**
	 * The ability label, as the source PR registers it.
	 *
	 * @return string
	 */
	protected function label(): string {
		return __( 'Media Delete' );
	}

	/**
	 * The ability description, as the source PR registers it.
	 *
	 * @return string
	 */
	protected function description(): string {
		return __( 'Deletes a media item by ID. With `force` set to true, the item and its files are deleted permanently. Without it, the item is moved to the trash, which is an error unless the site enables the media trash, as is trashing an item that is already in the trash. Returns the trashed item, or the deleted item under `previous` when `force` is true; use `fields` to choose which fields are returned. Requires an authenticated user who can delete the item.' );
	}

	/**
	 * The ability category, the package-authored media category.
	 *
	 * @return string
	 */
	protected function category_slug(): string {
		return self::CATEGORY;
	}

	/**
	 * The input JSON Schema, as the source PR registers it.
	 *
	 * @return array<string, mixed> The input JSON Schema.
	 */
	protected function input_schema(): array {
		return $this->get_delete_input_schema();
	}

	/**
	 * The output JSON Schema: the trashed attachment directly, or a forced
	 * deletion under a `deleted`/`previous` pair — a `oneOf`.
	 *
	 * @return array<string, mixed> The output JSON Schema.
	 */
	protected function output_schema(): array {
		return $this->get_delete_output_schema();
	}

	/**
	 * The execute callback, as the source PR registers it.
	 *
	 * @return callable(mixed): (array<string, mixed>|WP_Error) The execute callback.
	 */
	protected function make_execute_callback(): callable {
		return function ( $input = array() ) {
			return $this->execute_media_delete( $input );
		};
	}

	/**
	 * The permission callback, as the source PR registers it.
	 *
	 * @return callable(mixed): bool The permission callback.
	 */
	protected function make_permission_callback(): callable {
		return function ( $input = array() ): bool {
			return $this->delete_item_permissions_check( $input );
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
				// Repeating a deletion has no further effect; the Abilities API serves
				// destructive idempotent abilities over the DELETE method.
				'idempotent'  => true,
				'open_world'  => false,
			),
			'show_in_rest' => true,
			'mcp'          => array(
				'public' => true,
			),
		);
	}
}
