<?php
/**
 * Integration tests for the Anonymizer class.
 *
 * @package WordPress\AI\Tests\Integration\Stats
 */

namespace WordPress\AI\Tests\Integration\Stats;

use WP_UnitTestCase;
use WordPress\AI\Stats\Anonymizer;

/**
 * Anonymizer test case.
 *
 * @since x.x.x
 */
class AnonymizerTest extends WP_UnitTestCase {

	/**
	 * Terms below the minimum count are dropped.
	 *
	 * @since x.x.x
	 */
	public function test_drops_terms_below_min_count(): void {
		$patterns = Anonymizer::anonymize(
			array(
				array(
					'term'  => 'wordpress hosting',
					'count' => 1,
				),
			)
		);

		$this->assertSame( array(), $patterns );
	}

	/**
	 * Terms that meet the minimum count are kept and normalized.
	 *
	 * @since x.x.x
	 */
	public function test_keeps_and_normalizes_terms_at_min_count(): void {
		$patterns = Anonymizer::anonymize(
			array(
				array(
					'term'  => '  WordPress Hosting  ',
					'count' => 3,
				),
			)
		);

		$this->assertSame(
			array(
				array(
					'pattern' => 'wordpress hosting',
					'count'   => 3,
				),
			),
			$patterns
		);
	}

	/**
	 * Case/whitespace variants of the same term are merged before the min-count check.
	 *
	 * @since x.x.x
	 */
	public function test_merges_case_and_whitespace_variants(): void {
		$patterns = Anonymizer::anonymize(
			array(
				array(
					'term'  => 'best coffee grinder',
					'count' => 1,
				),
				array(
					'term'  => 'Best Coffee Grinder',
					'count' => 1,
				),
				array(
					'term'  => '  best   coffee grinder ',
					'count' => 1,
				),
			)
		);

		$this->assertSame(
			array(
				array(
					'pattern' => 'best coffee grinder',
					'count'   => 3,
				),
			),
			$patterns
		);
	}

	/**
	 * Terms that look like they contain an email address are never returned.
	 *
	 * @since x.x.x
	 */
	public function test_drops_terms_containing_email_addresses(): void {
		$patterns = Anonymizer::anonymize(
			array(
				array(
					'term'  => 'contact jane.doe@example.com about refund',
					'count' => 10,
				),
			)
		);

		$this->assertSame( array(), $patterns );
	}

	/**
	 * Terms that look like they contain a long numeric ID are never returned.
	 *
	 * @since x.x.x
	 */
	public function test_drops_terms_containing_long_digit_runs(): void {
		$patterns = Anonymizer::anonymize(
			array(
				array(
					'term'  => 'order status 483920123',
					'count' => 10,
				),
			)
		);

		$this->assertSame( array(), $patterns );
	}

	/**
	 * Results are sorted by count descending and limited.
	 *
	 * @since x.x.x
	 */
	public function test_sorts_by_count_descending_and_limits(): void {
		$patterns = Anonymizer::anonymize(
			array(
				array(
					'term'  => 'low',
					'count' => 2,
				),
				array(
					'term'  => 'high',
					'count' => 9,
				),
				array(
					'term'  => 'mid',
					'count' => 5,
				),
			),
			array( 'limit' => 2 )
		);

		$this->assertSame(
			array( 'high', 'mid' ),
			array_column( $patterns, 'pattern' )
		);
	}

	/**
	 * Malformed entries are skipped without error.
	 *
	 * @since x.x.x
	 */
	public function test_skips_malformed_entries(): void {
		$patterns = Anonymizer::anonymize(
			array(
				array( 'count' => 5 ),
				'not-an-array',
				array(
					'term'  => 'valid term',
					'count' => 5,
				),
			)
		);

		$this->assertSame(
			array(
				array(
					'pattern' => 'valid term',
					'count'   => 5,
				),
			),
			$patterns
		);
	}
}
