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
use WP_Role;
use WP_User;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Enforces the security contract for user accounts marked as agents.
 *
 * Agents reuse WordPress users for roles, capabilities, ownership, and
 * attribution, but they cannot log in interactively or reset passwords. On
 * multisite, each agent is also bound to one site independently of its site
 * memberships.
 *
 * @since x.x.x
 */
final class Agent_Account {
	/**
	 * User meta key marking an account as an agent.
	 *
	 * User meta is shared across a multisite network, so account type is a
	 * network-wide property even though the agent may act on only one site.
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
	 * User meta key recording the site an agent was provisioned for.
	 *
	 * The value is only written and enforced on multisite. Membership controls
	 * whether the agent is active there; it does not change this boundary.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const META_SITE_ID = 'wpai_agent_site_id';

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
	 * Hooks the identity rules into WordPress.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		add_filter( 'wp_authenticate_user', array( $this, 'block_interactive_login' ) );
		add_filter( 'allow_password_reset', array( $this, 'disable_password_reset' ), 10, 2 );
		add_filter( 'wp_is_application_passwords_available_for_user', array( $this, 'ensure_application_passwords' ), 10, 2 );

		if ( ! is_multisite() ) {
			return;
		}

		/*
		 * Identity and Application Passwords are network-wide, so membership
		 * alone cannot enforce a one-site principal. Check every authentication
		 * path after core resolves a user, and reject matched Application
		 * Passwords before core records their use as successful.
		 */
		add_filter( 'determine_current_user', array( $this, 'restrict_agents_to_assigned_site' ), 100 );
		add_filter( 'authenticate', array( $this, 'block_authentication_outside_assigned_site' ), 30 );
		add_action( 'wp_authenticate_application_password_errors', array( $this, 'block_application_password_outside_assigned_site' ), 10, 2 );
		add_filter( 'can_add_user_to_blog', array( $this, 'block_adding_agents_to_other_sites' ), 10, 4 );
		add_filter( 'pre_update_site_option_site_admins', array( $this, 'strip_agents_from_super_admins' ) );
		add_filter( 'map_meta_cap', array( $this, 'let_site_admins_manage_site_agents' ), 10, 4 );
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
		$user_id = self::user_id( $user );
		if ( 0 === $user_id ) {
			return false;
		}

		return (bool) get_user_meta( $user_id, self::META_KEY, true );
	}

	/**
	 * Checks whether this plugin can enforce a multisite agent's site binding.
	 *
	 * Agent identity and Application Passwords are network-wide. On multisite,
	 * every site must therefore load the safeguards registered by this plugin.
	 * Per-site activation cannot provide that guarantee.
	 *
	 * @since x.x.x
	 *
	 * @return bool True on single site or when the plugin is network-active.
	 */
	public static function can_enforce_site_binding(): bool {
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
	 * Provisioning needs `create_users` and `promote_users` because an agent is
	 * a new account with a role. The provisioner must also hold the primitive
	 * `edit_users` capability so they can reach the new agent's profile and issue
	 * its first credential. On multisite, core maps a direct `edit_users` check
	 * to `do_not_allow` for site administrators, while this experiment maps the
	 * target-specific `edit_user` check back to that primitive capability.
	 *
	 * @since x.x.x
	 *
	 * @return bool
	 */
	public static function current_user_can_provision(): bool {
		return self::can_enforce_site_binding() &&
			current_user_can( 'create_users' ) &&
			current_user_can( 'promote_users' ) &&
			self::current_user_can_edit_agents();
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
	 * @return \WP_User|\WP_Error Provisioned account or an error.
	 */
	public function provision( string $login, string $role, string $email, string $first_name = '', string $last_name = '', string $url = '' ) {
		$provisioner_id = get_current_user_id();
		$authorization  = $this->authorize_provisioning( $role );
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

		$meta_input = array(
			self::META_KEY        => '1',
			self::META_CREATED_BY => $provisioner_id,
		);

		if ( is_multisite() ) {
			$meta_input[ self::META_SITE_ID ] = get_current_blog_id();
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
				'meta_input' => $meta_input,
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
				! $this->role_is_within_current_user_capabilities( $role_slug )
			) {
				continue;
			}
			$roles[ $role_slug ] = array( 'name' => $role_details['name'] );
		}

		return $roles;
	}

	/**
	 * Authorizes agent provisioning and assignment of the requested role.
	 *
	 * @since x.x.x
	 *
	 * @param string $role Requested role slug.
	 * @return true|\WP_Error True when authorized, otherwise an error.
	 */
	private function authorize_provisioning( string $role ) {
		if ( ! self::can_enforce_site_binding() ) {
			return new WP_Error(
				'wpai_agent_requires_network_activation',
				__( 'Agent accounts require the AI plugin to be network-activated on multisite.', 'ai' )
			);
		}

		if ( ! current_user_can( 'create_users' ) ) {
			return new WP_Error( 'wpai_agent_cannot_create_users', __( 'You are not allowed to create users.', 'ai' ) );
		}

		if ( ! current_user_can( 'promote_users' ) ) {
			return new WP_Error( 'wpai_agent_cannot_promote_users', __( 'You are not allowed to assign roles to users.', 'ai' ) );
		}

		if ( ! self::current_user_can_edit_agents() ) {
			return new WP_Error(
				'wpai_agent_cannot_manage_agents',
				__( 'You must be allowed to edit agent accounts before you can create them.', 'ai' )
			);
		}

		// `get_assignable_roles()` only contains existing roles, so this also
		// rejects role slugs that do not exist.
		if ( ! array_key_exists( $role, $this->get_assignable_roles() ) ) {
			return new WP_Error(
				'wpai_agent_role_not_assignable',
				__( 'The selected role does not exist or grants permissions you do not have.', 'ai' )
			);
		}

		return true;
	}

	/**
	 * Checks that an agent role cannot exceed the current user's permissions.
	 *
	 * @since x.x.x
	 *
	 * @param string $role Role slug.
	 * @return bool True when every effective role capability is held by the current user.
	 */
	private function role_is_within_current_user_capabilities( string $role ): bool {
		$role_object = wp_roles()->get_role( $role );
		if ( ! $role_object instanceof WP_Role ) {
			return false;
		}

		$current_user_id = get_current_user_id();

		foreach ( $role_object->capabilities as $capability => $granted ) {
			if ( ! $granted ) {
				continue;
			}

			// Legacy user levels grant nothing on their own.
			if ( 0 === strpos( $capability, 'level_' ) ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Mapping every capability granted by the selected role.
			$required = map_meta_cap( $capability, $current_user_id );

			/*
			 * Core maps globally unavailable capabilities to `do_not_allow`, for
			 * example `manage_links` when the Link Manager is disabled. A plugin
			 * may also return it only for this provisioner, which cannot be known
			 * until the marked agent exists; the post-creation check handles that.
			 */
			if ( in_array( 'do_not_allow', $required, true ) ) {
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
			if ( ! current_user_can( $capability ) ) {
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
	 * Checks the primitive capability used to manage an agent after creation.
	 *
	 * @since x.x.x
	 *
	 * @return bool True when the current user can satisfy an `edit_user` check
	 *              for a site-bound agent.
	 */
	private static function current_user_can_edit_agents(): bool {
		$user = wp_get_current_user();

		return current_user_can( 'edit_users' ) || ! empty( $user->allcaps['edit_users'] );
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
	 * Keeps Application Passwords available for agent accounts on their site.
	 *
	 * Application Passwords are the built-in credential path for agents, so the
	 * assigned site may not lock them out with a user-level filter. On multisite,
	 * they stay unavailable from every other site and from Network Admin. The
	 * global availability check (HTTPS requirement) is not overridden.
	 *
	 * @since x.x.x
	 *
	 * @param bool     $available Whether Application Passwords are available for the user.
	 * @param \WP_User $user      The user being checked.
	 * @return bool Whether Application Passwords are available in this context.
	 */
	public function ensure_application_passwords( bool $available, WP_User $user ): bool {
		if ( ! self::is_agent( $user ) ) {
			return $available;
		}

		return ! is_multisite() || ( ! is_network_admin() && self::agent_can_act_on_current_site( $user ) );
	}

	/**
	 * Returns the site an agent was provisioned for.
	 *
	 * Missing or invalid metadata returns zero. Multisite safeguards treat that
	 * as unbound and fail closed.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_User|int $user Agent user object or ID.
	 * @return int Assigned site ID, or zero when none is recorded.
	 */
	public static function get_site_id( $user ): int {
		$user_id = self::user_id( $user );
		if ( 0 === $user_id || ! self::is_agent( $user_id ) ) {
			return 0;
		}

		return max( 0, (int) get_user_meta( $user_id, self::META_SITE_ID, true ) );
	}

	/**
	 * Checks whether an agent may act on the current site.
	 *
	 * The recorded site is the identity boundary; membership is the local
	 * enablement switch. Both must match. On single-site installations there is
	 * no cross-site boundary to enforce.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_User|int $user Agent user object or ID.
	 * @return bool True when the current site is the assigned site and the agent remains a member.
	 */
	private static function agent_can_act_on_current_site( $user ): bool {
		$user_id = self::user_id( $user );
		if ( 0 === $user_id ) {
			return false;
		}

		if ( ! is_multisite() ) {
			return true;
		}

		$site_id = self::get_site_id( $user_id );

		return $site_id > 0 && get_current_blog_id() === $site_id && is_user_member_of_blog( $user_id, $site_id );
	}

	/**
	 * Keeps an agent identity from resolving outside its assigned site.
	 *
	 * Runs on `determine_current_user` after core resolved Application
	 * Passwords, which covers REST and any other cookie-less request. On a
	 * site other than the recorded one, or after its site membership is
	 * removed, the request stays unauthenticated instead of acting as a
	 * capability-less network user.
	 *
	 * @since x.x.x
	 *
	 * @param int|false $user_id The user determined for the request so far.
	 * @return int|false The user, or 0 when the agent may not act on this site.
	 */
	public function restrict_agents_to_assigned_site( $user_id ) {
		if ( ! is_numeric( $user_id ) || (int) $user_id <= 0 ) {
			return $user_id;
		}

		if ( self::is_agent_restricted_on_current_site( (int) $user_id ) ) {
			return 0;
		}

		return $user_id;
	}

	/**
	 * Rejects agent authentication outside its assigned site.
	 *
	 * Runs on `authenticate`, which XML-RPC and the login flow use instead of
	 * `determine_current_user`. Both hooks enforce the same rule so no
	 * authentication path resolves an agent outside its site.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_User|\WP_Error|null $user The authentication result so far.
	 * @return \WP_User|\WP_Error|null The result, or an error for an agent outside its assigned site.
	 */
	public function block_authentication_outside_assigned_site( $user ) {
		if ( ! $user instanceof WP_User || ! self::is_agent_restricted_on_current_site( $user ) ) {
			return $user;
		}

		$error = new WP_Error();
		self::add_wrong_site_error( $error );

		return $error;
	}

	/**
	 * Rejects an Application Password before success is recorded off-site.
	 *
	 * Core fires this action after matching the credential but before recording
	 * its usage. The later authentication filters remain as backstops for other
	 * authentication mechanisms.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Error $error Authentication errors, mutated by reference.
	 * @param \WP_User  $user  User authenticating with the matched credential.
	 */
	public function block_application_password_outside_assigned_site( WP_Error $error, WP_User $user ): void {
		if ( ! self::is_agent_restricted_on_current_site( $user ) ) {
			return;
		}

		self::add_wrong_site_error( $error );
	}

	/**
	 * Blocks adding an agent to sites other than its assigned site.
	 *
	 * Membership may disable and later restore an agent on its assigned site,
	 * but it must never widen the recorded boundary.
	 *
	 * @since x.x.x
	 *
	 * @param true|\WP_Error $allowed Whether the user may be added to the site.
	 * @param int            $user_id The user being added.
	 * @param string         $role    Role the user would receive.
	 * @param int            $blog_id Target site ID.
	 * @return true|\WP_Error The prior result on the assigned site, or an error elsewhere.
	 */
	public function block_adding_agents_to_other_sites( $allowed, int $user_id, string $role, int $blog_id ) {
		if ( ! self::is_agent( $user_id ) ) {
			return $allowed;
		}

		if ( self::get_site_id( $user_id ) === $blog_id ) {
			return $allowed;
		}

		return new WP_Error(
			'wpai_agent_site_bound',
			__( 'Agent accounts are bound to the site they were created for and cannot be added to another site. Create a separate agent on that site instead.', 'ai' )
		);
	}

	/**
	 * Keeps agent accounts out of the network's super admin list.
	 *
	 * Super admin is a network-wide status outside the role system. It is
	 * incompatible with an agent identity that is deliberately bound to one
	 * site, so site-bound agents cannot receive it.
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
	 * Lets site administrators manage the agents of their own site.
	 *
	 * Core normally reserves editing another account for network admins. The
	 * one-site boundary makes a site's own agent a local principal, so its site
	 * administrators may manage it with `edit_users`. Application Password meta
	 * capabilities map through `edit_user` and inherit the same boundary.
	 *
	 * @since x.x.x
	 *
	 * @param array<int, string> $caps    Primitive capabilities resolved by `map_meta_cap()`.
	 * @param string             $cap     The capability being checked.
	 * @param int                $user_id The user the check runs for.
	 * @param array<int, mixed>  $args    Capability check arguments; the edited user first.
	 * @return array<int, string> Filtered primitive capabilities.
	 */
	public function let_site_admins_manage_site_agents( array $caps, string $cap, int $user_id, array $args ): array {
		if ( 'edit_user' !== $cap || ! isset( $args[0] ) || ! in_array( 'do_not_allow', $caps, true ) ) {
			return $caps;
		}

		// Do not grant agents the site-administrator exception. Their assigned
		// role and core's normal capability mapping remain authoritative.
		if ( self::is_agent( $user_id ) ) {
			return $caps;
		}

		$target = (int) $args[0];
		if (
			$target <= 0 ||
			$target === $user_id ||
			! self::is_agent( $target ) ||
			is_super_admin( $target ) ||
			! self::agent_can_act_on_current_site( $target )
		) {
			return $caps;
		}

		return array( 'edit_users' );
	}

	/**
	 * Normalizes a user object or ID.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_User|int $user User object or user ID.
	 * @return int Positive user ID, or zero for an invalid value.
	 */
	private static function user_id( $user ): int {
		if ( $user instanceof WP_User ) {
			return max( 0, $user->ID );
		}

		return is_numeric( $user ) ? max( 0, (int) $user ) : 0;
	}

	/**
	 * Checks whether an agent is outside its current-site boundary.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_User|int $user User object or user ID.
	 * @return bool True only for an agent that cannot act on the current site.
	 */
	private static function is_agent_restricted_on_current_site( $user ): bool {
		return self::is_agent( $user ) && ! self::agent_can_act_on_current_site( $user );
	}

	/**
	 * Adds the canonical site-boundary authentication error.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Error $error Authentication errors, mutated by reference.
	 */
	private static function add_wrong_site_error( WP_Error $error ): void {
		$error->add(
			'wpai_agent_wrong_site',
			__( 'Agent accounts can only authenticate on the site they were created for.', 'ai' )
		);
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
