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
		return 'ai-experiments';
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
	 * Gets the system instruction for the feature.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $filename Optional. Explicit filename to load. If not provided,
	 *                              attempts to load `system-instruction.md` or `prompt.md`.
	 * @return string The system instruction for the feature.
	 */
	public function get_system_instruction( ?string $filename = null ): string {
		return $this->load_system_instruction_from_file( $filename );
	}

	/**
	 * Loads system instruction from a markdown file in the feature's directory.
	 *
	 * Supports both automatic detection (looks for `system-instruction.md` or `prompt.md`)
	 * and explicit file paths. Returns empty string if file is not found, maintaining
	 * backward compatibility with hardcoded system instructions.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $filename Optional. Explicit filename to load. If not provided,
	 *                              attempts to load `system-instruction.md` or `prompt.md`.
	 * @return string The contents of the file, or empty string if file not found.
	 */
	protected function load_system_instruction_from_file( ?string $filename = null ): string {
		// Get the feature's directory using reflection.
		$reflection = new ReflectionClass( $this );
		$file_name  = $reflection->getFileName();

		if ( ! $file_name ) {
			return '';
		}

		$feature_dir = dirname( $file_name );

		// If explicit filename provided, use it.
		if ( null !== $filename ) {
			$file_path = trailingslashit( $feature_dir ) . $filename;

			if ( file_exists( $file_path ) && is_readable( $file_path ) ) {
				$content = file_get_contents( $file_path ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
				return false !== $content ? trim( wp_strip_all_tags( $content ) ) : '';
			}

			return '';
		}

		// Automatic detection: first `system-instruction.md`, then `prompt.md`.
		$possible_files = array( 'system-instruction.md', 'prompt.md' );
		foreach ( $possible_files as $possible_file ) {
			$file_path = trailingslashit( $feature_dir ) . $possible_file;

			if ( file_exists( $file_path ) && is_readable( $file_path ) ) {
				$content = file_get_contents( $file_path ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
				return false !== $content ? trim( wp_strip_all_tags( $content ) ) : '';
			}
		}

		return '';
	}
}
