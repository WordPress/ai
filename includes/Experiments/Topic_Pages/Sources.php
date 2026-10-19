<?php
/**
 * Public source selection for topic pages.
 *
 * @package WordPress\AI
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Topic_Pages;

use WP_Error;
use WP_Post;
use WP_Query;
use WP_Term;

/**
 * Validates terms and selects the site's own public coverage.
 *
 * @since x.x.x
 */
final class Sources {
	/**
	 * Returns eligible taxonomy names.
	 *
	 * @since x.x.x
	 *
	 * @return string[] Eligible taxonomy names.
	 */
	public static function taxonomies(): array {
		return array_values( array_filter( (array) apply_filters( 'wpai_topic_pages_taxonomies', array( 'category', 'post_tag' ) ), 'taxonomy_exists' ) );
	}

	/**
	 * Returns eligible public post types.
	 *
	 * @since x.x.x
	 *
	 * @return string[] Eligible post types.
	 */
	public static function post_types(): array {
		$types = (array) apply_filters( 'wpai_topic_pages_post_types', array( 'post', 'page' ) );
		return array_values(
			array_filter(
				$types,
				static function ( $type ): bool {
					return is_string( $type ) && post_type_exists( $type ) && is_post_type_viewable( $type );
				}
			)
		);
	}

	/**
	 * Minimum public coverage needed to display a topic.
	 *
	 * @since x.x.x
	 */
	public static function minimum(): int {
		return max( 1, (int) apply_filters( 'wpai_topic_pages_minimum_posts', 5 ) );
	}

	/**
	 * Returns an eligible public topic.
	 *
	 * @since x.x.x
	 *
	 * @param int $term_id Topic term ID.
	 * @return \WP_Term|\WP_Error A public, eligible taxonomy term.
	 */
	public function term( int $term_id ) {
		$term = get_term( $term_id );
		if ( ! $term instanceof WP_Term || ! in_array( $term->taxonomy, self::taxonomies(), true ) || ! is_taxonomy_viewable( $term->taxonomy ) ) {
			return new WP_Error( 'topic_invalid_term', __( 'Choose a term from a public, eligible taxonomy.', 'ai' ), array( 'status' => 400 ) );
		}
		return $term;
	}

	/**
	 * Query direct term membership; children are separate topics.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Term $term Eligible topic.
	 * @param int $limit Maximum sources to query.
	 * @return \WP_Query Source query.
	 */
	public function query( WP_Term $term, int $limit ): WP_Query {
		$post_types = self::post_types();
		if ( empty( $post_types ) ) {
			return new WP_Query( array( 'post__in' => array( 0 ) ) );
		}

		return new WP_Query(
			array(
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'has_password'   => false,
				'posts_per_page' => $limit,
				'orderby'        => array(
					'date' => 'ASC',
					'ID'   => 'ASC',
				),
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Public topic membership requires a taxonomy query.
					array(
						'taxonomy'         => $term->taxonomy,
						'terms'            => $term->term_id,
						'include_children' => false,
					),
				),
			)
		);
	}

	/**
	 * Check a source again before approval or public output.
	 *
	 * @since x.x.x
	 *
	 * @param int $post_id Source post ID.
	 * @param \WP_Term $term Topic term.
	 * @return bool Whether the source is publicly readable and belongs to the topic.
	 */
	public function is_public_source( int $post_id, WP_Term $term ): bool {
		$post = get_post( $post_id );
		return $post instanceof WP_Post && 'publish' === $post->post_status && '' === $post->post_password
			&& is_post_publicly_viewable( $post ) && in_array( $post->post_type, self::post_types(), true )
			&& has_term( $term->term_id, $term->taxonomy, $post );
	}

	/**
	 * Collect full source text for the topic.
	 *
	 * @since x.x.x
	 *
	 * @param int $term_id Topic term ID.
	 * @return array<int, array{id:int,title:string,date:string,content:string}>|\WP_Error
	 */
	public function collect( int $term_id ) {
		$term = $this->term( $term_id );
		if ( is_wp_error( $term ) ) {
			return $term;
		}
		$query = $this->query( $term, 21 );
		if ( $query->found_posts < self::minimum() ) {
			return new WP_Error( 'topic_below_minimum', __( 'This topic does not yet have enough public content.', 'ai' ) );
		}
		if ( $query->found_posts > 20 ) {
			return new WP_Error( 'topic_too_large', __( 'Topic Pages supports up to 20 source items.', 'ai' ) );
		}
		$sources = array();
		$length  = 0;
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof WP_Post || ! $this->is_public_source( $post->ID, $term ) ) {
				continue;
			}
			$content   = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
			$length   += strlen( $content );
			$sources[] = array(
				'id'      => $post->ID,
				'title'   => $post->post_title,
				'date'    => $post->post_date,
				'content' => $content,
			);
		}
		if ( count( $sources ) < self::minimum() ) {
			return new WP_Error( 'topic_below_minimum', __( 'This topic does not yet have enough public content.', 'ai' ) );
		}
		if ( $length > 60000 ) {
			return new WP_Error( 'topic_too_large', __( 'The source text exceeds the 60 KB limit. No content was sent to the provider.', 'ai' ) );
		}
		return $sources;
	}
}
