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

		$response = $this->get_stats_client()->get_search_terms(
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
	 * Whether Jetpack is installed, connected, and has the Stats module active.
	 *
	 * @since x.x.x
	 *
	 * @return bool True if Jetpack Stats can be queried.
	 */
	private function is_jetpack_stats_ready(): bool {
		return $this->is_jetpack_loaded() && $this->is_jetpack_connected() && $this->is_stats_module_active();
	}

	/**
	 * Whether Jetpack and its Stats package are loaded.
	 *
	 * Protected so tests can replace the Jetpack-dependent checks without
	 * defining fake Jetpack classes, which PHP can't unload for later tests.
	 *
	 * @since x.x.x
	 *
	 * @return bool True if the Jetpack classes this provider uses exist.
	 */
	protected function is_jetpack_loaded(): bool {
		return class_exists( WPCOM_Stats::class ) && class_exists( '\Jetpack' );
	}

	/**
	 * Whether Jetpack is connected to WordPress.com.
	 *
	 * @since x.x.x
	 *
	 * @return bool True if the connection is ready.
	 */
	protected function is_jetpack_connected(): bool {
		return \Jetpack::is_connection_ready();
	}

	/**
	 * Whether the Jetpack Stats module is active.
	 *
	 * @since x.x.x
	 *
	 * @return bool True if the Stats module is active.
	 */
	protected function is_stats_module_active(): bool {
		return \Jetpack::is_module_active( 'stats' );
	}

	/**
	 * Returns the Jetpack Stats client.
	 *
	 * @since x.x.x
	 *
	 * @return \Automattic\Jetpack\Stats\WPCOM_Stats The client. Declared `object` natively so tests can substitute a fake.
	 */
	protected function get_stats_client(): object {
		return new WPCOM_Stats();
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
