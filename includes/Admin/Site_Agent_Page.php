<?php
/**
 * Site Agent Admin Interface.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Admin;

use WordPress\AI\Abilities\Site_Agent\Site_Agent;

/**
 * Class Site_Agent_Page
 *
 * @since 0.7.0
 */
class Site_Agent_Page {

	/**
	 * Initialize the admin page.
	 *
	 * @since 0.7.0
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'add_agent_page' ) );
	}

	/**
	 * Add the agent page to the Tools menu.
	 *
	 * @since 0.7.0
	 */
	public static function add_agent_page(): void {
		add_management_page(
			__( 'AI Site Agent', 'ai' ),
			__( 'AI Site Agent', 'ai' ),
			'manage_options',
			'wp-ai-site-agent',
			array( self::class, 'render_page' )
		);
	}

	/**
	 * Render the admin page.
	 *
	 * @since 0.7.0
	 */
	public static function render_page(): void {
		$agent_response = null;

		if ( isset( $_POST['wp_ai_agent_command'] ) && check_admin_referer( 'run_ai_agent', 'wp_ai_agent_nonce' ) ) {
			$command = sanitize_text_field( wp_unslash( $_POST['wp_ai_agent_command'] ) );

			// Execute through wp_execute_ability or manually initializing the ability. We'll execute directly for the page logic.
			$agent          = new Site_Agent(
				'ai/site_agent',
				array(
					'label'       => __( 'Site Agent', 'ai' ),
					'description' => __( 'Site Agent', 'ai' ),
				)
			);
			$agent_response = $agent->execute( array( 'command' => $command ) );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'AI Site Agent', 'ai' ) . '</h1>';

		if ( is_wp_error( $agent_response ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $agent_response->get_error_message() ) . '</p></div>';
		} elseif ( $agent_response ) {
			$notice_class = isset( $agent_response['action_found'] ) && $agent_response['action_found'] ? 'notice-success' : 'notice-warning';
			$message      = $agent_response['message'] ?? __( 'Action processed.', 'ai' );
			echo '<div class="notice ' . esc_attr( $notice_class ) . '"><p>' . esc_html( $message ) . '</p></div>';
		}

		echo '<form method="post" action="">';
		wp_nonce_field( 'run_ai_agent', 'wp_ai_agent_nonce' );
		echo '<table class="form-table"><tbody><tr>';
		echo '<th scope="row"><label for="wp_ai_agent_command">' . esc_html__( 'Command', 'ai' ) . '</label></th>';
		echo '<td>';
		echo '<input type="text" id="wp_ai_agent_command" name="wp_ai_agent_command" class="regular-text ltr" style="width: 100%; max-width: 600px;" placeholder="' . esc_attr__( 'e.g., Update my site description to A blog about WordPress.', 'ai' ) . '" required />';
		echo '</td></tr></tbody></table>';

		submit_button( __( 'Run Command', 'ai' ) );
		echo '</form></div>';
	}
}
