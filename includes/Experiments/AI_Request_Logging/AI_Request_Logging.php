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
use WordPress\AI\Logging\Usage_Limit_Monitor;
use WordPress\AI\Settings\Settings_Registration;

defined( 'ABSPATH' ) || exit;

/**
 * Provides AI request logging for observability and debugging.
 *
 * @since 1.0.0
 */
class AI_Request_Logging extends Abstract_Feature {

	/**
	 * Default alert threshold, as a percentage of the monthly token limit.
	 *
	 * @since x.x.x
	 */
	public const DEFAULT_ALERT_THRESHOLD = 80;

	/**
	 * Shared log manager instance.
	 */
	private ?AI_Request_Log_Manager $manager = null;

	/**
	 * Monthly token limit monitor.
	 */
	private ?Usage_Limit_Monitor $usage_limit_monitor = null;

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
			'capability'  => 'none',
			'usage'       => array(
				'trigger' => 'none',
				'runs'    => __( 'Records every AI request that filters through the WP AI Client', 'ai' ),
				'sends'   => __( 'Nothing', 'ai' ),
				'details' => __( 'Keeps prompt and response previews until you delete them', 'ai' ),
			),
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
		$monitor    = $this->get_usage_limit_monitor();
		$page       = new AI_Request_Log_Page( $manager, $monitor );

		$monitor->register();

		add_action( 'rest_api_init', array( $controller, 'register_routes' ) );
		add_action( 'admin_menu', array( $page, 'register_menu' ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function register_settings(): void {
		register_setting(
			Settings_Registration::OPTION_GROUP,
			static::get_field_option_name( 'monthly_token_limit' ),
			array(
				'type'              => 'integer',
				'default'           => 0,
				'sanitize_callback' => array( $this, 'sanitize_monthly_token_limit' ),
				'show_in_rest'      => array(
					'schema' => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
				),
			)
		);

		register_setting(
			Settings_Registration::OPTION_GROUP,
			static::get_field_option_name( 'alert_threshold' ),
			array(
				'type'              => 'integer',
				'default'           => self::DEFAULT_ALERT_THRESHOLD,
				'sanitize_callback' => array( $this, 'sanitize_alert_threshold' ),
				'show_in_rest'      => array(
					'schema' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
					),
				),
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function get_settings_fields(): array {
		return array(
			array(
				'id'          => 'monthly_token_limit',
				'label'       => __( 'Monthly token limit', 'ai' ),
				'type'        => 'integer',
				'description' => __( 'Warn administrators when AI requests use this many tokens in a calendar month. Requests are not blocked. Use 0 for no limit.', 'ai' ),
				'default'     => 0,
				'isValid'     => array(
					'min' => 0,
				),
			),
			array(
				'id'          => 'alert_threshold',
				'label'       => __( 'Alert threshold (%)', 'ai' ),
				'type'        => 'integer',
				'description' => __( 'Also warn when usage reaches this percentage of the monthly limit.', 'ai' ),
				'default'     => self::DEFAULT_ALERT_THRESHOLD,
				'isValid'     => array(
					'min' => 1,
					'max' => 100,
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function get_preloaded_options(): array {
		return array(
			static::get_field_option_name( 'monthly_token_limit' ),
			static::get_field_option_name( 'alert_threshold' ),
		);
	}

	/**
	 * Sanitizes the monthly token limit.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $value Raw value.
	 * @return int Limit of 0 or more.
	 */
	public function sanitize_monthly_token_limit( $value ): int {
		return is_numeric( $value ) ? max( 0, (int) $value ) : 0;
	}

	/**
	 * Sanitizes the alert threshold.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $value Raw value.
	 * @return int Percentage between 1 and 100.
	 */
	public function sanitize_alert_threshold( $value ): int {
		if ( ! is_numeric( $value ) ) {
			return self::DEFAULT_ALERT_THRESHOLD;
		}

		return min( 100, max( 1, (int) $value ) );
	}

	/**
	 * Gets the monthly token limit.
	 *
	 * @since x.x.x
	 *
	 * @return int Monthly token limit, or 0 for no limit.
	 */
	public function get_monthly_token_limit(): int {
		$limit = $this->sanitize_monthly_token_limit( get_option( static::get_field_option_name( 'monthly_token_limit' ), 0 ) );

		/**
		 * Filters the monthly AI token limit used for usage warnings.
		 *
		 * @since x.x.x
		 *
		 * @param int $limit Monthly token limit, or 0 for no limit.
		 */
		return $this->sanitize_monthly_token_limit( apply_filters( 'wpai_monthly_token_limit', $limit ) );
	}

	/**
	 * Gets the alert threshold.
	 *
	 * @since x.x.x
	 *
	 * @return int Percentage of the monthly limit at which to warn.
	 */
	public function get_alert_threshold(): int {
		return $this->sanitize_alert_threshold( get_option( static::get_field_option_name( 'alert_threshold' ), self::DEFAULT_ALERT_THRESHOLD ) );
	}

	/**
	 * Lazily instantiate the usage limit monitor.
	 */
	private function get_usage_limit_monitor(): Usage_Limit_Monitor {
		if ( null === $this->usage_limit_monitor ) {
			$this->usage_limit_monitor = new Usage_Limit_Monitor(
				$this->get_manager(),
				$this->get_monthly_token_limit(),
				$this->get_alert_threshold()
			);
		}

		return $this->usage_limit_monitor;
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
