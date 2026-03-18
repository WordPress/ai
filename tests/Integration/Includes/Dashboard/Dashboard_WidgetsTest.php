<?php
/**
 * Tests for the Dashboard_Widgets class.
 *
 * @package WordPress\AI\Tests\Integration\Dashboard
 */

namespace WordPress\AI\Tests\Integration\Dashboard;

use WP_UnitTestCase;
use WordPress\AI\Dashboard\Dashboard_Widgets;
use WordPress\AI\Features\Registry;

/**
 * Dashboard_Widgets test case.
 *
 * @since x.x.x
 */
class Dashboard_WidgetsTest extends WP_UnitTestCase {

	/**
	 * Tests that init hooks into wp_dashboard_setup.
	 *
	 * @since x.x.x
	 */
	public function test_init_hooks_wp_dashboard_setup() {
		$registry = new Registry();
		$widgets  = new Dashboard_Widgets( $registry );
		$widgets->init();

		$this->assertIsInt(
			has_action( 'wp_dashboard_setup', array( $widgets, 'register_widgets' ) ),
			'register_widgets should be hooked to wp_dashboard_setup'
		);
	}

	/**
	 * Tests that register_widgets requires manage_options capability.
	 *
	 * @since x.x.x
	 */
	public function test_register_widgets_requires_manage_options() {
		if ( ! function_exists( 'wp_add_dashboard_widget' ) ) {
			require_once ABSPATH . 'wp-admin/includes/dashboard.php';
		}

		global $wp_meta_boxes;

		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$registry = new Registry();
		$widgets  = new Dashboard_Widgets( $registry );
		$widgets->register_widgets();

		$status_registered = isset( $wp_meta_boxes['dashboard']['normal']['core']['wpai_status'] );

		$this->assertFalse(
			$status_registered,
			'Widgets should not be registered for subscribers'
		);

		// Clean up.
		unset( $wp_meta_boxes['dashboard'] );
	}

	/**
	 * Tests that register_widgets registers both widgets for admin users.
	 *
	 * @since x.x.x
	 */
	public function test_register_widgets_for_admin() {
		if ( ! function_exists( 'wp_add_dashboard_widget' ) ) {
			require_once ABSPATH . 'wp-admin/includes/dashboard.php';
		}

		global $wp_meta_boxes;

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set the current screen to the dashboard so wp_add_dashboard_widget works.
		set_current_screen( 'dashboard' );

		$registry = new Registry();
		$widgets  = new Dashboard_Widgets( $registry );
		$widgets->register_widgets();

		// wp_add_dashboard_widget may place widgets in different priority levels.
		$all_widgets = array();
		foreach ( $wp_meta_boxes['dashboard'] ?? array() as $context ) {
			foreach ( $context as $priority_widgets ) {
				$all_widgets = array_merge( $all_widgets, array_keys( $priority_widgets ) );
			}
		}

		$this->assertContains(
			'wpai_status',
			$all_widgets,
			'AI Status widget should be registered'
		);
		$this->assertContains(
			'wpai_capabilities',
			$all_widgets,
			'AI Capabilities widget should be registered'
		);

		// Clean up.
		unset( $wp_meta_boxes['dashboard'] );
	}
}
