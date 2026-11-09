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
 * @since 1.3.0
 */
abstract class Abstract_Gated_Ability {
	/**
	 * Registers the underlying WordPress Ability (or abilities).
	 *
	 * Called only when the gating experiment is enabled.
	 *
	 * @since 1.3.0
	 */
	abstract public function register(): void;

	/**
	 * Whether the ability needs core objects exposed to the Abilities API
	 * (e.g. the `show_in_abilities` post-type/setting flags) before it
	 * registers. The experiment loads the `wordpress/abilities` package's
	 * support polyfills for the flag on hosts that lack it.
	 *
	 * Defaults to false; override in abilities that depend on core-object exposure.
	 *
	 * @since 1.3.0
	 *
	 * @return bool True if the ability depends on core-object exposure.
	 */
	public function requires_core_object_exposure(): bool {
		return false;
	}
}
