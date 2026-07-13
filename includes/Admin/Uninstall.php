<?php
/**
 * Handles removal of the plugin's data on uninstall.
 *
 * @package WordPress\AI\Admin
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Admin;

use WordPress\AI\Logging\AI_Request_Log_Schema;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Uninstall.
 *
 * Removes the plugin's custom table, options and scheduled events by default.
 * Developers can opt out by returning false from the
 * "wpai_remove_data_on_uninstall" filter to preserve the plugin's data.
 *
 * @internal
 *
 * @since x.x.x
 */
final class Uninstall {

	/**
	 * Scheduled cron hook used by the request log manager.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const REQUEST_LOG_CLEANUP_HOOK = 'wpai_request_logs_cleanup';

	/**
	 * Runs the uninstall routine.
	 *
	 * Cleanup happens by default unless a developer opts out via the
	 * "wpai_remove_data_on_uninstall" filter. On multisite the filter is
	 * evaluated per site so each site keeps control of its own data.
	 *
	 * @since x.x.x
	 *
	 * @return void
	 */
	public static function run(): void {
		if ( is_multisite() ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog
				self::maybe_clean_current_site();
				restore_current_blog();
			}

			return;
		}

		self::maybe_clean_current_site();
	}

	/**
	 * Removes the plugin's data for the current site when opted in.
	 *
	 * @since x.x.x
	 *
	 * @return void
	 */
	private static function maybe_clean_current_site(): void {
		/**
		 * Filters whether the plugin should remove all of its data on uninstall.
		 *
		 * Removal is enabled by default. Return false to keep the plugin's
		 * custom table, options, transients and scheduled events for the current
		 * site. On multisite this filter runs once per site.
		 *
		 * @since x.x.x
		 *
		 * @param bool $remove_data Whether to remove all plugin data. Default true.
		 */
		if ( ! (bool) apply_filters( 'wpai_remove_data_on_uninstall', true ) ) {
			return;
		}

		self::drop_request_logs_table();
		self::delete_options();
		self::delete_transients();
		self::clear_scheduled_events();
	}

	/**
	 * Drops the request logs custom table.
	 *
	 * @since x.x.x
	 *
	 * @return void
	 */
	private static function drop_request_logs_table(): void {
		global $wpdb;

		$table_name = $wpdb->prefix . AI_Request_Log_Schema::TABLE_NAME;

		$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Deletes all of the plugin's options.
	 *
	 * @since x.x.x
	 *
	 * @return void
	 */
	private static function delete_options(): void {
		global $wpdb;

		$like = $wpdb->esc_like( 'wpai_' ) . '%';

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$like
			)
		);
	}

	/**
	 * Deletes the plugin's transients (regular and site transients).
	 *
	 * @since x.x.x
	 *
	 * @return void
	 */
	private static function delete_transients(): void {
		global $wpdb;

		$patterns = array(
			$wpdb->esc_like( '_transient_wpai_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_wpai_' ) . '%',
			$wpdb->esc_like( '_site_transient_wpai_' ) . '%',
			$wpdb->esc_like( '_site_transient_timeout_wpai_' ) . '%',
		);

		foreach ( $patterns as $like ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
					$like
				)
			);
		}
	}

	/**
	 * Clears the plugin's scheduled cron events.
	 *
	 * @since x.x.x
	 *
	 * @return void
	 */
	private static function clear_scheduled_events(): void {
		wp_clear_scheduled_hook( self::REQUEST_LOG_CLEANUP_HOOK );
	}
}
