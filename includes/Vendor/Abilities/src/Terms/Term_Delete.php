<?php
/**
 * Canonical WordPress abilities: `wp/term-delete` ability.
 *
 * Ported from WordPress/ai PR #1103 (https://github.com/WordPress/ai/pull/1103) by @jorgefilipecosta.
 *
 * @package WordPress\Abilities
 */
declare( strict_types = 1 );

namespace WordPress\Abilities\Terms;

use WP_Error;

/**
 * Registers the `wp/term-delete` ability, which deletes a term by ID. Terms cannot be
 * trashed, so a delete only runs when `force` is true, and the deleted term returns
 * under `previous` through the `wp/terms-query` field projection. All logic is shared
 * with the sibling term write abilities in {@see Abstract_Term_Write}, and this class
 * only wires it into the authoring surface.
 *
 * @package WordPress\Abilities
 */
final class Term_Delete extends Abstract_Term_Write {

	/**
	 * The canonical ability name, under the experimental tier prefix.
	 *
	 * @return string
	 */
	protected function ability_name(): string {
		return 'wp/term-delete';
	}

	/**
	 * The ability label, as the source PR registers it.
	 *
	 * @return string
	 */
	protected function label(): string {
		return __( 'Term Delete' );
	}

	/**
	 * The ability description, as the source PR registers it.
	 *
	 * @return string
	 */
	protected function description(): string {
		return __( 'Deletes a term by ID. Terms cannot be trashed, so `force` must be true. Returns the deleted term under `previous`; use `fields` to choose which term fields are returned. Requires an authenticated user who can delete the term.' );
	}

	/**
	 * The ability category, the core-registered content category.
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
		return $this->get_term_delete_input_schema( $this->exposed_taxonomies );
	}

	/**
	 * The output JSON Schema: the deleted term under `previous`, in a `deleted`/`previous` pair.
	 *
	 * @return array<string, mixed> The output JSON Schema.
	 */
	protected function output_schema(): array {
		return $this->get_term_delete_output_schema();
	}

	/**
	 * The execute callback, as the source PR registers it.
	 *
	 * @return callable(mixed): (array<string, mixed>|WP_Error) The execute callback.
	 */
	protected function make_execute_callback(): callable {
		return function ( $input = array() ) {
			return $this->execute_term_delete( $input );
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
