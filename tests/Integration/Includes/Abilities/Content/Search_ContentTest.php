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
	 * Post ID whose read capability the current test denies, or 0 for none.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	private $denied_post_id = 0;

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

		$this->assertSame( array( 'results', 'total', 'total_pages', 'examined', 'withheld' ), $schema['required'], 'The output should always carry results, totals, the examined count and the withheld count.' );
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

	/**
	 * A user who can edit a post receives its editor URL.
	 *
	 * @since x.x.x
	 */
	public function test_edit_link_is_returned_for_a_post_the_user_can_edit(): void {
		$author_id = $this->login_as( 'author' );

		$post_id = self::factory()->post->create(
			array(
				'post_title'  => 'Editlinkable subject',
				'post_status' => 'publish',
				'post_author' => $author_id,
			)
		);

		$result = $this->execute( array( 'search' => 'Editlinkable' ) );
		$row    = $this->find_row( $result, $post_id );

		$this->assertNotNull( $row, 'The author should see their own post.' );
		$this->assertNotSame( '', $row['edit_link'], 'A post the user can edit should carry an editor URL.' );
		$this->assertStringContainsString( (string) $post_id, $row['edit_link'], 'The editor URL should address this post.' );
		$this->assertTrue( current_user_can( 'edit_post', $post_id ), 'Guard: the fixture user must actually be able to edit.' );
	}

	/**
	 * A user who can read but not edit a post receives no editor URL for it.
	 *
	 * @since x.x.x
	 */
	public function test_edit_link_is_withheld_for_a_post_the_user_cannot_edit(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => 'Uneditable subject',
				'post_status' => 'publish',
				'post_author' => self::$user_ids['author_secondary'],
			)
		);

		$this->login_as( 'contributor' );

		$result = $this->execute( array( 'search' => 'Uneditable' ) );
		$row    = $this->find_row( $result, $post_id );

		$this->assertNotNull( $row, 'A published post should be readable by a contributor.' );
		$this->assertFalse( current_user_can( 'edit_post', $post_id ), 'Guard: the contributor must not be able to edit.' );
		$this->assertSame( '', $row['edit_link'], 'A post the user cannot edit must carry no editor URL.' );
	}

	/**
	 * Finds a result row by post ID.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $result  The ability result.
	 * @param int   $post_id The post ID to look for.
	 * @return array<string, mixed>|null The row, or null when absent.
	 */
	private function find_row( $result, int $post_id ): ?array {
		if ( ! is_array( $result ) || ! isset( $result['results'] ) || ! is_array( $result['results'] ) ) {
			return null;
		}

		foreach ( $result['results'] as $row ) {
			if ( is_array( $row ) && isset( $row['id'] ) && (int) $row['id'] === $post_id ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Pagination alone never counts as withholding.
	 *
	 * This is the case the `withheld` field exists to keep honest: `total` far exceeds
	 * the returned rows here, but every one of the missing posts is on a later page and
	 * readable, so nothing was kept back from this person. A count derived by
	 * subtracting the page size from the total would report 30 posts hidden by role on
	 * an ordinary search.
	 *
	 * @since x.x.x
	 */
	public function test_pagination_alone_reports_nothing_withheld(): void {
		for ( $i = 0; $i < 50; $i++ ) {
			self::factory()->post->create(
				array(
					'post_title'  => 'Zebrafish paged ' . $i,
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
		$this->assertCount( 20, $result['results'], 'Guard: the page should be full.' );
		$this->assertSame( 50, $result['total'], 'Guard: the total should far exceed the page.' );
		$this->assertSame( 0, $result['withheld'], 'Rows on later pages are paginated away, not withheld.' );
	}

	/**
	 * An out-of-range page withholds nothing, however large the total.
	 *
	 * @since x.x.x
	 */
	public function test_page_beyond_last_reports_nothing_withheld(): void {
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
		$this->assertSame( array(), $result['results'], 'Guard: an out-of-range page carries no rows.' );
		$this->assertSame( 1, $result['total'], 'Guard: the total still reports the matching post.' );
		$this->assertSame( 0, $result['withheld'], 'A page past the end withheld nothing; it simply has no rows.' );
	}

	/**
	 * A search with no matches withholds nothing.
	 *
	 * @since x.x.x
	 */
	public function test_zero_matches_reports_nothing_withheld(): void {
		$this->login_as( 'editor' );

		$result = $this->execute( array( 'search' => 'Nothingmatchesthisterm' ) );

		$this->assertIsArray( $result, 'A search with no matches should not be an error.' );
		$this->assertSame( 0, $result['withheld'], 'Nothing matched, so nothing was withheld.' );
	}

	/**
	 * The withheld count is exactly the rows the execute-time permission walk dropped.
	 *
	 * The query is deliberately spread over two post types so `WP_Query` runs without
	 * the `perm` gate and hands the row filter rows it must reject. The capability is
	 * denied through `map_meta_cap`, which is how a plugin narrows read access in
	 * practice, and it is denied for exactly one of the three matching drafts.
	 *
	 * @since x.x.x
	 */
	public function test_withheld_counts_the_rows_the_permission_walk_dropped(): void {
		$editor_id = self::$user_ids['editor'];
		$draft_ids = array();

		for ( $i = 0; $i < 3; $i++ ) {
			$draft_ids[] = self::factory()->post->create(
				array(
					'post_title'  => 'Zebrafish restricted ' . $i,
					'post_status' => 'draft',
					'post_author' => $editor_id,
				)
			);
		}

		$this->login_as( 'editor' );

		$input = array(
			'search'    => 'Zebrafish',
			'status'    => array( 'draft' ),
			'post_type' => array( 'post', 'page' ),
		);

		$before = $this->execute( $input );

		$this->assertIsArray( $before, 'Guard: the search should succeed.' );
		$this->assertCount( 3, $before['results'], 'Guard: all three drafts should be readable to start with.' );
		$this->assertSame( 0, $before['withheld'], 'Guard: nothing is withheld before the capability is denied.' );

		$this->denied_post_id = $draft_ids[1];
		add_filter( 'map_meta_cap', array( $this, 'deny_read_for_denied_post' ), 10, 4 );

		try {
			$result = $this->execute( $input );
		} finally {
			remove_filter( 'map_meta_cap', array( $this, 'deny_read_for_denied_post' ), 10 );
			$this->denied_post_id = 0;
		}

		$this->assertIsArray( $result, 'The search should still succeed.' );
		$this->assertNotContains( $draft_ids[1], $this->result_ids( $result ), 'The unreadable draft must not be returned.' );
		$this->assertCount( 2, $result['results'], 'The two readable drafts should still be returned.' );
		$this->assertSame( 3, $result['total'], 'Guard: the underlying query still matched three posts.' );
		$this->assertSame( 1, $result['withheld'], 'Exactly one row was dropped by the permission walk.' );
	}

	/**
	 * The withheld count never names what was withheld.
	 *
	 * @since x.x.x
	 */
	public function test_withheld_count_carries_no_identifying_detail(): void {
		$editor_id = self::$user_ids['editor'];

		$secret_id = self::factory()->post->create(
			array(
				'post_title'  => 'Zebrafish confidential codename',
				'post_status' => 'draft',
				'post_author' => $editor_id,
			)
		);

		self::factory()->post->create(
			array(
				'post_title'  => 'Zebrafish ordinary',
				'post_status' => 'draft',
				'post_author' => $editor_id,
			)
		);

		$this->login_as( 'editor' );

		$this->denied_post_id = $secret_id;
		add_filter( 'map_meta_cap', array( $this, 'deny_read_for_denied_post' ), 10, 4 );

		try {
			$result = $this->execute(
				array(
					'search'    => 'Zebrafish',
					'status'    => array( 'draft' ),
					'post_type' => array( 'post', 'page' ),
				)
			);
		} finally {
			remove_filter( 'map_meta_cap', array( $this, 'deny_read_for_denied_post' ), 10 );
			$this->denied_post_id = 0;
		}

		$this->assertIsArray( $result, 'The search should succeed.' );
		$this->assertSame( 1, $result['withheld'], 'Guard: one row was withheld.' );
		$this->assertStringNotContainsString(
			'confidential codename',
			wp_json_encode( $result ),
			'The withheld count must never carry the content it stands for.'
		);
		$this->assertStringNotContainsString(
			(string) $secret_id,
			wp_json_encode( $result['withheld'] ),
			'The withheld count is a number, not an identifier.'
		);
	}

	/**
	 * The output schema declares the withheld count and says what it excludes.
	 *
	 * @since x.x.x
	 */
	public function test_output_schema_declares_withheld(): void {
		$this->register_ability();

		$schema = wp_get_ability( self::ABILITY )->get_output_schema();

		$this->assertSame(
			array( 'results', 'total', 'total_pages', 'examined', 'withheld' ),
			$schema['required'],
			'The output should always carry the withheld count.'
		);
		$this->assertSame(
			array( 'integer', 'null' ),
			$schema['properties']['withheld']['type'],
			'The withheld count is an integer or null; null is how the ability declines to claim a number.'
		);
		$this->assertStringContainsString(
			'pagination',
			$schema['properties']['withheld']['description'],
			'The description must say that pagination is not counted.'
		);
		$this->assertStringContainsString(
			'null',
			$schema['properties']['withheld']['description'],
			'The description must say when no count is reported at all.'
		);
		$this->assertSame( 'integer', $schema['properties']['examined']['type'], 'The examined count is an integer.' );
	}

	/**
	 * A post type dropped for permission reasons reports no withheld count at all.
	 *
	 * A contributor may query drafts of posts but not of pages, so the page post type
	 * is removed before the query is built and its rows never reach the per-row walk.
	 * The page-level count is then not the whole permission story, and reporting it as
	 * an integer would state "nothing else was kept back" on the strength of a check
	 * that never saw those rows. Null is the only honest answer, and it is not 0.
	 *
	 * @since x.x.x
	 */
	public function test_post_type_dropped_by_the_status_gate_reports_no_withheld_count(): void {
		$contributor_id = self::$user_ids['contributor'];

		self::factory()->post->create(
			array(
				'post_title'  => 'Zebrafish contributor draft',
				'post_status' => 'draft',
				'post_author' => $contributor_id,
			)
		);

		self::factory()->post->create(
			array(
				'post_title'  => 'Zebrafish page draft',
				'post_type'   => 'page',
				'post_status' => 'draft',
				'post_author' => $contributor_id,
			)
		);

		$this->login_as( 'contributor' );

		$narrowed = $this->execute(
			array(
				'search'    => 'Zebrafish',
				'status'    => array( 'draft' ),
				'post_type' => array( 'post' ),
			)
		);

		$this->assertIsArray( $narrowed, 'Guard: searching only the post type the role may query should succeed.' );
		$this->assertSame( 0, $narrowed['withheld'], 'Guard: with nothing dropped, the page-level count is the whole story and it is 0.' );

		$result = $this->execute(
			array(
				'search' => 'Zebrafish',
				'status' => array( 'draft' ),
			)
		);

		$this->assertIsArray( $result, 'Searching every exposed post type should still succeed for the types that pass the gate.' );
		$this->assertNotEmpty( $result['results'], 'Guard: the contributor\'s own draft post is still searchable.' );
		$this->assertNull(
			$result['withheld'],
			'A whole post type was excluded for permission reasons, so no page-level number stands for the outcome; 0 would be a false claim that nothing was kept back.'
		);
	}

	/**
	 * An empty search term still reports no withheld count when a post type was dropped.
	 *
	 * The early return builds its own result, so it has to make the same claim the
	 * queried path does: a post type excluded by the status gate means no page-level
	 * number can stand for the permission outcome, and 0 would say one did.
	 *
	 * @since x.x.x
	 */
	public function test_search_with_no_term_reports_no_withheld_count_when_a_post_type_was_dropped(): void {
		$this->login_as( 'contributor' );

		$result = $this->execute(
			array(
				'search' => '   ',
				'status' => array( 'draft' ),
			)
		);

		$this->assertIsArray( $result, 'An effectively empty search term returns an empty result set, not an error.' );
		$this->assertSame( array(), $result['results'], 'Guard: no term, no rows.' );
		$this->assertNull( $result['withheld'], 'The page post type was still dropped for permission reasons, so no count is claimed.' );
	}

	/**
	 * The examined count is the rows the page was built from, before the permission walk.
	 *
	 * This is the number a consumer must render as "searched": the returned rows have
	 * already had the withheld ones taken out of them, so presenting them beside the
	 * withheld count would show two numbers that do not reconcile.
	 *
	 * @since x.x.x
	 */
	public function test_examined_counts_the_rows_before_the_permission_walk(): void {
		$editor_id = self::$user_ids['editor'];
		$draft_ids = array();

		for ( $i = 0; $i < 3; $i++ ) {
			$draft_ids[] = self::factory()->post->create(
				array(
					'post_title'  => 'Zebrafish examined ' . $i,
					'post_status' => 'draft',
					'post_author' => $editor_id,
				)
			);
		}

		$this->login_as( 'editor' );

		$input = array(
			'search'    => 'Zebrafish',
			'status'    => array( 'draft' ),
			'post_type' => array( 'post', 'page' ),
		);

		$this->denied_post_id = $draft_ids[1];
		add_filter( 'map_meta_cap', array( $this, 'deny_read_for_denied_post' ), 10, 4 );

		try {
			$result = $this->execute( $input );
		} finally {
			remove_filter( 'map_meta_cap', array( $this, 'deny_read_for_denied_post' ), 10 );
			$this->denied_post_id = 0;
		}

		$this->assertIsArray( $result, 'The search should succeed.' );
		$this->assertSame( 3, $result['examined'], 'All three matching drafts were looked at.' );
		$this->assertCount( 2, $result['results'], 'Guard: only two survived the permission walk.' );
		$this->assertSame( 1, $result['withheld'], 'Guard: one row was dropped.' );
		$this->assertGreaterThan(
			count( $result['results'] ),
			$result['examined'],
			'A page that withheld a row examined more rows than it returned.'
		);
		$this->assertSame(
			$result['examined'] - $result['withheld'],
			count( $result['results'] ),
			'Examined minus withheld is exactly what came back.'
		);
	}

	/**
	 * The examined count counts only this page, never the matches on later pages.
	 *
	 * @since x.x.x
	 */
	public function test_examined_counts_only_the_current_page(): void {
		for ( $i = 0; $i < 30; $i++ ) {
			self::factory()->post->create(
				array(
					'post_title'  => 'Zebrafish examined paged ' . $i,
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
		$this->assertSame( 30, $result['total'], 'Guard: the total covers every page.' );
		$this->assertSame( 20, $result['examined'], 'Only the rows this page was built from are examined.' );
		$this->assertSame( count( $result['results'] ), $result['examined'], 'Nothing was withheld, so examined and returned agree.' );
		$this->assertSame( 0, $result['withheld'], 'Guard: later pages are pagination, not withholding.' );
	}

	/**
	 * Denies `read_post` for the one post a test marked as unreadable.
	 *
	 * @since x.x.x
	 *
	 * @param array<int, string> $caps    The mapped primitive capabilities.
	 * @param string             $cap     The capability being mapped.
	 * @param int                $user_id The user ID.
	 * @param array<int, mixed>  $args    The capability arguments.
	 * @return array<int, string> The mapped capabilities.
	 */
	public function deny_read_for_denied_post( array $caps, string $cap, int $user_id, array $args ): array {
		unset( $user_id );

		if ( 'read_post' !== $cap || 0 === $this->denied_post_id ) {
			return $caps;
		}

		if ( ! isset( $args[0] ) || (int) $args[0] !== $this->denied_post_id ) {
			return $caps;
		}

		return array( 'do_not_allow' );
	}
}
