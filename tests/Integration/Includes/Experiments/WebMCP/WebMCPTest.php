<?php
/**
 * Integration tests for the WebMCP experiment class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\WebMCP
 */

namespace WordPress\AI\Tests\Integration\Experiments\WebMCP;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Experiments\WebMCP\WebMCP;
use WordPress\AI\Features\Loader;
use WordPress\AI\Features\Registry;

/**
 * WebMCP experiment test case.
 *
 * @since x.x.x
 */
class WebMCPTest extends WP_UnitTestCase {

	/**
	 * Sets up the enabled experiment.
	 */
	public function setUp(): void {
		parent::setUp();
		update_option( 'wpai_feature_webmcp_enabled', true );
	}

	/**
	 * Cleans up.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( 'wpai_feature_webmcp_enabled' );
		delete_option( 'wpai_feature_webmcp_field_admin_abilities' );
		delete_option( 'wpai_feature_webmcp_field_visitor_abilities' );
		remove_all_filters( 'wpai_webmcp_exposed_abilities' );
		remove_all_actions( 'wpai_register_features' );
		wp_dequeue_script( 'ai-webmcp' );
		parent::tearDown();
	}

	/**
	 * Tests the metadata.
	 */
	public function test_experiment_metadata(): void {
		$experiment = new WebMCP();

		$this->assertSame( 'webmcp', $experiment->get_id() );
		$this->assertSame( 'WebMCP', $experiment->get_label() );
		$this->assertSame( Experiment_Category::ADMIN, $experiment->get_category() );
		$this->assertSame( 'experimental', $experiment->get_stability() );
		$this->assertSame( 'none', $experiment->get_capability() );
	}

	/**
	 * Tests that the experiment is off unless its option is set.
	 */
	public function test_experiment_is_disabled_by_default(): void {
		delete_option( 'wpai_feature_webmcp_enabled' );

		$this->assertFalse( ( new WebMCP() )->is_enabled() );
	}

	/**
	 * Tests that the experiment is on when its option is set.
	 */
	public function test_experiment_is_enabled_when_option_set(): void {
		$this->assertTrue( ( new WebMCP() )->is_enabled() );
	}

	/**
	 * Tests the two settings fields.
	 */
	public function test_settings_fields(): void {
		$ids = array_column( ( new WebMCP() )->get_settings_fields(), 'id' );

		$this->assertSame( array( 'admin_abilities', 'visitor_abilities' ), $ids );
		$this->assertSame( 'wpai_feature_webmcp_field_admin_abilities', WebMCP::get_field_option_name( 'admin_abilities' ) );
	}

	/**
	 * Tests registration through the plugin's loader and the routes it adds.
	 */
	public function test_experiment_registers_through_loader(): void {
		$registry = new Registry();
		$loader   = new Loader( $registry );

		add_action(
			'wpai_register_features',
			static function ( $reg ) {
				$reg->register_feature( new WebMCP() );
			}
		);

		$loader->init();
		do_action( 'rest_api_init', rest_get_server() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook fired to register the routes under test.

		$this->assertInstanceOf( WebMCP::class, $registry->get_feature( 'webmcp' ) );

		$routes = rest_get_server()->get_routes( 'ai/v1' );
		$this->assertArrayHasKey( '/ai/v1/webmcp/tools', $routes );
		$this->assertArrayHasKey( '/ai/v1/webmcp/execute', $routes );
		$this->assertArrayHasKey( '/ai/v1/webmcp/nonce', $routes );
	}

	/**
	 * Tests that the bridge is not enqueued for a logged-out request to wp-admin.
	 */
	public function test_bridge_not_enqueued_for_logged_out_admin(): void {
		wp_set_current_user( 0 );
		update_option( 'wpai_feature_webmcp_field_admin_abilities', 'core/get-post' );

		$experiment = new WebMCP();
		$experiment->register();
		$experiment->enqueue_admin_assets( 'index.php' );

		$this->assertFalse( wp_script_is( 'ai-webmcp', 'enqueued' ) );
	}

	/**
	 * Tests that the bridge is not enqueued on the front end when nothing is exposed to visitors.
	 */
	public function test_bridge_not_enqueued_on_front_end_without_visitor_abilities(): void {
		$experiment = new WebMCP();
		$experiment->register();
		$experiment->enqueue_front_end_assets();

		$this->assertFalse( wp_script_is( 'ai-webmcp', 'enqueued' ) );
	}
}
