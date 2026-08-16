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

use WordPress\AiClient\AiClient;

use function WordPress\AI\format_guidelines_for_prompt;
use function WordPress\AI\format_persona_for_prompt;
use function WordPress\AI\get_feature_developer_model_config;
use function WordPress\AI\get_preferred_models_for_text_generation;

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
	 * Returns whether this ability applies the active persona.
	 *
	 * Override in subclasses to opt into personas. Abilities that produce
	 * prose the reader sees should opt in; abilities that classify, moderate,
	 * or return structured data should not, because a voice instruction only
	 * distorts their output.
	 *
	 * @since x.x.x
	 *
	 * @return bool True when the persona should be applied. Default false.
	 */
	protected function supports_personas(): bool {
		return false;
	}

	/**
	 * Returns the formatted persona for prompt injection.
	 *
	 * Returns an empty string when the ability has not opted in, when the
	 * Personas experiment is disabled, or when no persona is selected.
	 *
	 * @since x.x.x
	 *
	 * @param int|null $post_id Optional. Post the generation relates to.
	 * @return string Formatted persona XML string, or empty string.
	 */
	protected function get_persona_for_prompt( ?int $post_id = null ): string {
		if ( ! $this->supports_personas() ) {
			return '';
		}

		return format_persona_for_prompt( $post_id );
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
	 * Supports a reserved `block_name` key in `$data` for block-specific guidelines,
	 * and a reserved `post_id` key for the post a persona override may be set on.
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

		$post_id = null;
		if ( isset( $data['post_id'] ) && is_numeric( $data['post_id'] ) ) {
			$post_id = (int) $data['post_id'];
			unset( $data['post_id'] );
		}

		$instruction = $this->load_system_instruction_from_file( $filename, $data );

		if ( '' !== $instruction && ! empty( $this->guideline_categories() ) ) {
			$guidelines = $this->get_guidelines_for_prompt( $block_name );

			if ( $guidelines ) {
				$instruction .= "\n\n" . 'The following guidelines represent the site&#039;s editorial standards. Apply them where relevant. Do not fabricate content to satisfy guidelines. If guidelines conflict with the input, prioritize accuracy.';
				$instruction .= "\n\n" . $guidelines;
			}
		}

		if ( '' !== $instruction ) {
			$persona = $this->get_persona_for_prompt( $post_id );

			if ( '' !== $persona ) {
				$instruction .= "\n\n" . 'The following persona describes the voice to write in. Adopt its role, tone, and register. It governs style only: never invent facts to fit the persona, and where it conflicts with an explicit instruction in the request, follow the request.';
				$instruction .= "\n\n" . $persona;
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
		$instruction = apply_filters( 'wpai_system_instruction', $instruction, $this->get_name(), $data );

		/**
		 * Filters the system instruction for a specific ability.
		 *
		 * The dynamic portion of the hook name, `$slug`, refers to the ability slug
		 * derived from its name (e.g. `ai/title-generation` becomes `title_generation`).
		 *
		 * This scoped filter runs after the global `wpai_system_instruction` filter,
		 * allowing developers to target a single ability without inspecting the name.
		 *
		 * @since x.x.x
		 *
		 * @param string $instruction The system instruction text.
		 * @param array  $data        The data passed to the system instruction file.
		 */
		return apply_filters( "wpai_{$this->get_ability_slug()}_system_instruction", $instruction, $data );
	}

	/**
	 * Returns the hook-safe slug for this ability.
	 *
	 * Derived from the ability name by stripping the `ai/` namespace prefix and
	 * converting hyphens to underscores. For example, `ai/title-generation`
	 * becomes `title_generation`. Used to build per-ability filter hook names.
	 *
	 * @since x.x.x
	 *
	 * @return string The hook-safe ability slug.
	 */
	protected function get_ability_slug(): string {
		$name = (string) $this->get_name();
		$name = preg_replace( '#^ai/#', '', $name );

		return str_replace( '-', '_', (string) $name );
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

	/**
	 * Sets the provider and model preference for a prompt builder based on developer mode settings.
	 *
	 * Reads the developer-configured provider/model for the given feature class and applies it
	 * to the prompt builder. Falls back to the supplied model preference list when no override
	 * is saved.
	 *
	 * @since 0.9.0
	 *
	 * @param \WP_AI_Client_Prompt_Builder $prompt_builder The prompt builder.
	 * @param class-string<\WordPress\AI\Contracts\Feature> $feature_class The feature class to read settings from.
	 * @param array<int, array{string, string}> $fallback_models The default models to use when no override is set.
	 * @return \WP_AI_Client_Prompt_Builder The prompt builder.
	 */
	protected function set_provider_model_preference( \WP_AI_Client_Prompt_Builder $prompt_builder, string $feature_class, array $fallback_models = array() ): \WP_AI_Client_Prompt_Builder {
		$config   = get_feature_developer_model_config( $feature_class::get_id() );
		$provider = $config['provider'];
		$model    = $config['model'];

		if ( $provider && $model ) {
			$prompt_builder->using_model(
				AiClient::defaultRegistry()->getProviderModel( $provider, $model )
			);
		} else {
			if ( $provider ) {
				$prompt_builder->using_provider( $provider );
			}

			if ( empty( $fallback_models ) ) {
				$fallback_models = get_preferred_models_for_text_generation();
			}

			$prompt_builder->using_model_preference( ...$fallback_models );
		}

		return $prompt_builder;
	}

	/**
	 * Filters the assembled user prompt.
	 *
	 * @since x.x.x
	 *
	 * @param string $prompt       The prompt string.
	 * @param mixed  ...$filter_args Additional arguments to pass to the filter.
	 * @return string The filtered prompt string.
	 */
	protected function filter_prompt( string $prompt, ...$filter_args ): string {
		/**
		 * Filters the assembled user prompt for the ability.
		 *
		 * @since x.x.x
		 *
		 * @param string $prompt The assembled prompt string.
		 * @param mixed  ...$filter_args Additional arguments to pass to the filter.
		 */
		return (string) apply_filters( "wpai_{$this->get_ability_slug()}_prompt", $prompt, ...$filter_args );
	}

	/**
	 * Configures a prompt builder with model preferences and applies the builder filter.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_AI_Client_Prompt_Builder      $prompt_builder  The configured prompt builder.
	 * @param class-string<\WordPress\AI\Contracts\Feature>|null $feature_class The feature class to read settings from, if any.
	 * @param array<int, array{string, string}> $fallback_models Optional fallback models for the developer override.
	 * @param mixed                             ...$filter_args  Additional arguments to pass to the builder filter.
	 * @return \WP_AI_Client_Prompt_Builder The prompt builder.
	 */
	protected function filter_prompt_builder( \WP_AI_Client_Prompt_Builder $prompt_builder, ?string $feature_class = null, array $fallback_models = array(), ...$filter_args ): \WP_AI_Client_Prompt_Builder {
		if ( $feature_class ) {
			$prompt_builder = $this->set_provider_model_preference( $prompt_builder, $feature_class, $fallback_models );
		} elseif ( ! empty( $fallback_models ) ) {
			$prompt_builder->using_model_preference( ...$fallback_models );
		}

		/**
		 * Filters the configured prompt builder for the ability.
		 *
		 * Runs after the model preference is applied and before generation
		 * support is verified. Extend the builder rather than replacing it, and
		 * always return a WP_AI_Client_Prompt_Builder.
		 *
		 * @since x.x.x
		 *
		 * @param \WP_AI_Client_Prompt_Builder $prompt_builder The configured prompt builder.
		 * @param mixed                        ...$filter_args Additional context arguments.
		 */
		$filtered_prompt_builder = apply_filters( "wpai_{$this->get_ability_slug()}_prompt_builder", $prompt_builder, ...$filter_args );

		if ( ! $filtered_prompt_builder instanceof \WP_AI_Client_Prompt_Builder ) {
			return $prompt_builder;
		}

		return $filtered_prompt_builder;
	}
}
