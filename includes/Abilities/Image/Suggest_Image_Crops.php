<?php
/**
 * Image crop suggestion Ability implementation.
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
 * Image crop suggestion WordPress Ability.
 *
 * Uses AI vision models to detect the dominant subject of an image, propose a
 * focal point, and recommend a crop window for each requested aspect ratio.
 *
 * @since x.x.x
 */
class Suggest_Image_Crops extends Abstract_Ability {

	use Resolves_Image_Reference;

	/**
	 * Default aspect ratios proposed when the caller does not supply any.
	 * Square / portrait / landscape
	 *
	 * @since x.x.x
	 *
	 * @var array<string>
	 */
	private $default_aspect_ratios = array(
		'1:1',
		'3:4',
		'16:9',
	);

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function guideline_categories(): array {
		return array( 'site', 'images' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'attachment_id' => array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'description'       => esc_html__( 'The attachment ID of the image to suggest crops for.', 'ai' ),
				),
				'image_url'     => array(
					'type'              => 'string',
					'sanitize_callback' => array( $this, 'sanitize_image_reference_input' ),
					'description'       => esc_html__( 'URL or data URI of the image to suggest crops for. Used if attachment_id is not provided.', 'ai' ),
				),
				'aspect_ratios' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'default'     => $this->default_aspect_ratios,
					'description' => esc_html__( 'Aspect ratios to suggest crops for, formatted as "W:H" (e.g. "1:1", "3:4", "16:9"). Defaults to square, portrait, and landscape.', 'ai' ),
				),
				'context'       => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_textarea_field',
					'description'       => esc_html__( 'Optional context about how the image will be used to inform the focal point and crop choices.', 'ai' ),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'focal_point' => array(
					'type'        => 'object',
					'description' => esc_html__( 'Suggested focal point as normalized coordinates in [0, 1] from the image top-left.', 'ai' ),
					'properties'  => array(
						'x' => array(
							'type'        => 'number',
							'description' => esc_html__( 'Focal point x position, 0 (left) to 1 (right).', 'ai' ),
						),
						'y' => array(
							'type'        => 'number',
							'description' => esc_html__( 'Focal point y position, 0 (top) to 1 (bottom).', 'ai' ),
						),
					),
				),
				'crops'       => array(
					'type'        => 'array',
					'description' => esc_html__( 'Suggested crop windows, one per requested aspect ratio.', 'ai' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'aspect_ratio' => array(
								'type'        => 'string',
								'description' => esc_html__( 'Requested aspect ratio this crop satisfies (e.g. "1:1").', 'ai' ),
							),
							'x'            => array(
								'type'        => 'number',
								'description' => esc_html__( 'Crop top-left x position as a fraction of image width.', 'ai' ),
							),
							'y'            => array(
								'type'        => 'number',
								'description' => esc_html__( 'Crop top-left y position as a fraction of image height.', 'ai' ),
							),
							'width'        => array(
								'type'        => 'number',
								'description' => esc_html__( 'Crop width as a fraction of image width.', 'ai' ),
							),
							'height'       => array(
								'type'        => 'number',
								'description' => esc_html__( 'Crop height as a fraction of image height.', 'ai' ),
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
	 * @since x.x.x
	 */
	protected function execute_callback( $input ) {
		$args = wp_parse_args(
			$input,
			array(
				'attachment_id' => null,
				'image_url'     => null,
				'aspect_ratios' => $this->default_aspect_ratios,
				'context'       => '',
			)
		);

		// Make sure we can resolve the image first.
		$image_reference = $this->resolve_image_reference( $args );
		if ( is_wp_error( $image_reference ) ) {
			return $image_reference;
		}

		/**
		 * Filters the aspect ratios to suggest crops for.
		 *
		 * @since x.x.x
		 *
		 * @param array<string> $aspect_ratios The aspect ratios to suggest crops for.
		 * @param array         $args          The arguments passed to the ability.
		 * @return array<string> The filtered aspect ratios.
		 */
		$aspect_ratios = apply_filters( 'wpai_suggest_image_crops_aspect_ratios', $args['aspect_ratios'], $args );
		$aspect_ratios = $this->normalize_aspect_ratios( $aspect_ratios );

		if ( empty( $aspect_ratios ) ) {
			return new WP_Error(
				'invalid_aspect_ratios',
				esc_html__( 'Provide at least one aspect ratio formatted as "W:H".', 'ai' )
			);
		}

		$response = $this->generate_suggestions(
			$image_reference,
			$aspect_ratios,
			normalize_content( (string) $args['context'] )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $this->parse_suggestions( $response, $aspect_ratios );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
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

			if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
				return new WP_Error(
					'insufficient_capabilities',
					esc_html__( 'You do not have permission to edit this attachment.', 'ai' )
				);
			}
		} elseif ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error(
				'insufficient_capabilities',
				esc_html__( 'You do not have permission to suggest image crops.', 'ai' )
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
			'mcp'          => array(
				'public'   => true,
				'type'     => 'tool',
				'category' => 'media',
			),
		);
	}

	/**
	 * Normalizes the aspect_ratios input.
	 *
	 * Accepts an array of strings or a single comma-separated string.
	 * Invalid entries are dropped. Duplicates are removed while preserving order.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $value Raw input from the caller.
	 * @return array<string> Normalized aspect ratio strings.
	 */
	protected function normalize_aspect_ratios( $value ): array {
		if ( is_string( $value ) ) {
			$value = array_map( 'trim', explode( ',', $value ) );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $value as $entry ) {
			if ( ! is_string( $entry ) ) {
				continue;
			}

			$entry = trim( $entry );
			if ( ! preg_match( '/^(\d+):(\d+)$/', $entry, $matches ) ) {
				continue;
			}

			if ( 0 === (int) $matches[1] || 0 === (int) $matches[2] ) {
				continue;
			}

			$normalized[ $entry ] = $entry;
		}

		return array_values( $normalized );
	}

	/**
	 * Calls the AI model and returns the raw JSON response string.
	 *
	 * @since x.x.x
	 *
	 * @param string        $image_reference Data URI for the image.
	 * @param array<string> $aspect_ratios   Validated aspect ratio strings.
	 * @param string        $context         Optional caller context for the prompt.
	 * @return string|\WP_Error Raw JSON response on success, WP_Error on failure.
	 */
	protected function generate_suggestions( string $image_reference, array $aspect_ratios, string $context = '' ) {
		$prompt_builder = $this->get_prompt_builder(
			$this->build_prompt( $context ),
			$image_reference,
			$aspect_ratios
		);

		if ( is_wp_error( $prompt_builder ) ) {
			return $prompt_builder;
		}

		return $prompt_builder->generate_text();
	}

	/**
	 * Builds the user prompt for the model.
	 *
	 * The requested aspect ratios are injected into the system instruction
	 * (see suggest-image-crops-system-instruction.php), so the user prompt only
	 * carries the analysis request and any optional caller context.
	 *
	 * @since x.x.x
	 *
	 * @param string $context Optional caller context.
	 * @return string The composed prompt.
	 */
	protected function build_prompt( string $context = '' ): string {
		$prompt = __( 'Analyze the image and suggest a focal point and crop window for each requested aspect ratio.', 'ai' );

		if ( '' !== $context ) {
			$prompt .= "\n\n<additional-context>" . $context . '</additional-context>';
		}

		return $prompt;
	}

	/**
	 * Configures the prompt builder for vision + structured JSON output.
	 *
	 * @since x.x.x
	 *
	 * @param string        $prompt        The composed user prompt.
	 * @param string        $reference     Data URI for the input image.
	 * @param array<string> $aspect_ratios Validated aspect ratio strings to expose in the system instruction.
	 * @return \WP_AI_Client_Prompt_Builder|\WP_Error The prompt builder, or WP_Error on failure.
	 */
	private function get_prompt_builder( string $prompt, string $reference, array $aspect_ratios ) {
		$system_instruction = $this->get_system_instruction(
			'suggest-image-crops-system-instruction.php',
			array( 'aspect_ratios' => $aspect_ratios )
		);

		$prompt_builder = wp_ai_client_prompt( $prompt )
			->with_file( $reference )
			->using_system_instruction( $system_instruction )
			->using_temperature( 0.2 )
			->using_model_preference( ...get_preferred_vision_models() )
			->as_json_response( $this->response_schema() );

		return $this->ensure_text_generation_supported(
			$prompt_builder,
			esc_html__( 'Image crop suggestion failed. Please ensure you have a connected provider that supports both text generation and vision capabilities.', 'ai' )
		);
	}

	/**
	 * JSON schema sent to the model to constrain its structured output.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> JSON schema for the model response.
	 */
	protected function response_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'focal_point' => array(
					'type'                 => 'object',
					'properties'           => array(
						'x' => array( 'type' => 'number' ),
						'y' => array( 'type' => 'number' ),
					),
					'required'             => array( 'x', 'y' ),
					'additionalProperties' => false,
				),
				'crops'       => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'aspect_ratio' => array( 'type' => 'string' ),
							'x'            => array( 'type' => 'number' ),
							'y'            => array( 'type' => 'number' ),
							'width'        => array( 'type' => 'number' ),
							'height'       => array( 'type' => 'number' ),
						),
						'required'             => array( 'aspect_ratio', 'x', 'y', 'width', 'height' ),
						'additionalProperties' => false,
					),
				),
			),
			'required'             => array( 'focal_point', 'crops' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Parses, validates, and sanitizes the model response.
	 *
	 * Drops invalid crops. Returns WP_Error if the response is unparseable,
	 * the focal point is missing, or every crop is invalid.
	 *
	 * @since x.x.x
	 *
	 * @param string        $response      Raw JSON string from the model.
	 * @param array<string> $aspect_ratios Validated aspect ratio strings the caller asked for.
	 * @return array{focal_point: array{x: float, y: float}, crops: list<array{aspect_ratio: string, x: float, y: float, width: float, height: float}>}|\WP_Error Parsed result or error.
	 */
	protected function parse_suggestions( string $response, array $aspect_ratios ) {
		$decoded = json_decode( $response, true );

		if ( ! is_array( $decoded ) ) {
			return new WP_Error(
				'invalid_response',
				esc_html__( 'Could not parse AI response as valid suggestions.', 'ai' )
			);
		}

		$focal_point = $this->parse_focal_point( $decoded['focal_point'] ?? null );
		if ( is_wp_error( $focal_point ) ) {
			return $focal_point;
		}

		$requested_set = array_flip( $aspect_ratios );
		$crops         = array();
		$raw_crops     = is_array( $decoded['crops'] ?? null ) ? $decoded['crops'] : array();

		foreach ( $raw_crops as $raw_crop ) {
			$crop = $this->parse_crop( $raw_crop, $focal_point, $requested_set );
			if ( null === $crop ) {
				continue;
			}
			$crops[] = $crop;
		}

		if ( empty( $crops ) ) {
			return new WP_Error(
				'no_valid_crops',
				esc_html__( 'The model did not return any valid crop suggestions.', 'ai' )
			);
		}

		return array(
			'focal_point' => $focal_point,
			'crops'       => $crops,
		);
	}

	/**
	 * Validates and clamps the focal point from the model response.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $raw Raw `focal_point` value from the decoded response.
	 * @return array{x: float, y: float}|\WP_Error Clamped focal point, or WP_Error if missing/invalid.
	 */
	protected function parse_focal_point( $raw ) {
		if (
			! is_array( $raw ) ||
			! isset( $raw['x'], $raw['y'] ) ||
			! is_numeric( $raw['x'] ) ||
			! is_numeric( $raw['y'] )
		) {
			return new WP_Error(
				'invalid_focal_point',
				esc_html__( 'The model did not return a valid focal point.', 'ai' )
			);
		}

		return array(
			'x' => $this->clamp_unit( (float) $raw['x'] ),
			'y' => $this->clamp_unit( (float) $raw['y'] ),
		);
	}

	/**
	 * Validates a single crop entry from the model response.
	 *
	 * Returns null when the crop is invalid (not an array, not in the requested aspect ratios, etc...)
	 *
	 * @since x.x.x
	 *
	 * @param mixed                     $raw           Raw crop entry from the decoded response.
	 * @param array{x: float, y: float} $focal_point   Clamped focal point.
	 * @param array<string, int>        $requested_set Lookup of requested aspect ratio strings.
	 * @return array{aspect_ratio: string, x: float, y: float, width: float, height: float}|null Parsed crop, or null when the entry should be dropped.
	 */
	protected function parse_crop( $raw, array $focal_point, array $requested_set ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}

		foreach ( array( 'aspect_ratio', 'x', 'y', 'width', 'height' ) as $key ) {
			if ( ! isset( $raw[ $key ] ) ) {
				return null;
			}
		}

		$aspect_ratio = is_string( $raw['aspect_ratio'] ) ? trim( $raw['aspect_ratio'] ) : '';
		if ( '' === $aspect_ratio || ! isset( $requested_set[ $aspect_ratio ] ) ) {
			return null;
		}

		if (
			! is_numeric( $raw['x'] ) ||
			! is_numeric( $raw['y'] ) ||
			! is_numeric( $raw['width'] ) ||
			! is_numeric( $raw['height'] )
		) {
			return null;
		}

		$x      = $this->clamp_unit( (float) $raw['x'] );
		$y      = $this->clamp_unit( (float) $raw['y'] );
		$width  = $this->clamp_unit( (float) $raw['width'] );
		$height = $this->clamp_unit( (float) $raw['height'] );

		if ( $width <= 0.0 || $height <= 0.0 ) {
			return null;
		}

		// Clamp width/height so the crop stays inside the image.
		$width  = min( $width, 1.0 - $x );
		$height = min( $height, 1.0 - $y );

		if ( $width <= 0.0 || $height <= 0.0 ) {
			return null;
		}

		// Focal point must lie inside the crop window.
		$max_x = $x + $width;
		$max_y = $y + $height;
		if (
			$focal_point['x'] < $x ||
			$focal_point['x'] > $max_x ||
			$focal_point['y'] < $y ||
			$focal_point['y'] > $max_y
		) {
			return null;
		}

		return array(
			'aspect_ratio' => $aspect_ratio,
			'x'            => $x,
			'y'            => $y,
			'width'        => $width,
			'height'       => $height,
		);
	}

	/**
	 * Clamps a value into the [0, 1] interval.
	 *
	 * @since x.x.x
	 *
	 * @param float $value Value to clamp.
	 * @return float Clamped value.
	 */
	protected function clamp_unit( float $value ): float {
		return max( 0.0, min( 1.0, $value ) );
	}
}
