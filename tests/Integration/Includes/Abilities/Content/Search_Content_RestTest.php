<?php
/**
 * REST tests for the content listing abilities run over GET.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Content
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Content;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use WP_UnitTest_Factory;
use WordPress\AI\Abilities\Content\List_Terms;
use WordPress\AI\Abilities\Content\Search_Content;
use WordPress\AI\Abilities\Show_In_Abilities;

/**
 * Search_Content and List_Terms REST test case.
 *
 * Both abilities are read-only, so they run over GET, where every input arrives
 * as a string: booleans as "true" or "false", and lists comma-separated. These
 * tests run the filters that way, so a filter is never dropped for its type.
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
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Resetting the REST server for the test.
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );

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
			( new Search_Content() )->register();
			( new List_Terms() )->register();
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
		foreach ( array( 'ai/search-content', 'ai/list-terms' ) as $ability_name ) {
			if ( ! wp_has_ability( $ability_name ) ) {
				continue;
			}

			wp_unregister_ability( $ability_name );
		}

		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Resetting the REST server after the test.

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

	/**
	 * The terms listing reads its taxonomy list and empty-terms flag from a query string.
	 *
	 * @since x.x.x
	 */
	public function test_list_terms_reads_its_inputs_from_a_query_string(): void {
		self::factory()->category->create( array( 'name' => 'Unused category' ) );
		$this->create_post( '2024-01-01 10:00:00', array( 'roadmap' ), false );

		$response = $this->run_ability(
			'ai/list-terms',
			array(
				'taxonomy'   => 'category,post_tag',
				'hide_empty' => 'false',
			)
		);

		$this->assertSame( 200, $response->get_status() );

		$names = array_column( $response->get_data()['terms'], 'name' );

		$this->assertContains( 'Unused category', $names, 'hide_empty=false keeps empty terms.' );
		$this->assertContains( 'roadmap', $names, 'Tags are listed alongside categories.' );
	}
}
