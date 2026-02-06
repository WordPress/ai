<?php
/**
 * Base64 encoded image import WordPress Ability implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Image;

use Throwable;
use WP_Error;
use WordPress\AI\Abstracts\Abstract_Ability;
use WordPress\AiClient\Files\DTO\File;

/**
 * Base64 encoded image import WordPress Ability.
 *
 * @since 0.2.0
 */
class Import_Base64_Image extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.2.0
	 */
	protected function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'data'        => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => esc_html__( 'The base64 encoded image data to import into the media library.', 'ai' ),
				),
				'filename'    => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => esc_html__( 'The filename of the image.', 'ai' ),
				),
				'title'       => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => esc_html__( 'The title of the image.', 'ai' ),
				),
				'description' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => esc_html__( 'The description of the image.', 'ai' ),
				),
				'alt_text'    => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => esc_html__( 'The alt text of the image.', 'ai' ),
				),
				'mime_type'   => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => esc_html__( 'The MIME type of the image.', 'ai' ),
				),
				'meta'        => array(
					'type'        => 'array',
					'description' => esc_html__( 'Optional meta data to save with the image.', 'ai' ),
					'items'       => array(
						'type'                 => 'object',
						'properties'           => array(
							'key'   => array(
								'type'              => 'string',
								'sanitize_callback' => 'sanitize_key',
								'description'       => esc_html__( 'The key of the meta data.', 'ai' ),
							),
							'value' => array(
								'type'              => 'string',
								'sanitize_callback' => 'sanitize_text_field',
								'description'       => esc_html__( 'The value of the meta data.', 'ai' ),
							),
						),
						'required'             => array( 'key', 'value' ),
						'additionalProperties' => false,
					),
				),
			),
			'required'   => array( 'data' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.2.0
	 */
	protected function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'image' => array(
					'type'        => 'object',
					'description' => esc_html__( 'Imported image data.', 'ai' ),
					'properties'  => array(
						'id'          => array(
							'type'        => 'integer',
							'description' => esc_html__( 'Attachment ID.', 'ai' ),
						),
						'url'         => array(
							'type'        => 'string',
							'description' => esc_html__( 'Attachment URL.', 'ai' ),
						),
						'filename'    => array(
							'type'        => 'string',
							'description' => esc_html__( 'Attachment filename.', 'ai' ),
						),
						'title'       => array(
							'type'        => 'string',
							'description' => esc_html__( 'Attachment title.', 'ai' ),
						),
						'description' => array(
							'type'        => 'string',
							'description' => esc_html__( 'Attachment description.', 'ai' ),
						),
						'alt_text'    => array(
							'type'        => 'string',
							'description' => esc_html__( 'Attachment alt text.', 'ai' ),
						),
					),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.2.0
	 */
	protected function execute_callback( $input ) {
		// Default arguments.
		$args = wp_parse_args(
			$input,
			array(
				'filename'    => 'ai-generated-image-' . time(),
				'title'       => '',
				'description' => '',
				'alt_text'    => '',
				'mime_type'   => null,
				'meta'        => array(),
			),
		);

		// Verify the data is a base64 encoded string.
		try {
			$file = new File( $input['data'], $args['mime_type'] );
		} catch ( Throwable $t ) {
			return new WP_Error(
				'invalid_data',
				esc_html__( 'The data is not a valid base64 encoded string.', 'ai' )
			);
		}

		// Verify the data is a valid image.
		if ( ! $file->isImage() ) {
			return new WP_Error(
				'invalid_data',
				esc_html__( 'The data is not a valid image.', 'ai' )
			);
		}

		// Get the base64 data.
		$base64_data = $file->getBase64Data();

		if ( empty( $base64_data ) ) {
			return new WP_Error(
				'no_base64_data',
				esc_html__( 'No base64 data found in the provided input.', 'ai' )
			);
		}

		// Try and import the image.
		$result = $this->import_image(
			$base64_data,
			array(
				'mime_type'   => $file->getMimeType(),
				'title'       => $args['title'],
				'description' => $args['description'],
				'filename'    => $args['filename'],
				'alt_text'    => $args['alt_text'],
			)
		);

		// If we have an error, return it.
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Save the meta data.
		foreach ( $args['meta'] as $meta ) {
			update_post_meta( $result['id'], sanitize_key( $meta['key'] ), sanitize_text_field( $meta['value'] ) );
		}

		// Return the image data in the format the Ability expects.
		return array(
			'image' => $result,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.2.0
	 */
	protected function permission_callback( $args ) {
		// Ensure the user has permission to upload files.
		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error(
				'insufficient_capabilities',
				esc_html__( 'You do not have permission to import images.', 'ai' )
			);
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.2.0
	 */
	protected function meta(): array {
		return array(
			'show_in_rest' => true,
		);
	}

	/**
	 * Imports an image from a base64 encoded string into the media library.
	 *
	 * @since 0.2.0
	 *
	 * @param string $data     The base64 encoded image data to import into the media library.
	 * @param array<string, mixed> $args The arguments for the image import.
	 *                                   - mime_type: The MIME type of the image (e.g., 'image/png', 'image/jpeg').
	 *                                   - title: The title of the image.
	 *                                   - description: The description of the image.
	 *                                   - filename: The filename of the image.
	 *                                   - alt_text: The alt text of the image.
	 * @return array<string, mixed>|\WP_Error The attachment data, or a WP_Error if there was an error.
	 */
	protected function import_image( string $data, array $args = array() ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Decode the base64 data.
		$decoded_data = base64_decode( $data, true );

		// Verify the data was decoded successfully.
		if ( false === $decoded_data ) {
			return new WP_Error(
				'invalid_base64',
				esc_html__( 'Failed to decode base64 image data.', 'ai' )
			);
		}

		// Create a temporary file.
		$temp_file = wp_tempnam( 'ai-image' );

		// Write the decoded data to the temporary file.
		$bytes_written = file_put_contents( $temp_file, $decoded_data ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents

		if ( false === $bytes_written ) {
			wp_delete_file( $temp_file );
			return new WP_Error(
				'write_failed',
				esc_html__( 'Failed to write image data to temporary file.', 'ai' )
			);
		}

		// Determine file extension from MIME type.
		$extension = wp_get_default_extension_for_mime_type( $args['mime_type'] );

		// Prepare file array for sideload.
		$file_array = array(
			'name'     => sanitize_file_name( $args['filename'] ) . '.' . $extension,
			'type'     => $args['mime_type'],
			'tmp_name' => $temp_file,
		);

		// Handle the sideload.
		$attachment_id = media_handle_sideload(
			$file_array,
			0,
			$args['description'],
			array(
				'post_title'     => sanitize_text_field( $args['title'] ),
				'post_content'   => sanitize_text_field( $args['description'] ),
				'post_mime_type' => $args['mime_type'],
				'meta_input'     => array(
					'_wp_attachment_image_alt' => sanitize_text_field( $args['alt_text'] ),
				),
			)
		);

		// Clean up temp file if it still exists.
		if ( file_exists( $temp_file ) ) {
			wp_delete_file( $temp_file );
		}

		// Ensure the import worked.
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// Get attachment data.
		$attachment = get_post( $attachment_id );

		if ( ! $attachment ) {
			return new WP_Error(
				'attachment_not_found',
				esc_html__( 'Failed to retrieve attachment data.', 'ai' )
			);
		}

		$attached_file = get_attached_file( $attachment_id );
		$filename      = $attached_file ? basename( $attached_file ) : '';

		// Return attachment data.
		return array(
			'id'          => $attachment_id,
			'url'         => wp_get_attachment_url( $attachment_id ),
			'filename'    => $filename,
			'title'       => $attachment->post_title,
			'description' => $attachment->post_content,
			'alt_text'    => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
		);
	}
}
