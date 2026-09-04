<?php
/**
 * Admin page for the MCP Access screen.
 *
 * @package WordPress\AI\Experiments\MCP_Adapter
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\MCP_Adapter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the MCP Access admin page.
 *
 * @since 0.9.0
 */
class Admin_Page {
	/**
	 * The admin page slug.
	 *
	 * @since 0.9.0
	 * @var string
	 */
	public const PAGE_SLUG = 'ai-mcp-access';

	/**
	 * Initializes the admin page hooks.
	 *
	 * @since 0.9.0
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
	}

	/**
	 * Adds the Tools submenu page.
	 *
	 * @since 0.9.0
	 */
	public function add_admin_menu(): void {
		add_submenu_page(
			'tools.php',
			__( 'MCP Access', 'ai' ),
			__( 'MCP Access', 'ai' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Renders the page mount point for the React app.
	 *
	 * @since 0.9.0
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'ai' ) );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'MCP Access', 'ai' ) . '</h1>';
		echo '<div id="ai-mcp-access-root"></div>';
		echo '</div>';
	}
}
