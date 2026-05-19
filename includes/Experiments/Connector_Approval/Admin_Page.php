<?php
/**
 * Admin page hosting the Connector Approval UI.
 *
 * @package WordPress\AI\Experiments\Connector_Approval
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Connector_Approval;

use WordPress\AI\Asset_Loader;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the Connector Approval admin page.
 *
 * @since 1.0.0
 */
final class Admin_Page {
	/**
	 * Menu slug used by the admin page.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const PAGE_SLUG = 'ai-connector-approval';

	/**
	 * Parent menu used to anchor this page.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	private const PARENT_SLUG = 'tools.php';

	/**
	 * Expected `load-*` hook suffix for this page.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	private const HOOK_SUFFIX = 'tools_page_ai-connector-approval';

	/**
	 * Registers the admin menu entry and asset enqueueing.
	 *
	 * @since 1.0.0
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_submenu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Returns the absolute admin URL for this page.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function url(): string {
		return admin_url( 'tools.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * Adds the submenu under Settings.
	 *
	 * @since 1.0.0
	 */
	public function add_submenu(): void {
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Connector Approvals', 'ai' ),
			__( 'Connector Approvals', 'ai' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Enqueues the admin page's script and styles.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( self::HOOK_SUFFIX !== $hook_suffix ) {
			return;
		}

		Asset_Loader::enqueue_script( 'connector_approval', 'experiments/connector-approval' );
		Asset_Loader::enqueue_style( 'connector_approval', 'experiments/connector-approval' );
		Asset_Loader::localize_script(
			'connector_approval',
			'ConnectorApproval',
			array(
				'restUrl' => esc_url_raw( rest_url( 'ai/v1/connector-approvals' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * Renders the page container.
	 *
	 * The actual UI is rendered by the React app mounted into the container.
	 *
	 * @since 1.0.0
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'ai' ) );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Connector Approvals', 'ai' ) . '</h1>';
		echo '<p>' . esc_html__( 'Control which plugins and themes are allowed to use each AI connector on this site. Prompts from unapproved callers are prevented and listed below for review.', 'ai' ) . '</p>';
		echo '<div id="ai-connector-approval-root"></div>';
		echo '</div>';
	}
}
