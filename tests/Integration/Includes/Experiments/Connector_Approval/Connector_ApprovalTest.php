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
	 * Ability IDs stubbed by this test case, tracked for cleanup.
	 *
	 * @since x.x.x
	 *
	 * @var string[]
	 */
	private array $stubbed_abilities = array();

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

		foreach ( $this->stubbed_abilities as $ability_id ) {
			wp_unregister_ability( $ability_id );
		}
		$this->stubbed_abilities = array();

		parent::tearDown();
	}

	/**
	 * Registers a minimal ability under the given ID and label, so that
	 * wp_get_ability() (used by Connector_Approval::get_context_aware_error_message())
	 * resolves a real label instead of falling back to the generic message.
	 *
	 * Mirrors the label each ability is actually registered with in production
	 * (see the corresponding Experiments directory's register_abilities() methods),
	 * so assertions in this test reflect real runtime output.
	 *
	 * @since x.x.x
	 *
	 * @param string $ability_id The ability ID, e.g. 'ai/title-generation'.
	 * @param string $label      The ability label to register it with.
	 */
	private function register_stub_ability( string $ability_id, string $label ): void {
		if ( wp_get_ability( $ability_id ) ) {
			return;
		}

		global $wp_current_filter;

		if ( ! wp_has_ability_category( WPAI_DEFAULT_ABILITY_CATEGORY ) ) {
			$wp_current_filter[] = 'wp_abilities_api_categories_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
			try {
				wp_register_ability_category(
					WPAI_DEFAULT_ABILITY_CATEGORY,
					array(
						'label'       => 'AI',
						'description' => 'Various AI features and experiments.',
					)
				);
			} finally {
				array_pop( $wp_current_filter );
			}
		}

		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			wp_register_ability(
				$ability_id,
				array(
					'label'               => $label,
					'description'         => 'Stub ability registered for testing.',
					'category'            => WPAI_DEFAULT_ABILITY_CATEGORY,
					'input_schema'        => array( 'type' => 'object' ),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => '__return_true',
					'permission_callback' => '__return_true',
				)
			);
		} finally {
			array_pop( $wp_current_filter );
		}

		$this->stubbed_abilities[] = $ability_id;
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
		$this->register_stub_ability( 'ai/title-generation', 'Title Generation' );

		$this->experiment->register();

		$request  = new \WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/ai/title-generation/run' );
		$response = new \WP_REST_Response(
			array(
				'code'    => 'wpai_connector_not_approved',
				'message' => 'The "google" AI connector has not been approved for use by "ai/ai.php".',
				'data'    => array( 'status' => 403 ),
			),
			403
		);

		$filtered_response = apply_filters( 'rest_post_dispatch', $response, rest_get_server(), $request );
		$data              = $filtered_response->get_data();

		$this->assertStringContainsString( 'Title Generation failed.', $data['message'] );
		$this->assertStringContainsString( 'The AI connector is currently pending authorization.', $data['message'] );
		$this->assertStringContainsString( 'Please approve the request under Tools > Connector Approvals.', $data['message'] );
	}

	/**
	 * Test that customize_rest_error filter modifies error messages for different abilities.
	 *
	 * @since 1.1.0
	 */
	public function test_customize_rest_error_different_abilities() {
		$this->register_stub_ability( 'ai/excerpt-generation', 'Excerpt Generation' );

		$this->experiment->register();

		// Test excerpt generation.
		$request1  = new \WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/ai/excerpt-generation/run' );
		$response1 = new \WP_REST_Response(
			array(
				'code'    => 'wpai_connector_not_approved',
				'message' => 'Blocked.',
				'data'    => array( 'status' => 403 ),
			),
			403
		);

		$filtered1 = apply_filters( 'rest_post_dispatch', $response1, rest_get_server(), $request1 );
		$data1     = $filtered1->get_data();
		$this->assertStringContainsString( 'Excerpt Generation failed.', $data1['message'] );

		// Test fallback.
		$request2  = new \WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/ai/unknown-ability/run' );
		$response2 = new \WP_REST_Response(
			array(
				'code'    => 'wpai_connector_not_approved',
				'message' => 'Blocked.',
				'data'    => array( 'status' => 403 ),
			),
			403
		);

		$filtered2 = apply_filters( 'rest_post_dispatch', $response2, rest_get_server(), $request2 );
		$data2     = $filtered2->get_data();
		$this->assertStringContainsString( 'Request failed.', $data2['message'] );

		// Test non-matching error code.
		$request3  = new \WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/ai/title-generation/run' );
		$response3 = new \WP_REST_Response(
			array(
				'code'    => 'some_other_error',
				'message' => 'Some other message.',
				'data'    => array( 'status' => 403 ),
			),
			403
		);

		$filtered3 = apply_filters( 'rest_post_dispatch', $response3, rest_get_server(), $request3 );
		$data3     = $filtered3->get_data();
		$this->assertSame( 'Some other message.', $data3['message'] );
	}

	/**
	 * Test that registering the experiment registers notices and pages when is_admin() is true.
	 *
	 * @since 1.1.0
	 */
	public function test_register_in_admin_context() {
		// Mock admin context using set_current_screen
		set_current_screen( 'dashboard' );

		$admin_experiment = new Connector_Approval();
		$admin_experiment->register();

		// Clean up the current screen
		set_current_screen( 'front' );

		// Check that the actions were added
		$this->assertGreaterThan( 0, has_action( 'admin_notices' ) );
		$this->assertGreaterThan( 0, has_action( 'admin_menu' ) );
	}

	/**
	 * Test customize_rest_error returns the input immediately if it is not a WP_REST_Response or is not an error.
	 *
	 * @since 1.1.0
	 */
	public function test_customize_rest_error_with_invalid_response() {
		$this->experiment->register();

		// Not a WP_REST_Response (e.g. string)
		$result_string = $this->experiment->customize_rest_error( 'not_a_response', rest_get_server(), new \WP_REST_Request() );
		$this->assertSame( 'not_a_response', $result_string );

		// WP_REST_Response but not an error (status 200)
		$response_200 = new \WP_REST_Response( array( 'status' => 'ok' ), 200 );
		$result_200   = $this->experiment->customize_rest_error( $response_200, rest_get_server(), new \WP_REST_Request() );
		$this->assertSame( $response_200, $result_200 );
	}

	/**
	 * Test customize_rest_error filter modifies error messages for all context abilities.
	 *
	 * @since 1.1.0
	 */
	public function test_customize_rest_error_all_abilities() {
		// Labels mirror what each ability is actually registered with in production
		// (see the corresponding Experiments/*/*.php::register_abilities() methods).
		$ability_labels = array(
			'ai/image-generation'       => 'Image Generation and Editing',
			'ai/alt-text-generation'    => 'Alt Text Generation',
			'ai/meta-description'       => 'Meta Description Generation',
			'ai/editorial-notes'        => 'Editorial Notes',
			'ai/editorial-updates'      => 'Editorial Updates',
			'ai/content-resizing'       => 'Content Resizing',
			'ai/content-classification' => 'Content Classification',
			'ai/summarization'          => 'Content Summarization',
			'ai/comment-analysis'       => 'Comment Analysis',
		);

		foreach ( $ability_labels as $ability_id => $label ) {
			$this->register_stub_ability( $ability_id, $label );
		}

		$this->experiment->register();

		$abilities = array(
			'ai/image-generation'       => 'Image Generation and Editing failed.',
			'ai/alt-text-generation'    => 'Alt Text Generation failed.',
			'ai/meta-description'       => 'Meta Description Generation failed.',
			'ai/editorial-notes'        => 'Editorial Notes failed.',
			'ai/editorial-updates'      => 'Editorial Updates failed.',
			'ai/content-resizing'       => 'Content Resizing failed.',
			'ai/content-classification' => 'Content Classification failed.',
			'ai/summarization'          => 'Content Summarization failed.',
			'ai/comment-analysis'       => 'Comment Analysis failed.',
		);

		foreach ( $abilities as $ability_id => $expected_substring ) {
			$request  = new \WP_REST_Request( 'POST', "/wp-abilities/v1/abilities/{$ability_id}/run" );
			$response = new \WP_REST_Response(
				array(
					'code'    => 'wpai_connector_not_approved',
					'message' => 'Blocked.',
					'data'    => array( 'status' => 403 ),
				),
				403
			);

			$filtered = $this->experiment->customize_rest_error( $response, rest_get_server(), $request );
			$data     = $filtered->get_data();
			$this->assertStringContainsString( $expected_substring, $data['message'] );
		}
	}
}
