<?php
/**
 * Gated ability: read users.
 *
 * @package WordPress\AI\Abilities\Gated
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Gated;

use WordPress\AI\Abilities\Users\Users as Users_Ability;
use WordPress\AI\Abstracts\Abstract_Gated_Ability;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Gates the core/read-users ability.
 *
 * @since x.x.x
 */
final class Read_Users extends Abstract_Gated_Ability {
	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		( new Users_Ability() )->init();
	}
}
