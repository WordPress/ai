<?php
/**
 * Auto-installer for the MCP Adapter companion plugin.
 *
 * @package WordPress\AI\Experiments\MCP_Adapter
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\MCP_Adapter;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Installs and activates the MCP Adapter plugin while the experiment is enabled.
 *
 * Enabling the MCP Access experiment is meant to wire the site up to the
 * companion plugin without a separate install chore. Because the experiment
 * framework only runs enabled experiments, the attempt happens on the first
 * admin page load after enabling (rather than on the option change itself,
 * which fires in a request where this code is not yet hooked).
 *
 * A failed attempt is locked for an hour so admin pages are not slowed down
 * by repeated download attempts; the MCP Access screen's manual button
 * remains available as the immediate retry path.
 *
 * @since 0.9.0
 */
class Plugin_Installer {
	/**
	 * Transient locking further automatic attempts after a failure.
	 *
	 * @since 0.9.0
	 * @var string
	 */
	public const LOCK_TRANSIENT = 'wpai_mcp_adapter_autoinstall_lock';

	/**
	 * Hooks the automatic install attempt into admin page loads.
	 *
	 * @since 0.9.0
	 */
	public function init(): void {
		add_action( 'admin_init', array( $this, 'maybe_install_and_activate' ) );
	}

	/**
	 * Installs and activates the companion plugin when needed and allowed.
	 *
	 * Silently does nothing when the plugin is already active, the current
	 * user lacks the required capabilities, or a recent attempt failed.
	 *
	 * @since 0.9.0
	 */
	public function maybe_install_and_activate(): void {
		$state = self::get_state();

		if ( 'active' === $state['status'] ) {
			return;
		}

		$capable = 'missing' === $state['status']
			? $state['can_install'] && $state['can_activate']
			: $state['can_activate'];

		if ( ! $capable ) {
			return;
		}

		if ( false !== get_transient( self::LOCK_TRANSIENT ) ) {
			return;
		}

		$result = $this->install_and_activate( $state );

		if ( ! is_wp_error( $result ) ) {
			return;
		}

		set_transient( self::LOCK_TRANSIENT, $result->get_error_message(), HOUR_IN_SECONDS );
	}

	/**
	 * Performs the actual install and/or activation.
	 *
	 * @since 0.9.0
	 *
	 * @param array<string, mixed> $state The plugin state, see {@see self::get_state()}.
	 *
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	protected function install_and_activate( array $state ) {
		/**
		 * Short-circuits the automatic install of the MCP Adapter plugin.
		 *
		 * Return true to report success or a WP_Error to report failure
		 * without touching the filesystem. Used in tests and available to
		 * hosts that manage plugins externally.
		 *
		 * @since 0.9.0
		 *
		 * @param true|\WP_Error|null  $pre   The short-circuit result. Default null (proceed).
		 * @param array<string, mixed> $state The plugin state.
		 */
		$pre = apply_filters( 'wpai_pre_mcp_adapter_autoinstall', null, $state );
		if ( null !== $pre ) {
			return $pre;
		}

		$file = $state['file'];

		if ( 'missing' === $state['status'] ) {
			$file = $this->download_and_install( $state['slug'] );

			if ( is_wp_error( $file ) ) {
				return $file;
			}
		}

		if ( ! is_string( $file ) || '' === $file ) {
			return new WP_Error( 'wpai_mcp_install_failed', __( 'The plugin file could not be determined after installation.', 'ai' ) );
		}

		$activated = activate_plugin( $file );

		return is_wp_error( $activated ) ? $activated : true;
	}

	/**
	 * Downloads and installs the plugin from WordPress.org.
	 *
	 * @since 0.9.0
	 *
	 * @param string $slug The plugin slug.
	 *
	 * @return string|\WP_Error The installed plugin file, or WP_Error on failure.
	 */
	private function download_and_install( string $slug ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

		$api = plugins_api(
			'plugin_information',
			array(
				'slug'   => $slug,
				'fields' => array( 'sections' => false ),
			)
		);

		if ( is_wp_error( $api ) ) {
			return $api;
		}

		$upgrader  = new \Plugin_Upgrader( new \Automatic_Upgrader_Skin() );
		$installed = $upgrader->install( $api->download_link );

		if ( is_wp_error( $installed ) ) {
			return $installed;
		}

		if ( true !== $installed ) {
			return new WP_Error( 'wpai_mcp_install_failed', __( 'The plugin could not be installed.', 'ai' ) );
		}

		$file = $upgrader->plugin_info();

		if ( ! is_string( $file ) || '' === $file ) {
			return new WP_Error( 'wpai_mcp_install_failed', __( 'The plugin file could not be determined after installation.', 'ai' ) );
		}

		return $file;
	}

	/**
	 * Describes the companion plugin's install state for the current site.
	 *
	 * @since 0.9.0
	 *
	 * @return array{slug: string, status: string, file: string|null, can_install: bool, can_activate: bool} The plugin state.
	 */
	public static function get_state(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		/**
		 * Filters the WordPress.org slug of the MCP Adapter companion plugin.
		 *
		 * Useful for testing the install flow against a stand-in plugin while
		 * the adapter is not yet published on WordPress.org.
		 *
		 * @since 0.9.0
		 *
		 * @param string $slug The plugin slug.
		 */
		$slug = apply_filters( 'wpai_mcp_adapter_plugin_slug', 'mcp-adapter' );

		$file   = null;
		$status = 'missing';
		foreach ( array_keys( get_plugins() ) as $plugin_file ) {
			if ( 0 !== strpos( $plugin_file, $slug . '/' ) ) {
				continue;
			}

			$file   = $plugin_file;
			$status = is_plugin_active( $plugin_file ) ? 'active' : 'installed';
			break;
		}

		$last_error = get_transient( self::LOCK_TRANSIENT );

		return array(
			'slug'              => $slug,
			'status'            => $status,
			'file'              => $file,
			// DISALLOW_FILE_MODS already strips install_plugins via map_meta_cap.
			'can_install'       => current_user_can( 'install_plugins' ),
			'can_activate'      => current_user_can( 'activate_plugins' ),
			'autoinstall_error' => is_string( $last_error ) ? $last_error : null,
		);
	}
}
