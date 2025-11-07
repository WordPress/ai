<?php
/**
 * Feature interface.
 *
 * @package WordPress\AI\Contracts
 */

declare( strict_types=1 );

namespace WordPress\AI\Contracts;

/**
 * Interface for all features.
 *
 * Every feature must implement this interface to be registered in the system.
 *
 * @since 0.1.0
 */
interface Feature {
	/**
	 * Gets the unique feature identifier.
	 *
	 * This should be a unique slug-style identifier (e.g., 'title-rewriter').
	 *
	 * @since 0.1.0
	 *
	 * @return string Feature ID.
	 */
	public function get_id(): string;

	/**
	 * Gets the human-readable feature label.
	 *
	 * This should be a translated string suitable for display in the admin.
	 *
	 * @since 0.1.0
	 *
	 * @return string Translated feature label.
	 */
	public function get_label(): string;

	/**
	 * Gets the feature description.
	 *
	 * This should be a translated string explaining what the feature does.
	 *
	 * @since 0.1.0
	 *
	 * @return string Translated feature description.
	 */
	public function get_description(): string;

	/**
	 * Registers the feature's hooks and functionality.
	 *
	 * This method is called when the feature is initialized.
	 * Use this to add actions, filters, and set up the feature.
	 *
	 * @since 0.1.0
	 */
	public function register(): void;

	/**
	 * Checks if the feature is currently enabled.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True if enabled, false otherwise.
	 */
	public function is_enabled(): bool;
}
