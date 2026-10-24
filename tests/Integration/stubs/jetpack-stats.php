<?php
/**
 * Test doubles for Jetpack and Jetpack Stats, which are not installed in the test environment.
 *
 * Behavior is controlled through globals so each test can set its own scenario:
 *
 * - `wpai_test_jetpack_connected` (bool, default true)
 * - `wpai_test_jetpack_stats_active` (bool, default true)
 * - `wpai_test_stats_response` (mixed, returned by WPCOM_Stats methods)
 * - `wpai_test_stats_request` (array, records the last method call)
 *
 * @package WordPress\AI\Tests\Integration
 */

namespace {
	// phpcs:disable

	if ( ! class_exists( 'Jetpack' ) ) {
		class Jetpack {
			public static function is_connection_ready() {
				return $GLOBALS['wpai_test_jetpack_connected'] ?? true;
			}

			public static function is_module_active( $module ) {
				return 'stats' === $module && ( $GLOBALS['wpai_test_jetpack_stats_active'] ?? true );
			}
		}
	}
}

namespace Automattic\Jetpack\Stats {
	if ( ! class_exists( __NAMESPACE__ . '\WPCOM_Stats' ) ) {
		class WPCOM_Stats {
			public function get_search_terms( $args = array() ) {
				$GLOBALS['wpai_test_stats_request'] = array( 'get_search_terms', $args );
				return $GLOBALS['wpai_test_stats_response'] ?? array();
			}

			public function get_post_views( $post_id, $args = array(), $cache_in_meta = false ) {
				$GLOBALS['wpai_test_stats_request'] = array( 'get_post_views', $post_id, $args );
				return $GLOBALS['wpai_test_stats_response'] ?? array();
			}
		}
	}
}
