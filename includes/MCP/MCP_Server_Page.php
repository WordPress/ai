<?php
/**
 * Admin page that surfaces MCP server status and configuration tools.
 *
 * @package WordPress\AI\MCP
 */

declare( strict_types=1 );

namespace WordPress\AI\MCP;

use WordPress\AI\Asset_Loader;

use function add_action;
use function add_options_page;
use function admin_url;
use function current_user_can;
use function esc_html__;
use function esc_html_e;
use function esc_url_raw;
use function rest_url;
use function wp_create_nonce;

/**
 * Renders the MCP Server screen under Settings → MCP Server.
 *
 * @since 0.1.0
 */
class MCP_Server_Page {
	/**
	 * Menu slug for the settings screen.
	 */
	private const PAGE_SLUG = 'ai-mcp-server';

	/**
	 * Shared MCP server manager.
	 */
	private MCP_Server_Manager $manager;

	/**
	 * Constructor.
	 *
	 * @param MCP_Server_Manager $manager Manager dependency.
	 */
	public function __construct( MCP_Server_Manager $manager ) {
		$this->manager = $manager;
	}

	/**
	 * Bootstraps hooks.
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	/**
	 * Registers the options page under Settings.
	 */
	public function register_menu(): void {
		$page_hook = add_options_page(
			esc_html__( 'MCP Server', 'ai' ),
			esc_html__( 'MCP Server', 'ai' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);

		if ( $page_hook ) {
			add_action( "load-{$page_hook}", array( $this, 'on_load' ) );
		}
	}

	/**
	 * Ensures assets are loaded when the page is visited.
	 */
	public function on_load(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueues the React bundle and passes localized data.
	 */
	public function enqueue_assets(): void {
		Asset_Loader::enqueue_script( 'mcp_server', 'admin/mcp-server' );
		Asset_Loader::enqueue_style( 'mcp_server', 'admin/style-mcp-server' );

		Asset_Loader::localize_script(
			'mcp_server',
			'McpServerSettings',
			array(
				'rest' => array(
					'nonce' => wp_create_nonce( 'wp_rest' ),
					'root'  => esc_url_raw( rest_url() ),
					'routes'=> array(
						'base'  => 'ai/v1/mcp-server',
						'tools' => 'ai/v1/mcp-server/tools',
						'test'  => 'ai/v1/mcp-server/test',
					),
				),
				'profileUrl'   => esc_url_raw( admin_url( 'profile.php#application-passwords-section' ) ),
				'initialState' => array(
					'enabled' => $this->manager->is_server_enabled(),
					'server'  => $this->manager->get_server_details(),
				),
			)
		);
	}

	/**
	 * Outputs the root DOM node for the React app.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap ai-mcp-server">
			<h1><?php esc_html_e( 'MCP Server', 'ai' ); ?></h1>
			<div id="ai-mcp-server-root"></div>
		</div>
		<?php
	}
}
