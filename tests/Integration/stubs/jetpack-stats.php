<?php
/**
 * Test doubles for Jetpack Stats, which is not installed in the test environment.
 *
 * @package WordPress\AI\Tests\Integration
 */

// phpcs:disable

if ( ! class_exists( 'Jetpack' ) ) {
	class Jetpack {}
}

if ( ! function_exists( 'stats_get_from_restapi' ) ) {
	/**
	 * Returns the canned response stored in $GLOBALS['wpai_test_stats_response'].
	 *
	 * @param array  $args     Request args (recorded for assertions).
	 * @param string $resource Sub-resource (recorded for assertions).
	 * @return mixed
	 */
	function stats_get_from_restapi( $args = array(), $resource = '' ) {
		$GLOBALS['wpai_test_stats_request'] = array( $args, $resource );
		return $GLOBALS['wpai_test_stats_response'] ?? array();
	}
}
