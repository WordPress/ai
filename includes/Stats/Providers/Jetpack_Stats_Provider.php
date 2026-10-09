<?php
/**
 * Jetpack Stats adapter.
 *
 * @package WordPress\AI\Stats\Providers
 */

declare( strict_types=1 );

namespace WordPress\AI\Stats\Providers;

use WP_Error;
use WordPress\AI\Stats\Stats_Provider;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Adapts Jetpack Stats to the Stats_Provider contract.
 *
 * Wraps `stats_get_from_restapi()`, which the Jetpack Stats module defines
 * to call the WordPress.com Stats REST API (`/sites/$site/stats/...`) using
 * the site's Jetpack connection.
 *
 * IMPORTANT: Jetpack Stats does not track on-site WordPress search queries.
 * Its `search-terms` sub-resource returns terms visitors used on an
 * *external* search engine (e.g. Google) that led them to the site - not
 * queries typed into this site's own search box, and not whether those
 * queries returned zero results. `get_search_queries()` here is therefore a
 * proxy signal for content-gap detection (topics people are looking for
 * that led them here), not a literal "zero-result on-site search" report.
 * See WordPress/ai#338 discussion: an on-site search log fallback was
 * deliberately scoped out of v1.
 *
 * @since x.x.x
 */
class Jetpack_Stats_Provider implements Stats_Provider {

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'jetpack-stats';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		if ( ! function_exists( 'stats_get_from_restapi' ) ) {
			return false;
		}

		if ( ! class_exists( '\Jetpack' ) && ! class_exists( '\Automattic\Jetpack\Connection\Manager' ) ) {
			return false;
		}

		/**
		 * Filters whether the Jetpack Stats provider is treated as available.
		 *
		 * Useful for tests and for sites that want to force-disable this
		 * provider without deactivating Jetpack Stats entirely.
		 *
		 * @since x.x.x
		 *
		 * @param bool $available Whether the provider is available.
		 */
		return (bool) apply_filters( 'wpai_jetpack_stats_provider_available', true );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_search_queries( array $args = array() ) {
		if ( ! $this->is_available() ) {
			return new WP_Error(
				'stats_provider_unavailable',
				__( 'Jetpack Stats is not active or connected.', 'ai' )
			);
		}

		$args = wp_parse_args(
			$args,
			array(
				'limit' => 50,
				'days'  => 30,
			)
		);

		$response = stats_get_from_restapi(
			array(
				'period' => 'day',
				'num'    => max( 1, absint( $args['days'] ) ),
				'max'    => max( 1, absint( $args['limit'] ) ),
			),
			'search-terms'
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $this->parse_search_terms_response( $response );
	}

	/**
	 * Parses a `search-terms` REST API response into normalized query terms.
	 *
	 * Split out from get_search_queries() so the parsing logic can be
	 * exercised in tests with fixture data, without depending on the
	 * `stats_get_from_restapi()` function that only exists when Jetpack
	 * Stats is installed and connected.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $response Raw response from `stats_get_from_restapi()`.
	 * @return array<int, array{term: string, count: int}> Normalized query terms.
	 */
	private function parse_search_terms_response( $response ): array {
		if ( ! is_array( $response ) || empty( $response['search-terms'] ) || ! is_array( $response['search-terms'] ) ) {
			return array();
		}

		$terms = array();

		foreach ( $response['search-terms'] as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['term'] ) ) {
				continue;
			}

			$terms[] = array(
				'term'  => sanitize_text_field( (string) $entry['term'] ),
				'count' => isset( $entry['views'] ) ? absint( $entry['views'] ) : 1,
			);
		}

		return $terms;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_post_traffic( int $post_id, array $args = array() ) {
		if ( ! $this->is_available() ) {
			return new WP_Error(
				'stats_provider_unavailable',
				__( 'Jetpack Stats is not active or connected.', 'ai' )
			);
		}

		if ( $post_id <= 0 ) {
			return new WP_Error(
				'invalid_post_id',
				__( 'A valid post ID is required to fetch traffic data.', 'ai' )
			);
		}

		$args = wp_parse_args(
			$args,
			array(
				'days' => 14,
			)
		);

		$response = stats_get_from_restapi(
			array(
				'period' => 'day',
				'num'    => max( 1, absint( $args['days'] ) ),
			),
			'post/' . absint( $post_id )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $this->parse_post_traffic_response( $response );
	}

	/**
	 * Parses a `post/{id}` REST API response into a daily view-count series.
	 *
	 * Split out from get_post_traffic() so the parsing logic can be
	 * exercised in tests with fixture data. See parse_search_terms_response().
	 *
	 * @since x.x.x
	 *
	 * @param mixed $response Raw response from `stats_get_from_restapi()`.
	 * @return array<int, array{date: string, views: int}> Normalized daily view counts.
	 */
	private function parse_post_traffic_response( $response ): array {
		if ( ! is_array( $response ) || empty( $response['data'] ) || ! is_array( $response['data'] ) ) {
			return array();
		}

		$series = array();

		foreach ( $response['data'] as $date => $views ) {
			$series[] = array(
				'date'  => sanitize_text_field( (string) $date ),
				'views' => absint( $views ),
			);
		}

		return $series;
	}
}
