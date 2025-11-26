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
}
