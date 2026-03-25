<?php
/**
 * Admin page for viewing AI request logs.
 *
 * @package WordPress\AI\Logging
 */

declare( strict_types=1 );

namespace WordPress\AI\Logging;

use WordPress\AI\Admin\Provider_Metadata_Registry;
use WordPress\AI\Asset_Loader;

/**
 * Renders the AI Request Logs screen under Tools.
 *
 * @since 0.1.0
 */
class AI_Request_Log_Page {

	/**
	 * Menu slug for the settings screen.
	 */
	private const PAGE_SLUG = 'ai-request-logs';

	/**
	 * Log manager instance.
	 */
	private AI_Request_Log_Manager $manager;

	/**
	 * Constructor.
	 *
	 * @param \WordPress\AI\Logging\AI_Request_Log_Manager $manager Manager dependency.
	 */
	public function __construct( AI_Request_Log_Manager $manager ) {
		$this->manager = $manager;
	}

	/**
	 * Registers the Tools page.
	 */
	public function register_menu(): void {
		$page_hook = add_submenu_page(
			'tools.php',
			__( 'AI Request Logs', 'ai' ),
			__( 'AI Request Logs', 'ai' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);

		if ( ! $page_hook ) {
			return;
		}

		add_action( "load-{$page_hook}", array( $this, 'on_load' ) );
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
		Asset_Loader::enqueue_script( 'ai_request_logs', 'admin/ai-request-logs' );
		Asset_Loader::enqueue_style( 'ai_request_logs', 'admin/style-ai-request-logs' );

		Asset_Loader::localize_script(
			'ai_request_logs',
			'AiRequestLogsSettings',
			array(
				'rest'             => array(
					'nonce'  => wp_create_nonce( 'wp_rest' ),
					'root'   => esc_url_raw( rest_url() ),
					'routes' => array(
						'logs'    => 'ai/v1/logs',
						'summary' => 'ai/v1/logs/summary',
						'filters' => 'ai/v1/logs/filters',
					),
				),
				'initialState'     => array(
					'enabled'       => $this->manager->is_logging_enabled(),
					'retentionDays' => $this->manager->get_retention_days(),
					'summary'       => $this->manager->get_summary( 'day' ),
					'filters'       => $this->manager->get_filter_options(),
				),
				'providerMetadata' => Provider_Metadata_Registry::get_metadata(),
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
		<div class="wrap ai-request-logs">
			<div class="ai-admin-header">
				<div class="ai-admin-header__inner">
					<div class="ai-admin-header__left">
						<span class="ai-admin-header__icon"><?php echo wp_kses_post( $this->get_plugin_icon_svg() ); ?></span>
						<div class="ai-admin-header__title">
							<h1><?php esc_html_e( 'AI Request Logs', 'ai' ); ?></h1>
						</div>
					</div>
					<div id="ai-request-logs-header-period" class="ai-admin-header__right"></div>
				</div>
			</div>
			<div id="ai-request-logs-root"></div>
		</div>
		<?php
	}

	/**
	 * Returns the colored plugin icon SVG for the screen header.
	 *
	 * @since 0.6.0
	 *
	 * @return string SVG markup or an empty string.
	 */
	private function get_plugin_icon_svg(): string {
		$icon_path = WPAI_PLUGIN_DIR . '.wordpress-org/icon.svg';

		if ( ! file_exists( $icon_path ) ) {
			return '';
		}

		$icon = file_get_contents( $icon_path ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Local plugin asset.

		return is_string( $icon ) ? $icon : '';
	}
}
