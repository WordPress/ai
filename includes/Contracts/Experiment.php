<?php
/**
 * Experiment interface.
 *
 * @package WordPress\AI\Contracts
 */

declare( strict_types=1 );

namespace WordPress\AI\Contracts;

/**
 * Interface for all experiments.
 *
 * Every experiment must implement this interface to be registered in the system.
 *
 * @since 0.1.0
 */
interface Experiment {
	/**
	 * Gets the unique experiment identifier.
	 *
	 * This should be a unique slug-style identifier (e.g., 'title-rewriter').
	 *
	 * @since 0.1.0
	 *
	 * @return string Experiment ID.
	 */
	public function get_id(): string;

	/**
	 * Gets the human-readable experiment label.
	 *
	 * This should be a translated string suitable for display in the admin.
	 *
	 * @since 0.1.0
	 *
	 * @return string Translated experiment label.
	 */
	public function get_label(): string;

	/**
	 * Gets the experiment description.
	 *
	 * This should be a translated string explaining what the experiment does.
	 *
	 * @since 0.1.0
	 *
	 * @return string Translated experiment description.
	 */
	public function get_description(): string;

	/**
	 * Registers the experiment's hooks and functionality.
	 *
	 * This method is called when the experiment is initialized.
	 * Use this to add actions, filters, and set up the experiment.
	 *
	 * @since 0.1.0
	 */
	public function register(): void;

	/**
	 * Checks if the experiment is currently enabled.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True if enabled, false otherwise.
	 */
	public function is_enabled(): bool;

	/**
	 * Provides contextual entry points for the experiment.
	 *
	 * @since x.x.x
	 *
	 * @return array<int, array{label: string, url: string, type?: string}>
	 */
	public function get_entry_points(): array;

	/**
	 * Checks if the experiment has custom settings.
	 *
	 * @since x.x.x
	 *
	 * @return bool True if the experiment has settings, false otherwise.
	 */
	public function has_settings(): bool;

	/**
	 * Returns DataForm-compatible field definitions for experiment settings.
	 *
	 * Each field should be an associative array with at minimum:
	 * - id: Unique field identifier
	 * - type: Field type (text, boolean, integer, etc.)
	 * - label: Human-readable label
	 *
	 * @since x.x.x
	 *
	 * @return array<int, array{id: string, type: string, label: string, description?: string, elements?: array<int, array{value: string, label: string}>}> DataForm fields.
	 */
	public function get_settings_fields(): array;

	/**
	 * Returns current values for experiment settings.
	 *
	 * Keys should match the field IDs from get_settings_fields().
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> Settings values keyed by field ID.
	 */
	public function get_settings_values(): array;

	/**
	 * Updates experiment settings from DataForm data.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $data Settings data from DataForm.
	 * @return bool True on success, false on failure.
	 */
	public function update_settings( array $data ): bool;
}
