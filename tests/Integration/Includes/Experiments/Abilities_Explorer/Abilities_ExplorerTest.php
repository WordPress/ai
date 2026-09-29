<?php
/**
 * Integration tests for the Abilities_Explorer experiment class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Abilities_Explorer
 */

namespace WordPress\AI\Tests\Integration\Experiments\Abilities_Explorer;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Abilities_Explorer\Abilities_Explorer;
use WordPress\AI\Experiments\Abilities_Explorer\REST\Abilities_Controller;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Features\Loader;
use WordPress\AI\Features\Registry;

/**
 * Abilities_Explorer test case.
 *
 * @since 0.2.0
 */
class Abilities_ExplorerTest extends WP_UnitTestCase {
	/**
	 * Experiment registry instance.
	 *
	 * @var \WordPress\AI\Features\Registry
	 */
	private $registry;

	/**
	 * Experiment loader instance.
	 *
	 * @var \WordPress\AI\Features\Loader
	 */
	private $loader;

	/**
	 * Set up test case.
	 *
	 * @since 0.2.0
	 */
	public function setUp(): void {
		parent::setUp();

		// Set up mock AI credentials so has_ai_credentials() returns true.
		update_option( 'wp_ai_client_provider_credentials', array( 'openai' => 'test-api-key' ) );

		// Mock has_valid_ai_credentials to return true for tests.
		add_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );

		// Enable experiments globally and individually.
		update_option( 'wpai_features_enabled', true );
		update_option( 'wpai_feature_abilities-explorer_enabled', true );

		$this->registry = new Registry();
		$this->loader   = new Loader( $this->registry );
	}

	/**
	 * Tear down test case.
	 *
	 * @since 0.2.0
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_abilities-explorer_enabled' );
		delete_option( 'wp_ai_client_provider_credentials' );
		remove_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );
		parent::tearDown();
	}

	/**
	 * Test that the experiment has correct metadata.
	 *
	 * @since 0.2.0
	 */
	public function test_experiment_metadata() {
		$experiment = new Abilities_Explorer();

		$this->assertEquals( 'abilities-explorer', $experiment->get_id() );
		$this->assertEquals( 'Abilities Explorer', $experiment->get_label() );
		$this->assertNotEmpty( $experiment->get_description() );
		$this->assertEquals( Experiment_Category::ADMIN, $experiment->get_category() );
	}

	/**
	 * Test that the experiment is enabled when option is set.
	 *
	 * @since 0.2.0
	 */
	public function test_experiment_is_enabled_when_option_set() {
		$experiment = new Abilities_Explorer();

		$this->assertTrue( $experiment->is_enabled() );
	}

	/**
	 * Test that the experiment is disabled when option is not set.
	 *
	 * @since 0.2.0
	 */
	public function test_experiment_is_disabled_when_option_not_set() {
		delete_option( 'wpai_feature_abilities-explorer_enabled' );

		$experiment = new Abilities_Explorer();

		$this->assertFalse( $experiment->is_enabled() );
	}

	/**
	 * Registering the experiment hooks no global asset loader.
	 *
	 * Assets are hooked from the Explorer screen's own `load-{hook}` action,
	 * so no `admin_enqueue_scripts` callback exists to fire on other pages.
	 *
	 * @since 0.2.0
	 * @since x.x.x Asserts on the screen-scoped hook instead of the removed global one.
	 */
	public function test_assets_not_enqueued_on_wrong_page() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$experiment = new Abilities_Explorer();
		$experiment->register();

		$this->assertFalse( has_action( 'admin_enqueue_scripts', array( $experiment, 'enqueue_assets' ) ) );
		$this->assertFalse( wp_script_is( 'ai_abilities_explorer', 'registered' ) );
	}

	/**
	 * Test that the experiment can be registered in the registry.
	 *
	 * @since 0.2.0
	 */
	public function test_experiment_registration_in_registry() {
		$experiment = new Abilities_Explorer();
		$this->registry->register_feature( $experiment );

		$this->assertTrue( $this->registry->has_feature( 'abilities-explorer' ) );

		$registered = $this->registry->get_feature( 'abilities-explorer' );
		$this->assertInstanceOf( Abilities_Explorer::class, $registered );
	}

	/**
	 * The REST routes are registered while the experiment is on.
	 *
	 * @since x.x.x
	 */
	public function test_rest_routes_are_registered_when_enabled() {
		$this->assertSame( $this->explorer_routes_after_boot(), $this->expected_routes() );
	}

	/**
	 * The REST routes do not exist while the experiment is off.
	 *
	 * @since x.x.x
	 */
	public function test_rest_routes_are_absent_when_disabled() {
		delete_option( 'wpai_feature_abilities-explorer_enabled' );

		$this->assertSame( array(), $this->explorer_routes_after_boot() );
	}

	/**
	 * Boots the loader on a fresh REST server and returns the Explorer routes it holds.
	 *
	 * @since x.x.x
	 *
	 * @return list<string> The registered Explorer routes, sorted.
	 */
	private function explorer_routes_after_boot(): array {
		global $wp_rest_server;

		$previous = $wp_rest_server;

		try {
			$this->loader->init();

			$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A fresh server, restored below.
			do_action( 'rest_api_init', $wp_rest_server ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.

			$routes = array_values( array_intersect( array_keys( $wp_rest_server->get_routes() ), $this->expected_routes() ) );
		} finally {
			$wp_rest_server = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the server.
		}

		sort( $routes );

		return $routes;
	}

	/**
	 * Returns the Explorer routes as the REST server keys them, sorted.
	 *
	 * @since x.x.x
	 *
	 * @return list<string> The routes.
	 */
	private function expected_routes(): array {
		$routes = array(
			'/' . Abilities_Controller::ABILITIES_ROUTE,
			'/' . Abilities_Controller::ITEM_ROUTE,
			'/' . Abilities_Controller::INVOKE_ROUTE,
			'/' . Abilities_Controller::SURFACE_ROUTE,
		);

		sort( $routes );

		return $routes;
	}
}
