<?php
/**
 * Filters shared by the content listing abilities.
 *
 * @package WordPress\AI
 *
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Content;

use WP_Error;
use WP_Post;
use WP_Taxonomy;
use WP_Term;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Search_Filters
 *
 * Turns the term, date and featured-image filters of {@see Search_Content} into
 * query clauses, and decides which taxonomies posts may be filtered and labelled
 * by, a set {@see List_Terms} lists too.
 *
 * One rule runs through all of it: a filter never widens the list. A filter that
 * names an unknown taxonomy or an impossible date is an error rather than ignored,
 * and one that names terms the taxonomy does not have matches nothing. Inputs are
 * read the way a GET request delivers them, as strings, as well as typed.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since x.x.x
 */
final class Search_Filters {

	/**
	 * Most terms a single taxonomy filter may name.
	 *
	 * @since x.x.x
	 * @var int
	 */
	public const MAX_TERMS = 20;

	/**
	 * Returns the taxonomies posts may be filtered and labelled by.
	 *
	 * Those that are publicly viewable and attached to a post type exposed to
	 * abilities, so a filter, a row's terms and a term listing say nothing a visitor
	 * to the site could not already see.
	 *
	 * @since x.x.x
	 *
	 * @return list<string> The taxonomy names.
	 */
	public static function viewable_taxonomies(): array {
		$taxonomies = array();

		foreach ( get_post_types( array( 'show_in_abilities' => true ) ) as $post_type ) {
			foreach ( get_object_taxonomies( $post_type, 'objects' ) as $taxonomy ) {
				if ( ! $taxonomy instanceof WP_Taxonomy || ! is_taxonomy_viewable( $taxonomy ) ) {
					continue;
				}

				$taxonomies[ $taxonomy->name ] = true;
			}
		}

		return array_keys( $taxonomies );
	}

	/**
	 * Resolves the taxonomy filter into a taxonomy and the IDs of the terms it names.
	 *
	 * Terms are matched by slug first and then by name, so "Updates" and "updates"
	 * both find the tag. Terms may arrive as a list or, from a GET request, as a
	 * comma-separated string.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $input      The ability input.
	 * @param list<string> $taxonomies The taxonomies that may be filtered by.
	 * @return array{taxonomy: string, term_ids: list<int>}|\WP_Error|null The filter, an error, or null when none was given.
	 */
	public static function resolve_tax( array $input, array $taxonomies ) {
		if ( ! isset( $input['tax'] ) ) {
			return null;
		}

		$tax      = $input['tax'];
		$taxonomy = is_array( $tax ) && isset( $tax['taxonomy'] ) && is_string( $tax['taxonomy'] ) ? sanitize_key( $tax['taxonomy'] ) : '';

		if ( ! in_array( $taxonomy, $taxonomies, true ) ) {
			return new WP_Error(
				'ai_search_content_invalid_taxonomy',
				__( 'That taxonomy cannot be used to filter posts.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		$terms = is_array( $tax ) && isset( $tax['terms'] ) && ( is_array( $tax['terms'] ) || is_string( $tax['terms'] ) )
			? array_slice( wp_parse_list( $tax['terms'] ), 0, self::MAX_TERMS )
			: array();

		$term_ids = array();

		foreach ( $terms as $term ) {
			if ( ! is_string( $term ) || '' === trim( $term ) ) {
				continue;
			}

			$found = get_term_by( 'slug', sanitize_title( $term ), $taxonomy );

			if ( ! $found instanceof WP_Term ) {
				$found = get_term_by( 'name', trim( $term ), $taxonomy );
			}

			if ( ! $found instanceof WP_Term ) {
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
	 * Builds the date range clause, with both end days included.
	 *
	 * A bound that is not a real calendar day is refused: WP_Date_Query would read
	 * it as 1 January 1970, which quietly drops an "after" bound and empties a
	 * "before" one.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $input The ability input.
	 * @return array<string, mixed>|\WP_Error The `date_query` clause, an empty array for no range, or an error.
	 */
	public static function date_query( array $input ) {
		$clause = array( 'inclusive' => true );

		foreach ( array(
			'date_after'  => 'after',
			'date_before' => 'before',
		) as $key => $bound ) {
			if ( ! isset( $input[ $key ] ) ) {
				continue;
			}

			$value = is_string( $input[ $key ] ) ? $input[ $key ] : '';
			$parts = 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches ) ? $matches : array();

			if ( array() === $parts || ! checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ) {
				return new WP_Error(
					'ai_search_content_invalid_date',
					__( 'A date must be a real day, written as YYYY-MM-DD.', 'ai' ),
					array( 'status' => 400 )
				);
			}

			// The whole of the end day is in range, not just its first second.
			$clause[ $bound ] = 'before' === $bound ? $value . ' 23:59:59' : $value . ' 00:00:00';
		}

		return count( $clause ) > 1 ? $clause : array();
	}

	/**
	 * Builds the featured-image clause.
	 *
	 * A post has a featured image when its `_thumbnail_id` is a positive number,
	 * the same test {@see has_post_thumbnail()} applies to the row, so the filter
	 * and the row never disagree about a post whose stored value is 0 or empty.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $input The ability input.
	 * @return array<int|string, mixed>|null The `meta_query` clause, or null when no filter was given.
	 */
	public static function featured_image_query( array $input ): ?array {
		$wanted = self::read_flag( $input['has_featured_image'] ?? null );

		if ( null === $wanted ) {
			return null;
		}

		$has = array(
			'key'     => '_thumbnail_id',
			'value'   => 0,
			'compare' => '>',
			'type'    => 'NUMERIC',
		);

		if ( $wanted ) {
			return array( $has );
		}

		return array(
			'relation' => 'OR',
			array(
				'key'     => '_thumbnail_id',
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => '_thumbnail_id',
				'value'   => 0,
				'compare' => '<=',
				'type'    => 'NUMERIC',
			),
		);
	}

	/**
	 * Reads a boolean input, typed or as a GET request delivers it.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $value The raw value.
	 * @return bool|null The flag, or null when the value is absent or not boolean-like.
	 */
	public static function read_flag( $value ): ?bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_int( $value ) ) {
			$value = (string) $value;
		}

		if ( is_string( $value ) && rest_is_boolean( $value ) ) {
			return rest_sanitize_boolean( $value );
		}

		return null;
	}

	/**
	 * Returns the names of a post's terms, keyed by taxonomy.
	 *
	 * Only the given taxonomies are reported, so a term from a private taxonomy
	 * never reaches the result. An empty object, not an empty list, when the post
	 * has none, so it still encodes as a JSON object.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post     $post       The post object.
	 * @param list<string> $taxonomies The taxonomies that may be reported.
	 * @return array<string, list<string>>|\stdClass The term names by taxonomy.
	 */
	public static function post_terms( WP_Post $post, array $taxonomies ) {
		$terms = array();

		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			if ( ! in_array( $taxonomy, $taxonomies, true ) ) {
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
}
