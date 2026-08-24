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
 * Creates agents through a dedicated variant of the core Add User screen.
 *
 * Reusing the form keeps identity fields, role selection, validation errors,
 * and accessibility aligned with core. Agent mode removes password and human
 * notification controls, then redirects to the profile where core manages
 * Application Passwords.
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
	 * Agent mode adds only a hidden discriminator; all account data continues
	 * to use core's fields. Each flow links to the other to make account type
	 * an explicit choice.
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
	 * Core has no hooks for the heading, intro, or submit label, so those labels
	 * are progressively enhanced. The form remains functional without JavaScript.
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
	 * Core provides no hook next to its "Add User" action. The submenu remains
	 * the non-JavaScript entry point.
	 *
	 * @since x.x.x
	 */
	public function print_users_screen_script(): void {
		// The network Users screen manages the whole network; agents are
		// created from the specific site they will work on.
		if ( is_network_admin() || ! Agent_Account::current_user_can_provision() ) {
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
	 * This runs before core's create-user handler. Success redirects before core
	 * can create a second account; failure is passed into core's form errors so
	 * submitted values and accessibility behavior are preserved.
	 *
	 * @since x.x.x
	 */
	public function handle_create(): void {
		if ( is_network_admin() ) {
			return;
		}

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

		// The network Add User screen is a different flow; agents are always
		// created from the site whose admin provisions them.
		if ( is_network_admin() ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a query param to pick the screen variant only, no data processing.
		return 'user-new.php' === $pagenow && ! empty( $_REQUEST[ self::AGENT_FIELD ] ) && Agent_Account::current_user_can_provision();
	}
}
