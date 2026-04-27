<?php
/**
 * Abstract Ability base class.
 *
 * @package WordPress\AI\Abstracts
 */

declare( strict_types=1 );

namespace WordPress\AI\Abstracts;

use ReflectionClass;
use WP_Ability;
use WP_Error;

use function WordPress\AI\format_guidelines_for_prompt;

/**
 * Base implementation for a WordPress Ability.
 *
 * @since 0.1.0
 */
abstract class Abstract_Ability extends WP_Ability {

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param string              $name       The name of the ability.
	 * @param array<string,mixed> $properties The properties of the ability. Must include `label`.
	 */
	public function __construct( string $name, array $properties = array() ) {
		parent::__construct(
			$name,
			array(
				'label'               => $properties['label'] ?? '',
				'description'         => $properties['description'] ?? '',
				'category'            => $this->category(),
				'input_schema'        => $this->input_schema(),
				'output_schema'       => $this->output_schema(),
				'execute_callback'    => array( $this, 'execute_callback' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'meta'                => $this->meta(),
			)
		);
	}

	/**
	 * Returns the category of the ability.
	 *
	 * @since 0.1.0
	 *
	 * @return string The category of the ability.
	 */
	protected function category(): string {
		return WPAI_DEFAULT_ABILITY_CATEGORY;
	}

	/**
	 * Returns the input schema of the ability.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The input schema of the ability.
	 */
	abstract protected function input_schema(): array;

	/**
	 * Returns the output schema of the ability.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The output schema of the ability.
	 */
	abstract protected function output_schema(): array;

	/**
	 * Executes the ability with the given input arguments.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $input The input arguments to the ability.
	 * @return mixed|\WP_Error The result of the ability execution, or a WP_Error on failure.
	 */
	abstract protected function execute_callback( $input );

	/**
	 * Checks whether the current user has permission to execute the ability with the given input arguments.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $input The input arguments to the ability.
	 * @return bool|\WP_Error True if the user has permission, WP_Error otherwise.
	 */
	abstract protected function permission_callback( $input );

	/**
	 * Returns the meta of the ability.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The meta of the ability.
	 */
	abstract protected function meta(): array;

	/**
	 * Normalizes the input for the Ability.
	 *
	 * Calls the parent method to run the default normalization logic
	 * and then will run any sanitize_callback functions that are defined
	 * in the input schema.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input The raw input provided for the Ability. Default `null`.
	 * @return mixed|\WP_Error The normalized input, a default from schema, `null`, or a
	 *                         `WP_Error` if a `sanitize_callback` returned one.
	 */
	public function normalize_input( $input = null ) {
		$input        = parent::normalize_input( $input );
		$input_schema = $this->get_input_schema();

		if ( empty( $input_schema ) ) {
			return $input;
		}

		return $this->sanitize_value_from_input_schema( $input, $input_schema );
	}

	/**
	 * Validates input data against the input schema.
	 *
	 * Short-circuits when normalize_input() returned a WP_Error from a sanitize callback.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The input data to validate. Default `null`.
	 * @return true|\WP_Error Returns true if valid or the WP_Error object if validation fails.
	 */
	public function validate_input( $input = null ) {
		if ( is_wp_error( $input ) ) {
			return $input;
		}

		return parent::validate_input( $input );
	}

	/**
	 * Applies input_schema sanitize_callback entries recursively.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $value The input value to sanitize.
	 * @param array<string, mixed> $schema The JSON schema fragment.
	 * @return mixed|\WP_Error The sanitized value, or a WP_Error if a callback returned one.
	 */
	protected function sanitize_value_from_input_schema( $value, array $schema ) {
		if ( isset( $schema['sanitize_callback'] ) && is_callable( $schema['sanitize_callback'] ) ) {
			$sanitized = call_user_func( $schema['sanitize_callback'], $value );
			if ( is_wp_error( $sanitized ) ) {
				return $sanitized;
			}
			return $sanitized;
		}

		$schema_type = $schema['type'] ?? null;
		$is_object   = ( 'object' === $schema_type && ! empty( $schema['properties'] ) && is_array( $schema['properties'] ) );
		if ( ! $is_object && 'object' !== $schema_type && ! empty( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
			$is_object = true;
		}

		if ( $is_object ) {
			if ( ! is_array( $value ) ) {
				return $value;
			}

			$property_schemas = $schema['properties'];
			foreach ( $property_schemas as $key => $prop_schema ) {
				if ( ! is_array( $prop_schema ) || ! array_key_exists( $key, $value ) ) {
					continue;
				}
				$sanitized = $this->sanitize_value_from_input_schema( $value[ $key ], $prop_schema );
				if ( is_wp_error( $sanitized ) ) {
					return $sanitized;
				}
				$value[ $key ] = $sanitized;
			}

			if ( isset( $schema['additionalProperties'] ) && is_array( $schema['additionalProperties'] ) ) {
				$addl = $schema['additionalProperties'];
				foreach ( $value as $key => $v ) {
					if ( array_key_exists( $key, $property_schemas ) ) {
						continue;
					}
					$sanitized = $this->sanitize_value_from_input_schema( $v, $addl );
					if ( is_wp_error( $sanitized ) ) {
						return $sanitized;
					}
					$value[ $key ] = $sanitized;
				}
			}

			return $value;
		}

		$is_array = ( 'array' === $schema_type && ! empty( $schema['items'] ) && is_array( $schema['items'] ) );
		if ( $is_array && is_array( $value ) ) {
			$items = $schema['items'];
			foreach ( $value as $i => $item ) {
				$sanitized = $this->sanitize_value_from_input_schema( $item, $items );
				if ( is_wp_error( $sanitized ) ) {
					return $sanitized;
				}
				$value[ $i ] = $sanitized;
			}
		}

		return $value;
	}

	/**
	 * Returns the guideline categories this ability uses.
	 *
	 * Override in subclasses to opt into guidelines.
	 * Return an empty array to skip guidelines (default).
	 *
	 * Valid categories: 'site', 'copy', 'images', 'additional'.
	 *
	 * @since 0.8.0
	 *
	 * @return list<string> Guideline category slugs.
	 */
	protected function guideline_categories(): array {
		return array();
	}

	/**
	 * Returns formatted guidelines for prompt injection.
	 *
	 * Uses guideline_categories() to determine which categories to include.
	 * Unsupported categories are silently dropped.
	 * Returns empty string when guidelines are unavailable or no categories declared.
	 *
	 * @since 0.8.0
	 *
	 * @param string|null $block_name Optional block name for block-specific guidelines.
	 * @return string Formatted guidelines XML string, or empty string.
	 */
	protected function get_guidelines_for_prompt( ?string $block_name = null ): string {
		$categories = array_values(
			array_intersect(
				$this->guideline_categories(),
				array( 'site', 'copy', 'images', 'additional' )
			)
		);
		if ( empty( $categories ) ) {
			return '';
		}
		return format_guidelines_for_prompt( $categories, $block_name );
	}

	/**
	 * Gets the system instruction for the feature.
	 *
	 * When guideline_categories() returns a non-empty array and guidelines are
	 * available, automatically appends them to the system instruction.
	 *
	 * Supports a reserved `block_name` key in `$data` for block-specific guidelines.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null            $filename   Optional. Explicit filename to load. If not provided,
	 *                                           attempts to load `system-instruction.php` or `prompt.php`.
	 * @param array<string, mixed>   $data       Optional. Data to expose to the system instruction file.
	 *                                           This data will be extracted as variables available in the file scope.
	 * @return string The system instruction for the feature.
	 */
	public function get_system_instruction( ?string $filename = null, array $data = array() ): string {
		$block_name = null;
		if ( isset( $data['block_name'] ) && is_string( $data['block_name'] ) ) {
			$block_name = $data['block_name'];
			unset( $data['block_name'] );
		}

		$instruction = $this->load_system_instruction_from_file( $filename, $data );

		if ( '' !== $instruction && ! empty( $this->guideline_categories() ) ) {
			$guidelines = $this->get_guidelines_for_prompt( $block_name );

			if ( $guidelines ) {
				$instruction .= "\n\n" . 'The following guidelines represent the site&#039;s editorial standards. Apply them where relevant. Do not fabricate content to satisfy guidelines. If guidelines conflict with the input, prioritize accuracy.';
				$instruction .= "\n\n" . $guidelines;
			}
		}

		/**
		 * Filters the system instruction for an ability.
		 *
		 * @since 0.7.0
		 *
		 * @param string $instruction The system instruction text.
		 * @param string $name        The name of the ability.
		 * @param array  $data        The data passed to the system instruction file.
		 */
		return apply_filters( 'wpai_system_instruction', $instruction, $this->get_name(), $data );
	}

	/**
	 * Loads system instruction from a PHP file in the feature's directory.
	 *
	 * PHP files should return a string directly, e.g.:
	 * ```php
	 * <?php
	 * return 'Your system instruction text here...';
	 * ```
	 *
	 * If data is provided, it will be extracted as variables available in the file scope.
	 * For example, if you pass `array( 'length' => 'short' )`, the variable `$length`
	 * will be available in the system instruction file.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null          $filename Optional. Explicit filename to load. If not provided,
	 *                                       attempts to load `system-instruction.php`.
	 * @param array<string, mixed> $data     Optional. Data to expose to the system instruction file.
	 *                                       This data will be extracted as variables available in the file scope.
	 * @return string The contents of the file, or empty string if file not found.
	 */
	protected function load_system_instruction_from_file( ?string $filename = null, array $data = array() ): string {
		// Get the feature's directory using reflection.
		$reflection = new ReflectionClass( $this );
		$file_name  = $reflection->getFileName();

		if ( ! $file_name ) {
			return '';
		}

		$feature_dir = dirname( $file_name );

		// Extract data into variables for use in the included file.
		if ( ! empty( $data ) ) {
			extract( $data, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		}

		// If explicit filename provided, use it.
		if ( null !== $filename ) {
			$file_path = trailingslashit( $feature_dir ) . $filename;

			if ( file_exists( $file_path ) && is_readable( $file_path ) ) {
				// PHP files should return a string directly.
				$content = require $file_path; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable

				return is_string( $content ) ? $content : '';
			}

			return '';
		}

		// Automatic detection if no filename provided.
		$file_path = trailingslashit( $feature_dir ) . 'system-instruction.php';

		if ( file_exists( $file_path ) && is_readable( $file_path ) ) {
			// PHP files should return a string directly.
			$content = require $file_path; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable

			return is_string( $content ) ? $content : '';
		}

		return '';
	}

	/**
	 * Ensures the prompt builder can run text generation.
	 *
	 * @since 0.7.0
	 *
	 * @param \WP_AI_Client_Prompt_Builder $prompt_builder The configured prompt builder.
	 * @param string                       $message        User-visible error message.
	 * @return \WP_AI_Client_Prompt_Builder|\WP_Error The prompt builder, or a WP_Error on failure.
	 */
	protected function ensure_text_generation_supported( $prompt_builder, string $message ) {
		if ( ! $prompt_builder->is_supported_for_text_generation() ) {
			return new WP_Error( 'unsupported_model', $message );
		}

		return $prompt_builder;
	}

	/**
	 * Ensures the prompt builder can run image generation.
	 *
	 * @since 0.7.0
	 *
	 * @param \WP_AI_Client_Prompt_Builder $prompt_builder The configured prompt builder.
	 * @param string                       $message        User-visible error message.
	 * @return \WP_AI_Client_Prompt_Builder|\WP_Error The prompt builder, or a WP_Error on failure.
	 */
	protected function ensure_image_generation_supported( $prompt_builder, string $message ) {
		if ( ! $prompt_builder->is_supported_for_image_generation() ) {
			return new WP_Error( 'unsupported_model', $message );
		}

		return $prompt_builder;
	}
}
