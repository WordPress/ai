<?php
/**
 * Canonical WordPress abilities: `core/content-update` ability.
 *
 * Ported from WordPress/ai PR #1025 (https://github.com/WordPress/ai/pull/1025) by @jorgefilipecosta.
 *
 * @package WordPress\Abilities
 */
declare( strict_types = 1 );

namespace WordPress\Abilities\Content;

use WP_Error;

/**
 * Registers the `core/content-update` ability, which updates a post by ID. All logic
 * is shared with the sibling write abilities in {@see Abstract_Content_Write}, which
 * this class only wires into the authoring surface.
 *
 * @package WordPress\Abilities
 */
final class Content_Update extends Abstract_Content_Write {
	/**
	 * The canonical ability name, as registered in the PR this is ported from.
	 *
	 * @return string
	 */
	protected function ability_name(): string {
		return 'core/content-update';
	}

	/**
	 * The ability label.
	 *
	 * @return string
	 */
	protected function label(): string {
		return __( 'Update Content' );
	}

	/**
	 * The ability description.
	 *
	 * @return string
	 */
	protected function description(): string {
		return __( 'Updates a post by ID. Accepts title.raw, content.raw, excerpt.raw, status, slug, date.local, date.gmt, author.slug, and parent, the field names `core/content-query` returns. Fields left out keep their current values. Fields the post type does not support are rejected. Returns the updated post; use `fields` to choose which post fields are returned. Requires an authenticated user who can edit the post.' );
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
	 * The input JSON Schema, built from the create schema as the PR builds it.
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

		// `id` becomes required and `type` an optional guard, as in the PR.
		return $this->get_content_update_input_schema( $this->get_content_create_input_schema( $post_types ) );
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
			return $this->execute_content_update( $input );
		};
	}

	/**
	 * The permission callback, as the PR registers it.
	 *
	 * @return callable(mixed): bool The permission callback.
	 */
	protected function make_permission_callback(): callable {
		return function ( $input = array() ): bool {
			return $this->check_update_permission( $input );
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
				// Overwritten values are not always kept in a revision.
				'destructive' => true,
				/*
				 * Every call touches the modified date, and destructive idempotent
				 * abilities are served over DELETE, which cannot carry post content.
				 */
				'idempotent'  => false,
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
