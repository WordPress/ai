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
use WP_REST_Request;
use ZipArchive;

/**
 * Handles plugin ZIP downloads — via REST (from files array) and via admin-post (from disk).
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
				'methods'             => 'POST',
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
					'files'       => array(
						'required' => true,
						'type'     => 'array',
					),
				),
			)
		);
	}

	/**
	 * REST handler — builds a ZIP from the supplied files array.
	 *
	 * @since x.x.x
	 *
	 * @param WP_REST_Request $request The REST request.
	 */
	public function handle_rest( WP_REST_Request $request ): void {
		$plugin_slug = $request->get_param( 'plugin_slug' );
		$files       = $request->get_param( 'files' );

		if ( empty( $files ) || ! is_array( $files ) ) {
			status_header( 400 );
			echo wp_json_encode( array( 'error' => __( 'No files provided.', 'ai' ) ) );
			exit;
		}

		foreach ( $files as $file ) {
			if ( ! is_array( $file ) || empty( $file['path'] ) || ! isset( $file['content'] ) ) {
				status_header( 400 );
				echo wp_json_encode( array( 'error' => __( 'Each file must have "path" and "content".', 'ai' ) ) );
				exit;
			}

			$path = $file['path'];
			if ( str_contains( $path, '..' ) || str_starts_with( $path, '/' ) || str_starts_with( $path, '\\' ) ) {
				status_header( 400 );
				echo wp_json_encode( array( 'error' => __( 'Invalid file path.', 'ai' ) ) );
				exit;
			}
		}

		$zip_content = $this->create_zip_from_files( $plugin_slug, $files );

		if ( ! $zip_content ) {
			status_header( 500 );
			echo wp_json_encode( array( 'error' => __( 'Failed to create ZIP archive.', 'ai' ) ) );
			exit;
		}

		$this->send_zip( $plugin_slug, $zip_content );
	}

	/**
	 * Admin-post handler — builds a ZIP from the installed plugin directory on disk.
	 *
	 * @since x.x.x
	 */
	public function handle_admin_post(): void {
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

		$this->send_zip( $slug, $zip_content );
	}

	/**
	 * Create a ZIP archive in memory from a files array.
	 *
	 * @since x.x.x
	 *
	 * @param string                           $plugin_slug Plugin slug (top-level ZIP folder).
	 * @param array<int, array<string, mixed>> $files       Files with "path" and "content" keys.
	 * @return string|false ZIP binary string or false on failure.
	 */
	private function create_zip_from_files( string $plugin_slug, array $files ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return false;
		}

		$tmp = tempnam( sys_get_temp_dir(), 'ai_plugin_' );
		$zip = new ZipArchive();

		if ( true !== $zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return false;
		}

		foreach ( $files as $file ) {
			$relative = $plugin_slug . '/' . ltrim( $file['path'], '/' );
			$zip->addFromString( $relative, $file['content'] );
		}

		$zip->close();

		$content = file_get_contents( $tmp );
		unlink( $tmp );

		return $content;
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
	 */
	private function send_zip( string $plugin_slug, string $zip_content ): void {
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $plugin_slug . '.zip"' );
		header( 'Content-Length: ' . strlen( $zip_content ) );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $zip_content;
		exit;
	}
}

