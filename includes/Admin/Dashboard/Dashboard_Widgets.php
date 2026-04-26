<?php
/**
 * Dashboard Widgets orchestrator.
 *
 * Registers dashboard widgets and enqueues their styles.
 *
 * @package WordPress\AI\Admin\Dashboard
 *
 * @since 0.8.0
 */

declare( strict_types=1 );

namespace WordPress\AI\Admin\Dashboard;

use WordPress\AI\Asset_Loader;
use WordPress\AI\Features\Registry;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders dashboard widgets.
 *
 * @since 0.8.0
 */
class Dashboard_Widgets {

	/**
	 * The feature registry instance.
	 *
	 * @since 0.8.0
	 *
	 * @var \WordPress\AI\Features\Registry
	 */
	private Registry $registry;

	/**
	 * Constructor.
	 *
	 * @since 0.8.0
	 *
	 * @param \WordPress\AI\Features\Registry $registry The feature registry.
	 */
	public function __construct( Registry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Hooks into WordPress to register dashboard widgets.
	 *
	 * @since 0.8.0
	 */
	public function init(): void {
		add_action( 'wp_dashboard_setup', array( $this, 'register_widgets' ) );
	}

	/**
	 * Registers the dashboard widgets and enqueues styles.
	 *
	 * Only registers widgets for users with the `manage_options` capability.
	 *
	 * @since 0.8.0
	 */
	public function register_widgets(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$status_widget = new AI_Status_Widget( $this->registry );

		wp_add_dashboard_widget(
			'wpai_status',
			__( 'AI Status', 'ai' ),
			array( $status_widget, 'render' )
		);

		$capabilities_widget = new AI_Capabilities_Widget( $this->registry );

		wp_add_dashboard_widget(
			'wpai_capabilities',
			__( 'AI Capabilities', 'ai' ),
			array( $capabilities_widget, 'render' )
		);

		Asset_Loader::enqueue_style( 'dashboard-widgets', 'admin/dashboard' );
	}
}
