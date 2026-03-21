<?php
/**
 * Handles any routines or migrations necessary when upgrading to a new version of the plugin.
 *
 * @package WordPress\AI\Admin
 * @since 0.6.0
 */

declare( strict_types=1 );

namespace WordPress\AI\Admin;

use WordPress\AI\Admin\Upgrades\V0_5_0;
use WordPress\AI\Admin\Upgrades\V0_6_0;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Upgrades.
 *
 * @internal
 *
 * @since 0.6.0
 */
final class Upgrades {
	/**
	 * The key to store the version.
	 *
	 * @since 0.6.0
	 */
	private const VERSION_OPTION_KEY = 'wpai_version';

	/**
	 * The key to store failed upgrade information for the admin notice.
	 *
	 * @since 0.6.0
	 */
	private const FAILED_UPGRADE_OPTION_KEY = 'wpai_failed_upgrade_message';

	/**
	 * Upgrade classes.
	 *
	 * New upgrade routine classes should be added here, in order of oldest to newest.
	 *
	 * @since 0.6.0
	 *
	 * @var class-string<\WordPress\AI\Admin\Upgrades\Abstract_Upgrade>[]
	 */
	private const UPGRADE_CLASSES = array( // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is used as a single const.
		V0_5_0::class,
		V0_6_0::class,
	);

	/**
	 * Initialize the class.
	 *
	 * @since 0.6.0
	 */
	public function init(): void {
		// Runs as a fallback, in case the activation hook is missed.
		add_action( 'admin_init', array( $this, 'do_upgrades' ) );

		add_action( 'admin_notices', array( $this, 'failed_upgrade_notice' ) );
	}

	/**
	 * Checks for and runs any pending upgrades.
	 *
	 * @since 0.6.0
	 */
	public static function do_upgrades(): void {
		$db_version = get_option( self::VERSION_OPTION_KEY, '' );

		foreach ( self::UPGRADE_CLASSES as $upgrade_class ) {
			/**
			 * Skip upgrades for newer versions.
			 * @todo Remove the !empty() check once we no long need to support < v0.5.0 and '' means a new install.
			 */
			if ( ! empty( $db_version ) && version_compare( $db_version, $upgrade_class::$version, '>=' ) ) {
				continue;
			}

			$upgrade = new $upgrade_class( $db_version );
			$result  = $upgrade->run();

			// Store the error message and stop if the upgrade failed.
			if ( is_wp_error( $result ) ) {
				update_option(
					self::FAILED_UPGRADE_OPTION_KEY,
					array(
						'version' => $upgrade_class::$version,
						'error'   => $result->get_error_message(),
					)
				);
				return;
			}

			$db_version = $upgrade_class::$version;
		}

		// If all upgrades completed successfully, the plugin was successfully upgraded to the latest version.
		delete_option( self::FAILED_UPGRADE_OPTION_KEY );
		update_option( self::VERSION_OPTION_KEY, WPAI_VERSION );
	}

	/**
	 * Displays an admin notice if a plugin upgrade failed, with the error message.
	 *
	 * @since 0.6.0
	 */
	public function failed_upgrade_notice(): void {
		// Skip if there's no failures.
		$failed_upgrade = get_option( self::FAILED_UPGRADE_OPTION_KEY, false );
		if ( ! $failed_upgrade ) {
			return;
		}

		// If the error is set but empty, clean it up.
		if ( empty( $failed_upgrade['version'] ) || empty( $failed_upgrade['error'] ) ) {
			delete_option( self::FAILED_UPGRADE_OPTION_KEY );
			return;
		}

		// Display the error message.
		wp_admin_notice(
			sprintf(
				/* translators: 1. The version the upgrade failed on, 2. The error message. */
				esc_html__( 'WordPress AI failed to upgrade to %1$s. Migration version %2$s failed with the following error: %3$s. Please deactivate and reactivate the plugin to try again.', 'ai' ),
				WPAI_VERSION,
				esc_html( $failed_upgrade['version'] ),
				esc_html( $failed_upgrade['error'] )
			),
			array(
				'type'        => 'error',
				'dismissible' => false,
			)
		);
	}
}
