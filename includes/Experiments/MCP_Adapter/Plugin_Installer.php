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
 * Installs and activates the MCP Adapter plugin once per experiment enable.
 *
 * The attempt runs on the first admin page load after enabling (the
 * experiment framework does not run disabled experiments, so the enabling
 * request itself cannot host it) and only once per enable cycle, so a
 * deliberate deactivation of the plugin sticks. Disabling the experiment
 * re-arms the attempt.
 *
 * @since x.x.x
 */
class Plugin_Installer {
	/**
	 * Option marking the current enable cycle's attempt as done.
	 *
	 * Holds '1' after a successful attempt, or the error message after a
	 * failed one. Cleared when the experiment is disabled, so the next
	 * enable runs a fresh attempt.
	 *
	 * @since x.x.x
	 * @var string
	 */
	public const HANDLED_OPTION = 'wpai_mcp_adapter_autoinstall_handled';

	/**
	 * Hooks the automatic install attempt into admin page loads.
	 *
	 * @since x.x.x
	 */
	public function init(): void {
		add_action( 'admin_init', array( $this, 'maybe_install_and_activate' ) );

		// The disable transition is only observable while this code is loaded.
		add_action( 'update_option_' . self::experiment_option_name(), array( $this, 'maybe_reset_on_disable' ), 10, 2 );
		add_action( 'delete_option_' . self::experiment_option_name(), array( $this, 'reset_handled' ) );
	}

	/**
	 * Runs the once-per-enable install attempt when needed and allowed.
	 *
	 * Does nothing when this enable cycle was already handled, when the
	 * plugin is already active, or when the current user lacks the required
	 * capabilities (an incapable visit does not consume the attempt).
	 *
	 * @since x.x.x
	 */
	public function maybe_install_and_activate(): void {
		$handled = get_option( self::HANDLED_OPTION );

		if ( false !== $handled ) {
			if ( ! self::is_stale_claim( $handled ) ) {
				return;
			}

			// A claim left behind by a crashed attempt must not stick forever.
			delete_option( self::HANDLED_OPTION );
		}

		// A blocking download has no place inside AJAX/heartbeat requests.
		if ( wp_doing_ajax() ) {
			return;
		}

		// Activation is the minimum capability for either path; bail before the disk scan.
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$state = self::get_state();

		// A copy may live outside a slug directory (e.g. a GitHub zip); loaded classes mean nothing to do.
		if ( 'active' === $state['status'] || ( 'mcp-adapter' === $state['slug'] && class_exists( '\WP\MCP\Core\McpAdapter' ) ) ) {
			update_option( self::HANDLED_OPTION, '1', false );
			return;
		}

		$capable = 'missing' === $state['status']
			? $state['can_install'] && $state['can_activate']
			: $state['can_activate'];

		if ( ! $capable ) {
			return;
		}

		// add_option() fails if the row exists, so concurrent requests cannot start a second upgrader.
		if ( ! add_option( self::HANDLED_OPTION, 'running:' . time(), '', false ) ) {
			return;
		}

		$result = $this->install_and_activate( $state );

		update_option( self::HANDLED_OPTION, $this->result_marker( $result ), false );
	}

	/**
	 * Checks whether a handled marker is an expired in-flight claim.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $handled The stored marker.
	 *
	 * @return bool Whether the marker is a claim old enough to assume a crash.
	 */
	private static function is_stale_claim( $handled ): bool {
		if ( ! is_string( $handled ) || 0 !== strpos( $handled, 'running:' ) ) {
			return false;
		}

		return time() - (int) substr( $handled, strlen( 'running:' ) ) > 5 * MINUTE_IN_SECONDS;
	}

	/**
	 * Converts an attempt result into the stored handled marker.
	 *
	 * @since x.x.x
	 *
	 * @param true|\WP_Error $result The attempt result.
	 *
	 * @return string '1' on success, otherwise a non-empty error message.
	 */
	private function result_marker( $result ): string {
		if ( ! is_wp_error( $result ) ) {
			return '1';
		}

		$message = $result->get_error_message();

		if ( '' === $message || '1' === $message ) {
			$message = (string) $result->get_error_code();
		}

		return '' !== $message && '1' !== $message ? $message : __( 'The installation failed for an unknown reason.', 'ai' );
	}

	/**
	 * Resets the handled marker when an enabling option is toggled off.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $old_value The previous option value.
	 * @param mixed $value     The new option value.
	 */
	public function maybe_reset_on_disable( $old_value, $value ): void {
		if ( $value ) {
			return;
		}

		$this->reset_handled();
	}

	/**
	 * Deletes the handled marker, re-arming the install attempt.
	 *
	 * @since x.x.x
	 */
	public function reset_handled(): void {
		delete_option( self::HANDLED_OPTION );
	}

	/**
	 * Returns the option name holding the experiment's enabled state.
	 *
	 * @since x.x.x
	 *
	 * @return string The option name.
	 */
	private static function experiment_option_name(): string {
		return 'wpai_feature_' . MCP_Adapter::get_id() . '_enabled';
	}

	/**
	 * Performs the actual install and/or activation.
	 *
	 * @since x.x.x
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
		 * @since x.x.x
		 *
		 * @param true|\WP_Error|null  $pre   The short-circuit result. Default null (proceed).
		 * @param array<string, mixed> $state The plugin state.
		 */
		$pre = apply_filters( 'wpai_pre_mcp_adapter_autoinstall', null, $state );
		if ( null !== $pre ) {
			if ( true === $pre || is_wp_error( $pre ) ) {
				return $pre;
			}

			// Any other value (e.g. __return_false) blocks the install; never report it as success.
			return new WP_Error( 'wpai_mcp_autoinstall_blocked', __( 'The automatic installation was blocked by a filter.', 'ai' ) );
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
	 * @since x.x.x
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

		$download_link = is_object( $api ) ? ( $api->download_link ?? '' ) : ( $api['download_link'] ?? '' );

		if ( ! is_string( $download_link ) || '' === $download_link ) {
			return new WP_Error( 'wpai_mcp_install_failed', __( 'The plugin download link could not be determined.', 'ai' ) );
		}

		$upgrader  = new \Plugin_Upgrader( new \Automatic_Upgrader_Skin() );
		$installed = $upgrader->install( $download_link );

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
	 * Checks whether a plugin file belongs to the companion plugin.
	 *
	 * Deliberately narrow (slug directory or root-level slug file): matching
	 * arbitrary directories by main-file name could activate an unrelated
	 * lookalike plugin.
	 *
	 * @since x.x.x
	 *
	 * @param string $plugin Path to the plugin file relative to the plugins directory.
	 * @param string $slug   The plugin slug.
	 *
	 * @return bool Whether the file belongs to the companion plugin.
	 */
	private static function is_companion_plugin_file( string $plugin, string $slug ): bool {
		return 0 === strpos( $plugin, $slug . '/' ) || $plugin === $slug . '.php';
	}

	/**
	 * Describes the companion plugin's install state for the current site.
	 *
	 * @since x.x.x
	 *
	 * @return array{slug: string, status: 'active'|'installed'|'missing', file: string|null, can_install: bool, can_activate: bool, autoinstall_error: string|null, autoinstall_handled: bool} The plugin state.
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
		 * @since x.x.x
		 *
		 * @param string $slug The plugin slug.
		 */
		$slug = apply_filters( 'wpai_mcp_adapter_plugin_slug', 'mcp-adapter' );

		$file   = null;
		$status = 'missing';
		foreach ( array_keys( get_plugins() ) as $plugin_file ) {
			if ( ! self::is_companion_plugin_file( (string) $plugin_file, $slug ) ) {
				continue;
			}

			$file   = (string) $plugin_file;
			$status = is_plugin_active( $file ) ? 'active' : 'installed';

			// Prefer an active copy so a duplicate is never activated alongside it.
			if ( 'active' === $status ) {
				break;
			}
		}

		$handled = get_option( self::HANDLED_OPTION );

		// An in-flight claim ('running:<timestamp>') is not a failure.
		$error = is_string( $handled ) && '1' !== $handled && 0 !== strpos( $handled, 'running:' ) ? $handled : null;

		return array(
			'slug'                => $slug,
			'status'              => $status,
			'file'                => $file,
			// DISALLOW_FILE_MODS already strips install_plugins via map_meta_cap.
			'can_install'         => current_user_can( 'install_plugins' ),
			'can_activate'        => current_user_can( 'activate_plugins' ),
			'autoinstall_error'   => 'active' === $status ? null : $error,
			'autoinstall_handled' => false !== $handled,
		);
	}
}
