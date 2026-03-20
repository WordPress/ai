<?php
/**
 * Plugin Writer logic.
 *
 * @package WordPress\AI\Experiments
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Plugin_Builder\Installer;

use WP_Error;

/**
 * Writes generated plugin files to wp-content/plugins using WP_Filesystem.
 *
 * @since x.x.x
 */
class PluginWriter {

	/**
	 * Write all files for a generated plugin.
	 *
	 * @since x.x.x
	 *
	 * @param string               $plugin_slug Plugin slug (serves as the directory name).
	 * @param array<int, array<string, mixed>> $files       Array of file associative arrays (must contain "path" and "content").
	 *
	 * @return array{main_file: string}|\WP_Error  The relative plugin path (slug/main.php) on success.
	 */
	public function write( string $plugin_slug, array $files ) {
		$filesystem = $this->init_filesystem();
		if ( is_wp_error( $filesystem ) ) {
			return $filesystem;
		}

		$plugins_dir = $filesystem->wp_plugins_dir();
		if ( ! $plugins_dir ) {
			return new WP_Error( 'no_plugins_dir', 'Could not determine plugins directory.' );
		}

		$plugin_dir = trailingslashit( $plugins_dir ) . $plugin_slug;

		// Create plugin directory.
		if ( ! $filesystem->is_dir( $plugin_dir ) ) {
			if ( ! $filesystem->mkdir( $plugin_dir, FS_CHMOD_DIR ) ) {
				return new WP_Error(
					'mkdir_failed',
					sprintf( 'Could not create directory: %s', $plugin_dir )
				);
			}
		}

		$main_file = '';

		foreach ( $files as $file ) {
			$relative_path = ltrim( $file['path'], '/' );
			$full_path     = trailingslashit( $plugin_dir ) . $relative_path;

			// Ensure subdirectory exists.
			$dir = dirname( $full_path );
			if ( ! $filesystem->is_dir( $dir ) ) {
				if ( ! wp_mkdir_p( $dir ) ) {
					return new WP_Error(
						'mkdir_failed',
						sprintf( 'Could not create directory: %s', $dir )
					);
				}
			}

			// Write the file.
			if ( ! $filesystem->put_contents( $full_path, $file['content'], FS_CHMOD_FILE ) ) {
				return new WP_Error(
					'write_failed',
					sprintf( 'Could not write file: %s', $relative_path )
				);
			}

			// Track the main plugin file.
			if ( empty( $file['is_main'] ) ) {
				continue;
			}

			$main_file = $plugin_slug . '/' . $relative_path;
		}

		// Fallback: if no file marked as main, use the first root-level PHP file.
		if ( empty( $main_file ) ) {
			foreach ( $files as $file ) {
				$path = ltrim( $file['path'], '/' );
				if ( str_ends_with( $path, '.php' ) && ! str_contains( $path, '/' ) ) {
					$main_file = $plugin_slug . '/' . $path;
					break;
				}
			}
		}

		if ( empty( $main_file ) ) {
			return new WP_Error( 'no_main_file', 'Could not determine the main plugin file.' );
		}

		return array( 'main_file' => $main_file );
	}

	/**
	 * Initialize WP_Filesystem.
	 *
	 * @return \WP_Filesystem_Base|\WP_Error
	 */
	private function init_filesystem() {
		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! WP_Filesystem() ) {
			return new WP_Error( 'filesystem_error', 'Could not initialize WP_Filesystem.' );
		}

		return $wp_filesystem;
	}
}
