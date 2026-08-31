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
 * attribution, but they cannot log in interactively or reset passwords.
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
		add_filter( 'map_meta_cap', array( $this, 'strip_unfiltered_html_from_agents' ), 10, 3 );

		if ( ! is_multisite() ) {
			return;
		}

		add_filter( 'pre_update_site_option_site_admins', array( $this, 'strip_agents_from_super_admins' ) );
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
	 * Provisioning needs `create_users` and `promote_users` because an agent is
	 * a new account with a role. The provisioner must also hold the primitive
	 * `edit_users` capability so they can reach the new agent's profile and issue
	 * its first credential. Core's normal capability mapping remains authoritative
	 * on both single-site and multisite installations.
	 *
	 * @since x.x.x
	 *
	 * @return bool
	 */
	public static function current_user_can_provision(): bool {
		return self::can_enforce_network_safeguards() &&
			current_user_can( 'create_users' ) &&
			current_user_can( 'promote_users' ) &&
			current_user_can( 'edit_users' );
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
		if ( ! self::can_enforce_network_safeguards() ) {
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

		if ( ! current_user_can( 'edit_users' ) ) {
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
	 * reinstates core's KSES filtering on content paths that use it. The
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
		if ( 'unfiltered_html' !== $cap || ! self::is_agent( $user_id ) ) {
			return $caps;
		}

		if ( user_can( $user_id, 'manage_options' ) ) {
			return $caps;
		}

		return array( 'do_not_allow' );
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
