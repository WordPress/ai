<?php
/**
 * Detects the plugin, theme, or core code behind an AI request.
 *
 * @package WordPress\AI\Logging
 */

declare( strict_types=1 );

namespace WordPress\AI\Logging;

defined( 'ABSPATH' ) || exit;

/**
 * Attributes AI requests to the plugin, mu-plugin, theme, or core file that made them.
 *
 * @since x.x.x
 */
class Request_Source_Detector {

	/**
	 * Detects which plugin, theme, or core file initiated the current call.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $skip_hook_dispatch Whether to skip the WordPress hook dispatch files, for callers running inside an action.
	 * @return array<string, string>|null Source metadata.
	 */
	public function detect( bool $skip_hook_dispatch = false ): ?array {
		$frames = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Required to attribute the originating plugin/theme/core source for a request.

		foreach ( $frames as $frame ) {
			if ( empty( $frame['file'] ) || ! is_string( $frame['file'] ) ) {
				continue;
			}

			$file = wp_normalize_path( $frame['file'] );

			if ( $this->is_infrastructure_file( $file ) ) {
				continue;
			}

			if ( $skip_hook_dispatch && $this->is_hook_dispatch_file( $file ) ) {
				continue;
			}

			$source = $this->match_request_source( $file );

			if ( null !== $source ) {
				return $source;
			}
		}

		return null;
	}

	/**
	 * Checks whether a file is part of the WordPress hook dispatch.
	 *
	 * @since x.x.x
	 *
	 * @param string $file Normalized absolute file path.
	 * @return bool True for the files that run action and filter callbacks.
	 */
	private function is_hook_dispatch_file( string $file ): bool {
		$includes_dir = trailingslashit( wp_normalize_path( ABSPATH . 'wp-includes' ) );

		return in_array( $file, array( $includes_dir . 'class-wp-hook.php', $includes_dir . 'plugin.php' ), true );
	}

	/**
	 * Determine if a file is part of the infrastructure or the actual request originator.
	 *
	 * @since 1.0.0
	 *
	 * @param string $file Normalized absolute file path.
	 * @return bool
	 */
	private function is_infrastructure_file( string $file ): bool {
		$logging_dir = wp_normalize_path( __DIR__ ) . '/';

		if ( 0 === strpos( $file, $logging_dir ) || false !== strpos( $file, '/vendor/' ) ) {
			return true;
		}

		foreach ( $this->get_infrastructure_dirs() as $dir ) {
			if ( 0 === strpos( $file, $dir ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Directory prefixes treated as infrastructure for source attribution.
	 *
	 * @since 1.0.0
	 *
	 * @return list<string>
	 */
	private function get_infrastructure_dirs(): array {
		// Core AI client wrapper and bundled SDK.
		$dirs = array(
			wp_normalize_path( ABSPATH . 'wp-includes/php-ai-client' ) . '/',
			wp_normalize_path( ABSPATH . 'wp-includes/ai-client' ) . '/',
		);

		$plugins_dir = trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) );

		foreach ( wp_get_connectors() as $connector_data ) {
			if ( 'ai_provider' !== ( $connector_data['type'] ?? '' ) ) {
				continue;
			}

			$slug = $this->resolve_connector_plugin_slug( $connector_data );
			if ( '' === $slug ) {
				continue;
			}

			$dirs[] = $plugins_dir . $slug . '/';
		}

		return $dirs;
	}

	/**
	 * Pulls the plugin directory slug out of a connector's `plugin.file` value.
	 *
	 * The connector registry canonicalizes plugin metadata to `['file' => 'slug/main.php']`
	 * (see WP_Connector_Registry::register()), so we just take the first path segment.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $connector_data Connector definition.
	 * @return string Plugin slug, or empty string when none can be derived.
	 */
	private function resolve_connector_plugin_slug( array $connector_data ): string {
		$file = $connector_data['plugin']['file'] ?? '';

		if ( ! is_string( $file ) || '' === $file ) {
			return '';
		}

		return (string) ( explode( '/', $file )[0] ?? '' );
	}

	/**
	 * Matches a file path to a WordPress source type.
	 *
	 * @since 1.0.0
	 *
	 * @param string $file Normalized absolute file path.
	 * @return array<string, string>|null Source metadata.
	 */
	private function match_request_source( string $file ): ?array {
		$plugin_source = $this->match_plugin_source( $file );

		if ( null !== $plugin_source ) {
			return $plugin_source;
		}

		$mu_plugin_source = $this->match_mu_plugin_source( $file );

		if ( null !== $mu_plugin_source ) {
			return $mu_plugin_source;
		}

		$theme_source = $this->match_theme_source( $file );

		if ( null !== $theme_source ) {
			return $theme_source;
		}

		return $this->match_core_source( $file );
	}

	/**
	 * Matches plugin file paths.
	 *
	 * @since 1.0.0
	 *
	 * @param string $file Normalized absolute file path.
	 * @return array<string, string>|null Source metadata.
	 */
	private function match_plugin_source( string $file ): ?array {
		$plugins_dir = trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) );

		if ( 0 !== strpos( $file, $plugins_dir ) ) {
			return null;
		}

		$relative = substr( $file, strlen( $plugins_dir ) );
		$segments = explode( '/', $relative );
		$slug     = $segments[0] ?? '';

		if ( '' === $slug ) {
			return null;
		}

		return array(
			'type' => 'plugin',
			'slug' => $slug,
			'name' => 'ai' === $slug ? __( 'AI', 'ai' ) : $slug,
			'file' => $relative,
		);
	}

	/**
	 * Matches mu-plugin file paths.
	 *
	 * @since 1.0.0
	 *
	 * @param string $file Normalized absolute file path.
	 * @return array<string, string>|null Source metadata.
	 */
	private function match_mu_plugin_source( string $file ): ?array {
		$mu_plugins_dir = trailingslashit( wp_normalize_path( WPMU_PLUGIN_DIR ) );

		if ( 0 !== strpos( $file, $mu_plugins_dir ) ) {
			return null;
		}

		$relative = substr( $file, strlen( $mu_plugins_dir ) );
		$segments = explode( '/', $relative );
		$slug     = $segments[0] ?? '';

		if ( '' === $slug ) {
			return null;
		}

		$name = preg_replace( '/\.php$/', '', $slug );

		if ( ! is_string( $name ) || '' === $name ) {
			$name = $slug;
		}

		return array(
			'type' => 'mu-plugin',
			'slug' => $name,
			'name' => $name,
			'file' => $relative,
		);
	}

	/**
	 * Matches theme file paths.
	 *
	 * @since 1.0.0
	 *
	 * @param string $file Normalized absolute file path.
	 * @return array<string, string>|null Source metadata.
	 */
	private function match_theme_source( string $file ): ?array {
		$themes_dir = trailingslashit( wp_normalize_path( get_theme_root() ) );

		if ( 0 !== strpos( $file, $themes_dir ) ) {
			return null;
		}

		$relative = substr( $file, strlen( $themes_dir ) );
		$segments = explode( '/', $relative );
		$slug     = $segments[0] ?? '';

		if ( '' === $slug ) {
			return null;
		}

		$theme = wp_get_theme( $slug );

		return array(
			'type' => 'theme',
			'slug' => $slug,
			'name' => $theme->exists() ? $theme->get( 'Name' ) : $slug,
			'file' => $relative,
		);
	}

	/**
	 * Matches core WordPress file paths.
	 *
	 * @since 1.0.0
	 *
	 * @param string $file Normalized absolute file path.
	 * @return array<string, string>|null Source metadata.
	 */
	private function match_core_source( string $file ): ?array {
		$core_directories = array(
			trailingslashit( wp_normalize_path( ABSPATH . 'wp-admin' ) ),
			trailingslashit( wp_normalize_path( ABSPATH . 'wp-includes' ) ),
		);

		foreach ( $core_directories as $core_directory ) {
			if ( 0 !== strpos( $file, $core_directory ) ) {
				continue;
			}

			return array(
				'type' => 'core',
				'slug' => 'wordpress',
				'name' => __( 'WordPress Core', 'ai' ),
				'file' => substr( $file, strlen( trailingslashit( wp_normalize_path( ABSPATH ) ) ) ),
			);
		}

		return null;
	}
}
