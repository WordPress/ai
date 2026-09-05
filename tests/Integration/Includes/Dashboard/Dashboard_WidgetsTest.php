<?php
/**
 * Tests for the Dashboard_Widgets class.
 *
 * @package WordPress\AI\Tests\Integration\Dashboard
 */

namespace WordPress\AI\Tests\Integration\Dashboard;

use WP_UnitTestCase;
use WordPress\AI\Admin\Dashboard\Dashboard_Widgets;
use WordPress\AI\Experiments\Content_Gap_Suggestions\Content_Gap_Suggestions;
use WordPress\AI\Features\Registry;

/**
 * Dashboard_Widgets test case.
 *
 * @since 0.8.0
 */
class Dashboard_WidgetsTest extends WP_UnitTestCase {

	/**
	 * Tests that init hooks into wp_dashboard_setup.
	 *
	 * @since 0.8.0
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
	 * @since 0.8.0
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
	 * @since 0.8.0
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
		$this->assertNotContains(
			'wpai_content_opportunities',
			$all_widgets,
			'Content Opportunities widget should not be registered when the feature is absent from the registry'
		);

		// Clean up.
		unset( $wp_meta_boxes['dashboard'] );
	}

	/**
	 * Tests that the Content Opportunities widget registers when the feature is enabled.
	 *
	 * @since x.x.x
	 */
	public function test_register_widgets_includes_content_opportunities_when_enabled() {
		if ( ! function_exists( 'wp_add_dashboard_widget' ) ) {
			require_once ABSPATH . 'wp-admin/includes/dashboard.php';
		}

		global $wp_meta_boxes;

		update_option( 'wpai_features_enabled', true );
		update_option( 'wpai_feature_content-gap-suggestions_enabled', true );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		set_current_screen( 'dashboard' );

		$registry = new Registry();
		$registry->register_feature( new Content_Gap_Suggestions() );

		$widgets = new Dashboard_Widgets( $registry );
		$widgets->register_widgets();

		$all_widgets = array();
		foreach ( $wp_meta_boxes['dashboard'] ?? array() as $context ) {
			foreach ( $context as $priority_widgets ) {
				$all_widgets = array_merge( $all_widgets, array_keys( $priority_widgets ) );
			}
		}

		$this->assertContains(
			'wpai_content_opportunities',
			$all_widgets,
			'Content Opportunities widget should be registered when the feature is enabled'
		);

		// Clean up.
		unset( $wp_meta_boxes['dashboard'] );
		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_content-gap-suggestions_enabled' );
	}

	/**
	 * Tests that the Content Opportunities widget is skipped when the feature is disabled.
	 *
	 * @since x.x.x
	 */
	public function test_register_widgets_excludes_content_opportunities_when_disabled() {
		if ( ! function_exists( 'wp_add_dashboard_widget' ) ) {
			require_once ABSPATH . 'wp-admin/includes/dashboard.php';
		}

		global $wp_meta_boxes;

		update_option( 'wpai_features_enabled', true );
		update_option( 'wpai_feature_content-gap-suggestions_enabled', false );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		set_current_screen( 'dashboard' );

		$registry = new Registry();
		$registry->register_feature( new Content_Gap_Suggestions() );

		$widgets = new Dashboard_Widgets( $registry );
		$widgets->register_widgets();

		$all_widgets = array();
		foreach ( $wp_meta_boxes['dashboard'] ?? array() as $context ) {
			foreach ( $context as $priority_widgets ) {
				$all_widgets = array_merge( $all_widgets, array_keys( $priority_widgets ) );
			}
		}

		$this->assertNotContains(
			'wpai_content_opportunities',
			$all_widgets,
			'Content Opportunities widget should not be registered when the feature is disabled'
		);

		// Clean up.
		unset( $wp_meta_boxes['dashboard'] );
		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_content-gap-suggestions_enabled' );
	}
}
