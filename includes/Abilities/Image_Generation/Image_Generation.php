<?php
/**
 * Image generation WordPress Ability implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Image_Generation;

use WP_Error;
use WordPress\AI\Abstracts\Abstract_Ability;
use WordPress\AI_Client\AI_Client;

/**
 * Image generation WordPress Ability.
 *
 * @since 0.1.0
 */
class Image_Generation extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'prompt' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => esc_html__( 'Prompt used to generate an image.', 'ai' ),
				),
			),
			'required'   => array( 'prompt' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function output_schema(): array {
		return array(
			'type'        => 'string',
			'description' => esc_html__( 'The base64 encoded image data.', 'ai' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function execute_callback( $input ) {
		// Generate the image.
		$result = $this->generate_image( $input['prompt'] );

		// If we have an error, return it.
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// If we have no results, return an error.
		if ( empty( $result ) ) {
			return new WP_Error(
				'no_results',
				esc_html__( 'No image was generated.', 'ai' )
			);
		}

		// Return the image data in the format the Ability expects.
		return sanitize_text_field( trim( $result ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function permission_callback( $args ) {
		// Ensure the user has permission to upload files.
		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error(
				'insufficient_capabilities',
				esc_html__( 'You do not have permission to generate images.', 'ai' )
			);
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function meta(): array {
		return array(
			'show_in_rest' => true,
		);
	}

	/**
	 * Generates an image from the given prompt.
	 *
	 * @since x.x.x
	 *
	 * @param string $prompt The prompt to generate an image from.
	 * @return string|\WP_Error The generated image data, or a WP_Error if there was an error.
	 */
	protected function generate_image( string $prompt ) {
		// Generate the image using the AI client.
		$file = AI_Client::prompt_with_wp_error( $prompt )->generate_image();

		// If we have an error, return it.
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		// Return the base64 encoded image data.
		$data = $file->getBase64Data();

		if ( empty( $data ) ) {
			return new WP_Error(
				'no_image_data',
				esc_html__( 'No image data was generated.', 'ai' )
			);
		}

		return $data;
	}
}
