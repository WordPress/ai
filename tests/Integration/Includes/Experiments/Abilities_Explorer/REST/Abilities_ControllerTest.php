<?php
/**
 * Integration tests for the Abilities Explorer REST controller.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Abilities_Explorer\REST
 */

namespace WordPress\AI\Tests\Integration\Experiments\Abilities_Explorer\REST;

use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;
use WordPress\AI\Experiments\AI_Workspace\Tool_Policy;
use WordPress\AI\Experiments\AI_Workspace\Tool_Selector;
use WordPress\AI\Experiments\Abilities_Explorer\REST\Abilities_Controller;
use WordPress\AI\Features\Loader;
use WordPress\AI\Features\Registry;
use WordPress\AI\Logging\AI_Request_Log_Schema;

/**
 * Abilities_Controller test case.
 *
 * Cookie authentication is simulated by setting `$GLOBALS['wp_rest_auth_cookie']`
 * to `true`, which is what core's `rest_cookie_collect_status()` leaves behind
 * once a valid logged-in cookie was seen. `dispatch()` skips the authentication
 * pass `serve_request()` runs, so the nonce half of cookie authentication is
 * core's to test, not this file's.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Experiments\Abilities_Explorer\REST\Abilities_Controller
 */
class Abilities_ControllerTest extends WP_UnitTestCase {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private int $admin_id;

	/**
	 * Fixture ability names registered by the current test.
	 *
	 * @var list<string>
	 */
	private array $registered = array();

	/**
	 * Fixture category slugs registered by the current test.
	 *
	 * @var list<string>
	 */
	private array $categories = array();

	/**
	 * Inputs the fixture abilities were executed with, in order.
	 *
	 * @var list<mixed>
	 */
	private array $executions = array();

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		add_filter( 'wpai_workspace_tool_admission_enabled', '__return_true' );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$controller = new Abilities_Controller();
		add_action( 'rest_api_init', array( $controller, 'register_routes' ) );
		do_action( 'rest_api_init', rest_get_server() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.

		$this->as_cookie_admin();
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		remove_filter( 'wpai_workspace_tool_admission_enabled', '__return_true' );

		foreach ( $this->registered as $name ) {
			if ( ! wp_has_ability( $name ) ) {
				continue;
			}

			wp_unregister_ability( $name );
		}

		foreach ( $this->categories as $slug ) {
			if ( ! wp_has_ability_category( $slug ) ) {
				continue;
			}

			wp_unregister_ability_category( $slug );
		}

		$this->registered = array();
		$this->categories = array();
		$this->executions = array();

		unset( $GLOBALS['wp_rest_auth_cookie'], $GLOBALS['wp_rest_application_password_uuid'] );

		delete_option( Tool_Policy::OWNER_EXCLUSIONS_OPTION );
		delete_option( Tool_Policy::POLICY_DISABLED_OPTION );

		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/*
	 * ---------------------------------------------------------------------
	 * List route.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The list holds every registered ability, including one not opted into REST.
	 *
	 * @since x.x.x
	 */
	public function test_list_includes_an_ability_not_shown_in_rest(): void {
		$slug = $this->register_fixture( 'wpai-test/rest-hidden', array( 'meta' => array( 'show_in_rest' => false ) ) );

		$response = $this->dispatch( 'GET', Abilities_Controller::ABILITIES_ROUTE );

		$this->assertSame( 200, $response->get_status() );

		$data  = $response->get_data();
		$slugs = array_column( $data['items'], 'slug' );

		$this->assertContains( $slug, $slugs, 'An ability that did not opt into REST must still be listed.' );
		$this->assertCount( count( wp_get_abilities() ), $data['items'], 'Every registered ability must be listed.' );
		$this->assertIsInt( $data['sequence'] );
		$this->assertArrayHasKey( 'disabled', $data['policy'] );

		$row = $this->row( $data['items'], $slug );

		$this->assertFalse( $row['show_in_rest'] );
		$this->assertArrayNotHasKey( 'input_schema', $row, 'Schemas come only from the item route.' );
		$this->assertArrayNotHasKey( 'raw_data', $row, 'Raw data comes only from the item route.' );
		$this->assertArrayHasKey( 'meta', $row );
		$this->assertSame( array(), $row['unencodable_fields'] );
	}

	/**
	 * Origin and provider stay separate, and the category label arrives unescaped.
	 *
	 * @since x.x.x
	 */
	public function test_list_keeps_origin_and_provider_apart_and_sends_the_category_label_unescaped(): void {
		$category = $this->register_category( 'wpai-test-picks', "Editor's picks & more" );

		$slug = $this->register_fixture(
			'wpai-test/custom-provider',
			array(
				'category' => $category,
				'meta'     => array( 'provider' => 'My Custom Plugin' ),
			)
		);

		$row = $this->row( $this->dispatch( 'GET', Abilities_Controller::ABILITIES_ROUTE )->get_data()['items'], $slug );

		$this->assertSame( 'Plugin', $row['origin'] );
		$this->assertSame( 'My Custom Plugin', $row['provider'] );
		$this->assertSame( "Editor's picks & more", $row['category'] );
	}

	/**
	 * A category label a filter escaped still arrives as plain text.
	 *
	 * @since x.x.x
	 */
	public function test_list_decodes_a_category_label_escaped_by_a_filter(): void {
		$slug = $this->register_fixture( 'wpai-test/escaped-category' );

		$filter = static function ( $category, $name ) use ( $slug ) {
			return $name === $slug ? esc_html( "Editor's picks" ) : $category;
		};
		add_filter( 'wpai_ability_category', $filter, 10, 2 );

		$row = $this->row( $this->dispatch( 'GET', Abilities_Controller::ABILITIES_ROUTE )->get_data()['items'], $slug );

		remove_filter( 'wpai_ability_category', $filter, 10 );

		$this->assertSame( "Editor's picks", $row['category'] );
	}

	/**
	 * One ability whose meta cannot be encoded does not take the list down.
	 *
	 * @since x.x.x
	 */
	public function test_list_survives_an_ability_with_unencodable_meta(): void {
		$good = $this->register_fixture( 'wpai-test/encodable' );
		$bad  = $this->register_fixture( 'wpai-test/unencodable', array( 'meta' => array( 'note' => "bad \xB1\x31 bytes" ) ) );

		$response = $this->dispatch( 'GET', Abilities_Controller::ABILITIES_ROUTE );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();

		$this->assertNotFalse( json_encode( $data ), 'The whole list payload must encode strictly.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Strict encode, without core's repair pass, is the point.

		$bad_row  = $this->row( $data['items'], $bad );
		$good_row = $this->row( $data['items'], $good );

		$this->assertNull( $bad_row['meta'], 'The field that failed to encode must be null.' );
		$this->assertSame( array( 'meta' ), $bad_row['unencodable_fields'], 'The null must be flagged so the screen can say why.' );
		$this->assertSame( 'wpai-test/unencodable', $bad_row['slug'], 'The rest of the bad row must survive.' );
		$this->assertSame( array(), $good_row['unencodable_fields'] );
		$this->assertIsArray( $good_row['meta'] );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Item route.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The item route sends schemas, raw data and example input.
	 *
	 * @since x.x.x
	 */
	public function test_item_returns_schemas_and_example_input(): void {
		$slug = $this->register_fixture(
			'wpai-test/item',
			array(
				'input_schema'  => array(
					'type'       => 'object',
					'properties' => array(
						'title' => array(
							'type'    => 'string',
							'default' => 'Hello',
						),
					),
				),
				'output_schema' => array( 'type' => 'string' ),
			)
		);

		$response = $this->dispatch( 'GET', Abilities_Controller::ITEM_ROUTE, array( 'name' => $slug ) );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();

		$this->assertSame( $slug, $data['slug'] );
		$this->assertSame( 'object', $data['input_schema']['type'] );
		$this->assertSame( array( 'type' => 'string' ), $data['output_schema'] );
		$this->assertSame( $slug, $data['raw_data']['name'] );
		$this->assertSame( array( 'title' => 'Hello' ), $data['example_input'] );
		$this->assertArrayHasKey( 'meta', $data );
	}

	/**
	 * The item route answers 404 for an unregistered name.
	 *
	 * @since x.x.x
	 */
	public function test_item_returns_404_for_an_unregistered_name(): void {
		$response = $this->dispatch( 'GET', Abilities_Controller::ITEM_ROUTE, array( 'name' => 'wpai-test/never-registered' ) );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * The item route answers 400 when the name is missing.
	 *
	 * @since x.x.x
	 */
	public function test_item_returns_400_for_a_missing_name(): void {
		$response = $this->dispatch( 'GET', Abilities_Controller::ITEM_ROUTE );

		$this->assertSame( 400, $response->get_status() );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Invoke route.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Valid input runs the ability and returns its data.
	 *
	 * @since x.x.x
	 */
	public function test_invoke_with_valid_input_returns_the_data(): void {
		$slug = $this->register_echo_fixture( 'wpai-test/echo', $this->message_schema() );

		$response = $this->invoke( $slug, '{"message":"hello"}' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'success' => true,
				'data'    => array( 'message' => 'hello' ),
			),
			$response->get_data()
		);
	}

	/**
	 * Input the Explorer's own validation rejects returns 400 with the messages.
	 *
	 * @since x.x.x
	 */
	public function test_invoke_with_input_failing_explorer_validation_returns_400(): void {
		$slug = $this->register_echo_fixture( 'wpai-test/echo-required', $this->message_schema() );

		$response = $this->invoke( $slug, '{}' );

		$this->assertSame( 400, $response->get_status() );

		$data = $response->get_data();

		$this->assertSame( array( 'Required field "message" is missing' ), $data['data']['errors'] );
		$this->assertSame( array(), $this->executions, 'Rejected input must never reach the ability.' );
	}

	/**
	 * Input that passes the Explorer but fails core's schema check is the ability's outcome.
	 *
	 * @since x.x.x
	 */
	public function test_invoke_with_input_failing_core_validation_returns_the_ability_error(): void {
		$schema = $this->message_schema();

		$schema['properties']['message']['minLength'] = 10;

		$slug = $this->register_echo_fixture( 'wpai-test/echo-min-length', $schema );

		$response = $this->invoke( $slug, '{"message":"short"}' );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();

		$this->assertFalse( $data['success'] );
		$this->assertSame( 'ability_invalid_input', $data['error']['code'] );
		$this->assertNotEmpty( $data['error']['message'] );
		$this->assertArrayHasKey( 'data', $data['error'] );
		$this->assertArrayNotHasKey( 'trace', $data );
	}

	/**
	 * An empty string and a JSON null both mean "no input".
	 *
	 * @since x.x.x
	 */
	public function test_invoke_without_an_input_schema_accepts_empty_and_null(): void {
		$slug = $this->register_echo_fixture( 'wpai-test/no-input', array() );

		foreach ( array( '', 'null' ) as $raw ) {
			$response = $this->invoke( $slug, $raw );

			$this->assertSame( 200, $response->get_status(), sprintf( 'Input %s must invoke with no input.', wp_json_encode( $raw ) ) );
			$this->assertTrue( $response->get_data()['success'], sprintf( 'Input %s must succeed.', wp_json_encode( $raw ) ) );
		}

		$this->assertSame( array( null, null ), $this->executions );
	}

	/**
	 * An omitted input is the same as an empty one.
	 *
	 * @since x.x.x
	 */
	public function test_invoke_with_input_omitted_invokes_with_no_input(): void {
		$slug = $this->register_echo_fixture( 'wpai-test/no-input-omitted', array() );

		$response = $this->dispatch( 'POST', Abilities_Controller::INVOKE_ROUTE, array( 'name' => $slug ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );
	}

	/**
	 * Scalar input is decoded on the server and reaches the ability as an integer.
	 *
	 * @since x.x.x
	 */
	public function test_invoke_decodes_scalar_input_on_the_server(): void {
		$slug = $this->register_echo_fixture( 'wpai-test/integer', array( 'type' => 'integer' ) );

		$response = $this->invoke( $slug, '42' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );
		$this->assertSame( array( 42 ), $this->executions );
	}

	/**
	 * Malformed JSON is a route-level problem.
	 *
	 * @since x.x.x
	 */
	public function test_invoke_with_malformed_json_returns_400(): void {
		$slug = $this->register_echo_fixture( 'wpai-test/malformed', $this->message_schema() );

		$response = $this->invoke( $slug, '{' );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( array(), $this->executions );
	}

	/**
	 * Input must arrive as a string, not a pre-decoded value.
	 *
	 * @since x.x.x
	 */
	public function test_invoke_refuses_non_string_input(): void {
		$slug = $this->register_echo_fixture( 'wpai-test/non-string', $this->message_schema() );

		$response = $this->dispatch(
			'POST',
			Abilities_Controller::INVOKE_ROUTE,
			array(
				'name'  => $slug,
				'input' => array( 'message' => 'hello' ),
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( array(), $this->executions );
	}

	/**
	 * An unregistered ability is a 404.
	 *
	 * @since x.x.x
	 */
	public function test_invoke_returns_404_for_an_unregistered_ability(): void {
		$response = $this->invoke( 'wpai-test/never-registered', '' );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * The ability's own permission check still runs, and its denial is the ability's outcome.
	 *
	 * @since x.x.x
	 */
	public function test_invoke_reports_the_ability_permission_denial_as_its_outcome(): void {
		$slug = $this->register_fixture(
			'wpai-test/denied',
			array(
				'execute_callback'    => function () {
					$this->executions[] = 'ran';
					return true;
				},
				'permission_callback' => '__return_false',
			)
		);

		$response = $this->invoke( $slug, '' );

		$this->assertSame( 200, $response->get_status(), 'An ability refusing the admin is not a route-level 403.' );

		$data = $response->get_data();

		$this->assertFalse( $data['success'] );
		$this->assertSame( 'ability_invalid_permissions', $data['error']['code'] );
		$this->assertSame( array(), $this->executions );
	}

	/**
	 * Invoking writes no AI request log row.
	 *
	 * @since x.x.x
	 */
	public function test_invoke_writes_no_request_log_row(): void {
		global $wpdb;

		$schema = new AI_Request_Log_Schema();
		$schema->maybe_create_table();
		$table = $schema->get_table_name();

		$slug = $this->register_echo_fixture( 'wpai-test/unlogged', $this->message_schema() );

		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$response = $this->invoke( $slug, '{"message":"hello"}' );

		$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertTrue( $response->get_data()['success'] );
		$this->assertSame( $before, $after );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Surface route.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Remove stores the exclusion and returns the updated row; a repeat is "no change".
	 *
	 * @since x.x.x
	 */
	public function test_surface_remove_stores_the_exclusion_and_a_repeat_is_no_change(): void {
		$slug = $this->register_declared_fixture( 'wpai-test/surface-remove' );

		$response = $this->surface( 'remove', $slug );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();

		$this->assertTrue( $data['changed'] );
		$this->assertSame( 'remove', $data['change'] );
		$this->assertSame( $slug, $data['item']['slug'] );
		$this->assertTrue( $data['item']['owner_excluded'] );
		$this->assertIsInt( $data['sequence'] );
		$this->assertContains( $slug, get_option( Tool_Policy::OWNER_EXCLUSIONS_OPTION ) );

		$repeat = $this->surface( 'remove', $slug );

		$this->assertSame( 200, $repeat->get_status(), 'Already removed (another tab) counts as success.' );
		$this->assertFalse( $repeat->get_data()['changed'] );
		$this->assertTrue( $repeat->get_data()['item']['owner_excluded'] );
		$this->assertGreaterThanOrEqual( $data['sequence'], $repeat->get_data()['sequence'] );
	}

	/**
	 * Restore clears the stored exclusion.
	 *
	 * @since x.x.x
	 */
	public function test_surface_restore_clears_the_exclusion(): void {
		$slug = $this->register_declared_fixture( 'wpai-test/surface-restore' );

		( new Tool_Policy() )->exclude_from_surface( $slug );

		$response = $this->surface( 'restore', $slug );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['changed'] );
		$this->assertFalse( $response->get_data()['item']['owner_excluded'] );
		$this->assertNotContains( $slug, get_option( Tool_Policy::OWNER_EXCLUSIONS_OPTION, array() ) );
	}

	/**
	 * Restoring a never-admitted ability clears the exclusion but keeps it off the assistant.
	 *
	 * @since x.x.x
	 */
	public function test_surface_restore_of_a_withheld_ability_keeps_it_off_the_assistant(): void {
		$this->require_filtered_discovery();

		$slug = $this->register_declared_fixture( 'wpai-test/surface-withheld' );

		add_filter(
			'wpai_workspace_withheld_abilities',
			static function ( $withheld ) use ( $slug ) {
				$withheld[] = $slug;
				return $withheld;
			}
		);

		( new Tool_Policy() )->exclude_from_surface( $slug );

		$data = $this->surface( 'restore', $slug )->get_data();

		$this->assertFalse( ( new Tool_Policy() )->is_owner_excluded( $slug ) );
		$this->assertFalse( $data['item']['conversational_surface'] );
		$this->assertSame( Tool_Policy::REASON_WITHHELD, $data['item']['surface_reason'] );
	}

	/**
	 * The policy switch works both ways and answers with the full list.
	 *
	 * @since x.x.x
	 */
	public function test_surface_policy_switch_disables_and_enables_and_returns_the_list(): void {
		$disable = $this->surface( 'disable_policy' );

		$this->assertSame( 200, $disable->get_status() );
		$this->assertTrue( ( new Tool_Policy() )->is_policy_disabled() );
		$this->assertTrue( $disable->get_data()['policy']['disabled'] );
		$this->assertCount( count( wp_get_abilities() ), $disable->get_data()['items'] );
		$this->assertArrayNotHasKey( 'item', $disable->get_data() );

		$enable = $this->surface( 'enable_policy' );

		$this->assertSame( 200, $enable->get_status() );
		$this->assertFalse( ( new Tool_Policy() )->is_policy_disabled() );
		$this->assertFalse( $enable->get_data()['policy']['disabled'] );

		$again = $this->surface( 'enable_policy' );

		$this->assertSame( 200, $again->get_status(), 'Already on counts as success.' );
		$this->assertFalse( $again->get_data()['changed'] );
	}

	/**
	 * An unknown change is refused and changes nothing.
	 *
	 * @since x.x.x
	 */
	public function test_surface_refuses_an_unknown_change(): void {
		$slug = $this->register_declared_fixture( 'wpai-test/surface-unknown' );

		$response = $this->surface( 'obliterate', $slug );

		$this->assertSame( 400, $response->get_status() );
		$this->assertOptionsUntouched();
	}

	/**
	 * Remove refuses an unregistered or missing name.
	 *
	 * @since x.x.x
	 */
	public function test_surface_remove_refuses_an_unregistered_or_missing_name(): void {
		$this->assertSame( 404, $this->surface( 'remove', 'wpai-test/never-registered' )->get_status() );
		$this->assertSame( 400, $this->surface( 'remove' )->get_status() );
		$this->assertOptionsUntouched();
	}

	/**
	 * A GET to the surface route changes nothing.
	 *
	 * @since x.x.x
	 */
	public function test_surface_get_changes_nothing(): void {
		$slug = $this->register_declared_fixture( 'wpai-test/surface-get' );

		$response = $this->dispatch(
			'GET',
			Abilities_Controller::SURFACE_ROUTE,
			array(
				'change' => 'remove',
				'name'   => $slug,
			)
		);

		$this->assertNotSame( 200, $response->get_status() );
		$this->assertOptionsUntouched();

		$response = $this->dispatch( 'GET', Abilities_Controller::SURFACE_ROUTE, array( 'change' => 'disable_policy' ) );

		$this->assertNotSame( 200, $response->get_status() );
		$this->assertOptionsUntouched();
	}

	/*
	 * ---------------------------------------------------------------------
	 * Trust boundary.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * An editor is refused on every route.
	 *
	 * @since x.x.x
	 */
	public function test_editor_is_refused_on_every_route(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertRefusedEverywhere( 'An editor' );
	}

	/**
	 * An administrator's application password, with no cookie, is refused on every route.
	 *
	 * @since x.x.x
	 */
	public function test_application_password_is_refused_on_every_route(): void {
		unset( $GLOBALS['wp_rest_auth_cookie'] );
		$GLOBALS['wp_rest_application_password_uuid'] = wp_generate_uuid4(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core's own authentication global, set as core would.

		$this->assertRefusedEverywhere( 'An application password' );
	}

	/**
	 * An application password is refused even if the cookie flag is also set.
	 *
	 * @since x.x.x
	 */
	public function test_application_password_is_refused_even_alongside_the_cookie_flag(): void {
		$GLOBALS['wp_rest_application_password_uuid'] = wp_generate_uuid4(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core's own authentication global, set as core would.

		$this->assertRefusedEverywhere( 'An application password alongside a cookie' );
	}

	/**
	 * An administrator set by a `determine_current_user` filter, with no cookie, is refused.
	 *
	 * This is how JWT, OAuth and Basic Auth plugins authenticate, and core's
	 * nonce check never applies to them.
	 *
	 * @since x.x.x
	 */
	public function test_determine_current_user_without_a_cookie_is_refused_on_every_route(): void {
		unset( $GLOBALS['wp_rest_auth_cookie'] );

		$admin_id = $this->admin_id;
		$filter   = static function () use ( $admin_id ) {
			return $admin_id;
		};

		wp_set_current_user( 0 );
		$GLOBALS['current_user'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Forces core to resolve the user through the filter again.
		add_filter( 'determine_current_user', $filter, 100 );

		$this->assertSame( $admin_id, get_current_user_id(), 'The filter must authenticate the administrator for the refusal to mean anything.' );
		$this->assertTrue( current_user_can( 'manage_options' ) );

		$this->assertRefusedEverywhere( 'A determine_current_user administrator' );

		remove_filter( 'determine_current_user', $filter, 100 );
	}

	/**
	 * A cookie-authenticated administrator reaches every route.
	 *
	 * The positive control for the refusals above.
	 *
	 * @since x.x.x
	 */
	public function test_cookie_administrator_reaches_every_route(): void {
		$slug = $this->register_echo_fixture( 'wpai-test/control', array() );

		$this->assertSame( 200, $this->dispatch( 'GET', Abilities_Controller::ABILITIES_ROUTE )->get_status() );
		$this->assertSame( 200, $this->dispatch( 'GET', Abilities_Controller::ITEM_ROUTE, array( 'name' => $slug ) )->get_status() );
		$this->assertSame( 200, $this->invoke( $slug, '' )->get_status() );
		$this->assertSame( 200, $this->surface( 'disable_policy' )->get_status() );
	}

	/**
	 * No route is an ability, and nothing invoke- or surface-shaped reaches the assistant.
	 *
	 * The ability registry is rebuilt with every experiment switched on, so any
	 * experiment that registered one of these routes as an ability would show up.
	 *
	 * @since x.x.x
	 */
	public function test_no_route_is_registered_as_an_ability(): void {
		add_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );
		$every_experiment_on = static function ( $pre, $option ) {
			if ( 'wpai_features_enabled' === $option || 1 === preg_match( '/^wpai_feature_.+_enabled$/', (string) $option ) ) {
				return true;
			}

			return $pre;
		};
		add_filter( 'pre_option', $every_experiment_on, 10, 2 );

		$abilities_instance  = new \ReflectionProperty( \WP_Abilities_Registry::class, 'instance' );
		$categories_instance = new \ReflectionProperty( \WP_Ability_Categories_Registry::class, 'instance' );
		$abilities_instance->setAccessible( true );
		$categories_instance->setAccessible( true );
		$original_abilities  = $abilities_instance->getValue();
		$original_categories = $categories_instance->getValue();

		try {
			( new Loader( new Registry() ) )->init();

			$abilities_instance->setValue( null, null );
			$categories_instance->setValue( null, null );

			$abilities = wp_get_abilities();

			$this->assertTrue( wp_has_ability( 'ai/propose-drafts' ), 'The rebuilt registry must hold the AI Workspace experiment’s abilities.' );
			$this->assertTrue( wp_has_ability( 'ai/suggest-reply' ), 'The rebuilt registry must hold the Suggest Reply experiment’s abilities.' );

			foreach ( $abilities as $ability ) {
				foreach ( array( 'execute_callback', 'permission_callback' ) as $property ) {
					$reflection = new \ReflectionProperty( \WP_Ability::class, $property );
					$reflection->setAccessible( true );

					$this->assertFalse(
						$this->callback_reaches_controller( $reflection->getValue( $ability ) ),
						sprintf( 'Ability %s must not reach the Explorer REST controller through its %s.', $ability->get_name(), $property )
					);
				}
			}

			add_filter(
				'wpai_workspace_tool_candidates',
				static function ( $candidates ) {
					$candidates['ai/abilities-explorer-invoke']  = '';
					$candidates['ai/abilities-explorer-surface'] = '';
					$candidates['ai/invoke-ability']             = '';
					$candidates['ai/set-tool-surface']           = '';
					return $candidates;
				}
			);

			foreach ( ( new Tool_Selector() )->get_tool_names( Tool_Selector::SCOPE_SITE ) as $name ) {
				$this->assertSame( 0, preg_match( '/invoke|surface/', $name ), sprintf( 'The assistant must never be offered %s.', $name ) );
			}
		} finally {
			$abilities_instance->setValue( null, $original_abilities );
			$categories_instance->setValue( null, $original_categories );
			remove_filter( 'pre_option', $every_experiment_on, 10 );
			remove_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * Helpers.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Reports whether a callback is bound to the Explorer REST controller.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $callback The callback.
	 * @return bool True when it reaches the controller.
	 */
	private function callback_reaches_controller( $callback ): bool {
		if ( is_array( $callback ) && isset( $callback[0] ) ) {
			$target = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];

			return Abilities_Controller::class === $target;
		}

		if ( is_string( $callback ) ) {
			return 0 === strpos( ltrim( $callback, '\\' ), Abilities_Controller::class . '::' );
		}

		if ( $callback instanceof \Closure ) {
			$reflection = new \ReflectionFunction( $callback );
			$scope      = $reflection->getClosureScopeClass();

			return null !== $scope && Abilities_Controller::class === $scope->getName();
		}

		return false;
	}

	/**
	 * Makes the current request a cookie-authenticated administrator's.
	 *
	 * @since x.x.x
	 */
	private function as_cookie_admin(): void {
		wp_set_current_user( $this->admin_id );
		$GLOBALS['wp_rest_auth_cookie'] = true; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core's own authentication global, set as core would.
	}

	/**
	 * Asserts every route refuses the current request and changes nothing.
	 *
	 * @since x.x.x
	 *
	 * @param string $who Who is being refused, for the messages.
	 */
	private function assertRefusedEverywhere( string $who ): void {
		$slug = 'wpai-test/refused';
		$this->register_fixture(
			$slug,
			array(
				'execute_callback' => function () {
					$this->executions[] = 'ran';
					return true;
				},
			)
		);

		$requests = array(
			array( 'GET', Abilities_Controller::ABILITIES_ROUTE, array() ),
			array( 'GET', Abilities_Controller::ITEM_ROUTE, array( 'name' => $slug ) ),
			array(
				'POST',
				Abilities_Controller::INVOKE_ROUTE,
				array(
					'name'  => $slug,
					'input' => '',
				),
			),
			array(
				'POST',
				Abilities_Controller::SURFACE_ROUTE,
				array(
					'change' => 'remove',
					'name'   => $slug,
				),
			),
			array( 'POST', Abilities_Controller::SURFACE_ROUTE, array( 'change' => 'disable_policy' ) ),
		);

		foreach ( $requests as $request ) {
			$response = $this->dispatch( $request[0], $request[1], $request[2] );

			$this->assertSame(
				403,
				$response->get_status(),
				sprintf( '%s must be refused on %s %s.', $who, $request[0], $request[1] )
			);
		}

		$this->assertSame( array(), $this->executions, sprintf( '%s must not run an ability.', $who ) );
		$this->assertOptionsUntouched();
	}

	/**
	 * Asserts neither policy option was written.
	 *
	 * @since x.x.x
	 */
	private function assertOptionsUntouched(): void {
		$this->assertFalse( get_option( Tool_Policy::OWNER_EXCLUSIONS_OPTION ), 'The exclusion list must be unchanged.' );
		$this->assertFalse( get_option( Tool_Policy::POLICY_DISABLED_OPTION ), 'The policy switch must be unchanged.' );
	}

	/**
	 * Dispatches a request to a full route path.
	 *
	 * @since x.x.x
	 *
	 * @param string               $method HTTP method.
	 * @param string               $route  Full route, without the leading slash.
	 * @param array<string, mixed> $params Parameters: query for GET, body otherwise.
	 * @return \WP_REST_Response The response.
	 */
	private function dispatch( string $method, string $route, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/' . $route );

		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_body_params( $params );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Invokes an ability through the route.
	 *
	 * @since x.x.x
	 *
	 * @param string $name  Ability name.
	 * @param string $input Raw JSON input.
	 * @return \WP_REST_Response The response.
	 */
	private function invoke( string $name, string $input ): WP_REST_Response {
		return $this->dispatch(
			'POST',
			Abilities_Controller::INVOKE_ROUTE,
			array(
				'name'  => $name,
				'input' => $input,
			)
		);
	}

	/**
	 * Requests a surface change through the route.
	 *
	 * @since x.x.x
	 *
	 * @param string $change The change.
	 * @param string $name   Optional. Ability name. Default none.
	 * @return \WP_REST_Response The response.
	 */
	private function surface( string $change, string $name = '' ): WP_REST_Response {
		$params = array( 'change' => $change );

		if ( '' !== $name ) {
			$params['name'] = $name;
		}

		return $this->dispatch( 'POST', Abilities_Controller::SURFACE_ROUTE, $params );
	}

	/**
	 * Returns the row for one slug.
	 *
	 * @since x.x.x
	 *
	 * @param array<int, array<string, mixed>> $items The list items.
	 * @param string                           $slug  The ability name.
	 * @return array<string, mixed> The row.
	 */
	private function row( array $items, string $slug ): array {
		$rows = array_column( $items, null, 'slug' );

		$this->assertArrayHasKey( $slug, $rows );

		return $rows[ $slug ];
	}

	/**
	 * Returns an object schema with one required string property.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The schema.
	 */
	private function message_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'message' => array( 'type' => 'string' ),
			),
			'required'   => array( 'message' ),
		);
	}

	/**
	 * Registers a fixture that records and returns its input.
	 *
	 * @since x.x.x
	 *
	 * @param string               $slug   The ability name.
	 * @param array<string, mixed> $schema The input schema.
	 * @return string The ability name.
	 */
	private function register_echo_fixture( string $slug, array $schema ): string {
		$args = array(
			'execute_callback' => function ( $input = null ) {
				$this->executions[] = $input;
				return null === $input ? true : $input;
			},
		);

		if ( array() !== $schema ) {
			$args['input_schema'] = $schema;
		}

		return $this->register_fixture( $slug, $args );
	}

	/**
	 * Registers a fixture that declares itself fit for the assistant.
	 *
	 * @since x.x.x
	 *
	 * @param string $slug The ability name.
	 * @return string The ability name.
	 */
	private function register_declared_fixture( string $slug ): string {
		return $this->register_fixture(
			$slug,
			array(
				'meta' => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'open_world'  => false,
					),
					'ai-workspace' => array( 'public' => true ),
				),
			)
		);
	}

	/**
	 * Registers a fixture ability.
	 *
	 * @since x.x.x
	 *
	 * @param string               $slug The ability name.
	 * @param array<string, mixed> $args Overrides for the registration arguments.
	 * @return string The ability name.
	 */
	private function register_fixture( string $slug, array $args = array() ): string {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.

		try {
			wp_register_ability(
				$slug,
				array_merge(
					array(
						'label'               => 'Explorer REST fixture',
						'description'         => 'A fixture ability for the Explorer REST routes.',
						'category'            => WPAI_DEFAULT_ABILITY_CATEGORY,
						'execute_callback'    => '__return_true',
						'permission_callback' => '__return_true',
					),
					$args
				)
			);
		} finally {
			array_pop( $wp_current_filter );
		}

		$this->registered[] = $slug;

		return $slug;
	}

	/**
	 * Registers a fixture category.
	 *
	 * @since x.x.x
	 *
	 * @param string $slug  The category slug.
	 * @param string $label The category label.
	 * @return string The category slug.
	 */
	private function register_category( string $slug, string $label ): string {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_categories_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.

		try {
			wp_register_ability_category(
				$slug,
				array(
					'label'       => $label,
					'description' => 'A fixture category.',
				)
			);
		} finally {
			array_pop( $wp_current_filter );
		}

		$this->categories[] = $slug;

		return $slug;
	}

	/**
	 * Skips a test on a WordPress that cannot filter ability discovery.
	 *
	 * @since x.x.x
	 */
	private function require_filtered_discovery(): void {
		if ( ( new Tool_Policy() )->supports_filtered_discovery() ) {
			return;
		}

		$this->markTestSkipped( 'This WordPress does not support filtered ability discovery.' );
	}
}
