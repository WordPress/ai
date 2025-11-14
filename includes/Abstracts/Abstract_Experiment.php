<?php
/**
 * Abstract Experiment base class.
 *
 * @package WordPress\AI\Abstracts
 */

declare( strict_types=1 );

namespace WordPress\AI\Abstracts;

use WordPress\AI\Contracts\Experiment;
use WordPress\AI\Exception\Invalid_Experiment_Metadata_Exception;

/**
 * Base implementation for experiments.
 *
 * Provides common functionality for all experiments including enable/disable state.
 *
 * @since 0.1.0
 */
abstract class Abstract_Experiment implements Experiment {
	/**
	 * Experiment identifier.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	protected string $id;

	/**
	 * Experiment label.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	protected string $label;

	/**
	 * Experiment description.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	protected string $description;

	/**
	 * Constructor.
	 *
	 * Loads experiment metadata and initializes properties.
	 *
	 * @since 0.1.0
	 *
	 * @throws \WordPress\AI\Exception\Invalid_Experiment_Metadata_Exception If experiment metadata is invalid.
	 */
	final public function __construct() {
		$metadata = $this->load_experiment_metadata();

		if ( empty( $metadata['id'] ) ) {
			throw new Invalid_Experiment_Metadata_Exception(
				esc_html__( 'Experiment id is required in load_experiment_metadata().', 'ai' )
			);
		}

		if ( empty( $metadata['label'] ) ) {
			throw new Invalid_Experiment_Metadata_Exception(
				esc_html__( 'Experiment label is required in load_experiment_metadata().', 'ai' )
			);
		}

		if ( empty( $metadata['description'] ) ) {
			throw new Invalid_Experiment_Metadata_Exception(
				esc_html__( 'Experiment description is required in load_experiment_metadata().', 'ai' )
			);
		}

		$this->id          = $metadata['id'];
		$this->label       = $metadata['label'];
		$this->description = $metadata['description'];
	}

	/**
	 * Loads experiment metadata.
	 *
	 * Must return an array with keys: id, label, description.
	 *
	 * @since 0.1.0
	 *
	 * @return array{id: string, label: string, description: string} Experiment metadata.
	 */
	abstract protected function load_experiment_metadata(): array;

	/**
	 * Gets the experiment ID.
	 *
	 * @since 0.1.0
	 *
	 * @return string Experiment identifier.
	 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * Gets the experiment label.
	 *
	 * @since 0.1.0
	 *
	 * @return string Translated experiment label.
	 */
	public function get_label(): string {
		return $this->label;
	}

	/**
	 * Gets the experiment description.
	 *
	 * @since 0.1.0
	 *
	 * @return string Translated experiment description.
	 */
	public function get_description(): string {
		return $this->description;
	}

	/**
	 * Checks if experiment is enabled.
	 *
	 * Experiments require both the global toggle and individual experiment toggle to be enabled.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True if enabled, false otherwise.
	 */
	final public function is_enabled(): bool {
		// Check global experiments toggle first.
		$global_enabled = (bool) get_option( 'ai_experiments_enabled', false );
		if ( ! $global_enabled ) {
			return false;
		}

		// Check experiment-specific option.
		$experiment_enabled = (bool) get_option( "ai_experiment_{$this->id}_enabled", false );

		/**
		 * Filters the enabled status for a specific experiment.
		 *
		 * The dynamic portion of the hook name, `$this->id`, refers to the experiment ID.
		 *
		 * @since 0.1.0
		 *
		 * @param bool $experiment_enabled Whether the experiment is enabled.
		 */
		return (bool) apply_filters( "ai_experiment_{$this->id}_enabled", $experiment_enabled );
	}

	/**
	 * Registers experiment-specific settings.
	 *
	 * Override this method in child classes to register custom settings sections or fields
	 * using WordPress Settings API (register_setting, add_settings_section, add_settings_field).
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register_settings(): void {
		// Default implementation does nothing.
		// Child classes can override to register custom settings.
	}

	/**
	 * Registers the experiment.
	 *
	 * Must be implemented by child classes to set up hooks and functionality.
	 *
	 * @since 0.1.0
	 */
	abstract public function register(): void;
}
