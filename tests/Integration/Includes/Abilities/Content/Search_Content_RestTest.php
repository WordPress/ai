<?php
/**
 * REST tests for the content search ability run over GET.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Content
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Content;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use WP_UnitTest_Factory;
use WordPress\AI\Abilities\Content\Search_Content;
use WordPress\AI\Abilities\Show_In_Abilities;
use WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace\Fixtures\Workspace_Tools;

/**
 * Search_Content REST test case.
 *
 * Runs the filters over GET, where every input arrives as a string.
 *
 * @since x.x.x
 */
class Search_Content_RestTest extends WP_UnitTestCase {

	/**
	 * Administrator user ID.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	private static $admin_id;

	/**
	 * The REST server.
	 *
	 * @since x.x.x
	 *
	 * @var \WP_REST_Server
	 */
	private $server;

	/**
	 * Creates the shared users.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_UnitTest_Factory $factory The unit test factory.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ): void {
		self::$admin_id = $factory->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		global $wp_rest_server, $wp_current_filter;
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Resetting the REST server for the test.
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Fires core's own hook.

		( new Show_In_Abilities() )->register();

		if ( ! wp_has_ability_category( 'content' ) ) {
			$wp_current_filter[] = 'wp_abilities_api_categories_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
			try {
				wp_register_ability_category(
					'content',
					array(
						'label'       => 'Content',
						'description' => 'Content.',
					)
				);
			} finally {
				array_pop( $wp_current_filter );
			}
		}

		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			Workspace_Tools::register( new Search_Content() );
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
		if ( wp_has_ability( 'ai/search-content' ) ) {
			wp_unregister_ability( 'ai/search-content' );
		}

		foreach ( array( 'post', 'page' ) as $post_type ) {
			$object = get_post_type_object( $post_type );
			if ( ! $object ) {
				continue;
			}

			// Unsetting a property WP 7.2 declares leaves it uninitialized, so restore the default.
			if ( property_exists( \WP_Post_Type::class, 'show_in_abilities' ) ) {
				$object->show_in_abilities = true;
			} else {
				unset( $object->show_in_abilities );
			}
		}

		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Resetting the REST server after the test.

		parent::tearDown();
	}

	/**
	 * Runs an ability over GET with the given input.
	 *
	 * @since x.x.x
	 *
	 * @param string               $ability_name The ability.
	 * @param array<string, mixed> $input        The ability input, as a query string carries it.
	 * @return \WP_REST_Response The response.
	 */
	private function run_ability( string $ability_name, array $input ) {
		$request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/' . $ability_name . '/run' );
		$request->set_query_params( array( 'input' => $input ) );

		return $this->server->dispatch( $request );
	}

	/**
	 * Creates a published post with tags and, optionally, a featured image.
	 *
	 * @since x.x.x
	 *
	 * @param string       $date      The post date, `Y-m-d H:i:s`.
	 * @param list<string> $tags      Tag names.
	 * @param bool         $thumbnail Whether to give it a featured image.
	 * @return int The post ID.
	 */
	private function create_post( string $date, array $tags, bool $thumbnail ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_date'   => $date,
				'post_status' => 'publish',
			)
		);

		wp_set_post_tags( $post_id, $tags );

		if ( $thumbnail ) {
			$attachment_id = self::factory()->attachment->create_object(
				'thumb-' . $post_id . '.jpg',
				$post_id,
				array(
					'post_mime_type' => 'image/jpeg',
					'post_type'      => 'attachment',
				)
			);
			set_post_thumbnail( $post_id, $attachment_id );
		}

		return $post_id;
	}

	/**
	 * Every search filter works when its value arrives as a string.
	 *
	 * @since x.x.x
	 */
	public function test_search_filters_are_honoured_from_a_query_string(): void {
		$match = $this->create_post( '2023-05-01 10:00:00', array( 'updates' ), false );
		$this->create_post( '2023-05-02 10:00:00', array( 'updates' ), true );
		$this->create_post( '2024-05-01 10:00:00', array( 'updates' ), false );
		$news = $this->create_post( '2023-06-01 10:00:00', array( 'news' ), false );
		$this->create_post( '2023-06-02 10:00:00', array( 'other' ), false );

		$response = $this->run_ability(
			'ai/search-content',
			array(
				'tax'                => array(
					'taxonomy' => 'post_tag',
					'terms'    => 'updates,news',
				),
				'date_after'         => '2023-01-01',
				'date_before'        => '2023-12-31',
				'has_featured_image' => 'false',
				'per_page'           => '20',
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertEqualsCanonicalizing(
			array( $match, $news ),
			array_column( $response->get_data()['results'], 'id' ),
			'Only the 2023 posts tagged "updates" or "news" without a featured image match.'
		);
	}
}
