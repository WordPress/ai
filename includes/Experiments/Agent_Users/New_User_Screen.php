<?php
/**
 * Add User screen integration for agent accounts.
 *
 * @package WordPress\AI\Experiments\Agent_Users
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Agent_Users;

use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Lets administrators create an agent from Users → Add Agent.
 *
 * An agent is a regular user with a marker, so it is created where every
 * other user is created. "Users → Add Agent" opens the Add User screen in
 * agent mode, the way "Add Page" is "Add Post" with a parameter. Both flows
 * share one form, so there is one set of validation, role restrictions, and
 * errors. In agent mode the password and the notification email are hidden
 * and ignored: there is no password to send because the account cannot log
 * in interactively. Username, email, names, website, and role stay, exactly
 * as for any other user, and the display name is derived from the names
 * the way core does it.
 *
 * Submissions are intercepted on `admin_action_createuser`, which fires
 * before core's own handler. Successful creation redirects to the agent's
 * profile, where core's Application Passwords UI issues the credential. A
 * failed one is handed to core's validation, so the form re-renders with
 * the error and the submitted values, exactly as for any other user.
 *
 * @since x.x.x
 */
final class New_User_Screen {
	/**
	 * Query variable and hidden field marking the flow as agent creation.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const AGENT_FIELD = 'wpai_agent';

	/**
	 * Submenu slug of the "Add Agent" entry: the Add User screen in agent mode.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const MENU_SLUG = 'user-new.php?' . self::AGENT_FIELD . '=1';

	/**
	 * Agent account service.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Experiments\Agent_Users\Agent_Account
	 */
	private Agent_Account $account;

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Experiments\Agent_Users\Agent_Account $account Agent account service.
	 */
	public function __construct( Agent_Account $account ) {
		$this->account = $account;
	}

	/**
	 * Registers the Add User screen hooks.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_submenu' ) );
		add_filter( 'submenu_file', array( $this, 'highlight_submenu' ) );
		add_filter( 'admin_title', array( $this, 'filter_admin_title' ) );
		add_action( 'user_new_form', array( $this, 'render_fields' ) );
		add_action( 'admin_action_createuser', array( $this, 'handle_create' ) );
		add_action( 'admin_print_styles-user-new.php', array( $this, 'print_styles' ) );
		add_action( 'admin_print_footer_scripts-user-new.php', array( $this, 'print_script' ) );
		add_action( 'admin_print_footer_scripts-users.php', array( $this, 'print_users_screen_script' ) );
	}

	/**
	 * Adds "Add Agent" under Users, right after "Add User".
	 *
	 * The slug is the Add User screen with the agent parameter, so the menu
	 * entry opens the shared form in agent mode.
	 *
	 * @since x.x.x
	 */
	public function add_submenu(): void {
		if ( ! Agent_Account::current_user_can_provision() ) {
			return;
		}

		add_submenu_page(
			'users.php',
			__( 'Add Agent', 'ai' ),
			__( 'Add Agent', 'ai' ),
			'create_users',
			self::MENU_SLUG,
			'',
			2 // Ordinal position: after "All Users" and "Add User".
		);
	}

	/**
	 * Highlights "Add Agent" in the menu while the screen is in agent mode.
	 *
	 * @since x.x.x
	 *
	 * @param string|null $submenu_file The submenu file to highlight.
	 * @return string|null The submenu file.
	 */
	public function highlight_submenu( $submenu_file ) {
		if ( self::is_agent_mode() ) {
			return self::MENU_SLUG;
		}

		return $submenu_file;
	}

	/**
	 * Names the browser tab after the flow while in agent mode.
	 *
	 * @since x.x.x
	 *
	 * @param string $admin_title The page title, with the site name appended.
	 * @return string The page title.
	 */
	public function filter_admin_title( string $admin_title ): string {
		if ( ! self::is_agent_mode() ) {
			return $admin_title;
		}

		return str_replace( __( 'Add User' ), __( 'Add Agent', 'ai' ), $admin_title ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- Matching core's own string.
	}

	/**
	 * Prints the stylesheet hiding human-only fields in agent mode.
	 *
	 * Core renders the rows unconditionally, so hiding them is a display
	 * concern. The handler ignores their values either way.
	 *
	 * @since x.x.x
	 */
	public function print_styles(): void {
		if ( ! self::is_agent_mode() ) {
			return;
		}

		echo '<style>
			#createuser tr:has( #pass1, #send_user_notification, #noconfirmation ),
			#createuser .user-pass2-wrap,
			#createuser .pw-weak { display: none; }
		</style>';
	}

	/**
	 * Returns the URL of the Add Agent screen.
	 *
	 * @since x.x.x
	 *
	 * @return string
	 */
	public static function url(): string {
		return admin_url( self::MENU_SLUG );
	}

	/**
	 * Renders the agent fields in agent mode, or a pointer to it otherwise.
	 *
	 * Each flow links to the other, so a wrong turn costs one click. The
	 * pointer renders where the hook fires and is moved below the submit
	 * button by the footer script.
	 *
	 * Agent mode adds no inputs of its own: core's username, email, names,
	 * website, and role fields are reused, and a hidden field marks the flow.
	 *
	 * @since x.x.x
	 *
	 * @param string $context Form context, `add-new-user` or `add-existing-user`.
	 */
	public function render_fields( string $context ): void {
		if ( 'add-new-user' !== $context || ! Agent_Account::current_user_can_provision() ) {
			return;
		}

		if ( ! self::is_agent_mode() ) {
			echo '<p class="description wpai-agent-pointer">' . wp_kses(
				sprintf(
					/* translators: %s: URL of the Add Agent screen. */
					__( 'Adding an account for an AI agent, an MCP client, or a scheduled job? Use <a href="%s">Add Agent</a> instead.', 'ai' ),
					esc_url( self::url() )
				),
				array( 'a' => array( 'href' => array() ) )
			) . '</p>';
			return;
		}

		echo '<input type="hidden" name="' . esc_attr( self::AGENT_FIELD ) . '" value="1" />';

		echo '<p class="description wpai-agent-pointer">' . wp_kses(
			sprintf(
				/* translators: %s: URL of the Add User screen. */
				__( 'Adding an account for a person? Use <a href="%s">Add User</a> instead.', 'ai' ),
				esc_url( admin_url( 'user-new.php' ) )
			),
			array( 'a' => array( 'href' => array() ) )
		) . '</p>';
	}

	/**
	 * Prints the script adapting the Add User screen.
	 *
	 * Core hardcodes the heading, the intro paragraph, and the submit button
	 * label, and fires `user_new_form` only between the fields and the submit
	 * button. The small adjustments that need other positions happen on the
	 * client: the cross-link to the other flow moves below the submit button,
	 * out of the way of the form, and in agent mode the texts are adapted.
	 * Without JavaScript the form still works, it just reads "Add User".
	 *
	 * @since x.x.x
	 */
	public function print_script(): void {
		if ( ! Agent_Account::current_user_can_provision() ) {
			return;
		}

		$data = wp_json_encode(
			array(
				'agentMode' => self::is_agent_mode(),
				'label'     => __( 'Add Agent', 'ai' ),
				'intro'     => array(
					__( 'Create an account for an AI agent, an MCP client, a scheduled job, or similar software. Agent accounts cannot log in with a password. They authenticate with an Application Password, which you create on the agent’s profile right after this step. Their work is attributed to the agent, and their access can be revoked without touching a human account. The email receives notifications about the agent’s activity.', 'ai' ),
					__( 'The role is the agent’s capability ceiling. Grant the smallest role that fits the work. Some capabilities are always blocked for agents, no matter the role: posting unfiltered HTML and creating, editing, promoting, or deleting users.', 'ai' ),
				),
			)
		);

		$script = <<<'JS'
( function () {
	var data = WPAI_DATA;
	var form = document.getElementById( 'createuser' );
	if ( ! form ) {
		return;
	}

	var heading = document.getElementById( 'add-new-user' );
	var submit = document.getElementById( 'createusersub' );
	var pointer = form.querySelector( '.wpai-agent-pointer' );
	var intro = form.previousElementSibling;

	if ( ! intro || 'P' !== intro.tagName ) {
		intro = null;
	}

	if ( data.agentMode ) {
		if ( heading ) {
			heading.textContent = data.label;
		}
		if ( submit ) {
			submit.value = data.label;
		}
		if ( intro ) {
			intro.textContent = data.intro[ 0 ];
			data.intro.slice( 1 ).forEach( function ( text ) {
				var paragraph = document.createElement( 'p' );
				paragraph.textContent = text;
				intro.after( paragraph );
				intro = paragraph;
			} );
		}
	}

	if ( submit && pointer ) {
		submit.closest( 'p.submit' ).after( pointer );
	}
} )();
JS;

		wp_print_inline_script_tag( str_replace( 'WPAI_DATA', (string) $data, $script ) );
	}

	/**
	 * Prints the script adding an "Add Agent" action to the Users screen header.
	 *
	 * Core renders the header action without a hook, so the second action is
	 * added on the client next to "Add User". The menu entry remains the
	 * canonical way in.
	 *
	 * @since x.x.x
	 */
	public function print_users_screen_script(): void {
		if ( ! Agent_Account::current_user_can_provision() ) {
			return;
		}

		$link = wp_json_encode(
			sprintf(
				'<a href="%1$s" class="page-title-action">%2$s</a>',
				esc_url( self::url() ),
				esc_html__( 'Add Agent', 'ai' )
			)
		);

		$script = <<<'JS'
( function () {
	var action = document.querySelector( '.wrap .page-title-action' );
	if ( action ) {
		action.insertAdjacentHTML( 'afterend', ' ' + WPAI_LINK );
	}
} )();
JS;

		wp_print_inline_script_tag( str_replace( 'WPAI_LINK', (string) $link, $script ) );
	}

	/**
	 * Creates the agent when the form was submitted in agent mode.
	 *
	 * Runs on `admin_action_createuser`, before `user-new.php` handles the
	 * request. On success the request ends with a redirect, so core's own
	 * user creation never runs. On failure the error is queued for core's
	 * validation (see `report_errors()`), which stops the insert and re-renders
	 * the form with the submitted values.
	 *
	 * @since x.x.x
	 */
	public function handle_create(): void {
		if ( empty( $_POST[ self::AGENT_FIELD ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Deciding whether to handle the request; the nonce is verified right after.
			return;
		}

		check_admin_referer( 'create-user', '_wpnonce_create-user' );

		if ( ! Agent_Account::current_user_can_provision() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to create agent accounts.', 'ai' ), 403 );
		}

		$login      = isset( $_POST['user_login'] ) ? sanitize_user( wp_unslash( $_POST['user_login'] ), true ) : '';
		$email      = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$first_name = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
		$last_name  = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
		$url        = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$role       = isset( $_POST['role'] ) ? sanitize_key( wp_unslash( $_POST['role'] ) ) : '';

		$result = $this->account->provision( $login, $role, $email, $first_name, $last_name, $url );

		if ( is_wp_error( $result ) ) {
			add_action(
				'user_profile_update_errors',
				function ( WP_Error $errors ) use ( $result ): void {
					$this->report_errors( $errors, $result );
				}
			);
			return;
		}

		wp_safe_redirect( Profile_Screen::url( $result->ID, true ) );
		exit;
	}

	/**
	 * Reports a failed agent submission through core's form validation.
	 *
	 * Core validates the same username and email rules first, so the agent
	 * error is only added when core found nothing, to avoid saying the same
	 * thing twice. The password is hidden in agent mode and never used, so
	 * core's complaint about it is dropped.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Error $errors Core's validation errors, by reference.
	 * @param \WP_Error $result The provisioning error.
	 */
	private function report_errors( WP_Error $errors, WP_Error $result ): void {
		$errors->remove( 'pass' );

		if ( $errors->has_errors() ) {
			return;
		}

		$errors->add( $result->get_error_code(), $result->get_error_message() );
	}

	/**
	 * Checks whether the Add User screen was opened in agent mode.
	 *
	 * @since x.x.x
	 *
	 * @return bool
	 */
	private static function is_agent_mode(): bool {
		global $pagenow;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a query param to pick the screen variant only, no data processing.
		return 'user-new.php' === $pagenow && ! empty( $_REQUEST[ self::AGENT_FIELD ] ) && Agent_Account::current_user_can_provision();
	}
}
