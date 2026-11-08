<?php
/**
 * Canonical WordPress abilities: `wp/media-upload` ability.
 *
 * Ported from WordPress/ai PR #1104 (https://github.com/WordPress/ai/pull/1104) by @jorgefilipecosta.
 *
 * @package WordPress\Abilities
 */
declare( strict_types = 1 );

namespace WordPress\Abilities\Media;

use WP_Error;

/**
 * Registers the `wp/media-upload` ability, which uploads a file to the media
 * library, from base64 data and a file name, or by downloading an image from a
 * URL. All logic is shared with the sibling media write abilities in
 * {@see Abstract_Media_Write} (which also reuses the `wp/media-query` field
 * projection), and this class only wires it into the authoring surface.
 *
 * @package WordPress\Abilities
 */
final class Media_Upload extends Abstract_Media_Write {
	/**
	 * The canonical ability name, under the experimental tier prefix.
	 *
	 * @return string
	 */
	protected function ability_name(): string {
		return 'wp/media-upload';
	}

	/**
	 * The ability label, as the source PR registers it.
	 *
	 * @return string
	 */
	protected function label(): string {
		return __( 'Media Upload' );
	}

	/**
	 * The ability description, as the source PR registers it.
	 *
	 * @return string
	 */
	protected function description(): string {
		return __( 'Uploads a file to the media library, from base64 data and a file name, or by downloading an image from a URL. With data, accepts a title, caption, description, alt text, parent post, author, status, slug, and date; a URL upload only accepts the parent post. Returns the new item; use `fields` to choose which fields are returned. Requires an authenticated user who can upload files.' );
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
	 * The file comes either as base64 data with a file name, or as a URL to
	 * download an image from — a `oneOf`. A URL upload takes no attachment
	 * fields other than the parent post, because the downloaded image provides
	 * them.
	 *
	 * @return array<string, mixed> The input JSON Schema.
	 */
	protected function input_schema(): array {
		return $this->get_upload_input_schema();
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
			return $this->execute_media_upload( $input );
		};
	}

	/**
	 * The permission callback, as the source PR registers it.
	 *
	 * @return callable(mixed): bool The permission callback.
	 */
	protected function make_permission_callback(): callable {
		return function ( $input = array() ): bool {
			return $this->create_item_permissions_check();
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
				'destructive' => false,
				// Every call creates a new attachment.
				'idempotent'  => false,
				// A URL upload downloads the file from another site.
				'open_world'  => true,
			),
			'show_in_rest' => true,
			'mcp'          => array(
				'public' => true,
			),
		);
	}
}
