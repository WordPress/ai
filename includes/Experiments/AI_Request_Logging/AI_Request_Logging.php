<?php
/**
 * AI Request Logging experiment implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\AI_Request_Logging;

use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Logging\AI_Request_Log_Manager;
use WordPress\AI\Logging\AI_Request_Log_Page;
use WordPress\AI\Logging\Logging_Integration;
use WordPress\AI\Logging\REST\AI_Request_Log_Controller;
use WordPress\AI\Settings\Settings_Registration;
use function add_action;
use function esc_attr;
use function esc_html_e;
use function register_setting;

/**
 * Provides AI request logging for observability and cost tracking.
 *
 * @since 0.6.0
 */
class AI_Request_Logging extends Abstract_Feature {

	/**
	 * Shared log manager instance.
	 */
	private ?AI_Request_Log_Manager $manager = null;

	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'ai-request-logging';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'AI Request Logging', 'ai' ),
			'description' => __( 'Logs AI requests for observability, debugging, and cost tracking. View detailed logs under Tools.', 'ai' ),
			'category'    => Experiment_Category::ADMIN,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		$manager = $this->get_manager();
		$manager->init();
		Logging_Integration::init( $manager );

		$controller = new AI_Request_Log_Controller( $manager );
		$page       = new AI_Request_Log_Page( $manager );

		add_action( 'rest_api_init', array( $controller, 'register_routes' ) );
		add_action( 'admin_menu', array( $page, 'register_menu' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_settings(): void {
		register_setting(
			Settings_Registration::OPTION_GROUP,
			AI_Request_Log_Manager::OPTION_RETENTION_DAYS,
			array(
				'type'              => 'integer',
				'default'           => AI_Request_Log_Manager::DEFAULT_RETENTION_DAYS,
				'sanitize_callback' => array( $this, 'sanitize_retention_days' ),
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function render_settings_fields(): void {
		$retention_days = $this->get_manager()->get_retention_days();
		?>
		<div class="ai-experiment-settings">
			<label for="<?php echo esc_attr( AI_Request_Log_Manager::OPTION_RETENTION_DAYS ); ?>" class="ai-experiment-settings__label">
				<?php esc_html_e( 'Log retention (days)', 'ai' ); ?>
			</label>
			<input
				type="number"
				min="1"
				max="365"
				step="1"
				id="<?php echo esc_attr( AI_Request_Log_Manager::OPTION_RETENTION_DAYS ); ?>"
				name="<?php echo esc_attr( AI_Request_Log_Manager::OPTION_RETENTION_DAYS ); ?>"
				value="<?php echo esc_attr( (string) $retention_days ); ?>"
			/>
			<p class="description">
				<?php esc_html_e( 'Logs older than this will be automatically deleted.', 'ai' ); ?>
			</p>
			<p class="description">
				<a href="<?php echo esc_url( admin_url( 'tools.php?page=ai-request-logs' ) ); ?>">
					<?php esc_html_e( 'Open the AI Request Logs screen.', 'ai' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Sanitize retention day values from the settings UI.
	 *
	 * @param mixed $value Raw value.
	 * @return int Sanitized value within 1-365.
	 */
	public function sanitize_retention_days( $value ): int {
		$value = (int) $value;
		return max( 1, min( 365, $value ) );
	}

	/**
	 * Lazily instantiate the log manager.
	 */
	private function get_manager(): AI_Request_Log_Manager {
		if ( null === $this->manager ) {
			$this->manager = new AI_Request_Log_Manager();
		}

		return $this->manager;
	}
}
