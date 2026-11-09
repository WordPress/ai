<?php
/**
 * Canonical WordPress abilities: `wp/media-update` ability.
 *
 * Ported from WordPress/ai PR #1104 (https://github.com/WordPress/ai/pull/1104) by @jorgefilipecosta.
 *
 * @package WordPress\Abilities
 */
declare( strict_types = 1 );

namespace WordPress\Abilities\Media;

use WP_Error;

/**
 * Registers the `wp/media-update` ability, which updates a media item by ID
 * and answers with it through the `wp/media-query` field projection, in the
 * edit context. All logic is shared with the sibling media write abilities in
 * {@see Abstract_Media_Write}, and this class only wires it into the authoring
 * surface.
 *
 * @package WordPress\Abilities
 */
final class Media_Update extends Abstract_Media_Write {
	/**
	 * The canonical ability name, under the experimental tier prefix.
	 *
	 * @return string
	 */
	protected function ability_name(): string {
		return 'wp/media-update';
	}

	/**
	 * The ability label, as the source PR registers it.
	 *
	 * @return string
	 */
	protected function label(): string {
		return __( 'Media Update' );
	}

	/**
	 * The ability description, as the source PR registers it.
	 *
	 * @return string
	 */
	protected function description(): string {
		return __( 'Updates a media item by ID. Accepts a title, caption, description, alt text, parent post, author, status, slug, and date. Returns the updated item; use `fields` to choose which fields are returned. Requires an authenticated user who can edit the item.' );
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
	 * The input JSON Schema, as the source PR builds it when registering.
	 *
	 * The status is not restricted by an enum: an attachment may keep its
	 * current status, such as `inherit`, so the status is validated against the
	 * attachment during execution.
	 *
	 * @return array<string, mixed> The input JSON Schema.
	 */
	protected function input_schema(): array {
		return $this->get_update_input_schema();
	}

	/**
	 * The output JSON Schema: the written attachment, in the edit context.
	 *
	 * @return array<string, mixed> The output JSON Schema.
	 */
	protected function output_schema(): array {
		return $this->get_write_output_schema();
	}

	/**
	 * The execute callback, as the source PR registers it.
	 *
	 * @return callable(mixed): (array<string, mixed>|WP_Error) The execute callback.
	 */
	protected function make_execute_callback(): callable {
		return function ( $input = array() ) {
			return $this->execute_media_update( $input );
		};
	}

	/**
	 * The permission callback, as the source PR registers it.
	 *
	 * @return callable(mixed): bool The permission callback.
	 */
	protected function make_permission_callback(): callable {
		return function ( $input = array() ): bool {
			return $this->update_item_permissions_check( $input );
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
				// Attachments keep no revisions of the values an update overwrites.
				'destructive' => true,
				// Every call touches the modified date, and destructive idempotent
				// abilities are served over DELETE, which carries the input in the URL.
				'idempotent'  => false,
				'open_world'  => false,
			),
			'show_in_rest' => true,
			'mcp'          => array(
				'public' => true,
			),
		);
	}
}
