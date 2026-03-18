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
 * @since x.x.x
 */
interface Feature {
	/**
	 * Gets the unique feature identifier.
	 *
	 * This should be a unique slug-style identifier (e.g., 'title-rewriter').
	 *
	 * @since x.x.x
	 *
	 * @return non-empty-string Feature ID.
	 */
	public static function get_id(): string;

	/**
	 * Gets the human-readable feature label.
	 *
	 * This should be a translated string suitable for display in the admin.
	 *
	 * @since x.x.x
	 *
	 * @return non-empty-string Translated feature label.
	 */
	public function get_label(): string;

	/**
	 * Gets the feature description.
	 *
	 * This should be a translated string explaining what the feature does.
	 *
	 * @since x.x.x
	 *
	 * @return non-empty-string Translated feature description.
	 */
	public function get_description(): string;

	/**
	 * Gets the feature category.
	 *
	 * Determines where the feature appears in the settings UI.
	 *
	 * @since x.x.x
	 *
	 * @return non-empty-string The feature category.
	 */
	public function get_category(): string;

	/**
	 * Gets the feature stability level.
	 *
	 * @since x.x.x
	 *
	 * @return 'deprecated'|'experimental'|'stable'
	 */
	public function get_stability(): string;

	/**
	 * Registers the feature's hooks and functionality.
	 *
	 * This method is called when the feature is initialized.
	 * Use this to add actions, filters, and set up the feature.
	 *
	 * @since x.x.x
	 */
	public function register(): void;

	/**
	 * Checks if the feature is currently enabled.
	 *
	 * @since x.x.x
	 *
	 * @return bool True if enabled, false otherwise.
	 */
	public function is_enabled(): bool;
}
