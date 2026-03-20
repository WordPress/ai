<?php
/**
 * REST API Download controller.
 *
 * @package WordPress\AI\Experiments
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Plugin_Builder\Rest;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use WP_Error;
use WP_REST_Request;
use ZipArchive;

/**
 * Handles plugin ZIP downloads — via REST (from disk) and via admin-post (from disk).
 *
 * @since x.x.x
 */
class DownloadController {

	private const ROUTE_NAMESPACE = 'wordpress-ai-plugin-builder/v1';

	/** WordPress option that stores AI-generated plugin slugs. */
	public const OPTION_KEY = 'wp_ai_plugin_builder_slugs';

	/**
	 * Register REST route and admin-post action.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/download',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_rest' ),
				'permission_callback' => static function () {
					return current_user_can( 'install_plugins' );
				},
				'args'                => array(
					'plugin_slug' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_file_name',
					),
				),
			)
		);
	}

	/**
	 * REST handler — builds a ZIP from the installed plugin directory on disk.
	 *
	 * @since x.x.x
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_Error|void
	 */
	public function handle_rest( WP_REST_Request $request ) {
		$plugin_slug = $request->get_param( 'plugin_slug' );

		$ai_slugs = get_option( self::OPTION_KEY, array() );
		if ( ! in_array( $plugin_slug, (array) $ai_slugs, true ) ) {
			return new WP_Error( 'not_found', __( 'Plugin not found.', 'ai' ), array( 'status' => 404 ) );
		}

		$plugin_dir = WP_PLUGIN_DIR . '/' . $plugin_slug;
		if ( ! is_dir( $plugin_dir ) ) {
			return new WP_Error( 'not_found', __( 'Plugin directory not found.', 'ai' ), array( 'status' => 404 ) );
		}

		$zip_content = $this->create_zip_from_dir( $plugin_dir, $plugin_slug );

		if ( ! $zip_content ) {
			return new WP_Error( 'zip_failed', __( 'Failed to create ZIP archive.', 'ai' ), array( 'status' => 500 ) );
		}

		status_header( 200 );
		foreach ( $this->get_zip_response_headers( $plugin_slug, $zip_content ) as $header => $value ) {
			header( "$header: $value" );
		}

		echo $zip_content;
		exit;
	}

	/**
	 * Admin-post handler — builds a ZIP from the installed plugin directory on disk.
	 *
	 * @since x.x.x
	 *
	 * @phpstan-return never
	 */
	public function handle_admin_post() {
		$slug = isset( $_GET['slug'] ) ? sanitize_file_name( wp_unslash( $_GET['slug'] ) ) : '';
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! current_user_can( 'install_plugins' ) || ! wp_verify_nonce( $nonce, 'ai_download_' . $slug ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'ai' ), 403 );
		}

		$ai_slugs = get_option( self::OPTION_KEY, array() );
		if ( ! in_array( $slug, (array) $ai_slugs, true ) ) {
			wp_die( esc_html__( 'Plugin not found.', 'ai' ), 404 );
		}

		$plugin_dir = WP_PLUGIN_DIR . '/' . $slug;
		if ( ! is_dir( $plugin_dir ) ) {
			wp_die( esc_html__( 'Plugin directory not found.', 'ai' ), 404 );
		}

		$zip_content = $this->create_zip_from_dir( $plugin_dir, $slug );

		if ( ! $zip_content ) {
			wp_die( esc_html__( 'Failed to create ZIP archive.', 'ai' ), 500 );
		}

		foreach ( $this->get_zip_response_headers( $slug, $zip_content ) as $header => $value ) {
			header( "$header: $value" );
		}
		echo $zip_content;
		exit;
	}

	/**
	 * Create a ZIP archive in memory from a plugin directory on disk.
	 *
	 * @since x.x.x
	 *
	 * @param string $plugin_dir  Absolute path to the plugin directory.
	 * @param string $plugin_slug Plugin slug (top-level ZIP folder).
	 * @return string|false ZIP binary string or false on failure.
	 */
	private function create_zip_from_dir( string $plugin_dir, string $plugin_slug ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return false;
		}

		$tmp = tempnam( sys_get_temp_dir(), 'ai_plugin_' );
		$zip = new ZipArchive();

		if ( true !== $zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return false;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $plugin_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			$file_path     = $file->getRealPath();
			$relative_path = $plugin_slug . '/' . substr( $file_path, strlen( $plugin_dir ) + 1 );
			$zip->addFile( $file_path, $relative_path );
		}

		$zip->close();

		$content = file_get_contents( $tmp );
		unlink( $tmp );

		return $content;
	}

	/**
	 * Send ZIP binary response with appropriate headers.
	 *
	 * @since x.x.x
	 *
	 * @param string $plugin_slug Slug used as the download filename.
	 * @param string $zip_content Binary ZIP content.
	 * @return array<string, string> ZIP response headers.
	 */
	private function get_zip_response_headers( string $plugin_slug, string $zip_content ): array {
		return array(
			'Content-Type'        => 'application/zip',
			'Content-Disposition' => 'attachment; filename=' . $plugin_slug . '.zip',
			'Content-Length'      => (string) strlen( $zip_content ),
			'Cache-Control'       => 'no-cache, must-revalidate',
		);
	}
}
