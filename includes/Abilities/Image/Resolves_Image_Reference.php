<?php
/**
 * Trait that resolves image input (attachment ID, URL, or data URI) to a data URI.
 *
 * @package WordPress\AI\Abilities\Image
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Image;

use WP_Error;

/**
 * Provides shared logic for abilities that accept an image as input.
 *
 * @since x.x.x
 */
trait Resolves_Image_Reference {

	/**
	 * Resolves an image input array to a data URI.
	 *
	 * Accepts either `attachment_id` (preferred) or `image_url`. Returns the
	 * canonical error codes used across image-input abilities so callers can
	 * surface consistent messages.
	 *
	 * @since x.x.x
	 *
	 * @param array{attachment_id?: int|null, image_url?: string|null} $args Input arguments.
	 * @return string|\WP_Error Data URI on success, WP_Error on failure.
	 */
	protected function resolve_image_reference( array $args ) {
		if ( ! empty( $args['attachment_id'] ) ) {
			return $this->resolve_attachment_to_data_uri( absint( $args['attachment_id'] ) );
		}

		if ( ! empty( $args['image_url'] ) && is_string( $args['image_url'] ) ) {
			return $this->resolve_url_to_data_uri( $args['image_url'] );
		}

		return new WP_Error(
			'no_image_provided',
			esc_html__( 'Either attachment_id or image_url must be provided.', 'ai' )
		);
	}

	/**
	 * Resolves an attachment to a data URI.
	 *
	 * Tries the local file first; falls back to downloading the attached URL.
	 *
	 * @since x.x.x
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string|\WP_Error Data URI on success, WP_Error on failure.
	 */
	protected function resolve_attachment_to_data_uri( int $attachment_id ) {
		$attachment = get_post( $attachment_id );

		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error(
				'invalid_attachment',
				/* translators: %d: Attachment ID. */
				sprintf( esc_html__( 'Attachment with ID %d not found.', 'ai' ), $attachment_id )
			);
		}

		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return new WP_Error(
				'not_an_image',
				esc_html__( 'The specified attachment is not an image.', 'ai' )
			);
		}

		// Try the local file first.
		$file_path = get_attached_file( $attachment_id );
		if ( $file_path && file_exists( $file_path ) ) {
			$data_uri = $this->image_file_to_data_uri( $file_path );
			if ( $data_uri ) {
				return $data_uri;
			}
		}

		// Fall back to downloading the URL associated with the attachment.
		$image_src = wp_get_attachment_image_src( $attachment_id, 'large' );

		if ( ! $image_src ) {
			$image_src = wp_get_attachment_image_src( $attachment_id, 'full' );
		}

		if ( ! $image_src || empty( $image_src[0] ) ) {
			return new WP_Error(
				'image_url_not_found',
				esc_html__( 'Could not retrieve image URL from attachment.', 'ai' )
			);
		}

		return $this->download_url_to_data_uri( $image_src[0] );
	}

	/**
	 * Resolves an image URL (or data URI) to a data URI.
	 * Data URIs are returned as-is.
	 *
	 * @since x.x.x
	 *
	 * @param string $url Image URL or data URI.
	 * @return string|\WP_Error Data URI on success, WP_Error on failure.
	 */
	protected function resolve_url_to_data_uri( string $url ) {
		// Pass data URIs through.
		if ( str_starts_with( $url, 'data:' ) ) {
			return $url;
		}

		$path = $this->maybe_map_image_url_to_local_path( $url );
		if ( $path ) {
			$data_uri = $this->image_file_to_data_uri( $path );
			if ( $data_uri ) {
				return $data_uri;
			}
		}

		return $this->download_url_to_data_uri( $url );
	}

	/**
	 * Downloads a remote URL to a temp file and converts it to a data URI.
	 *
	 * @since x.x.x
	 *
	 * @param string $url Remote image URL.
	 * @return string|\WP_Error Data URI on success, WP_Error on failure.
	 */
	protected function download_url_to_data_uri( string $url ) {
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$temp_file = download_url( $url );

		if ( is_wp_error( $temp_file ) ) {
			return $temp_file;
		}

		$data_uri = $this->image_file_to_data_uri( $temp_file );

		if ( file_exists( $temp_file ) ) {
			wp_delete_file( $temp_file );
		}

		if ( ! $data_uri ) {
			return new WP_Error(
				'file_read_error',
				esc_html__( 'Could not read the downloaded image file.', 'ai' )
			);
		}

		return $data_uri;
	}

	/**
	 * Converts a local file to a data URI.
	 *
	 * @since x.x.x
	 *
	 * @param string $file_path Path to the file.
	 * @return string|null Data URI, or null on failure.
	 */
	protected function image_file_to_data_uri( string $file_path ): ?string {
		$mime_type = wp_check_filetype( $file_path )['type'];
		if ( ! $mime_type ) {
			return null;
		}

		$contents = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
		if ( false === $contents ) {
			return null;
		}

		return 'data:' . $mime_type . ';base64,' . base64_encode( $contents );
	}

	/**
	 * Maps an uploads URL to a local filesystem path when possible.
	 *
	 * Returns null when the URL is not under the uploads dir, when path
	 * traversal is attempted, or when the resolved file does not exist.
	 *
	 * @since x.x.x
	 *
	 * @param string $url URL to map.
	 * @return string|null Local path, or null when no safe mapping exists.
	 */
	protected function maybe_map_image_url_to_local_path( string $url ): ?string {
		$uploads = wp_get_upload_dir();

		if (
			empty( $uploads['baseurl'] ) ||
			empty( $uploads['basedir'] )
		) {
			return null;
		}

		$normalized_url     = $this->normalize_image_upload_url( $url );
		$normalized_baseurl = $this->normalize_image_upload_url( $uploads['baseurl'] );

		if ( ! str_contains( $normalized_url, $normalized_baseurl ) ) {
			return null;
		}

		$relative_path = ltrim(
			substr( $normalized_url, strlen( $normalized_baseurl ) ),
			'/'
		);

		if ( '' === $relative_path ) {
			return null;
		}

		// Reject path traversal attempts in the relative path.
		if (
			'..' === $relative_path ||
			str_starts_with( $relative_path, '../' ) ||
			str_contains( $relative_path, '/..' )
		) {
			return null;
		}

		$base_dir       = wp_normalize_path( trailingslashit( $uploads['basedir'] ) );
		$full_path      = $base_dir . $relative_path;
		$real_full_path = realpath( $full_path );

		if ( false === $real_full_path ) {
			return null;
		}

		$real_full_path = wp_normalize_path( $real_full_path );

		// Ensure the resolved path is strictly within the uploads base directory.
		if ( ! str_starts_with( $real_full_path, $base_dir ) ) {
			return null;
		}

		if ( file_exists( $real_full_path ) && is_file( $real_full_path ) ) {
			return $real_full_path;
		}

		return null;
	}

	/**
	 * Normalizes an uploads URL for comparison (strips scheme and trailing slash).
	 *
	 * @since x.x.x
	 *
	 * @param string $url URL to normalize.
	 * @return string Normalized URL.
	 */
	protected function normalize_image_upload_url( string $url ): string {
		$without_scheme = preg_replace( '#^https?://#i', '', $url );

		return rtrim( $without_scheme ?? $url, '/' );
	}

	/**
	 * Sanitizes an image reference input string while preserving data URIs.
	 *
	 * Suitable as a `sanitize_callback` for `image_url` schema fields.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $value Raw input.
	 * @return string Sanitized value.
	 */
	protected function sanitize_image_reference_input( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		if ( str_starts_with( $value, 'data:' ) ) {
			return $value;
		}

		return esc_url_raw( $value );
	}
}
