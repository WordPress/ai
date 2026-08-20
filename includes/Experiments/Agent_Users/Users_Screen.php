<?php
/**
 * Users list table integration for agent accounts.
 *
 * @package WordPress\AI\Experiments\Agent_Users
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Agent_Users;

use WP_User_Query;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Surfaces agent accounts on the Users screen.
 *
 * Agents stay fully visible in user queries and listings. A lot of code
 * enumerates users to make decisions, and an invisible principal breaks it,
 * so hiding is limited to explicit picker UIs elsewhere. This class only
 * adds visibility: a badge column and an opt-in "AI Agents" view that
 * filters the table to agents when clicked.
 *
 * @since x.x.x
 */
final class Users_Screen {
	/**
	 * Column key for the agent badge.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const COLUMN_KEY = 'wpai_agent';

	/**
	 * Query variable enabling the agents-only view.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const VIEW_QUERY_VAR = 'wpai_agents';

	/**
	 * Registers the Users screen hooks.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		add_filter( 'manage_users_columns', array( $this, 'add_column' ) );
		add_filter( 'manage_users_custom_column', array( $this, 'render_column' ), 10, 3 );
		add_filter( 'views_users', array( $this, 'add_view' ) );
		add_filter( 'users_list_table_query_args', array( $this, 'filter_list_table' ) );
		add_action( 'admin_print_styles-users.php', array( $this, 'print_styles' ) );
	}

	/**
	 * Adds the agent column to the Users list table.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, string> $columns Column keys mapped to labels.
	 * @return array<string, string> Columns including the agent badge column.
	 */
	public function add_column( array $columns ): array {
		$columns[ self::COLUMN_KEY ] = __( 'Agent', 'ai' );

		return $columns;
	}

	/**
	 * Renders the agent badge for agent accounts.
	 *
	 * @since x.x.x
	 *
	 * @param string $output    Current column output.
	 * @param string $column    Column key.
	 * @param int    $user_id   User ID for the row.
	 * @return string Column output.
	 */
	public function render_column( string $output, string $column, int $user_id ): string {
		if ( self::COLUMN_KEY !== $column ) {
			return $output;
		}

		if ( ! Agent_Account::is_agent( $user_id ) ) {
			return '';
		}

		return '<span class="wpai-agent-badge">' . esc_html__( 'AI Agent', 'ai' ) . '</span>';
	}

	/**
	 * Returns the URL of the agents-only view on the Users screen.
	 *
	 * @since x.x.x
	 *
	 * @return string
	 */
	public static function view_url(): string {
		return add_query_arg( self::VIEW_QUERY_VAR, '1', admin_url( 'users.php' ) );
	}

	/**
	 * Adds the "AI Agents" view link to the Users screen.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, string> $views View links.
	 * @return array<string, string> Views including the agents view.
	 */
	public function add_view( array $views ): array {
		$count = $this->count_agents();
		if ( 0 === $count ) {
			return $views;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading query param to mark the active view only, no data processing.
		$is_current = isset( $_GET[ self::VIEW_QUERY_VAR ] );

		$views[ self::VIEW_QUERY_VAR ] = sprintf(
			'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
			esc_url( self::view_url() ),
			$is_current ? ' class="current" aria-current="page"' : '',
			esc_html__( 'AI Agents', 'ai' ),
			esc_html( number_format_i18n( $count ) )
		);

		return $views;
	}

	/**
	 * Filters the Users list table to agents when the view is active.
	 *
	 * This is an opt-in filter the administrator clicks, not a default
	 * exclusion: without the query variable, the table shows every account.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $args List table query arguments.
	 * @return array<string, mixed> Filtered query arguments.
	 */
	public function filter_list_table( array $args ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading query param for an opt-in list filter only, no data processing.
		if ( ! isset( $_GET[ self::VIEW_QUERY_VAR ] ) ) {
			return $args;
		}

		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bounded admin-screen query; agents are a small set.
		$args['meta_key']     = Agent_Account::META_KEY;
		$args['meta_compare'] = 'EXISTS';

		return $args;
	}

	/**
	 * Prints the badge stylesheet.
	 *
	 * @since x.x.x
	 */
	public function print_styles(): void {
		echo '<style>
			.wpai-agent-badge {
				display: inline-block;
				padding: 1px 8px;
				border-radius: 9999px;
				background: #2271b1;
				color: #fff;
				font-size: 11px;
				font-weight: 600;
				line-height: 1.8;
			}
			.fixed .column-wpai_agent { width: 90px; }
		</style>';
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
