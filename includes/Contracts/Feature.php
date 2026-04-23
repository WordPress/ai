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
 * @since 0.6.0
 */
interface Feature {
	/**
	 * Gets the unique feature identifier.
	 *
	 * This should be a unique slug-style identifier (e.g., 'title-rewriter').
	 *
	 * @since 0.6.0
	 *
	 * @return non-empty-string Feature ID.
	 */
	public static function get_id(): string;

	/**
	 * Gets the human-readable feature label.
	 *
	 * This should be a translated string suitable for display in the admin.
	 *
	 * @since 0.6.0
	 *
	 * @return non-empty-string Translated feature label.
	 */
	public function get_label(): string;

	/**
	 * Gets the feature description.
	 *
	 * This should be a translated string explaining what the feature does.
	 *
	 * @since 0.6.0
	 *
	 * @return non-empty-string Translated feature description.
	 */
	public function get_description(): string;

	/**
	 * Gets the feature category.
	 *
	 * Determines where the feature appears in the settings UI.
	 *
	 * @since 0.6.0
	 *
	 * @return non-empty-string The feature category.
	 */
	public function get_category(): string;

	/**
	 * Gets the feature stability level.
	 *
	 * @since 0.6.0
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
	 * @since 0.6.0
	 */
	public function register(): void;

	/**
	 * Checks if the feature is currently enabled.
	 *
	 * @since 0.6.0
	 *
	 * @return bool True if enabled, false otherwise.
	 */
	public function is_enabled(): bool;

	/**
	 * Gets field definitions with fully resolved option names.
	 *
	 * Returns an empty array when the feature has no custom settings.
	 *
	 * @since 0.7.0
	 *
	 * @return array<int, array{
	 *   id: string,
	 *   label: string,
	 *   type: string,
	 *   default?: mixed,
	 *   elements?: list<array{value: string, label: string}>,
	 *   isValid?: array{min?: int, max?: int},
	 * }> Array of field definitions with full option names.
	 */
	public function get_settings_fields_metadata(): array;

	/**
	 * Gets the image URL for feature showcase display.
	 *
	 * @since 0.8.0
	 *
	 * @return string The image URL, or empty string if not set.
	 */
	public function get_image(): string;
}
