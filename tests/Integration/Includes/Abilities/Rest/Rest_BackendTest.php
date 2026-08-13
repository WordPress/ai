<?php
/**
 * Integration tests for the REST-backed implementations of the core read abilities.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Rest
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Rest;

use WP_Error;
use WP_UnitTestCase;
use WordPress\AI\Abilities\Content\Content;
use WordPress\AI\Abilities\Settings\Settings;
use WordPress\AI\Abilities\Show_In_Abilities;

/**
 * REST-backed ability implementation test case.
 *
 * The suite runs twice, with the REST-backed implementations off and on. These tests are
 * about the REST-backed implementations themselves, so they turn them on for every test
 * and therefore cover the same ground in both runs.
 *
 * @since 1.3.0
 */
class Rest_BackendTest extends WP_UnitTestCase {

	/**
	 * The settings exposure component. Held so the same instance can detach its filter on tear down.
	 *
	 * @since 1.3.0
	 *
	 * @var \WordPress\AI\Abilities\Show_In_Abilities
	 */
	private $show_in_abilities;

	/**
	 * Set up test case.
	 *
	 * @since 1.3.0
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
	 * @since 1.3.0
	 */
	public function tearDown(): void {
		remove_filter( 'wpai_abilities_rest_backend', '__return_true' );
		remove_filter( 'register_setting_args', array( $this->show_in_abilities, 'mark_setting' ), 10 );

		foreach ( array( 'core/read-content', 'core/read-settings' ) as $ability ) {
			if ( wp_has_ability( $ability ) ) {
				wp_unregister_ability( $ability );
			}
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
	 * @since 1.3.0
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
	 * @since 1.3.0
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
	 * Ensures an ability category exists for an ability to attach to.
	 *
	 * @since 1.3.0
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
	 * @since 1.3.0
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
	 * Registers the plugin's core/read-settings ability inside a faked init action.
	 *
	 * @since 1.3.0
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
