<?php
/**
 * Admin page for MCP experiment.
 *
 * @package WordPress\AI\Experiments\MCP
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\MCP;

use WordPress\AI\Asset_Loader;

use function __;
use function add_action;
use function add_options_page;
use function admin_url;
use function current_user_can;
use function esc_html_e;
use function esc_url_raw;
use function rest_url;
use function wp_create_nonce;

/**
 * Renders the MCP admin interface.
 *
 * @since 0.1.0
 */
class Admin_Page {

	private const PAGE_SLUG = 'ai-mcp';
	private const MENU_ICON = 'data:image/svg+xml;base64,PHN2ZyBmaWxsPSJjdXJyZW50Q29sb3IiIGZpbGwtcnVsZT0iZXZlbm9kZCIgaGVpZ2h0PSIxZW0iIHN0eWxlPSJmbGV4Om5vbmU7bGluZS1oZWlnaHQ6MSIgdmlld0JveD0iMCAwIDI0IDI0IiB3aWR0aD0iMWVtIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjx0aXRsZT5Nb2RlbENvbnRleHRQcm90b2NvbDwvdGl0bGU+PHBhdGggZD0iTTE1LjY4OCAyLjM0M2EyLjU4OCAyLjU4OCAwIDAwLTMuNjEgMGwtOS42MjYgOS40NGEuODYzLjg2MyAwIDAxLTEuMjAzIDAgLjgyMy44MjMgMCAwMTAtMS4xOGw5LjYyNi05LjQ0YTQuMzEzIDQuMzEzIDAgMDE2LjAxNiAwIDQuMTE2IDQuMTE2IDAgMDExLjIwNCAzLjU0IDQuMyA0LjMgMCAwMTMuNjA5IDEuMThsLjA1LjA1YTQuMTE1IDQuMTE1IDAgMDEwIDUuOWwtOC43MDYgOC41MzdhLjI3NC4yNzQgMCAwMDAgLjM5M2wxLjc4OCAxLjc1NGEuODIzLjgyMyAwIDAxMCAxLjE4Ljg2My44NjMgMCAwMS0xLjIwMyAwbC0xLjc4OC0xLjc1M2ExLjkyIDEuOTIgMCAwMTAtMi43NTRsOC43MDYtOC41MzhhMi40NyAyLjQ3IDAgMDAwLTMuNTRsLS4wNS0uMDQ5YTIuNTg4IDIuNTg4IDAgMDAtMy42MDctLjAwM2wtNy4xNzIgNy4wMzQtLjAwMi4wMDItLjA5OC4wOTdhLjg2My44NjMgMCAwMS0xLjIwNCAwIC44MjMuODIzIDAgMDEwLTEuMThsNy4yNzMtNy4xMzNhMi40NyAyLjQ3IDAgMDAtLjAwMy0zLjUzN3oiPjwvcGF0aD48cGF0aCBkPSJNMTQuNDg1IDQuNzAzYS44MjMuODIzIDAgMDAwLTEuMTguODYzLjg2MyAwIDAwLTEuMjA0IDBsLTcuMTE5IDYuOTgyYTQuMTE1IDQuMTE1IDAgMDAwIDUuOSA0LjMxNCA0LjMxNCAwIDAwNi4wMTYgMGw3LjEyLTYuOTgyYS44MjMuODIzIDAgMDAwLTEuMTguODYzLjg2MyAwIDAwLTEuMjA0IDBsLTcuMTE5IDYuOTgyYTIuNTg4IDIuNTg4IDAgMDEtMy42MSAwIDIuNDcgMi40NyAwIDAxMC0zLjU0bDcuMTItNi45ODJ6Ij48L3BhdGg+PC9zdmc+';

	public function __construct( private Manager $manager ) {}

	/**
	 * Hook admin actions.
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	/**
	 * Register the options page.
	 */
	public function register_menu(): void {
		$page_hook = add_menu_page(
			__( 'MCP', 'ai' ),
			__( 'MCP', 'ai' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			self::MENU_ICON,
			58
		);

		if ( $page_hook ) {
			add_action( "load-{$page_hook}", array( $this, 'on_load' ) );
		}
	}

	/**
	 * Enqueue assets.
	 */
	public function on_load(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Render.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap ai-mcp-server">
			<div class="ai-admin-header ai-mcp-server__header">
				<div class="ai-admin-header__inner">
					<div class="ai-admin-header__left">
						<span class="ai-admin-header__icon">
							<svg width="1em" height="1em" viewBox="0 0 24 24" fill="currentColor" fill-rule="evenodd" xmlns="http://www.w3.org/2000/svg">
								<path d="M15.688 2.343a2.588 2.588 0 00-3.61 0l-9.626 9.44a.863.863 0 01-1.203 0 .823.823 0 010-1.18l9.626-9.44a4.313 4.313 0 016.016 0 4.116 4.116 0 011.204 3.54 4.3 4.3 0 013.609 1.18l.05.05a4.115 4.115 0 010 5.9l-8.706 8.537a.274.274 0 000 .393l1.788 1.754a.823.823 0 010 1.18.863.863 0 01-1.203 0l-1.788-1.753a1.92 1.92 0 010-2.754l8.706-8.538a2.47 2.47 0 000-3.54l-.05-.049a2.588 2.588 0 00-3.607-.003l-7.172 7.034-.002.002-.098.097a.863.863 0 01-1.204 0 .823.823 0 010-1.18l7.273-7.133a2.47 2.47 0 00-.003-3.537z" />
								<path d="M14.485 4.703a.823.823 0 000-1.18.863.863 0 00-1.204 0l-7.119 6.982a4.115 4.115 0 000 5.9 4.314 4.314 0 006.016 0l7.12-6.982a.823.823 0 000-1.18.863.863 0 00-1.204 0l-7.119 6.982a2.588 2.588 0 01-3.61 0 2.47 2.47 0 010-3.54l7.12-6.982z" />
							</svg>
						</span>
						<div class="ai-admin-header__title">
							<h1><?php esc_html_e( 'MCP', 'ai' ); ?></h1>
						</div>
						<!-- React mounts status badge here -->
						<div id="ai-mcp-header-status"></div>
					</div>
					<!-- React mounts global toggle here -->
					<div id="ai-mcp-header-toggle" class="ai-admin-header__right"></div>
				</div>
			</div>
			<div id="ai-mcp-server-root"></div>
		</div>
		<?php
	}

	/**
	 * Enqueue JS/CSS bundle.
	 */
	public function enqueue_assets(): void {
		Asset_Loader::enqueue_script( 'mcp_server', 'admin/mcp-server' );
		Asset_Loader::enqueue_style( 'mcp_server', 'admin/style-mcp-server' );

		Asset_Loader::localize_script(
			'mcp_server',
			'McpServerSettings',
			array(
				'rest' => array(
					'nonce'  => wp_create_nonce( 'wp_rest' ),
					'root'   => esc_url_raw( rest_url() ),
					'routes' => array(
						'overview'  => 'ai/v1/mcp',
						'enabled'   => 'ai/v1/mcp/enabled',
						'server'    => 'ai/v1/mcp/server',
						'addServer' => 'ai/v1/mcp/server/add',
						'tools'     => 'ai/v1/mcp/tools',
						'test'      => 'ai/v1/mcp/test',
					),
				),
				'profileUrl' => esc_url_raw( admin_url( 'profile.php#application-passwords-section' ) ),
			)
		);
	}
}
