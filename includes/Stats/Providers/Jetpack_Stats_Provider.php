<?php
/**
 * Jetpack Stats adapter.
 *
 * @package WordPress\AI\Stats\Providers
 */

declare( strict_types=1 );

namespace WordPress\AI\Stats\Providers;

use Automattic\Jetpack\Stats\WPCOM_Stats;
use WP_Error;
use WordPress\AI\Stats\Stats_Provider_Interface;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Adapts Jetpack Stats to the Stats_Provider_Interface contract.
 *
 * Wraps `Automattic\Jetpack\Stats\WPCOM_Stats`, which calls the WordPress.com
 * Stats REST API (`/sites/$site/stats/...`) using the site's Jetpack connection.
 *
 * IMPORTANT: Jetpack Stats does not track on-site WordPress search queries.
 * Its `search-terms` resource returns terms visitors used on an *external*
 * search engine (e.g. Google) that led them to the site - not queries typed
 * into this site's own search box, and not whether those queries returned
 * zero results. `get_search_queries()` here is therefore a proxy signal for
 * content-gap detection (topics people are looking for that led them here),
 * not a literal "zero-result on-site search" report. See WordPress/ai#338
 * discussion: an on-site search log fallback was deliberately scoped out of v1.
 *
 * @since x.x.x
 */
class Jetpack_Stats_Provider implements Stats_Provider_Interface {

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
		$available = $this->is_jetpack_stats_ready();

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
		return (bool) apply_filters( 'wpai_jetpack_stats_provider_available', $available );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_search_queries( array $args = array() ) {
		if ( ! $this->is_available() ) {
			return $this->unavailable_error();
		}

		$args = wp_parse_args(
			$args,
			array(
				'limit' => 50,
				'days'  => 30,
			)
		);

		$response = ( new WPCOM_Stats() )->get_search_terms(
			array(
				'period'    => 'day',
				'num'       => max( 1, absint( $args['days'] ) ),
				'max'       => max( 1, absint( $args['limit'] ) ),
				'summarize' => 1,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $this->parse_search_terms_response( $response );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_post_traffic( int $post_id, array $args = array() ) {
		if ( ! $this->is_available() ) {
			return $this->unavailable_error();
		}

		if ( $post_id <= 0 ) {
			return new WP_Error(
				'invalid_post_id',
				__( 'A valid post ID is required to fetch traffic data.', 'ai' )
			);
		}

		$response = ( new WPCOM_Stats() )->get_post_views( $post_id );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $this->parse_post_traffic_response( $response );
	}

	/**
	 * Whether Jetpack is installed, connected, and has the Stats module active.
	 *
	 * @since x.x.x
	 *
	 * @return bool True if Jetpack Stats can be queried.
	 */
	private function is_jetpack_stats_ready(): bool {
		if ( ! class_exists( WPCOM_Stats::class ) || ! class_exists( '\Jetpack' ) ) {
			return false;
		}

		if ( ! \Jetpack::is_connection_ready() ) {
			return false;
		}

		return \Jetpack::is_module_active( 'stats' );
	}

	/**
	 * Builds the error returned when Jetpack Stats can't be queried.
	 *
	 * @since x.x.x
	 *
	 * @return \WP_Error The error.
	 */
	private function unavailable_error(): WP_Error {
		return new WP_Error(
			'stats_provider_unavailable',
			__( 'Jetpack Stats is not active or connected.', 'ai' )
		);
	}

	/**
	 * Parses a `search-terms` response into normalized query terms.
	 *
	 * Uses the summarized shape (`summary.search_terms`) when present, and
	 * otherwise the per-day shape (`days.{date}.search_terms`), merging
	 * repeated terms.
	 * Split out so the parsing can be tested with fixture data.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $response Raw response from WPCOM_Stats.
	 * @return array<int, array{term: string, count: int}> Normalized query terms.
	 */
	private function parse_search_terms_response( $response ): array {
		$response = $this->to_array( $response );

		// Prefer the summary: when both are present it already covers the same
		// period as the days, so using both would double the counts.
		$groups = array();

		if ( isset( $response['summary']['search_terms'] ) && is_array( $response['summary']['search_terms'] ) ) {
			$groups[] = $response['summary']['search_terms'];
		} elseif ( isset( $response['days'] ) && is_array( $response['days'] ) ) {
			foreach ( $response['days'] as $day ) {
				if ( ! is_array( $day ) || ! isset( $day['search_terms'] ) || ! is_array( $day['search_terms'] ) ) {
					continue;
				}

				$groups[] = $day['search_terms'];
			}
		}

		$counts = array();

		foreach ( $groups as $entries ) {
			foreach ( $entries as $entry ) {
				if ( ! is_array( $entry ) || empty( $entry['term'] ) ) {
					continue;
				}

				$term = sanitize_text_field( (string) $entry['term'] );

				if ( '' === $term ) {
					continue;
				}

				$counts[ $term ] = ( $counts[ $term ] ?? 0 ) + ( isset( $entry['views'] ) ? absint( $entry['views'] ) : 1 );
			}
		}

		$terms = array();

		foreach ( $counts as $term => $count ) {
			$terms[] = array(
				'term'  => (string) $term,
				'count' => $count,
			);
		}

		return $terms;
	}

	/**
	 * Parses a post stats response into a daily view-count series.
	 *
	 * Accepts `data` rows as `[ date, views ]` pairs or a `date => views` map.
	 * Split out so the parsing can be tested with fixture data.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $response Raw response from WPCOM_Stats.
	 * @return array<int, array{date: string, views: int}> Normalized daily view counts.
	 */
	private function parse_post_traffic_response( $response ): array {
		$response = $this->to_array( $response );

		if ( empty( $response['data'] ) || ! is_array( $response['data'] ) ) {
			return array();
		}

		$series = array();

		foreach ( $response['data'] as $key => $value ) {
			if ( is_array( $value ) && isset( $value[0], $value[1] ) ) {
				$date  = (string) $value[0];
				$views = $value[1];
			} elseif ( ! is_array( $value ) ) {
				$date  = (string) $key;
				$views = $value;
			} else {
				continue;
			}

			$series[] = array(
				'date'  => sanitize_text_field( $date ),
				'views' => absint( $views ),
			);
		}

		return $series;
	}

	/**
	 * Normalizes an API response (array or object) to a nested array.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $response Raw response.
	 * @return array<mixed> The response as an array, or an empty array if it isn't structured data.
	 */
	private function to_array( $response ): array {
		if ( is_object( $response ) ) {
			$response = json_decode( (string) wp_json_encode( $response ), true );
		}

		return is_array( $response ) ? $response : array();
	}
}
