<?php
/**
 * Alt text generation WordPress Ability implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Alt_Text_Generation;

use WP_Error;
use WordPress\AI\Abstracts\Abstract_Ability;
use WordPress\AI_Client\AI_Client;

/**
 * Alt text generation WordPress Ability.
 *
 * Uses AI vision models to generate descriptive alt text for images.
 *
 * @since x.x.x
 */
class Alt_Text_Generation extends Abstract_Ability {

	/**
	 * The maximum character length for generated alt text.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	protected const MAX_ALT_TEXT_LENGTH = 125;

	/**
	 * Returns the input schema of the ability.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The input schema of the ability.
	 */
	protected function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'attachment_id' => array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'description'       => esc_html__( 'The attachment ID of the image to generate alt text for.', 'ai' ),
				),
					'image_url'     => array(
						'type'              => 'string',
						'sanitize_callback' => array( $this, 'sanitize_image_reference_input' ),
						'description'       => esc_html__( 'URL or data URI of the image to generate alt text for. Used if attachment_id is not provided.', 'ai' ),
					),
				'context'       => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_textarea_field',
					'description'       => esc_html__( 'Optional context about the image or surrounding content to improve alt text relevance.', 'ai' ),
				),
			),
		);
	}

	/**
	 * Returns the output schema of the ability.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The output schema of the ability.
	 */
	protected function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'alt_text' => array(
					'type'        => 'string',
					'description' => esc_html__( 'Generated alt text for the image.', 'ai' ),
				),
			),
		);
	}

	/**
	 * Executes the ability with the given input arguments.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input The input arguments to the ability.
	 * @return array{alt_text: string}|\WP_Error The result of the ability execution, or a WP_Error on failure.
	 */
	protected function execute_callback( $input ) {
		$args = wp_parse_args(
			$input,
			array(
				'attachment_id' => null,
				'image_url'     => null,
				'context'       => '',
			),
		);

		$image_reference = $this->get_image_reference( $args );

		if ( is_wp_error( $image_reference ) ) {
			return $image_reference;
		}

		$result = $this->generate_alt_text( $image_reference, $args['context'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result ) ) {
			return new WP_Error(
				'no_results',
				esc_html__( 'No alt text was generated.', 'ai' )
			);
		}

		return array(
			'alt_text' => sanitize_text_field( $result ),
		);
	}

	/**
	 * Gets the image as a data URI from the input arguments.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $args The input arguments.
	 * @return array{reference: string}|\WP_Error The prepared reference payload or WP_Error on failure.
	 */
	protected function get_image_reference( array $args ) {
		if ( ! empty( $args['attachment_id'] ) ) {
			return $this->get_attachment_reference( absint( $args['attachment_id'] ) );
		}

		if ( ! empty( $args['image_url'] ) ) {
			// Preserve data URIs as-is so the AI client can read the inline bytes.
			if ( 0 === strpos( $args['image_url'], 'data:' ) ) {
				return $this->prepare_reference_result( $args['image_url'] );
			}

			$path = $this->maybe_map_url_to_local_path( $args['image_url'] );

			if ( $path ) {
				$data_uri = $this->file_to_data_uri( $path );
				if ( $data_uri ) {
					return $this->prepare_reference_result( $data_uri );
				}
			}

			$downloaded = $this->download_remote_image_to_temp_file( $args['image_url'] );

			if ( is_wp_error( $downloaded ) ) {
				return $downloaded;
			}

			$data_uri = $this->file_to_data_uri( $downloaded );
			$this->cleanup_temporary_file( $downloaded );

			if ( ! $data_uri ) {
				return new WP_Error(
					'file_read_error',
					esc_html__( 'Could not read the downloaded image file.', 'ai' )
				);
			}

			return $this->prepare_reference_result( $data_uri );
		}

		return new WP_Error(
			'no_image_provided',
			esc_html__( 'Either attachment_id or image_url must be provided.', 'ai' )
		);
	}

	/**
	 * Generates alt text for the given image reference.
	 *
	 * @since x.x.x
	 *
	 * @param array{reference: string} $image_reference Prepared image reference containing a data URI.
	 * @param string                   $context         Optional context to improve alt text relevance.
	 * @return string|\WP_Error The generated alt text or WP_Error on failure.
	 */
	protected function generate_alt_text( array $image_reference, string $context = '' ) {
		$prompt = $this->build_prompt( $context );

		$result = AI_Client::prompt_with_wp_error( $prompt )
			->with_file( $image_reference['reference'] )
			->using_system_instruction( $this->get_system_instruction() )
			->using_temperature( 0.3 )
			->using_model_preference(
				array( 'openai', 'gpt-5-nano' ),
				array( 'anthropic', 'claude-haiku-4-5' ),
				array( 'google', 'gemini-2.5-flash' )
			)
			->generate_text();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Clean up the result.
		$alt_text = trim( $result );
		$alt_text = trim( $alt_text, '"\'.' );

		// Truncate if too long.
		if ( strlen( $alt_text ) > self::MAX_ALT_TEXT_LENGTH ) {
			$alt_text = substr( $alt_text, 0, self::MAX_ALT_TEXT_LENGTH - 3 ) . '...';
		}

		return $alt_text;
	}

	/**
	 * Returns a data URI for an attachment.
	 *
	 * @since x.x.x
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{reference: string}|\WP_Error Data URI reference array or WP_Error on failure.
	 */
	protected function get_attachment_reference( int $attachment_id ) {
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

		$file_path = get_attached_file( $attachment_id );
		if ( $file_path && file_exists( $file_path ) ) {
			$data_uri = $this->file_to_data_uri( $file_path );
			if ( $data_uri ) {
				return $this->prepare_reference_result( $data_uri );
			}
		}

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

		// Download remote URL and convert to data URI.
		$downloaded = $this->download_remote_image_to_temp_file( $image_src[0] );

		if ( is_wp_error( $downloaded ) ) {
			return $downloaded;
		}

		$data_uri = $this->file_to_data_uri( $downloaded );
		$this->cleanup_temporary_file( $downloaded );

		if ( ! $data_uri ) {
			return new WP_Error(
				'file_read_error',
				esc_html__( 'Could not read the downloaded image file.', 'ai' )
			);
		}

		return $this->prepare_reference_result( $data_uri );
	}

	/**
	 * Attempts to map an uploads URL to a local filesystem path.
	 *
	 * @since x.x.x
	 *
	 * @param string $url The URL to convert.
	 * @return string|null The local path if found, otherwise null.
	 */
	protected function maybe_map_url_to_local_path( string $url ): ?string {
		$uploads = wp_get_upload_dir();

		if (
			empty( $uploads['baseurl'] ) ||
			empty( $uploads['basedir'] )
		) {
			return null;
		}

		$normalized_url     = $this->normalize_upload_url( $url );
		$normalized_baseurl = $this->normalize_upload_url( $uploads['baseurl'] );

		if ( false === strpos( $normalized_url, $normalized_baseurl ) ) {
			return null;
		}

		$relative_path = ltrim(
			substr( $normalized_url, strlen( $normalized_baseurl ) ),
			'/'
		);

		if ( '' === $relative_path ) {
			return null;
		}

		$full_path = wp_normalize_path(
			trailingslashit( $uploads['basedir'] ) . $relative_path
		);

		if ( file_exists( $full_path ) && is_file( $full_path ) ) {
			return $full_path;
		}

		return null;
	}

	/**
	 * Normalizes an uploads URL for comparison.
	 *
	 * @since x.x.x
	 *
	 * @param string $url URL to normalize.
	 * @return string Normalized URL without scheme and trailing slashes.
	 */
	protected function normalize_upload_url( string $url ): string {
		$without_scheme = preg_replace( '#^https?://#i', '', $url );

		return rtrim( $without_scheme ?? $url, '/' );
	}

	/**
	 * Builds the prompt for alt text generation.
	 *
	 * @since x.x.x
	 *
	 * @param string $context Optional context about the image.
	 * @return string The prompt for the AI.
	 */
	protected function build_prompt( string $context = '' ): string {
		$prompt = __( 'Generate alt text for this image.', 'ai' );

		if ( ! empty( $context ) ) {
			$prompt .= ' ' . sprintf(
				/* translators: %s: Context about the image. */
				__( 'Context: %s', 'ai' ),
				$context
			);
		}

		return $prompt;
	}

	/**
	 * Sanitizes incoming image references while allowing data URIs.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $value Raw user input.
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

		if ( 0 === strpos( $value, 'data:' ) ) {
			return $value;
		}

		return esc_url_raw( $value );
	}

	/**
	 * Downloads a remote image to a temporary file for processing.
	 *
	 * @since x.x.x
	 *
	 * @param string $url Remote image URL.
	 * @return string|\WP_Error Path to the temporary file or WP_Error on failure.
	 */
	protected function download_remote_image_to_temp_file( string $url ) {
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$temp_file = download_url( $url );

		if ( is_wp_error( $temp_file ) ) {
			return $temp_file;
		}

		return $temp_file;
	}

	/**
	 * Cleans up a temporary file if it exists.
	 *
	 * @since x.x.x
	 *
	 * @param string $file_path The file path to clean up.
	 * @return void
	 */
	protected function cleanup_temporary_file( string $file_path ): void {
		if ( ! file_exists( $file_path ) ) {
			return;
		}

		if ( function_exists( 'wp_delete_file' ) ) {
			wp_delete_file( $file_path );
			return;
		}

		@unlink( $file_path ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
	}

	/**
	 * Helper to standardize the reference result.
	 *
	 * @since x.x.x
	 *
	 * @param string $reference The data URI reference.
	 * @return array{reference: string} Standardized reference array.
	 */
	protected function prepare_reference_result( string $reference ): array {
		return array(
			'reference' => $reference,
		);
	}

	/**
	 * Converts a file to a data URI.
	 *
	 * @since x.x.x
	 *
	 * @param string $file_path Path to the file.
	 * @return string|null Data URI or null on failure.
	 */
	protected function file_to_data_uri( string $file_path ): ?string {
		$mime_type = wp_check_filetype( $file_path )['type'];
		if ( ! $mime_type ) {
			return null;
		}

		$contents = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $contents ) {
			return null;
		}

		return 'data:' . $mime_type . ';base64,' . base64_encode( $contents );
	}

	/**
	 * Returns the permission callback of the ability.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $args The input arguments to the ability.
	 * @return bool|\WP_Error True if the user has permission, WP_Error otherwise.
	 */
	protected function permission_callback( $args ) {
		$attachment_id = isset( $args['attachment_id'] ) ? absint( $args['attachment_id'] ) : null;

		if ( $attachment_id ) {
			$attachment = get_post( $attachment_id );

			if ( ! $attachment ) {
				return new WP_Error(
					'attachment_not_found',
					/* translators: %d: Attachment ID. */
					sprintf( esc_html__( 'Attachment with ID %d not found.', 'ai' ), $attachment_id )
				);
			}

			// Check if user can edit this attachment.
			if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
				return new WP_Error(
					'insufficient_capabilities',
					esc_html__( 'You do not have permission to edit this attachment.', 'ai' )
				);
			}
		} elseif ( ! current_user_can( 'upload_files' ) ) {
			// For URL-based generation, require upload_files capability.
			return new WP_Error(
				'insufficient_capabilities',
				esc_html__( 'You do not have permission to generate alt text.', 'ai' )
			);
		}

		return true;
	}

	/**
	 * Returns the meta of the ability.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The meta of the ability.
	 */
	protected function meta(): array {
		return array(
			'show_in_rest' => true,
			'mcp'          => array(
				'public'   => true,
				'type'     => 'tool',
				'category' => 'media',
			),
		);
	}
}
