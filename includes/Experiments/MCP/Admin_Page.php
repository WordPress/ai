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
			<h1><?php esc_html_e( 'MCP', 'ai' ); ?></h1>
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
