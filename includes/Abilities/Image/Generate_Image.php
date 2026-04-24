<?php
/**
 * Image generation WordPress Ability implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Image;

use Throwable;
use WP_Error;
use WordPress\AI\Abstracts\Abstract_Ability;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Files\Enums\FileTypeEnum;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

use function WordPress\AI\get_preferred_image_models;

/**
 * Image generation WordPress Ability.
 *
 * @since 0.2.0
 */
class Generate_Image extends Abstract_Ability {

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
	 * @since 0.2.0
	 */
	protected function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'prompt'    => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => esc_html__( 'Prompt used to generate an image.', 'ai' ),
				),
				'reference' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => esc_html__( 'Optional base64-encoded image to use as a reference image for edits.', 'ai' ),
				),
			),
			'required'   => array( 'prompt' ),
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
					'description' => esc_html__( 'Generated image data.', 'ai' ),
					'properties'  => array(
						'data'              => array(
							'type'        => 'string',
							'description' => esc_html__( 'The base64 encoded image data.', 'ai' ),
						),
						'provider_metadata' => array(
							'type'        => 'object',
							'description' => esc_html__( 'Information about the provider that generated the image.', 'ai' ),
							'properties'  => array(
								'id'   => array(
									'type'        => 'string',
									'description' => esc_html__( 'The provider ID.', 'ai' ),
								),
								'name' => array(
									'type'        => 'string',
									'description' => esc_html__( 'The provider name.', 'ai' ),
								),
								'type' => array(
									'type'        => 'string',
									'description' => esc_html__( 'The provider type.', 'ai' ),
								),
							),
						),
						'model_metadata'    => array(
							'type'        => 'object',
							'description' => esc_html__( 'Information about the model that generated the image.', 'ai' ),
							'properties'  => array(
								'id'   => array(
									'type'        => 'string',
									'description' => esc_html__( 'The model ID.', 'ai' ),
								),
								'name' => array(
									'type'        => 'string',
									'description' => esc_html__( 'The model name.', 'ai' ),
								),
							),
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
		$reference_image = ! empty( $input['reference'] ) ? (string) $input['reference'] : null;

		// Generate the image.
		$result = $this->generate_image( $input['prompt'], $reference_image );

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
				esc_html__( 'You do not have permission to generate images.', 'ai' )
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
	 * Generates an image from the given prompt.
	 *
	 * @since 0.2.0
	 *
	 * @param string $prompt The prompt to generate an image from.
	 * @param string|null $reference_image Optional base64-encoded image to use as a reference for edits.
	 * @return array{data: string, provider_metadata: array<string, string>, model_metadata: array<string, string>}|\WP_Error The generated image data, or a WP_Error on failure.
	 */
	protected function generate_image( string $prompt, ?string $reference_image = null ) { // phpcs:ignore Generic.NamingConventions.ConstructorName.OldStyle
		$prompt_builder = $this->get_prompt_builder( $prompt, $reference_image );

		if ( is_wp_error( $prompt_builder ) ) {
			return $prompt_builder;
		}

		// Generate the image using the AI client.
		$result = $prompt_builder->generate_image_result();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = array(
			'data'              => '',
			'provider_metadata' => array(),
			'model_metadata'    => array(),
		);

		try {
			// Get the File from the result.
			$image_file = $result->toImageFile();

			// Extract the base64 encoded image data.
			$data['data'] = sanitize_text_field( trim( $image_file->getBase64Data() ?? '' ) );

			if ( empty( $data['data'] ) ) {
				return new WP_Error(
					'no_image_data',
					esc_html__( 'No image data was generated.', 'ai' )
				);
			}

			// Get details about the provider and model that generated the image.
			$data['provider_metadata'] = $result->getProviderMetadata()->toArray();
			$data['model_metadata']    = $result->getModelMetadata()->toArray();

			// Remove data we don't care about.
			unset( $data['provider_metadata'][ ProviderMetadata::KEY_CREDENTIALS_URL ] );
			unset( $data['model_metadata'][ ModelMetadata::KEY_SUPPORTED_OPTIONS ] );
			unset( $data['model_metadata'][ ModelMetadata::KEY_SUPPORTED_CAPABILITIES ] );
		} catch ( Throwable $t ) {
			return new WP_Error(
				'no_image_data',
				esc_html__( 'No image data was generated.', 'ai' ),
				$t
			);
		}

		return $data;
	}

	/**
	 * Gets a prompt builder for generating an image.
	 *
	 * @since 0.6.0
	 *
	 * @param string $prompt The prompt to generate an image from.
	 * @param string|null $reference_image Optional base64-encoded image to use as a reference for edits.
	 * @return \WP_AI_Client_Prompt_Builder|\WP_Error The prompt builder, or a WP_Error on failure.
	 */
	private function get_prompt_builder( string $prompt, ?string $reference_image = null ) {
		$request_options = new RequestOptions();
		$request_options->setTimeout( 90 );

		$prompt_builder = wp_ai_client_prompt( $prompt )
			->using_request_options( $request_options )
			->as_output_file_type( FileTypeEnum::inline() )
			->using_model_preference( ...get_preferred_image_models() );

		// Inject guidelines as a system instruction to match other abilities.
		$guidelines = $this->get_guidelines_for_prompt();
		if ( $guidelines ) {
			$instruction  = 'The following guidelines represent the site&#039;s editorial standards. Apply them where relevant. Do not fabricate content to satisfy guidelines. If guidelines conflict with the input, prioritize accuracy.';
			$instruction .= "\n\n" . $guidelines;
			$prompt_builder->using_system_instruction( $instruction );
		}

		if ( null !== $reference_image ) {
			try {
				$file           = new File( $reference_image );
				$prompt_builder = $prompt_builder->with_file( $file );
			} catch ( Throwable $t ) {
				return new WP_Error(
					'invalid_reference',
					esc_html__( 'The reference image is not valid base64-encoded data.', 'ai' )
				);
			}
		}

		$error_message = esc_html__( 'Image generation failed. Please ensure you have a connected provider that supports image generation.', 'ai' );

		if ( null !== $reference_image ) {
			$error_message = esc_html__( 'Image refinement failed. Please ensure you have a connected provider that supports image refinement, not just image generation.', 'ai' );
		}

		return $this->ensure_image_generation_supported( $prompt_builder, $error_message );
	}
}
