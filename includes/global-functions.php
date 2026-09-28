<?php
/**
 * Public global helper functions.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

use WordPress\AI\Experiments\Agent_Users\Agent_Account;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Checks whether a user account is an agent.
 *
 * @since x.x.x
 *
 * @param \WP_User|int $user User object or user ID.
 * @return bool True when the account is marked as an agent.
 */
function wpai_is_agent_user( $user ): bool {
	return Agent_Account::is_agent( $user );
}

/**
 * Returns the user an agent account acts on behalf of.
 *
 * @since x.x.x
 *
 * @param \WP_User|int $agent Agent user object or user ID.
 * @return \WP_User|null The parent, or null for human accounts and orphaned agents.
 */
function wpai_get_agent_parent( $agent ): ?WP_User {
	return Agent_Account::get_parent( $agent );
}
