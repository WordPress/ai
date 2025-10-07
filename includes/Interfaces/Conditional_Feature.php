<?php
/**
 * Conditional Feature interface.
 *
 * @package WordPress\AI\Interfaces
 */

namespace WordPress\AI\Interfaces;

/**
 * Interface for features with activation requirements.
 *
 * Features implementing this interface can define requirements that must be met
 * before the feature can be enabled. Examples include PHP extensions, WordPress
 * version, or other plugin dependencies.
 *
 * @since 0.1.0
 */
interface Conditional_Feature extends Feature {
	/**
	 * Checks if the feature's requirements are met.
	 *
	 * This method should check all necessary requirements for the feature
	 * to function properly. Examples:
	 * - Required PHP extensions (e.g., gd, curl)
	 * - Minimum WordPress version
	 * - Other plugins that must be active
	 * - Server capabilities
	 *
	 * @since 0.1.0
	 *
	 * @return bool True if all requirements are met, false otherwise.
	 */
	public function meets_requirements(): bool;

	/**
	 * Gets the requirements message for admin display.
	 *
	 * This message is shown in the admin interface when requirements are not met.
	 * It should clearly explain what requirements are missing and how to resolve them.
	 *
	 * @since 0.1.0
	 *
	 * @return string Message explaining unmet requirements.
	 */
	public function get_requirements_message(): string;
}
