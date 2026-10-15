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
use WordPress\AI\Experiments\Abilities_Explorer\REST\Abilities_Controller;

/**
 * Abilities_Controller test case.
 *
 * Cookie authentication is simulated by setting `$GLOBALS['wp_rest_auth_cookie']`
 * to `true`, which is what core's `rest_cookie_collect_status()` leaves behind
 * once a valid logged-in cookie was seen. `dispatch()` skips the authentication
 * pass `serve_request()` runs, so every request also carries a valid `wp_rest`
 * nonce in `X-WP-Nonce` for the current user, which the controller checks
 * itself. {@see self::$nonce} changes or drops it.
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
	 * The `X-WP-Nonce` header value requests carry.
	 *
	 * `null` sends a valid `wp_rest` nonce for the current user, created at
	 * dispatch time; an empty string sends no header at all.
	 *
	 * @var string|null
	 */
	private ?string $nonce = null;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

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
		$this->nonce      = null;

		unset( $GLOBALS['wp_rest_auth_cookie'], $GLOBALS['wp_rest_application_password_uuid'] );

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

		$row = $this->row( $data['items'], $slug );

		$this->assertFalse( $row['meta']['show_in_rest'] );
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
	 * A property typed with a JSON Schema type list invokes instead of erroring.
	 *
	 * @since x.x.x
	 */
	public function test_invoke_accepts_a_property_typed_with_a_type_list(): void {
		$slug = $this->register_echo_fixture(
			'wpai-test/echo-type-list',
			array(
				'type'       => 'object',
				'properties' => array(
					'note' => array( 'type' => array( 'string', 'null' ) ),
				),
			)
		);

		$response = $this->invoke( $slug, '{"note":"hello"}' );

		$this->assertSame( 200, $response->get_status(), 'A type list must not take the route down.' );
		$this->assertSame(
			array(
				'success' => true,
				'data'    => array( 'note' => 'hello' ),
			),
			$response->get_data()
		);

		$response = $this->invoke( $slug, '{"note":null}' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );

		$response = $this->invoke( $slug, '{"note":5}' );

		$this->assertSame( 400, $response->get_status(), 'A value matching no listed type is the Explorer\'s rejection, not a 500.' );
		$this->assertSame( array( 'Field "note" should be of type "string, null"' ), $response->get_data()['data']['errors'] );
		$this->assertSame( array( array( 'note' => 'hello' ), array( 'note' => null ) ), $this->executions );
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

	/*
	 * ---------------------------------------------------------------------
	 * Rows, ported from the retired list table's tests.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Every row's origin is one of the three known origins.
	 *
	 * The provider filter offers Core, Plugin and Theme from these origins, so
	 * a row carrying anything else would add an option no rule can match.
	 *
	 * @since x.x.x
	 */
	public function test_list_reports_only_known_origins(): void {
		$this->register_fixture( 'wpai-test/known-origin' );

		$items   = $this->dispatch( 'GET', Abilities_Controller::ABILITIES_ROUTE )->get_data()['items'];
		$origins = array_values( array_unique( array_column( $items, 'origin' ) ) );

		$this->assertSame(
			array(),
			array_diff( $origins, array( 'Core', 'Plugin', 'Theme' ) ),
			'Every row must resolve to one of the known origins.'
		);
		$this->assertContains( 'Plugin', $origins, 'The plugin fixture must be counted under Plugin.' );
	}

	/**
	 * A custom provider label is listed beside the known origins, not in place of one.
	 *
	 * @since x.x.x
	 */
	public function test_list_carries_a_custom_provider_beside_the_known_origins(): void {
		$slug = $this->register_fixture(
			'custom-provider-plugin/table-ability',
			array( 'meta' => array( 'provider' => 'My Custom Plugin' ) )
		);

		$items = $this->dispatch( 'GET', Abilities_Controller::ABILITIES_ROUTE )->get_data()['items'];

		$this->assertContains( 'My Custom Plugin', array_column( $items, 'provider' ), 'The custom label must reach the screen so it can be offered as a filter option.' );
		$this->assertNotContains( 'My Custom Plugin', array_column( $items, 'origin' ), 'A custom label must never become an origin.' );
		$this->assertSame( 'My Custom Plugin', $this->row( $items, $slug )['provider_label'] );
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
	 * A cookie-authenticated administrator with no REST nonce is refused on every route.
	 *
	 * Core's cookie check skips the nonce when an earlier
	 * `rest_authentication_errors` filter already answered, so the cookie flag
	 * alone does not prove the nonce was checked.
	 *
	 * @since x.x.x
	 */
	public function test_cookie_administrator_without_a_nonce_is_refused_on_every_route(): void {
		$this->nonce = '';

		$this->assertRefusedEverywhere( 'A cookie administrator with no nonce' );
	}

	/**
	 * A cookie-authenticated administrator with an invalid REST nonce is refused on every route.
	 *
	 * @since x.x.x
	 */
	public function test_cookie_administrator_with_an_invalid_nonce_is_refused_on_every_route(): void {
		$this->nonce = 'not-a-nonce';

		$this->assertRefusedEverywhere( 'A cookie administrator with an invalid nonce' );
	}

	/**
	 * A nonce for another action is refused on every route.
	 *
	 * @since x.x.x
	 */
	public function test_cookie_administrator_with_a_nonce_for_another_action_is_refused_on_every_route(): void {
		$this->nonce = wp_create_nonce( 'some-other-action' );

		$this->assertRefusedEverywhere( 'A cookie administrator with a nonce for another action' );
	}

	/**
	 * A valid nonce sent as the `_wpnonce` parameter is accepted like the header.
	 *
	 * @since x.x.x
	 */
	public function test_cookie_administrator_with_the_nonce_as_a_parameter_is_accepted(): void {
		$this->nonce = '';

		$response = $this->dispatch( 'GET', Abilities_Controller::ABILITIES_ROUTE, array( '_wpnonce' => wp_create_nonce( 'wp_rest' ) ) );

		$this->assertSame( 200, $response->get_status() );
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
	}

	/*
	 * ---------------------------------------------------------------------
	 * Helpers.
	 * ---------------------------------------------------------------------
	 */

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
		$nonce   = $this->nonce ?? wp_create_nonce( 'wp_rest' );

		if ( '' !== $nonce ) {
			$request->set_header( 'X-WP-Nonce', $nonce );
		}

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
}
