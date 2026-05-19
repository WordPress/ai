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

defined( 'ABSPATH' ) || exit;

/**
 * Provides AI request logging for observability and debugging.
 *
 * @since 1.0.0
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
			'capability'  => 'none',
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
	 * Lazily instantiate the log manager.
	 */
	private function get_manager(): AI_Request_Log_Manager {
		if ( null === $this->manager ) {
			$this->manager = new AI_Request_Log_Manager();
		}

		return $this->manager;
	}
}
