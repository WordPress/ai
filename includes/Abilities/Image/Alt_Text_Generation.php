<?php
/**
 * Alt text generation WordPress Ability implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Image;

use WP_Error;
use WordPress\AI\Abstracts\Abstract_Ability;

use function WordPress\AI\get_preferred_vision_models;
use function WordPress\AI\normalize_content;

/**
 * Alt text generation WordPress Ability.
 *
 * Uses AI vision models to propose alt text aligned with WCAG-oriented practice.
 *
 * @since 0.3.0
 */
class Alt_Text_Generation extends Abstract_Ability {

	/**
	 * The maximum character length for generated alt text.
	 *
	 * @since 0.3.0
	 *
	 * @var int
	 */
	protected const MAX_ALT_TEXT_LENGTH = 125;

	/**
	 * Model output token that means the correct alternative text is empty (alt="").
	 *
	 * @since 0.7.0
	 *
	 * @var string
	 */
	private const DECORATIVE_ALT_TOKEN = '[[DECORATIVE_ALT]]';

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.8.0
	 */
	protected function guideline_categories(): array {
		return array( 'site', 'images' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.3.0
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
				'image_meta'    => array(
					'type'        => 'string',
					'description' => esc_html__( 'Structured metadata about how the image block is used, such as whether it is linked.', 'ai' ),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.3.0
	 */
	protected function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'alt_text'      => array(
					'type'        => 'string',
					'description' => esc_html__( 'Generated alternative text for the image; may be empty when alt="" is correct.', 'ai' ),
				),
				'is_decorative' => array(
					'type'        => 'boolean',
					'description' => esc_html__( 'Whether the image was determined to be decorative.', 'ai' ),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.3.0
	 */
	protected function execute_callback( $input ) {
		// Default arguments.
		$args = wp_parse_args(
			$input,
			array(
				'attachment_id' => null,
				'image_url'     => null,
				'context'       => '',
				'image_meta'    => '',
			),
		);

		// Get the image reference.
		$image_reference = $this->get_image_reference( $args );

		if ( is_wp_error( $image_reference ) ) {
			return $image_reference;
		}

		// Generate the alt text.
		$result = $this->generate_alt_text(
			$image_reference,
			normalize_content( $args['context'] ),
			sanitize_textarea_field( $args['image_meta'] )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Detect the decorative token from the AI response.
		if ( 0 === strcasecmp( trim( $result ), self::DECORATIVE_ALT_TOKEN ) ) {
			return array(
				'alt_text'      => '',
				'is_decorative' => true,
			);
		}

		// Return the alt text in the format the Ability expects.
		return array(
			'alt_text' => sanitize_text_field( $result ),
		);
	}

	/**
	 * Gets the image as a data URI from the input arguments.
	 *
	 * @since 0.3.0
	 *
	 * @param array<string, mixed> $args The input arguments.
	 * @return array{reference: string}|\WP_Error The prepared reference payload or WP_Error on failure.
	 */
	protected function get_image_reference( array $args ) {
		// If an attachment ID is provided, get the attachment from the database.
		if ( ! empty( $args['attachment_id'] ) ) {
			return $this->get_attachment_reference( absint( $args['attachment_id'] ) );
		}

		// If an image URL is provided, get the image from the URL.
		if ( ! empty( $args['image_url'] ) ) {
			// Preserve data URIs as-is so the AI client can read the inline bytes.
			if ( str_starts_with( $args['image_url'], 'data:' ) ) {
				return $this->prepare_reference_result( $args['image_url'] );
			}

			// Try to map the URL to a local path.
			$path = $this->maybe_map_url_to_local_path( $args['image_url'] );

			if ( $path ) {
				$data_uri = $this->file_to_data_uri( $path );
				if ( $data_uri ) {
					return $this->prepare_reference_result( $data_uri );
				}
			}

			// Download the remote image to a temporary file.
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
	 * @since 0.3.0
	 *
	 * @param array{reference: string} $image_reference Prepared image reference containing a data URI.
	 * @param string                   $context         Optional context to improve alt text relevance.
	 * @param string                   $image_meta      Optional metadata about how the image block is used.
	 * @return string|\WP_Error The generated alt text or WP_Error on failure.
	 */
	protected function generate_alt_text( array $image_reference, string $context = '', string $image_meta = '' ) {
		$prompt_builder = $this->get_prompt_builder( $this->build_prompt( $context, $image_meta ), $image_reference['reference'] );

		if ( is_wp_error( $prompt_builder ) ) {
			return $prompt_builder;
		}

		$result = $prompt_builder->generate_text();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Clean up the result.
		$alt_text = trim( $result );
		$alt_text = trim( $alt_text, '"\'.' );

		return $alt_text;
	}

	/**
	 * Converts an attachment to a data URI.
	 *
	 * @since 0.3.0
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{reference: string}|\WP_Error Data URI reference array or WP_Error on failure.
	 */
	protected function get_attachment_reference( int $attachment_id ) {
		$attachment = get_post( $attachment_id );

		// Ensure the attachment is valid.
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error(
				'invalid_attachment',
				/* translators: %d: Attachment ID. */
				sprintf( esc_html__( 'Attachment with ID %d not found.', 'ai' ), $attachment_id )
			);
		}

		// Ensure the attachment is an image.
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return new WP_Error(
				'not_an_image',
				esc_html__( 'The specified attachment is not an image.', 'ai' )
			);
		}

		// Try and get the data URI from the file path.
		$file_path = get_attached_file( $attachment_id );
		if ( $file_path && file_exists( $file_path ) ) {
			$data_uri = $this->file_to_data_uri( $file_path );
			if ( $data_uri ) {
				return $this->prepare_reference_result( $data_uri );
			}
		}

		// If the file path doesn't exist, try and use the image src.
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
	 * @since 0.3.0
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

		$base_dir       = wp_normalize_path(
			trailingslashit( $uploads['basedir'] )
		);
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
	 * Normalizes an uploads URL for comparison.
	 *
	 * @since 0.3.0
	 *
	 * @param string $url URL to normalize.
	 * @return string Normalized URL without scheme and trailing slashes.
	 */
	protected function normalize_upload_url( string $url ): string {
		$without_scheme = preg_replace( '#^https?://#i', '', $url );

		return rtrim( $without_scheme ?? $url, '/' );
	}

	/**
	 * Gets a prompt builder for generating alt text.
	 *
	 * @since 0.7.0
	 *
	 * @param string $prompt The prompt to generate alt text from.
	 * @param string $reference The reference image.
	 * @return \WP_AI_Client_Prompt_Builder|\WP_Error The prompt builder, or a WP_Error on failure.
	 */
	private function get_prompt_builder( string $prompt, string $reference ) {
		$prompt_builder = wp_ai_client_prompt( $prompt )
			->with_file( $reference )
			->using_system_instruction( $this->get_system_instruction( 'alt-text-system-instruction.php' ) )
			->using_temperature( 0.3 )
			->using_model_preference( ...get_preferred_vision_models() );

		return $this->ensure_text_generation_supported(
			$prompt_builder,
			esc_html__( 'Alt text generation failed. Please ensure you have a connected provider that supports both text generation and vision capabilities.', 'ai' )
		);
	}

	/**
	 * Builds the prompt for alt text generation.
	 *
	 * @since 0.3.0
	 *
	 * @param string $context    Optional context about the image.
	 * @param string $image_meta Optional metadata about how the image block is used.
	 * @return string The prompt for the AI.
	 */
	protected function build_prompt( string $context = '', string $image_meta = '' ): string {
		$prompt = __( 'Generate alt text for this image.', 'ai' );

		// If we have additional context, add it to the prompt.
		if ( ! empty( $context ) ) {
			$prompt .= ' ' . __( 'Ensure the alt text you return matches the language of the content in the <additional-context> tag.', 'ai' );

			$prompt .= "\n\n<additional-context>" . $context . '</additional-context>';
		} else {
			$prompt .= ' ' . sprintf(
				/* translators: %s: locale code, e.g. pl_PL */
				__( 'Ensure the alt text you return matches the language of this locale: %s.', 'ai' ),
				get_locale()
			);
		}

		// If we have image block usage metadata, add it to the prompt.
		if ( ! empty( $image_meta ) ) {
			$prompt .= "\n\n<image-meta>" . $image_meta . '</image-meta>';
		}

		return $prompt;
	}

	/**
	 * Sanitizes incoming image references while allowing data URIs.
	 *
	 * @since 0.3.0
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

		if ( str_starts_with( $value, 'data:' ) ) {
			return $value;
		}

		return esc_url_raw( $value );
	}

	/**
	 * Downloads a remote image to a temporary file for processing.
	 *
	 * @since 0.3.0
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
	 * @since 0.3.0
	 *
	 * @param string $file_path The file path to clean up.
	 * @return void
	 */
	protected function cleanup_temporary_file( string $file_path ): void {
		if ( ! file_exists( $file_path ) ) {
			return;
		}

		wp_delete_file( $file_path );
	}

	/**
	 * Helper to standardize the reference result.
	 *
	 * @since 0.3.0
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
	 * @since 0.3.0
	 *
	 * @param string $file_path Path to the file.
	 * @return string|null Data URI or null on failure.
	 */
	protected function file_to_data_uri( string $file_path ): ?string {
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
	 * {@inheritDoc}
	 *
	 * @since 0.3.0
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
	 * {@inheritDoc}
	 *
	 * @since 0.3.0
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
