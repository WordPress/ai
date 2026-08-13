<?php
/**
 * Integration tests for the REST-backed implementations of the core read abilities.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Rest
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Rest;

use WP_UnitTestCase;
use WordPress\AI\Abilities\Content\Content;
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
	 * Set up test case.
	 *
	 * @since 1.3.0
	 */
	public function setUp(): void {
		parent::setUp();

		add_filter( 'wpai_abilities_rest_backend', '__return_true' );

		// Mark the curated core post types (post, page) as exposed to abilities.
		( new Show_In_Abilities() )->register();

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

		if ( wp_has_ability( 'core/read-content' ) ) {
			wp_unregister_ability( 'core/read-content' );
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
}
