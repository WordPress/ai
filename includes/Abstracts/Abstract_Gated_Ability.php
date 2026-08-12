<?php
/**
 * Abstract Gated Ability base class.
 *
 * @package WordPress\AI\Abstracts
 */

declare( strict_types=1 );

namespace WordPress\AI\Abstracts;

/**
 * Base class for abilities that are gated behind an experiment.
 *
 * Each gated ability wraps the registration of one or more WordPress Abilities
 * so an experiment can register them together, only when the experiment is
 * enabled.
 *
 * @since x.x.x
 */
abstract class Abstract_Gated_Ability {
	/**
	 * Registers the underlying WordPress Ability (or abilities).
	 *
	 * Called only when the gating experiment is enabled.
	 *
	 * @since x.x.x
	 */
	abstract public function register(): void;

	/**
	 * Whether the ability needs core objects exposed to the Abilities API (via
	 * Show_In_Abilities) before it registers.
	 *
	 * Defaults to false; override in abilities that depend on core-object exposure.
	 *
	 * @since x.x.x
	 *
	 * @return bool True if the ability depends on core-object exposure.
	 */
	public function requires_core_object_exposure(): bool {
		return false;
	}
}
