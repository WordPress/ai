<?php
/**
 * WP-Cron scheduling helpers for background work.
 *
 * @package WordPress\AI\Background
 */

declare( strict_types=1 );

namespace WordPress\AI\Background;

defined( 'ABSPATH' ) || exit;

/**
 * Schedules single WP-Cron events so a hook has at most one pending run.
 *
 * @since x.x.x
 */
class Scheduler {

	/**
	 * Schedules a single event unless one is already scheduled at or before the given time.
	 *
	 * @since x.x.x
	 *
	 * @param string      $hook      Cron hook.
	 * @param int         $timestamp Unix time the run should happen.
	 * @param list<mixed> $args      Optional. Arguments passed to the hook. Default empty.
	 * @return bool True when a run is scheduled at or before `$timestamp` afterwards.
	 */
	public static function schedule_at( string $hook, int $timestamp, array $args = array() ): bool {
		$scheduled = wp_next_scheduled( $hook, $args );

		if ( false !== $scheduled ) {
			if ( $scheduled <= $timestamp ) {
				return true;
			}

			wp_unschedule_event( $scheduled, $hook, $args );
		}

		return true === wp_schedule_single_event( $timestamp, $hook, $args );
	}
}
