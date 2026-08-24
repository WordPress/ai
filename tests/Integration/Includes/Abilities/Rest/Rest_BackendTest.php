<?php
/**
 * Integration tests for the REST-backed implementations of the core read abilities.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Rest
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Rest;

use WP_Error;
use WP_REST_Response;
use WP_UnitTestCase;
use WordPress\AI\Abilities\Content\Content;
use WordPress\AI\Abilities\Settings\Settings;
use WordPress\AI\Abilities\Show_In_Abilities;
use WordPress\AI\Abilities\Users\Users;

/**
 * REST-backed ability implementation test case.
 *
 * The suite runs twice, with the REST-backed implementations off and on. These tests are
 * about the REST-backed implementations themselves, so they turn them on for every test
 * and therefore cover the same ground in both runs.
 *
 * @since x.x.x
 */
class Rest_BackendTest extends WP_UnitTestCase {

	/**
	 * The settings exposure component. Held so the same instance can detach its filter on tear down.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Abilities\Show_In_Abilities
	 */
	private $show_in_abilities;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		add_filter( 'wpai_abilities_rest_backend', '__return_true' );

		// Mark the curated core post types (post, page) and settings as exposed to abilities.
		$this->show_in_abilities = new Show_In_Abilities();
		$this->show_in_abilities->register();
		register_initial_settings();

		foreach ( array( 'content', 'site', 'user' ) as $category ) {
			$this->ensure_ability_category( $category );
		}
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		remove_filter( 'wpai_abilities_rest_backend', '__return_true' );
		remove_filter( 'register_setting_args', array( $this->show_in_abilities, 'mark_setting' ), 10 );

		foreach ( array( 'core/read-content', 'core/read-settings', 'core/read-users' ) as $ability ) {
			if ( ! wp_has_ability( $ability ) ) {
				continue;
			}

			wp_unregister_ability( $ability );
		}

		foreach ( array( 'post', 'page' ) as $post_type ) {
			$object = get_post_type_object( $post_type );
			if ( ! $object ) {
				continue;
			}

			unset( $object->show_in_abilities );
		}

		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Reading a post type that REST does not expose leaves no route behind for it.
	 *
	 * The post type is exposed to REST for the length of the request, which means the REST
	 * server built during that request carries a route the post type must not have once the
	 * flag is restored. That server has to go, whether or not one existed beforehand.
	 *
	 * @since x.x.x
	 */
	public function test_reading_an_unexposed_post_type_leaves_no_route_behind(): void {
		register_post_type(
			'wpai_rest_cpt',
			array(
				'public'            => true,
				'show_in_abilities' => true,
				'supports'          => array( 'title', 'editor' ),
			)
		);

		try {
			wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

			$post_id = self::factory()->post->create(
				array(
					'post_type'   => 'wpai_rest_cpt',
					'post_status' => 'publish',
				)
			);

			$this->register_content_ability();

			// The ability runs where no REST server has been built yet, as it does under
			// WP-CLI or in any request that is not a REST request.
			unset( $GLOBALS['wp_rest_server'] );

			$result = wp_get_ability( 'core/read-content' )->execute( array( 'post_type' => 'wpai_rest_cpt' ) );

			$this->assertNotWPError( $result, 'The unexposed post type should still be readable through the ability.' );
			$this->assertContains( $post_id, wp_list_pluck( $result['posts'], 'id' ), 'The post of the unexposed post type should be returned.' );

			$this->assertArrayNotHasKey(
				'/wp/v2/wpai_rest_cpt',
				rest_get_server()->get_routes(),
				'A post type that REST does not expose should have no route left after the ability ran.'
			);
		} finally {
			unregister_post_type( 'wpai_rest_cpt' );
			unset( $GLOBALS['wp_rest_server'] );
		}
	}

	/**
	 * A refused settings endpoint is reported, not answered from the stored options.
	 *
	 * The endpoint is the execution path here, so what it refuses must not come back from
	 * `get_option()` instead. Otherwise any policy the endpoint applies is bypassed.
	 *
	 * @since x.x.x
	 */
	public function test_a_refused_settings_endpoint_is_not_answered_from_the_options(): void {
		$deny = static function ( $result, $server, $request ) {
			return '/wp/v2/settings' === $request->get_route()
				? new WP_Error( 'rest_forbidden', 'Denied for the test.', array( 'status' => 403 ) )
				: $result;
		};
		add_filter( 'rest_pre_dispatch', $deny, 10, 3 );

		try {
			wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
			$this->register_settings_ability();

			$result = wp_get_ability( 'core/read-settings' )->execute( array( 'fields' => array( 'blogname' ) ) );

			$this->assertWPError( $result, 'A refused settings endpoint should be reported as an error.' );
			$this->assertSame( 'rest_forbidden', $result->get_error_code(), 'The error from the endpoint should be passed on unchanged.' );
		} finally {
			remove_filter( 'rest_pre_dispatch', $deny, 10 );
		}
	}

	/**
	 * A user row that cannot be read is reported, not dropped from the page.
	 *
	 * Rows asking for sensitive fields are read one by one. When such a read fails, keeping
	 * the rest of the page would return fewer users than the totals promise, and the caller
	 * would have no way to tell a withheld user from one that does not exist.
	 *
	 * @since x.x.x
	 */
	public function test_a_user_row_that_cannot_be_read_is_reported(): void {
		$deny = static function ( $result, $server, $request ) {
			return 0 === strpos( $request->get_route(), '/wp/v2/users/' )
				? new WP_Error( 'rest_user_cannot_view', 'Denied for the test.', array( 'status' => 403 ) )
				: $result;
		};
		add_filter( 'rest_pre_dispatch', $deny, 10, 3 );

		try {
			$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
			wp_set_current_user( $admin_id );

			$this->register_users_ability();

			// `email` is a sensitive field, so the row is read through the single-user route.
			$result = wp_get_ability( 'core/read-users' )->execute(
				array(
					'include' => array( $admin_id ),
					'fields'  => array( 'id', 'email' ),
				)
			);

			$this->assertWPError( $result, 'A user row that cannot be read should be reported as an error.' );
			$this->assertSame( 'rest_user_cannot_view', $result->get_error_code(), 'The error from the endpoint should be passed on unchanged.' );
		} finally {
			remove_filter( 'rest_pre_dispatch', $deny, 10 );
		}
	}

	/**
	 * A successful response the mapping cannot read is reported, not read as empty.
	 *
	 * @since x.x.x
	 */
	public function test_a_response_in_an_unexpected_shape_is_reported(): void {
		$mangle = static function ( $response, $handler, $request ) {
			return '/wp/v2/users' === $request->get_route()
				? new WP_REST_Response( 'malformed-success-body', 200 )
				: $response;
		};
		add_filter( 'rest_request_after_callbacks', $mangle, 10, 3 );

		try {
			wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
			$this->register_users_ability();

			$result = wp_get_ability( 'core/read-users' )->execute( array( 'fields' => array( 'id', 'name' ) ) );

			$this->assertWPError( $result, 'A response the mapping cannot read should be reported as an error.' );
			$this->assertSame( 'rest_unexpected_response', $result->get_error_code(), 'The unexpected response should have its own error code.' );
		} finally {
			remove_filter( 'rest_request_after_callbacks', $mangle, 10 );
		}
	}

	/**
	 * Request parameters survive a reordered REST parameter order.
	 *
	 * Plugins may filter `rest_request_parameter_order`. When `URL` comes first, parameters
	 * written without naming their type land there, and dispatching replaces the URL
	 * parameters with the ones matched from the route, dropping them.
	 *
	 * @since x.x.x
	 */
	public function test_request_parameters_survive_a_reordered_parameter_order(): void {
		$url_first = static function () {
			return array( 'URL', 'GET', 'defaults' );
		};
		add_filter( 'rest_request_parameter_order', $url_first );

		try {
			wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
			$target_id = self::factory()->user->create( array( 'role' => 'editor' ) );
			self::factory()->user->create( array( 'role' => 'editor' ) );

			$this->register_users_ability();

			$result = wp_get_ability( 'core/read-users' )->execute(
				array(
					'include' => array( $target_id ),
					'fields'  => array( 'id', 'name' ),
				)
			);

			$this->assertNotWPError( $result, 'The users query should succeed under a reordered parameter order.' );
			$this->assertSame(
				array( $target_id ),
				wp_list_pluck( $result['users'], 'id' ),
				'Only the included user should be returned, so the request parameters reached the endpoint.'
			);
		} finally {
			remove_filter( 'rest_request_parameter_order', $url_first );
		}
	}

	/**
	 * Ensures an ability category exists for an ability to attach to.
	 *
	 * @since x.x.x
	 *
	 * @param string $slug The ability category slug.
	 */
	private function ensure_ability_category( string $slug ): void {
		if ( wp_has_ability_category( $slug ) ) {
			return;
		}

		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_categories_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			wp_register_ability_category(
				$slug,
				array(
					'label'       => ucfirst( $slug ),
					'description' => ucfirst( $slug ) . '.',
				)
			);
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Registers the plugin's core/read-content ability inside a faked init action.
	 *
	 * @since x.x.x
	 */
	private function register_content_ability(): void {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			( new Content() )->register();
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Registers the plugin's core/read-users ability inside a faked init action.
	 *
	 * @since x.x.x
	 */
	private function register_users_ability(): void {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			( new Users() )->register();
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Registers the plugin's core/read-settings ability inside a faked init action.
	 *
	 * @since x.x.x
	 */
	private function register_settings_ability(): void {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			( new Settings() )->register();
		} finally {
			array_pop( $wp_current_filter );
		}
	}
}
