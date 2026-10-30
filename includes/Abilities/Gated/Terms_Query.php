<?php
/**
 * Gated ability: terms query, create, update, and delete.
 *
 * @package WordPress\AI\Abilities\Gated
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Gated;

use WordPress\AI\Abilities\Terms\Terms as Terms_Ability;
use WordPress\AI\Abstracts\Abstract_Gated_Ability;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Gates the terms abilities: core/terms-query, core/term-create, core/term-update, and
 * core/term-delete.
 *
 * @since x.x.x
 */
final class Terms_Query extends Abstract_Gated_Ability {
	/**
	 * {@inheritDoc}
	 */
	public function requires_core_object_exposure(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		( new Terms_Ability() )->init();
	}
}
