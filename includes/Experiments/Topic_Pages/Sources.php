<?php
/**
 * Bounded public source selection for Topic Pages.
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
 * Selects public category coverage without loading the whole archive.
 *
 * @since x.x.x
 */
final class Sources {

	/**
	 * @return string[] Eligible taxonomies.
	 */
	public static function taxonomies(): array {
		/**
		 * Filters the public taxonomies available to Topic Pages.
		 *
		 * @since x.x.x
		 *
		 * @param string[] $taxonomies Taxonomy names. Defaults to categories.
		 */
		$taxonomies = apply_filters( 'wpai_topic_pages_taxonomies', array( 'category' ) );

		return array_values( array_filter( (array) $taxonomies, 'taxonomy_exists' ) );
	}

	/**
	 * @return string[] Public post types.
	 */
	public static function post_types(): array {
		/**
		 * Filters the post types used as Topic Page sources.
		 *
		 * @since x.x.x
		 *
		 * @param string[] $post_types Post type names. Nonpublic types are excluded.
		 */
		$post_types = apply_filters( 'wpai_topic_pages_post_types', array( 'post' ) );

		return array_values(
			array_filter(
				(array) $post_types,
				static function ( $type ): bool {
					return is_string( $type ) && post_type_exists( $type ) && is_post_type_viewable( $type );
				}
			)
		);
	}

	/**
	 * Minimum number of public sources.
	 */
	public static function minimum(): int {
		/**
		 * Filters the minimum number of public posts needed for a Topic Page.
		 *
		 * @since x.x.x
		 *
		 * @param int $minimum Minimum public post count. Defaults to five.
		 */
		$minimum = apply_filters( 'wpai_topic_pages_minimum_posts', 5 );

		return max( 1, (int) $minimum );
	}

	/**
	 * @return \WP_Term|\WP_Error Eligible term.
	 */
	public function term( int $term_id ) {
		$term = get_term( $term_id );

		if (
			! $term instanceof WP_Term ||
			! in_array( $term->taxonomy, self::taxonomies(), true ) ||
			! is_taxonomy_viewable( $term->taxonomy )
		) {
			return new WP_Error( 'topic_invalid_term', __( 'Choose a public category.', 'ai' ), array( 'status' => 400 ) );
		}

		return $term;
	}

	/**
	 * Query category membership, including descendants.
	 *
	 * @param \WP_Term $term Category.
	 * @param int $limit Maximum number of posts.
	 * @param array<string,mixed> $extra Query overrides.
	 * @return \WP_Query Source query.
	 */
	public function query( WP_Term $term, int $limit, array $extra = array() ): WP_Query {
		$types = self::post_types();

		return new WP_Query(
			array_merge(
				array(
					'post_type'           => $types ? $types : array( 'post' ),
					'post__in'            => $types ? array() : array( 0 ),
					'post_status'         => 'publish',
					'has_password'        => false,
					'posts_per_page'      => $limit,
					'orderby'             => array(
						'date' => 'ASC',
						'ID'   => 'ASC',
					),
					'ignore_sticky_posts' => true,
					'tax_query'           => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Category membership defines the sources.
						array(
							'taxonomy'         => $term->taxonomy,
							'terms'            => array( $term->term_id ),
							'include_children' => true,
						),
					), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Category membership is the source boundary.
				),
				$extra
			)
		);
	}

	/**
	 * Check the threshold with a limited ID query, not a full count.
	 */
	public function eligible( int $term_id ): bool {
		return ! is_wp_error( $this->eligible_term( $term_id ) );
	}

	/**
	 * Validate a category and its source threshold together.
	 *
	 * @return \WP_Term|\WP_Error Eligible category or the reason it cannot generate content.
	 */
	public function eligible_term( int $term_id ) {
		$term = $this->term( $term_id );

		if ( is_wp_error( $term ) ) {
			return $term;
		}

		$minimum = self::minimum();

		if ( count( $this->ids( $term, $minimum ) ) < $minimum ) {
			return new WP_Error( 'topic_below_minimum', __( 'This category needs at least five public posts before a topic guide can be generated.', 'ai' ) );
		}

		return $term;
	}

	/**
	 * Recheck visibility and category membership before displaying a source.
	 */
	public function is_public_source( int $post_id, WP_Term $term ): bool {
		$post = get_post( $post_id );

		if (
			! $post instanceof WP_Post ||
			'publish' !== $post->post_status ||
			'' !== $post->post_password ||
			! is_post_publicly_viewable( $post ) ||
			! in_array( $post->post_type, self::post_types(), true )
		) {
			return false;
		}

		$terms = get_the_terms( $post_id, $term->taxonomy );

		if ( ! $terms || is_wp_error( $terms ) ) {
			return false;
		}

		foreach ( $terms as $source_term ) {
			if (
				$source_term->term_id === $term->term_id ||
				in_array( $term->term_id, get_ancestors( $source_term->term_id, $term->taxonomy, 'taxonomy' ), true )
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return int[] At most $limit IDs, distributed across publication dates.
	 */
	public function sample( int $term_id, int $limit = 20 ): array {
		$term = $this->term( $term_id );

		if ( is_wp_error( $term ) ) {
			return array();
		}

		return $this->sample_for_term( $term, $limit );
	}

	/**
	 * Sample a category already validated by the caller.
	 *
	 * @return int[] Posts distributed across publication dates.
	 */
	private function sample_for_term( WP_Term $term, int $limit = 20 ): array {
		$limit  = max( 2, min( 20, $limit ) );
		$key    = 'wpai_topic_sample_' . $term->term_id . '_' . $limit;
		$cached = get_transient( $key );

		if (
			is_array( $cached ) &&
			count(
				array_filter(
					$cached,
					fn( $id ) => is_int( $id ) && $this->is_public_source( $id, $term )
				)
			) === count( $cached )
		) {
			return $cached;
		}

		$first = $this->ids( $term, $limit + 1 );

		if ( count( $first ) <= $limit ) {
			$ids = $first;
		} else {
			$last  = $this->ids(
				$term,
				1,
				array(
					'orderby' => array(
						'date' => 'DESC',
						'ID'   => 'DESC',
					),
				)
			)[0];
			$start = strtotime( $this->date( $first[0] ) );
			$end   = strtotime( $this->date( $last ) );
			$ids   = array( $first[0], $last );

			for ( $i = 1; $end > $start && $i < $limit - 1; $i++ ) {
				$after = gmdate( 'Y-m-d H:i:s', (int) ( $start + ( $end - $start ) * $i / ( $limit - 1 ) ) );
				$ids   = array_merge(
					$ids,
					$this->ids(
						$term,
						1,
						array(
							'date_query' => array(
								array(
									'after'     => $after,
									'inclusive' => true,
								),
							),
						)
					)
				);
			}

			// Fill sparse date ranges from the already bounded query, including equal-date posts.
			$ids = array_slice( array_values( array_unique( array_merge( $ids, $first ) ) ), 0, $limit );
			usort(
				$ids,
				function ( int $a, int $b ): int {
					$date = strcmp( $this->date( $a ), $this->date( $b ) );

					return $date ? $date : $a <=> $b;
				}
			);
		}

		set_transient( $key, $ids, DAY_IN_SECONDS );

		return $ids;
	}

	/**
	 * @return array<int,array<string,mixed>>|\WP_Error A bounded set of text excerpts.
	 */
	public function collect( int $term_id, string $section = Store::OVERVIEW ) {
		$term = $this->eligible_term( $term_id );

		if ( is_wp_error( $term ) ) {
			return $term;
		}

		return $this->collect_for_term( $term, $section );
	}

	/**
	 * Collect excerpts after the caller has checked category eligibility.
	 *
	 * @internal
	 * @param \WP_Term $term Validated, eligible category.
	 * @param string $section Requested section.
	 * @return array<int,array<string,mixed>>|\WP_Error Bounded text excerpts.
	 */
	public function collect_for_term( WP_Term $term, string $section ) {
		$ids     = Store::SUMMARY === $section ? array_reverse(
			$this->ids(
				$term,
				5,
				array(
					'orderby' => array(
						'date' => 'DESC',
						'ID'   => 'DESC',
					),
				)
			)
		) : $this->sample_for_term( $term );
		$budget  = (int) floor( 60000 / max( 1, count( $ids ) ) );
		$sources = array();

		foreach ( $ids as $id ) {
			$post = get_post( $id );

			if ( ! $post instanceof WP_Post || ! $this->is_public_source( $id, $term ) ) {
				return new WP_Error( 'topic_sources_changed', __( 'Source articles changed. Please try again.', 'ai' ) );
			}

			$text = substr( wp_scrub_utf8( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ) ), 0, $budget );
			// The input is valid UTF-8; only an incomplete final character can need trimming.
			while ( ! wp_is_valid_utf8( $text ) ) {
				$text = substr( $text, 0, -1 );
			}

			$sources[] = array(
				'id'      => $id,
				'title'   => '' !== trim( $post->post_title ) ? $post->post_title : __( '(no title)', 'ai' ),
				'date'    => $post->post_date,
				'content' => $text,
			);
		}

		return $sources;
	}

	/**
	 * Fingerprint the exact source records used for generation or freshness checks.
	 *
	 * @param array<int,array<string,mixed>> $sources Bounded source records.
	 * @return string Source fingerprint.
	 */
	public static function fingerprint( array $sources ): string {
		return hash( 'sha256', (string) wp_json_encode( $sources ) );
	}

	/**
	 * Invalidate selections for a category and its parents.
	 *
	 * @param int[] $term_ids Changed categories.
	 * @param string $taxonomy Taxonomy name.
	 */
	public static function invalidate( array $term_ids, string $taxonomy ): void {
		if ( ! in_array( $taxonomy, self::taxonomies(), true ) ) {
			return;
		}

		foreach ( $term_ids as $id ) {
			foreach ( array_merge( array( (int) $id ), get_ancestors( (int) $id, $taxonomy, 'taxonomy' ) ) as $topic ) {
				delete_transient( 'wpai_topic_sample_' . $topic . '_10' );
				delete_transient( 'wpai_topic_sample_' . $topic . '_20' );
			}
		}
	}

	/**
	 * Invalidate dates and text changed without a taxonomy change.
	 */
	public static function post_changed( int $post_id ): void {
		foreach ( self::taxonomies() as $taxonomy ) {
			$ids = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );

			if ( is_wp_error( $ids ) ) {
				continue;
			}

			self::invalidate( $ids, $taxonomy );
		}
	}

	/**
	 * Return a date safely if a post is deleted between queries.
	 */
	private function date( int $post_id ): string {
		$post = get_post( $post_id );

		return $post instanceof WP_Post ? $post->post_date : '';
	}

	/**
	 * Query only IDs, without totals or unnecessary metadata.
	 *
	 * @param \WP_Term $term Category.
	 * @param int $limit Maximum number of posts.
	 * @param array<string,mixed> $extra Query overrides.
	 * @return int[] Source IDs.
	 */
	private function ids( WP_Term $term, int $limit, array $extra = array() ): array {
		return array_map(
			static fn( $post ) => $post instanceof WP_Post ? $post->ID : $post,
			$this->query(
				$term,
				$limit,
				array_merge(
					array(
						'fields'                 => 'ids',
						'no_found_rows'          => true,
						'update_post_meta_cache' => false,
						'update_post_term_cache' => false,
					),
					$extra
				)
			)->posts
		);
	}
}
