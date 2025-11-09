<?php
/**
 * Shared asset loader utilities.
 *
 * @package WordPress\AI
 */

namespace WordPress\AI;

/**
 * Utility for enqueuing plugin assets using generated metadata.
 *
 * @since 0.1.0
 */
class Asset_Loader {
	/**
	 * Enqueues a script and its dependencies.
	 *
	 * @since 0.1.0
	 *
	 * @param string $handle    Script handle (without internal prefix).
	 * @param string $file_name Basename within build/ without extension (e.g. 'index').
	 */
	public static function enqueue_script( string $handle, string $file_name ): void {
		$script_path = WP_AI_DIR . 'build/' . $file_name . '.js';
		$script_url  = AI_PLUGIN_URL . 'build/' . $file_name . '.js';
		$asset_path  = $script_path . '.asset.php';

		$asset = array(
			'dependencies' => array(),
			'version'      => file_exists( $script_path ) ? filemtime( $script_path ) : null,
		);

		if ( file_exists( $asset_path ) ) {
			$loaded = include $asset_path;
			if ( is_array( $loaded ) ) {
				$asset = array_merge( $asset, $loaded );
			}
		}

		wp_enqueue_script(
			self::prefix_handle( $handle ),
			$script_url,
			$asset['dependencies'] ?? array(),
			$asset['version'] ?? null,
			true
		);
	}

	/**
	 * Enqueues a stylesheet.
	 *
	 * @since 0.1.0
	 *
	 * @param string $handle    Style handle (without internal prefix).
	 * @param string $file_name Basename within build/ without extension (e.g. 'style-index').
	 * @param array<string> $deps Optional style dependencies.
	 */
	public static function enqueue_style( string $handle, string $file_name, array $deps = array() ): void {
		$style_path = WP_AI_DIR . 'build/' . $file_name . '.css';
		$style_url  = AI_PLUGIN_URL . 'build/' . $file_name . '.css';

		if ( ! file_exists( $style_path ) ) {
			return;
		}

		$version    = filemtime( $style_path );
		$asset_path = $style_path . '.asset.php';
		if ( file_exists( $asset_path ) ) {
			$loaded = include $asset_path;
			if ( is_array( $loaded ) && isset( $loaded['version'] ) ) {
				$version = $loaded['version'];
			}
		}

		wp_enqueue_style(
			self::prefix_handle( $handle ),
			$style_url,
			$deps,
			$version
		);
	}

	/**
	 * Localizes arbitrary data to an enqueued script.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $handle      Script handle used in enqueue_script.
	 * @param string               $object_name JS object name.
	 * @param array<string, mixed> $data        Data to localize.
	 */
	public static function localize_script( string $handle, string $object_name, array $data ): void {
		wp_localize_script(
			self::prefix_handle( $handle ),
			$object_name,
			$data
		);
	}

	/**
	 * Internal helper for consistent handle prefixes.
	 *
	 * @param string $handle Handle name.
	 * @return string Prefixed handle.
	 */
	public static function prefix_handle( string $handle ): string {
		return 'wp-ai-' . $handle;
	}
}
