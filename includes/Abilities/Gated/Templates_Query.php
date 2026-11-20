<?php
/**
 * Gated ability: templates query.
 *
 * @package WordPress\AI\Abilities\Gated
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Gated;

use WordPress\AI\Abilities\Templates\Templates as Templates_Ability;
use WordPress\AI\Abstracts\Abstract_Gated_Ability;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Gates the core/templates-query ability.
 *
 * @since x.x.x
 */
final class Templates_Query extends Abstract_Gated_Ability {
	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		( new Templates_Ability() )->init();
	}
}
