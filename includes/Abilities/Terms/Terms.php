<?php
/**
 * The `core/terms-query` WordPress Ability.
 *
 * @package WordPress\AI
 *
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Terms;

use WP_Error;
use WP_Post;
use WP_Taxonomy;
use WP_Term;
use stdClass;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Terms
 *
 * Registers the read-only `core/terms-query` ability, which retrieves one or more
 * terms from taxonomies exposed to abilities via `show_in_abilities`. Supports fetching
 * a single term by ID or by taxonomy and slug, or querying a paginated collection of
 * one taxonomy's terms, optionally filtered by search, parent, attached post,
 * emptiness, or included and excluded IDs.
 *
 * Exposure is an explicit opt-in, like `core/content-query` for post types:
 * `show_in_rest` alone is not enough, since the REST API runs filters and callbacks
 * that abilities skip. Listing the terms attached to a post mirrors the REST terms
 * controller and requires that the post be publicly viewable or readable by the
 * current user.
 *
 * Like the other core query abilities in the plugin, this class is kept close to a
 * proposed WordPress core implementation. Differences from the core version are
 * marked with `// Plugin:` comments. Additionally, all user-facing strings use the
 * 'ai' text domain.
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
	 * Supported collection orderby values.
	 *
	 * @since x.x.x
	 * @var string[]
	 */
	private const ORDERBY_VALUES = array( // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is used as an array const.
		'name',
		'slug',
		'id',
		'count',
		'include',
	);

	/**
	 * Default fields returned when the caller does not request a field subset.
	 *
	 * @since x.x.x
	 * @var string[]
	 */
	private const DEFAULT_FIELDS = array( // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is used as an array const.
		'id',
		'name',
		'slug',
		'taxonomy',
		'parent',
		'count',
	);

	/**
	 * Hooks the ability into the Abilities API.
	 *
	 * Plugin: this method has no equivalent in the core class. In core, register() is
	 * invoked directly from wp_register_core_abilities() (already on the
	 * `wp_abilities_api_init` hook). The plugin instead hooks register() slightly later
	 * (priority 11) so it can override any core-provided copy, and registers the category
	 * as a fallback in case core has not.
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
	 * Plugin: this method has no equivalent in the core class; core relies on
	 * wp_register_core_ability_categories() to register the `content` category.
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
		if ( array() === $this->get_exposed_taxonomies() ) {
			return;
		}

		// Plugin: unregister any core-provided copy first so the plugin's version wins.
		if ( wp_has_ability( 'core/terms-query' ) ) {
			wp_unregister_ability( 'core/terms-query' );
		}

		wp_register_ability(
			'core/terms-query',
			array(
				'label'               => __( 'Terms Query', 'ai' ),
				'description'         => __( 'Reads taxonomy terms, such as categories and tags, from taxonomies exposed to abilities. Single-term lookups by ID or by taxonomy and slug return the term object directly. Query mode returns the terms of one taxonomy, optionally filtered by search, parent, attached post, emptiness, or included and excluded IDs. Requires an authenticated user.', 'ai' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->get_terms_input_schema(),
				'output_schema'       => $this->get_terms_output_schema(),
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
	 * Permission callback for the `core/terms-query` ability.
	 *
	 * Single-term requests are checked against the resolved term, and collection
	 * requests against the requested taxonomy. The `post` filter is checked when the
	 * ability executes, so an invalid or unreadable post gets a specific error, like
	 * the REST terms controller returns.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return bool True if the request may proceed, false otherwise.
	 */
	public function check_permission( $input = array() ): bool {
		$input = $this->to_input_array( $input );

		if ( ! is_user_logged_in() ) {
			return false;
		}

		if ( $this->is_single_term_request( $input ) ) {
			return $this->resolve_readable_term( $input ) instanceof WP_Term;
		}

		return $this->get_readable_taxonomy( $input ) instanceof WP_Taxonomy;
	}

	/**
	 * Executes the `core/terms-query` ability.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed>|\WP_Error Term data, paginated collection data, or a WP_Error on failure.
	 */
	public function execute_terms_query( $input = array() ) {
		$input  = $this->to_input_array( $input );
		$fields = $this->normalize_fields( $input );

		if ( $this->is_single_term_request( $input ) ) {
			$term = $this->resolve_readable_term( $input );
			if ( ! $term instanceof WP_Term ) {
				return new WP_Error(
					'ability_invalid_permissions',
					__( 'The requested term cannot be read.', 'ai' )
				);
			}

			return $this->format_term( $term, $fields );
		}

		$taxonomy = $this->get_readable_taxonomy( $input );
		if ( ! $taxonomy instanceof WP_Taxonomy ) {
			return new WP_Error(
				'ability_invalid_permissions',
				__( 'The requested taxonomy cannot be read.', 'ai' )
			);
		}

		$post_id = empty( $input['post'] ) ? 0 : $this->input_int( $input['post'] );
		if ( 0 !== $post_id ) {
			$post_error = $this->check_post_filter( $post_id, $taxonomy->name );
			if ( $post_error instanceof WP_Error ) {
				return $post_error;
			}
		}

		$per_page = $this->normalize_per_page( $input );
		$page     = isset( $input['page'] ) ? max( 1, $this->input_int( $input['page'] ) ) : 1;

		// Defaults match the REST terms controller: ordered by name, ascending, and
		// empty terms included.
		$query_args = array(
			'taxonomy'   => $taxonomy->name,
			'number'     => $per_page,
			'offset'     => ( $page - 1 ) * $per_page,
			'orderby'    => $this->normalize_orderby( $input ),
			'order'      => $this->normalize_order( $input ),
			'hide_empty' => $this->normalize_bool( $input['hide_empty'] ?? false ),
		);

		$include = $this->normalize_id_list( $input, 'include' );
		if ( array() !== $include ) {
			$query_args['include'] = $include;
		}

		// Like the REST controller, `exclude` is ignored by the term query when `include` is set.
		$exclude = $this->normalize_id_list( $input, 'exclude' );
		if ( array() !== $exclude ) {
			$query_args['exclude'] = $exclude; // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Term query argument, bounded by the per-page limit.
		}

		if ( isset( $input['search'] ) && is_string( $input['search'] ) && '' !== $input['search'] ) {
			$query_args['search'] = $input['search'];
		}

		// Like the REST controller, `parent` only applies to hierarchical taxonomies.
		if ( $taxonomy->hierarchical && array_key_exists( 'parent', $input ) ) {
			$query_args['parent'] = $this->input_int( $input['parent'] );
		}

		/*
		 * Default query arguments set when the taxonomy was registered override the
		 * request, matching the REST controller. They are skipped for post lookups,
		 * since wp_get_object_terms() already applies them.
		 */
		if ( 0 === $post_id && isset( $taxonomy->args ) && is_array( $taxonomy->args ) ) {
			$query_args = array_merge( $query_args, $taxonomy->args );
		}

		if ( 0 !== $post_id ) {
			$terms                    = wp_get_object_terms( $post_id, $taxonomy->name, $query_args );
			$query_args['object_ids'] = $post_id;
		} else {
			$terms = get_terms( $query_args );
		}

		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$count_args = $query_args;
		unset( $count_args['number'], $count_args['offset'] );

		$total = wp_count_terms( $count_args );
		$total = is_wp_error( $total ) ? 0 : (int) $total;

		$items = array();
		foreach ( (array) $terms as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}

			$items[] = $this->format_term( $term, $fields );
		}

		return array(
			'terms'       => $items,
			'total'       => $total,
			'total_pages' => (int) ceil( $total / $per_page ),
		);
	}

	/**
	 * Casts raw ability input to an array.
	 *
	 * Schema validation accepts object input (`rest_is_object()` allows a
	 * `stdClass`, and the input schema's own `default` is one), so it must be
	 * treated as equivalent to its array form rather than discarded. Any other
	 * non-array input is replaced with an empty array.
	 *
	 * The Abilities API validates input against the schema but does not coerce
	 * it, and REST `GET` requests (the only method for read-only abilities)
	 * deliver every scalar as a string. Each normalizer below therefore accepts
	 * the string forms validation accepted.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input The raw ability input.
	 * @return array<mixed> The input as an array.
	 */
	private function to_input_array( $input ): array {
		if ( $input instanceof stdClass ) {
			$input = (array) $input;
		}

		return is_array( $input ) ? $input : array();
	}

	/**
	 * Casts a raw input value to a non-negative integer.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $value The raw input value.
	 * @return int The value as a non-negative integer, or 0 when not scalar.
	 */
	private function input_int( $value ): int {
		return is_scalar( $value ) ? absint( $value ) : 0;
	}

	/**
	 * Checks whether the input asks for a single term rather than a collection.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $input The ability input.
	 * @return bool Whether the input is a single-term lookup.
	 */
	private function is_single_term_request( array $input ): bool {
		return array_key_exists( 'id', $input ) || array_key_exists( 'slug', $input );
	}

	/**
	 * Resolves the target of a single-term lookup when the current user may read it.
	 *
	 * Shared by the permission and execute callbacks so the single-term
	 * authorization decision has exactly one implementation.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $input The ability input.
	 * @return \WP_Term|null The readable term, or null when not found or not readable.
	 */
	private function resolve_readable_term( array $input ): ?WP_Term {
		if ( array_key_exists( 'id', $input ) ) {
			$term = get_term( $this->input_int( $input['id'] ) );
			if ( ! $term instanceof WP_Term ) {
				return null;
			}

			// An optional taxonomy must match the term's own taxonomy.
			if ( isset( $input['taxonomy'] ) && $input['taxonomy'] !== $term->taxonomy ) {
				return null;
			}
		} else {
			$taxonomy = $this->get_readable_taxonomy( $input );
			if ( ! $taxonomy instanceof WP_Taxonomy || ! is_string( $input['slug'] ) || '' === $input['slug'] ) {
				return null;
			}

			$term = get_term_by( 'slug', $input['slug'], $taxonomy->name );
			if ( ! $term instanceof WP_Term ) {
				return null;
			}
		}

		$taxonomy = $this->get_readable_taxonomy( array( 'taxonomy' => $term->taxonomy ) );

		return $taxonomy instanceof WP_Taxonomy ? $term : null;
	}

	/**
	 * Returns the requested taxonomy when the current user may read its terms.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $input The ability input.
	 * @return \WP_Taxonomy|null The readable taxonomy, or null when missing or not exposed to abilities.
	 */
	private function get_readable_taxonomy( array $input ): ?WP_Taxonomy {
		if ( ! isset( $input['taxonomy'] ) || ! is_string( $input['taxonomy'] ) ) {
			return null;
		}

		$taxonomy = get_taxonomy( $input['taxonomy'] );
		if ( ! $taxonomy instanceof WP_Taxonomy || empty( $taxonomy->show_in_abilities ) ) {
			return null;
		}

		return $taxonomy;
	}

	/**
	 * Checks the `post` filter of a collection request.
	 *
	 * Mirrors the post checks in WP_REST_Terms_Controller::get_items_permissions_check(),
	 * returning the same errors with the plugin's code prefix.
	 *
	 * @since x.x.x
	 *
	 * @param int    $post_id  The requested post ID.
	 * @param string $taxonomy The taxonomy name.
	 * @return \WP_Error|null A WP_Error when the post is missing or its terms cannot be read, otherwise null.
	 */
	private function check_post_filter( int $post_id, string $taxonomy ): ?WP_Error {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return new WP_Error(
				'terms_post_invalid_id',
				__( 'Invalid post ID.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $this->can_read_terms_for_post( $post, $taxonomy ) ) {
			return new WP_Error(
				'terms_forbidden_context',
				__( 'Sorry, you are not allowed to view terms for this post.', 'ai' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return null;
	}

	/**
	 * Checks whether the terms attached to a post may be read.
	 *
	 * Mirrors WP_REST_Terms_Controller::check_read_terms_permission_for_post().
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post $post     The post.
	 * @param string   $taxonomy The taxonomy name.
	 * @return bool Whether the post's terms in the taxonomy can be read.
	 */
	private function can_read_terms_for_post( WP_Post $post, string $taxonomy ): bool {
		if ( ! is_object_in_taxonomy( $post->post_type, $taxonomy ) ) {
			return false;
		}

		return is_post_publicly_viewable( $post ) || current_user_can( 'read_post', $post->ID );
	}

	/**
	 * Returns the taxonomies exposed to abilities via `show_in_abilities`.
	 *
	 * Resolved on every call rather than cached, since taxonomies can be registered or
	 * unregistered after the ability is registered.
	 *
	 * @since x.x.x
	 *
	 * @return string[] Exposed taxonomy names.
	 */
	private function get_exposed_taxonomies(): array {
		return array_values( get_taxonomies( array( 'show_in_abilities' => true ) ) );
	}

	/**
	 * Returns the requested fields, or a lean default set when none are given.
	 *
	 * The `id` field is always included, so the result is never empty and always
	 * serializes as a JSON object.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $input The ability input.
	 * @return string[] List of requested field names.
	 */
	private function normalize_fields( array $input ): array {
		$fields = isset( $input['fields'] ) ? $this->normalize_string_list( $input['fields'] ) : array();
		if ( array() === $fields ) {
			$fields = self::DEFAULT_FIELDS;
		}

		if ( ! in_array( 'id', $fields, true ) ) {
			array_unshift( $fields, 'id' );
		}

		return $fields;
	}

	/**
	 * Normalizes the requested per-page value to the supported bounds.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $input The ability input.
	 * @return int The clamped per-page value.
	 */
	private function normalize_per_page( array $input ): int {
		$per_page = isset( $input['per_page'] ) ? $this->input_int( $input['per_page'] ) : self::DEFAULT_PER_PAGE;

		return max( 1, min( self::MAX_PER_PAGE, $per_page ) );
	}

	/**
	 * Normalizes the collection orderby value.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $input The ability input.
	 * @return string The orderby value.
	 */
	private function normalize_orderby( array $input ): string {
		$orderby = $input['orderby'] ?? 'name';

		return is_string( $orderby ) && in_array( $orderby, self::ORDERBY_VALUES, true ) ? $orderby : 'name';
	}

	/**
	 * Normalizes the collection order value.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $input The ability input.
	 * @return string The order value, `ASC` or `DESC`.
	 */
	private function normalize_order( array $input ): string {
		$order = $input['order'] ?? 'asc';

		return is_string( $order ) && 'desc' === strtolower( $order ) ? 'DESC' : 'ASC';
	}

	/**
	 * Normalizes a boolean input, accepting the string forms REST `GET` delivers.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $value The raw input value.
	 * @return bool The boolean value.
	 */
	private function normalize_bool( $value ): bool {
		if ( is_string( $value ) ) {
			return in_array( strtolower( $value ), array( 'true', '1' ), true );
		}

		return true === $value || 1 === $value;
	}

	/**
	 * Normalizes a mixed value into a list of non-empty strings.
	 *
	 * Accepts arrays and CSV strings, since REST `GET` requests deliver list
	 * input as strings that schema validation coerces only for the check.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $value Raw value.
	 * @return string[] Normalized strings.
	 */
	private function normalize_string_list( $value ): array {
		if ( is_string( $value ) ) {
			$value = wp_parse_list( $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$strings = array();
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) || '' === $item ) {
				continue;
			}

			$strings[] = $item;
		}

		return array_values( array_unique( $strings ) );
	}

	/**
	 * Normalizes a collection-mode list of term IDs, such as `include` or `exclude`.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $input The ability input.
	 * @param string       $key   The input key holding the list.
	 * @return int[] Term IDs.
	 */
	private function normalize_id_list( array $input, string $key ): array {
		if ( empty( $input[ $key ] ) ) {
			return array();
		}

		$ids = $input[ $key ];
		if ( is_scalar( $ids ) ) {
			$ids = (string) $ids;
		} elseif ( ! is_array( $ids ) ) {
			return array();
		}

		return array_values( array_filter( wp_parse_id_list( $ids ) ) );
	}

	/**
	 * Returns the term field definitions, keyed by field name in output order.
	 *
	 * This is the single source of truth for the ability's term fields: the output
	 * schema uses the definitions directly, while the input schema uses the keys.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> Term field definitions.
	 */
	private function get_term_properties(): array {
		return array(
			'id'          => array(
				'type'        => 'integer',
				'description' => __( 'Unique identifier for the term.', 'ai' ),
			),
			'name'        => array(
				'type'        => 'string',
				'description' => __( 'HTML title for the term.', 'ai' ),
			),
			'slug'        => array(
				'type'        => 'string',
				'description' => __( 'An alphanumeric identifier for the term unique to its taxonomy.', 'ai' ),
			),
			'taxonomy'    => array(
				'type'        => 'string',
				'description' => __( 'Type attribution for the term.', 'ai' ),
			),
			'description' => array(
				'type'        => 'string',
				'description' => __( 'HTML description of the term.', 'ai' ),
			),
			'parent'      => array(
				'type'        => 'integer',
				'description' => __( 'The parent term ID. Present for hierarchical taxonomies.', 'ai' ),
			),
			'count'       => array(
				'type'        => 'integer',
				'description' => __( 'Number of published posts for the term.', 'ai' ),
			),
			'link'        => array(
				'type'        => 'string',
				'description' => __( 'URL of the term archive.', 'ai' ),
			),
		);
	}

	/**
	 * Builds the input schema for the `core/terms-query` ability.
	 *
	 * The ability has three mutually exclusive modes, modeled as a `oneOf` so invalid
	 * combinations are rejected rather than silently ignored:
	 *
	 *   - Get a single term by `id`.
	 *   - Get a single term by `taxonomy` and `slug`.
	 *   - Query a collection of one taxonomy's terms.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The input JSON Schema.
	 */
	private function get_terms_input_schema(): array {
		/*
		 * The taxonomy enum intentionally reflects taxonomies available at ability
		 * registration time, matching how the sibling query abilities pin post types
		 * and roles.
		 */
		$taxonomy = array(
			'type'        => 'string',
			'enum'        => $this->get_exposed_taxonomies(),
			'description' => __( 'The taxonomy name, for example category or post_tag.', 'ai' ),
		);
		$fields   = array(
			'type'        => 'array',
			'uniqueItems' => true,
			'minItems'    => 1,
			'items'       => array(
				'type' => 'string',
				'enum' => array_keys( $this->get_term_properties() ),
			),
			'description' => __( 'Limit each returned term to these fields. If omitted, a lean set of common read fields is returned.', 'ai' ),
		);

		return array(
			'type'    => 'object',
			'default' => (object) array(),
			'oneOf'   => array(
				array(
					'title'                => __( 'Get a single term by ID', 'ai' ),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
					'properties'           => array(
						'id'       => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Retrieve a single readable term by ID.', 'ai' ),
						),
						'taxonomy' => $taxonomy,
						'fields'   => $fields,
					),
				),
				array(
					'title'                => __( 'Get a single term by taxonomy and slug', 'ai' ),
					'required'             => array( 'taxonomy', 'slug' ),
					'additionalProperties' => false,
					'properties'           => array(
						'taxonomy' => $taxonomy,
						'slug'     => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => __( 'Retrieve a single readable term by its slug within the taxonomy.', 'ai' ),
						),
						'fields'   => $fields,
					),
				),
				array(
					'title'                => __( 'Query the terms of a taxonomy', 'ai' ),
					'required'             => array( 'taxonomy' ),
					'additionalProperties' => false,
					'properties'           => array(
						'taxonomy'   => $taxonomy,
						'search'     => array(
							'type'        => 'string',
							'description' => __( 'Limit results to terms whose name or slug contains this string.', 'ai' ),
						),
						'parent'     => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => __( 'Limit results to direct children of this term ID. Use 0 for top-level terms. Applies to hierarchical taxonomies only.', 'ai' ),
						),
						'post'       => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Limit results to terms assigned to this post ID.', 'ai' ),
						),
						'hide_empty' => array(
							'type'        => 'boolean',
							'description' => __( 'Whether to hide terms not assigned to any published post. Defaults to false.', 'ai' ),
						),
						'include'    => array(
							'type'        => 'array',
							'uniqueItems' => true,
							'minItems'    => 1,
							'items'       => array(
								'type'    => 'integer',
								'minimum' => 1,
							),
							'description' => __( 'Limit the query to these term IDs.', 'ai' ),
						),
						'exclude'    => array( // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Term query argument, bounded by the per-page limit.
							'type'        => 'array',
							'uniqueItems' => true,
							'minItems'    => 1,
							'items'       => array(
								'type'    => 'integer',
								'minimum' => 1,
							),
							'description' => __( 'Exclude these term IDs from the results. Ignored when include is set.', 'ai' ),
						),
						'orderby'    => array(
							'type'        => 'string',
							'enum'        => self::ORDERBY_VALUES,
							'description' => __( 'Sort the collection by this term attribute. Defaults to name.', 'ai' ),
						),
						'order'      => array(
							'type'        => 'string',
							'enum'        => array( 'asc', 'desc' ),
							'description' => __( 'Sort order. Defaults to asc.', 'ai' ),
						),
						'fields'     => $fields,
						'page'       => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Page of results to return.', 'ai' ),
						),
						'per_page'   => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => self::MAX_PER_PAGE,
							'description' => __( 'Maximum number of terms to return per page.', 'ai' ),
						),
					),
				),
			),
		);
	}

	/**
	 * Builds the output schema for the `core/terms-query` ability.
	 *
	 * No term field is marked required because the `fields` input lets the caller
	 * request any subset. Single-term mode returns the term object directly, while
	 * collection mode returns a paginated wrapper.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The output JSON Schema.
	 */
	private function get_terms_output_schema(): array {
		$term_schema = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => $this->get_term_properties(),
		);

		$collection_schema = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'terms', 'total', 'total_pages' ),
			'properties'           => array(
				'terms'       => array(
					'type'        => 'array',
					'description' => __( 'The readable terms matching the collection request.', 'ai' ),
					'items'       => $term_schema,
				),
				'total'       => array(
					'type'        => 'integer',
					'description' => __( 'Total number of terms matching the query, across all pages.', 'ai' ),
				),
				'total_pages' => array(
					'type'        => 'integer',
					'description' => __( 'Total number of result pages available for the query.', 'ai' ),
				),
			),
		);

		return array(
			'oneOf' => array(
				$term_schema,
				$collection_schema,
			),
		);
	}

	/**
	 * Formats a term into the ability output shape.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Term $term   The term object.
	 * @param string[] $fields The requested field names.
	 * @return array<string, mixed> The formatted term data.
	 */
	private function format_term( WP_Term $term, array $fields ): array {
		$fields_requested = static function ( string $field ) use ( $fields ): bool {
			return in_array( $field, $fields, true );
		};

		$data = array();

		if ( $fields_requested( 'id' ) ) {
			$data['id'] = (int) $term->term_id;
		}
		if ( $fields_requested( 'name' ) ) {
			$data['name'] = (string) $term->name;
		}
		if ( $fields_requested( 'slug' ) ) {
			$data['slug'] = (string) $term->slug;
		}
		if ( $fields_requested( 'taxonomy' ) ) {
			$data['taxonomy'] = (string) $term->taxonomy;
		}
		if ( $fields_requested( 'description' ) ) {
			$data['description'] = (string) $term->description;
		}
		// Like the REST controller, `parent` is only reported for hierarchical taxonomies.
		if ( $fields_requested( 'parent' ) && is_taxonomy_hierarchical( $term->taxonomy ) ) {
			$data['parent'] = (int) $term->parent;
		}
		if ( $fields_requested( 'count' ) ) {
			$data['count'] = (int) $term->count;
		}
		if ( $fields_requested( 'link' ) ) {
			$link         = get_term_link( $term );
			$data['link'] = is_string( $link ) ? $link : '';
		}

		return $data;
	}
}
