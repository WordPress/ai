<?php
/**
 * Plugin Activator logic.
 *
 * @package WordPress\AI\Experiments
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Plugin_Builder\Installer;

use WP_Error;

/**
 * Safely activates a generated plugin with fatal error detection.
 *
 * Uses register_shutdown_function to catch fatal errors during activation.
 *
 * @since x.x.x
 */
class PluginActivator {

	private const FATAL_OPTION_KEY = 'ai_plugin_builder_fatal_error';

	/**
	 * Activate a plugin by its relative path (e.g. "my-plugin/my-plugin.php").
	 *
	 * @since x.x.x
	 *
	 * @param string $plugin_path Relative path to the main plugin file.
	 * @return true|WP_Error
	 */
	public function activate( string $plugin_path ) {
		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Clear any previous fatal error record.
		delete_option( self::FATAL_OPTION_KEY );

		// Suppress display errors during activation.
		$old_display = ini_get( 'display_errors' );
		ini_set( 'display_errors', '0' ); // phpcs:ignore WordPress.PHP.IniSet

		// Register shutdown handler to catch fatal errors.
		register_shutdown_function(
			static function () use ( $plugin_path ) {
				$error = error_get_last();
				if ( ! $error || ! in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
					return;
				}

				update_option(
					self::FATAL_OPTION_KEY,
					array(
						'plugin'  => $plugin_path,
						'message' => $error['message'],
						'file'    => $error['file'],
						'line'    => $error['line'],
					)
				);
			}
		);

		$result = activate_plugin( $plugin_path );

		// Restore display errors.
		ini_set( 'display_errors', (string) $old_display ); // phpcs:ignore WordPress.PHP.IniSet

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}
}
