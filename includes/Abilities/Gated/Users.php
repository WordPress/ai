<?php
/**
 * Gated ability: users query, and user create, update, and delete.
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
 * Gates the user abilities: core/users-query, core/user-create, core/user-update,
 * and core/user-delete.
 *
 * @since 1.3.0
 * @since x.x.x Also gates the create, update, and delete abilities.
 */
final class Users extends Abstract_Gated_Ability {
	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		( new Users_Ability() )->init();
	}
}
