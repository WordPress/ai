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

	use Resolves_Image_Reference;

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
		$image_reference = $this->resolve_image_reference( $args );

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
	 * Generates alt text for the given image reference.
	 *
	 * @since 0.3.0
	 *
	 * @param string $image_reference Data URI for the image to analyze.
	 * @param string $context         Optional context to improve alt text relevance.
	 * @param string $image_meta      Optional metadata about how the image block is used.
	 * @return string|\WP_Error The generated alt text or WP_Error on failure.
	 */
	protected function generate_alt_text( string $image_reference, string $context = '', string $image_meta = '' ) {
		$prompt_builder = $this->get_prompt_builder( $this->build_prompt( $context, $image_meta ), $image_reference );

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
