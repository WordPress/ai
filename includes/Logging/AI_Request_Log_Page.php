<?php
/**
 * Admin page for viewing AI request logs.
 *
 * @package WordPress\AI\Logging
 */

declare( strict_types=1 );

namespace WordPress\AI\Logging;

use WordPress\AI\Asset_Loader;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the AI Request Logs screen under Tools.
 *
 * @since 1.0.0
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
		// Enqueue bundled DataViews styles — copied into build/ by the build step.
		// Falls back to the WP-registered handle if available in a future WP release.
		$dataviews_css = WPAI_PLUGIN_DIR . 'build/admin/dataviews.css';
		if ( ! wp_styles()->query( 'wp-dataviews' ) && file_exists( $dataviews_css ) ) {
			wp_enqueue_style(
				'ai-dataviews',
				WPAI_PLUGIN_URL . 'build/admin/dataviews.css',
				array(),
				(string) filemtime( $dataviews_css )
			);
		}

		Asset_Loader::enqueue_script( 'ai_request_logs', 'admin/ai-request-logs' );
		Asset_Loader::enqueue_style( 'ai_request_logs', 'admin/ai-request-logs' );

		Asset_Loader::localize_script(
			'ai_request_logs',
			'RequestLogsSettings',
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
					'summary' => $this->manager->get_summary( 'day' ),
					'filters' => $this->manager->get_filter_options(),
				),
				'connectorsUrl'    => admin_url( 'options-connectors.php' ),
				'providerMetadata' => $this->get_provider_metadata(),
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
			<div id="ai-request-logs-root"></div>
		</div>
		<?php
	}

	/**
	 * Builds the provider metadata payload sent to the React app.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_provider_metadata(): array {
		$providers = array();

		foreach ( wp_get_connectors() as $slug => $connector_data ) {
			if ( ! is_array( $connector_data ) || 'ai_provider' !== ( $connector_data['type'] ?? '' ) ) {
				continue;
			}

			$auth = isset( $connector_data['authentication'] ) && is_array( $connector_data['authentication'] )
				? $connector_data['authentication']
				: array();

			$entry = array(
				'id'   => (string) $slug,
				'name' => isset( $connector_data['name'] ) && is_string( $connector_data['name'] ) ? $connector_data['name'] : (string) $slug,
				'type' => 'none' === ( $auth['method'] ?? '' ) ? 'client' : 'cloud',
			);

			if ( ! empty( $connector_data['logo_url'] ) && is_string( $connector_data['logo_url'] ) ) {
				$entry['logo'] = $connector_data['logo_url'];
			}

			if ( ! empty( $auth['credentials_url'] ) && is_string( $auth['credentials_url'] ) ) {
				$entry['url'] = $auth['credentials_url'];
			}

			$providers[ (string) $slug ] = $entry;
		}

		return $providers;
	}
}
