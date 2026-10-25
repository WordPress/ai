<?php
/**
 * Agent account identity.
 *
 * @package WordPress\AI\Experiments\Agent_Users
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Agent_Users;

use WP_Role;
use WP_User;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Identifies agent accounts and their parents.
 *
 * Agents reuse WordPress users for roles, capabilities, ownership, and
 * attribution. Every agent is the child of a human parent account it acts
 * on behalf of, and is suspended wherever that parent lends it no authority.
 * This class answers who is an agent, who its parent is, and whether it is
 * suspended; the rules applying that live in the other Agent Users classes.
 *
 * @since x.x.x
 */
final class Agent_Account {
	/**
	 * User meta key marking an account as an agent.
	 *
	 * User meta is shared across a multisite network, so account type is a
	 * network-wide property. Site memberships and roles remain site-specific,
	 * exactly as they are for human accounts.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const META_KEY = 'wpai_agent';

	/**
	 * User meta key recording which user provisioned the agent.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const META_CREATED_BY = 'wpai_agent_created_by';

	/**
	 * User meta key linking an agent to the human account it acts for.
	 *
	 * Like the marker, the link is network-wide on multisite.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const META_PARENT = 'wpai_agent_parent';

	/**
	 * Capability allowing a user to be the parent of agents.
	 *
	 * It means "may have agents". Agents are still created by user managers;
	 * self-service creation by parents, if added, is meant to be gated by this
	 * same capability. Users without an explicit grant or denial receive it
	 * when they can `edit_posts`. Site owners restrict it per role or user with
	 * any role editor. Agents never receive it, so agents cannot have agents.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const PARENT_CAP = 'wpai_add_agents';

	/**
	 * Network option recording whether any agent exists, `1` or `0`.
	 *
	 * The agent safeguards only register once an agent exists, so installs
	 * that never create one do not run them on every request.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const AGENTS_EXIST_OPTION = 'wpai_agents_exist';

	/**
	 * Checks whether any agent may exist, without querying users on every request.
	 *
	 * The answer is computed on activation, or on the first request when the
	 * activation hook never ran, and set again whenever an account is marked
	 * as an agent. It may stay `true` after the last agent is gone, which only
	 * keeps the safeguards running. A marker written behind the plugin's back
	 * is not seen here; such an agent is found when it authenticates, see
	 * `Main::register_agent_account_safeguards()`, and on activation.
	 *
	 * @since x.x.x
	 *
	 * @return bool True when agents may exist.
	 */
	public static function agents_may_exist(): bool {
		$flag = get_site_option( self::AGENTS_EXIST_OPTION );
		if ( false === $flag ) {
			$flag = self::refresh_agents_exist_flag();
		}

		return '1' === (string) $flag;
	}

	/**
	 * Recomputes and stores whether any agent exists.
	 *
	 * @since x.x.x
	 *
	 * @return string `1` when an agent exists, otherwise `0`.
	 */
	public static function refresh_agents_exist_flag(): string {
		$flag = self::agents_exist() ? '1' : '0';
		update_site_option( self::AGENTS_EXIST_OPTION, $flag );

		return $flag;
	}

	/**
	 * Checks whether an account is an agent.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_User|int $user User object or user ID.
	 * @return bool True when the account is marked as an agent.
	 */
	public static function is_agent( $user ): bool {
		if ( $user instanceof WP_User ) {
			$user = $user->ID;
		}

		$user_id = is_numeric( $user ) ? (int) $user : 0;
		if ( $user_id <= 0 ) {
			return false;
		}

		return (bool) get_user_meta( $user_id, self::META_KEY, true );
	}

	/**
	 * Returns the human account an agent acts for.
	 *
	 * This is the stored link only; see `is_suspended()` for whether the parent
	 * currently lends the agent any authority.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_User|int $agent Agent user object or user ID.
	 * @return \WP_User|null The parent, or null for humans and orphaned agents.
	 */
	public static function get_parent( $agent ): ?WP_User {
		if ( ! self::is_agent( $agent ) ) {
			return null;
		}

		$agent_id  = $agent instanceof WP_User ? $agent->ID : (int) $agent;
		$parent_id = (int) get_user_meta( $agent_id, self::META_PARENT, true );
		$parent    = $parent_id > 0 ? get_user_by( 'id', $parent_id ) : false;

		// An agent can never be a parent, even if the meta was edited directly.
		if ( ! $parent instanceof WP_User || self::is_agent( $parent ) ) {
			return null;
		}

		return $parent;
	}

	/**
	 * Checks whether an agent is suspended on the current site.
	 *
	 * An agent is suspended while its parent is missing or no longer allowed to
	 * have agents, and wherever it holds a role that manages the site, which
	 * agents may not have. It keeps its account, content, and credentials, but
	 * cannot authenticate or do anything until an administrator fixes the cause
	 * or deletes the agent.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_User|int $agent Agent user object or user ID.
	 * @return bool True for suspended agents, false for humans and active agents.
	 */
	public static function is_suspended( $agent ): bool {
		if ( ! self::is_agent( $agent ) ) {
			return false;
		}

		$parent = self::get_parent( $agent );

		return null === $parent ||
			// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- The agent parent capability constant.
			! user_can( $parent, self::PARENT_CAP ) ||
			self::holds_site_management( $agent instanceof WP_User ? $agent->ID : (int) $agent );
	}

	/**
	 * Explains why an agent is suspended on the current site.
	 *
	 * Suspension is evaluated per site on multisite, because the parent's
	 * membership and capabilities, and the agent's role, are site-specific.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_User|int $agent Agent user object or user ID.
	 * @return string `no_parent`, `role_not_allowed`, `parent_not_member`,
	 *                `parent_not_eligible`, or an empty string for humans and
	 *                active agents.
	 */
	public static function get_suspension_reason( $agent ): string {
		if ( ! self::is_suspended( $agent ) ) {
			return '';
		}

		$parent = self::get_parent( $agent );
		if ( null === $parent ) {
			return 'no_parent';
		}

		if ( self::holds_site_management( $agent instanceof WP_User ? $agent->ID : (int) $agent ) ) {
			return 'role_not_allowed';
		}

		if ( is_multisite() && ! self::belongs_to_site( $parent->ID, get_current_blog_id() ) && ! is_super_admin( $parent->ID ) ) {
			return 'parent_not_member';
		}

		return 'parent_not_eligible';
	}

	/**
	 * Checks whether a role manages the site, which agents may not do.
	 *
	 * Site management is identified by `manage_options` rather than by role
	 * name, so custom administrative roles are covered as well. Everything
	 * else a role grants, including installing plugins, stays bounded by the
	 * parent's capabilities, so the parent decides whether an agent may do it.
	 *
	 * @since x.x.x
	 *
	 * @param string $role Role slug.
	 * @return bool True when the role grants `manage_options`.
	 */
	public static function role_manages_site( string $role ): bool {
		$role_object = wp_roles()->get_role( $role );

		return $role_object instanceof WP_Role && ! empty( $role_object->capabilities['manage_options'] );
	}

	/**
	 * Checks whether a user holds `manage_options` on the current site.
	 *
	 * Agents are never given such a role, but WP-CLI, the bulk role change on
	 * the Users screen, and direct writes can still assign one. The stored
	 * capabilities are read instead of calling `user_can()`, whose result for
	 * agents is decided by this check.
	 *
	 * @since x.x.x
	 *
	 * @param int $user_id User ID.
	 * @return bool True when the user's roles or own capabilities grant `manage_options`.
	 */
	private static function holds_site_management( int $user_id ): bool {
		$user = get_userdata( $user_id );

		return $user instanceof WP_User && ! empty( $user->allcaps['manage_options'] );
	}

	/**
	 * Returns the IDs of every agent attached to a parent, across the network.
	 *
	 * @since x.x.x
	 *
	 * @param int $parent_id Parent user ID.
	 * @return array<int, int> Agent user IDs.
	 */
	public static function get_agent_ids( int $parent_id ): array {
		if ( $parent_id <= 0 ) {
			return array();
		}

		$ids = get_users(
			array(
				'blog_id'    => 0,
				'fields'     => 'ID',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded lookup by parent; agents are a small set.
				'meta_query' => array(
					array(
						'key'   => self::META_PARENT,
						'value' => $parent_id,
					),
					array(
						'key'     => self::META_KEY,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Checks whether any agent account exists, across the network.
	 *
	 * @since x.x.x
	 *
	 * @return bool True when at least one account is marked as an agent.
	 */
	public static function agents_exist(): bool {
		$ids = get_users(
			array(
				'blog_id'      => 0,
				'fields'       => 'ID',
				'number'       => 1,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bounded lookup; agents are a small set.
				'meta_key'     => self::META_KEY,
				'meta_compare' => 'EXISTS',
			)
		);

		return array() !== $ids;
	}

	/**
	 * Checks whether this plugin can enforce agent safeguards across a network.
	 *
	 * Agent identity is network-wide, so every site must block interactive login
	 * and password resets for the same accounts. Per-site activation cannot
	 * provide that guarantee.
	 *
	 * @since x.x.x
	 *
	 * @return bool True on single site or when the plugin is network-active.
	 */
	public static function can_enforce_network_safeguards(): bool {
		if ( ! is_multisite() ) {
			return true;
		}

		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active_for_network( plugin_basename( WPAI_PLUGIN_FILE ) );
	}

	/**
	 * Checks whether a user belongs to a site, including an archived, spam, or deleted one.
	 *
	 * `is_user_member_of_blog()` answers false for those sites, although the
	 * user keeps a role there and core's network delete screen lists them.
	 *
	 * @since x.x.x
	 *
	 * @param int $user_id User ID.
	 * @param int $site_id Site ID.
	 * @return bool True when the user has a role on the site.
	 */
	public static function belongs_to_site( int $user_id, int $site_id ): bool {
		return array_key_exists( $site_id, get_blogs_of_user( $user_id, true ) );
	}
}
