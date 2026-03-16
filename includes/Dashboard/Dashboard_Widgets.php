<?php
/**
 * Dashboard Widgets orchestrator.
 *
 * Registers AI dashboard widgets and enqueues their styles.
 *
 * @package WordPress\AI\Dashboard
 *
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Dashboard;

use WordPress\AI\Asset_Loader;
use WordPress\AI\Experiment_Registry;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders AI dashboard widgets.
 *
 * @since x.x.x
 */
class Dashboard_Widgets {

	/**
	 * The experiment registry instance.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Experiment_Registry
	 */
	private Experiment_Registry $registry;

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Experiment_Registry $registry The experiment registry.
	 */
	public function __construct( Experiment_Registry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Hooks into WordPress to register dashboard widgets.
	 *
	 * @since x.x.x
	 */
	public function init(): void {
		add_action( 'wp_dashboard_setup', array( $this, 'register_widgets' ) );
	}

	/**
	 * Registers the dashboard widgets and enqueues styles.
	 *
	 * Only registers widgets for users with the `manage_options` capability.
	 *
	 * @since x.x.x
	 */
	public function register_widgets(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$status_widget = new AI_Status_Widget( $this->registry );

		wp_add_dashboard_widget(
			'ai_experiments_status',
			__( 'AI Status', 'ai' ),
			array( $status_widget, 'render' )
		);

		Asset_Loader::enqueue_style( 'dashboard-widgets', 'admin/dashboard' );
	}
}
