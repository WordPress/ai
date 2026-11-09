<?php
/**
 * Canonical WordPress abilities: `wp/term-update` ability.
 *
 * Ported from WordPress/ai PR #1103 (https://github.com/WordPress/ai/pull/1103) by @jorgefilipecosta.
 *
 * @package WordPress\Abilities
 */
declare( strict_types = 1 );

namespace WordPress\Abilities\Terms;

use WP_Error;

/**
 * Registers the `wp/term-update` ability, which updates a term by ID and answers with it
 * through the `wp/terms-query` field projection. All logic is shared with the sibling
 * term write abilities in {@see Abstract_Term_Write}, and this class only wires it into
 * the authoring surface.
 *
 * @package WordPress\Abilities
 */
final class Term_Update extends Abstract_Term_Write {

	/**
	 * The canonical ability name, under the experimental tier prefix.
	 *
	 * @return string
	 */
	protected function ability_name(): string {
		return 'wp/term-update';
	}

	/**
	 * The ability label, as the source PR registers it.
	 *
	 * @return string
	 */
	protected function label(): string {
		return __( 'Term Update' );
	}

	/**
	 * The ability description, as the source PR registers it.
	 *
	 * @return string
	 */
	protected function description(): string {
		return __( 'Updates a term by ID. Accepts a name, slug, description, and parent. Returns the updated term; use `fields` to choose which term fields are returned. Requires an authenticated user who can edit the term.' );
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
	 * The input JSON Schema, as the source PR builds it when registering.
	 *
	 * The term is identified by `id`, and `taxonomy` becomes an optional guard.
	 *
	 * @return array<string, mixed> The input JSON Schema.
	 */
	protected function input_schema(): array {
		return $this->get_term_update_input_schema( $this->get_term_create_input_schema( $this->exposed_taxonomies ) );
	}

	/**
	 * The output JSON Schema: the updated term, in the `wp/terms-query` projection.
	 *
	 * @return array<string, mixed> The output JSON Schema.
	 */
	protected function output_schema(): array {
		return $this->get_term_output_schema();
	}

	/**
	 * The execute callback, as the source PR registers it.
	 *
	 * @return callable(mixed): (array<string, mixed>|WP_Error) The execute callback.
	 */
	protected function make_execute_callback(): callable {
		return function ( $input = array() ) {
			return $this->execute_term_update( $input );
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
				// Destructive idempotent abilities are served over DELETE, which carries the
				// input in the query string, so the update stays on POST.
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
