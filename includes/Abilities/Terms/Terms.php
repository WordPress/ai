<?php
/**
 * The `core/terms-query`, `core/term-create`, `core/term-update`, and `core/term-delete`
 * WordPress Abilities.
 *
 * @package WordPress\AI
 *
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Terms;

use WP_Error;
use WP_Post;
use WP_Term;
use stdClass;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Terms
 *
 * Registers the read-only `core/terms-query` ability, which retrieves terms of a taxonomy
 * exposed to abilities via `show_in_abilities`. Supports fetching a single term by ID or by
 * taxonomy and slug, or querying the terms of one taxonomy filtered by search, parent, post,
 * emptiness, or included and excluded IDs.
 *
 * Also registers `core/term-create`, `core/term-update`, and `core/term-delete`, which write
 * terms of the same taxonomies and return them through the same field projection.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since x.x.x
 */
final class Terms {

	/**
	 * The ability category used for term abilities.
	 *
	 * @since x.x.x
	 * @var string
	 */
	private const CATEGORY = 'content';

	/**
	 * Default number of terms returned per page in collection mode.
	 *
	 * @since x.x.x
	 * @var int
	 */
	private const DEFAULT_PER_PAGE = 10;

	/**
	 * Maximum number of terms returned per page in collection mode.
	 *
	 * @since x.x.x
	 * @var int
	 */
	private const MAX_PER_PAGE = 100;

	/**
	 * Default fields returned when the caller does not request a field subset.
	 *
	 * @since x.x.x
	 * @var list<string>
	 */
	private array $default_fields = array(
		'id',
		'count',
		'name',
		'slug',
		'taxonomy',
		'parent',
	);

	/**
	 * Hooks the ability into the Abilities API.
	 *
	 * Registers the ability slightly later than core (priority 11) so it can override any
	 * core-provided copy, and registers the category as a fallback in case core has not.
	 *
	 * @since x.x.x
	 */
	public function init(): void {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ), 11 );
		add_action( 'wp_abilities_api_init', array( $this, 'register' ), 11 );
	}

	/**
	 * Registers the `content` ability category if it is not already registered.
	 *
	 * @since x.x.x
	 */
	public function register_category(): void {
		if ( wp_has_ability_category( self::CATEGORY ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Content', 'ai' ),
				'description' => __( 'Abilities that retrieve or manage posts and other content.', 'ai' ),
			)
		);
	}

	/**
	 * Registers all term abilities.
	 *
	 * Must run on the `wp_abilities_api_init` hook.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		$this->register_terms_query();
		$this->register_term_write_abilities();
	}

	/**
	 * Registers the read-only `core/terms-query` ability.
	 *
	 * @since x.x.x
	 */
	private function register_terms_query(): void {
		/*
		 * Taxonomies must be registered with `show_in_abilities` before the ability is
		 * registered so they are included in its input schema.
		 */
		$taxonomies = array_values( get_taxonomies( array( 'show_in_abilities' => true ) ) );
		if ( empty( $taxonomies ) ) {
			return;
		}

		// Unregister any core-provided copy first so this version wins.
		if ( wp_has_ability( 'core/terms-query' ) ) {
			wp_unregister_ability( 'core/terms-query' );
		}

		wp_register_ability(
			'core/terms-query',
			array(
				'label'               => __( 'Terms Query', 'ai' ),
				'description'         => __( 'Reads terms, such as categories and tags, from taxonomies exposed to abilities. Single-term lookups by ID or by taxonomy and slug return the term object directly. Query mode returns the terms of one taxonomy, filtered by search, parent, post, emptiness, or included and excluded IDs. Requires an authenticated user.', 'ai' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->get_terms_query_input_schema( $taxonomies ),
				'output_schema'       => $this->get_terms_query_output_schema(),
				'execute_callback'    => array( $this, 'execute_terms_query' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						// MCP clients assume open-world (may reach external systems) when the
						// hint is absent; this ability only reads the local database.
						'open_world'  => false,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Registers the `core/term-create`, `core/term-update`, and `core/term-delete` abilities.
	 *
	 * @since x.x.x
	 */
	private function register_term_write_abilities(): void {
		/*
		 * Taxonomies must be registered with `show_in_abilities` before the abilities are
		 * registered so they are included in their input schemas.
		 */
		$taxonomies = array_values( array_filter( get_taxonomies(), array( $this, 'check_is_taxonomy_allowed' ) ) );
		if ( empty( $taxonomies ) ) {
			return;
		}

		$create_schema = $this->get_term_create_input_schema( $taxonomies );

		$abilities = array(
			'core/term-create' => array(
				'label'               => __( 'Term Create', 'ai' ),
				'description'         => __( 'Creates a term, such as a category or tag, in a taxonomy exposed to abilities. Accepts a name, slug, description, and parent. Returns the created term; use `fields` to choose which term fields are returned. Requires an authenticated user who can create terms in the taxonomy.', 'ai' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $create_schema,
				'output_schema'       => $this->get_term_output_schema(),
				'execute_callback'    => array( $this, 'execute_term_create' ),
				'permission_callback' => array( $this, 'check_create_permission' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						// Every call creates a new term.
						'idempotent'  => false,
						'open_world'  => false,
					),
					'show_in_rest' => true,
				),
			),
			'core/term-update' => array(
				'label'               => __( 'Term Update', 'ai' ),
				'description'         => __( 'Updates a term by ID. Accepts a name, slug, description, and parent. Returns the updated term; use `fields` to choose which term fields are returned. Requires an authenticated user who can edit the term.', 'ai' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->get_term_update_input_schema( $create_schema ),
				'output_schema'       => $this->get_term_output_schema(),
				'execute_callback'    => array( $this, 'execute_term_update' ),
				'permission_callback' => array( $this, 'check_update_permission' ),
				'meta'                => array(
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
				),
			),
			'core/term-delete' => array(
				'label'               => __( 'Term Delete', 'ai' ),
				'description'         => __( 'Deletes a term by ID. Terms cannot be trashed, so `force` must be true. Returns the deleted term under `previous`; use `fields` to choose which term fields are returned. Requires an authenticated user who can delete the term.', 'ai' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->get_term_delete_input_schema( $taxonomies ),
				'output_schema'       => $this->get_term_delete_output_schema(),
				'execute_callback'    => array( $this, 'execute_term_delete' ),
				'permission_callback' => array( $this, 'check_delete_permission' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						// Repeating a deletion has no further effect; the Abilities API serves
						// destructive idempotent abilities over the DELETE method.
						'idempotent'  => true,
						'open_world'  => false,
					),
					'show_in_rest' => true,
				),
			),
		);

		foreach ( $abilities as $name => $args ) {
			// Unregister any core-provided copy first so this version wins.
			if ( wp_has_ability( $name ) ) {
				wp_unregister_ability( $name );
			}

			wp_register_ability( $name, $args );
		}
	}

	/**
	 * Permission callback for the `core/terms-query` ability.
	 *
	 * Requires a logged-in user. A single-term request is denied unless the term exists in a
	 * taxonomy exposed to abilities, and in the requested taxonomy when one is given. A
	 * collection request is denied when its taxonomy is not exposed. A `post` filter the
	 * caller cannot use is left to {@see self::execute_terms_query()}, which reports it with
	 * a specific error: the Abilities API replaces any error returned here with a generic one.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return bool True if the request may proceed, false otherwise.
	 */
	public function check_permission( $input = array() ): bool {
		$input = rest_sanitize_object( $input );

		if ( ! is_user_logged_in() ) {
			return false;
		}

		if ( isset( $input['id'] ) || isset( $input['slug'] ) ) {
			return ! is_wp_error( $this->get_term_for_input( $input ) );
		}

		return $this->check_is_taxonomy_allowed( isset( $input['taxonomy'] ) && is_string( $input['taxonomy'] ) ? $input['taxonomy'] : '' );
	}

	/**
	 * Executes the `core/terms-query` ability.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed>|\WP_Error A single term, a `terms` list with totals in collection mode, or a WP_Error.
	 */
	public function execute_terms_query( $input = array() ) {
		$input = rest_sanitize_object( $input );

		if ( isset( $input['id'] ) || isset( $input['slug'] ) ) {
			$term = $this->get_term_for_input( $input );
			if ( is_wp_error( $term ) ) {
				return $term;
			}

			return $this->prepare_item_for_response( $term, $this->get_fields_for_response( $term->taxonomy, $input ) );
		}

		$request    = $this->get_collection_request( $input );
		$permission = $this->get_items_permissions_check( $request );
		if ( true !== $permission ) {
			return is_wp_error( $permission ) ? $permission : new WP_Error(
				'terms_forbidden',
				__( 'Sorry, you are not allowed to do that.', 'ai' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return $this->get_items( $request, $this->get_fields_for_response( $request['taxonomy'], $input ) );
	}

	/**
	 * Casts a raw input value to an integer.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $value The raw input value.
	 * @return int The value as an integer, or 0 when not scalar.
	 */
	private function input_int( $value ): int {
		return is_scalar( $value ) ? (int) $value : 0;
	}

	/**
	 * Resolves the term a single-term request names.
	 *
	 * A slug is looked up in the requested taxonomy. An ID is looked up in the requested
	 * taxonomy when one is given, and otherwise in the taxonomy the term belongs to.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $input The ability input.
	 * @return \WP_Term|\WP_Error The term, or a WP_Error when it cannot be read.
	 */
	private function get_term_for_input( array $input ) {
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
	 * Builds the collection request from the ability input.
	 *
	 * Casts the values to their types and fills in the defaults: the Abilities API validates
	 * the input without casting it, and a GET request on WordPress 7.0 delivers every value
	 * as a string.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $input The ability input.
	 * @return array<string, mixed> The collection request.
	 */
	private function get_collection_request( array $input ): array {
		$request = array(
			'taxonomy'   => isset( $input['taxonomy'] ) && is_string( $input['taxonomy'] ) ? $input['taxonomy'] : '',
			'page'       => isset( $input['page'] ) ? max( 1, $this->input_int( $input['page'] ) ) : 1,
			'per_page'   => isset( $input['per_page'] ) ? max( 1, min( self::MAX_PER_PAGE, $this->input_int( $input['per_page'] ) ) ) : self::DEFAULT_PER_PAGE,
			'order'      => $input['order'] ?? 'asc',
			'orderby'    => $input['orderby'] ?? 'name',
			'hide_empty' => isset( $input['hide_empty'] ) && rest_is_boolean( $input['hide_empty'] ) && rest_sanitize_boolean( (string) $input['hide_empty'] ),
			'exclude'    => wp_parse_id_list( $input['exclude'] ?? array() ), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Term query argument.
			'include'    => wp_parse_id_list( $input['include'] ?? array() ),
			'post'       => isset( $input['post'] ) ? $this->input_int( $input['post'] ) : null,
		);

		if ( isset( $input['search'] ) && is_string( $input['search'] ) ) {
			$request['search'] = sanitize_text_field( $input['search'] );
		}

		if ( isset( $input['parent'] ) ) {
			$request['parent'] = $this->input_int( $input['parent'] );
		}

		return $request;
	}

	/**
	 * Returns the fields to include for terms of a taxonomy.
	 *
	 * These are the requested fields, or a lean default set when none are given, and always
	 * `id`. `parent` is only included for hierarchical taxonomies.
	 *
	 * @since x.x.x
	 *
	 * @param string       $taxonomy The taxonomy of the terms.
	 * @param array<mixed> $input    The ability input.
	 * @return array<scalar> The field names.
	 */
	private function get_fields_for_response( string $taxonomy, array $input ): array {
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
	 * Checks if the terms for a post can be read.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post $post     Post object.
	 * @param string   $taxonomy Taxonomy key.
	 * @return bool Whether the terms for the post can be read.
	 */
	private function check_read_terms_permission_for_post( WP_Post $post, string $taxonomy ): bool {
		// If the requested post isn't associated with this taxonomy, deny access.
		if ( ! is_object_in_taxonomy( $post->post_type, $taxonomy ) ) {
			return false;
		}

		// Grant access if the post is publicly viewable.
		if ( is_post_publicly_viewable( $post ) ) {
			return true;
		}

		// Otherwise grant access if the post is readable by the logged-in user.
		return current_user_can( 'read_post', $post->ID );
	}

	/**
	 * Checks if a request has access to read terms in the specified taxonomy.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $request The collection request.
	 * @return bool|\WP_Error True if the request has read access, otherwise false or WP_Error object.
	 */
	private function get_items_permissions_check( array $request ) {
		$tax_obj = get_taxonomy( $request['taxonomy'] );

		if ( ! $tax_obj || ! $this->check_is_taxonomy_allowed( $request['taxonomy'] ) ) {
			return false;
		}

		if ( ! empty( $request['post'] ) ) {
			$post = get_post( $request['post'] );

			if ( ! $post ) {
				return new WP_Error(
					'terms_post_invalid_id',
					__( 'Invalid post ID.', 'ai' ),
					array(
						'status' => 400,
					)
				);
			}

			if ( ! $this->check_read_terms_permission_for_post( $post, $request['taxonomy'] ) ) {
				return new WP_Error(
					'terms_forbidden_context',
					__( 'Sorry, you are not allowed to view terms for this post.', 'ai' ),
					array(
						'status' => rest_authorization_required_code(),
					)
				);
			}
		}

		return true;
	}

	/**
	 * Retrieves terms associated with a taxonomy.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $request The collection request.
	 * @param array<scalar>        $fields  The fields to include for each term.
	 * @return array<string, mixed> The terms, with the total number of terms and pages.
	 */
	private function get_items( array $request, array $fields ): array {
		/*
		 * This array defines mappings between public API query parameters whose
		 * values are accepted as-passed, and their internal WP_Query parameter
		 * name equivalents (some are the same).
		 */
		$parameter_mappings = array(
			'exclude'    => 'exclude', // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Term query argument.
			'include'    => 'include',
			'order'      => 'order',
			'orderby'    => 'orderby',
			'post'       => 'post',
			'hide_empty' => 'hide_empty',
			'per_page'   => 'number',
			'search'     => 'search',
		);

		$prepared_args = array( 'taxonomy' => $request['taxonomy'] );

		/*
		 * For each known parameter which is present in the request,
		 * set the parameter's value on the query $prepared_args.
		 */
		foreach ( $parameter_mappings as $api_param => $wp_param ) {
			if ( ! isset( $request[ $api_param ] ) ) {
				continue;
			}

			$prepared_args[ $wp_param ] = $request[ $api_param ];
		}

		$prepared_args['offset'] = ( $request['page'] - 1 ) * $prepared_args['number']; // @phpstan-ignore offsetAccess.notFound (per_page is always set, so number is too.)

		/** @var \WP_Taxonomy $taxonomy_obj The permission check has resolved the taxonomy. */
		$taxonomy_obj = get_taxonomy( $request['taxonomy'] );

		if ( $taxonomy_obj->hierarchical && isset( $request['parent'] ) ) {
			if ( 0 === $request['parent'] ) {
				// Only query top-level terms.
				$prepared_args['parent'] = 0;
			} elseif ( $request['parent'] ) {
				$prepared_args['parent'] = $request['parent'];
			}
		}

		/*
		 * When a taxonomy is registered with an 'args' array,
		 * those params override the `$args` passed to this function.
		 *
		 * We only need to do this if no `post` argument is provided.
		 * Otherwise, terms will be fetched using `wp_get_object_terms()`,
		 * which respects the default query arguments set for the taxonomy.
		 */
		if (
			empty( $prepared_args['post'] ) &&
			isset( $taxonomy_obj->args ) &&
			is_array( $taxonomy_obj->args )
		) {
			$prepared_args = array_merge( $prepared_args, $taxonomy_obj->args );
		}

		if ( ! empty( $prepared_args['post'] ) ) {
			$query_result = wp_get_object_terms( $prepared_args['post'], $request['taxonomy'], $prepared_args );

			// Used when calling wp_count_terms() below.
			$prepared_args['object_ids'] = $prepared_args['post'];
		} else {
			$query_result = get_terms( $prepared_args );
		}

		$count_args = $prepared_args;

		unset( $count_args['number'], $count_args['offset'] );

		$total_terms = wp_count_terms( $count_args );

		// wp_count_terms() can return a falsey value when the term has no children.
		if ( ! $total_terms || is_wp_error( $total_terms ) ) {
			$total_terms = 0;
		}

		$response = array();
		foreach ( (array) $query_result as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}

			$response[] = $this->prepare_item_for_response( $term, $fields );
		}

		$per_page = (int) $prepared_args['number'];

		return array(
			'terms'       => $response,
			'total'       => (int) $total_terms,
			'total_pages' => (int) ceil( $total_terms / $per_page ),
		);
	}

	/**
	 * Get the term, if the ID is valid.
	 *
	 * @since x.x.x
	 *
	 * @param int    $id       Supplied ID.
	 * @param string $taxonomy Taxonomy key.
	 * @return \WP_Term|\WP_Error Term object if ID is valid, WP_Error otherwise.
	 */
	private function get_term( $id, string $taxonomy ) {
		$error = new WP_Error(
			'terms_term_invalid',
			__( 'Term does not exist.', 'ai' ),
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
	 * @since x.x.x
	 *
	 * @param \WP_Term      $item   Term object.
	 * @param array<scalar> $fields The fields to include.
	 * @return array<string, mixed> The term data.
	 */
	private function prepare_item_for_response( WP_Term $item, array $fields ): array {
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
	 * @since x.x.x
	 *
	 * @return array<string, mixed> Item schema data.
	 */
	private function get_item_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'          => array(
					'description' => __( 'Unique identifier for the term.', 'ai' ),
					'type'        => 'integer',
				),
				'count'       => array(
					'description' => __( 'Number of published posts for the term.', 'ai' ),
					'type'        => 'integer',
				),
				'description' => array(
					'description' => __( 'HTML description of the term.', 'ai' ),
					'type'        => 'string',
				),
				'link'        => array(
					'description' => __( 'URL of the term.', 'ai' ),
					'type'        => 'string',
				),
				'name'        => array(
					'description' => __( 'HTML title for the term.', 'ai' ),
					'type'        => 'string',
				),
				'slug'        => array(
					'description' => __( 'An alphanumeric identifier for the term unique to its type.', 'ai' ),
					'type'        => 'string',
				),
				'taxonomy'    => array(
					'description' => __( 'Type attribution for the term.', 'ai' ),
					'type'        => 'string',
				),
				'parent'      => array(
					'description' => __( 'The parent term ID. Present for hierarchical taxonomies.', 'ai' ),
					'type'        => 'integer',
				),
			),
		);
	}

	/**
	 * Retrieves the query params for collections.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> Collection parameters.
	 */
	private function get_collection_params(): array {
		return array(
			'page'       => array(
				'description' => __( 'Current page of the collection. Defaults to 1.', 'ai' ),
				'type'        => 'integer',
				'minimum'     => 1,
			),
			'per_page'   => array(
				'description' => __( 'Maximum number of items to be returned in result set. Defaults to 10.', 'ai' ),
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => self::MAX_PER_PAGE,
			),
			'search'     => array(
				'description' => __( 'Limit results to those matching a string.', 'ai' ),
				'type'        => 'string',
			),
			'exclude'    => array( // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Term query argument.
				'description' => __( 'Ensure result set excludes specific IDs.', 'ai' ),
				'type'        => 'array',
				'items'       => array(
					'type' => 'integer',
				),
			),
			'include'    => array(
				'description' => __( 'Limit result set to specific IDs.', 'ai' ),
				'type'        => 'array',
				'items'       => array(
					'type' => 'integer',
				),
			),
			'order'      => array(
				'description' => __( 'Order sort attribute ascending or descending. Defaults to asc.', 'ai' ),
				'type'        => 'string',
				'enum'        => array(
					'asc',
					'desc',
				),
			),
			'orderby'    => array(
				'description' => __( 'Sort collection by term attribute. Defaults to name.', 'ai' ),
				'type'        => 'string',
				'enum'        => array(
					'id',
					'include',
					'name',
					'slug',
					'term_group',
					'description',
					'count',
				),
			),
			'hide_empty' => array(
				'description' => __( 'Whether to hide terms not assigned to any posts. Defaults to false.', 'ai' ),
				'type'        => 'boolean',
			),
			'parent'     => array(
				'description' => __( 'Limit result set to terms assigned to a specific parent. Ignored for taxonomies that are not hierarchical.', 'ai' ),
				'type'        => 'integer',
			),
			'post'       => array(
				'description' => __( 'Limit result set to terms assigned to a specific post.', 'ai' ),
				'type'        => 'integer',
			),
		);
	}

	/**
	 * Checks that the taxonomy is valid.
	 *
	 * @since x.x.x
	 *
	 * @param string $taxonomy Taxonomy to check.
	 * @return bool Whether the taxonomy is exposed to abilities.
	 */
	private function check_is_taxonomy_allowed( string $taxonomy ): bool {
		$taxonomy_obj = get_taxonomy( $taxonomy );
		return $taxonomy_obj && ! empty( $taxonomy_obj->show_in_abilities );
	}

	/**
	 * Builds the input schema for the `core/terms-query` ability.
	 *
	 * The ability has three mutually exclusive modes, modeled as a `oneOf` so invalid
	 * combinations are rejected rather than silently ignored:
	 *
	 *   - Get a single term by `id` (optionally guarded by `taxonomy`).
	 *   - Get a single term by `taxonomy` and `slug`.
	 *   - Query the terms of one `taxonomy`, filtered by the collection parameters.
	 *
	 * Each mode sets `additionalProperties: false`, and `fields` is accepted in every mode.
	 *
	 * @since x.x.x
	 *
	 * @param list<string> $taxonomies Exposed taxonomy names.
	 * @return array<string, mixed> The input JSON Schema.
	 */
	private function get_terms_query_input_schema( array $taxonomies ): array {
		$fields = array(
			'description' => __( 'Limit each returned term to these fields. If omitted, a lean set of common fields is returned. The ID is always included.', 'ai' ),
			'type'        => 'array',
			'uniqueItems' => true,
			'items'       => array(
				'type' => 'string',
				'enum' => array_keys( $this->get_item_schema()['properties'] ),
			),
		);

		return array(
			'type'  => 'object',
			'oneOf' => array(
				array(
					'title'                => __( 'Get a single term by ID', 'ai' ),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
					'properties'           => array(
						'id'       => array(
							'description' => __( 'Unique identifier for the term.', 'ai' ),
							'type'        => 'integer',
							'minimum'     => 1,
						),
						'taxonomy' => array(
							'description' => __( 'Optional. Return the term only if it belongs to this taxonomy.', 'ai' ),
							'type'        => 'string',
							'enum'        => $taxonomies,
						),
						'fields'   => $fields,
					),
				),
				array(
					'title'                => __( 'Get a single term by taxonomy and slug', 'ai' ),
					'required'             => array( 'taxonomy', 'slug' ),
					'additionalProperties' => false,
					'properties'           => array(
						'taxonomy' => array(
							'description' => __( 'Taxonomy of the term.', 'ai' ),
							'type'        => 'string',
							'enum'        => $taxonomies,
						),
						'slug'     => array(
							'description' => __( 'An alphanumeric identifier for the term unique to its type.', 'ai' ),
							'type'        => 'string',
							'minLength'   => 1,
						),
						'fields'   => $fields,
					),
				),
				array(
					'title'                => __( 'Query the terms of a taxonomy', 'ai' ),
					'required'             => array( 'taxonomy' ),
					'additionalProperties' => false,
					'properties'           => array_merge(
						array(
							'taxonomy' => array(
								'description' => __( 'Taxonomy to query.', 'ai' ),
								'type'        => 'string',
								'enum'        => $taxonomies,
							),
						),
						$this->get_collection_params(),
						array( 'fields' => $fields )
					),
				),
			),
		);
	}

	/**
	 * Builds the output schema for the `core/terms-query` ability.
	 *
	 * Single-term mode returns the term object directly, while collection mode returns a
	 * paginated wrapper. Both shapes reject unknown properties, which keeps a term from
	 * matching the wrapper, so no term field is marked required.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The output JSON Schema.
	 */
	private function get_terms_query_output_schema(): array {
		$item_schema = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => $this->get_item_schema()['properties'],
		);

		return array(
			'type'  => 'object',
			'oneOf' => array(
				$item_schema,
				array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'terms', 'total', 'total_pages' ),
					'properties'           => array(
						'terms'       => array(
							'description' => __( 'The terms matching the query.', 'ai' ),
							'type'        => 'array',
							'items'       => $item_schema,
						),
						'total'       => array(
							'description' => __( 'Total number of terms matching the query, across all pages. With hide_empty, empty parent terms returned for their non-empty children are not counted.', 'ai' ),
							'type'        => 'integer',
						),
						'total_pages' => array(
							'description' => __( 'Total number of pages for the query, based on the total.', 'ai' ),
							'type'        => 'integer',
						),
					),
				),
			),
		);
	}

	/**
	 * Checks permission for the `core/term-create` ability.
	 *
	 * The taxonomy must be exposed to abilities, and the current user must be able to edit
	 * its terms when it is hierarchical, or to assign them when it is not. The rest of the
	 * input is checked during execution.
	 *
	 * @since x.x.x
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
	 * Executes the `core/term-create` ability.
	 *
	 * {@see WP_Ability::execute()} always runs {@see self::check_create_permission()} first,
	 * so this only re-validates that the taxonomy is exposed before writing the term.
	 *
	 * @since x.x.x
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
				__( 'Sorry, you are not allowed to do that.', 'ai' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		$parent_error = $this->check_parent( $input, $taxonomy );
		if ( $parent_error instanceof WP_Error ) {
			return $parent_error;
		}

		$prepared_term = $this->prepare_item_for_database( $input, $taxonomy );

		$term = wp_insert_term( wp_slash( $prepared_term->name ), $taxonomy, wp_slash( (array) $prepared_term ) ); // @phpstan-ignore argument.type (The name is required, so it is a string.)
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
	 * Checks permission for the `core/term-update` ability.
	 *
	 * The term must exist in a taxonomy exposed to abilities (and in the `taxonomy` guard
	 * when given), and the current user must be able to edit it. The rest of the input is
	 * checked during execution.
	 *
	 * @since x.x.x
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
		if ( is_wp_error( $term ) ) {
			return false;
		}

		return current_user_can( 'edit_term', $term->term_id );
	}

	/**
	 * Executes the `core/term-update` ability.
	 *
	 * {@see WP_Ability::execute()} always runs {@see self::check_update_permission()} first,
	 * so this only re-validates the lookup itself before writing the term.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed>|\WP_Error The updated term, or a WP_Error.
	 */
	public function execute_term_update( $input = array() ) {
		$input = rest_sanitize_object( $input );

		$term = $this->get_term_for_write( $input );
		if ( is_wp_error( $term ) ) {
			return $term;
		}

		$parent_error = $this->check_parent( $input, $term->taxonomy );
		if ( $parent_error instanceof WP_Error ) {
			return $parent_error;
		}

		$prepared_term = $this->prepare_item_for_database( $input, $term->taxonomy );

		// Only update the term if we have something to update.
		if ( ! empty( $prepared_term ) ) { // @phpstan-ignore empty.variable (The prepared term is an object, so this never skips the update.)
			$update = wp_update_term( $term->term_id, $term->taxonomy, wp_slash( (array) $prepared_term ) );

			if ( is_wp_error( $update ) ) {
				return $update;
			}
		}

		$term = $this->get_term( $term->term_id, $term->taxonomy );
		if ( is_wp_error( $term ) ) {
			return $term;
		}

		return $this->prepare_item_for_response( $term, $this->get_fields_for_response( $term->taxonomy, $input ) );
	}

	/**
	 * Checks permission for the `core/term-delete` ability.
	 *
	 * The term must exist in a taxonomy exposed to abilities (and in the `taxonomy` guard
	 * when given), and the current user must be able to delete it.
	 *
	 * @since x.x.x
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
		if ( is_wp_error( $term ) ) {
			return false;
		}

		return current_user_can( 'delete_term', $term->term_id );
	}

	/**
	 * Executes the `core/term-delete` ability.
	 *
	 * {@see WP_Ability::execute()} always runs {@see self::check_delete_permission()} first;
	 * this re-validates the lookup and, because the operation is destructive, checks the
	 * delete capability once more right before anything is removed. Terms cannot be trashed,
	 * so the term is only deleted when `force` is true, and is then returned under `previous`.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed>|\WP_Error A `deleted`/`previous` pair, or a WP_Error.
	 */
	public function execute_term_delete( $input = array() ) {
		$input = rest_sanitize_object( $input );

		$term = $this->get_term_for_write( $input );
		if ( is_wp_error( $term ) ) {
			return $term;
		}

		if ( ! current_user_can( 'delete_term', $term->term_id ) ) {
			return new WP_Error(
				'terms_cannot_delete',
				__( 'Sorry, you are not allowed to delete this term.', 'ai' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		$force = isset( $input['force'] ) && rest_is_boolean( $input['force'] ) && rest_sanitize_boolean( (string) $input['force'] );

		// We don't support trashing for terms.
		if ( ! $force ) {
			return new WP_Error(
				'terms_trash_not_supported',
				__( 'Terms do not support trashing. Set `force` to true to delete.', 'ai' ),
				array( 'status' => 501 )
			);
		}

		$previous = $this->prepare_item_for_response( $term, $this->get_fields_for_response( $term->taxonomy, $input ) );

		$retval = wp_delete_term( $term->term_id, $term->taxonomy );

		if ( ! $retval ) {
			return new WP_Error(
				'terms_cannot_delete',
				__( 'The term cannot be deleted.', 'ai' ),
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
	 * write's `slug` is a value to store. A guard that is not a taxonomy name matches no
	 * term.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $input The ability input.
	 * @return \WP_Term|\WP_Error The term, or a WP_Error when it cannot be written.
	 */
	private function get_term_for_write( array $input ) {
		if ( isset( $input['taxonomy'] ) && ! is_string( $input['taxonomy'] ) ) {
			// The same error as for a missing term.
			return $this->get_term( 0, '' );
		}

		return $this->get_term_for_input( array( 'id' => $input['id'] ?? 0 ) + $input );
	}

	/**
	 * Checks that the parent given for a term can be set.
	 *
	 * Shared by the create and update abilities. A parent can only be set in a hierarchical
	 * taxonomy, and must be a term of the same taxonomy, or 0 for a top-level term.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $input    The ability input.
	 * @param string       $taxonomy Taxonomy key.
	 * @return \WP_Error|null A WP_Error when the parent cannot be set, or null.
	 */
	private function check_parent( array $input, string $taxonomy ): ?WP_Error {
		if ( isset( $input['parent'] ) ) {
			if ( ! is_taxonomy_hierarchical( $taxonomy ) ) {
				return new WP_Error(
					'terms_taxonomy_not_hierarchical',
					__( 'Cannot set parent term, taxonomy is not hierarchical.', 'ai' ),
					array( 'status' => 400 )
				);
			}

			$parent = get_term( (int) $input['parent'], $taxonomy );

			if ( ! $parent ) {
				return new WP_Error(
					'terms_term_invalid',
					__( 'Parent term does not exist.', 'ai' ),
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
	 * @since x.x.x
	 *
	 * @param array<mixed> $input    The ability input.
	 * @param string       $taxonomy Taxonomy key.
	 * @return \stdClass Term object.
	 */
	private function prepare_item_for_database( array $input, string $taxonomy ): stdClass {
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
	 * Builds the schema of the `fields` input shared by the write abilities.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The `fields` JSON Schema.
	 */
	private function get_fields_input_schema(): array {
		return array(
			'description' => __( 'Limit the returned term to these fields. If omitted, a lean set of common fields is returned. The ID is always included.', 'ai' ),
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
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The term JSON Schema.
	 */
	private function get_term_output_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => $this->get_item_schema()['properties'],
		);
	}

	/**
	 * Builds the input schema for the `core/term-create` ability.
	 *
	 * `additionalProperties: false` rejects unknown fields instead of dropping them, so e.g.
	 * passing an `id` fails validation instead of silently creating a new term.
	 *
	 * @since x.x.x
	 *
	 * @param list<string> $taxonomies Exposed taxonomy names.
	 * @return array<string, mixed> The input JSON Schema.
	 */
	private function get_term_create_input_schema( array $taxonomies ): array {
		$properties = $this->get_item_schema()['properties'];

		return array(
			'type'                 => 'object',
			'required'             => array( 'taxonomy', 'name' ),
			'additionalProperties' => false,
			'properties'           => array(
				'taxonomy'    => array(
					'description' => __( 'Taxonomy of the term to create.', 'ai' ),
					'type'        => 'string',
					'enum'        => $taxonomies,
				),
				'name'        => $properties['name'],
				'slug'        => $properties['slug'],
				'description' => $properties['description'],
				'parent'      => array(
					'description' => __( 'The parent term ID, or 0 for a top-level term. Only supported for hierarchical taxonomies.', 'ai' ),
					'type'        => 'integer',
				),
				'fields'      => $this->get_fields_input_schema(),
			),
		);
	}

	/**
	 * Builds the input schema for the `core/term-update` ability from the create schema.
	 *
	 * The term is identified by `id`, and `taxonomy` becomes an optional guard.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $create_schema The input schema of the `core/term-create` ability.
	 * @return array<string, mixed> The input JSON Schema.
	 */
	private function get_term_update_input_schema( array $create_schema ): array {
		$properties = array(
			'id' => array(
				'description' => __( 'Unique identifier for the term.', 'ai' ),
				'type'        => 'integer',
				'minimum'     => 1,
			),
		) + $create_schema['properties'];

		$properties['taxonomy']['description'] = __( 'Optional. Update the term only if it belongs to this taxonomy.', 'ai' );

		$create_schema['required']   = array( 'id' );
		$create_schema['properties'] = $properties;

		return $create_schema;
	}

	/**
	 * Builds the input schema for the `core/term-delete` ability.
	 *
	 * @since x.x.x
	 *
	 * @param list<string> $taxonomies Exposed taxonomy names.
	 * @return array<string, mixed> The input JSON Schema.
	 */
	private function get_term_delete_input_schema( array $taxonomies ): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'id' ),
			'additionalProperties' => false,
			'properties'           => array(
				'id'       => array(
					'description' => __( 'Unique identifier for the term.', 'ai' ),
					'type'        => 'integer',
					'minimum'     => 1,
				),
				'taxonomy' => array(
					'description' => __( 'Optional. Delete the term only if it belongs to this taxonomy.', 'ai' ),
					'type'        => 'string',
					'enum'        => $taxonomies,
				),
				'force'    => array(
					'description' => __( 'Required to be true, as terms do not support trashing.', 'ai' ),
					'type'        => 'boolean',
				),
				'fields'   => $this->get_fields_input_schema(),
			),
		);
	}

	/**
	 * Builds the output schema for the `core/term-delete` ability.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The output JSON Schema.
	 */
	private function get_term_delete_output_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'deleted', 'previous' ),
			'properties'           => array(
				'deleted'  => array(
					'description' => __( 'Whether the term was deleted.', 'ai' ),
					'type'        => 'boolean',
				),
				'previous' => $this->get_term_output_schema(),
			),
		);
	}
}
