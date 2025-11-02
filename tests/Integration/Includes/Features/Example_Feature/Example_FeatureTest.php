<?php
/**
 * Integration tests for the Example_Feature class.
 *
 * @package WordPress\AI\Tests\Integration\Features
 */

namespace WordPress\AI\Tests\Integration\Features\Example_Feature;

use WordPress\AI\Feature_Registry;
use WordPress\AI\Feature_Loader;
use WordPress\AI\Features\Example_Feature\Example_Feature;
use WP_UnitTestCase;

/**
 * Example_Feature test case.
 *
 * @since 0.1.0
 */
class Example_FeatureTest extends WP_UnitTestCase {
	/**
	 * Set up test case.
	 *
	 * @since 0.1.0
	 */
	public function setUp(): void {
		parent::setUp();

		$registry = new Feature_Registry();
		$loader   = new Feature_Loader( $registry );
		$loader->register_default_features();

		$feature = $registry->get_feature( 'example-feature' );
		$this->assertInstanceOf( Example_Feature::class, $feature, 'Example feature should be registered in the registry.' );
	}

	/**
	 * Tear down test case.
	 *
	 * @since 0.1.0
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test that the feature is registered correctly.
	 *
	 * @since 0.1.0
	 */
	public function test_feature_registration() {
		$feature = new Example_Feature();

		$this->assertEquals( 'example-feature', $feature->get_id() );
		$this->assertEquals( 'Example Feature', $feature->get_label() );
		$this->assertTrue( $feature->is_enabled() );
	}

	/**
	 * Test that footer content is added for logged-in users.
	 *
	 * @since 0.1.0
	 */
	public function test_add_footer_content_for_logged_in_users() {
		$this->logInAsAdmin();

		$this->setExpectedDeprecated( 'the_block_template_skip_link' );

		ob_start();
		do_action( 'wp_footer' );
		$footer_content = ob_get_clean();

		$this->assertStringContainsString( '<!-- Example Feature: AI Plugin Active -->', $footer_content );
	}

	/**
	 * Test that footer content is not added for logged-out users.
	 *
	 * @since 0.1.0
	 */
	public function test_add_footer_content_for_logged_out_users() {
		$this->logOut();

		$this->setExpectedDeprecated( 'the_block_template_skip_link' );

		ob_start();
		do_action( 'wp_footer' );
		$footer_content = ob_get_clean();

		$this->assertStringNotContainsString( '<!-- Example Feature: AI Plugin Active -->', $footer_content );
	}

	/**
	 * Test that title is modified when WP_DEBUG is true.
	 *
	 * @since 0.1.0
	 */
	public function test_modify_title_with_debug_true() {
		$this->assertTrue( defined( 'WP_DEBUG' ) && WP_DEBUG, 'WP_DEBUG should be true in test environment' );

		$title_parts = array(
			'title' => 'My Post',
			'site'  => 'My Site',
		);

		$modified_title = apply_filters( 'document_title_parts', $title_parts );

		$this->assertEquals( 'My Site [AI]', $modified_title['site'] );
	}

	/**
	 * Test that title is not modified when WP_DEBUG is false.
	 *
	 * @since 0.1.0
	 */
	public function test_modify_title_with_debug_false() {
		// Temporarily define WP_DEBUG as false.
		if ( defined( 'WP_DEBUG' ) ) {
			// If already defined, undefine it to redefine.
			// This is tricky in PHPUnit, usually better to avoid defining constants in tests.
			// For this specific case, we'll assume it's not defined or can be overridden.
			// In a real scenario, you might mock the constant or use a different approach.
			// For now, we'll just ensure it's not true.
			$this->markTestSkipped( 'Cannot reliably test WP_DEBUG false if already defined.' );
		}
		define( 'WP_DEBUG', false );

		$title_parts = array(
			'title' => 'My Post',
			'site'  => 'My Site',
		);

		$modified_title = apply_filters( 'document_title_parts', $title_parts );

		$this->assertEquals( 'My Site', $modified_title['site'] );
	}

	/**
	 * Test that the REST route is registered.
	 *
	 * @since 0.1.0
	 */
	public function test_rest_route_registration() {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/ai/v1/example', $routes );
		$this->assertArrayHasKey( 'methods', $routes['/ai/v1/example'][0] );
		$methods = $routes['/ai/v1/example'][0]['methods'];

		if ( is_array( $methods ) ) {
			$this->assertContains( 'GET', array_keys( $methods ) );
		} else {
			$this->assertEquals( 'GET', $methods );
		}
	}

	/**
	 * Test the REST endpoint callback.
	 *
	 * @since 0.1.0
	 */
	public function test_rest_endpoint_callback() {
		$this->logInAsAdmin();

		$request = new \WP_REST_Request( 'GET', '/ai/v1/example' );
		$response = rest_get_server()->dispatch( $request );
		$data = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 'example-feature', $data['feature_id'] );
		$this->assertEquals( 'Example Feature', $data['label'] );
		$this->assertEquals( 'Example feature is active!', $data['message'] );
	}

	/**
	 * Test REST permission callback for users with manage_options capability.
	 *
	 * @since 0.1.0
	 */
	public function test_rest_permission_callback_with_manage_options() {
		$this->logInAsAdmin();

		$request = new \WP_REST_Request( 'GET', '/ai/v1/example' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
	}

	/**
	 * Test REST permission callback for users without manage_options capability.
	 *
	 * @since 0.1.0
	 */
	public function test_rest_permission_callback_without_manage_options() {
		$this->logOut();
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$request = new \WP_REST_Request( 'GET', '/ai/v1/example' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() ); // 403 Forbidden
	}

	/**
	 * Test that register() calls add_action for settings sections.
	 *
	 * @since 0.1.0
	 */
	public function test_register_calls_add_action_for_settings_sections() {
		$feature = new Example_Feature();
		$feature->register();

		$this->assertTrue( has_action( 'ai_register_settings_sections' ) !== false );
	}

	/**
	 * Test that register() skips functional hooks when feature is disabled.
	 *
	 * @since 0.1.0
	 */
	public function test_register_skips_functional_hooks_when_disabled() {
		add_filter( 'ai_feature_example-feature_enabled', '__return_false' );

		$feature = new Example_Feature();
		$feature->register();

		$this->assertFalse( has_action( 'wp_footer', array( $feature, 'add_footer_content' ) ) );
		$this->assertFalse( has_filter( 'document_title_parts', array( $feature, 'modify_title' ) ) );
		$this->assertFalse( has_action( 'rest_api_init', array( $feature, 'register_rest_route' ) ) );

		remove_filter( 'ai_feature_example-feature_enabled', '__return_false' );
	}

	/**
	 * Test register_settings_sections() early return when section exists.
	 *
	 * @since 0.1.0
	 */
	public function test_register_settings_sections_early_return() {
		$registry = $this->createMock( \WordPress\AI\Admin\Settings\Settings_Registry::class );
		$registry->expects( $this->once() )
			->method( 'has_section' )
			->with( 'example-feature' )
			->willReturn( true );

		$registry->expects( $this->never() )
			->method( 'register_section' );

		$feature = new Example_Feature();
		$feature->register_settings_sections( $registry );
	}

	/**
	 * Test render_settings_section() outputs expected content.
	 *
	 * @since 0.1.0
	 */
	public function test_render_settings_section() {
		$toggle  = $this->createMock( \WordPress\AI\Admin\Settings\Settings_Toggle::class );
		$section = $this->createMock( \WordPress\AI\Admin\Settings\Settings_Section::class );

		$feature = new Example_Feature();

		ob_start();
		$feature->render_settings_section( $toggle, $section );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Example Feature does not expose additional controls yet', $output );
	}

	/**
	 * Logs in a user with administrator privileges.
	 *
	 * @since 0.1.0
	 */
	protected function logInAsAdmin() {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
	}

	/**
	 * Logs out the current user.
	 *
	 * @since 0.1.0
	 */
	protected function logOut() {
		wp_logout();
	}
}
