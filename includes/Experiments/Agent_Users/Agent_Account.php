<?php
/**
 * Agent account identity service.
 *
 * @package WordPress\AI\Experiments\Agent_Users
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Agent_Users;

use WP_Error;
use WP_Post;
use WP_Post_Type;
use WP_Role;
use WP_User;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Enforces the security contract for user accounts marked as agents.
 *
 * Agents reuse WordPress users for roles, capabilities, ownership, and
 * attribution, but they cannot log in interactively or reset passwords. Every
 * agent is the child of a human parent account it acts on behalf of and can
 * never do more than that parent currently can.
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
	 * Suffix every agent username ends with.
	 *
	 * The suffix makes agents recognizable wherever only the login is shown,
	 * such as WP-CLI output, author names, and logs. Provisioning appends it
	 * when missing, so programmatic callers get it as well.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const LOGIN_SUFFIX = '_agent';

	/**
	 * Agents chosen to receive their parent's content, keyed by parent ID.
	 *
	 * Recorded when a parent leaves a site so that deleting the parent's
	 * account later in the same request keeps these agents.
	 *
	 * @since x.x.x
	 *
	 * @var array<int, array<int, int>>
	 */
	private array $heirs = array();

	/**
	 * Hooks the identity rules into WordPress.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		add_filter( 'wp_authenticate_user', array( $this, 'block_interactive_login' ) );
		add_filter( 'allow_password_reset', array( $this, 'disable_password_reset' ), 10, 2 );
		add_filter( 'wp_is_application_passwords_available_for_user', array( $this, 'ensure_application_passwords' ), 10, 2 );
		add_filter( 'map_meta_cap', array( $this, 'strip_unfiltered_html_from_agents' ), 10, 3 );
		add_filter( 'map_meta_cap', array( $this, 'map_parent_user_management' ), 10, 4 );
		add_filter( 'map_meta_cap', array( $this, 'share_post_ownership' ), 10, 4 );
		add_filter( 'user_has_cap', array( $this, 'grant_default_parent_capability' ), 10, 4 );
		// Runs last so no other mapping can lift an agent above its parent.
		add_filter( 'map_meta_cap', array( $this, 'limit_agents_to_parent' ), PHP_INT_MAX, 4 );
		add_action( 'wp_authenticate_application_password_errors', array( $this, 'reject_suspended_agent_credentials' ), 10, 2 );
		add_filter( 'auth_user_meta_' . self::META_KEY, '__return_false' );
		add_filter( 'auth_user_meta_' . self::META_PARENT, '__return_false' );

		add_action( 'delete_user', array( $this, 'delete_agents_of_deleted_user' ), 10, 2 );
		add_filter( 'users_have_additional_content', array( $this, 'count_agent_content' ), 10, 2 );
		add_action( 'delete_user_form', array( $this, 'render_agents_deleted_with_parent' ), 10, 2 );

		if ( ! is_multisite() ) {
			return;
		}

		add_filter( 'pre_update_site_option_site_admins', array( $this, 'strip_agents_from_super_admins' ) );
		add_action( 'remove_user_from_blog', array( $this, 'remove_agents_of_removed_user' ), 10, 3 );
		add_action( 'wpmu_delete_user', array( $this, 'delete_agents_of_deleted_network_user' ) );
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
	 * Checks whether an agent is suspended because its parent lends it no authority.
	 *
	 * An agent is suspended while its parent is missing or no longer allowed to
	 * have agents. It keeps its account, content, and credentials, but cannot
	 * authenticate or do anything until an administrator restores the parent's
	 * eligibility or deletes the agent.
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

		// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- The agent parent capability constant.
		return null === $parent || ! user_can( $parent, self::PARENT_CAP );
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
	 * Checks whether the current user may provision agents.
	 *
	 * @since x.x.x
	 *
	 * @return bool True when every provisioning gate in `authorize_provisioner()` passes.
	 */
	public static function current_user_can_provision(): bool {
		return ! is_wp_error( self::authorize_provisioner() );
	}

	/**
	 * Checks the provisioning gates for the current user.
	 *
	 * Provisioning needs `create_users` and `promote_users` because an agent is
	 * a new account with a role. The provisioner must also hold the primitive
	 * `edit_users` capability so they can reach the new agent's profile and issue
	 * its first credential. Core's normal capability mapping remains authoritative
	 * on both single-site and multisite installations. This is the single source
	 * for the gates, so the UI checks and direct provisioning cannot drift apart.
	 *
	 * @since x.x.x
	 *
	 * @return true|\WP_Error True when the current user may provision agents.
	 */
	private static function authorize_provisioner() {
		if ( ! self::can_enforce_network_safeguards() ) {
			return new WP_Error(
				'wpai_agent_requires_network_activation',
				__( 'Agent accounts require the AI plugin to be network-activated on multisite.', 'ai' )
			);
		}

		// Agents cannot have agents, so they cannot provision them for anyone else either.
		if ( self::is_agent( get_current_user_id() ) ) {
			return new WP_Error( 'wpai_agent_cannot_provision_agents', __( 'Agent accounts cannot create agents.', 'ai' ) );
		}

		if ( ! current_user_can( 'create_users' ) ) {
			return new WP_Error( 'wpai_agent_cannot_create_users', __( 'You are not allowed to create users.', 'ai' ) );
		}

		if ( ! current_user_can( 'promote_users' ) ) {
			return new WP_Error( 'wpai_agent_cannot_promote_users', __( 'You are not allowed to assign roles to users.', 'ai' ) );
		}

		if ( ! current_user_can( 'edit_users' ) ) {
			return new WP_Error(
				'wpai_agent_cannot_manage_agents',
				__( 'You must be allowed to edit agent accounts before you can create them.', 'ai' )
			);
		}

		return true;
	}

	/**
	 * Provisions a new agent account.
	 *
	 * The account gets an unknown random password because interactive login is
	 * unavailable. Credentials are issued separately through core's one-time
	 * Application Password flow.
	 *
	 * @since x.x.x
	 *
	 * @param string $login      Username for the account.
	 * @param string $role       Role slug for the account.
	 * @param string $email      Email receiving notifications about the agent's activity.
	 * @param string $first_name Optional. First name, exactly as on the Add User screen.
	 * @param string $last_name  Optional. Last name, exactly as on the Add User screen.
	 * @param string $url        Optional. Website, exactly as on the Add User screen.
	 * @param int    $parent_id  Optional. Human account the agent acts for. Defaults to the current user.
	 * @return \WP_User|\WP_Error Provisioned account or an error.
	 */
	public function provision( string $login, string $role, string $email, string $first_name = '', string $last_name = '', string $url = '', int $parent_id = 0 ) {
		$provisioner_id = get_current_user_id();
		$parent_id      = $parent_id > 0 ? $parent_id : $provisioner_id;
		$authorization  = $this->authorize_provisioning( $role, $parent_id );
		if ( is_wp_error( $authorization ) ) {
			return $authorization;
		}

		$login = $this->validate_login( $login );
		if ( is_wp_error( $login ) ) {
			return $login;
		}

		$email = $this->validate_email( $email );
		if ( is_wp_error( $email ) ) {
			return $email;
		}

		$user_id = wp_insert_user(
			array(
				'user_login' => $login,
				'user_pass'  => wp_generate_password( 64, true, true ),
				'user_email' => $email,
				'first_name' => trim( $first_name ),
				'last_name'  => trim( $last_name ),
				'user_url'   => trim( $url ),
				'role'       => $role,
				'meta_input' => array(
					self::META_KEY        => '1',
					self::META_CREATED_BY => $provisioner_id,
					self::META_PARENT     => $parent_id,
				),
			)
		);
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user instanceof WP_User ) {
			return new WP_Error( 'wpai_agent_not_found', __( 'The agent account could not be loaded after creation.', 'ai' ) );
		}

		if ( ! $this->provisioned_role_is_within_user_capabilities( $user, $provisioner_id, $role ) ) {
			self::delete_provisioned_user( $user_id );
			return new WP_Error(
				'wpai_agent_role_not_assignable',
				__( 'The selected role cannot grant permissions you do not have.', 'ai' )
			);
		}

		/*
		 * Match the core Add User flow so notification and compatibility hooks
		 * still run. Only the administrator is notified: the generated password
		 * is deliberately unknown and cannot be used for interactive login.
		 */
		do_action( 'edit_user_created_user', $user_id, 'admin' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Matching the core Add User action.

		return $user;
	}

	/**
	 * Returns roles the current user may assign to an agent.
	 *
	 * WordPress's editable roles filter remains the first boundary. The role's
	 * granted capabilities must also be a subset of the current user's effective
	 * capabilities, which keeps a delegated user manager from minting an agent
	 * more powerful than themselves. Provisioning repeats the comparison with
	 * the real marked agent before exposing the account, because only then can
	 * user-specific filters determine the agent's final access.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{name: string}> Assignable role details.
	 */
	public function get_assignable_roles(): array {
		if ( ! self::current_user_can_provision() ) {
			return array();
		}

		if ( ! function_exists( 'get_editable_roles' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		$roles = array();
		foreach ( get_editable_roles() as $role_slug => $role_details ) {
			if (
				! is_string( $role_slug ) ||
				! isset( $role_details['name'] ) ||
				! is_string( $role_details['name'] ) ||
				! $this->role_is_within_user_capabilities( $role_slug, get_current_user_id() )
			) {
				continue;
			}
			$roles[ $role_slug ] = array( 'name' => $role_details['name'] );
		}

		return $roles;
	}

	/**
	 * Authorizes agent provisioning, the parent, and the requested role.
	 *
	 * The role is checked against the parent as well so the stored role reflects
	 * what the agent can actually do when it is created.
	 *
	 * @since x.x.x
	 *
	 * @param string $role      Requested role slug.
	 * @param int    $parent_id Requested parent user ID.
	 * @return true|\WP_Error True when authorized, otherwise an error.
	 */
	private function authorize_provisioning( string $role, int $parent_id ) {
		$authorized = self::authorize_provisioner();
		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		// `get_assignable_roles()` only contains existing roles, so this also
		// rejects role slugs that do not exist.
		if ( ! array_key_exists( $role, $this->get_assignable_roles() ) ) {
			return new WP_Error(
				'wpai_agent_role_not_assignable',
				__( 'The selected role does not exist or grants permissions you do not have.', 'ai' )
			);
		}

		$parent = get_user_by( 'id', $parent_id );
		if (
			! $parent instanceof WP_User ||
			self::is_agent( $parent ) ||
			( ! is_user_member_of_blog( $parent->ID ) && ! is_super_admin( $parent->ID ) ) ||
			// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- The agent parent capability constant.
			! user_can( $parent, self::PARENT_CAP )
		) {
			return new WP_Error(
				'wpai_agent_invalid_parent',
				__( 'The selected parent user cannot have agents on this site.', 'ai' )
			);
		}

		if ( ! $this->role_is_within_user_capabilities( $role, $parent->ID ) ) {
			return new WP_Error(
				'wpai_agent_role_exceeds_parent',
				__( 'The selected role grants permissions the parent user does not have.', 'ai' )
			);
		}

		return true;
	}

	/**
	 * Checks that an agent role cannot exceed a user's permissions.
	 *
	 * @since x.x.x
	 *
	 * @param string $role    Role slug.
	 * @param int    $user_id User whose permissions bound the role.
	 * @return bool True when every effective role capability is held by the user.
	 */
	private function role_is_within_user_capabilities( string $role, int $user_id ): bool {
		$role_object = wp_roles()->get_role( $role );
		if ( ! $role_object instanceof WP_Role ) {
			return false;
		}

		foreach ( $role_object->capabilities as $capability => $granted ) {
			if ( ! $granted ) {
				continue;
			}

			// Legacy user levels grant nothing on their own.
			if ( 0 === strpos( $capability, 'level_' ) ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Mapping every capability granted by the selected role.
			$required = map_meta_cap( $capability, $user_id );

			/*
			 * Core maps globally unavailable capabilities to `do_not_allow`, for
			 * example `manage_links` when the Link Manager is disabled. A plugin
			 * may also return it only for this user, which cannot be known
			 * until the marked agent exists; the post-creation check handles that.
			 */
			if ( in_array( 'do_not_allow', $required, true ) ) {
				continue;
			}

			/*
			 * The agent-side strip makes `unfiltered_html` inert for roles
			 * without `manage_options`, so it cannot represent escalation.
			 */
			if ( in_array( 'unfiltered_html', $required, true ) && empty( $role_object->capabilities['manage_options'] ) ) {
				continue;
			}

			/*
			 * An unmet network prerequisite makes the capability inert for the
			 * role too, so it cannot represent a privilege escalation.
			 */
			$extra = array_diff( $required, array( $capability ) );
			if ( array() !== $extra && ! $this->role_grants_any( $role_object, $extra ) ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Comparing every capability granted by the selected role.
			if ( ! user_can( $user_id, $capability ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Verifies the provisioned agent against the provisioner's effective access.
	 *
	 * The role-list check runs before an agent exists and therefore cannot know
	 * how user-specific capability filters will treat that agent. Repeating the
	 * comparison with the real marked account closes that gap. Capabilities that
	 * are inert for the agent do not represent additional access.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_User $agent          Newly provisioned agent.
	 * @param int      $provisioner_id User who provisioned the agent.
	 * @param string   $role           Assigned role slug.
	 * @return bool True when the role gives the agent no access the provisioner lacks.
	 */
	private function provisioned_role_is_within_user_capabilities( WP_User $agent, int $provisioner_id, string $role ): bool {
		$role_object = wp_roles()->get_role( $role );
		if ( ! $role_object instanceof WP_Role ) {
			return false;
		}

		foreach ( $role_object->capabilities as $capability => $granted ) {
			// Legacy user levels grant nothing on their own.
			if ( ! $granted || 0 === strpos( $capability, 'level_' ) ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Comparing every capability granted by the selected role.
			if ( user_can( $agent, $capability ) && ! user_can( $provisioner_id, $capability ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Deletes an account when post-creation authorization fails.
	 *
	 * @since x.x.x
	 *
	 * @param int $user_id Newly provisioned user ID.
	 */
	private static function delete_provisioned_user( int $user_id ): void {
		if ( is_multisite() ) {
			if ( ! function_exists( 'wpmu_delete_user' ) ) {
				require_once ABSPATH . 'wp-admin/includes/ms.php';
			}

			wpmu_delete_user( $user_id );
			return;
		}

		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		wp_delete_user( $user_id );
	}

	/**
	 * Checks whether a role grants at least one of the given capabilities.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Role           $role_object  The role to inspect.
	 * @param array<int, string> $capabilities Capabilities to look for.
	 * @return bool True when the role grants any of the capabilities.
	 */
	private function role_grants_any( WP_Role $role_object, array $capabilities ): bool {
		foreach ( $capabilities as $capability ) {
			if ( ! empty( $role_object->capabilities[ $capability ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Blocks interactive login for agent accounts.
	 *
	 * Runs on the `wp_authenticate_user` filter, which fires for password
	 * form logins (wp-login.php and XML-RPC). Application Passwords
	 * authenticate through a separate path and keep working.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_User|\WP_Error $user The authenticated user, or an error from an earlier check.
	 * @return \WP_User|\WP_Error The user, or an error for agent accounts.
	 */
	public function block_interactive_login( $user ) {
		if ( ! $user instanceof WP_User || ! self::is_agent( $user ) ) {
			return $user;
		}

		return new WP_Error(
			'wpai_agent_login_disabled',
			__( 'Agent accounts cannot log in interactively. Use an Application Password instead.', 'ai' )
		);
	}

	/**
	 * Disables password resets for agent accounts.
	 *
	 * @since x.x.x
	 *
	 * @param bool $allow   Whether the reset is allowed.
	 * @param int  $user_id The user requesting a reset.
	 * @return bool False for agent accounts.
	 */
	public function disable_password_reset( bool $allow, int $user_id ): bool {
		if ( self::is_agent( $user_id ) ) {
			return false;
		}

		return $allow;
	}

	/**
	 * Keeps Application Passwords available for agent accounts.
	 *
	 * Application Passwords are the built-in credential path for agents, so a
	 * user-level filter must not lock them out. The global availability check,
	 * including the HTTPS requirement, is not overridden. On multisite the
	 * credential identifies the network user; site roles determine its authority.
	 *
	 * @since x.x.x
	 *
	 * @param bool     $available Whether Application Passwords are available for the user.
	 * @param \WP_User $user      The user being checked.
	 * @return bool True for agent accounts.
	 */
	public function ensure_application_passwords( bool $available, WP_User $user ): bool {
		if ( self::is_agent( $user ) ) {
			return true;
		}

		return $available;
	}

	/**
	 * Keeps agent accounts out of the network's super admin list.
	 *
	 * Super admin is a network-wide status outside the site role system and
	 * bypasses most capability checks. Agent authority must remain defined by
	 * explicit roles, so agents cannot receive it.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $super_admins The super admin logins about to be saved.
	 * @return mixed The list without agent accounts.
	 */
	public function strip_agents_from_super_admins( $super_admins ) {
		if ( ! is_array( $super_admins ) ) {
			return $super_admins;
		}

		return array_values(
			array_filter(
				$super_admins,
				static function ( $login ): bool {
					if ( ! is_string( $login ) ) {
						return true;
					}

					$user = get_user_by( 'login', $login );

					return ! $user instanceof WP_User || ! self::is_agent( $user );
				}
			)
		);
	}

	/**
	 * Strips `unfiltered_html` from agents without administrative access.
	 *
	 * Some roles below Administrator carry `unfiltered_html`, most notably
	 * Editor on single-site installations. For an agent that default is unsafe:
	 * model output stored with it becomes stored XSS. Removing the capability
	 * reinstates core's KSES filtering on content paths that use it.
	 *
	 * The check matches the resolved primitive instead of the requested
	 * capability name, so meta capabilities that core resolves to
	 * `unfiltered_html`, such as `edit_css`, are covered as well. When core has
	 * already denied the capability, on multisite or under
	 * `DISALLOW_UNFILTERED_HTML`, the result passes through unchanged. The
	 * administrative boundary uses `manage_options` rather than a role name so
	 * custom roles and user-level capability filters follow core behavior.
	 *
	 * @since x.x.x
	 *
	 * @param array<int, string> $caps    Primitive capabilities resolved by `map_meta_cap()`.
	 * @param string             $cap     The capability being checked.
	 * @param int                $user_id The user the check runs for.
	 * @return array<int, string> Filtered primitive capabilities.
	 */
	public function strip_unfiltered_html_from_agents( array $caps, string $cap, int $user_id ): array {
		if ( ! in_array( 'unfiltered_html', $caps, true ) || ! self::is_agent( $user_id ) ) {
			return $caps;
		}

		if ( user_can( $user_id, 'manage_options' ) ) {
			return $caps;
		}

		return array( 'do_not_allow' );
	}

	/**
	 * Grants the parent capability by default and never to agents.
	 *
	 * Humans without an explicit grant or denial for `PARENT_CAP` may have
	 * agents when they can `edit_posts`, so site owners opt roles or users out
	 * (or in) with any role editor.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, bool> $allcaps Capabilities the user has.
	 * @param array<int, string>  $caps    Primitive capabilities being checked.
	 * @param array<int, mixed>   $args    Original `has_cap()` arguments.
	 * @param \WP_User            $user    The user being checked.
	 * @return array<string, bool> Filtered capabilities.
	 */
	public function grant_default_parent_capability( array $allcaps, array $caps, array $args, WP_User $user ): array {
		if ( ! in_array( self::PARENT_CAP, $caps, true ) ) {
			return $allcaps;
		}

		if ( self::is_agent( $user ) ) {
			$allcaps[ self::PARENT_CAP ] = false;
		} elseif ( ! isset( $allcaps[ self::PARENT_CAP ] ) ) {
			$allcaps[ self::PARENT_CAP ] = ! empty( $allcaps['edit_posts'] );
		}

		return $allcaps;
	}

	/**
	 * Limits every agent to what its parent can currently do.
	 *
	 * The parent is checked for the same capability with the same arguments,
	 * such as the post being edited, so object-specific rules that apply to the
	 * parent also bound the agent. An agent's authority is therefore both its
	 * own role and its parent's current permissions, and demoting the parent
	 * narrows their agents immediately. Suspended agents are denied everything,
	 * including operations core allows without any capability, such as editing
	 * their own profile and Application Passwords.
	 *
	 * @since x.x.x
	 *
	 * @param array<int, string> $caps    Primitive capabilities resolved by `map_meta_cap()`.
	 * @param string             $cap     The capability being checked.
	 * @param int                $user_id The user the check runs for.
	 * @param array<int, mixed>  $args    Additional arguments, such as an object ID.
	 * @return array<int, string> Filtered primitive capabilities.
	 */
	public function limit_agents_to_parent( array $caps, string $cap, int $user_id, array $args ): array {
		if ( in_array( 'do_not_allow', $caps, true ) || ! self::is_agent( $user_id ) ) {
			return $caps;
		}

		$parent = self::get_parent( $user_id );
		// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- The agent parent capability constant.
		if ( null === $parent || ! user_can( $parent, self::PARENT_CAP ) ) {
			return array( 'do_not_allow' );
		}

		// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Checking the parent for the capability the agent is checked for.
		if ( ! user_can( $parent, $cap, ...$args ) ) {
			return array( 'do_not_allow' );
		}

		return $caps;
	}

	/**
	 * Rejects Application Passwords of suspended agents.
	 *
	 * The credentials are kept so an administrator can inspect and revoke them,
	 * but they stop authenticating while the agent is suspended.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Error $error Errors collected while authenticating, added to in place.
	 * @param \WP_User  $user  The user authenticating.
	 */
	public function reject_suspended_agent_credentials( WP_Error $error, WP_User $user ): void {
		if ( ! self::is_suspended( $user ) ) {
			return;
		}

		$error->add(
			'wpai_agent_suspended',
			__( 'This agent is suspended because its parent user no longer exists or can no longer have agents.', 'ai' )
		);
	}

	/**
	 * Maps user management between agents and their parents.
	 *
	 * Parents can edit their agents' profiles, which covers managing their
	 * Application Passwords, without holding `edit_users`. Changing an agent's
	 * role still requires core's `promote_user`. Agents can never edit, promote,
	 * remove, or delete their own parent, whatever their role.
	 *
	 * @todo Needs evaluation and discussion: on multisite, core only lets
	 *       users with `manage_network_users` edit other users, and #961
	 *       follows that rule for agents. Mapping the parent's access to
	 *       `PARENT_CAP` deliberately bypasses it so parents can manage their
	 *       own agents' credentials on every install. This differs from the
	 *       core permission model and should be decided with maintainers.
	 *
	 * @since x.x.x
	 *
	 * @param array<int, string> $caps    Primitive capabilities resolved by `map_meta_cap()`.
	 * @param string             $cap     The capability being checked.
	 * @param int                $user_id The user the check runs for.
	 * @param array<int, mixed>  $args    Additional arguments, starting with the target user ID.
	 * @return array<int, string> Filtered primitive capabilities.
	 */
	public function map_parent_user_management( array $caps, string $cap, int $user_id, array $args ): array {
		if ( ! in_array( $cap, array( 'edit_user', 'promote_user', 'remove_user', 'delete_user' ), true ) || empty( $args[0] ) ) {
			return $caps;
		}

		$target_id = (int) $args[0];

		$parent = self::get_parent( $user_id );
		if ( null !== $parent && $parent->ID === $target_id ) {
			return array( 'do_not_allow' );
		}

		$target_parent = 'edit_user' === $cap ? self::get_parent( $target_id ) : null;
		if ( null === $target_parent || $target_parent->ID !== $user_id ) {
			return $caps;
		}

		return array( self::PARENT_CAP );
	}

	/**
	 * Lets an agent and its parent treat each other's posts as their own.
	 *
	 * The agent acts on behalf of its parent, so each may edit, delete, and
	 * read the other's posts under the same rules that apply to their own
	 * posts, for example `edit_published_posts` for published ones. Anything
	 * about "others' posts" is swapped for its own-post counterpart; every
	 * other requirement core resolved is kept. Siblings, agents sharing a
	 * parent, are not linked to each other.
	 *
	 * @since x.x.x
	 *
	 * @param array<int, string> $caps    Primitive capabilities resolved by `map_meta_cap()`.
	 * @param string             $cap     The capability being checked.
	 * @param int                $user_id The user the check runs for.
	 * @param array<int, mixed>  $args    Additional arguments, starting with the post ID.
	 * @return array<int, string> Filtered primitive capabilities.
	 */
	public function share_post_ownership( array $caps, string $cap, int $user_id, array $args ): array {
		// Post meta capabilities whose mapping depends on the post author.
		$author_dependent = array( 'edit_post', 'edit_page', 'delete_post', 'delete_page', 'read_post', 'read_page' );
		if ( ! in_array( $cap, $author_dependent, true ) || empty( $args[0] ) ) {
			return $caps;
		}

		$post = get_post( (int) $args[0] );
		if ( ! $post instanceof WP_Post ) {
			return $caps;
		}

		$author_id = (int) $post->post_author;
		if ( $author_id === $user_id || ! self::are_linked( $user_id, $author_id ) ) {
			return $caps;
		}

		$post_type = get_post_type_object( $post->post_type );
		if ( ! $post_type instanceof WP_Post_Type || ! $post_type->map_meta_cap ) {
			return $caps;
		}

		$own_counterparts = array(
			$post_type->cap->edit_others_posts   => $post_type->cap->edit_posts,
			$post_type->cap->delete_others_posts => $post_type->cap->delete_posts,
			$post_type->cap->read_private_posts  => $post_type->cap->read,
		);

		return array_values(
			array_unique(
				array_map(
					static function ( string $required ) use ( $own_counterparts ): string {
						return $own_counterparts[ $required ] ?? $required;
					},
					$caps
				)
			)
		);
	}

	/**
	 * Checks whether one user is the other's parent.
	 *
	 * @since x.x.x
	 *
	 * @param int $user_id  One user ID.
	 * @param int $other_id The other user ID.
	 * @return bool True when either is the other's agent.
	 */
	private static function are_linked( int $user_id, int $other_id ): bool {
		$parent = self::get_parent( $user_id );
		if ( null !== $parent && $parent->ID === $other_id ) {
			return true;
		}

		$parent = self::get_parent( $other_id );

		return null !== $parent && $parent->ID === $user_id;
	}

	/**
	 * Deletes a parent's agents together with the parent.
	 *
	 * Agent content follows the parent's: it is reassigned to the same user, or
	 * deleted when the parent's content is. An agent chosen to receive the
	 * parent's content is kept and detached, which suspends it. On multisite,
	 * `wp_delete_user()` only removes the parent from the current site, so the
	 * agents are removed from that site the same way and the heir keeps its
	 * link.
	 *
	 * @since x.x.x
	 *
	 * @param int|string $user_id  Deleted user ID.
	 * @param int|null   $reassign User ID receiving the deleted user's content, or null.
	 */
	public function delete_agents_of_deleted_user( $user_id, $reassign ): void {
		$user_id  = (int) $user_id;
		$reassign = null === $reassign ? null : (int) $reassign;

		foreach ( self::get_agent_ids( $user_id ) as $agent_id ) {
			if ( $agent_id === $reassign ) {
				$this->keep_heir( $user_id, $agent_id, ! is_multisite() );
				continue;
			}

			wp_delete_user( $agent_id, $reassign );
		}
	}

	/**
	 * Removes a parent's agents from a site the parent is removed from.
	 *
	 * Removal from one site is not account deletion: an agent chosen to receive
	 * the parent's content keeps its link, so it stays bound to the parent on
	 * every other site.
	 *
	 * @since x.x.x
	 *
	 * @param int|string      $user_id  Removed user ID.
	 * @param int|string      $blog_id  Site ID.
	 * @param int|string|null $reassign User ID receiving the removed user's content, or 0.
	 */
	public function remove_agents_of_removed_user( $user_id, $blog_id, $reassign = 0 ): void {
		$user_id  = (int) $user_id;
		$blog_id  = (int) $blog_id;
		$reassign = (int) $reassign;

		foreach ( self::get_agent_ids( $user_id ) as $agent_id ) {
			if ( $agent_id === $reassign ) {
				$this->keep_heir( $user_id, $agent_id, false );
				continue;
			}

			if ( ! is_user_member_of_blog( $agent_id, $blog_id ) ) {
				continue;
			}

			remove_user_from_blog( $agent_id, $blog_id, $reassign );
		}
	}

	/**
	 * Deletes a parent's agents together with the parent across the network.
	 *
	 * Agents that received the parent's content on a site earlier in the same
	 * request are kept and detached instead.
	 *
	 * @since x.x.x
	 *
	 * @param int|string $user_id Deleted user ID.
	 */
	public function delete_agents_of_deleted_network_user( $user_id ): void {
		$user_id = (int) $user_id;

		foreach ( self::get_agent_ids( $user_id ) as $agent_id ) {
			if ( in_array( $agent_id, $this->heirs[ $user_id ] ?? array(), true ) ) {
				delete_user_meta( $agent_id, self::META_PARENT );
				continue;
			}

			wpmu_delete_user( $agent_id );
		}

		unset( $this->heirs[ $user_id ] );
	}

	/**
	 * Keeps an agent chosen to receive its parent's content.
	 *
	 * @since x.x.x
	 *
	 * @param int  $parent_id Parent user ID.
	 * @param int  $agent_id  Agent user ID.
	 * @param bool $detach    Whether the parent account is being deleted, so the link goes too.
	 */
	private function keep_heir( int $parent_id, int $agent_id, bool $detach ): void {
		if ( $detach ) {
			delete_user_meta( $agent_id, self::META_PARENT );
			return;
		}

		$this->heirs[ $parent_id ][] = $agent_id;
	}

	/**
	 * Reports agent content when their parents are deleted.
	 *
	 * Core only offers to reassign content when the deleted users own some.
	 * Their agents are deleted along with them, so their content counts too;
	 * otherwise it would be deleted without the choice ever being offered.
	 *
	 * @since x.x.x
	 *
	 * @param bool       $has_content Whether the users have additional content.
	 * @param array<int> $user_ids    IDs of the users being deleted.
	 * @return bool True when the users or their agents own content.
	 */
	public function count_agent_content( $has_content, $user_ids ): bool {
		if ( $has_content || ! is_array( $user_ids ) ) {
			return (bool) $has_content;
		}

		$agent_ids = array();
		foreach ( $user_ids as $user_id ) {
			$agent_ids = array_merge( $agent_ids, self::get_agent_ids( (int) $user_id ) );
		}

		if ( array() === $agent_ids ) {
			return false;
		}

		global $wpdb;
		$placeholders = implode( ', ', array_fill( 0, count( $agent_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Mirrors core's own content check on the delete screen; placeholders are generated above.
		$post_id = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_author IN ( {$placeholders} ) LIMIT 1", $agent_ids ) );
		$link_id = null === $post_id ? $wpdb->get_var( $wpdb->prepare( "SELECT link_id FROM {$wpdb->links} WHERE link_owner IN ( {$placeholders} ) LIMIT 1", $agent_ids ) ) : null;
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		return null !== $post_id || null !== $link_id;
	}

	/**
	 * Names the agents deleted along with their parents on the delete screen.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_User  $current_user The user deleting accounts.
	 * @param array<int> $user_ids     IDs of the users being deleted.
	 */
	public function render_agents_deleted_with_parent( $current_user, $user_ids ): void {
		$names = array();
		foreach ( (array) $user_ids as $user_id ) {
			foreach ( self::get_agent_ids( (int) $user_id ) as $agent_id ) {
				$agent = get_user_by( 'id', $agent_id );
				if ( ! ( $agent instanceof WP_User ) ) {
					continue;
				}

				$names[] = $agent->user_login;
			}
		}

		if ( array() === $names ) {
			return;
		}

		echo '<p class="wpai-agents-deleted-with-parent"><strong>' . esc_html__( 'Their agents will be deleted too:', 'ai' ) . '</strong> ' . esc_html( implode( ', ', $names ) ) . '. ';
		echo esc_html__( 'Agent content follows the choice above. An agent you attribute the content to is kept, without a parent.', 'ai' ) . '</p>';
	}

	/**
	 * Validates the email for a new agent account.
	 *
	 * Applies the same rules core applies on the Add User screen.
	 *
	 * @since x.x.x
	 *
	 * @param string $email The requested email.
	 * @return string|\WP_Error The email to store, or an error when it cannot be used.
	 */
	private function validate_email( string $email ) {
		$email = trim( $email );

		if ( '' === $email ) {
			return new WP_Error( 'wpai_agent_empty_email', __( 'Please enter an email address.', 'ai' ) );
		}

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'wpai_agent_invalid_email', __( 'The email address is not correct.', 'ai' ) );
		}

		if ( email_exists( $email ) ) {
			return new WP_Error( 'wpai_agent_email_exists', __( 'This email is already registered. Please choose another one.', 'ai' ) );
		}

		return $email;
	}

	/**
	 * Appends the agent suffix to a username when it is missing.
	 *
	 * @since x.x.x
	 *
	 * @param string $login A sanitized username.
	 * @return string The username ending with `LOGIN_SUFFIX`.
	 */
	public static function apply_login_suffix( string $login ): string {
		$suffix_length = strlen( self::LOGIN_SUFFIX );

		if ( strlen( $login ) >= $suffix_length && substr( $login, -$suffix_length ) === self::LOGIN_SUFFIX ) {
			return $login;
		}

		return $login . self::LOGIN_SUFFIX;
	}

	/**
	 * Validates the login for a new agent account.
	 *
	 * Applies the same rules core applies on the Add User screen, after
	 * appending the agent suffix when it is missing.
	 *
	 * @since x.x.x
	 *
	 * @param string $login The requested username.
	 * @return string|\WP_Error The sanitized login, or an error when it cannot be used.
	 */
	private function validate_login( string $login ) {
		$login = sanitize_user( trim( $login ), true );

		if ( '' === $login || self::LOGIN_SUFFIX === $login ) {
			return new WP_Error( 'wpai_agent_empty_login', __( 'The agent username cannot be empty.', 'ai' ) );
		}

		$login = self::apply_login_suffix( $login );

		if ( strlen( $login ) > 60 ) {
			return new WP_Error(
				'wpai_agent_login_too_long',
				sprintf(
					/* translators: %s: Username suffix, for example "_agent". */
					__( 'The agent username may not be longer than 60 characters, including the %s suffix.', 'ai' ),
					self::LOGIN_SUFFIX
				)
			);
		}

		if ( ! validate_username( $login ) ) {
			return new WP_Error( 'wpai_agent_invalid_login', __( 'This username is invalid because it uses illegal characters. Please enter a valid username.', 'ai' ) );
		}

		if ( username_exists( $login ) ) {
			return new WP_Error( 'wpai_agent_login_exists', __( 'This username is already registered. Please choose another one.', 'ai' ) );
		}

		return $login;
	}
}
