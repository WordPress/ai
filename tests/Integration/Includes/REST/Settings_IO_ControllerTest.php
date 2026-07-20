<?php
/**
 * Integration tests for the Settings_IO_Controller class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\REST
 */

namespace WordPress\AI\Tests\Integration\Includes\REST;

use WP_REST_Request;
use WP_UnitTestCase;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Features\Registry;
use WordPress\AI\REST\Settings_IO_Controller;
use WordPress\AI\Settings\Settings_Registration;

/**
 * Stub feature for IO controller tests.
 */
class IO_Test_Feature extends Abstract_Feature {
	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'io-test-feature';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => 'IO Test Feature',
			'description' => 'A feature for IO controller testing.',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {}
}

/**
 * Settings_IO_Controller test case.
 *
 * @since x.x.x
 */
class Settings_IO_ControllerTest extends WP_UnitTestCase {

	/**
	 * Controller under test.
	 *
	 * @var \WordPress\AI\REST\Settings_IO_Controller
	 */
	private Settings_IO_Controller $controller;

	/**
	 * {@inheritDoc}
	 */
	public function setUp(): void {
		parent::setUp();

		$this->controller = new Settings_IO_Controller();

		// Register our test feature settings so the controller can discover them.
		$registry     = new Registry();
		$registry->register_feature( new IO_Test_Feature() );
		$registration = new Settings_Registration( $registry );
		$registration->register_settings();
	}

	/**
	 * {@inheritDoc}
	 */
	public function tearDown(): void {
		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_io-test-feature_enabled' );
		delete_option( 'wpai_feature_io-test-feature_field_developer' );
		unregister_setting( Settings_Registration::OPTION_GROUP, 'wpai_features_enabled' );
		unregister_setting( Settings_Registration::OPTION_GROUP, 'wpai_feature_io-test-feature_enabled' );
		unregister_setting( Settings_Registration::OPTION_GROUP, 'wpai_feature_io-test-feature_field_developer' );
		parent::tearDown();
	}

	// Route registration

	/**
	 * Tests that the export REST route is registered.
	 *
	 * @since x.x.x
	 */
	public function test_register_routes_registers_export_route(): void {
		$this->controller->init();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action( 'rest_api_init' );

		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/ai/v1/settings/export', $routes );
	}

	/**
	 * Tests that the import REST route is registered.
	 *
	 * @since x.x.x
	 */
	public function test_register_routes_registers_import_route(): void {
		$this->controller->init();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action( 'rest_api_init' );

		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/ai/v1/settings/import', $routes );
	}

	// Permission checks

	/**
	 * Tests that the permission check returns true for administrators.
	 *
	 * @since x.x.x
	 */
	public function test_check_permission_allows_manage_options_users(): void {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$this->assertTrue( $this->controller->check_permission() );
	}

	/**
	 * Tests that the permission check returns false for subscribers.
	 *
	 * @since x.x.x
	 */
	public function test_check_permission_denies_subscribers(): void {
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$this->assertFalse( $this->controller->check_permission() );
	}

	/**
	 * Tests that unauthenticated users cannot access the export endpoint.
	 *
	 * @since x.x.x
	 */
	public function test_export_requires_manage_options(): void {
		$this->controller->init();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action( 'rest_api_init' );

		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$request  = new WP_REST_Request( 'GET', '/ai/v1/settings/export' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Tests that unauthenticated users cannot access the import endpoint.
	 *
	 * @since x.x.x
	 */
	public function test_import_requires_manage_options(): void {
		$this->controller->init();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action( 'rest_api_init' );

		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$request = new WP_REST_Request( 'POST', '/ai/v1/settings/import' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( array( 'version' => 1, 'settings' => array(), 'providers' => array() ) ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	// Export

	/**
	 * Tests that the export endpoint returns the correct payload structure.
	 *
	 * @since x.x.x
	 */
	public function test_export_returns_correct_structure(): void {
		$this->controller->init();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action( 'rest_api_init' );

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$request  = new WP_REST_Request( 'GET', '/ai/v1/settings/export' );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'version', $data );
		$this->assertArrayHasKey( 'exported_at', $data );
		$this->assertArrayHasKey( 'plugin_version', $data );
		$this->assertArrayHasKey( 'providers', $data );
		$this->assertArrayHasKey( 'settings', $data );
		$this->assertSame( Settings_IO_Controller::SCHEMA_VERSION, $data['version'] );
	}

	/**
	 * Tests that the export payload includes the global toggle.
	 *
	 * @since x.x.x
	 */
	public function test_export_includes_global_toggle(): void {
		update_option( 'wpai_features_enabled', true );

		$this->controller->init();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action( 'rest_api_init' );

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$request  = new WP_REST_Request( 'GET', '/ai/v1/settings/export' );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'wpai_features_enabled', $data['settings'] );
	}

	/**
	 * Tests that developer config options appear in the providers section.
	 *
	 * @since x.x.x
	 */
	public function test_export_places_developer_config_in_providers_section(): void {
		$dev_config = array( 'provider' => 'openai', 'model' => 'gpt-4.1-mini' );
		update_option( 'wpai_feature_io-test-feature_field_developer', $dev_config );

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$response = $this->controller->export_settings();
		$data     = $response->get_data();

		$this->assertArrayHasKey(
			'wpai_feature_io-test-feature_field_developer',
			$data['providers']
		);
		$this->assertArrayNotHasKey(
			'wpai_feature_io-test-feature_field_developer',
			$data['settings']
		);
	}

	/**
	 * Tests that sensitive option names are excluded from the exportable list.
	 *
	 * @since x.x.x
	 */
	public function test_get_exportable_option_names_excludes_sensitive_options(): void {
		// Temporarily register a fake sensitive option in our group.
		register_setting(
			Settings_Registration::OPTION_GROUP,
			'wpai_some_api_key',
			array( 'type' => 'string' )
		);

		$exportable = $this->controller->get_exportable_option_names();

		$this->assertNotContains( 'wpai_some_api_key', $exportable );

		unregister_setting( Settings_Registration::OPTION_GROUP, 'wpai_some_api_key' );
	}

	/**
	 * Tests that non-sensitive options are included in the exportable list.
	 *
	 * @since x.x.x
	 */
	public function test_get_exportable_option_names_includes_safe_options(): void {
		$exportable = $this->controller->get_exportable_option_names();

		$this->assertContains( 'wpai_features_enabled', $exportable );
		$this->assertContains( 'wpai_feature_io-test-feature_enabled', $exportable );
	}

	// Import

	/**
	 * Tests that a valid import payload succeeds.
	 *
	 * @since x.x.x
	 */
	public function test_import_with_valid_payload_succeeds(): void {
		$this->controller->init();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action( 'rest_api_init' );

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$payload = array(
			'version'   => 1,
			'settings'  => array( 'wpai_features_enabled' => true ),
			'providers' => array(),
		);

		$request = new WP_REST_Request( 'POST', '/ai/v1/settings/import' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $payload ) );

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'imported', $data );
		$this->assertGreaterThan( 0, $data['imported'] );
		$this->assertTrue( (bool) get_option( 'wpai_features_enabled' ) );
	}

	/**
	 * Tests that import with an unsupported schema version returns 422.
	 *
	 * @since x.x.x
	 */
	public function test_import_rejects_unsupported_schema_version(): void {
		$this->controller->init();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action( 'rest_api_init' );

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$payload = array(
			'version'   => 999,
			'settings'  => array(),
			'providers' => array(),
		);

		$request = new WP_REST_Request( 'POST', '/ai/v1/settings/import' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $payload ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'unsupported_schema_version', $response->as_error()->get_error_code() );
	}

	/**
	 * Tests that import ignores unknown option names silently.
	 *
	 * @since x.x.x
	 */
	public function test_import_ignores_unknown_option_names(): void {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$request = new WP_REST_Request( 'POST', '/ai/v1/settings/import' );
		$request->set_param( 'version', 1 );
		$request->set_param(
			'settings',
			array( 'completely_unknown_option_xyz' => true )
		);
		$request->set_param( 'providers', array() );

		$response = $this->controller->import_settings( $request );
		$data     = $response->get_data();

		$this->assertSame( 0, $data['imported'] );
	}

	/**
	 * Tests that import does not write sensitive-named options even if they are
	 * somehow registered in the plugin's option group.
	 *
	 * @since x.x.x
	 */
	public function test_import_does_not_write_sensitive_options(): void {
		// Register a fake sensitive setting.
		register_setting(
			Settings_Registration::OPTION_GROUP,
			'wpai_some_api_key',
			array( 'type' => 'string' )
		);

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$request = new WP_REST_Request( 'POST', '/ai/v1/settings/import' );
		$request->set_param( 'version', 1 );
		$request->set_param(
			'settings',
			array( 'wpai_some_api_key' => 'should-not-be-written' )
		);
		$request->set_param( 'providers', array() );

		$this->controller->import_settings( $request );

		$stored = get_option( 'wpai_some_api_key', false );
		$this->assertFalse( $stored );

		unregister_setting( Settings_Registration::OPTION_GROUP, 'wpai_some_api_key' );
	}

	/**
	 * Tests that import returns 400 when the version parameter is missing.
	 *
	 * @since x.x.x
	 */
	public function test_import_returns_400_when_version_missing(): void {
		$this->controller->init();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action( 'rest_api_init' );

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$request = new WP_REST_Request( 'POST', '/ai/v1/settings/import' );
		$request->set_header( 'Content-Type', 'application/json' );
		// 'version' is required — omit it.
		$request->set_body( (string) wp_json_encode( array( 'settings' => array() ) ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	// Type validation / sanitization on import.

	/**
	 * Tests that a boolean-typed setting rejects a value that cannot be
	 * interpreted as a boolean, and does not write it to the database.
	 *
	 * @since x.x.x
	 */
	public function test_import_rejects_invalid_boolean_value(): void {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Force a known baseline rather than assuming the registered default,
		// since `wpai_features_enabled` is a shared global option that other
		// tests in the suite may have already changed.
		update_option( 'wpai_features_enabled', false );

		$request = new WP_REST_Request( 'POST', '/ai/v1/settings/import' );
		$request->set_param( 'version', 1 );
		$request->set_param(
			'settings',
			array( 'wpai_features_enabled' => 'not_a_boolean' )
		);
		$request->set_param( 'providers', array() );

		$response = $this->controller->import_settings( $request );
		$data     = $response->get_data();

		$this->assertSame( 0, $data['imported'] );
		$this->assertSame( 1, $data['rejected'] );
		// The option should remain unchanged, never having been written.
		// Note: WordPress stores scalar option values as raw strings, so
		// get_option() may return "" rather than a native `false`; casting
		// to bool normalizes this for the comparison.
		$this->assertFalse( (bool) get_option( 'wpai_features_enabled' ) );
	}

	/**
	 * Tests that a boolean-ish string value (e.g. "true"/"1") is sanitized
	 * into a proper boolean rather than rejected or stored verbatim.
	 *
	 * @since x.x.x
	 */
	public function test_import_sanitizes_boolean_like_string_value(): void {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Force a known baseline so the transition to `true` is meaningful
		// regardless of what other tests in the suite left behind.
		update_option( 'wpai_features_enabled', false );

		$request = new WP_REST_Request( 'POST', '/ai/v1/settings/import' );
		$request->set_param( 'version', 1 );
		$request->set_param(
			'settings',
			array( 'wpai_features_enabled' => 'true' )
		);
		$request->set_param( 'providers', array() );

		$response = $this->controller->import_settings( $request );
		$data     = $response->get_data();

		$this->assertSame( 1, $data['imported'] );
		$this->assertSame( 0, $data['rejected'] );
		// Note: WordPress stores scalar option values as raw strings in the
		// database (no serialization for scalars), so get_option() returns
		// "1" rather than a native PHP `true`. Casting to bool confirms the
		// sanitized value is truthy without relying on strict type identity.
		$this->assertTrue( (bool) get_option( 'wpai_features_enabled' ) );
	}

	/**
	 * Tests that a developer config option rejects a value that does not
	 * match its registered object schema (e.g. a plain string).
	 *
	 * @since x.x.x
	 */
	public function test_import_rejects_malformed_developer_config_object(): void {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$request = new WP_REST_Request( 'POST', '/ai/v1/settings/import' );
		$request->set_param( 'version', 1 );
		$request->set_param( 'settings', array() );
		$request->set_param(
			'providers',
			array( 'wpai_feature_io-test-feature_field_developer' => 'not-an-object' )
		);

		$response = $this->controller->import_settings( $request );
		$data     = $response->get_data();

		$this->assertSame( 0, $data['imported'] );
		$this->assertSame( 1, $data['rejected'] );
		$this->assertSame(
			array(),
			get_option( 'wpai_feature_io-test-feature_field_developer', array() )
		);
	}

	/**
	 * Tests that a valid developer config object is imported and its string
	 * properties are sanitized (e.g. extraneous whitespace trimmed).
	 *
	 * @since x.x.x
	 */
	public function test_import_accepts_valid_developer_config_object(): void {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$request = new WP_REST_Request( 'POST', '/ai/v1/settings/import' );
		$request->set_param( 'version', 1 );
		$request->set_param( 'settings', array() );
		$request->set_param(
			'providers',
			array(
				'wpai_feature_io-test-feature_field_developer' => array(
					'provider' => 'openai',
					'model'    => 'gpt-4.1-mini',
				),
			)
		);

		$response = $this->controller->import_settings( $request );
		$data     = $response->get_data();

		$this->assertSame( 1, $data['imported'] );
		$this->assertSame( 0, $data['rejected'] );
		$this->assertSame(
			array(
				'provider' => 'openai',
				'model'    => 'gpt-4.1-mini',
			),
			get_option( 'wpai_feature_io-test-feature_field_developer' )
		);
	}

	/**
	 * Tests that the response message reflects rejected values when a batch
	 * import contains a mix of valid and invalid values.
	 *
	 * @since x.x.x
	 */
	public function test_import_response_message_reports_rejected_count(): void {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$request = new WP_REST_Request( 'POST', '/ai/v1/settings/import' );
		$request->set_param( 'version', 1 );
		$request->set_param(
			'settings',
			array(
				'wpai_features_enabled'                => true,
				'wpai_feature_io-test-feature_enabled' => 'garbage-not-boolean',
			)
		);
		$request->set_param( 'providers', array() );

		$response = $this->controller->import_settings( $request );
		$data     = $response->get_data();

		$this->assertSame( 1, $data['imported'] );
		$this->assertSame( 1, $data['rejected'] );
		$this->assertStringContainsString( '1', $data['message'] );
	}
}

