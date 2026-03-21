<?php
/**
 * REST API controller for fetching physical plugin files.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Plugin_Builder\Rest;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Server;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Controller for getting physical file contents.
 *
 * @since x.x.x
 */
class FilesController extends WP_REST_Controller {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = 'wordpress-ai-plugin-builder/v1';
		$this->rest_base = 'files';
	}

	/**
	 * Registers the routes for the objects of the controller.
	 */
	public function register(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<plugin_slug>[a-zA-Z0-9_\-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'plugin_slug' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_title',
						),
					),
				),
			)
		);
	}

	/**
	 * Checks if a given request has access to read plugin files.
	 *
	 * @param \WP_REST_Request $request Full data about the request.
	 * @return true|\WP_Error True if the request has read access, WP_Error object otherwise.
	 */
	public function permissions_check( $request ) {
		if ( ! current_user_can( 'install_plugins' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to read plugin builder files.', 'ai' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}
		return true;
	}

	/**
	 * Retrieves the files for a specific plugin slug.
	 *
	 * @param \WP_REST_Request $request Full data about the request.
	 * @return \WP_REST_Response|\WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_item( $request ) {
		$plugin_slug = $request->get_param( 'plugin_slug' );

		$plugin_dir = WP_PLUGIN_DIR . '/' . $plugin_slug;

		if ( ! is_dir( $plugin_dir ) ) {
			return new WP_Error( 'not_found', 'Plugin directory not found.', array( 'status' => 404 ) );
		}

		$files = $this->get_files_recursive( $plugin_dir, $plugin_dir );

		return rest_ensure_response(
			array(
				'plugin_slug' => $plugin_slug,
				'files'       => $files,
			)
		);
	}

	/**
	 * Recursively read all files in a directory.
	 */
	private function get_files_recursive( $dir, $base_dir ) {
		$results = array();
		$files   = scandir( $dir );
		if ( ! $files ) {
			return $results;
		}

		foreach ( $files as $value ) {
			$path = realpath( $dir . DIRECTORY_SEPARATOR . $value );
			if ( ! is_dir( $path ) ) {
				if ( '.' !== $value && '..' !== $value ) {
					// Prepare path relative to the plugin directory
					$relative_path = str_replace( $base_dir . DIRECTORY_SEPARATOR, '', $path );

					// Get basic description from comments maybe, or just empty
					$content = file_get_contents( $path );
					if ( false !== $content ) {
						$results[] = array(
							'path'        => $relative_path,
							'content'     => $content,
							'description' => 'Existing file.',
						);
					}
				}
			} elseif ( '.' !== $value && '..' !== $value ) {
				$results = array_merge( $results, $this->get_files_recursive( $path, $base_dir ) );
			}
		}

		return $results;
	}
}
