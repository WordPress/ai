<?php
/**
 * Stats Provider interface.
 *
 * @package WordPress\AI\Stats
 */

declare( strict_types=1 );

namespace WordPress\AI\Stats;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Contract for adapters that expose traffic/search data from an analytics plugin.
 *
 * Implementations wrap a specific analytics plugin (e.g. Jetpack Stats) and
 * normalize its data into the shapes consumed by the Content Gap Suggestions
 * and Traffic Amplification experiments. Implementations must never return
 * raw personally-identifying data (IPs, user agents, full referrer URLs) -
 * only aggregate counts and query/term strings.
 *
 * @since x.x.x
 */
interface Stats_Provider {

	/**
	 * Machine-readable identifier for this provider (e.g. 'jetpack-stats').
	 *
	 * @since x.x.x
	 *
	 * @return non-empty-string Provider identifier.
	 */
	public function get_id(): string;

	/**
	 * Whether this provider's backing plugin is installed, active, and connected.
	 *
	 * @since x.x.x
	 *
	 * @return bool True if the provider can be queried right now.
	 */
	public function is_available(): bool;

	/**
	 * Returns search/referrer query terms associated with the site.
	 *
	 * @since x.x.x
	 *
	 * @param array{limit?: int, days?: int} $args {
	 *     Optional. Query arguments.
	 *
	 *     @type int $limit Maximum number of terms to return. Default 50.
	 *     @type int $days  Number of trailing days to include. Default 30.
	 * }
	 * @return array<int, array{term: string, count: int}>|\WP_Error List of query terms with counts, or WP_Error on failure.
	 */
	public function get_search_queries( array $args = array() );

	/**
	 * Returns a rolling daily view-count series for a single post.
	 *
	 * @since x.x.x
	 *
	 * @param int                $post_id Post ID.
	 * @param array{days?: int}  $args {
	 *     Optional. Query arguments.
	 *
	 *     @type int $days Number of trailing days to include. Default 14.
	 * }
	 * @return array<int, array{date: string, views: int}>|\WP_Error Daily view counts, or WP_Error on failure.
	 */
	public function get_post_traffic( int $post_id, array $args = array() );
}
