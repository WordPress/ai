<?php
/**
 * Gated nav menu ability.
 *
 * @package WordPress\AI\Abilities\Gated
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Gated;

use WordPress\AI\Abilities\Nav_Menus\Nav_Menus as Nav_Menus_Ability;
use WordPress\AI\Abstracts\Abstract_Gated_Ability;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Registers the nav menus ability when the Custom Abilities experiment is enabled.
 *
 * @since x.x.x
 */
final class Nav_Menus extends Abstract_Gated_Ability {
	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		( new Nav_Menus_Ability() )->init();
	}
}
