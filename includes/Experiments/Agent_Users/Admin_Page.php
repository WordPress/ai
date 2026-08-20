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
use WP_User;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the Users → AI Agents admin page.
 *
 * The page lists provisioned agents and hosts the creation form. Creation
 * shows the agent's Application Password exactly once, using the same
 * one-time reveal model as core's Application Passwords screen. Everything
 * else (editing, revoking passwords, deleting with content reassignment)
 * intentionally reuses the core user screens.
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
	 * Form action name for the `admin-post.php` handler.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const FORM_ACTION = 'wpai_create_agent_user';

	/**
	 * Prefix of the transient carrying the one-time creation result.
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
	 * Prints the small stylesheet used by the page.
	 *
	 * @since x.x.x
	 */
	public static function print_styles(): void {
		echo '<style>
			.wpai-agent-password-reveal code { font-size: 14px; padding: 6px 8px; display: inline-block; user-select: all; }
			.wpai-agent-create-form { max-width: 600px; }
		</style>';
	}

	/**
	 * Handles the creation form submission.
	 *
	 * @since x.x.x
	 */
	public function handle_create(): void {
		if ( ! current_user_can( 'create_users' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to create users.', 'ai' ) );
		}

		check_admin_referer( self::FORM_ACTION );

		$name = isset( $_POST['wpai_agent_name'] ) ? sanitize_text_field( wp_unslash( $_POST['wpai_agent_name'] ) ) : '';
		$role = isset( $_POST['wpai_agent_role'] ) ? sanitize_key( wp_unslash( $_POST['wpai_agent_role'] ) ) : '';

		if ( ! array_key_exists( $role, self::get_assignable_roles() ) ) {
			$this->store_result(
				array(
					'type'    => 'error',
					'message' => __( 'The selected role cannot be assigned.', 'ai' ),
				)
			);
			$this->redirect_back();
		}

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
				'type'     => 'success',
				'user_id'  => $result['user']->ID,
				'login'    => $result['user']->user_login,
				'password' => $result['password'],
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
		if ( ! current_user_can( 'create_users' ) ) {
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
	 * Renders the one-time result notice from the last form submission.
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

		echo '<div class="notice notice-success wpai-agent-password-reveal">';
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
		echo '<p>' . esc_html__( 'Application Password for this agent:', 'ai' ) . '</p>';
		echo '<p><code>' . esc_html( (string) $result['password'] ) . '</code></p>';
		echo '<p><strong>' . esc_html__( 'Copy it now. It will not be shown again.', 'ai' ) . '</strong> ' . esc_html__( 'Use the agent login name and this password for Basic Authentication, for example when connecting an MCP client.', 'ai' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Renders the creation form.
	 *
	 * @since x.x.x
	 */
	private function render_create_form(): void {
		$roles = self::get_assignable_roles();

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
	 * Renders the table of existing agents.
	 *
	 * @since x.x.x
	 */
	private function render_agents_table(): void {
		$agents = get_users(
			array(
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bounded admin-screen query; agents are a small set.
				'meta_key'     => Agent_Account::META_KEY,
				'meta_compare' => 'EXISTS',
				'orderby'      => 'registered',
				'order'        => 'DESC',
				'number'       => 100,
			)
		);

		echo '<h2>' . esc_html__( 'Existing Agents', 'ai' ) . '</h2>';

		if ( array() === $agents ) {
			echo '<p>' . esc_html__( 'No agents have been created yet.', 'ai' ) . '</p>';
			return;
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
		echo '<p class="description">' . esc_html__( 'Use the profile screen to revoke Application Passwords, and the Users screen to delete an agent. Deleting asks what to do with the agent’s content, so nothing is lost by accident.', 'ai' ) . '</p>';
	}

	/**
	 * Returns the roles the current user may assign to a new agent.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{name: string}> Role slugs mapped to role details.
	 */
	private static function get_assignable_roles(): array {
		if ( ! function_exists( 'get_editable_roles' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		$roles = array();
		foreach ( get_editable_roles() as $role_slug => $role_details ) {
			if ( ! is_string( $role_slug ) || ! isset( $role_details['name'] ) || ! is_string( $role_details['name'] ) ) {
				continue;
			}

			$roles[ $role_slug ] = array( 'name' => $role_details['name'] );
		}

		return $roles;
	}

	/**
	 * Stores the creation result for a one-time reveal after the redirect.
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
