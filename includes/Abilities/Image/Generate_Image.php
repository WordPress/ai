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
use WordPress\AI_Client\AI_Client;
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
	 * @since 0.2.0
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
	 * @return array{data: string, provider_metadata: array<string, string>, model_metadata: array<string, string>}|\WP_Error The generated image data, provider metadata, and model metadata, or a WP_Error if there was an error.
	 */
	protected function generate_image( string $prompt ) { // phpcs:ignore Generic.NamingConventions.ConstructorName.OldStyle
		$request_options = new RequestOptions();
		$request_options->setTimeout( 90 );

		// Generate the image using the AI client.
		$result = AI_Client::prompt_with_wp_error( $prompt )
			->using_request_options( $request_options )
			->as_output_file_type( FileTypeEnum::inline() )
			->using_model_preference( ...get_preferred_image_models() )
			->generate_image_result();

		// If we have an error, return it.
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
			$file = $result->toImageFile();

			// Return the base64 encoded image data.
			$data['data'] = sanitize_text_field( trim( $file->getBase64Data() ?? '' ) );

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
}
