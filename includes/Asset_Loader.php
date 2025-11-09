<?php
/**
 * Shared asset loader utilities.
 *
 * @package WordPress\AI
 */

namespace WordPress\AI;

class Asset_Loader {

	/**
	 * Enqueue a script using a script path and its asset metadata.
	 *
	 * @since 0.1.0
	 *
	 * @param string $handle The handle for the script.
	 * @param string $file_name The script file name.
	 */
	public static function enqueue_script( string $handle, string $file_name ): void {
		$script_path       = WP_AI_DIR . 'build/' . $file_name . '.js';
		$script_url        = AI_PLUGIN_URL . 'build/' . $file_name . '.js';
		$script_asset_path = $script_path . '.asset.php';

		if ( file_exists( $script_asset_path ) ) {
			// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
			$asset_data = require $script_asset_path;
		} else {
			$asset_data = array(
				'dependencies' => array(),
				'version'      => file_exists( $script_path ) ? filemtime( $script_path ) : null,
			);
		}

		wp_enqueue_script(
			'ai_' . $handle,
			$script_url,
			$asset_data['dependencies'],
			$asset_data['version'],
			true
		);
	}

	/**
	 * Enqueue a style using a style path and its asset metadata.
	 *
	 * @since 0.1.0
	 *
	 * @param string $handle The handle for the style.
	 * @param string $file_name The script file name.
	 */
	public static function enqueue_style( string $handle, string $file_name ): void {
		$style_path       = WP_AI_DIR . 'build/' . $file_name . '.css';
		$style_url        = AI_PLUGIN_URL . 'build/' . $file_name . '.css';
		$style_asset_path = $style_path . '.asset.php';

		if ( file_exists( $style_asset_path ) ) {
			// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
			$asset_data = require $style_asset_path;
		} else {
			$asset_data = array(
				'dependencies' => array(),
				'version'      => file_exists( $style_path ) ? filemtime( $style_path ) : null,
			);
		}

		wp_enqueue_style(
			'ai_' . $handle,
			$style_url,
			$asset_data['dependencies'],
			$asset_data['version']
		);
	}

	/**
	 * Localize data for an enqueued script.
	 *
	 * This method allows passing PHP data to JavaScript using `wp_localize_script()`.
	 * It must be called after the script has been enqueued using `enqueue_script()`.
	 *
	 * @since 0.1.0
	 *
	 * @param string $handle The script handle used in `enqueue_script()` (without prefix).
	 * @param string $object_name The name of the JavaScript object to contain the data.
	 * @param array<string, mixed> $data The data to localize.
	 */
	public static function localize_script( string $handle, string $object_name, array $data ): void {
		wp_localize_script(
			'ai_' . $handle,
			'wpAi' . $object_name,
			$data
		);
	}
}
