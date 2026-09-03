<?php
/**
 * Integration tests for the ai/search-content Ability provided by the plugin.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Content
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Content;

use WP_Error;
use WP_UnitTestCase;
use WordPress\AI\Abilities\Content\Search_Content;
use WordPress\AI\Abilities\Show_In_Abilities;

/**
 * Search_Content ability test case.
 *
 * These tests are deliberately weighted towards capability leakage: every row the
 * ability returns must be a row the requesting user could have read anyway.
 *
 * @since x.x.x
 */
class Search_ContentTest extends WP_UnitTestCase {

	/**
	 * The registered ability name.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const ABILITY = 'ai/search-content';

	/**
	 * Shared user IDs keyed by role or fixture name.
	 *
	 * @since x.x.x
	 *
	 * @var array<string, int>
	 */
	private static $user_ids = array();

	/**
	 * Creates the shared users for the search ability tests.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_UnitTest_Factory $factory The unit test factory.
	 */
	public static function wpSetUpBeforeClass( $factory ): void {
		self::$user_ids = array(
			'administrator'    => $factory->user->create( array( 'role' => 'administrator' ) ),
			'editor'           => $factory->user->create( array( 'role' => 'editor' ) ),
			'subscriber'       => $factory->user->create( array( 'role' => 'subscriber' ) ),
			'contributor'      => $factory->user->create( array( 'role' => 'contributor' ) ),
			'author'           => $factory->user->create( array( 'role' => 'author' ) ),
			'author_secondary' => $factory->user->create( array( 'role' => 'author' ) ),
		);
	}

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		// Mark the curated core post types (post, page) as exposed to abilities.
		( new Show_In_Abilities() )->register();

		$this->ensure_ability_category( 'content' );
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		if ( wp_has_ability( self::ABILITY ) ) {
			wp_unregister_ability( self::ABILITY );
		}

		// Restore the curated post types to their unmarked state to avoid leaking into other tests.
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
	 * Registers the plugin's ai/search-content ability inside a faked init action.
	 *
	 * @since x.x.x
	 */
	private function register_ability(): void {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			( new Search_Content() )->register();
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Logs in as a user with the given role and returns the user ID.
	 *
	 * @since x.x.x
	 *
	 * @param string $role The role to log in as.
	 * @return int The user ID.
	 */
	private function login_as( string $role ): int {
		$user_id = self::$user_ids[ $role ] ?? self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * Registers the ability and executes it with the given input.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $input The ability input.
	 * @return mixed The ability result.
	 */
	private function execute( array $input ) {
		$this->register_ability();

		return wp_get_ability( self::ABILITY )->execute( $input );
	}

	/**
	 * Returns the post IDs contained in a successful result set.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $result The ability result.
	 * @return list<int> The returned post IDs.
	 */
	private function result_ids( $result ): array {
		$this->assertIsArray( $result, 'The ability should return a result array.' );
		$this->assertArrayHasKey( 'results', $result, 'The result should carry a results list.' );

		return array_map(
			static function ( array $item ): int {
				return (int) $item['id'];
			},
			$result['results']
		);
	}

	/**
	 * The ability is registered in the content category and flagged read-only.
	 *
	 * @since x.x.x
	 */
	public function test_registers_search_content_ability(): void {
		$this->register_ability();

		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability, 'The ai/search-content ability should be registered.' );
		$this->assertSame( self::ABILITY, $ability->get_name(), 'The registered ability should use the expected name.' );
		$this->assertSame( 'content', $ability->get_category(), 'The registered ability should use the content category.' );
		$this->assertTrue( $ability->get_meta_item( 'show_in_rest', false ), 'The ability should be exposed in REST.' );

		$annotations = $ability->get_meta_item( 'annotations', array() );
		$this->assertTrue( $annotations['readonly'], 'The ability should be marked read-only.' );
		$this->assertFalse( $annotations['destructive'], 'The ability should be marked non-destructive.' );
		$this->assertTrue( $annotations['idempotent'], 'The ability should be marked idempotent.' );
		$this->assertFalse( $annotations['open_world'], 'The ability should be marked closed-world; it only reads the local database.' );
	}

	/**
	 * The ability is not registered when no post types are exposed to abilities.
	 *
	 * @since x.x.x
	 */
	public function test_does_not_register_without_exposed_post_types(): void {
		foreach ( array( 'post', 'page' ) as $post_type ) {
			$object = get_post_type_object( $post_type );
			$this->assertNotFalse( $object, "Precondition: the {$post_type} post type should exist." );

			$object->show_in_abilities = false;
		}

		$this->register_ability();

		$this->assertFalse( wp_has_ability( self::ABILITY ), 'The search ability should not register without any exposed post types.' );
	}

	/**
	 * The input schema caps the page size at the documented context limit.
	 *
	 * @since x.x.x
	 */
	public function test_input_schema_caps_per_page(): void {
		$this->register_ability();

		$schema = wp_get_ability( self::ABILITY )->get_input_schema();

		$this->assertSame( 'object', $schema['type'], 'The input schema should describe an object.' );
		$this->assertSame( array( 'search' ), $schema['required'], 'A search term should be required.' );
		$this->assertFalse( $schema['additionalProperties'], 'The input schema should reject unrelated properties.' );
		$this->assertSame( 20, $schema['properties']['per_page']['maximum'], 'The page size should be capped at 20 items.' );
	}

	/**
	 * The output schema returns titles and excerpts, never full body content.
	 *
	 * @since x.x.x
	 */
	public function test_output_schema_never_exposes_body_content(): void {
		$this->register_ability();

		$schema = wp_get_ability( self::ABILITY )->get_output_schema();
		$item   = $schema['properties']['results']['items'];

		$this->assertSame( array( 'results', 'total', 'total_pages' ), $schema['required'], 'The output should always carry results and totals.' );
		$this->assertArrayHasKey( 'title', $item['properties'], 'Each result should carry a title.' );
		$this->assertArrayHasKey( 'excerpt', $item['properties'], 'Each result should carry an excerpt.' );
		$this->assertFalse( $item['additionalProperties'], 'Result items should reject unrelated properties.' );

		foreach ( array_keys( $item['properties'] ) as $property ) {
			$this->assertStringNotContainsString( 'content', (string) $property, 'Search results must not expose full body content.' );
		}
	}

	/**
	 * A logged-out invocation is denied before any query runs.
	 *
	 * @since x.x.x
	 */
	public function test_logged_out_request_returns_permission_error(): void {
		self::factory()->post->create(
			array(
				'post_title'  => 'Zebrafish migration',
				'post_status' => 'publish',
			)
		);

		wp_set_current_user( 0 );

		$result = $this->execute( array( 'search' => 'Zebrafish' ) );

		$this->assertInstanceOf( WP_Error::class, $result, 'Logged-out users should receive an error.' );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code(), 'Logged-out users should receive a permission error.' );
	}

	/**
	 * A search matching more posts than the cap returns at most 20 items with correct totals.
	 *
	 * @since x.x.x
	 */
	public function test_caps_results_at_twenty_and_reports_total_pages(): void {
		for ( $i = 0; $i < 50; $i++ ) {
			self::factory()->post->create(
				array(
					'post_title'  => 'Zebrafish report ' . $i,
					'post_status' => 'publish',
				)
			);
		}

		$this->login_as( 'editor' );

		$result = $this->execute(
			array(
				'search'   => 'Zebrafish',
				'per_page' => 20,
			)
		);

		$this->assertIsArray( $result, 'A matching search should return a result array.' );
		$this->assertCount( 20, $result['results'], 'The result set should be capped at 20 items.' );
		$this->assertSame( 50, $result['total'], 'The total should report every matching post.' );
		$this->assertSame( 3, $result['total_pages'], 'The total pages should be derived from the clamped page size.' );

		// Asking for more than the cap is rejected by the schema rather than honored.
		$rejected = $this->execute(
			array(
				'search'   => 'Zebrafish',
				'per_page' => 100,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $rejected, 'A page size above the cap should be rejected.' );
		$this->assertSame( 'ability_invalid_input', $rejected->get_error_code(), 'A page size above the cap is an input error.' );
	}

	/**
	 * The execute callback clamps the page size even when schema validation is bypassed.
	 *
	 * The cap is a context limit that must hold on any transport, so it is enforced in
	 * the callback as well as in the input schema.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_clamps_per_page_without_schema_validation(): void {
		for ( $i = 0; $i < 25; $i++ ) {
			self::factory()->post->create(
				array(
					'post_title'  => 'Zebrafish unclamped ' . $i,
					'post_status' => 'publish',
				)
			);
		}

		$this->login_as( 'editor' );
		$this->register_ability();

		$result = ( new Search_Content() )->execute_search_content(
			array(
				'search'   => 'Zebrafish',
				'per_page' => 500,
			)
		);

		$this->assertCount( 20, $result['results'], 'The callback should clamp the page size to 20 items.' );
	}

	/**
	 * Pagination beyond the first page returns a different slice of the same result set.
	 *
	 * @since x.x.x
	 */
	public function test_pagination_returns_later_pages(): void {
		for ( $i = 0; $i < 12; $i++ ) {
			self::factory()->post->create(
				array(
					'post_title'  => 'Zebrafish paging ' . $i,
					'post_status' => 'publish',
				)
			);
		}

		$this->login_as( 'editor' );

		$first = $this->execute(
			array(
				'search'   => 'Zebrafish',
				'per_page' => 5,
			)
		);

		$second = $this->execute(
			array(
				'search'   => 'Zebrafish',
				'per_page' => 5,
				'page'     => 2,
			)
		);

		$this->assertCount( 5, $first['results'], 'The first page should be full.' );
		$this->assertCount( 5, $second['results'], 'The second page should be full.' );
		$this->assertSame( 3, $first['total_pages'], 'Twelve matches at five per page span three pages.' );
		$this->assertSame(
			array(),
			array_intersect( $this->result_ids( $first ), $this->result_ids( $second ) ),
			'Pages should not overlap.'
		);
	}

	/**
	 * A page beyond the last one is an empty result set, not an error.
	 *
	 * @since x.x.x
	 */
	public function test_page_beyond_last_returns_empty_list_with_totals(): void {
		self::factory()->post->create(
			array(
				'post_title'  => 'Zebrafish solitary',
				'post_status' => 'publish',
			)
		);

		$this->login_as( 'editor' );

		$result = $this->execute(
			array(
				'search' => 'Zebrafish',
				'page'   => 5,
			)
		);

		$this->assertIsArray( $result, 'An out-of-range page should not be an error.' );
		$this->assertSame( array(), $result['results'], 'An out-of-range page should carry no results.' );
		$this->assertSame( 1, $result['total'], 'The total should still report the matching post.' );
		$this->assertSame( 1, $result['total_pages'], 'The total pages should still be reported.' );
	}

	/**
	 * A search with no matches returns an empty, well-formed result set.
	 *
	 * @since x.x.x
	 */
	public function test_zero_matches_returns_empty_list(): void {
		$this->login_as( 'editor' );

		$result = $this->execute( array( 'search' => 'Nothingmatchesthisterm' ) );

		$this->assertIsArray( $result, 'A search with no matches should not be an error.' );
		$this->assertSame( array(), $result['results'], 'No matches should return an empty list.' );
		$this->assertSame( 0, $result['total'], 'No matches should report a zero total.' );
		$this->assertSame( 0, $result['total_pages'], 'No matches should report zero pages.' );
	}

	/**
	 * The search term matches both titles and body content.
	 *
	 * @since x.x.x
	 */
	public function test_matches_titles_and_body_content(): void {
		$by_title = self::factory()->post->create(
			array(
				'post_title'   => 'Zebrafish in the title',
				'post_content' => 'Unrelated body.',
				'post_status'  => 'publish',
			)
		);

		$by_content = self::factory()->post->create(
			array(
				'post_title'   => 'Unrelated title',
				'post_content' => 'The body mentions Zebrafish once.',
				'post_status'  => 'publish',
			)
		);

		$this->login_as( 'editor' );

		$ids = $this->result_ids( $this->execute( array( 'search' => 'Zebrafish' ) ) );

		$this->assertContains( $by_title, $ids, 'A title match should be returned.' );
		$this->assertContains( $by_content, $ids, 'A body content match should be returned.' );
	}

	/**
	 * Results carry a title and an excerpt, and never the full body content.
	 *
	 * @since x.x.x
	 */
	public function test_results_return_titles_and_excerpts_only(): void {
		$body = 'Zebrafish body sentinel paragraph that must never be returned in full.';

		self::factory()->post->create(
			array(
				'post_title'   => 'Zebrafish excerpt subject',
				'post_content' => $body,
				'post_excerpt' => 'A short zebrafish summary.',
				'post_status'  => 'publish',
			)
		);

		$this->login_as( 'editor' );

		$result = $this->execute( array( 'search' => 'Zebrafish' ) );

		$this->assertCount( 1, $result['results'], 'The matching post should be returned.' );

		$item = $result['results'][0];

		$this->assertSame( 'Zebrafish excerpt subject', $item['title'], 'The rendered title should be returned.' );
		$this->assertSame( 'A short zebrafish summary.', $item['excerpt'], 'The excerpt should be returned as plain text.' );
		$this->assertArrayNotHasKey( 'content', $item, 'Full body content must not be returned.' );
		$this->assertNotContains( $body, $item, 'The body content must not appear in any returned field.' );
	}

	/**
	 * A draft by another author is withheld from a contributor but returned to an editor.
	 *
	 * The coarse status gate lets a contributor query drafts at all — they can edit
	 * their own — so this is the execute-time row filter, not the permission callback.
	 *
	 * @since x.x.x
	 */
	public function test_draft_by_another_author_is_absent_for_contributor_present_for_editor(): void {
		$draft_id = self::factory()->post->create(
			array(
				'post_author' => self::$user_ids['author_secondary'],
				'post_title'  => 'Zebrafish draft',
				'post_status' => 'draft',
			)
		);

		$this->login_as( 'contributor' );

		$contributor_ids = $this->result_ids(
			$this->execute(
				array(
					'search' => 'Zebrafish',
					'status' => array( 'draft' ),
				)
			)
		);

		$this->assertNotContains( $draft_id, $contributor_ids, "A contributor must not see another author's draft." );

		$this->login_as( 'editor' );

		$editor_ids = $this->result_ids(
			$this->execute(
				array(
					'search' => 'Zebrafish',
					'status' => array( 'draft' ),
				)
			)
		);

		$this->assertContains( $draft_id, $editor_ids, "An editor should see another author's draft." );
	}

	/**
	 * A pending post by another author is withheld from a contributor at execute time.
	 *
	 * @since x.x.x
	 */
	public function test_pending_post_by_another_author_is_absent_for_contributor(): void {
		$pending_id = self::factory()->post->create(
			array(
				'post_author' => self::$user_ids['author_secondary'],
				'post_title'  => 'Zebrafish pending',
				'post_status' => 'pending',
			)
		);

		$own_id = self::factory()->post->create(
			array(
				'post_author' => self::$user_ids['contributor'],
				'post_title'  => 'Zebrafish pending mine',
				'post_status' => 'pending',
			)
		);

		$this->login_as( 'contributor' );

		$ids = $this->result_ids(
			$this->execute(
				array(
					'search' => 'Zebrafish',
					'status' => array( 'pending' ),
				)
			)
		);

		$this->assertNotContains( $pending_id, $ids, "A contributor must not see another author's pending post." );
		$this->assertContains( $own_id, $ids, 'A contributor should still see their own pending post.' );
	}

	/**
	 * Another author's private post never reaches an author who cannot read it.
	 *
	 * @since x.x.x
	 */
	public function test_private_post_of_another_author_is_absent_for_author(): void {
		$private_id = self::factory()->post->create(
			array(
				'post_author' => self::$user_ids['author_secondary'],
				'post_title'  => 'Zebrafish private',
				'post_status' => 'private',
			)
		);

		$own_private_id = self::factory()->post->create(
			array(
				'post_author' => self::$user_ids['author'],
				'post_title'  => 'Zebrafish private mine',
				'post_status' => 'private',
			)
		);

		$this->login_as( 'author' );

		// The default (published) search must not surface the private post.
		$ids = $this->result_ids( $this->execute( array( 'search' => 'Zebrafish' ) ) );
		$this->assertNotContains( $private_id, $ids, "An author must not see another author's private post." );

		// Asking for private posts explicitly still withholds the one they cannot read.
		$private_ids = $this->result_ids(
			$this->execute(
				array(
					'search' => 'Zebrafish',
					'status' => array( 'private' ),
				)
			)
		);

		$this->assertNotContains( $private_id, $private_ids, "An author must not see another author's private post when asking for private posts." );
		$this->assertContains( $own_private_id, $private_ids, 'An author should still see their own private post.' );

		// An editor, who can read private posts, does see it.
		$this->login_as( 'editor' );

		$editor_ids = $this->result_ids(
			$this->execute(
				array(
					'search' => 'Zebrafish',
					'status' => array( 'private' ),
				)
			)
		);

		$this->assertContains( $private_id, $editor_ids, 'An editor should see the private post.' );
	}

	/**
	 * A subscriber cannot query drafts at all.
	 *
	 * @since x.x.x
	 */
	public function test_subscriber_draft_query_returns_permission_error(): void {
		$this->login_as( 'subscriber' );

		$result = $this->execute(
			array(
				'search' => 'Zebrafish',
				'status' => array( 'draft' ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result, 'Subscribers cannot query drafts.' );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code(), 'Subscriber draft queries should return a permission error.' );
	}

	/**
	 * A password-protected post's excerpt is withheld from a user lacking read access.
	 *
	 * @since x.x.x
	 */
	public function test_password_protected_excerpt_is_withheld_without_read_access(): void {
		$protected_id = self::factory()->post->create(
			array(
				'post_author'   => self::$user_ids['administrator'],
				'post_title'    => 'Zebrafish protected',
				'post_content'  => 'Hidden zebrafish body.',
				'post_excerpt'  => 'Hidden zebrafish excerpt.',
				'post_status'   => 'publish',
				'post_password' => 'secret',
			)
		);

		$this->login_as( 'subscriber' );

		$result = $this->execute( array( 'search' => 'Zebrafish' ) );
		$ids    = $this->result_ids( $result );
		$index  = array_search( $protected_id, $ids, true );

		$this->assertNotFalse( $index, 'A published password-protected post is still listed.' );
		$this->assertSame( '', $result['results'][ $index ]['excerpt'], 'The protected excerpt must be withheld.' );

		$this->login_as( 'administrator' );

		$admin_result = $this->execute( array( 'search' => 'Zebrafish' ) );
		$admin_ids    = $this->result_ids( $admin_result );
		$admin_index  = array_search( $protected_id, $admin_ids, true );

		$this->assertNotFalse( $admin_index, 'The administrator should still see the post.' );
		$this->assertSame(
			'Hidden zebrafish excerpt.',
			$admin_result['results'][ $admin_index ]['excerpt'],
			'An editor of the protected post should receive the real excerpt.'
		);
	}

	/**
	 * Posts of a post type not exposed to abilities are never returned.
	 *
	 * @since x.x.x
	 */
	public function test_unexposed_post_types_are_never_searched(): void {
		register_post_type(
			'wpai_search_cpt',
			array(
				'public'   => true,
				'supports' => array( 'title', 'editor', 'excerpt' ),
			)
		);

		try {
			$hidden_id = self::factory()->post->create(
				array(
					'post_type'   => 'wpai_search_cpt',
					'post_title'  => 'Zebrafish hidden type',
					'post_status' => 'publish',
				)
			);

			$this->login_as( 'administrator' );

			$ids = $this->result_ids( $this->execute( array( 'search' => 'Zebrafish' ) ) );

			$this->assertNotContains( $hidden_id, $ids, 'Unexposed post types must not be searched.' );
		} finally {
			unregister_post_type( 'wpai_search_cpt' );
		}
	}
}
