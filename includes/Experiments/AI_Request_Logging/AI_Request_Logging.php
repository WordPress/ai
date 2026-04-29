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

defined( 'ABSPATH' ) || exit;

/**
 * Provides AI request logging for observability and debugging.
 *
 * @since x.x.x
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
			'description' => __( 'Logs AI requests for observability and debugging. View detailed logs under Tools.', 'ai' ),
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
			static::get_field_option_name( 'retention_days' ),
			array(
				'type'              => 'integer',
				'default'           => AI_Request_Log_Manager::DEFAULT_RETENTION_DAYS,
				'sanitize_callback' => array( $this, 'sanitize_retention_days' ),
				'show_in_rest'      => array(
					'schema' => array(
						'type'    => 'integer',
						'minimum' => AI_Request_Log_Manager::MIN_RETENTION_DAYS,
						'maximum' => AI_Request_Log_Manager::MAX_RETENTION_DAYS,
					),
				),
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_settings_fields(): array {
		return array(
			array(
				'id'      => 'retention_days',
				'label'   => __( 'Log retention (days)', 'ai' ),
				'type'    => 'integer',
				'default' => AI_Request_Log_Manager::DEFAULT_RETENTION_DAYS,
				'isValid' => array(
					'min' => AI_Request_Log_Manager::MIN_RETENTION_DAYS,
					'max' => AI_Request_Log_Manager::MAX_RETENTION_DAYS,
				),
			),
		);
	}

	/**
	 * Sanitize retention day values from the settings UI.
	 *
	 * @param mixed $value Raw value.
	 * @return int Sanitized value within 1-365.
	 */
	public function sanitize_retention_days( $value ): int {
		return max(
			AI_Request_Log_Manager::MIN_RETENTION_DAYS,
			min( AI_Request_Log_Manager::MAX_RETENTION_DAYS, (int) $value )
		);
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
