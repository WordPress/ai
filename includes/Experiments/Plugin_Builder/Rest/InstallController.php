<?php
/**
 * REST API Install controller.
 *
 * @package WordPress\AI\Experiments
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Plugin_Builder\Rest;

use WP_Error;
use WP_REST_Response;
use WordPress\AI\Experiments\Plugin_Builder\Config;
use WordPress\AI\Experiments\Plugin_Builder\Installer\PluginActivator;
use WordPress\AI\Experiments\Plugin_Builder\Installer\PluginWriter;
use WordPress\AI\Experiments\Plugin_Builder\Installer\SlugValidator;

/**
 * POST /wordpress-ai-plugin-builder/v1/install — write generated files and activate.
 *
 * @since x.x.x
 */
class InstallController {

	private const ROUTE_NAMESPACE = 'wordpress-ai-plugin-builder/v1';

	/**
	 * Register routes.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/install',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => static function () {
					return current_user_can( Config::install_capability() );
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
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle( \WP_REST_Request $request ) {
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

		// Step 2: Activate the plugin.
		$activator  = new PluginActivator();
		$activation = $activator->activate( $main_file );

		if ( is_wp_error( $activation ) ) {
			return new WP_REST_Response(
				array(
					'installed' => true,
					'activated' => false,
					'error'     => $activation->get_error_message(),
					'plugin'    => $main_file,
				),
				200
			);
		}

		return new WP_REST_Response(
			array(
				'installed' => true,
				'activated' => true,
				'plugin'    => $main_file,
			),
			200
		);
	}
}
