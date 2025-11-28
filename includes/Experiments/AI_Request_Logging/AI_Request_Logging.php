<?php
/**
 * AI Request Logging experiment implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\AI_Request_Logging;

use WordPress\AI\Abstracts\Abstract_Experiment;
use WordPress\AI\Logging\AI_Request_Log_Manager;
use WordPress\AI\Logging\AI_Request_Log_Page;
use WordPress\AI\Logging\REST\AI_Request_Log_Controller;

use function WordPress\AI\get_request_log_manager;
use WordPress\AI\Settings\Settings_Registration;

use function add_action;
use function esc_attr;
use function esc_html__;
use function esc_html_e;
use function is_admin;
use function register_setting;

/**
 * Provides AI request logging for observability and cost tracking.
 *
 * @since 0.1.0
 */
class AI_Request_Logging extends Abstract_Experiment {

	/**
	 * Shared log manager instance.
	 */
	private ?AI_Request_Log_Manager $manager = null;

	/**
	 * {@inheritDoc}
	 */
	protected function load_experiment_metadata(): array {
		return array(
			'id'          => 'ai-request-logging',
			'label'       => esc_html__( 'AI Request Logging', 'ai' ),
			'description' => esc_html__( 'Log AI requests for observability, debugging, and cost tracking. View logs under Settings → AI Request Logs.', 'ai' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		$manager = $this->get_manager();

		add_action(
			'rest_api_init',
			static function () use ( $manager ) {
				$controller = new AI_Request_Log_Controller( $manager );
				$controller->register_routes();
			}
		);

		if ( is_admin() ) {
			$page = new AI_Request_Log_Page( $manager );
			$page->init();
		}
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
		</div>
		<?php
	}

	/**
	 * {@inheritDoc}
	 */
	public function has_settings(): bool {
		return true;
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
			$this->manager = get_request_log_manager() ?? new AI_Request_Log_Manager();
		}

		return $this->manager;
	}
}
