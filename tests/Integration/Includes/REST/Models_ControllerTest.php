<?php
/**
 * Integration tests for the Models_Controller class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\REST
 */

namespace WordPress\AI\Tests\Integration\Includes\REST;

use WP_REST_Request;
use WP_UnitTestCase;
use WordPress\AI\REST\Models_Controller;

/**
 * Models_Controller test case.
 *
 * @since x.x.x
 */
class Models_ControllerTest extends WP_UnitTestCase {
	/**
	 * Test that the providers REST route is registered.
	 *
	 * @since x.x.x
	 */
	public function test_register_routes_registers_providers_route(): void {
		$controller = new Models_Controller();
		$controller->init();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Testing registration on the core REST API hook.
		do_action( 'rest_api_init' );

		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/ai/v1/providers', $routes );
		$this->assertArrayHasKey( 'methods', $routes['/ai/v1/providers'][0] );
	}

	/**
	 * Test that the providers endpoint requires manage_options.
	 *
	 * @since x.x.x
	 */
	public function test_providers_route_requires_manage_options(): void {
		$controller = new Models_Controller();
		$controller->init();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Testing registration on the core REST API hook.
		do_action( 'rest_api_init' );

		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$request = new WP_REST_Request( 'GET', '/ai/v1/providers' );
		$request->set_param( 'capability', 'text_generation' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Test that the providers endpoint validates the capability argument.
	 *
	 * @since x.x.x
	 */
	public function test_providers_route_rejects_invalid_capability(): void {
		$controller = new Models_Controller();
		$controller->init();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Testing registration on the core REST API hook.
		do_action( 'rest_api_init' );

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$request = new WP_REST_Request( 'GET', '/ai/v1/providers' );
		$request->set_param( 'capability', 'unsupported' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_capability', $response->as_error()->get_error_code() );
	}

	/**
	 * Test that valid capabilities build model requirements.
	 *
	 * @dataProvider data_valid_capabilities
	 *
	 * @since x.x.x
	 *
	 * @param string $capability Capability slug.
	 */
	public function test_build_requirements_accepts_valid_capabilities( string $capability ): void {
		$controller = new Models_Controller();
		$method     = new \ReflectionMethod( Models_Controller::class, 'build_requirements' );
		$method->setAccessible( true );

		$result = $method->invoke( $controller, $capability );

		$this->assertInstanceOf( \WordPress\AiClient\Providers\Models\DTO\ModelRequirements::class, $result );
	}

	/**
	 * Data provider for valid capability values.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{string}>
	 */
	public function data_valid_capabilities(): array {
		return array(
			'text generation'  => array( 'text_generation' ),
			'image generation' => array( 'image_generation' ),
			'vision'           => array( 'vision' ),
		);
	}

	/**
	 * Test that unknown capabilities throw at the requirements boundary.
	 *
	 * @since x.x.x
	 */
	public function test_build_requirements_rejects_unknown_capability(): void {
		$controller = new Models_Controller();
		$method     = new \ReflectionMethod( Models_Controller::class, 'build_requirements' );
		$method->setAccessible( true );

		$this->expectException( \InvalidArgumentException::class );

		$method->invoke( $controller, 'unknown' );
	}
}
