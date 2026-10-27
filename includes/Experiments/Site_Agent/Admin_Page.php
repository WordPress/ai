<?php
/**
 * Admin page hosting the Site Agent app shell.
 *
 * @package WordPress\AI\Experiments\Site_Agent
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Site_Agent;

use WordPress\AI\Asset_Loader;
use WordPress\AI\Experiments\Site_Agent\REST\Controller;

use function WordPress\AI\has_valid_ai_credentials;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the Site Agent admin screen under Tools.
 *
 * The screen is capability gated on every request: when the menu entry is
 * built, when WordPress dispatches the page, and again in the render callback.
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
	public const PAGE_SLUG = 'ai-site-agent';

	/**
	 * Parent menu used to anchor this page.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const PARENT_SLUG = 'tools.php';

	/**
	 * Script and style handle, without the Asset_Loader prefix.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const ASSET_HANDLE = 'site_agent';

	/**
	 * Built asset path, relative to the build directory and without extension.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const ASSET_PATH = 'experiments/site-agent';

	/**
	 * Registers the admin menu entry.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_submenu' ) );
	}

	/**
	 * Returns the absolute admin URL for this page.
	 *
	 * @since x.x.x
	 *
	 * @return string The admin URL for the Site Agent screen.
	 */
	public static function url(): string {
		return admin_url( self::PARENT_SLUG . '?page=' . self::PAGE_SLUG );
	}

	/**
	 * Adds the submenu entry under Tools.
	 *
	 * @since x.x.x
	 */
	public function add_submenu(): void {
		$capability = Site_Agent::get_screen_capability();

		$page_hook = add_submenu_page(
			self::PARENT_SLUG,
			__( 'Site Agent', 'ai' ),
			__( 'Site Agent', 'ai' ),
			$capability, // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Filtered by Site_Agent::get_screen_capability().
			self::PAGE_SLUG,
			array( $this, 'render' )
		);

		if ( ! $page_hook ) {
			return;
		}

		add_action( "load-{$page_hook}", array( $this, 'on_load' ) );
	}

	/**
	 * Hooks screen-specific behaviour once WordPress dispatches this page.
	 *
	 * @since x.x.x
	 */
	public function on_load(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueues the Site Agent bundle and passes its localized data.
	 *
	 * @since x.x.x
	 */
	public function enqueue_assets(): void {
		$capability = Site_Agent::get_screen_capability();

		// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Filtered by Site_Agent::get_screen_capability().
		if ( ! current_user_can( $capability ) ) {
			return;
		}

		Asset_Loader::enqueue_script( self::ASSET_HANDLE, self::ASSET_PATH );
		Asset_Loader::enqueue_style( self::ASSET_HANDLE, self::ASSET_PATH );

		Asset_Loader::localize_script(
			self::ASSET_HANDLE,
			'SiteAgent',
			array(
				'rest'             => array(
					'nonce'  => wp_create_nonce( 'wp_rest' ),
					'root'   => esc_url_raw( rest_url() ),
					'routes' => array(
						'turn'    => Controller::TURN_ROUTE,
						'approve' => Controller::APPROVE_ROUTE,
						'decline' => Controller::DECLINE_ROUTE,
					),
				),
				'availability'     => $this->get_availability(),
				'canWrite'         => Site_Agent::writes_allowed(),
				'settingsUrl'      => admin_url( 'options-general.php?page=ai-wp-admin' ),
				'maxMessageLength' => Site_Agent::MAX_MESSAGE_LENGTH,
			)
		);
	}

	/**
	 * Outputs the root DOM node the React application mounts into.
	 *
	 * @since x.x.x
	 */
	public function render(): void {
		$capability = Site_Agent::get_screen_capability();

		// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Filtered by Site_Agent::get_screen_capability().
		if ( ! current_user_can( $capability ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'ai' ) );
		}

		echo '<div class="wrap ai-site-agent">';
		echo '<h1>' . esc_html__( 'Site Agent', 'ai' ) . '</h1>';
		echo '<p class="ai-site-agent__intro">' . esc_html__( 'Ask about your site or request a change. The assistant proposes actions, and you review and approve the exact values before anything happens.', 'ai' ) . '</p>';
		echo '<div id="ai-site-agent-root"></div>';
		echo '</div>';
	}

	/**
	 * Describes whether the Site Agent can operate, and why not when it cannot.
	 *
	 * @since x.x.x
	 *
	 * @return array{status: string} The availability status.
	 */
	private function get_availability(): array {
		if ( ! has_valid_ai_credentials() ) {
			return array( 'status' => 'no-credentials' );
		}

		if ( ! Function_Calling_Support::is_available() ) {
			return array( 'status' => 'no-function-calling' );
		}

		return array( 'status' => 'ready' );
	}
}
