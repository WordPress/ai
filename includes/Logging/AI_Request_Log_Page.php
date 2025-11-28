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
			<div class="ai-admin-header">
				<div class="ai-admin-header__inner">
					<div class="ai-admin-header__left">
						<span class="ai-admin-header__icon">
							<svg width="1em" height="1em" viewBox="0 0 100 100" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
								<path d="M97.8823 47.0151L77.3637 39.9372C69.2494 37.1494 62.8508 30.7507 60.063 22.6363L52.9851 2.11795C52.0201 -0.705983 47.9801 -0.705983 47.0151 2.11795L39.9372 22.6363C37.1494 30.7507 30.7507 37.1494 22.6363 39.9372L2.11795 47.0151C-0.705983 47.9801 -0.705983 52.0201 2.11795 52.9851L22.6363 60.063C30.7507 62.8508 37.1494 69.2494 39.9372 77.3637L47.0151 97.8823C47.9801 100.706 52.0201 100.706 52.9851 97.8823L60.063 77.3637C62.8508 69.2494 69.2494 62.8508 77.3637 60.063L97.8823 52.9851C100.706 52.0201 100.706 47.9801 97.8823 47.0151ZM73.9323 51.5194L63.673 55.058C59.598 56.4523 56.4165 59.6694 55.0222 63.7087L51.4837 73.968C50.983 75.398 48.9815 75.398 48.4808 73.968L44.9422 63.7087C43.5479 59.6337 40.3308 56.4523 36.2915 55.058L26.0322 51.5194C24.6024 51.0187 24.6024 49.0172 26.0322 48.5165L36.2915 44.9779C40.3665 43.5837 43.5479 40.3665 44.9422 36.3272L48.4808 26.068C48.9815 24.6381 50.983 24.6381 51.4837 26.068L55.0222 36.3272C56.4165 40.4022 59.6337 43.5837 63.673 44.9779L73.9323 48.5165C75.3623 49.0172 75.3623 51.0187 73.9323 51.5194Z" />
							</svg>
						</span>
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
}
