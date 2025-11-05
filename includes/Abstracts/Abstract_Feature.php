<?php
/**
 * Abstract Feature base class.
 *
 * @package WordPress\AI\Abstracts
 */

namespace WordPress\AI\Abstracts;

use ReflectionClass;
use WordPress\AI\Contracts\Feature;
use WordPress\AI\Exception\Invalid_Feature_Metadata_Exception;

/**
 * Base implementation for features.
 *
 * Provides common functionality for all features including enable/disable state.
 *
 * @since 0.1.0
 */
abstract class Abstract_Feature implements Feature {
	/**
	 * Feature identifier.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	protected $id;

	/**
	 * Feature label.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	protected $label;

	/**
	 * Feature description.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	protected $description;

	/**
	 * Whether the feature is enabled.
	 *
	 * @since 0.1.0
	 * @var bool
	 */
	private $enabled = true;

	/**
	 * System instruction to send to the LLM.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	protected $system_instruction = '';

	/**
	 * Constructor.
	 *
	 * Loads feature metadata and initializes properties.
	 *
	 * @since 0.1.0
	 *
	 * @throws \WordPress\AI\Exception\Invalid_Feature_Metadata_Exception If feature metadata is invalid.
	 */
	final public function __construct() {
		$metadata = $this->load_feature_metadata();

		if ( empty( $metadata['id'] ) ) {
			throw new Invalid_Feature_Metadata_Exception(
				esc_html__( 'Feature id is required in load_feature_metadata().', 'ai' )
			);
		}

		if ( empty( $metadata['label'] ) ) {
			throw new Invalid_Feature_Metadata_Exception(
				esc_html__( 'Feature label is required in load_feature_metadata().', 'ai' )
			);
		}

		if ( empty( $metadata['description'] ) ) {
			throw new Invalid_Feature_Metadata_Exception(
				esc_html__( 'Feature description is required in load_feature_metadata().', 'ai' )
			);
		}

		$this->id          = $metadata['id'];
		$this->label       = $metadata['label'];
		$this->description = $metadata['description'];

		// Try to load system instruction from file.
		$loaded_instruction = $this->load_system_instruction_from_file();
		if ( ! empty( $loaded_instruction ) ) { // phpcs:ignore SlevomatCodingStandard.ControlStructures.EarlyExit.EarlyExitNotUsed
			$this->system_instruction = $loaded_instruction;
		}
	}

	/**
	 * Loads feature metadata.
	 *
	 * Must return an array with keys: id, label, description.
	 *
	 * @since 0.1.0
	 *
	 * @return array{id: string, label: string, description: string} Feature metadata.
	 */
	abstract protected function load_feature_metadata(): array;

	/**
	 * Gets the feature ID.
	 *
	 * @since 0.1.0
	 *
	 * @return string Feature identifier.
	 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * Gets the feature label.
	 *
	 * @since 0.1.0
	 *
	 * @return string Translated feature label.
	 */
	public function get_label(): string {
		return $this->label;
	}

	/**
	 * Gets the feature description.
	 *
	 * @since 0.1.0
	 *
	 * @return string Translated feature description.
	 */
	public function get_description(): string {
		return $this->description;
	}

	/**
	 * Gets the ability slug for the feature.
	 *
	 * @since 0.1.0
	 *
	 * @return string The ability slug for the feature.
	 */
	public function get_ability_slug(): string {
		return 'ai/' . $this->id;
	}

	/**
	 * Gets the system instruction for the feature.
	 *
	 * @since 0.1.0
	 *
	 * @return string The system instruction for the feature.
	 */
	public function get_system_instruction(): string {
		return $this->system_instruction;
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

	/**
	 * Checks if feature is enabled.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True if enabled, false otherwise.
	 */
	final public function is_enabled(): bool {
		$enabled = $this->enabled;

		/**
		 * Filters the enabled status for a specific feature.
		 *
		 * The dynamic portion of the hook name, `$this->id`, refers to the feature ID.
		 *
		 * @since 0.1.0
		 *
		 * @param bool $enabled Whether the feature is enabled.
		 */
		return (bool) apply_filters( "ai_feature_{$this->id}_enabled", $enabled );
	}

	/**
	 * Registers the feature.
	 *
	 * Must be implemented by child classes to set up hooks and functionality.
	 *
	 * @since 0.1.0
	 */
	abstract public function register(): void;
}
