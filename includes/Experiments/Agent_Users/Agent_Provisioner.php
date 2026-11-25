<?php
/**
 * Agent account provisioning.
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
 * Creates agent accounts.
 *
 * Provisioning checks who may create agents, who may be the parent, and that
 * the requested role grants nothing the provisioner or the parent lacks,
 * before and after the account exists.
 *
 * @since x.x.x
 */
final class Agent_Provisioner {
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
		if ( ! Agent_Account::can_enforce_network_safeguards() ) {
			return new WP_Error(
				'wpai_agent_requires_network_activation',
				__( 'Agent accounts require the AI plugin to be network-activated on multisite.', 'ai' )
			);
		}

		// Agents cannot have agents, so they cannot provision them for anyone else either.
		if ( Agent_Account::is_agent( get_current_user_id() ) ) {
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
					Agent_Account::META_KEY        => '1',
					Agent_Account::META_CREATED_BY => $provisioner_id,
					Agent_Account::META_PARENT     => $parent_id,
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
				Agent_Account::role_manages_site( $role_slug ) ||
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

		if ( Agent_Account::role_manages_site( $role ) ) {
			return new WP_Error( 'wpai_agent_role_not_allowed', __( 'Agents cannot have a role that manages the site, such as Administrator.', 'ai' ) );
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
			Agent_Account::is_agent( $parent ) ||
			( ! Agent_Account::belongs_to_site( $parent->ID, get_current_blog_id() ) && ! is_super_admin( $parent->ID ) ) ||
			// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- The agent parent capability constant.
			! user_can( $parent, Agent_Account::PARENT_CAP )
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

			// Agents never hold `unfiltered_html` or the capabilities to change other users, so these cannot represent escalation.
			if ( in_array( 'unfiltered_html', $required, true ) || array() !== array_intersect( $required, Agent_Capabilities::USER_MANAGEMENT_CAPS ) ) {
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
	 * appending the agent suffix when it is missing. Like core, a username
	 * with characters sanitizing would remove is rejected rather than quietly
	 * changed into a different one.
	 *
	 * @since x.x.x
	 *
	 * @param string $login The requested username.
	 * @return string|\WP_Error The login to store, or an error when it cannot be used.
	 */
	private function validate_login( string $login ) {
		$login = trim( $login );

		if ( '' === $login || self::LOGIN_SUFFIX === $login ) {
			return new WP_Error( 'wpai_agent_empty_login', __( 'The agent username cannot be empty.', 'ai' ) );
		}

		if ( sanitize_user( $login, true ) !== $login ) {
			return new WP_Error( 'wpai_agent_invalid_login', __( 'This username is invalid because it uses illegal characters. Please enter a valid username.', 'ai' ) );
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
