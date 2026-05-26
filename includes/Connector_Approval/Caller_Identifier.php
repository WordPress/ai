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
 * @since 1.0.0
 */
final class Caller_Identifier {
	/**
	 * Caller type for regular plugins.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const TYPE_PLUGIN = 'plugin';

	/**
	 * Caller type for must-use plugins.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const TYPE_MU_PLUGIN = 'mu-plugin';

	/**
	 * Caller type for themes.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const TYPE_THEME = 'theme';

	/**
	 * Caller type for WordPress core.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const TYPE_CORE = 'core';

	/**
	 * Per-request memoization of caller lookups keyed by a backtrace fingerprint.
	 *
	 * @since 1.0.0
	 *
	 * @var array<string, array{type: string, basename: string, name: string}|null>
	 */
	private array $cache = array();

	/**
	 * Path prefixes that indicate a stack frame is infrastructure rather than a request origin.
	 *
	 * @since 1.0.0
	 *
	 * @var list<string>
	 */
	private array $skip_prefixes;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$wp_includes = defined( 'WPINC' ) ? constant( 'WPINC' ) : 'wp-includes';
		if ( ! is_string( $wp_includes ) ) {
			$wp_includes = 'wp-includes';
		}

		$core   = wp_normalize_path( ABSPATH . $wp_includes ) . '/';
		$plugin = wp_normalize_path( dirname( __DIR__ ) ) . '/';

		$this->skip_prefixes = array(
			$core . 'option.php',
			$core . 'class-wp-hook.php',
			$core . 'plugin.php',
			$core . 'connectors.php',
			$core . 'class-wp-connector-registry.php',
			$core . 'http.php',
			$core . 'class-wp-http.php',
			$core . 'class-http.php',
			$core . 'class-wp-http-requests-hooks.php',
			$core . 'Requests/',
			$core . 'class-requests.php',
			$core . 'ai-client/',
			$core . 'php-ai-client/',
			$plugin . 'Connector_Approval/',
			$plugin . 'Logging/',
			$plugin . 'Settings/',
			$plugin . 'helpers.php',
		);
	}

	/**
	 * Identifies the current caller.
	 *
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * Finds the deepest stack frame that belongs to an extension and describes it.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, array<string, mixed>> $frames Raw backtrace frames.
	 * @return array{type: string, basename: string, name: string}|null
	 */
	private function resolve( array $frames ): ?array {
		$origin = null;

		foreach ( $frames as $frame ) {
			$file = isset( $frame['file'] ) && is_string( $frame['file'] ) ? $frame['file'] : '';
			if ( '' === $file ) {
				continue;
			}

			if ( $this->should_skip( $file ) ) {
				continue;
			}

			$extension = $this->classify_file( $file );
			if ( null === $extension ) {
				continue;
			}

			$origin = $extension;
		}

		return $origin;
	}

	/**
	 * Checks whether a file path is part of the enforcement or core plumbing.
	 *
	 * @since 1.0.0
	 *
	 * @param string $file Absolute file path.
	 * @return bool
	 */
	private function should_skip( string $file ): bool {
		$normalized = wp_normalize_path( $file );
		foreach ( $this->skip_prefixes as $prefix ) {
			if ( str_starts_with( $normalized, wp_normalize_path( $prefix ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Attempts to map a file path to a plugin, mu-plugin, or theme.
	 *
	 * @since 1.0.0
	 *
	 * @param string $file Absolute file path.
	 * @return array{type: string, basename: string, name: string}|null
	 */
	private function classify_file( string $file ): ?array {
		$normalized      = wp_normalize_path( $file );
		$plugin_base_dir = wp_normalize_path( WP_PLUGIN_DIR );

		$plugin_segment = $this->match_slug( $normalized, $plugin_base_dir );
		if ( null !== $plugin_segment ) {
			$plugin_relative = ltrim( substr( $normalized, strlen( rtrim( $plugin_base_dir, '/' ) . '/' ) ), '/' );
			$basename        = $this->resolve_plugin_basename( $plugin_segment, $plugin_relative );
			return array(
				'type'     => self::TYPE_PLUGIN,
				'basename' => $basename,
				'name'     => $this->plugin_name( $basename ),
			);
		}

		if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
			$mu = $this->match_slug( $normalized, wp_normalize_path( WPMU_PLUGIN_DIR ) );
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
	 * Returns the first path segment of a file that lives under a given base directory.
	 *
	 * For files inside a plugin or theme subdirectory this is the slug
	 * (e.g. `ai` for `.../plugins/ai/includes/foo.php`). For a single file
	 * placed directly in the base directory this is that filename.
	 *
	 * @since 1.0.0
	 *
	 * @param string $file     Normalized absolute file path.
	 * @param string $base_dir Normalized base directory.
	 * @return string|null Returns the first path segment, or null if the file
	 *                     is outside the base directory.
	 */
	private function match_slug( string $file, string $base_dir ): ?string {
		$base_dir = rtrim( $base_dir, '/' ) . '/';
		if ( ! str_starts_with( $file, $base_dir ) ) {
			return null;
		}

		$relative = substr( $file, strlen( $base_dir ) );
		if ( '' === $relative ) {
			return null;
		}

		$segments = explode( '/', $relative );

		return $segments[0];
	}

	/**
	 * Resolves a plugin path segment to a canonical plugin basename.
	 *
	 * `get_plugins()` keys are of the form `slug/main-file.php` for directory
	 * plugins and `plugin.php` for single-file plugins. When the caller lives
	 * inside a plugin directory we only know the slug from the backtrace, so
	 * we look up the registered plugins that match. If the frame's own
	 * relative path happens to be a registered basename (a directory with
	 * multiple plugin header files is legal), that exact basename is
	 * preferred so attribution is deterministic. Otherwise the first
	 * registered plugin whose basename starts with the slug is returned.
	 * When the caller is a single-file plugin the segment already is the
	 * basename.
	 *
	 * @since 1.0.0
	 *
	 * @param string $segment       First path segment under `WP_PLUGIN_DIR`.
	 * @param string $relative_path Path of the caller relative to `WP_PLUGIN_DIR` (e.g. `slug/includes/foo.php`).
	 * @return string The canonical plugin basename, or `$segment` when no match is found.
	 */
	private function resolve_plugin_basename( string $segment, string $relative_path = '' ): string {
		// Single-file plugin at the root of WP_PLUGIN_DIR.
		if ( '' !== pathinfo( $segment, PATHINFO_EXTENSION ) ) {
			return $segment;
		}

		$plugins = $this->load_plugins();

		// Prefer the plugin basename that matches the caller's own file, so a
		// directory with multiple plugin header files attributes deterministically.
		if ( '' !== $relative_path && isset( $plugins[ $relative_path ] ) ) {
			return $relative_path;
		}

		$prefix = $segment . '/';
		foreach ( $plugins as $plugin_basename => $_plugin_data ) {
			if ( str_starts_with( (string) $plugin_basename, $prefix ) ) {
				return (string) $plugin_basename;
			}
		}

		return $segment;
	}

	/**
	 * Returns the best human-readable plugin name for a given basename.
	 *
	 * Falls back to the basename if `get_plugins()` has no metadata for it.
	 *
	 * @since 1.0.0
	 *
	 * @param string $basename Plugin basename.
	 * @return string
	 */
	private function plugin_name( string $basename ): string {
		$plugins = $this->load_plugins();
		if ( isset( $plugins[ $basename ]['Name'] ) && '' !== $plugins[ $basename ]['Name'] ) {
			return (string) $plugins[ $basename ]['Name'];
		}

		return $basename;
	}

	/**
	 * Loads the plugin registry, requiring WP's plugin admin bootstrap if needed.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function load_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return get_plugins();
	}

	/**
	 * Attempts to classify a file as belonging to a theme.
	 *
	 * @since 1.0.0
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
			$slug       = $this->match_slug( $file, $theme_root );
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
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function reset_cache(): void {
		$this->cache = array();
	}
}
