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
	 * Abilities registered by a test, unregistered on teardown.
	 *
	 * @var list<string>
	 */
	private array $registered = array();

	/**
	 * Cleans up.
	 */
	public function tearDown(): void {
		foreach ( $this->registered as $name ) {
			wp_unregister_ability( $name );
		}
		$this->registered = array();
		wp_set_current_user( 0 );
		delete_option( 'wpai_feature_webmcp_enabled' );
		delete_option( 'wpai_feature_webmcp_field_admin_abilities' );
		delete_option( 'wpai_feature_webmcp_field_visitor_abilities' );
		remove_all_filters( 'wpai_webmcp_exposed_abilities' );
		remove_all_actions( 'wpai_register_features' );
		wp_dequeue_script( 'ai_webmcp' );
		wp_deregister_script( 'ai_webmcp' );
		parent::tearDown();
	}

	/**
	 * Registers a read-only test ability inside the abilities init context.
	 *
	 * Core's own abilities are not registered in the PHPUnit context, so the
	 * enqueue tests bring their own.
	 *
	 * @param string $name Ability name.
	 */
	private function register_ability( string $name ): void {
		global $wp_current_filter;

		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register a test ability.

		try {
			wp_register_ability(
				$name,
				array(
					'label'               => 'Test ' . $name,
					'description'         => 'Test ability ' . $name,
					'category'            => WPAI_DEFAULT_ABILITY_CATEGORY,
					'execute_callback'    => '__return_true',
					'permission_callback' => '__return_true',
				)
			);
		} finally {
			array_pop( $wp_current_filter );
		}

		$this->registered[] = $name;
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
		$this->register_ability( 'webmcp-test/admin-tool' );
		update_option( 'wpai_feature_webmcp_field_admin_abilities', 'webmcp-test/admin-tool' );

		$experiment = new WebMCP();
		$experiment->register();
		$experiment->enqueue_admin_assets( 'index.php' );

		$this->assertFalse( wp_script_is( 'ai_webmcp', 'enqueued' ) );
	}

	/**
	 * Tests that a logged-in user on a wp-admin screen gets the bridge and its data when something is exposed.
	 *
	 * CI builds the scripts before the PHP tests run, so the asset file exists here.
	 */
	public function test_bridge_enqueued_for_logged_in_admin_with_exposed_ability(): void {
		if ( ! file_exists( WPAI_PLUGIN_DIR . 'build-scripts/experiments/webmcp.asset.php' ) ) {
			$this->markTestSkipped( 'Scripts are not built.' );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->register_ability( 'webmcp-test/admin-tool' );
		update_option( 'wpai_feature_webmcp_field_admin_abilities', 'webmcp-test/admin-tool' );

		$experiment = new WebMCP();
		$experiment->register();
		$experiment->enqueue_admin_assets( 'index.php' );

		$this->assertTrue( wp_script_is( 'ai_webmcp', 'enqueued' ) );

		$inline = wp_scripts()->get_data( 'ai_webmcp', 'before' );
		$this->assertIsArray( $inline );
		// wp_json_encode() escapes slashes, so compare on the unescaped text.
		$printed = str_replace( '\\/', '/', implode( "\n", array_filter( $inline, 'is_string' ) ) );
		$this->assertStringContainsString( 'window.aiWebMCP=', $printed );
		$this->assertStringContainsString( '"context":"admin"', $printed );
		$this->assertStringContainsString( '/ai/v1/webmcp/execute', $printed );
	}

	/**
	 * Tests that the front end gets the bridge once a visitor ability is listed.
	 */
	public function test_bridge_enqueued_on_front_end_with_visitor_ability(): void {
		if ( ! file_exists( WPAI_PLUGIN_DIR . 'build-scripts/experiments/webmcp.asset.php' ) ) {
			$this->markTestSkipped( 'Scripts are not built.' );
		}

		$this->register_ability( 'webmcp-test/visitor-tool' );
		update_option( 'wpai_feature_webmcp_field_visitor_abilities', 'webmcp-test/visitor-tool' );

		$experiment = new WebMCP();
		$experiment->register();
		$experiment->enqueue_front_end_assets();

		$this->assertTrue( wp_script_is( 'ai_webmcp', 'enqueued' ) );
	}

	/**
	 * Tests that the bridge is not enqueued on the front end when nothing is exposed to visitors.
	 */
	public function test_bridge_not_enqueued_on_front_end_without_visitor_abilities(): void {
		$experiment = new WebMCP();
		$experiment->register();
		$experiment->enqueue_front_end_assets();

		$this->assertFalse( wp_script_is( 'ai_webmcp', 'enqueued' ) );
	}
}
