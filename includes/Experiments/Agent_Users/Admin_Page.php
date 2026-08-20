<?php
/**
 * Admin page for provisioning and listing agent accounts.
 *
 * @package WordPress\AI\Experiments\Agent_Users
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Agent_Users;

use WP_Application_Passwords;
use WP_Application_Passwords_List_Table;
use WP_User;
use WP_User_Query;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the Users → AI Agents admin page.
 *
 * The page lists provisioned agents and hosts the creation form. After an
 * account is created, WordPress core's Application Password REST flow creates
 * and reveals its first credential exactly once. Everything else (editing,
 * revoking passwords, deleting with content reassignment) intentionally
 * reuses the core user screens.
 *
 * @since x.x.x
 */
final class Admin_Page {
	/**
	 * Menu slug used by the admin page.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const PAGE_SLUG = 'wpai-agent-users';

	/**
	 * Parent menu used to anchor this page.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const PARENT_SLUG = 'users.php';

	/**
	 * Admin hook suffix for this page.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const HOOK_SUFFIX = 'users_page_' . self::PAGE_SLUG;

	/**
	 * Number of agents shown in the recent agents table.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	private const RECENT_AGENTS_LIMIT = 20;

	/**
	 * Form action name for the `admin-post.php` handler.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const FORM_ACTION = 'wpai_create_agent_user';

	/**
	 * Prefix of the transient carrying the non-sensitive creation result.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const RESULT_TRANSIENT_PREFIX = 'wpai_agent_user_result_';

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
	 * Registers the admin menu entry and the form handler.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_submenu' ) );
		add_action( 'admin_post_' . self::FORM_ACTION, array( $this, 'handle_create' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_print_styles-users_page_' . self::PAGE_SLUG, array( self::class, 'print_styles' ) );
	}

	/**
	 * Returns the absolute admin URL for this page.
	 *
	 * @since x.x.x
	 *
	 * @return string
	 */
	public static function url(): string {
		return admin_url( 'users.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * Adds the submenu under Users.
	 *
	 * @since x.x.x
	 */
	public function add_submenu(): void {
		if ( ! self::current_user_can_provision() ) {
			return;
		}

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'AI Agents', 'ai' ),
			__( 'AI Agents', 'ai' ),
			'create_users',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Enqueues WordPress core's Application Password management scripts.
	 *
	 * Core creates Application Passwords through its REST endpoint and reveals
	 * the plaintext credential only in that response. Reusing the same script
	 * keeps agent credentials out of transients, user meta, and redirect URLs.
	 *
	 * @since x.x.x
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( self::HOOK_SUFFIX !== $hook_suffix ) {
			return;
		}

		wp_enqueue_script( 'user-profile' );
		wp_enqueue_script( 'application-passwords' );
	}

	/**
	 * Prints the small stylesheet used by the page.
	 *
	 * @since x.x.x
	 */
	public static function print_styles(): void {
		echo '<style>
			.wpai-agent-create-form { max-width: 600px; }
			.wpai-agent-application-passwords { max-width: 900px; }
		</style>';
	}

	/**
	 * Handles the creation form submission.
	 *
	 * @since x.x.x
	 */
	public function handle_create(): void {
		if ( ! self::current_user_can_provision() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to create users with assignable roles.', 'ai' ) );
		}

		check_admin_referer( self::FORM_ACTION );

		$name = isset( $_POST['wpai_agent_name'] ) ? sanitize_text_field( wp_unslash( $_POST['wpai_agent_name'] ) ) : '';
		$role = isset( $_POST['wpai_agent_role'] ) ? sanitize_key( wp_unslash( $_POST['wpai_agent_role'] ) ) : '';

		$result = $this->account->provision( $name, $role );

		if ( is_wp_error( $result ) ) {
			$this->store_result(
				array(
					'type'    => 'error',
					'message' => $result->get_error_message(),
				)
			);
			$this->redirect_back();
		}

		$this->store_result(
			array(
				'type'    => 'success',
				'user_id' => $result->ID,
				'login'   => $result->user_login,
			)
		);
		$this->redirect_back();
	}

	/**
	 * Renders the page.
	 *
	 * @since x.x.x
	 */
	public function render(): void {
		if ( ! self::current_user_can_provision() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'ai' ) );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'AI Agents', 'ai' ) . '</h1>';
		echo '<p>' . esc_html__( 'Agent accounts give external AI agents their own identity: work is attributed to the agent, and its access can be granted, audited, and revoked without touching a human account. Agents cannot log in interactively and authenticate with an Application Password instead.', 'ai' ) . '</p>';

		$this->render_result_notice();
		$this->render_create_form();
		$this->render_agents_table();

		echo '</div>';
	}

	/**
	 * Renders the result notice from the last form submission.
	 *
	 * @since x.x.x
	 */
	private function render_result_notice(): void {
		$result = $this->take_result();
		if ( null === $result ) {
			return;
		}

		if ( 'error' === $result['type'] ) {
			echo '<div class="notice notice-error"><p>' . esc_html( (string) $result['message'] ) . '</p></div>';
			return;
		}

		$edit_link = get_edit_user_link( (int) $result['user_id'] );

		echo '<div class="notice notice-success">';
		echo '<p>' . wp_kses(
			sprintf(
				/* translators: 1: Agent login name, 2: URL of the user profile screen. */
				__( 'Agent <strong>%1$s</strong> was created. You can adjust it any time on its <a href="%2$s">profile screen</a>.', 'ai' ),
				esc_html( (string) $result['login'] ),
				esc_url( $edit_link )
			),
			array(
				'strong' => array(),
				'a'      => array( 'href' => array() ),
			)
		) . '</p>';
		echo '</div>';

		$this->render_application_passwords( (int) $result['user_id'] );
	}

	/**
	 * Renders the creation form.
	 *
	 * @since x.x.x
	 */
	private function render_create_form(): void {
		$roles = $this->account->get_assignable_roles();

		echo '<h2>' . esc_html__( 'Add New Agent', 'ai' ) . '</h2>';
		echo '<form class="wpai-agent-create-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::FORM_ACTION ) . '" />';
		wp_nonce_field( self::FORM_ACTION );

		echo '<table class="form-table" role="presentation">';

		echo '<tr>';
		echo '<th scope="row"><label for="wpai_agent_name">' . esc_html__( 'Agent name', 'ai' ) . '</label></th>';
		echo '<td><input name="wpai_agent_name" id="wpai_agent_name" type="text" class="regular-text" required="required" />';
		echo '<p class="description">' . esc_html__( 'Shown wherever the agent’s work is attributed, for example as the post author. The login name is generated from it with an "agent-" prefix.', 'ai' ) . '</p></td>';
		echo '</tr>';

		echo '<tr>';
		echo '<th scope="row"><label for="wpai_agent_role">' . esc_html__( 'Role', 'ai' ) . '</label></th>';
		echo '<td><select name="wpai_agent_role" id="wpai_agent_role">';
		foreach ( $roles as $role_slug => $role_details ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $role_slug ),
				selected( 'editor', $role_slug, false ),
				esc_html( translate_user_role( $role_details['name'] ) )
			);
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'The role is the agent’s capability ceiling. Grant the smallest role that fits the work. An Administrator agent controls most of the site, so only use it when the work truly needs it.', 'ai' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Some capabilities are always blocked for agents, no matter the role: posting unfiltered HTML and creating, editing, promoting, or deleting users.', 'ai' ) . '</p></td>';
		echo '</tr>';

		echo '</table>';

		submit_button( __( 'Create Agent', 'ai' ) );
		echo '</form>';
	}

	/**
	 * Renders the table of recently created agents.
	 *
	 * This is a convenience list, not the management surface. The full,
	 * paginated list lives in the "AI Agents" view on the Users screen.
	 *
	 * @since x.x.x
	 */
	private function render_agents_table(): void {
		$query  = new WP_User_Query(
			array(
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Admin-screen query limited to the most recent agents.
				'meta_key'     => Agent_Account::META_KEY,
				'meta_compare' => 'EXISTS',
				'orderby'      => array(
					'registered' => 'DESC',
					'ID'         => 'DESC',
				),
				'number'       => self::RECENT_AGENTS_LIMIT,
				'count_total'  => true,
			)
		);
		$agents = $query->get_results();
		$total  = $query->get_total();

		echo '<h2>' . esc_html(
			sprintf(
				/* translators: %s: Number of agent accounts. */
				__( 'Recent Agents (%s)', 'ai' ),
				number_format_i18n( $total )
			)
		) . '</h2>';

		if ( array() === $agents ) {
			echo '<p>' . esc_html__( 'No agents have been created yet.', 'ai' ) . '</p>';
			return;
		}

		if ( $total > count( $agents ) ) {
			echo '<p>' . wp_kses(
				sprintf(
					/* translators: 1: Number of agents shown, 2: URL of the AI Agents view, 3: Total number of agents. */
					__( 'Showing the %1$s most recent agents. <a href="%2$s">See all %3$s agents on the Users screen</a>.', 'ai' ),
					esc_html( number_format_i18n( count( $agents ) ) ),
					esc_url( Users_Screen::view_url() ),
					esc_html( number_format_i18n( $total ) )
				),
				array( 'a' => array( 'href' => array() ) )
			) . '</p>';
		}

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Name', 'ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Login', 'ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Role', 'ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Created by', 'ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Created on', 'ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Application Passwords', 'ai' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $agents as $agent ) {
			if ( ! $agent instanceof WP_User ) {
				continue;
			}

			$role_names = array_map(
				static function ( string $role_slug ): string {
					$role_details = wp_roles()->roles[ $role_slug ] ?? null;
					return null === $role_details ? $role_slug : translate_user_role( $role_details['name'] );
				},
				$agent->roles
			);

			$created_by      = (int) get_user_meta( $agent->ID, Agent_Account::META_CREATED_BY, true );
			$created_by_user = $created_by > 0 ? get_user_by( 'id', $created_by ) : false;
			$password_count  = count( WP_Application_Passwords::get_user_application_passwords( $agent->ID ) );

			echo '<tr>';
			echo '<td><a href="' . esc_url( get_edit_user_link( $agent->ID ) ) . '"><strong>' . esc_html( $agent->display_name ) . '</strong></a></td>';
			echo '<td>' . esc_html( $agent->user_login ) . '</td>';
			echo '<td>' . esc_html( implode( ', ', $role_names ) ) . '</td>';
			echo '<td>' . esc_html( $created_by_user instanceof WP_User ? $created_by_user->display_name : __( 'Unknown', 'ai' ) ) . '</td>';
			echo '<td>' . esc_html( (string) mysql2date( (string) get_option( 'date_format' ), $agent->user_registered ) ) . '</td>';
			echo '<td>' . esc_html( (string) $password_count ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '<p class="description">' . wp_kses(
			sprintf(
				/* translators: %s: URL of the AI Agents view on the Users screen. */
				__( 'The <a href="%s">AI Agents view on the Users screen</a> lists every agent with search, sorting, and pagination. Use the profile screen to revoke Application Passwords, and the Users screen to delete an agent. Deleting asks what to do with the agent’s content, so nothing is lost by accident.', 'ai' ),
				esc_url( Users_Screen::view_url() )
			),
			array( 'a' => array( 'href' => array() ) )
		) . '</p>';
	}

	/**
	 * Renders core's Application Password creation and one-time reveal UI.
	 *
	 * @since x.x.x
	 *
	 * @param int $agent_id Newly provisioned agent user ID.
	 */
	private function render_application_passwords( int $agent_id ): void {
		$agent = get_user_by( 'id', $agent_id );
		if ( ! $agent instanceof WP_User || ! Agent_Account::is_agent( $agent ) ) {
			return;
		}

		echo '<div class="application-passwords wpai-agent-application-passwords hide-if-no-js" id="application-passwords-section">';
		echo '<h2>' . esc_html__( 'Create an Application Password', 'ai' ) . '</h2>';
		echo '<p>' . esc_html__( 'Application Passwords authenticate non-interactive clients such as MCP clients and REST API integrations. The generated password is shown once and can be revoked independently.', 'ai' ) . '</p>';

		if ( ! wp_is_application_passwords_available_for_user( $agent ) ) {
			echo '<p>' . esc_html__( 'Application Passwords are not available on this site. HTTPS is required unless this is a local environment.', 'ai' ) . '</p>';
			echo '</div>';
			return;
		}

		if ( ! current_user_can( 'create_app_password', $agent_id ) ) {
			echo '<p>' . esc_html__( 'You are not allowed to create an Application Password for this agent.', 'ai' ) . '</p>';
			echo '</div>';
			return;
		}

		if ( wp_is_site_protected_by_basic_auth( 'front' ) ) {
			echo '<p>' . esc_html__( 'This site uses Basic Authentication, which is not compatible with Application Passwords.', 'ai' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<div class="create-application-password form-wrap">';
		echo '<div class="form-field">';
		echo '<label for="new_application_password_name">' . esc_html__( 'New Application Password Name', 'ai' ) . '</label>';
		echo '<input type="text" size="30" id="new_application_password_name" name="new_application_password_name" class="input ltr" aria-required="true" aria-describedby="new_application_password_name_desc" spellcheck="false" />';
		echo '<p class="description" id="new_application_password_name_desc">' . esc_html__( 'Give this credential a name that identifies the client that will use it.', 'ai' ) . '</p>';
		echo '</div>';

		do_action( 'wp_create_application_password_form', $agent ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Extending core's Application Password form.

		echo '<button type="button" name="do_new_application_password" id="do_new_application_password" class="button button-secondary">' . esc_html__( 'Add Application Password', 'ai' ) . '</button>';
		echo '</div>';
		echo '<input type="hidden" id="user_id" value="' . esc_attr( (string) $agent_id ) . '" />';

		$had_user_id        = array_key_exists( 'user_id', $GLOBALS );
		$previous_user_id   = $GLOBALS['user_id'] ?? null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core list table reads this global.
		$GLOBALS['user_id'] = $agent_id; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core list table reads this global.

		if ( ! function_exists( '_get_list_table' ) ) {
			require_once ABSPATH . 'wp-admin/includes/list-table.php';
		}

		/** @var \WP_Application_Passwords_List_Table $application_passwords_list_table */
		$application_passwords_list_table = _get_list_table(
			'WP_Application_Passwords_List_Table',
			array( 'screen' => 'application-passwords-user' )
		);
		$application_passwords_list_table->prepare_items();

		echo '<div class="application-passwords-list-table-wrapper">';
		$application_passwords_list_table->display();
		echo '</div>';

		if ( $had_user_id ) {
			$GLOBALS['user_id'] = $previous_user_id; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Restoring the core global.
		} else {
			unset( $GLOBALS['user_id'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Restoring the core global.
		}

		$this->render_application_password_templates( $application_passwords_list_table );
		echo '</div>';
	}

	/**
	 * Renders the client-side templates consumed by core's Application Password script.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Application_Passwords_List_Table $list_table Application Password list table.
	 */
	private function render_application_password_templates( WP_Application_Passwords_List_Table $list_table ): void {
		?>
		<script type="text/html" id="tmpl-new-application-password">
			<div class="notice notice-success is-dismissible new-application-password-notice" role="alert">
				<p class="application-password-display">
					<label for="new-application-password-value">
						<?php
						echo wp_kses(
							sprintf(
								/* translators: %s: Application name. */
								__( 'Your new password for %s is:', 'ai' ),
								'<strong>{{ data.name }}</strong>'
							),
							array( 'strong' => array() )
						);
						?>
					</label>
					<input id="new-application-password-value" type="text" class="code" readonly="readonly" value="{{ data.password }}" />
					<button type="button" class="button copy-button" data-clipboard-text="{{ data.password }}"><?php echo esc_html__( 'Copy', 'ai' ); ?></button>
					<span class="success hidden" aria-hidden="true"><?php echo esc_html__( 'Copied!', 'ai' ); ?></span>
				</p>
				<p><strong><?php echo esc_html__( 'Save this password now.', 'ai' ); ?></strong> <?php echo esc_html__( 'It will not be shown again.', 'ai' ); ?></p>
				<button type="button" class="notice-dismiss"><span class="screen-reader-text"><?php echo esc_html__( 'Dismiss this notice.', 'ai' ); ?></span></button>
			</div>
		</script>
		<script type="text/html" id="tmpl-application-password-row">
			<?php $list_table->print_js_template_row(); ?>
		</script>
		<?php
	}

	/**
	 * Checks whether the current user may create accounts and assign roles.
	 *
	 * @since x.x.x
	 */
	private static function current_user_can_provision(): bool {
		return current_user_can( 'create_users' ) && current_user_can( 'promote_users' );
	}

	/**
	 * Stores non-sensitive account details for display after the redirect.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $result Result data.
	 */
	private function store_result( array $result ): void {
		set_transient( self::RESULT_TRANSIENT_PREFIX . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Retrieves and immediately deletes the stored creation result.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed>|null Result data, or null when there is none.
	 */
	private function take_result(): ?array {
		$key    = self::RESULT_TRANSIENT_PREFIX . get_current_user_id();
		$result = get_transient( $key );

		if ( ! is_array( $result ) ) {
			return null;
		}

		delete_transient( $key );

		return $result;
	}

	/**
	 * Redirects back to the admin page and stops execution.
	 *
	 * @since x.x.x
	 *
	 * @phpstan-return never
	 */
	private function redirect_back(): void {
		wp_safe_redirect( self::url() );
		exit;
	}
}
