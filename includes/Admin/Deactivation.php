<?php
/**
 * Runs on plugin deactivation.
 *
 * @package WordPress\AI\Admin
 * @since 1.1.0
 */

declare( strict_types=1 );

namespace WordPress\AI\Admin;

use WordPress\AI\Experiments\Key_Encryption\Key_Decryption_Exception;
use WordPress\AI\Experiments\Key_Encryption\Key_Encryption;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Deactivation routines.
 *
 * @internal
 *
 * @since 1.1.0
 */
final class Deactivation {
	/**
	 * Runs on plugin deactivation.
	 *
	 * Reverses the Key Encryption experiment when it is
	 * currently enabled so the user is never locked out of
	 * their API keys after deactivating the plugin. On a network-wide
	 * deactivation this is done for every site in the network.
	 *
	 * When a key cannot be decrypted, the deactivation is stopped with a message
	 * that names it. Nothing can read that key once the plugin is inactive.
	 *
	 * @since 1.1.0
	 * @since x.x.x Added the `$network_wide` parameter.
	 *
	 * @param bool|null $network_wide Whether the plugin is being deactivated for the whole network.
	 *                                WordPress can pass null here, which is treated as false.
	 */
	public static function deactivation_callback( ?bool $network_wide = false ): void {
		$network_wide = true === $network_wide;

		$errors = Key_Encryption::for_each_site(
			$network_wide,
			static function (): void {
				self::restore_plaintext_keys();
			}
		);

		if ( array() === $errors ) {
			return;
		}

		// The plugin stays active, so the keys that were restored above are flagged
		// to be encrypted again on the next request.
		Key_Encryption::for_each_site( $network_wide, array( Key_Encryption::class, 'flag_resume_migration' ) );

		wp_die(
			self::get_failure_message( $errors ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in get_failure_message().
			esc_html__( 'The AI plugin was not deactivated', 'ai' ),
			array(
				'response'  => 500,
				'back_link' => true,
			)
		);
	}

	/**
	 * Restores the current site's plaintext keys when Key Encryption is enabled on it.
	 *
	 * @since x.x.x
	 */
	private static function restore_plaintext_keys(): void {
		if ( ! Key_Encryption::is_effectively_enabled() ) {
			return;
		}

		Key_Encryption::get_bridge()->decrypt_all();
	}

	/**
	 * Builds the message shown when API keys could not be restored.
	 *
	 * @since x.x.x
	 *
	 * @param array<int, \Throwable> $errors What went wrong, keyed by site ID.
	 * @return string The message as escaped HTML.
	 */
	private static function get_failure_message( array $errors ): string {
		$items = '';

		foreach ( $errors as $site_id => $error ) {
			$keys = $error instanceof Key_Decryption_Exception
				? implode( ', ', array_map( array( self::class, 'get_connector_name' ), $error->get_connector_ids() ) )
				: $error->getMessage();

			$items .= '<li>' . esc_html( is_multisite() ? get_site_url( $site_id ) . ': ' . $keys : $keys ) . '</li>';
		}

		return '<p>' . esc_html__( 'The AI plugin was not deactivated because these API keys could not be decrypted:', 'ai' ) . '</p>'
			. '<ul>' . $items . '</ul>'
			. '<p>' . esc_html__( 'Remove these keys under Settings > Connectors and enter them again, then deactivate the plugin.', 'ai' ) . '</p>';
	}

	/**
	 * Returns the display name of a connector.
	 *
	 * @since x.x.x
	 *
	 * @param string $connector_id The connector ID.
	 * @return string The connector name, or its ID when the connector is not registered.
	 */
	private static function get_connector_name( string $connector_id ): string {
		$connector = wp_get_connectors()[ $connector_id ] ?? array();

		return isset( $connector['name'] ) && is_string( $connector['name'] ) ? $connector['name'] : $connector_id;
	}
}
