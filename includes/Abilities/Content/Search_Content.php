<?php
/**
 * The `ai/search-content` WordPress Ability.
 *
 * @package WordPress\AI
 *
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Content;

use WP_Error;
use WP_Post;
use WP_Post_Type;
use WP_Query;
use WP_Taxonomy;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Search_Content
 *
 * Registers the read-only `ai/search-content` ability, which runs a full-text search
 * across the post types exposed to abilities via `show_in_abilities` and returns a
 * bounded list of matching posts as titles and excerpts. Without a search term it
 * lists the newest posts instead, and either way the list can be narrowed by a
 * taxonomy term, a date range and whether a post has a featured image.
 *
 * This is a sibling of {@see Content}, not a change to it: `core/content-query` is kept
 * byte-similar to WordPress core's copy, and its registered description states that its
 * lookups and filters are exact-match only. Rather than widen that ability, this class
 * adds search as its own ability so every ability consumer — the AI Workspace, the MCP
 * surface, and the Abilities Explorer — gets the same behavior.
 *
 * Two properties are load bearing:
 *
 *  - The result set is capped at {@see self::MAX_PER_PAGE} items. That is a context
 *    limit for the model, not an access control.
 *  - Every returned row is filtered at execute time by the requesting user's own
 *    capabilities, using the same read permission walk `core/content-query` performs,
 *    so a result set never contains an item the user could not otherwise read. The
 *    permission callback can only gate the request coarsely, because the matching
 *    rows are unknown until the query runs.
 *
 * Full body content is deliberately never returned. A body is read separately, and
 * for a handful of posts at a time, by {@see Read_Content_Bodies}.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since x.x.x
 */
final class Search_Content {

	/**
	 * The ability name.
	 *
	 * @since x.x.x
	 * @var string
	 */
	private const ABILITY = 'ai/search-content';

	/**
	 * The ability category used for content abilities.
	 *
	 * @since x.x.x
	 * @var string
	 */
	private const CATEGORY = 'content';

	/**
	 * Default number of results returned per page.
	 *
	 * @since x.x.x
	 * @var int
	 */
	private const DEFAULT_PER_PAGE = 10;

	/**
	 * Maximum number of results returned per page.
	 *
	 * A search hands its results to a model as conversation context, so the page size
	 * is capped low on purpose. See {@see self::normalize_per_page()}.
	 *
	 * @since x.x.x
	 * @var int
	 */
	private const MAX_PER_PAGE = 20;

	/**
	 * Most terms a single taxonomy filter may name.
	 *
	 * @since x.x.x
	 * @var int
	 */
	private const MAX_FILTER_TERMS = 20;

	/**
	 * Hooks the ability into the Abilities API.
	 *
	 * The `content` category is registered as a fallback so the ability can be enabled
	 * on its own, without the experiment that registers {@see Content}.
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
	 * Registers the read-only `ai/search-content` ability.
	 *
	 * Must run on the `wp_abilities_api_init` hook.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		/*
		 * Post types must be registered with `show_in_abilities` before the ability is
		 * registered so they are included in its input schema.
		 */
		$post_types = array_keys( $this->get_exposed_post_types() );
		if ( empty( $post_types ) ) {
			return;
		}

		if ( wp_has_ability( self::ABILITY ) ) {
			wp_unregister_ability( self::ABILITY );
		}

		/*
		 * Internal statuses (e.g. `inherit`) are excluded, matching `core/content-query`.
		 */
		$statuses = array_values( get_post_stati( array( 'internal' => false ) ) );

		wp_register_ability(
			self::ABILITY,
			array(
				'label'               => __( 'Search Content', 'ai' ),
				'description'         => sprintf(
					/* translators: %d: the maximum number of search results returned per page. */
					__( 'Searches the post types exposed to abilities for a term appearing in a post title, excerpt, or body, or, without a search term, lists the newest posts. Either way the list can be narrowed to posts with a taxonomy term (a category or tag, by slug or name), published in a date range, or with or without a featured image. Each post comes back with its title, excerpt, terms and whether it has a featured image, never full body content. Results are limited to %d posts per page and are filtered by the current user\'s capabilities, so a post the user cannot read is never returned; "withheld" says how many matches on the returned page that removed, and is 0 when the only reason more posts were not returned is that they are on later pages. It is null rather than a number when one or more post types could not be searched at all under this user\'s role, because a page-level count is then not the whole permission story. Requires an authenticated user.', 'ai' ),
					self::MAX_PER_PAGE
				),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->get_input_schema( $post_types, $statuses, $this->get_filterable_taxonomies() ),
				'output_schema'       => $this->get_output_schema(),
				'execute_callback'    => array( $this, 'execute_search_content' ),
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
	 * Permission callback for the `ai/search-content` ability.
	 *
	 * This gate is coarse by necessity: which rows a search matches is unknown until the
	 * query runs, so it only checks that the caller is authenticated and that at least one
	 * exposed post type may be queried with the requested statuses. Row-level read
	 * permission is enforced in {@see self::execute_search_content()}, which is the check
	 * that keeps results free of posts the caller cannot read.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return bool True if the request may proceed, false otherwise.
	 */
	public function check_permission( $input = array() ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		return array() !== $this->queryable_post_types( rest_sanitize_object( $input ) );
	}

	/**
	 * Executes the `ai/search-content` ability.
	 *
	 * {@see \WP_Ability::execute()} always runs {@see self::check_permission()} first, so
	 * this only needs to enforce what the gate could not: every matched row is checked
	 * against the current user's read permission before it is included, mirroring the
	 * row filtering `core/content-query` performs in query mode.
	 *
	 * Totals come from the underlying query and may therefore exceed the number of
	 * returned rows, matching `core/content-query` and the REST posts controller.
	 *
	 * `total` exceeds the returned rows for two unrelated reasons — the rest of the
	 * matches are on later pages, and the permission walk dropped some of this page —
	 * so the difference between them says nothing about either. `withheld` reports the
	 * second reason on its own: it counts the rows this call built its page from and
	 * then dropped, and pagination never contributes to it. A consumer that wants to
	 * tell a person their role kept something back must read `withheld`; deriving it as
	 * `total - count( results )` would report every unvisited page as hidden by role.
	 *
	 * `withheld` is null, not a number, when {@see self::queryable_post_types()} dropped
	 * a whole post type for permission reasons before the query ran. Rows of that post
	 * type never reach the row filter, so the per-row count is no longer the whole
	 * permission story and reporting it as an integer would state "nothing more was kept
	 * back" on the strength of a walk that never saw those rows. How many rows the
	 * unsearched post types would have matched is not knowable without querying them
	 * under capabilities the caller does not hold, so no count is invented for them.
	 *
	 * `examined` counts the rows this page was built from, before the row filter ran, so
	 * a consumer can say how much was looked at without reading `results` as that number.
	 * It is not a permission figure and never identifies a row.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * Without a search term the newest posts are listed, and the same row filter and
	 * counts apply. A taxonomy filter that names a taxonomy this ability does not list
	 * is refused rather than ignored, so a typo can never widen the list to every post;
	 * one that names terms the taxonomy does not have matches nothing.
	 *
	 * @return array{results: list<array<string, mixed>>, total: int, total_pages: int, examined: int, withheld: int|null}|\WP_Error The bounded, filtered result set, or an error for an unknown taxonomy.
	 */
	public function execute_search_content( $input = array() ) {
		$input  = rest_sanitize_object( $input );
		$search = isset( $input['search'] ) && is_scalar( $input['search'] ) ? trim( (string) $input['search'] ) : '';
		$tax    = $this->resolve_tax_filter( $input );

		if ( is_wp_error( $tax ) ) {
			return $tax;
		}

		/*
		 * Resolved once, because the dropped list is as load bearing as the queryable
		 * one: a post type the status gate removed here is a permission outcome the
		 * per-row count below can never see.
		 */
		$resolved   = $this->resolve_post_types( $input );
		$post_types = $resolved['queryable'];

		/*
		 * A whole post type was excluded for permission reasons, so the page-level
		 * count answers only for the types that were searched. Report no count at all
		 * rather than a number that reads as the whole story.
		 */
		$permission_gap = array() !== $resolved['dropped'];

		$empty = array(
			'results'     => array(),
			'total'       => 0,
			'total_pages' => 0,
			'examined'    => 0,
			'withheld'    => $permission_gap ? null : 0,
		);

		// A filter that names terms the taxonomy does not have matches nothing.
		if ( array() === $post_types || ( null !== $tax && array() === $tax['term_ids'] ) ) {
			return $empty;
		}

		$per_page = $this->normalize_per_page( $input );
		$page     = isset( $input['page'] ) ? max( 1, $this->input_int( $input['page'] ) ) : 1;

		/*
		 * Relevance is left to WP_Query, which orders search results by it and falls
		 * back to post date; without a term there is nothing to rank, so the newest
		 * posts come first. `posts_per_page` is always clamped, so the query is
		 * bounded regardless of the caller's input. The meta and term caches are
		 * primed because every row reports its terms and featured image.
		 */
		$query_args = array(
			'post_type'              => $post_types,
			'post_status'            => $this->normalize_statuses( $input ),
			'posts_per_page'         => $per_page,
			'paged'                  => $page,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => true,
		);

		if ( '' !== $search ) {
			$query_args['s'] = $search;
		}

		if ( '' === $search || 'date' === $this->normalize_orderby( $input ) ) {
			$query_args['orderby'] = 'date';
			$query_args['order']   = 'DESC';
		}

		if ( null !== $tax ) {
			$query_args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- A term filter the caller asked for, on a bounded page.
				array(
					'taxonomy' => $tax['taxonomy'],
					'field'    => 'term_id',
					'terms'    => $tax['term_ids'],
				),
			);
		}

		$date_query = $this->build_date_query( $input );

		if ( array() !== $date_query ) {
			$query_args['date_query'] = array( $date_query );
		}

		if ( isset( $input['has_featured_image'] ) && is_bool( $input['has_featured_image'] ) ) {
			$query_args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- A featured-image filter the caller asked for, on a bounded page.
				array(
					'key'     => '_thumbnail_id',
					'compare' => $input['has_featured_image'] ? 'EXISTS' : 'NOT EXISTS',
				),
			);
		}

		/*
		 * `perm` is only asked for when a single post type is queried. WP_Query resolves
		 * the readable-status capabilities from the queried post type, and for a query
		 * spanning more than one post type it falls back to the placeholder capability
		 * `read_private_multiple_post_types`, which nobody holds — narrowing private
		 * statuses to the caller's own posts even for an administrator. That would hide
		 * readable posts rather than expose unreadable ones, but the row filter below is
		 * the authoritative check either way, so the redundant SQL gate is only added
		 * where core can express it correctly.
		 */
		if ( 1 === count( $post_types ) ) {
			$query_args['perm'] = 'readable';
		}

		$query = new WP_Query( $query_args );
		$total = $this->get_query_total( $query, $query_args, $page );

		$results = array();

		/*
		 * Counted here, one row at a time, rather than derived from the totals: this
		 * loop is the only place that knows which rows the permission walk rejected.
		 * A row that is not a post at all is not a permission outcome and is skipped
		 * without being counted.
		 */
		$withheld = 0;

		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			if ( ! $this->check_read_permission( $post ) ) {
				++$withheld;
				continue;
			}

			$results[] = $this->format_post( $post );
		}

		return array(
			'results'     => $results,
			'total'       => $total,
			'total_pages' => $total > 0 ? (int) ceil( $total / $per_page ) : 0,
			'examined'    => count( $query->posts ),
			'withheld'    => $permission_gap ? null : $withheld,
		);
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
	 * Normalizes the requested order to `relevance` or `date`.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $input The ability input.
	 * @return string The order.
	 */
	private function normalize_orderby( array $input ): string {
		return isset( $input['orderby'] ) && 'date' === $input['orderby'] ? 'date' : 'relevance';
	}

	/**
	 * Builds the date range filter, with both end days included.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $input The ability input.
	 * @return array<string, mixed> The `date_query` clause, or an empty array for no range.
	 */
	private function build_date_query( array $input ): array {
		$clause = array( 'inclusive' => true );

		foreach ( array(
			'date_after'  => 'after',
			'date_before' => 'before',
		) as $key => $bound ) {
			$value = isset( $input[ $key ] ) && is_string( $input[ $key ] ) ? $input[ $key ] : '';

			if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
				continue;
			}

			// The whole of the end day is in range, not just its first second.
			$clause[ $bound ] = 'before' === $bound ? $value . ' 23:59:59' : $value . ' 00:00:00';
		}

		return count( $clause ) > 1 ? $clause : array();
	}

	/**
	 * Resolves the taxonomy filter into a taxonomy and the IDs of the terms it names.
	 *
	 * Terms are matched by slug first and then by name, so "Updates" and "updates"
	 * both find the tag. A taxonomy this ability does not list is refused: a filter
	 * that was dropped would return every post as though it had matched.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $input The ability input.
	 * @return array{taxonomy: string, term_ids: list<int>}|\WP_Error|null The filter, an error, or null when none was given.
	 */
	private function resolve_tax_filter( array $input ) {
		if ( ! isset( $input['tax'] ) || ! is_array( $input['tax'] ) ) {
			return null;
		}

		$taxonomy = isset( $input['tax']['taxonomy'] ) && is_string( $input['tax']['taxonomy'] ) ? sanitize_key( $input['tax']['taxonomy'] ) : '';

		if ( ! in_array( $taxonomy, $this->get_filterable_taxonomies(), true ) ) {
			return new WP_Error(
				'ai_search_content_invalid_taxonomy',
				__( 'That taxonomy cannot be used to filter posts.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		$terms    = isset( $input['tax']['terms'] ) && is_array( $input['tax']['terms'] ) ? array_slice( $input['tax']['terms'], 0, self::MAX_FILTER_TERMS ) : array();
		$term_ids = array();

		foreach ( $terms as $term ) {
			if ( ! is_string( $term ) || '' === trim( $term ) ) {
				continue;
			}

			$found = get_term_by( 'slug', sanitize_title( $term ), $taxonomy );

			if ( ! $found instanceof \WP_Term ) {
				$found = get_term_by( 'name', trim( $term ), $taxonomy );
			}

			if ( ! ( $found instanceof \WP_Term ) ) {
				continue;
			}

			$term_ids[] = (int) $found->term_id;
		}

		return array(
			'taxonomy' => $taxonomy,
			'term_ids' => array_values( array_unique( $term_ids ) ),
		);
	}

	/**
	 * Returns the taxonomies posts may be filtered and labelled by.
	 *
	 * Those that are publicly viewable and attached to a post type exposed to
	 * abilities, so the filter and each row's terms say nothing a visitor to the
	 * site could not already see.
	 *
	 * @since x.x.x
	 *
	 * @return list<string> The taxonomy names.
	 */
	private function get_filterable_taxonomies(): array {
		$taxonomies = array();

		foreach ( array_keys( $this->get_exposed_post_types() ) as $post_type ) {
			foreach ( get_object_taxonomies( $post_type, 'objects' ) as $taxonomy ) {
				if ( ! ( $taxonomy instanceof WP_Taxonomy ) || ! is_taxonomy_viewable( $taxonomy ) ) {
					continue;
				}

				$taxonomies[ $taxonomy->name ] = true;
			}
		}

		return array_keys( $taxonomies );
	}

	/**
	 * Parses a raw list input into a list of strings.
	 *
	 * A GET request delivers list inputs as scalar/CSV strings; this parses them the
	 * same way schema validation did (wp_parse_list) so they are honored regardless of
	 * transport, mirroring {@see Content}.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $input The ability input.
	 * @param string       $key   The input key holding the list.
	 * @return list<string> The parsed string values; empty when absent or unparseable.
	 */
	private function parse_list_input( array $input, string $key ): array {
		$value = $input[ $key ] ?? null;
		if ( ! is_array( $value ) && ! is_string( $value ) ) {
			return array();
		}

		return array_values( array_filter( wp_parse_list( $value ), 'is_string' ) );
	}

	/**
	 * Resolves the post types the current user may search with the requested statuses.
	 *
	 * A request naming no post type searches every exposed post type, so the status gate
	 * is applied per post type and the types that fail it are dropped rather than failing
	 * the whole request: a contributor searching drafts can still search their own posts
	 * even though they cannot query draft pages. A post type named explicitly by the
	 * caller but not exposed to abilities fails the request outright, so an unexposed post
	 * type is never silently searched or silently skipped.
	 *
	 * This is both the permission gate and the query's post type list, so the two cannot
	 * disagree. It is a coarse gate only: each matched row is still checked individually
	 * by {@see self::check_read_permission()}.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $input The ability input.
	 * @return list<string> The searchable post type names; empty when the request is not permitted.
	 */
	private function queryable_post_types( array $input ): array {
		return $this->resolve_post_types( $input )['queryable'];
	}

	/**
	 * Resolves the requested post types into the ones that may be searched and the ones dropped.
	 *
	 * The dropped list exists because dropping a post type here is a permission outcome
	 * that the execute-time row filter can never observe: those rows are excluded from
	 * the SQL, so they are never counted as withheld. A caller that reports a permission
	 * figure has to know this list was non-empty, or it will report the page's own count
	 * as though it were the whole story. See {@see self::execute_search_content()}.
	 *
	 * A post type named explicitly but not exposed to abilities fails the request
	 * outright and is not a drop: both lists come back empty, so nothing is reported as
	 * withheld for a request that was refused rather than narrowed.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $input The ability input.
	 * @return array{queryable: list<string>, dropped: list<string>} The searchable post types and the ones the status gate removed.
	 */
	private function resolve_post_types( array $input ): array {
		$exposed   = $this->get_exposed_post_types();
		$requested = array_map( 'sanitize_key', $this->parse_list_input( $input, 'post_type' ) );

		if ( array() === $requested ) {
			$requested = array_keys( $exposed );
		}

		$queryable = array();
		$dropped   = array();

		foreach ( $requested as $post_type ) {
			if ( ! isset( $exposed[ $post_type ] ) ) {
				return array(
					'queryable' => array(),
					'dropped'   => array(),
				);
			}

			if ( ! $this->can_query_statuses( $input, $exposed[ $post_type ] ) ) {
				$dropped[] = $post_type;
				continue;
			}

			$queryable[] = $post_type;
		}

		return array(
			'queryable' => $queryable,
			'dropped'   => $dropped,
		);
	}

	/**
	 * Normalizes the requested statuses to a non-empty, sanitized list defaulting to publish.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $input The ability input.
	 * @return list<string> Normalized list of post status slugs.
	 */
	private function normalize_statuses( array $input ): array {
		$statuses = $this->parse_list_input( $input, 'status' );

		return array() === $statuses ? array( 'publish' ) : array_map( 'sanitize_key', $statuses );
	}

	/**
	 * Normalizes the requested per-page value to the supported bounds.
	 *
	 * Mirrors `normalize_per_page()` in the users ability, with a lower ceiling: search
	 * results are model context, so the cap is {@see self::MAX_PER_PAGE}. Clamping here
	 * as well as in the input schema keeps the bound in force on transports that do not
	 * validate input against the schema.
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
	 * Returns the query total, recovering it when WP_Query skipped the count.
	 *
	 * WP_Query leaves `found_posts` at 0 when a requested page has no rows. Re-run a
	 * minimal unpaged query so a caller who paged past the end still learns how many
	 * posts matched, mirroring {@see Content}.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Query    $query      The executed query.
	 * @param array<mixed> $query_args The arguments used for the executed query.
	 * @param int          $page       The requested page.
	 * @return int Total matching rows across all pages.
	 */
	private function get_query_total( WP_Query $query, array $query_args, int $page ): int {
		$total = (int) $query->found_posts;

		if ( $total > 0 || $page <= 1 ) {
			return $total;
		}

		$count_args                   = $query_args;
		$count_args['fields']         = 'ids';
		$count_args['posts_per_page'] = 1;
		unset( $count_args['paged'] );

		$count_query = new WP_Query( $count_args );

		return (int) $count_query->found_posts;
	}

	/**
	 * Returns the post types exposed through the Abilities API, keyed by name.
	 *
	 * Deliberately resolved on every call rather than cached: post types can be
	 * unregistered or re-registered with different arguments between the ability
	 * being registered and the ability being used.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, \WP_Post_Type> Exposed post type objects keyed by name.
	 */
	private function get_exposed_post_types(): array {
		$exposed_post_types = array();

		foreach ( get_post_types( array( 'show_in_abilities' => true ), 'objects' ) as $post_type_object ) {
			$exposed_post_types[ $post_type_object->name ] = $post_type_object;
		}

		return $exposed_post_types;
	}

	/**
	 * Resolves a capability name from a post type's capability map.
	 *
	 * The capability map is a plain object with untyped properties, so guard the
	 * lookup and fail closed with `do_not_allow` when the name cannot be resolved.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post_Type $post_type_object The post type object.
	 * @param string        $capability       The capability key, e.g. 'edit_posts'.
	 * @return string The resolved capability name, or 'do_not_allow' when unresolved.
	 */
	private function post_type_cap( WP_Post_Type $post_type_object, string $capability ): string {
		$cap = $post_type_object->cap->$capability ?? null;

		return is_string( $cap ) && '' !== $cap ? $cap : 'do_not_allow';
	}

	/**
	 * Checks whether the current user may query the requested statuses.
	 *
	 * Mirrors `can_query_statuses()` in {@see Content}, which in turn mirrors the REST
	 * posts controller's conservative collection-status gate: requesting non-default
	 * statuses requires edit access, except `private`, which may be queried by users who
	 * can read private posts. Passing this gate does not make a row readable; every row
	 * is still checked individually at execute time.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed>  $input            The ability input.
	 * @param \WP_Post_Type $post_type_object The post type object.
	 * @return bool True if the requested statuses may be queried.
	 */
	private function can_query_statuses( array $input, WP_Post_Type $post_type_object ): bool {
		foreach ( $this->normalize_statuses( $input ) as $status ) {
			if ( 'publish' === $status ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is resolved from the post type's capability object.
			if ( 'private' === $status && current_user_can( $this->post_type_cap( $post_type_object, 'read_private_posts' ) ) ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is resolved from the post type's capability object.
			if ( current_user_can( $this->post_type_cap( $post_type_object, 'edit_posts' ) ) ) {
				continue;
			}

			return false;
		}

		return true;
	}

	/**
	 * Checks if a post can be read by the current user.
	 *
	 * Mirrors `check_read_permission()` in {@see Content} — itself a mirror of the REST
	 * posts controller's read permission — including the inherited-parent walk, so the
	 * two abilities cannot disagree about what a user may see. This is the row filter
	 * that keeps a search result set free of posts the caller cannot read.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post         $post             Post object.
	 * @param array<int, true> $checked_post_ids Post IDs already checked while walking inherited parents.
	 * @return bool Whether the post can be read.
	 */
	private function check_read_permission( WP_Post $post, array $checked_post_ids = array() ): bool {
		if ( isset( $checked_post_ids[ $post->ID ] ) ) {
			return false;
		}

		$checked_post_ids[ $post->ID ] = true;

		$post_type = get_post_type_object( $post->post_type );
		if ( ! $post_type instanceof WP_Post_Type || empty( $post_type->show_in_abilities ) ) {
			return false;
		}

		/*
		 * Treat publicly viewable posts as readable. This checks both the post type
		 * and post status using Core's viewability helpers, which is stricter than
		 * checking the status object's `public` flag alone.
		 */
		if ( is_post_publicly_viewable( $post ) ) {
			return true;
		}

		/*
		 * Use the normalized status for the status object lookup. For attachments,
		 * get_post_status() resolves `inherit` through the parent before returning.
		 */
		$post_status = get_post_status( $post );
		if ( ! is_string( $post_status ) ) {
			return false;
		}

		$post_status_object = get_post_status_object( $post_status );
		if ( ! $post_status_object instanceof \stdClass ) {
			return false;
		}

		/*
		 * Core maps `read_post` for public statuses to the post type's plain `read`
		 * capability. Publicly viewable posts already returned above, so a remaining
		 * public status is public but not viewable and should require edit access.
		 */
		if ( $post_status_object->public ) {
			return current_user_can( 'edit_post', $post->ID );
		}

		/*
		 * For non-public statuses, defer to Core's meta-capability mapping. This
		 * handles own drafts, private posts, and statuses that require edit access.
		 */
		if ( current_user_can( 'read_post', $post->ID ) ) {
			return true;
		}

		/*
		 * Mirror the REST posts controller's inherited-parent behavior, but keep the
		 * ability fail-closed for missing parents or parent loops.
		 */
		if (
			'inherit' === $post->post_status &&
			$post->post_parent > 0 &&
			(int) $post->post_parent !== (int) $post->ID
		) {
			$parent = get_post( $post->post_parent );
			if ( $parent instanceof WP_Post ) {
				return $this->check_read_permission( $parent, $checked_post_ids );
			}
		}

		return false;
	}

	/**
	 * Formats a readable post into a search result.
	 *
	 * The excerpt of a password-protected post is withheld unless the current user can
	 * edit that post, mirroring the REST posts controller. For such an editor the
	 * cookie-based password gate is suspended only while this post's excerpt is built,
	 * so other protected posts a filter may render stay locked.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post $post The post object.
	 * @return array<string, mixed> The formatted search result.
	 */
	private function format_post( WP_Post $post ): array {
		$can_edit          = current_user_can( 'edit_post', $post->ID );
		$password_required = post_password_required( $post );

		if ( $password_required && $can_edit ) {
			add_filter( 'post_password_required', array( $this, 'allow_password_content' ), 10, 2 );

			try {
				return $this->build_result( $post, false );
			} finally {
				remove_filter( 'post_password_required', array( $this, 'allow_password_content' ), 10 );
			}
		}

		return $this->build_result( $post, $password_required );
	}

	/**
	 * Builds the result row for a post.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post $post         The post object.
	 * @param bool     $is_protected Whether the excerpt must be withheld as password-protected.
	 * @return array<string, mixed> The formatted search result.
	 */
	private function build_result( WP_Post $post, bool $is_protected ): array {
		return array(
			'id'                 => (int) $post->ID,
			'post_type'          => $post->post_type,
			'status'             => $post->post_status,
			'date'               => $this->format_date( $post ),
			'slug'               => $post->post_name,
			'link'               => (string) get_permalink( $post ),
			'title'              => post_type_supports( $post->post_type, 'title' ) ? $this->get_title( $post ) : '',
			'excerpt'            => $is_protected ? '' : $this->get_excerpt( $post ),
			'edit_link'          => $this->get_edit_link( $post ),
			'terms'              => $this->get_terms( $post ),
			'has_featured_image' => has_post_thumbnail( $post ),
		);
	}

	/**
	 * Returns the names of a post's terms, keyed by taxonomy.
	 *
	 * Only taxonomies posts may be filtered by are reported, so a term from a
	 * private taxonomy never reaches the result. An empty object, not an empty
	 * list, when the post has none.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post $post The post object.
	 * @return array<string, list<string>>|\stdClass The term names by taxonomy.
	 */
	private function get_terms( WP_Post $post ) {
		$terms      = array();
		$filterable = $this->get_filterable_taxonomies();

		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			if ( ! in_array( $taxonomy, $filterable, true ) ) {
				continue;
			}

			$post_terms = get_the_terms( $post, $taxonomy );

			if ( ! is_array( $post_terms ) ) {
				continue;
			}

			$terms[ $taxonomy ] = array_values( wp_list_pluck( $post_terms, 'name' ) );
		}

		return array() === $terms ? new \stdClass() : $terms;
	}

	/**
	 * Returns the editor URL for a post the current user can edit.
	 *
	 * The URL doubles as the permission proof: it is present only when the current user
	 * can edit the post, so a consumer offering an edit affordance does not have to
	 * re-derive the capability, and cannot construct an editor URL for a post the user
	 * may only read.
	 *
	 * The explicit capability check is deliberate redundancy. {@see get_edit_post_link()}
	 * already returns nothing for a user who cannot edit, so this gate changes no
	 * behaviour today -- verified by mutation, where removing it left the tests green.
	 * It is kept because the emptiness of this field is a permission guarantee consumers
	 * rely on, and that guarantee should not rest on the internals of a core function
	 * that is free to change.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post $post The post being formatted.
	 * @return string The editor URL, or an empty string when the user cannot edit the post.
	 */
	private function get_edit_link( WP_Post $post ): string {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return '';
		}

		return (string) get_edit_post_link( $post, 'raw' );
	}

	/**
	 * Filters {@see post_password_required()} to unlock only posts the current user can edit.
	 *
	 * Added by {@see self::format_post()} while formatting a password-protected post the
	 * current user can edit, so the excerpt resolves to its real value without also
	 * unlocking other protected posts an excerpt filter may render.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $required Whether the post currently requires a password.
	 * @param mixed $post     The post being checked; a WP_Post when invoked by the core filter.
	 * @return bool Whether the post still requires a password.
	 */
	public function allow_password_content( $required, $post ): bool {
		if ( ! $required || ! $post instanceof WP_Post ) {
			return (bool) $required;
		}

		return ! current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * Returns the post title with the protected/private prefixes stripped.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post $post The post object.
	 * @return string The post title.
	 */
	private function get_title( WP_Post $post ): string {
		$strip = array( $this, 'return_raw_title_format' );
		add_filter( 'protected_title_format', $strip );
		add_filter( 'private_title_format', $strip );

		/*
		 * The format filters are removed in a finally block so a throw from a title
		 * filter cannot leave them attached for the rest of the request.
		 */
		try {
			return get_the_title( $post );
		} finally {
			remove_filter( 'protected_title_format', $strip );
			remove_filter( 'private_title_format', $strip );
		}
	}

	/**
	 * Returns the raw title format, used to strip protected/private title prefixes.
	 *
	 * @since x.x.x
	 *
	 * @return string The unprefixed title format.
	 */
	public function return_raw_title_format(): string {
		return '%s';
	}

	/**
	 * Returns the post excerpt as plain text.
	 *
	 * Applies the `get_the_excerpt` filter chain, which generates a trimmed excerpt from
	 * the post content when the post has no explicit excerpt, then strips markup so the
	 * result is usable as model context. Post globals are prepared and restored around
	 * the filters so filters relying on loop globals render against the requested post,
	 * mirroring the REST posts controller and {@see Content}.
	 *
	 * The `the_excerpt` filter is deliberately not applied: it only adds display markup
	 * that is stripped again here.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post $post The post object.
	 * @return string The plain-text post excerpt.
	 */
	private function get_excerpt( WP_Post $post ): string {
		if ( ! post_type_supports( $post->post_type, 'excerpt' ) && ! post_type_supports( $post->post_type, 'editor' ) ) {
			return '';
		}

		$previous_post = $GLOBALS['post'] ?? null;

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Temporarily mirrors REST post context for excerpt rendering.
		$GLOBALS['post'] = $post;
		setup_postdata( $post );

		/*
		 * The global post context is restored in a finally block so a throw from an
		 * excerpt filter cannot leave it pointing at this post for the rest of the
		 * request.
		 */
		try {
			/** This filter is documented in wp-includes/post-template.php. */
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Applying the core excerpt filter to mirror REST rendering.
			$excerpt = apply_filters( 'get_the_excerpt', $post->post_excerpt, $post );

			if ( ! is_string( $excerpt ) ) {
				return '';
			}

			return trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $excerpt ) ) );
		} finally {
			if ( $previous_post instanceof WP_Post ) {
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the previous global post context.
				$GLOBALS['post'] = $previous_post;
				setup_postdata( $previous_post );
			} else {
				unset( $GLOBALS['post'] );
				wp_reset_postdata();
			}
		}
	}

	/**
	 * Formats the post publication date as an ISO 8601 string in the site's timezone.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post $post The post object.
	 * @return string The ISO 8601 date, or an empty string if unavailable.
	 */
	private function format_date( WP_Post $post ): string {
		$datetime = get_post_datetime( $post, 'date', 'local' );

		return $datetime ? $datetime->format( 'c' ) : '';
	}

	/**
	 * Builds the input schema for the `ai/search-content` ability.
	 *
	 * @since x.x.x
	 *
	 * @param list<string> $post_types Exposed post type names.
	 * @param list<string> $statuses   Requestable post status slugs.
	 * @param list<string> $taxonomies Taxonomies posts may be filtered by.
	 * @return array<string, mixed> The input JSON Schema.
	 */
	private function get_input_schema( array $post_types, array $statuses, array $taxonomies ): array {
		$date = array(
			'type'    => 'string',
			'pattern' => '^\\d{4}-\\d{2}-\\d{2}$',
		);

		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'search'             => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => __( 'The term to search for in post titles, excerpts, and body content. Leave it out to list the newest posts.', 'ai' ),
				),
				'orderby'            => array(
					'type'        => 'string',
					'enum'        => array( 'relevance', 'date' ),
					'description' => __( 'How to order the posts: by relevance to the search term, or newest first. Defaults to relevance with a search term, and to date without one.', 'ai' ),
				),
				'tax'                => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'taxonomy', 'terms' ),
					'description'          => __( 'Only posts with at least one of these terms, such as a category or tag.', 'ai' ),
					'properties'           => array(
						'taxonomy' => array(
							'type' => 'string',
							'enum' => $taxonomies,
						),
						'terms'    => array(
							'type'        => 'array',
							'minItems'    => 1,
							'maxItems'    => self::MAX_FILTER_TERMS,
							'items'       => array(
								'type'      => 'string',
								'minLength' => 1,
							),
							'description' => __( 'Term slugs or names.', 'ai' ),
						),
					),
				),
				'date_after'         => $date + array(
					'description' => __( 'Only posts published on or after this day, as YYYY-MM-DD.', 'ai' ),
				),
				'date_before'        => $date + array(
					'description' => __( 'Only posts published on or before this day, as YYYY-MM-DD.', 'ai' ),
				),
				'has_featured_image' => array(
					'type'        => 'boolean',
					'description' => __( 'Only posts with a featured image (true) or without one (false).', 'ai' ),
				),
				'post_type'          => array(
					'type'        => 'array',
					'uniqueItems' => true,
					'items'       => array(
						'type' => 'string',
						'enum' => $post_types,
					),
					'description' => __( 'Limit the search to these post types. Defaults to every post type exposed to abilities.', 'ai' ),
				),
				'status'             => array(
					'type'        => 'array',
					'uniqueItems' => true,
					'items'       => array(
						'type' => 'string',
						'enum' => $statuses,
					),
					'description' => __( 'Limit the search to posts with one or more of these statuses. Defaults to publish. Non-published statuses require the appropriate capabilities.', 'ai' ),
				),
				'page'               => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Page of results to return. Check `total_pages` before requesting later pages; a page beyond the last one returns no results.', 'ai' ),
				),
				'per_page'           => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => self::MAX_PER_PAGE,
					'description' => __( 'Maximum number of results to return per page.', 'ai' ),
				),
			),
		);
	}

	/**
	 * Builds the output schema for the `ai/search-content` ability.
	 *
	 * Result rows carry identifying metadata plus a title and an excerpt. Full body
	 * content is intentionally absent: it belongs to {@see Read_Content_Bodies}.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The output JSON Schema.
	 */
	private function get_output_schema(): array {
		$result_schema = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'id', 'post_type', 'status', 'date', 'slug', 'link', 'title', 'excerpt', 'edit_link', 'terms', 'has_featured_image' ),
			'properties'           => array(
				'id'                 => array(
					'type'        => 'integer',
					'description' => __( 'The post ID.', 'ai' ),
				),
				'post_type'          => array(
					'type'        => 'string',
					'description' => __( 'The post type.', 'ai' ),
				),
				'status'             => array(
					'type'        => 'string',
					'description' => __( 'The post status.', 'ai' ),
				),
				'date'               => array(
					'type'        => 'string',
					'description' => __( "The publication date, in ISO 8601 format using the site's timezone. Empty string when the date cannot be resolved.", 'ai' ),
				),
				'slug'               => array(
					'type'        => 'string',
					'description' => __( 'The post slug.', 'ai' ),
				),
				'link'               => array(
					'type'        => 'string',
					'description' => __( 'The permalink URL.', 'ai' ),
				),
				'title'              => array(
					'type'        => 'string',
					'description' => __( 'The rendered post title. Empty when the post type does not support titles.', 'ai' ),
				),
				'excerpt'            => array(
					'type'        => 'string',
					'description' => __( 'The post excerpt as plain text, generated from the post content when the post has none. Empty when withheld for a password-protected post.', 'ai' ),
				),
				'edit_link'          => array(
					'type'        => 'string',
					'description' => __( 'The editor URL for this post, present only when the current user can edit it. Empty string otherwise.', 'ai' ),
				),
				'terms'              => array(
					'type'                 => 'object',
					'additionalProperties' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'description'          => __( 'The names of the post\'s terms, keyed by taxonomy, such as category and post_tag. Only public taxonomies are listed.', 'ai' ),
				),
				'has_featured_image' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the post has a featured image.', 'ai' ),
				),
			),
		);

		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'results', 'total', 'total_pages', 'examined', 'withheld' ),
			'properties'           => array(
				'results'     => array(
					'type'        => 'array',
					'description' => __( 'The readable posts matching the search.', 'ai' ),
					'items'       => $result_schema,
				),
				'total'       => array(
					'type'        => 'integer',
					'description' => __( 'Total number of posts matching the underlying search, across all pages. It exceeds the number of returned posts both when later pages hold more matches and when row-level permission checks withheld some of this page, so the difference between the two is not a count of anything. Read "withheld" for the permission outcome.', 'ai' ),
				),
				'total_pages' => array(
					'type'        => 'integer',
					'description' => __( 'Total number of result pages available for the underlying search.', 'ai' ),
				),
				'examined'    => array(
					'type'        => 'integer',
					'description' => __( 'How many posts this page was built from before the per-post permission check ran. It is never smaller than the number of returned posts, and the difference between the two is exactly "withheld" whenever "withheld" is a number. It is not a permission figure on its own and it never identifies a post.', 'ai' ),
				),
				'withheld'    => array(
					'type'        => array( 'integer', 'null' ),
					'description' => __( 'How many of the posts on this page were dropped because the current user may not read them. It counts only this page, and only permission: posts left unreturned by pagination are not counted, so this is 0 on an ordinary search that simply has more pages. It is null, which is not the same as 0, when a whole post type could not be searched under the current user\'s role: rows of that type never reached the per-post check, so no page-level number can stand for the permission outcome, and none is invented. It is a count and nothing more; it never identifies what was withheld.', 'ai' ),
				),
			),
		);
	}
}
