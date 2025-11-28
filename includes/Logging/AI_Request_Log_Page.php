<?php
/**
 * Admin page for viewing AI request logs.
 *
 * @package WordPress\AI\Logging
 */

declare( strict_types=1 );

namespace WordPress\AI\Logging;

use WordPress\AI\Asset_Loader;

/**
 * Renders the AI Request Logs screen under Settings.
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
	 * @param AI_Request_Log_Manager $manager Manager dependency.
	 */
	public function __construct( AI_Request_Log_Manager $manager ) {
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
			__( 'AI Request Logs', 'ai' ),
			__( 'AI Request Logs', 'ai' ),
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
		Asset_Loader::enqueue_script( 'ai_request_logs', 'admin/ai-request-logs' );
		Asset_Loader::enqueue_style( 'ai_request_logs', 'admin/style-ai-request-logs' );

		Asset_Loader::localize_script(
			'ai_request_logs',
			'AiRequestLogsSettings',
			array(
				'rest'         => array(
					'nonce'  => wp_create_nonce( 'wp_rest' ),
					'root'   => esc_url_raw( rest_url() ),
					'routes' => array(
						'logs'    => 'ai/v1/logs',
						'summary' => 'ai/v1/logs/summary',
						'filters' => 'ai/v1/logs/filters',
					),
				),
				'initialState' => array(
					'enabled'       => $this->manager->is_logging_enabled(),
					'retentionDays' => $this->manager->get_retention_days(),
					'summary'       => $this->manager->get_summary( 'day' ),
					'filters'       => $this->manager->get_filter_options(),
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
		<div class="wrap ai-request-logs">
			<h1><?php esc_html_e( 'AI Request Logs', 'ai' ); ?></h1>
			<div id="ai-request-logs-root"></div>
		</div>
		<?php
	}
}
