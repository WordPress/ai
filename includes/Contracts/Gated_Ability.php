<?php
/**
 * Gated Ability interface.
 *
 * @package WordPress\AI\Contracts
 */

declare( strict_types=1 );

namespace WordPress\AI\Contracts;

/**
 * Interface for abilities that can be individually toggled via the Abilities
 * Explorer experiment.
 *
 * Each gated ability wraps the registration of one or more WordPress Abilities
 * so the Abilities Explorer can surface a toggle for it and register it only
 * when the toggle is enabled.
 *
 * @since x.x.x
 */
interface Gated_Ability {
	/**
	 * Gets the unique toggle key for the ability.
	 *
	 * Used as the settings-field suffix (slug-style, e.g. 'read_settings').
	 *
	 * @since x.x.x
	 *
	 * @return non-empty-string The ability toggle key.
	 */
	public static function get_key(): string;

	/**
	 * Gets the human-readable label shown next to the toggle.
	 *
	 * @since x.x.x
	 *
	 * @return non-empty-string Translated label.
	 */
	public function get_label(): string;

	/**
	 * Gets the description shown as help text under the toggle.
	 *
	 * @since x.x.x
	 *
	 * @return non-empty-string Translated description.
	 */
	public function get_description(): string;

	/**
	 * Whether the ability needs core objects exposed to the Abilities API (via
	 * Show_In_Abilities) before it registers.
	 *
	 * @since x.x.x
	 *
	 * @return bool True if the ability depends on core-object exposure.
	 */
	public function requires_core_object_exposure(): bool;

	/**
	 * Registers the underlying WordPress Ability (or abilities).
	 *
	 * Called only when the ability's toggle is enabled.
	 *
	 * @since x.x.x
	 */
	public function register(): void;
}
