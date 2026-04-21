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
	public const PAGE_SLUG = 'ai-connector-approval';

	/**
	 * Parent menu used to anchor this page.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const PARENT_SLUG = 'options-general.php';

	/**
	 * Expected `load-*` hook suffix for this page.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const HOOK_SUFFIX = 'settings_page_ai-connector-approval';

	/**
	 * Registers the admin menu entry and asset enqueueing.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_submenu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Returns the absolute admin URL for this page.
	 *
	 * @since x.x.x
	 *
	 * @return string
	 */
	public static function url(): string {
		return admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * Adds the submenu under Settings.
	 *
	 * @since x.x.x
	 */
	public function add_submenu(): void {
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'AI Connector Approvals', 'ai' ),
			__( 'AI Connector Approvals', 'ai' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render' ),
			2
		);
	}

	/**
	 * Enqueues the admin page's script and styles.
	 *
	 * @since x.x.x
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
	 * @since x.x.x
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'ai' ) );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'AI Connector Approvals', 'ai' ) . '</h1>';
		echo '<p>' . esc_html__( 'Control which plugins and themes are allowed to use each AI connector on this site. Prompts from unapproved callers are prevented and listed below for review.', 'ai' ) . '</p>';
		echo '<div id="ai-connector-approval-root"></div>';
		echo '</div>';
	}
}
