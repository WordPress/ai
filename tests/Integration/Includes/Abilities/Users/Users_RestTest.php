<?php
/**
 * Integration tests for running the core/users-query Ability provided by the plugin
 * through the Abilities REST run endpoint.
 *
 * The tests are kept almost identical to the WordPress core tests for the
 * `WP_Abilities_Users` class, so the two implementations stay in sync.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Users
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Users;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use WP_UnitTest_Factory;
use WordPress\AI\Abilities\Users\Users;

/**
 * Users ability REST test case.
 *
 * @since x.x.x
 */
class Users_RestTest extends WP_UnitTestCase {

	/**
	 * The REST server instance for the current test.
	 *
	 * @var WP_REST_Server
	 */
	protected $server;

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	protected static $admin_id;

	/**
	 * Subscriber user ID.
	 *
	 * @var int
	 */
	protected static $subscriber_id;

	/**
	 * The run route for the core/users-query ability.
	 *
	 * @var string
	 */
	const RUN_ROUTE = '/wp-abilities/v1/abilities/core/users-query/run';

	/**
	 * Sets up users.
	 *
	 * @since x.x.x
	 *
	 * @param WP_UnitTest_Factory $factory The unit test factory.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ): void {
		self::$admin_id      = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$subscriber_id = $factory->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Set up test case.
	 *
	 * Plugin: registers the plugin's core/users-query ability inside a faked init action.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Resetting the REST server for the test.
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );

		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			( new Users() )->register();
		} finally {
			array_pop( $wp_current_filter );
		}

		wp_set_current_user( self::$admin_id );
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		if ( wp_has_ability( 'core/users-query' ) ) {
			wp_unregister_ability( 'core/users-query' );
		}

		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Resetting the REST server after the test.

		parent::tearDown();
	}

	/**
	 * Builds a GET run request with the given ability input.
	 *
	 * @param array<string, mixed> $input The ability input.
	 * @return WP_REST_Request The request.
	 */
	private function run_request( array $input ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', self::RUN_ROUTE );
		$request->set_query_params( array( 'input' => $input ) );
		return $request;
	}

	/**
	 * Creates an author with a published post.
	 *
	 * @param array<string, mixed> $args Optional. Arguments for the user. Default empty array.
	 * @return int The author's user ID.
	 */
	private function create_public_author( array $args = array() ): int {
		$author_id = self::factory()->user->create( array_merge( array( 'role' => 'author' ), $args ) );
		self::factory()->post->create(
			array(
				'post_author' => $author_id,
				'post_status' => 'publish',
			)
		);

		return $author_id;
	}

	/**
	 * Logged out user receives 401.
	 *
	 * @since x.x.x
	 */
	public function test_logged_out_user_receives_401(): void {
		wp_set_current_user( 0 );

		$response = $this->server->dispatch( $this->run_request( array( 'where' => array( 'ids' => array( self::$admin_id ) ) ) ) );

		$this->assertSame( 401, $response->get_status(), 'A logged-out request should be rejected as unauthenticated.' );
	}

	/**
	 * Subscriber filtering by roles receives 403.
	 *
	 * @since x.x.x
	 */
	public function test_subscriber_filtering_by_roles_receives_403(): void {
		wp_set_current_user( self::$subscriber_id );

		$response = $this->server->dispatch( $this->run_request( array( 'where' => array( 'roles' => array( 'administrator' ) ) ) ) );

		$this->assertSame( 403, $response->get_status(), 'Filtering by role should require permission to list users.' );
		$this->assertSame( 'rest_ability_cannot_execute', $response->get_data()['code'], 'The error should come from the permission check.' );
	}

	/**
	 * Subscriber reading a public author receives public fields.
	 *
	 * @since x.x.x
	 */
	public function test_subscriber_reading_a_public_author_receives_public_fields(): void {
		$author_id = $this->create_public_author( array( 'display_name' => 'Public REST Author' ) );

		wp_set_current_user( self::$subscriber_id );

		$response = $this->server->dispatch(
			$this->run_request(
				array(
					'where'  => array( 'ids' => array( $author_id ) ),
					'fields' => array( 'id', 'name', 'username', 'email', 'roles' ),
				)
			)
		);
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), 'A subscriber should be able to read an author with a published post.' );
		$this->assertSame( $author_id, $data['items'][0]['id'], 'The lookup should return the requested author.' );
		$this->assertSame( 'Public REST Author', $data['items'][0]['name'], 'The display name should be returned to a subscriber.' );
		$this->assertArrayNotHasKey( 'username', $data['items'][0], 'The username should not be returned to a subscriber.' );
		$this->assertArrayNotHasKey( 'email', $data['items'][0], 'The email address should not be returned to a subscriber.' );
		$this->assertArrayNotHasKey( 'roles', $data['items'][0], 'The roles should not be returned to a subscriber.' );
	}

	/**
	 * Editor reading a user without public posts receives an empty list.
	 *
	 * @since x.x.x
	 */
	public function test_editor_reading_a_user_without_public_posts_receives_an_empty_list(): void {
		$user_id = self::factory()->user->create(
			array(
				'role'          => 'author',
				'user_nicename' => 'rest-users-pending-author',
				'description'   => 'Pending REST author biography.',
			)
		);
		self::factory()->post->create(
			array(
				'post_author' => $user_id,
				'post_status' => 'pending',
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$response = $this->server->dispatch(
			$this->run_request(
				array(
					'where'  => array( 'slugs' => array( 'rest-users-pending-author' ) ),
					'fields' => array( 'id', 'name', 'description', 'email' ),
				)
			)
		);
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), 'An editor should be able to query a user of the site without public posts.' );
		$this->assertSame( array(), $data['items'], 'A user without public posts should not be returned to an editor.' );
		$this->assertSame(
			array(
				'total'       => 0,
				'total_pages' => 0,
			),
			$data['pagination'],
			'The empty result should report zero totals.'
		);
	}

	/**
	 * Subscriber filtering a public author by email or username is rejected.
	 *
	 * @since x.x.x
	 */
	public function test_subscriber_filtering_a_public_author_by_email_or_username_is_rejected(): void {
		$this->create_public_author(
			array(
				'user_login' => 'rest_users_username_lookup',
				'user_email' => 'rest-users-email-lookup@example.org',
			)
		);

		wp_set_current_user( self::$subscriber_id );

		$response = $this->server->dispatch( $this->run_request( array( 'where' => array( 'email' => 'rest-users-email-lookup@example.org' ) ) ) );

		$this->assertSame( 400, $response->get_status(), 'Filtering by email should fail validation, since email is not a filter.' );
		$this->assertSame( 'ability_invalid_input', $response->get_data()['code'], 'The email error should come from input validation.' );

		$response = $this->server->dispatch( $this->run_request( array( 'where' => array( 'usernames' => array( 'rest_users_username_lookup' ) ) ) ) );

		$this->assertSame( 403, $response->get_status(), 'Filtering by username should require permission to list users, even for a public author.' );
		$this->assertSame( 'rest_ability_cannot_execute', $response->get_data()['code'], 'The username error should come from the permission check.' );
	}

	/**
	 * Subscriber query only lists public authors.
	 *
	 * @since x.x.x
	 */
	public function test_subscriber_query_only_lists_public_authors(): void {
		$author_id = $this->create_public_author();

		wp_set_current_user( self::$subscriber_id );

		$response = $this->server->dispatch( $this->run_request( array( 'fields' => array( 'id' ) ) ) );
		$user_ids = wp_list_pluck( $response->get_data()['items'], 'id' );

		$this->assertSame( 200, $response->get_status(), 'A subscriber should be able to query users.' );
		$this->assertContains( $author_id, $user_ids, 'An author with a published post should be listed for a subscriber.' );
		$this->assertNotContains( self::$admin_id, $user_ids, 'A user without published posts should not be listed for a subscriber.' );
		$this->assertNotContains( self::$subscriber_id, $user_ids, 'The subscriber should not be listed without published posts.' );
	}

	/**
	 * Admin query lists users without published posts.
	 *
	 * @since x.x.x
	 */
	public function test_admin_query_lists_users_without_published_posts(): void {
		$response = $this->server->dispatch( $this->run_request( array( 'fields' => array( 'id' ) ) ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), 'An administrator should be able to query users.' );
		$this->assertArrayHasKey( 'items', $data, 'Collection mode should return the users as items.' );
		$this->assertContains( self::$subscriber_id, wp_list_pluck( $data['items'], 'id' ), 'A user without published posts should be listed for an administrator.' );
	}

	/**
	 * Admin query IDs limits results.
	 *
	 * @since x.x.x
	 */
	public function test_admin_query_ids_limits_results(): void {
		$first  = self::factory()->user->create( array( 'user_login' => 'rest_users_include_a' ) );
		$second = self::factory()->user->create( array( 'user_login' => 'rest_users_include_b' ) );
		$third  = self::factory()->user->create( array( 'user_login' => 'rest_users_include_c' ) );

		$response = $this->server->dispatch(
			$this->run_request(
				array(
					// Deliberately pass IDs in the opposite of the expected login order.
					'where'  => array( 'ids' => array( $third, $first ) ),
					'fields' => array( 'id' ),
				)
			)
		);
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), 'The ID list query should succeed.' );
		$this->assertSame( array( $first, $third ), wp_list_pluck( $data['items'], 'id' ), 'Included users should be returned by login, regardless of the order of the ID list.' );
		$this->assertNotContains( $second, wp_list_pluck( $data['items'], 'id' ), 'Users outside the ID list should not be returned.' );
		$this->assertSame( 2, $data['pagination']['total'], 'The total should only count the included users.' );
	}

	/**
	 * Gets a single user by ID.
	 *
	 * @since x.x.x
	 */
	public function test_get_single_user_by_id(): void {
		$response = $this->server->dispatch( $this->run_request( array( 'where' => array( 'ids' => array( self::$subscriber_id ) ) ) ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), 'The ID lookup should succeed.' );
		$this->assertSame( self::$subscriber_id, $data['items'][0]['id'], 'The ID lookup should return the requested user.' );
		$this->assertCount( 1, $data['items'], 'A single-user lookup should return only the requested user.' );
		$this->assertSame(
			array(
				'total'       => 1,
				'total_pages' => 1,
			),
			$data['pagination'],
			'A single-user lookup should report one user on one page.'
		);
	}

	/**
	 * Gets a single user by slug.
	 *
	 * @since x.x.x
	 */
	public function test_get_single_user_by_slug(): void {
		$user_id = self::factory()->user->create( array( 'user_nicename' => 'rest-users-slug' ) );

		$response = $this->server->dispatch( $this->run_request( array( 'where' => array( 'slugs' => array( 'rest-users-slug' ) ) ) ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), 'The slug lookup should succeed.' );
		$this->assertSame( $user_id, $data['items'][0]['id'], 'The slug lookup should return the matching user.' );
		$this->assertSame( 'rest-users-slug', $data['items'][0]['slug'], 'The slug lookup should return the user slug.' );
		$this->assertCount( 1, $data['items'], 'A slug lookup should return only the matching user.' );
		$this->assertSame(
			array(
				'total'       => 1,
				'total_pages' => 1,
			),
			$data['pagination'],
			'A slug lookup should report one user on one page.'
		);
	}

	/**
	 * Wrong HTTP method returns 405.
	 *
	 * @since x.x.x
	 */
	public function test_wrong_http_method_returns_405(): void {
		$request = new WP_REST_Request( 'POST', self::RUN_ROUTE );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'input' => array( 'where' => array( 'ids' => array( self::$admin_id ) ) ) ) ) );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 405, $response->get_status(), 'A POST request to the read-only ability should be rejected.' );
		$this->assertSame( 'rest_ability_invalid_method', $response->get_data()['code'], 'The error should identify the invalid HTTP method.' );
	}

	/**
	 * Pagination returns totals in body.
	 *
	 * @since x.x.x
	 */
	public function test_pagination_returns_totals_in_body(): void {
		self::factory()->user->create_many( 3 );

		$response = $this->server->dispatch(
			$this->run_request(
				array(
					'pagination' => array(
						'page'     => 1,
						'per_page' => 2,
					),
				)
			)
		);
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), 'The paginated query should succeed.' );
		$this->assertCount( 2, $data['items'], 'The first page should be limited to per_page users.' );
		$this->assertGreaterThanOrEqual( 5, $data['pagination']['total'], 'The total should count users across all pages.' );
		$this->assertSame( (int) ceil( $data['pagination']['total'] / 2 ), $data['pagination']['total_pages'], 'The total pages should match the total and per_page.' );
	}

	/**
	 * Out of range page returns 404.
	 *
	 * @since x.x.x
	 */
	public function test_out_of_range_page_returns_404(): void {
		$response = $this->server->dispatch(
			$this->run_request(
				array(
					'pagination' => array(
						'page'     => 999,
						'per_page' => 1,
					),
				)
			)
		);

		$this->assertSame( 404, $response->get_status(), 'Requesting a page past the last one should return a 404 error.' );
		$this->assertSame( 'users_invalid_page_number', $response->get_data()['code'], 'The error should identify the invalid page number.' );
	}

	/**
	 * A GET request delivers every value as a string, and a list as a comma-separated string.
	 *
	 * @since x.x.x
	 */
	public function test_query_string_values_are_accepted(): void {
		$first  = self::factory()->user->create( array( 'user_login' => 'rest_users_query_string_a' ) );
		$second = self::factory()->user->create( array( 'user_login' => 'rest_users_query_string_b' ) );

		$response = $this->server->dispatch(
			$this->run_request(
				array(
					'where'      => array( 'ids' => $second . ',' . $first ),
					'fields'     => 'id,name',
					'pagination' => array(
						'page'     => '2',
						'per_page' => '1',
					),
				)
			)
		);
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), 'String values from a query string should be accepted.' );
		$this->assertSame( array( $second ), wp_list_pluck( $data['items'], 'id' ), 'The second page of one user should hold the second included user by login.' );
		$this->assertSameSets( array( 'id', 'name' ), array_keys( $data['items'][0] ), 'Only the fields from the comma-separated list should be returned.' );
		$this->assertSame( 2, $data['pagination']['total'], 'The total should count both included users.' );
		$this->assertSame( 2, $data['pagination']['total_pages'], 'The total pages should be computed from the string per_page value.' );
	}

	/**
	 * Has published posts accepts the string true.
	 *
	 * @since x.x.x
	 */
	public function test_has_published_posts_accepts_the_string_true(): void {
		$author_id     = $this->create_public_author();
		$non_author_id = self::factory()->user->create( array( 'role' => 'author' ) );

		$response = $this->server->dispatch(
			$this->run_request(
				array(
					'where'  => array(
						'ids'                 => $author_id . ',' . $non_author_id,
						'has_published_posts' => 'true',
					),
					'fields' => 'id',
				)
			)
		);
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), 'The string "true" should be accepted for has_published_posts.' );
		$this->assertSame( array( $author_id ), wp_list_pluck( $data['items'], 'id' ), 'Only the included user with a published post should be returned.' );
	}
}
