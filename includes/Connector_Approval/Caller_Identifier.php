<?php
/**
 * Identifies the originating plugin, mu-plugin, or theme of a call.
 *
 * @package WordPress\AI\Connector_Approval
 */

declare( strict_types=1 );

namespace WordPress\AI\Connector_Approval;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Walks the call stack to identify which extension initiated the current execution.
 *
 * Used by the option guard to decide whether to honor a credential read. The
 * returned `basename` is shaped like `plugin-slug/plugin-file.php` for plugins
 * (matching `plugin_basename()` and `wp_get_connectors()[$id]['plugin']['file']`)
 * so callers can compare directly against the connector registry without further
 * normalization.
 *
 * @since x.x.x
 */
final class Caller_Identifier {
	/**
	 * Caller type for regular plugins.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const TYPE_PLUGIN = 'plugin';

	/**
	 * Caller type for must-use plugins.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const TYPE_MU_PLUGIN = 'mu-plugin';

	/**
	 * Caller type for themes.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const TYPE_THEME = 'theme';

	/**
	 * Caller type for WordPress core.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const TYPE_CORE = 'core';

	/**
	 * Per-request memoization of caller lookups keyed by a backtrace fingerprint.
	 *
	 * @since x.x.x
	 *
	 * @var array<string, array{type: string, basename: string, name: string}|null>
	 */
	private array $cache = array();

	/**
	 * Substrings that indicate a stack frame is part of the enforcement plumbing itself.
	 *
	 * @since x.x.x
	 *
	 * @var list<string>
	 */
	private array $skip_substrings;

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 */
	public function __construct() {
		$this->skip_substrings = array(
			'/wp-includes/option.php',
			'/wp-includes/class-wp-hook.php',
			'/wp-includes/plugin.php',
			'/wp-includes/connectors.php',
			'/wp-includes/class-wp-connector-registry.php',
			DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'Connector_Approval' . DIRECTORY_SEPARATOR,
		);
	}

	/**
	 * Identifies the current caller.
	 *
	 * @since x.x.x
	 *
	 * @return array{type: string, basename: string, name: string}|null
	 *     `null` when no plugin, mu-plugin, or theme frame could be found.
	 */
	public function identify(): ?array {
		// phpcs:ignore PHPCompatibility.FunctionUse.ArgumentFunctionsUsage.DEBUG_BACKTRACE_IGNORE_ARGS
		$frames      = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
		$fingerprint = $this->fingerprint( $frames );

		if ( array_key_exists( $fingerprint, $this->cache ) ) {
			return $this->cache[ $fingerprint ];
		}

		$result                      = $this->resolve( $frames );
		$this->cache[ $fingerprint ] = $result;

		return $result;
	}

	/**
	 * Builds a stable key for the cache from the backtrace file+line sequence.
	 *
	 * @since x.x.x
	 *
	 * @param array<int, array<string, mixed>> $frames Raw backtrace frames.
	 * @return string
	 */
	private function fingerprint( array $frames ): string {
		$parts = array();
		foreach ( $frames as $frame ) {
			$file    = isset( $frame['file'] ) && is_string( $frame['file'] ) ? $frame['file'] : '';
			$line    = isset( $frame['line'] ) ? (int) $frame['line'] : 0;
			$parts[] = $file . ':' . $line;
		}

		return md5( implode( '|', $parts ) );
	}

	/**
	 * Finds the first stack frame that belongs to an extension and describes it.
	 *
	 * @since x.x.x
	 *
	 * @param array<int, array<string, mixed>> $frames Raw backtrace frames.
	 * @return array{type: string, basename: string, name: string}|null
	 */
	private function resolve( array $frames ): ?array {
		foreach ( $frames as $frame ) {
			$file = isset( $frame['file'] ) && is_string( $frame['file'] ) ? $frame['file'] : '';
			if ( '' === $file ) {
				continue;
			}

			if ( $this->should_skip( $file ) ) {
				continue;
			}

			$extension = $this->classify_file( $file );
			if ( null !== $extension ) {
				return $extension;
			}
		}

		return null;
	}

	/**
	 * Checks whether a file path is part of the enforcement or core plumbing.
	 *
	 * @since x.x.x
	 *
	 * @param string $file Absolute file path.
	 * @return bool
	 */
	private function should_skip( string $file ): bool {
		$normalized = wp_normalize_path( $file );
		foreach ( $this->skip_substrings as $needle ) {
			if ( false !== strpos( $normalized, wp_normalize_path( $needle ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Attempts to map a file path to a plugin, mu-plugin, or theme.
	 *
	 * @since x.x.x
	 *
	 * @param string $file Absolute file path.
	 * @return array{type: string, basename: string, name: string}|null
	 */
	private function classify_file( string $file ): ?array {
		$normalized = wp_normalize_path( $file );

		$plugin = $this->match_directory( $normalized, wp_normalize_path( WP_PLUGIN_DIR ) );
		if ( null !== $plugin ) {
			return array(
				'type'     => self::TYPE_PLUGIN,
				'basename' => $plugin,
				'name'     => $this->plugin_name( $plugin ),
			);
		}

		if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
			$mu = $this->match_directory( $normalized, wp_normalize_path( WPMU_PLUGIN_DIR ) );
			if ( null !== $mu ) {
				return array(
					'type'     => self::TYPE_MU_PLUGIN,
					'basename' => $mu,
					'name'     => $mu,
				);
			}
		}

		$theme = $this->match_theme( $normalized );
		if ( null !== $theme ) {
			return $theme;
		}

		return null;
	}

	/**
	 * Returns the basename of a file that lives under a given base directory, or null.
	 *
	 * For plugins this is `slug/file.php` when the file is inside a plugin
	 * directory, or just `file.php` when the plugin is a single-file plugin
	 * placed directly in the plugins directory.
	 *
	 * @since x.x.x
	 *
	 * @param string $file     Normalized absolute file path.
	 * @param string $base_dir Normalized base directory.
	 * @return string|null
	 */
	private function match_directory( string $file, string $base_dir ): ?string {
		$base_dir = rtrim( $base_dir, '/' ) . '/';
		if ( 0 !== strpos( $file, $base_dir ) ) {
			return null;
		}

		$relative = substr( $file, strlen( $base_dir ) );
		if ( '' === $relative ) {
			return null;
		}

		$segments = explode( '/', $relative );
		if ( count( $segments ) === 1 ) {
			return $segments[0];
		}

		return $segments[0];
	}

	/**
	 * Returns the best human-readable plugin name for a given basename.
	 *
	 * Falls back to the basename if `get_plugins()` has no metadata for it.
	 *
	 * @since x.x.x
	 *
	 * @param string $basename Plugin basename.
	 * @return string
	 */
	private function plugin_name( string $basename ): string {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = get_plugins();
		if ( isset( $plugins[ $basename ]['Name'] ) && '' !== $plugins[ $basename ]['Name'] ) {
			return (string) $plugins[ $basename ]['Name'];
		}

		foreach ( $plugins as $plugin_basename => $plugin_data ) {
			if ( 0 === strpos( (string) $plugin_basename, dirname( $basename ) . '/' ) ) {
				return (string) ( $plugin_data['Name'] ?? $basename );
			}
		}

		return $basename;
	}

	/**
	 * Attempts to classify a file as belonging to a theme.
	 *
	 * @since x.x.x
	 *
	 * @param string $file Normalized absolute file path.
	 * @return array{type: string, basename: string, name: string}|null
	 */
	private function match_theme( string $file ): ?array {
		foreach ( (array) get_theme_roots() as $root ) {
			if ( ! is_string( $root ) || '' === $root ) {
				continue;
			}

			$theme_root = wp_normalize_path( trailingslashit( WP_CONTENT_DIR . $root ) );
			$slug       = $this->match_directory( $file, $theme_root );
			if ( null === $slug ) {
				continue;
			}

			$theme = wp_get_theme( $slug );
			$name  = $theme->exists() ? (string) $theme->get( 'Name' ) : $slug;

			return array(
				'type'     => self::TYPE_THEME,
				'basename' => $slug,
				'name'     => '' !== $name ? $name : $slug,
			);
		}

		return null;
	}

	/**
	 * Clears the per-request cache. Primarily used in tests.
	 *
	 * @since x.x.x
	 *
	 * @return void
	 */
	public function reset_cache(): void {
		$this->cache = array();
	}
}
