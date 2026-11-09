<?php
/**
 * Canonical WordPress abilities: shared base for the term write abilities.
 *
 * Ported from WordPress/ai PR #1103 (https://github.com/WordPress/ai/pull/1103)
 * by @jorgefilipecosta.
 *
 * The helpers shared with this package's `wp/terms-query` port (the exposed-taxonomy
 * check, the term lookup, the `fields` projection, the term response shaping, and the
 * term item schema) are extracted verbatim from Terms_Query.php with `private` relaxed
 * to `protected` so the write ability subclasses reuse them — Terms_Query itself keeps
 * its byte-stable backport shape (this is the same sharing pattern the media write
 * trio applies to Media_Query). The write logic itself (create via `wp_insert_term`,
 * update via `wp_update_term`, delete with the fail-closed `force` gate and the delete
 * capability re-check) is a faithful port of the PR: upstream names the abilities
 * `core/term-*`; this package ships them under the experimental tier's `wp/` prefix
 * (see NAME-LIFECYCLE.md), with the `default` text domain and registration owned by
 * the package's Registrar.
 *
 * The default category is not protected by any code of its own here, exactly as in the
 * PR: core's `map_meta_cap()` answers `do_not_allow` for `delete_term` against the
 * term named by the `default_{$taxonomy}` option, so the permission callback denies it
 * like a missing capability, and `wp_delete_term()` returns 0 for the same term, so the
 * execute callback's `! $retval` guard answers `terms_cannot_delete` (500) when a
 * filter grants the capability anyway.
 *
 * @package WordPress\Abilities
 */
declare( strict_types = 1 );

namespace WordPress\Abilities\Terms;

use stdClass;
use WP_Error;
use WP_Term;

use WordPress\Abilities\Abstract_Core_Ability;

/**
 * Shared base for the `wp/term-create`, `wp/term-update`, and `wp/term-delete` abilities.
 *
 * A written term is answered through the same field projection as `wp/terms-query`,
 * limited by the `fields` input, with the ID always included; a delete returns the
 * deleted term under `previous`. Terms write only to the taxonomies exposed to
 * abilities via `show_in_abilities` — the same opt-in machinery the query rides.
 *
 * @package WordPress\Abilities
 */
abstract class Abstract_Term_Write extends Abstract_Core_Ability {

	/**
	 * The ability category used for term abilities.
	 *
	 * @var string
	 */
	protected const CATEGORY = 'content';

	/**
	 * Default fields returned when the caller does not request a field subset.
	 *
	 * @var list<string>
	 */
	protected array $default_fields = array(
		'id',
		'count',
		'name',
		'slug',
		'taxonomy',
		'parent',
	);

	/**
	 * The taxonomies exposed to abilities, in registration order, from registration time.
	 *
	 * @var list<string>
	 */
	protected array $exposed_taxonomies = array();

	/**
	 * Takes the exposed-taxonomies snapshot, as the source PR does before registering.
	 */
	protected function prepare(): void {
		/*
		 * Taxonomies must be registered with `show_in_abilities` before the abilities are
		 * registered so they are included in their input schemas.
		 */
		$this->exposed_taxonomies = array_values( array_filter( get_taxonomies(), array( $this, 'check_is_taxonomy_allowed' ) ) );
	}

	/**
	 * Whether any taxonomy is exposed, as the source PR checks before registering.
	 *
	 * An input schema with an empty taxonomy enum would be invalid, so the abilities
	 * are not registered when nothing is exposed.
	 *
	 * @return bool True when at least one taxonomy is exposed.
	 */
	public function should_register(): bool {
		return array() !== $this->exposed_taxonomies;
	}

	/**
	 * No request-level capability floor beyond the permission callbacks' checks.
	 *
	 * The source PR registers these abilities without `required_capabilities`; the
	 * permission callbacks own authorization, as the base class documents.
	 *
	 * @return list<string> The empty capability floor.
	 */
	protected function required_capabilities(): array {
		return array();
	}

	/**
	 * Checks permission for the `wp/term-create` ability.
	 *
	 * The taxonomy must be exposed to abilities, and the current user must be able to edit
	 * its terms when it is hierarchical, or to assign them when it is not. The rest of the
	 * input is checked during execution.
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return bool True if the request may proceed, false otherwise.
	 */
	public function check_create_permission( $input = array() ): bool {
		$input    = rest_sanitize_object( $input );
		$taxonomy = isset( $input['taxonomy'] ) && is_string( $input['taxonomy'] ) ? $input['taxonomy'] : '';

		if ( ! is_user_logged_in() ) {
			return false;
		}

		if ( ! $this->check_is_taxonomy_allowed( $taxonomy ) ) {
			return false;
		}

		/** @var \WP_Taxonomy $taxonomy_obj The taxonomy check has resolved the taxonomy. */
		$taxonomy_obj = get_taxonomy( $taxonomy );

		return ! ( ( is_taxonomy_hierarchical( $taxonomy )
				&& ! current_user_can( $taxonomy_obj->cap->edit_terms ) ) // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is resolved from the taxonomy's capability object.
			|| ( ! is_taxonomy_hierarchical( $taxonomy )
				&& ! current_user_can( $taxonomy_obj->cap->assign_terms ) ) ); // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is resolved from the taxonomy's capability object.
	}

	/**
	 * Executes the `wp/term-create` ability.
	 *
	 * {@see WP_Ability::execute()} always runs {@see self::check_create_permission()} first,
	 * so this only re-validates that the taxonomy is exposed before writing the term.
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed>|\WP_Error The created term, or a WP_Error.
	 */
	public function execute_term_create( $input = array() ) {
		$input    = rest_sanitize_object( $input );
		$taxonomy = isset( $input['taxonomy'] ) && is_string( $input['taxonomy'] ) ? $input['taxonomy'] : '';

		if ( ! $this->check_is_taxonomy_allowed( $taxonomy ) ) {
			return new WP_Error(
				'terms_forbidden',
				__( 'Sorry, you are not allowed to do that.' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		$parent_error = $this->check_parent( $input, $taxonomy );
		if ( $parent_error instanceof WP_Error ) {
			return $parent_error;
		}

		$prepared_term = $this->prepare_item_for_database( $input, $taxonomy );

		$term = wp_insert_term( wp_slash( $prepared_term->name ?? '' ), $taxonomy, wp_slash( (array) $prepared_term ) );
		if ( is_wp_error( $term ) ) {
			/*
			 * If we're going to inform the client that the term already exists,
			 * give them the identifier for future use.
			 */
			$term_id = $term->get_error_data( 'term_exists' );
			if ( $term_id ) {
				/** @var \WP_Term $existing_term The error names a term of the taxonomy. */
				$existing_term = get_term( $term_id, $taxonomy );
				$term->add_data( $existing_term->term_id, 'term_exists' );
				$term->add_data(
					array(
						'status'  => 400,
						'term_id' => $term_id,
					)
				);
			}

			return $term;
		}

		$term = $this->get_term( $term['term_id'], $taxonomy );
		if ( is_wp_error( $term ) ) {
			return $term;
		}

		return $this->prepare_item_for_response( $term, $this->get_fields_for_response( $taxonomy, $input ) );
	}

	/**
	 * Checks permission for the `wp/term-update` ability.
	 *
	 * The term must exist in a taxonomy exposed to abilities (and in the `taxonomy` guard
	 * when given), and the current user must be able to edit it. The rest of the input is
	 * checked during execution.
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return bool True if the request may proceed, false otherwise.
	 */
	public function check_update_permission( $input = array() ): bool {
		$input = rest_sanitize_object( $input );

		if ( ! is_user_logged_in() ) {
			return false;
		}

		$term = $this->get_term_for_write( $input );
		if ( $term instanceof WP_Error ) {
			return false;
		}

		return current_user_can( 'edit_term', $term->term_id );
	}

	/**
	 * Executes the `wp/term-update` ability.
	 *
	 * {@see WP_Ability::execute()} always runs {@see self::check_update_permission()} first,
	 * so this only re-validates the lookup itself before writing the term.
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed>|\WP_Error The updated term, or a WP_Error.
	 */
	public function execute_term_update( $input = array() ) {
		$input = rest_sanitize_object( $input );

		$term = $this->get_term_for_write( $input );
		if ( $term instanceof WP_Error ) {
			return $term;
		}

		$parent_error = $this->check_parent( $input, $term->taxonomy );
		if ( $parent_error instanceof WP_Error ) {
			return $parent_error;
		}

		$prepared_term = $this->prepare_item_for_database( $input, $term->taxonomy );

		// Only update the term if we have something to update.
		if ( ! empty( $prepared_term ) ) { // @phpstan-ignore empty.variable (The prepared term is a stdClass instance, so this never skips the update; the ignore records that the check reads as always-true on the object.)
			$update = wp_update_term( $term->term_id, $term->taxonomy, wp_slash( (array) $prepared_term ) );

			if ( is_wp_error( $update ) ) {
				return $update;
			}
		}

		$updated_term = $this->get_term( $term->term_id, $term->taxonomy );
		if ( is_wp_error( $updated_term ) ) {
			return $updated_term;
		}

		return $this->prepare_item_for_response( $updated_term, $this->get_fields_for_response( $term->taxonomy, $input ) );
	}

	/**
	 * Checks permission for the `wp/term-delete` ability.
	 *
	 * The term must exist in a taxonomy exposed to abilities (and in the `taxonomy` guard
	 * when given), and the current user must be able to delete it.
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return bool True if the request may proceed, false otherwise.
	 */
	public function check_delete_permission( $input = array() ): bool {
		$input = rest_sanitize_object( $input );

		if ( ! is_user_logged_in() ) {
			return false;
		}

		$term = $this->get_term_for_write( $input );
		if ( $term instanceof WP_Error ) {
			return false;
		}

		return current_user_can( 'delete_term', $term->term_id );
	}

	/**
	 * Executes the `wp/term-delete` ability.
	 *
	 * {@see WP_Ability::execute()} always runs {@see self::check_delete_permission()} first;
	 * this re-validates the lookup and, because the operation is destructive, checks the
	 * delete capability once more right before anything is removed. Terms cannot be trashed,
	 * so the term is only deleted when `force` is true, and is then returned under `previous`.
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed>|\WP_Error A `deleted`/`previous` pair, or a WP_Error.
	 */
	public function execute_term_delete( $input = array() ) {
		$input = rest_sanitize_object( $input );

		$term = $this->get_term_for_write( $input );
		if ( $term instanceof WP_Error ) {
			return $term;
		}

		if ( ! current_user_can( 'delete_term', $term->term_id ) ) {
			return new WP_Error(
				'terms_cannot_delete',
				__( 'Sorry, you are not allowed to delete this term.' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		$force = isset( $input['force'] ) && rest_is_boolean( $input['force'] ) && rest_sanitize_boolean( (string) $input['force'] );

		// We don't support trashing for terms.
		if ( ! $force ) {
			return new WP_Error(
				'terms_trash_not_supported',
				__( 'Terms do not support trashing. Set `force` to true to delete.' ),
				array( 'status' => 501 )
			);
		}

		$previous = $this->prepare_item_for_response( $term, $this->get_fields_for_response( $term->taxonomy, $input ) );

		$retval = wp_delete_term( $term->term_id, $term->taxonomy );

		if ( ! $retval ) {
			return new WP_Error(
				'terms_cannot_delete',
				__( 'The term cannot be deleted.' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'deleted'  => true,
			'previous' => $previous,
		);
	}

	/**
	 * Resolves the term a write names by `id`, in the `taxonomy` guard when one is given.
	 *
	 * The term is never looked up by slug, unlike in {@see self::get_term_for_input()}: a
	 * write's `slug` is a value to store. An ID that is not a whole number, or a guard that
	 * is not a taxonomy name, matches no term, so a malformed value cannot be cast onto
	 * another term.
	 *
	 * @param array<mixed> $input The ability input.
	 * @return WP_Term|\WP_Error The term, or a WP_Error when it cannot be written.
	 */
	protected function get_term_for_write( array $input ) {
		$id = $input['id'] ?? null;

		if ( ! ( is_int( $id ) || ( is_string( $id ) && ctype_digit( $id ) ) )
			|| ( isset( $input['taxonomy'] ) && ! is_string( $input['taxonomy'] ) )
		) {
			// The same error as for a missing term.
			return $this->get_term( 0, '' );
		}

		$with_pinned_id = array( 'id' => $id ) + $input;

		return $this->get_term_for_input( $with_pinned_id );
	}

	/**
	 * Checks that the parent given for a term can be set.
	 *
	 * Shared by the create and update abilities. A parent can only be set in a hierarchical
	 * taxonomy, and must be a term of the same taxonomy, or 0 for a top-level term.
	 *
	 * @param array<mixed> $input    The ability input.
	 * @param string       $taxonomy Taxonomy key.
	 * @return WP_Error|null A WP_Error when the parent cannot be set, or null.
	 */
	protected function check_parent( array $input, string $taxonomy ): ?WP_Error {
		if ( isset( $input['parent'] ) ) {
			if ( ! is_taxonomy_hierarchical( $taxonomy ) ) {
				return new WP_Error(
					'terms_taxonomy_not_hierarchical',
					__( 'Cannot set parent term, taxonomy is not hierarchical.' ),
					array( 'status' => 400 )
				);
			}

			$parent = get_term( (int) $input['parent'], $taxonomy );

			if ( ! $parent ) {
				return new WP_Error(
					'terms_term_invalid',
					__( 'Parent term does not exist.' ),
					array( 'status' => 400 )
				);
			}
		}

		return null;
	}

	/**
	 * Prepares a single term for create or update.
	 *
	 * The name is sanitized as plain text, and the slug like a title.
	 *
	 * @param array<mixed> $input    The ability input.
	 * @param string       $taxonomy Taxonomy key.
	 * @return stdClass Term object.
	 */
	protected function prepare_item_for_database( array $input, string $taxonomy ): stdClass {
		$prepared_term = new stdClass();

		if ( isset( $input['name'] ) ) {
			$prepared_term->name = sanitize_text_field( $input['name'] );
		}

		if ( isset( $input['slug'] ) ) {
			$prepared_term->slug = sanitize_title( $input['slug'] );
		}

		if ( isset( $input['taxonomy'] ) ) {
			$prepared_term->taxonomy = $input['taxonomy'];
		}

		if ( isset( $input['description'] ) ) {
			$prepared_term->description = $input['description'];
		}

		if ( isset( $input['parent'] ) && is_taxonomy_hierarchical( $taxonomy ) ) {
			$parent_term_id   = 0;
			$requested_parent = (int) $input['parent'];

			if ( $requested_parent ) {
				$parent_term = get_term( $requested_parent, $taxonomy );

				if ( $parent_term instanceof WP_Term ) {
					$parent_term_id = $parent_term->term_id;
				}
			}

			$prepared_term->parent = $parent_term_id;
		}

		return $prepared_term;
	}

	/**
	 * Builds the input schema for the `wp/term-create` ability.
	 *
	 * `additionalProperties: false` rejects unknown fields instead of dropping them, so e.g.
	 * passing an `id` fails validation instead of silently creating a new term.
	 *
	 * @param list<string> $taxonomies Exposed taxonomy names.
	 * @return array<string, mixed> The input JSON Schema.
	 */
	protected function get_term_create_input_schema( array $taxonomies ): array {
		$properties = $this->get_item_schema()['properties'];

		return array(
			'type'                 => 'object',
			'required'             => array( 'taxonomy', 'name' ),
			'additionalProperties' => false,
			'properties'           => array(
				'taxonomy'    => array(
					'description' => __( 'Taxonomy of the term to create.' ),
					'type'        => 'string',
					'enum'        => $taxonomies,
				),
				'name'        => $properties['name'],
				'slug'        => $properties['slug'],
				'description' => $properties['description'],
				'parent'      => array(
					'description' => __( 'The parent term ID, or 0 for a top-level term. Only supported for hierarchical taxonomies.' ),
					'type'        => 'integer',
				),
				'fields'      => $this->get_fields_input_schema(),
			),
		);
	}

	/**
	 * Builds the input schema for the `wp/term-update` ability from the create schema.
	 *
	 * The term is identified by `id`, and `taxonomy` becomes an optional guard.
	 *
	 * @param array<string, mixed> $create_schema The input schema of the `wp/term-create` ability.
	 * @return array<string, mixed> The input JSON Schema.
	 */
	protected function get_term_update_input_schema( array $create_schema ): array {
		$properties = array(
			'id' => array(
				'description' => __( 'Unique identifier for the term.' ),
				'type'        => 'integer',
				'minimum'     => 1,
			),
		) + $create_schema['properties'];

		$properties['taxonomy']['description'] = __( 'Optional. Update the term only if it belongs to this taxonomy.' );

		$create_schema['required']   = array( 'id' );
		$create_schema['properties'] = $properties;

		return $create_schema;
	}

	/**
	 * Builds the input schema for the `wp/term-delete` ability.
	 *
	 * @param list<string> $taxonomies Exposed taxonomy names.
	 * @return array<string, mixed> The input JSON Schema.
	 */
	protected function get_term_delete_input_schema( array $taxonomies ): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'id' ),
			'additionalProperties' => false,
			'properties'           => array(
				'id'       => array(
					'description' => __( 'Unique identifier for the term.' ),
					'type'        => 'integer',
					'minimum'     => 1,
				),
				'taxonomy' => array(
					'description' => __( 'Optional. Delete the term only if it belongs to this taxonomy.' ),
					'type'        => 'string',
					'enum'        => $taxonomies,
				),
				'force'    => array(
					'description' => __( 'Required to be true, as terms do not support trashing.' ),
					'type'        => 'boolean',
				),
				'fields'   => $this->get_fields_input_schema(),
			),
		);
	}

	/**
	 * Builds the schema of the `fields` input shared by the write abilities.
	 *
	 * @return array<string, mixed> The `fields` JSON Schema.
	 */
	protected function get_fields_input_schema(): array {
		return array(
			'description' => __( 'Limit the returned term to these fields. If omitted, a lean set of common fields is returned. The ID is always included.' ),
			'type'        => 'array',
			'uniqueItems' => true,
			'items'       => array(
				'type' => 'string',
				'enum' => array_keys( $this->get_item_schema()['properties'] ),
			),
		);
	}

	/**
	 * Builds the output schema of a single term, shared by the write abilities.
	 *
	 * No field is marked required because the `fields` input lets the caller request any
	 * subset, and `parent` is only present for hierarchical taxonomies.
	 *
	 * @return array<string, mixed> The term JSON Schema.
	 */
	protected function get_term_output_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => $this->get_item_schema()['properties'],
		);
	}

	/**
	 * Builds the output schema for the `wp/term-delete` ability.
	 *
	 * @return array<string, mixed> The output JSON Schema.
	 */
	protected function get_term_delete_output_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'deleted', 'previous' ),
			'properties'           => array(
				'deleted'  => array(
					'description' => __( 'Whether the term was deleted.' ),
					'type'        => 'boolean',
				),
				'previous' => $this->get_term_output_schema(),
			),
		);
	}

	// -- The helpers below are extracted verbatim from this package's Terms_Query
	// -- port of WordPress/ai PR #1099, with `private` relaxed to `protected` so
	// -- the write abilities reuse them. They are the field projection that lets
	// -- a written term be read back unchanged through `wp/terms-query`'s
	// -- contract. Only `get_term_for_write()` above adds write-specific logic on
	// -- top of them.

	/**
	 * Parses a raw input value to an integer, or 0 when not scalar.
	 *
	 * @param mixed $value The raw input value.
	 * @return int The value as an integer, or 0 when not scalar.
	 */
	protected function input_int( $value ): int {
		return is_scalar( $value ) ? (int) $value : 0;
	}

	/**
	 * Resolves the term a single-term request names.
	 *
	 * A slug is looked up in the requested taxonomy. An ID is looked up in the requested
	 * taxonomy when one is given, and otherwise in the taxonomy the term belongs to.
	 *
	 * @param array<mixed> $input The ability input.
	 * @return WP_Term|WP_Error The term, or a WP_Error when it cannot be read.
	 */
	protected function get_term_for_input( array $input ) {
		$taxonomy = isset( $input['taxonomy'] ) && is_string( $input['taxonomy'] ) ? $input['taxonomy'] : '';

		if ( isset( $input['id'] ) ) {
			$id = $this->input_int( $input['id'] );

			if ( '' === $taxonomy ) {
				$term     = get_term( $id );
				$taxonomy = $term instanceof WP_Term ? $term->taxonomy : '';
			}
		} else {
			$term = is_string( $input['slug'] ) ? get_term_by( 'slug', $input['slug'], $taxonomy ) : false;
			$id   = $term instanceof WP_Term ? $term->term_id : 0;
		}

		return $this->get_term( $id, $taxonomy );
	}

	/**
	 * Returns the fields to include for terms of a taxonomy.
	 *
	 * These are the requested fields, or a lean default set when none are given, and always
	 * `id`. `parent` is only included for hierarchical taxonomies.
	 *
	 * @param string       $taxonomy The taxonomy of the terms.
	 * @param array<mixed> $input    The ability input.
	 * @return array<scalar> The field names.
	 */
	protected function get_fields_for_response( string $taxonomy, array $input ): array {
		$fields = isset( $input['fields'] ) ? wp_parse_list( $input['fields'] ) : array();
		if ( array() === $fields ) {
			$fields = $this->default_fields;
		}

		$fields[] = 'id';

		if ( ! is_taxonomy_hierarchical( $taxonomy ) ) {
			$fields = array_diff( $fields, array( 'parent' ) );
		}

		return $fields;
	}

	/**
	 * Get the term, if the ID is valid.
	 *
	 * @param mixed  $id       Supplied ID.
	 * @param string $taxonomy Taxonomy key.
	 * @return WP_Term|\WP_Error Term object if ID is valid, WP_Error otherwise.
	 */
	protected function get_term( $id, string $taxonomy ) {
		$error = new WP_Error(
			'terms_term_invalid',
			__( 'Term does not exist.' ),
			array( 'status' => 404 )
		);

		if ( ! $this->check_is_taxonomy_allowed( $taxonomy ) ) {
			return $error;
		}

		if ( (int) $id <= 0 ) {
			return $error;
		}

		$term = get_term( (int) $id, $taxonomy );
		if ( ! $term instanceof WP_Term || $term->taxonomy !== $taxonomy ) {
			return $error;
		}

		return $term;
	}

	/**
	 * Prepares a single term output for response.
	 *
	 * @param WP_Term       $item   Term object.
	 * @param array<scalar> $fields The fields to include.
	 * @return array<string, mixed> The term data.
	 */
	protected function prepare_item_for_response( WP_Term $item, array $fields ): array {
		$data = array();

		if ( in_array( 'id', $fields, true ) ) {
			$data['id'] = (int) $item->term_id;
		}

		if ( in_array( 'count', $fields, true ) ) {
			$data['count'] = (int) $item->count;
		}

		if ( in_array( 'description', $fields, true ) ) {
			$data['description'] = $item->description;
		}

		if ( in_array( 'link', $fields, true ) ) {
			$data['link'] = get_term_link( $item );
		}

		if ( in_array( 'name', $fields, true ) ) {
			$data['name'] = $item->name;
		}

		if ( in_array( 'slug', $fields, true ) ) {
			$data['slug'] = $item->slug;
		}

		if ( in_array( 'taxonomy', $fields, true ) ) {
			$data['taxonomy'] = $item->taxonomy;
		}

		if ( in_array( 'parent', $fields, true ) ) {
			$data['parent'] = (int) $item->parent;
		}

		return $data;
	}

	/**
	 * Retrieves the term's schema, conforming to JSON Schema.
	 *
	 * @return array<string, mixed> Item schema data.
	 */
	protected function get_item_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'          => array(
					'description' => __( 'Unique identifier for the term.' ),
					'type'        => 'integer',
				),
				'count'       => array(
					'description' => __( 'Number of published posts for the term.' ),
					'type'        => 'integer',
				),
				'description' => array(
					'description' => __( 'HTML description of the term.' ),
					'type'        => 'string',
				),
				'link'        => array(
					'description' => __( 'URL of the term.' ),
					'type'        => 'string',
				),
				'name'        => array(
					'description' => __( 'HTML title for the term.' ),
					'type'        => 'string',
				),
				'slug'        => array(
					'description' => __( 'An alphanumeric identifier for the term unique to its type.' ),
					'type'        => 'string',
				),
				'taxonomy'    => array(
					'description' => __( 'Type attribution for the term.' ),
					'type'        => 'string',
				),
				'parent'      => array(
					'description' => __( 'The parent term ID. Present for hierarchical taxonomies.' ),
					'type'        => 'integer',
				),
			),
		);
	}

	/**
	 * Checks that the taxonomy is valid.
	 *
	 * @param string $taxonomy Taxonomy to check.
	 * @return bool Whether the taxonomy is exposed to abilities.
	 */
	protected function check_is_taxonomy_allowed( string $taxonomy ): bool {
		$taxonomy_obj = get_taxonomy( $taxonomy );

		return $taxonomy_obj && ! empty( $taxonomy_obj->show_in_abilities );
	}
}
