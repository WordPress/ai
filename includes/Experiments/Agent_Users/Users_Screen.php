<?php
/**
 * Users list table integration for agent accounts.
 *
 * @package WordPress\AI\Experiments\Agent_Users
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Agent_Users;

use WP_User;
use WP_User_Query;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Surfaces agent accounts on the Users screen.
 *
 * Agents remain in normal user queries because they own content and participate
 * in capability checks. This class distinguishes them without changing those
 * queries: it adds a role marker, an opt-in filter, and relevant row actions.
 *
 * @since x.x.x
 */
final class Users_Screen {
	/**
	 * Query variable carrying the account type filter.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const FILTER_QUERY_VAR = 'wpai_account_type';

	/**
	 * Registers the Users screen hooks.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		add_filter( 'get_role_list', array( $this, 'mark_agent_roles' ), 10, 2 );
		add_action( 'manage_users_extra_tablenav', array( $this, 'render_filter' ) );
		add_filter( 'users_list_table_query_args', array( $this, 'filter_list_table' ) );
		add_filter( 'user_row_actions', array( $this, 'filter_row_actions' ), 10, 2 );
		// The network Users list uses its own row actions filter and has no
		// role column or extra tablenav, so this is its only integration point.
		add_filter( 'ms_user_row_actions', array( $this, 'filter_row_actions' ), 10, 2 );
	}

	/**
	 * Marks agent accounts in the Role column.
	 *
	 * Showing account type next to the role communicates both identity and the
	 * capability ceiling without inventing agent-specific roles.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, string> $role_list   Translated role names keyed by role.
	 * @param \WP_User              $user_object The user for the row.
	 * @return array<string, string> Role names, marked for agent accounts.
	 */
	public function mark_agent_roles( array $role_list, WP_User $user_object ): array {
		if ( ! Agent_Account::is_agent( $user_object ) ) {
			return $role_list;
		}

		foreach ( $role_list as $role => $name ) {
			/* translators: %s: Role name. */
			$role_list[ $role ] = sprintf( __( '%s (agent)', 'ai' ), $name );
		}

		return $role_list;
	}

	/**
	 * Renders the agent account filter after the role changer.
	 *
	 * Account type is independent of role and therefore gets its own filter. It
	 * renders only once because duplicate named selects overwrite each other.
	 *
	 * @since x.x.x
	 *
	 * @param string $which Table navigation position, `top` or `bottom`.
	 */
	public function render_filter( string $which ): void {
		if ( 'top' !== $which || 0 === $this->count_agents() ) {
			return;
		}

		$select_id = self::FILTER_QUERY_VAR;
		$current   = self::current_filter();
		$options   = array(
			''      => __( 'All users', 'ai' ),
			'agent' => __( 'Agents only', 'ai' ),
			'human' => __( 'Exclude agents', 'ai' ),
		);

		echo '<div class="alignleft actions">';
		echo '<label class="screen-reader-text" for="' . esc_attr( $select_id ) . '">' . esc_html__( 'Filter by agent accounts', 'ai' ) . '</label>';
		echo '<select name="' . esc_attr( self::FILTER_QUERY_VAR ) . '" id="' . esc_attr( $select_id ) . '">';
		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';

		submit_button( __( 'Filter', 'ai' ), '', 'wpai_filter', false );
		echo '</div>';
	}

	/**
	 * Applies the account type filter to the Users list table query.
	 *
	 * This is an opt-in filter the administrator selects, not a default
	 * exclusion: without the query variable, the table shows every account.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $args List table query arguments.
	 * @return array<string, mixed> Filtered query arguments.
	 */
	public function filter_list_table( array $args ): array {
		$filter = self::current_filter();
		if ( '' === $filter ) {
			return $args;
		}

		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bounded admin-screen query; agents are a small set.
		$args['meta_key']     = Agent_Account::META_KEY;
		$args['meta_compare'] = 'agent' === $filter ? 'EXISTS' : 'NOT EXISTS';

		return $args;
	}

	/**
	 * Returns the selected account type filter.
	 *
	 * @since x.x.x
	 *
	 * @return string `agent`, `human`, or an empty string for no filter.
	 */
	private static function current_filter(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading query param for an opt-in list filter only, no data processing.
		$value = isset( $_GET[ self::FILTER_QUERY_VAR ] ) ? sanitize_key( wp_unslash( $_GET[ self::FILTER_QUERY_VAR ] ) ) : '';

		return in_array( $value, array( 'agent', 'human' ), true ) ? $value : '';
	}

	/**
	 * Adapts the row actions for agent accounts.
	 *
	 * A password reset link makes no sense for an account that cannot log in
	 * with a password, so it is replaced by a shortcut to the Application
	 * Passwords section of the agent's profile.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, string> $actions     Row action links.
	 * @param \WP_User              $user_object The user for the row.
	 * @return array<string, string> Filtered row actions.
	 */
	public function filter_row_actions( array $actions, WP_User $user_object ): array {
		if ( ! Agent_Account::is_agent( $user_object ) ) {
			return $actions;
		}

		unset( $actions['resetpassword'] );

		if ( is_network_admin() ) {
			$site_id = Agent_Account::get_site_id( $user_object );
			if ( $site_id > 0 && current_user_can( 'edit_user', $user_object->ID ) ) {
				$actions['wpai_manage_agent'] = sprintf(
					'<a href="%1$s">%2$s</a>',
					esc_url( get_admin_url( $site_id, 'user-edit.php?user_id=' . $user_object->ID ) . '#application-passwords-section' ),
					esc_html__( 'Manage Agent on Assigned Site', 'ai' )
				);
			}

			return $actions;
		}

		if ( current_user_can( 'edit_user', $user_object->ID ) ) {
			$actions['wpai_application_passwords'] = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( Profile_Screen::url( $user_object->ID ) ),
				esc_html__( 'Application Passwords', 'ai' )
			);
		}

		return $actions;
	}

	/**
	 * Counts agent accounts.
	 *
	 * @since x.x.x
	 *
	 * @return int Number of agent accounts.
	 */
	private function count_agents(): int {
		$query = new WP_User_Query(
			array(
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bounded admin-screen query; agents are a small set.
				'meta_key'     => Agent_Account::META_KEY,
				'meta_compare' => 'EXISTS',
				'fields'       => 'ID',
				'number'       => 1,
				'count_total'  => true,
			)
		);

		return $query->get_total();
	}
}
