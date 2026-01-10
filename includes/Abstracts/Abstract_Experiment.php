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
use WordPress\AI\Settings\Settings_Registration;

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
	 * Cache for this experiment's enabled status.
	 *
	 * @since 0.1.0
	 * @var bool|null
	 */
	private ?bool $enabled_cache = null;

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
	 * Results are cached per instance to avoid redundant option lookups and filter calls.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True if enabled, false otherwise.
	 */
	final public function is_enabled(): bool {
		// Return cached result if available.
		if ( null !== $this->enabled_cache ) {
			return $this->enabled_cache;
		}

		// Check global experiments toggle first.
		$global_enabled = (bool) get_option( Settings_Registration::GLOBAL_OPTION, false );
		if ( ! $global_enabled ) {
			$this->enabled_cache = false;
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
		$is_enabled = (bool) apply_filters( "ai_experiments_experiment_{$this->id}_enabled", $experiment_enabled );

		// Cache the result.
		$this->enabled_cache = $is_enabled;

		return $is_enabled;
	}

	/**
	 * Registers experiment-specific settings.
	 *
	 * Override this method in child classes to register custom settings options
	 * using WordPress Settings API (register_setting).
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
	 * Provides contextual entry points for the experiment.
	 *
	 * Child classes can override to return an array of links, for example:
	 * array(
	 *     array(
	 *         'label' => __( 'Try', 'ai' ),
	 *         'url'   => admin_url( 'post-new.php' ),
	 *         'type'  => 'try',
	 *     ),
	 *     array(
	 *         'label' => __( 'Dashboard', 'ai' ),
	 *         'url'   => admin_url( 'admin.php?page=ai-mcp' ),
	 *         'type'  => 'dashboard',
	 *     ),
	 * );
	 *
	 * @since x.x.x
	 *
	 * @return array<int, array{label: string, url: string, type?: string}>
	 */
	public function get_entry_points(): array {
		return array();
	}

	/**
	 * Checks if the experiment has custom settings.
	 *
	 * Override this method in child classes that have settings to return true.
	 *
	 * @since x.x.x
	 *
	 * @return bool True if the experiment has settings, false otherwise.
	 */
	public function has_settings(): bool {
		return false;
	}

	/**
	 * Returns DataForm-compatible field definitions for experiment settings.
	 *
	 * Override in child classes to define custom settings fields.
	 * Each field should follow the @wordpress/dataviews field schema.
	 *
	 * @since x.x.x
	 *
	 * @return array<int, array{id: string, type: string, label: string, description?: string, elements?: array<int, array{value: string, label: string}>}> DataForm fields.
	 */
	public function get_settings_fields(): array {
		return array();
	}

	/**
	 * Returns current values for experiment settings.
	 *
	 * Override in child classes to return settings data.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> Settings values keyed by field ID.
	 */
	public function get_settings_values(): array {
		return array();
	}

	/**
	 * Updates experiment settings from DataForm data.
	 *
	 * Override in child classes to handle settings persistence.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $data Settings data from DataForm.
	 * @return bool True on success, false on failure.
	 */
	public function update_settings( array $data ): bool {
		return true;
	}

	/**
	 * Gets the option name for a custom experiment setting field.
	 *
	 * Generates a properly namespaced option name for experiment-specific settings.
	 * Use this when registering and rendering custom settings fields to ensure
	 * consistent naming across the plugin.
	 *
	 * @since 0.1.0
	 *
	 * @param string $option_name The base option name (e.g., 'api_key', 'temperature').
	 * @return string The fully namespaced option name.
	 */
	final protected function get_field_option_name( string $option_name ): string {
		return "ai_experiment_{$this->id}_field_{$option_name}";
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
