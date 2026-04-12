<?php
/**
 * AI Observability and Request Logging.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Admin;

/**
 * Class Observability_Logger
 *
 * @since 0.7.0
 */
class Observability_Logger {

	public const TABLE_NAME = 'wp_ai_experiments_logs';

	/**
	 * Initialize the logger and register activation hooks.
	 *
	 * @since 0.7.0
	 */
	public static function init(): void {
		add_action( 'admin_init', array( self::class, 'create_table' ) );
		add_action( 'admin_menu', array( self::class, 'add_observability_page' ) );
	}

	/**
	 * Create the custom database table for AI logs.
	 *
	 * @since 0.7.0
	 */
	public static function create_table(): void {
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
			return;
		}

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
			provider varchar(50) NOT NULL,
			prompt text NOT NULL,
			response text NOT NULL,
			status varchar(20) NOT NULL,
			PRIMARY KEY  (id)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Log an AI request to the database.
	 *
	 * @since 0.7.0
	 *
	 * @param string $provider The AI provider.
	 * @param string $prompt   The payload sent to the AI.
	 * @param string $response The raw response from the AI.
	 * @param string $status   'success' or 'error'.
	 */
	public static function log( string $provider, string $prompt, string $response, string $status = 'success' ): void {
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table_name,
			array(
				'time'     => current_time( 'mysql' ),
				'provider' => sanitize_text_field( $provider ),
				'prompt'   => $prompt,
				'response' => $response,
				'status'   => sanitize_text_field( $status ),
			)
		);
	}

	/**
	 * Add the observability page to the Settings menu.
	 *
	 * @since 0.7.0
	 */
	public static function add_observability_page(): void {
		add_options_page(
			__( 'AI Observability', 'ai' ),
			__( 'AI Observability', 'ai' ),
			'manage_options',
			'wp-ai-observability',
			array( self::class, 'render_page' )
		);
	}

	/**
	 * Render the admin page.
	 *
	 * @since 0.7.0
	 */
	public static function render_page(): void {
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$logs = $wpdb->get_results( "SELECT * FROM {$table_name} ORDER BY time DESC LIMIT 50" );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'AI Observability & Logs', 'ai' ) . '</h1>';

		if ( empty( $logs ) ) {
			echo '<p>' . esc_html__( 'No logs recorded yet.', 'ai' ) . '</p></div>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Time', 'ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Provider', 'ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Prompt Snippet', 'ai' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $logs as $log ) {
			$prompt_snippet = mb_substr( esc_html( $log->prompt ), 0, 80 ) . '...';
			$status_color   = 'success' === $log->status ? 'green' : 'red';

			echo '<tr>';
			echo '<td>' . esc_html( $log->time ) . '</td>';
			echo '<td><strong>' . esc_html( $log->provider ) . '</strong></td>';
			echo '<td style="color: ' . esc_attr( $status_color ) . ';">' . esc_html( $log->status ) . '</td>';
			echo '<td><code>' . esc_html( $prompt_snippet ) . '</code></td>';
			echo '</tr>';
		}

		echo '</tbody></table></div>';
	}
}
