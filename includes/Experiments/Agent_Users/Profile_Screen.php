<?php
/**
 * Profile screen integration for agent accounts.
 *
 * @package WordPress\AI\Experiments\Agent_Users
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Agent_Users;

use WP_User;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Adapts the core profile screen for agent accounts.
 *
 * The profile remains the management surface because roles, identity data,
 * and Application Passwords already live there. Human-only login and admin UI
 * controls are hidden, while core continues to own the form and credentials.
 *
 * @since x.x.x
 */
final class Profile_Screen {
	/**
	 * Query variable flagging the profile screen right after creation.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const CREATED_QUERY_VAR = 'wpai_agent_created';

	/**
	 * Registers the profile screen hooks.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		add_filter( 'show_password_fields', array( $this, 'hide_password_fields' ), 10, 2 );
		add_filter( 'admin_title', array( $this, 'filter_admin_title' ) );
		add_action( 'admin_notices', array( $this, 'render_account_type' ) );
		add_action( 'admin_notices', array( $this, 'render_created_notice' ) );
		add_action( 'admin_print_styles-user-edit.php', array( $this, 'print_styles' ) );
		add_action( 'admin_print_footer_scripts-user-edit.php', array( $this, 'print_script' ) );
	}

	/**
	 * Hides the fields that only make sense for a person on an agent's profile.
	 *
	 * Core renders these rows unconditionally, so hiding them is a display
	 * concern.
	 *
	 * @since x.x.x
	 */
	public function print_styles(): void {
		if ( null === self::profile_agent() ) {
			return;
		}

		echo '<style>
			/* Personal Options, the admin UI preferences: an agent never sees the admin. */
			#your-profile h2:has( + .form-table :is( .user-admin-color-wrap, .user-comment-shortcuts-wrap, .user-admin-bar-front-wrap ) ),
			#your-profile .form-table:has( .user-admin-color-wrap, .user-comment-shortcuts-wrap, .user-admin-bar-front-wrap ) { display: none; }
		</style>';
	}

	/**
	 * Checks whether the current screen is a user profile edit screen.
	 *
	 * The network admin variant loads the same `user-edit.php` file, so the
	 * hook suffixes match and only the screen id differs.
	 *
	 * @since x.x.x
	 *
	 * @return bool True on the site or network user edit screen.
	 */
	private static function is_profile_screen(): bool {
		$screen = get_current_screen();

		return null !== $screen && in_array( $screen->id, array( 'user-edit', 'user-edit-network' ), true );
	}

	/**
	 * Returns the agent whose profile is being edited, if any.
	 *
	 * @since x.x.x
	 *
	 * @return \WP_User|null The agent, or null when the screen shows a human or no user.
	 */
	private static function profile_agent(): ?WP_User {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the user ID to pick a screen variant only, no data processing.
		$user_id = isset( $_GET['user_id'] ) ? (int) $_GET['user_id'] : 0;
		if ( $user_id <= 0 ) {
			return null;
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user instanceof WP_User || ! Agent_Account::is_agent( $user ) ) {
			return null;
		}

		return $user;
	}

	/**
	 * Returns the URL of an agent's profile screen.
	 *
	 * @since x.x.x
	 *
	 * @param int  $user_id      Agent user ID.
	 * @param bool $just_created Whether to flag the screen as the post-creation step.
	 * @return string
	 */
	public static function url( int $user_id, bool $just_created = false ): string {
		$url = get_edit_user_link( $user_id );

		if ( $just_created ) {
			$url = add_query_arg( self::CREATED_QUERY_VAR, '1', $url );
		}

		return $url . '#application-passwords-section';
	}

	/**
	 * Names the browser tab after the account while editing an agent.
	 *
	 * @since x.x.x
	 *
	 * @param string $admin_title The page title, with the site name appended.
	 * @return string The page title.
	 */
	public function filter_admin_title( string $admin_title ): string {
		global $pagenow;

		if ( 'user-edit.php' !== $pagenow || null === self::profile_agent() ) {
			return $admin_title;
		}

		return str_replace( __( 'Edit User' ), __( 'Edit Agent', 'ai' ), $admin_title ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- Matching core's own string.
	}

	/**
	 * Hides the password and sessions block for agent accounts.
	 *
	 * @since x.x.x
	 *
	 * @param bool     $show         Whether to show the password fields.
	 * @param \WP_User $profile_user The user being edited.
	 * @return bool False for agent accounts.
	 */
	public function hide_password_fields( bool $show, WP_User $profile_user ): bool {
		if ( Agent_Account::is_agent( $profile_user ) ) {
			return false;
		}

		return $show;
	}

	/**
	 * Renders the account type note for an agent's profile.
	 *
	 * It is descriptive text rather than a notice. Core has no hook below the
	 * title, so the footer script moves it from the notices position.
	 *
	 * @since x.x.x
	 */
	public function render_account_type(): void {
		if ( ! self::is_profile_screen() || null === self::profile_agent() ) {
			return;
		}

		echo '<p class="wpai-agent-account-type" hidden>';
		echo '<strong>' . esc_html__( 'Agent account.', 'ai' ) . '</strong> ';
		echo esc_html__( 'This account is used by software, such as an AI agent or a scheduled job, not by a person. It cannot log in with a password; use Application Passwords for API access. Its role defines what it can do, exactly as it does for a human account. An Administrator agent has full Administrator access.', 'ai' );
		echo '</p>';
	}

	/**
	 * Prints the script adapting the header and the submit button to the agent.
	 *
	 * Core has no hooks for these labels, so the agent wording is progressively
	 * enhanced on the client.
	 *
	 * @since x.x.x
	 */
	public function print_script(): void {
		if ( null === self::profile_agent() ) {
			return;
		}

		$data = wp_json_encode(
			array(
				'coreTitle'   => __( 'Edit User' ), // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- Matching core's own string.
				'title'       => __( 'Edit Agent', 'ai' ),
				'submit'      => __( 'Update Agent', 'ai' ),
				'addAgent'    => __( 'Add Agent', 'ai' ),
				'addAgentUrl' => New_User_Screen::url(),
				// Provisioning starts from a site so the initial role has site
				// context; the network profile uses core's own header action.
				'canAdd'      => ! is_network_admin() && Agent_Account::current_user_can_provision(),
			)
		);

		$script = <<<'JS'
( function () {
	var data = WPAI_DATA;
	var heading = document.querySelector( '#profile-page .wp-heading-inline' );
	var action = document.querySelector( '#profile-page .page-title-action' );
	var marker = document.querySelector( '#profile-page .wp-header-end' );
	var note = document.querySelector( '.wpai-agent-account-type' );
	var submit = document.getElementById( 'submit' );

	if ( heading ) {
		// Core renders "Edit User <name>"; keep the name.
		heading.textContent = heading.textContent.trim().replace( data.coreTitle, data.title );
	}
	if ( action && data.canAdd ) {
		action.textContent = data.addAgent;
		action.href = data.addAgentUrl;
	}
	if ( note && marker ) {
		marker.after( note );
		note.hidden = false;
	}
	if ( submit && 'submit' === submit.type ) {
		submit.value = data.submit;
	}
} )();
JS;

		wp_print_inline_script_tag( str_replace( 'WPAI_DATA', (string) $data, $script ) );
	}

	/**
	 * Renders the next-step notice right after an agent was created.
	 *
	 * @since x.x.x
	 */
	public function render_created_notice(): void {
		if ( ! self::is_profile_screen() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a query param to pick a notice only, no data processing.
		if ( empty( $_GET[ self::CREATED_QUERY_VAR ] ) ) {
			return;
		}

		$agent = self::profile_agent();
		if ( null === $agent ) {
			return;
		}

		$available = wp_is_application_passwords_available_for_user( $agent );
		$message   = $available
			/* translators: %s: Agent display name. */
			? __( 'Agent <strong>%s</strong> was created. Add an Application Password below so the agent can connect. The password is shown only once.', 'ai' )
			/* translators: %s: Agent display name. */
			: __( 'Agent <strong>%s</strong> was created, but Application Passwords are not available on this site. The agent cannot connect until the site is served over HTTPS or the environment type is set to "local".', 'ai' );

		wp_admin_notice(
			wp_kses( sprintf( $message, esc_html( $agent->display_name ) ), array( 'strong' => array() ) ),
			array(
				'type'        => $available ? 'success' : 'warning',
				'dismissible' => true,
			)
		);
	}
}
