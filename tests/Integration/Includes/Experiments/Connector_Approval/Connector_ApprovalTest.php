<?php
/**
 * Integration tests for the Connector_Approval experiment class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Connector_Approval
 */

namespace WordPress\AI\Tests\Integration\Experiments\Connector_Approval;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Connector_Approval\Connector_Approval;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Features\Loader;
use WordPress\AI\Features\Registry;

/**
 * Connector_Approval experiment test case.
 *
 * @since 1.0.0
 */
class Connector_ApprovalTest extends WP_UnitTestCase {
	/**
	 * Experiment instance under test.
	 *
	 * @since 1.0.0
	 *
	 * @var \WordPress\AI\Experiments\Connector_Approval\Connector_Approval
	 */
	private Connector_Approval $experiment;

	/**
	 * Set up test case.
	 *
	 * @since 1.0.0
	 */
	public function setUp(): void {
		parent::setUp();

		add_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );

		update_option( 'wpai_features_enabled', true );
		update_option( 'wpai_feature_connector-approval_enabled', true );

		$registry = new Registry();
		$loader   = new Loader( $registry );
		$loader->init();

		$experiment = $registry->get_feature( 'connector-approval' );
		$this->assertInstanceOf(
			Connector_Approval::class,
			$experiment,
			'Connector Approval experiment should be registered in the registry.'
		);

		$this->experiment = $experiment;
	}

	/**
	 * Tear down test case.
	 *
	 * @since 1.0.0
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_connector-approval_enabled' );
		remove_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );
		remove_all_filters( 'wpai_feature_connector-approval_enabled' );
		parent::tearDown();
	}

	/**
	 * Test that the experiment metadata is registered correctly.
	 *
	 * @since 1.0.0
	 */
	public function test_experiment_registration() {
		$this->assertSame( 'connector-approval', $this->experiment->get_id() );
		$this->assertSame( 'Connector Approval', $this->experiment->get_label() );
		$this->assertSame( Experiment_Category::ADMIN, $this->experiment->get_category() );
		$this->assertTrue( $this->experiment->is_enabled() );
	}

	/**
	 * Test that the experiment can be disabled via feature filter.
	 *
	 * @since 1.0.0
	 */
	public function test_experiment_can_be_disabled_via_filter() {
		add_filter( 'wpai_feature_connector-approval_enabled', '__return_false' );

		$experiment = new Connector_Approval();
		$this->assertFalse( $experiment->is_enabled() );

		remove_all_filters( 'wpai_feature_connector-approval_enabled' );
	}

	/**
	 * Test that registering the experiment exposes REST routes.
	 *
	 * @since 1.0.0
	 */
	public function test_register_exposes_connector_approval_rest_routes() {
		$this->experiment->register();
		do_action( 'rest_api_init' );

		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/ai/v1/connector-approvals', $routes );
		$this->assertArrayHasKey( '/ai/v1/connector-approvals/pending', $routes );

		$collection_methods = array();
		foreach ( $routes['/ai/v1/connector-approvals'] as $handler ) {
			$methods = $handler['methods'] ?? array();
			if ( is_array( $methods ) ) {
				$collection_methods = array_merge( $collection_methods, array_keys( $methods ) );
			} else {
				$collection_methods[] = $methods;
			}
		}
		$this->assertContains( 'GET', $collection_methods );
		$this->assertContains( 'POST', $collection_methods );

		$pending_methods = array();
		foreach ( $routes['/ai/v1/connector-approvals/pending'] as $handler ) {
			$methods = $handler['methods'] ?? array();
			if ( is_array( $methods ) ) {
				$pending_methods = array_merge( $pending_methods, array_keys( $methods ) );
			} else {
				$pending_methods[] = $methods;
			}
		}
		$this->assertContains( 'DELETE', $pending_methods );
	}

	/**
	 * Test that customize_rest_error filter modifies error messages.
	 *
	 * @since 1.1.0
	 */
	public function test_customize_rest_error() {
		$this->experiment->register();

		$request = new \WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/ai/title-generation/run' );
		$response = new \WP_REST_Response(
			array(
				'code'    => 'wpai_connector_not_approved',
				'message' => 'The "google" AI connector has not been approved for use by "ai/ai.php".',
				'data'    => array( 'status' => 403 ),
			),
			403
		);

		$filtered_response = apply_filters( 'rest_post_dispatch', $response, rest_get_server(), $request );
		$data = $filtered_response->get_data();

		$this->assertStringContainsString( 'Title generation failed.', $data['message'] );
		$this->assertStringContainsString( 'The AI connector is currently pending authorization.', $data['message'] );
		$this->assertStringContainsString( 'Please approve the request under Tools > Connector Approvals.', $data['message'] );
	}

	/**
	 * Test that customize_rest_error filter modifies error messages for different abilities.
	 *
	 * @since 1.1.0
	 */
	public function test_customize_rest_error_different_abilities() {
		$this->experiment->register();

		// Test excerpt generation.
		$request1 = new \WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/ai/excerpt-generation/run' );
		$response1 = new \WP_REST_Response(
			array(
				'code'    => 'wpai_connector_not_approved',
				'message' => 'Blocked.',
				'data'    => array( 'status' => 403 ),
			),
			403
		);

		$filtered1 = apply_filters( 'rest_post_dispatch', $response1, rest_get_server(), $request1 );
		$data1 = $filtered1->get_data();
		$this->assertStringContainsString( 'Excerpt generation failed.', $data1['message'] );

		// Test fallback.
		$request2 = new \WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/ai/unknown-ability/run' );
		$response2 = new \WP_REST_Response(
			array(
				'code'    => 'wpai_connector_not_approved',
				'message' => 'Blocked.',
				'data'    => array( 'status' => 403 ),
			),
			403
		);

		$filtered2 = apply_filters( 'rest_post_dispatch', $response2, rest_get_server(), $request2 );
		$data2 = $filtered2->get_data();
		$this->assertStringContainsString( 'Request failed.', $data2['message'] );

		// Test non-matching error code.
		$request3 = new \WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/ai/title-generation/run' );
		$response3 = new \WP_REST_Response(
			array(
				'code'    => 'some_other_error',
				'message' => 'Some other message.',
				'data'    => array( 'status' => 403 ),
			),
			403
		);

		$filtered3 = apply_filters( 'rest_post_dispatch', $response3, rest_get_server(), $request3 );
		$data3 = $filtered3->get_data();
		$this->assertSame( 'Some other message.', $data3['message'] );
	}
}
