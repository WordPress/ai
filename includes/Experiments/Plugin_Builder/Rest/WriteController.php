<?php
/**
 * REST API Install controller.
 *
 * @package WordPress\AI\Experiments
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Plugin_Builder\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WordPress\AI\Experiments\Plugin_Builder\Installer\PluginWriter;
use WordPress\AI\Experiments\Plugin_Builder\Installer\SlugValidator;
use WordPress\AI\Experiments\Plugin_Builder\Rest\DownloadController;

/**
 * POST /wordpress-ai-plugin-builder/v1/write-files — write generated files to disk.
 *
 * @since 0.7.0
 */
class WriteController {

	private const ROUTE_NAMESPACE = 'wordpress-ai-plugin-builder/v1';

	/**
	 * Register routes.
	 *
	 * @since 0.7.0
	 */
	public function register(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/write-files',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => static function () {
					return current_user_can( 'install_plugins' );
				},
				'args'                => array(
					'plugin_slug' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_file_name',
					),
					'files'       => array(
						'required' => true,
						'type'     => 'array',
					),
					'force'       => array(
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					),
				),
			)
		);
	}

	/**
	 * Handle request.
	 *
	 * @since 0.7.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $request ) {
		$plugin_slug = $request->get_param( 'plugin_slug' );
		$files       = $request->get_param( 'files' );
		$force       = (bool) $request->get_param( 'force' );

		if ( empty( $files ) || ! is_array( $files ) ) {
			return new WP_Error(
				'invalid_files',
				'No files provided.',
				array( 'status' => 400 )
			);
		}

		// Validate each file has path + content, and check for path traversal.
		foreach ( $files as $file ) {
			if ( ! is_array( $file ) || empty( $file['path'] ) || ! isset( $file['content'] ) ) {
				return new WP_Error(
					'invalid_file',
					'Each file must have "path" and "content".',
					array( 'status' => 400 )
				);
			}

			// Prevent directory traversal attacks.
			$path = $file['path'];
			if ( str_contains( $path, '..' ) || str_starts_with( $path, '/' ) || str_starts_with( $path, '\\' ) ) {
				return new WP_Error(
					'invalid_path',
					'File paths cannot contain directory traversal sequences or be absolute.',
					array( 'status' => 400 )
				);
			}
		}

		// Validate plugin slug against WordPress.org and local plugins.
		$validator  = new SlugValidator();
		$validation = $validator->validate( $plugin_slug );

		// Hard errors block installation.
		if ( ! $validation['valid'] ) {
			return new WP_Error(
				'slug_conflict',
				(string) $validation['error'],
				array( 'status' => 409 )
			);
		}

		// Warnings require force=true to proceed.
		if ( ! empty( $validation['warnings'] ) && ! $force ) {
			return new WP_REST_Response(
				array(
					'needs_confirmation' => true,
					'warnings'           => $validation['warnings'],
					'message'            => 'Plugin slug has potential conflicts. Set force=true to proceed anyway.',
				),
				200
			);
		}

		// Step 1: Write files via WP_Filesystem.
		$writer = new PluginWriter();
		$result = $writer->write( $plugin_slug, $files );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$main_file = $result['main_file'];

		// Step 2: Return success with the main file path formatted for WP REST API.
		if ( ! function_exists( 'plugin_basename' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin_path = plugin_basename( $main_file );
		if ( '.php' === substr( $plugin_path, -4 ) ) {
			$plugin_path = substr( $plugin_path, 0, -4 );
		}

		// Track this slug so we can identify AI-generated plugins later.
		$ai_slugs = get_option( DownloadController::OPTION_KEY, array() );
		if ( ! in_array( $plugin_slug, (array) $ai_slugs, true ) ) {
			$ai_slugs[] = $plugin_slug;
			update_option( DownloadController::OPTION_KEY, $ai_slugs );
		}

		return new WP_REST_Response(
			array(
				'written' => true,
				'plugin'  => $plugin_path,
			),
			200
		);
	}
}
