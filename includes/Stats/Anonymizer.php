<?php
/**
 * Query pattern anonymizer.
 *
 * @package WordPress\AI\Stats
 */

declare( strict_types=1 );

namespace WordPress\AI\Stats;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Reduces raw search/query terms to anonymized, aggregated patterns.
 *
 * This is the boundary between site stats data and anything sent to an AI
 * provider: raw per-visitor query strings must never cross it unmodified.
 * Terms that look like they may contain personal data (emails, phone-like
 * digit runs, long numeric IDs) are dropped outright, remaining terms are
 * normalized and merged by lowercase/trimmed form, and terms seen too few
 * times to be a "pattern" (as opposed to a single visitor's one-off query)
 * are dropped.
 *
 * @since x.x.x
 */
final class Anonymizer {

	/**
	 * Minimum aggregate occurrence count for a term to be considered a pattern.
	 *
	 * Terms below this threshold are dropped rather than sent to the AI
	 * provider, since a count of one is indistinguishable from a single
	 * visitor's personal or identifying query.
	 *
	 * @since x.x.x
	 * @var int
	 */
	private const DEFAULT_MIN_COUNT = 2;

	/**
	 * Reduces raw query terms into anonymized, aggregated patterns.
	 *
	 * @since x.x.x
	 *
	 * @param array<int, array{term: string, count: int}> $terms Raw query terms, as returned by a Stats_Provider.
	 * @param array{min_count?: int, limit?: int}          $args  {
	 *     Optional. Arguments.
	 *
	 *     @type int $min_count Minimum aggregate count for a term to be kept. Default 2.
	 *     @type int $limit     Maximum number of patterns to return. Default 20.
	 * }
	 * @return array<int, array{pattern: string, count: int}> Anonymized patterns, sorted by count descending.
	 */
	public static function anonymize( array $terms, array $args = array() ): array {
		$args = wp_parse_args(
			$args,
			array(
				'min_count' => self::DEFAULT_MIN_COUNT,
				'limit'     => 20,
			)
		);

		$grouped = array();

		foreach ( $terms as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['term'] ) ) {
				continue;
			}

			$term = self::normalize( (string) $entry['term'] );

			if ( '' === $term || self::looks_identifying( $term ) ) {
				continue;
			}

			$count = isset( $entry['count'] ) ? absint( $entry['count'] ) : 1;

			if ( ! isset( $grouped[ $term ] ) ) {
				$grouped[ $term ] = 0;
			}

			$grouped[ $term ] += $count;
		}

		$patterns = array();

		foreach ( $grouped as $term => $count ) {
			if ( $count < absint( $args['min_count'] ) ) {
				continue;
			}

			$patterns[] = array(
				'pattern' => $term,
				'count'   => $count,
			);
		}

		usort(
			$patterns,
			static function ( array $a, array $b ): int {
				return $b['count'] <=> $a['count'];
			}
		);

		return array_slice( $patterns, 0, max( 0, absint( $args['limit'] ) ) );
	}

	/**
	 * Normalizes a term for grouping: trims, lowercases, collapses whitespace.
	 *
	 * @since x.x.x
	 *
	 * @param string $term Raw term.
	 * @return string Normalized term.
	 */
	private static function normalize( string $term ): string {
		$term = wp_strip_all_tags( $term );
		$term = preg_replace( '/\s+/', ' ', $term ) ?? $term;

		return trim( strtolower( $term ) );
	}

	/**
	 * Heuristically flags terms that may contain personal or identifying data.
	 *
	 * @since x.x.x
	 *
	 * @param string $term Normalized term.
	 * @return bool True if the term looks like it may be identifying and should be dropped.
	 */
	private static function looks_identifying( string $term ): bool {
		// Email addresses.
		if ( preg_match( '/[^\s@]+@[^\s@]+\.[^\s@]+/', $term ) ) {
			return true;
		}

		// Long digit runs (phone numbers, order/account IDs, etc.).
		if ( preg_match( '/\d{5,}/', $term ) ) {
			return true;
		}

		/**
		 * Filters whether a normalized term looks identifying and should be dropped.
		 *
		 * Return true to force-drop a term the built-in heuristics missed.
		 *
		 * @since x.x.x
		 *
		 * @param bool   $looks_identifying Whether the built-in heuristics flagged the term.
		 * @param string $term              The normalized term being evaluated.
		 */
		return (bool) apply_filters( 'wpai_stats_term_looks_identifying', false, $term );
	}
}
