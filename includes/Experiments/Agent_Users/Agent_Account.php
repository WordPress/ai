<?php
/**
 * Agent account identity service.
 *
 * @package WordPress\AI\Experiments\Agent_Users
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Agent_Users;

use WP_Application_Passwords;
use WP_Error;
use WP_User;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Provides the agent identity primitive on top of regular user accounts.
 *
 * An agent account is a normal user with a marker in user meta. The account
 * keeps the full role system as its capability ceiling, stays visible in user
 * queries, and owns the content it creates. What changes compared to a human
 * account:
 *
 * - Interactive (password form) login is blocked. The account authenticates
 *   through Application Passwords or any other mechanism that resolves the
 *   request to this user.
 * - Password resets are disabled.
 * - A small list of capabilities is always removed, no matter the role,
 *   because their defaults are written for humans. The clearest example is
 *   `unfiltered_html`: model output combined with it means stored XSS.
 *
 * See https://github.com/WordPress/ai/issues/923 for the full proposal.
 *
 * @since x.x.x
 */
final class Agent_Account {
	/**
	 * User meta key marking an account as an agent.
	 *
	 * User meta is shared across a multisite network, so being an agent is a
	 * network-wide fact, while per-site agency stays what it is for humans:
	 * the role granted on each site.
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
	 * Domain used for generated placeholder email addresses.
	 *
	 * The `.invalid` TLD is reserved (RFC 2606), so these addresses can never
	 * route mail. Agents do not receive email: password resets are disabled
	 * and no human reads the inbox.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const EMAIL_DOMAIN = 'agents.invalid';

	/**
	 * Capabilities removed from agent accounts regardless of role.
	 *
	 * `unfiltered_html` prevents stored XSS from model output. The user
	 * management capabilities prevent an agent from creating accounts or
	 * escalating privileges through an existing one, for example by changing
	 * an administrator's email and password.
	 *
	 * @since x.x.x
	 *
	 * @var array<int, string>
	 */
	private const BLOCKED_CAPABILITIES = array( // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is used as an array const.
		'unfiltered_html',
		'create_users',
		'edit_users',
		'promote_users',
		'delete_users',
		'remove_users',
	);

	/**
	 * Hooks the identity rules into WordPress.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		add_filter( 'wp_authenticate_user', array( $this, 'block_interactive_login' ) );
		add_filter( 'allow_password_reset', array( $this, 'disable_password_reset' ), 10, 2 );
		add_filter( 'wp_is_application_passwords_available_for_user', array( $this, 'ensure_application_passwords' ), 10, 2 );
		add_filter( 'user_has_cap', array( $this, 'remove_blocked_capabilities' ), 10, 4 );
		add_filter( 'map_meta_cap', array( $this, 'map_blocked_capabilities' ), 10, 3 );
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
			$user_id = $user->ID;
		} elseif ( is_numeric( $user ) ) {
			$user_id = (int) $user;
		} else {
			return false;
		}

		if ( $user_id <= 0 ) {
			return false;
		}

		return (bool) get_user_meta( $user_id, self::META_KEY, true );
	}

	/**
	 * Provisions a new agent account.
	 *
	 * Creates the user with the given role, marks it as an agent, and creates
	 * the first Application Password. The plain-text password is returned
	 * exactly once and is never stored.
	 *
	 * @since x.x.x
	 *
	 * @param string $name Human-readable agent name, used as the display name.
	 * @param string $role Role slug for the account.
	 * @return array{user: \WP_User, password: string}|\WP_Error Provisioned account and its
	 *                                                           one-time Application Password.
	 */
	public function provision( string $name, string $role ) {
		$name = trim( $name );
		if ( '' === $name ) {
			return new WP_Error( 'wpai_agent_empty_name', __( 'The agent name cannot be empty.', 'ai' ) );
		}

		if ( ! wp_roles()->is_role( $role ) ) {
			return new WP_Error( 'wpai_agent_invalid_role', __( 'The selected role does not exist.', 'ai' ) );
		}

		$login = $this->generate_login( $name );
		if ( is_wp_error( $login ) ) {
			return $login;
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_pass'    => wp_generate_password( 64, true, true ),
				'user_email'   => $login . '@' . self::EMAIL_DOMAIN,
				'display_name' => $name,
				'role'         => $role,
				'meta_input'   => array(
					self::META_KEY        => '1',
					self::META_CREATED_BY => get_current_user_id(),
				),
			)
		);
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$credential = WP_Application_Passwords::create_new_application_password(
			$user_id,
			array( 'name' => __( 'Provisioned with the agent account', 'ai' ) )
		);
		if ( is_wp_error( $credential ) ) {
			// Roll back so a failed provisioning leaves nothing behind.
			if ( ! function_exists( 'wp_delete_user' ) ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
			}
			wp_delete_user( $user_id );

			return $credential;
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user instanceof WP_User ) {
			return new WP_Error( 'wpai_agent_not_found', __( 'The agent account could not be loaded after creation.', 'ai' ) );
		}

		return array(
			'user'     => $user,
			'password' => $credential[0],
		);
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
	 * Application Passwords are the agent's only door, so a site that disables
	 * them for regular users must not lock agents out by accident. The global
	 * availability check (HTTPS requirement) is not overridden.
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
	 * Removes blocked capabilities from an agent's capability list.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, bool>  $allcaps All capabilities of the user.
	 * @param array<int, string>   $caps    Required primitive capabilities.
	 * @param array<int, mixed>    $args    Capability check arguments.
	 * @param \WP_User             $user    The user being checked.
	 * @return array<string, bool> Filtered capabilities.
	 */
	public function remove_blocked_capabilities( array $allcaps, array $caps, array $args, WP_User $user ): array {
		if ( ! self::is_agent( $user ) ) {
			return $allcaps;
		}

		foreach ( self::BLOCKED_CAPABILITIES as $capability ) {
			unset( $allcaps[ $capability ] );
		}

		return $allcaps;
	}

	/**
	 * Maps blocked capability checks to `do_not_allow` for agent accounts.
	 *
	 * This covers the paths `user_has_cap` cannot reach, most importantly
	 * multisite super admins, who bypass the capability list entirely unless
	 * the check maps to `do_not_allow`. Both hooks read the same blocked
	 * list, so the rule cannot drift between the two layers.
	 *
	 * @since x.x.x
	 *
	 * @param array<int, string> $caps    Primitive capabilities resolved by `map_meta_cap()`.
	 * @param string             $cap     The capability being checked.
	 * @param int                $user_id The user being checked.
	 * @return array<int, string> Filtered primitive capabilities.
	 */
	public function map_blocked_capabilities( array $caps, string $cap, int $user_id ): array {
		if ( $user_id <= 0 || ! self::is_agent( $user_id ) ) {
			return $caps;
		}

		if ( in_array( $cap, self::BLOCKED_CAPABILITIES, true ) || array_intersect( $caps, self::BLOCKED_CAPABILITIES ) ) {
			return array( 'do_not_allow' );
		}

		return $caps;
	}

	/**
	 * Generates a unique login for a new agent account.
	 *
	 * @since x.x.x
	 *
	 * @param string $name The human-readable agent name.
	 * @return string|\WP_Error The login, or an error when no usable login can be derived.
	 */
	private function generate_login( string $name ) {
		$base = sanitize_user( 'agent-' . sanitize_title( $name ), true );
		$base = substr( $base, 0, 50 );

		if ( '' === $base || 'agent-' === $base ) {
			return new WP_Error( 'wpai_agent_invalid_name', __( 'The agent name must contain letters or numbers.', 'ai' ) );
		}

		$login  = $base;
		$suffix = 2;
		while ( username_exists( $login ) || email_exists( $login . '@' . self::EMAIL_DOMAIN ) ) {
			if ( $suffix > 100 ) {
				return new WP_Error( 'wpai_agent_login_exhausted', __( 'A unique login could not be generated for this name.', 'ai' ) );
			}

			$login = $base . '-' . $suffix;
			++$suffix;
		}

		return $login;
	}
}
