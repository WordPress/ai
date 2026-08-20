<?php
/**
 * Handles removal of the plugin's data on uninstall.
 *
 * @package WordPress\AI\Admin
 * @since 1.3.0
 */

declare( strict_types=1 );

namespace WordPress\AI\Admin;

use WordPress\AI\Experiments\Key_Encryption\Secrets_Bridge;
use WordPress\AI\Logging\AI_Request_Log_Schema;
use WordPress\AI\Vendor\Secrets\Secrets_Provider_Encrypted_Options;

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
 * @since 1.3.0
 */
final class Uninstall {

	/**
	 * Scheduled cron hook used by the request log manager.
	 *
	 * @since 1.3.0
	 *
	 * @var string
	 */
	private const REQUEST_LOG_CLEANUP_HOOK = 'wpai_request_logs_cleanup';

	/**
	 * User meta key set when the connector approval notice is dismissed.
	 *
	 * @since 1.3.0
	 *
	 * @var string
	 */
	private const CONNECTOR_APPROVAL_NOTICE_META = 'wpai_connector_approval_notice_dismissed';

	/**
	 * Runs the uninstall routine.
	 *
	 * Cleanup happens by default unless a developer opts out via the
	 * "wpai_remove_data_on_uninstall" filter. On multisite the filter is
	 * evaluated per site so each site keeps control of its own data.
	 *
	 * @since 1.3.0
	 */
	public static function run(): void {
		if ( is_multisite() ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);

			$cleaned_any_site = false;

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog
				$cleaned_any_site = self::maybe_clean_current_site() || $cleaned_any_site;
				restore_current_blog();
			}

			// Network-level data is shared by every site, so it is cleaned once
			// rather than inside the loop, and only when at least one site opted in.
			if ( $cleaned_any_site ) {
				self::delete_network_transients();
			}

			return;
		}

		self::maybe_clean_current_site();
	}

	/**
	 * Removes the plugin's data for the current site when opted in.
	 *
	 * @since 1.3.0
	 *
	 * @return bool Whether the data was removed.
	 */
	private static function maybe_clean_current_site(): bool {
		/**
		 * Filters whether the plugin should remove all of its data on uninstall.
		 *
		 * Removal is enabled by default. Return false to keep the plugin's
		 * custom table, options, transients and scheduled events for the current
		 * site. On multisite this filter runs once per site.
		 *
		 * @since 1.3.0
		 *
		 * @param bool $remove_data Whether to remove all plugin data. Default true.
		 */
		if ( ! (bool) apply_filters( 'wpai_remove_data_on_uninstall', true ) ) {
			return false;
		}

		self::drop_request_logs_table();
		self::delete_options();
		self::delete_meta();
		self::delete_transients();
		self::clear_scheduled_events();

		return true;
	}

	/**
	 * Drops the request logs custom table.
	 *
	 * @since 1.3.0
	 */
	private static function drop_request_logs_table(): void {
		global $wpdb;

		$table_name = $wpdb->prefix . AI_Request_Log_Schema::TABLE_NAME;

		$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Deletes all of the plugin's options.
	 *
	 * @since 1.3.0
	 */
	private static function delete_options(): void {
		// Option name prefixes owned by the plugin. `wpai_` covers settings,
		// feature toggles, versions and connector approvals; `ai_experiment_`
		// covers options left over from pre-1.0 installs; `_secret_ai/` covers
		// the connector API keys encrypted by the Key Encryption experiment.
		$prefixes = array(
			'wpai_',
			'ai_experiment_',
			self::secrets_option_prefix(),
		);

		foreach ( $prefixes as $prefix ) {
			foreach ( self::get_option_names_by_prefix( $prefix ) as $option_name ) {
				delete_option( $option_name );
			}
		}

		// Exact option names that don't share a plugin prefix: legacy pre-1.0
		// options from the plugin's own AI Credentials screen, which was replaced
		// by the Connectors approach.
		$option_names = array(
			'ai_experiments_enabled',
			'wp_ai_client_provider_credentials',
		);

		foreach ( $option_names as $option_name ) {
			delete_option( $option_name );
		}

		self::maybe_delete_secrets_master_key();
	}

	/**
	 * Deletes the Secrets master key, but only once nothing else depends on it.
	 *
	 * The master key option name comes from the vendored Displace Secrets Manager
	 * SDK, not from this plugin, so the same option can be in use by the upstream
	 * plugin or anything else built on that SDK. Deleting it while other
	 * `_secret_*` rows exist would make those secrets permanently undecryptable,
	 * so it is only removed when this plugin's secrets were the last ones left.
	 *
	 * @since 1.3.0
	 */
	private static function maybe_delete_secrets_master_key(): void {
		global $wpdb;

		$remaining = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name != %s LIMIT 1",
				$wpdb->esc_like( Secrets_Provider_Encrypted_Options::OPTION_PREFIX ) . '%',
				Secrets_Provider_Encrypted_Options::MASTER_KEY_OPTION
			)
		);

		if ( null !== $remaining ) {
			return;
		}

		delete_option( Secrets_Provider_Encrypted_Options::MASTER_KEY_OPTION );
	}

	/**
	 * Deletes the plugin's metadata (post, comment and user meta).
	 *
	 * Only meta owned by the plugin is removed. Meta the plugin writes into but
	 * does not own (e.g. core "_wp_attachment_image_alt" or third-party SEO
	 * description keys) is left untouched.
	 *
	 * @since 1.3.0
	 */
	private static function delete_meta(): void {
		global $wpdb;

		// User meta: connector approval notice dismissal flag.
		$user_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s",
				self::CONNECTOR_APPROVAL_NOTICE_META
			)
		);

		/** @var list<string> $user_ids */
		foreach ( $user_ids as $user_id ) {
			delete_user_meta( (int) $user_id, self::CONNECTOR_APPROVAL_NOTICE_META );
		}
	}

	/**
	 * Deletes the plugin's transients for the current site.
	 *
	 * @since 1.3.0
	 */
	private static function delete_transients(): void {
		$prefix = '_transient_';

		foreach ( self::get_option_names_by_prefix( $prefix . 'wpai_' ) as $option_name ) {
			// delete_transient() removes the paired timeout row and the cached value.
			delete_transient( substr( $option_name, strlen( $prefix ) ) );
		}

		if ( is_multisite() ) {
			// On multisite, site transients are network-level and live in the
			// sitemeta table, so they are handled by delete_network_transients().
			return;
		}

		$site_prefix = '_site_transient_';

		foreach ( self::get_option_names_by_prefix( $site_prefix . 'wpai_' ) as $option_name ) {
			delete_site_transient( substr( $option_name, strlen( $site_prefix ) ) );
		}
	}

	/**
	 * Deletes the plugin's network-level site transients on multisite.
	 *
	 * @since 1.3.0
	 */
	private static function delete_network_transients(): void {
		global $wpdb;

		$prefix = '_site_transient_';

		$meta_keys = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT meta_key FROM {$wpdb->sitemeta} WHERE site_id = %d AND meta_key LIKE %s",
				get_current_network_id(),
				$wpdb->esc_like( $prefix . 'wpai_' ) . '%'
			)
		);

		/** @var list<string> $meta_keys */
		foreach ( $meta_keys as $meta_key ) {
			delete_site_transient( substr( $meta_key, strlen( $prefix ) ) );
		}
	}

	/**
	 * Clears the plugin's scheduled cron events.
	 *
	 * @since 1.3.0
	 */
	private static function clear_scheduled_events(): void {
		wp_clear_scheduled_hook( self::REQUEST_LOG_CLEANUP_HOOK );
	}

	/**
	 * Returns the option name prefix used for this plugin's encrypted secrets.
	 *
	 * @since 1.3.0
	 *
	 * @return string The option name prefix, e.g. "_secret_ai/".
	 */
	private static function secrets_option_prefix(): string {
		return Secrets_Provider_Encrypted_Options::OPTION_PREFIX . Secrets_Bridge::SECRET_NAMESPACE . '/';
	}

	/**
	 * Returns every option name in the current site that starts with a prefix.
	 *
	 * @since 1.3.0
	 *
	 * @param string $prefix Literal option name prefix to match.
	 * @return list<string> Matching option names.
	 */
	private static function get_option_names_by_prefix( string $prefix ): array {
		global $wpdb;

		$option_names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( $prefix ) . '%'
			)
		);

		return array_values( array_map( 'strval', $option_names ) );
	}
}
