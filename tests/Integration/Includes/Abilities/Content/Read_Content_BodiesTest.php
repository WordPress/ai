<?php
/**
 * Integration tests for the ai/read-content-bodies Ability provided by the plugin.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Content
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Content;

use WP_Error;
use WP_UnitTestCase;
use WordPress\AI\Abilities\Content\Read_Content_Bodies;
use WordPress\AI\Abilities\Show_In_Abilities;

/**
 * Read_Content_Bodies ability test case.
 *
 * This ability returns whole post bodies rather than excerpts, so the tests are
 * weighted almost entirely towards leakage: every body it hands back must be a body
 * the requesting user could have read anyway, and the five-post cap must hold on a
 * transport that never validated the input schema.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Abilities\Content\Read_Content_Bodies
 */
class Read_Content_BodiesTest extends WP_UnitTestCase {

	/**
	 * The registered ability name.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const ABILITY = 'ai/read-content-bodies';

	/**
	 * Shared user IDs keyed by role or fixture name.
	 *
	 * @since x.x.x
	 *
	 * @var array<string, int>
	 */
	private static $user_ids = array();

	/**
	 * Creates the shared users for the read ability tests.
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
	 * Registers the plugin's ai/read-content-bodies ability inside a faked init action.
	 *
	 * @since x.x.x
	 */
	private function register_ability(): void {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			( new Read_Content_Bodies() )->register();
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
		$this->assertArrayHasKey( 'posts', $result, 'The result should carry a posts list.' );

		return array_map(
			static function ( array $item ): int {
				return (int) $item['id'];
			},
			$result['posts']
		);
	}

	/**
	 * Finds a returned post row by post ID.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $result  The ability result.
	 * @param int   $post_id The post ID to look for.
	 * @return array<string, mixed>|null The row, or null when absent.
	 */
	private function find_row( $result, int $post_id ): ?array {
		if ( ! is_array( $result ) || ! isset( $result['posts'] ) || ! is_array( $result['posts'] ) ) {
			return null;
		}

		foreach ( $result['posts'] as $row ) {
			if ( is_array( $row ) && isset( $row['id'] ) && (int) $row['id'] === $post_id ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * The ability is registered in the content category and flagged read-only.
	 *
	 * @since x.x.x
	 */
	public function test_registers_read_content_bodies_ability(): void {
		$this->register_ability();

		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability, 'The ai/read-content-bodies ability should be registered.' );
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

		$this->assertFalse( wp_has_ability( self::ABILITY ), 'The read ability should not register without any exposed post types.' );
	}

	/**
	 * The description tells the model the cap and the filtering, and names no other tool.
	 *
	 * The description is read by the model as a tool description. Naming an ability the
	 * workspace does not offer advertises a capability the assistant does not have.
	 *
	 * @since x.x.x
	 */
	public function test_description_states_the_cap_and_names_no_other_ability(): void {
		$this->register_ability();

		$description = wp_get_ability( self::ABILITY )->get_description();

		$this->assertStringContainsString( '5', $description, 'The description should state the five-post cap.' );
		$this->assertStringNotContainsString( 'core/read-content', $description, 'The description must not name an ability the workspace does not offer.' );
		$this->assertStringNotContainsString( 'ai/search-content', $description, 'The description must not name another ability by name.' );
	}

	/**
	 * The input schema caps the request at five posts.
	 *
	 * @since x.x.x
	 */
	public function test_input_schema_caps_the_request_at_five_posts(): void {
		$this->register_ability();

		$schema = wp_get_ability( self::ABILITY )->get_input_schema();

		$this->assertSame( 'object', $schema['type'], 'The input schema should describe an object.' );
		$this->assertSame( array( 'ids' ), $schema['required'], 'A list of post IDs should be required.' );
		$this->assertFalse( $schema['additionalProperties'], 'The input schema should reject unrelated properties.' );
		$this->assertSame( 5, $schema['properties']['ids']['maxItems'], 'At most five posts may be requested per call.' );
		$this->assertSame( 1, $schema['properties']['ids']['minItems'], 'At least one post must be requested.' );
	}

	/**
	 * A request for six posts is refused by the schema.
	 *
	 * @since x.x.x
	 */
	public function test_request_for_six_posts_is_refused(): void {
		$this->login_as( 'editor' );

		$ids = array();
		for ( $i = 0; $i < 6; $i++ ) {
			$ids[] = self::factory()->post->create(
				array(
					'post_title'   => 'Zebrafish over cap ' . $i,
					'post_content' => 'Body ' . $i,
					'post_status'  => 'publish',
				)
			);
		}

		$result = $this->execute( array( 'ids' => $ids ) );

		$this->assertInstanceOf( WP_Error::class, $result, 'A request for six posts should be rejected.' );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code(), 'A request above the cap is an input error.' );
	}

	/**
	 * The execute callback clamps to five posts even when schema validation is bypassed.
	 *
	 * The cap is a context limit that must hold on any transport, so it is enforced in
	 * the callback as well as in the input schema.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_clamps_to_five_without_schema_validation(): void {
		$this->login_as( 'editor' );

		$ids = array();
		for ( $i = 0; $i < 12; $i++ ) {
			$ids[] = self::factory()->post->create(
				array(
					'post_title'   => 'Zebrafish unclamped ' . $i,
					'post_content' => 'Body ' . $i,
					'post_status'  => 'publish',
				)
			);
		}

		$this->register_ability();

		$result = ( new Read_Content_Bodies() )->execute_read_content_bodies( array( 'ids' => $ids ) );

		$this->assertCount( 5, $result['posts'], 'The callback should clamp the request to five posts.' );
	}

	/**
	 * A logged-out invocation is denied before any post is loaded.
	 *
	 * @since x.x.x
	 */
	public function test_logged_out_request_returns_permission_error(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Zebrafish public',
				'post_content' => 'A published body.',
				'post_status'  => 'publish',
			)
		);

		wp_set_current_user( 0 );

		$result = $this->execute( array( 'ids' => array( $post_id ) ) );

		$this->assertInstanceOf( WP_Error::class, $result, 'Logged-out users should receive an error.' );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code(), 'Logged-out users should receive a permission error.' );
	}

	/**
	 * A readable post comes back with its full body as plain text.
	 *
	 * @since x.x.x
	 */
	public function test_returns_the_full_body_of_a_readable_post(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Zebrafish readable',
				'post_content' => "<p>First paragraph sentinel.</p>\n<p>Second paragraph sentinel.</p>",
				'post_status'  => 'publish',
			)
		);

		$this->login_as( 'editor' );

		$result = $this->execute( array( 'ids' => array( $post_id ) ) );
		$row    = $this->find_row( $result, $post_id );

		$this->assertNotNull( $row, 'A readable post should be returned.' );
		$this->assertSame( 'Zebrafish readable', $row['title'] );
		$this->assertStringContainsString( 'First paragraph sentinel.', $row['content'], 'The whole body should be returned, not an excerpt.' );
		$this->assertStringContainsString( 'Second paragraph sentinel.', $row['content'], 'The whole body should be returned, not an excerpt.' );
		$this->assertFalse( $row['content_protected'], 'An unprotected post is not reported as protected.' );
		$this->assertSame( array(), $result['unavailable'], 'Nothing was withheld.' );
	}

	/**
	 * An unknown or unreadable ID is reported as unavailable rather than as an error.
	 *
	 * The two are deliberately indistinguishable: a caller cannot tell a post that does
	 * not exist from one they may not read.
	 *
	 * @since x.x.x
	 */
	public function test_unknown_id_is_reported_as_unavailable(): void {
		$this->login_as( 'editor' );

		$result = $this->execute( array( 'ids' => array( 99999999 ) ) );

		$this->assertIsArray( $result, 'An unknown ID should not be an error.' );
		$this->assertSame( array(), $result['posts'], 'An unknown ID returns no post.' );
		$this->assertSame( array( 99999999 ), $result['unavailable'], 'The unreturned ID should be reported.' );
	}

	/**
	 * Another author's private body is never returned to a user who cannot read it.
	 *
	 * @since x.x.x
	 */
	public function test_private_body_of_another_author_is_withheld_from_author(): void {
		$secret     = 'Zebrafish private body sentinel.';
		$private_id = self::factory()->post->create(
			array(
				'post_author'  => self::$user_ids['author_secondary'],
				'post_title'   => 'Zebrafish private',
				'post_content' => $secret,
				'post_status'  => 'private',
			)
		);

		$this->login_as( 'author' );

		$result = $this->execute( array( 'ids' => array( $private_id ) ) );

		$this->assertNotContains( $private_id, $this->result_ids( $result ), "An author must not receive another author's private body." );
		$this->assertSame( array( $private_id ), $result['unavailable'] );
		$this->assertStringNotContainsString(
			$secret,
			(string) wp_json_encode( $result ),
			'The private body must not appear anywhere in the ability output.'
		);

		// An editor, who can read private posts, does receive it.
		$this->login_as( 'editor' );

		$editor_result = $this->execute( array( 'ids' => array( $private_id ) ) );
		$editor_row    = $this->find_row( $editor_result, $private_id );

		$this->assertNotNull( $editor_row, 'An editor should receive the private post.' );
		$this->assertStringContainsString( $secret, $editor_row['content'] );
	}

	/**
	 * Another author's draft body is withheld from a contributor and a subscriber.
	 *
	 * @since x.x.x
	 */
	public function test_draft_body_of_another_author_is_withheld_from_lower_roles(): void {
		$secret   = 'Zebrafish draft body sentinel.';
		$draft_id = self::factory()->post->create(
			array(
				'post_author'  => self::$user_ids['author_secondary'],
				'post_title'   => 'Zebrafish draft',
				'post_content' => $secret,
				'post_status'  => 'draft',
			)
		);

		foreach ( array( 'subscriber', 'contributor', 'author' ) as $role ) {
			$this->login_as( $role );

			$result = $this->execute( array( 'ids' => array( $draft_id ) ) );

			$this->assertSame( array(), $result['posts'], sprintf( 'A %s must not receive another author\'s draft body.', $role ) );
			$this->assertStringNotContainsString(
				$secret,
				(string) wp_json_encode( $result ),
				sprintf( 'The draft body must not appear anywhere in the output for a %s.', $role )
			);
		}

		$this->login_as( 'editor' );

		$editor_row = $this->find_row( $this->execute( array( 'ids' => array( $draft_id ) ) ), $draft_id );

		$this->assertNotNull( $editor_row, 'An editor should receive the draft.' );
		$this->assertStringContainsString( $secret, $editor_row['content'] );
	}

	/**
	 * A contributor still receives their own draft body.
	 *
	 * @since x.x.x
	 */
	public function test_contributor_receives_their_own_draft_body(): void {
		$contributor_id = self::$user_ids['contributor'];

		$own_id = self::factory()->post->create(
			array(
				'post_author'  => $contributor_id,
				'post_title'   => 'Zebrafish mine',
				'post_content' => 'My own draft body.',
				'post_status'  => 'draft',
			)
		);

		$this->login_as( 'contributor' );

		$row = $this->find_row( $this->execute( array( 'ids' => array( $own_id ) ) ), $own_id );

		$this->assertNotNull( $row, 'A contributor should receive their own draft.' );
		$this->assertStringContainsString( 'My own draft body.', $row['content'] );
	}

	/**
	 * A password-protected body is withheld from a user lacking access and returned to an editor of it.
	 *
	 * @since x.x.x
	 */
	public function test_password_protected_body_is_withheld_without_edit_access(): void {
		$secret       = 'Hidden zebrafish body.';
		$protected_id = self::factory()->post->create(
			array(
				'post_author'   => self::$user_ids['administrator'],
				'post_title'    => 'Zebrafish protected',
				'post_content'  => $secret,
				'post_status'   => 'publish',
				'post_password' => 'secret',
			)
		);

		foreach ( array( 'subscriber', 'contributor', 'author' ) as $role ) {
			$this->login_as( $role );

			$result = $this->execute( array( 'ids' => array( $protected_id ) ) );
			$row    = $this->find_row( $result, $protected_id );

			$this->assertNotNull( $row, 'A published password-protected post is still listed.' );
			$this->assertSame( '', $row['content'], sprintf( 'The protected body must be withheld from a %s.', $role ) );
			$this->assertTrue( $row['content_protected'], 'The row should say the body is password protected.' );
			$this->assertStringNotContainsString(
				$secret,
				(string) wp_json_encode( $result ),
				sprintf( 'The protected body must not appear anywhere in the output for a %s.', $role )
			);
		}

		$this->login_as( 'editor' );

		$editor_row = $this->find_row( $this->execute( array( 'ids' => array( $protected_id ) ) ), $protected_id );

		$this->assertNotNull( $editor_row, 'An editor should still see the post.' );
		$this->assertStringContainsString( $secret, $editor_row['content'], 'A user who can edit the protected post receives its body.' );
		$this->assertTrue( $editor_row['content_protected'], 'The row still reports that the post is password protected.' );
	}

	/**
	 * Posts of a post type not exposed to abilities are never returned.
	 *
	 * @since x.x.x
	 */
	public function test_unexposed_post_types_are_never_read(): void {
		register_post_type(
			'wpai_read_cpt',
			array(
				'public'   => true,
				'supports' => array( 'title', 'editor', 'excerpt' ),
			)
		);

		try {
			$hidden_id = self::factory()->post->create(
				array(
					'post_type'    => 'wpai_read_cpt',
					'post_title'   => 'Zebrafish hidden type',
					'post_content' => 'Hidden type body sentinel.',
					'post_status'  => 'publish',
				)
			);

			$this->login_as( 'administrator' );

			$result = $this->execute( array( 'ids' => array( $hidden_id ) ) );

			$this->assertSame( array(), $result['posts'], 'Unexposed post types must not be read.' );
			$this->assertStringNotContainsString( 'Hidden type body sentinel.', (string) wp_json_encode( $result ) );
		} finally {
			unregister_post_type( 'wpai_read_cpt' );
		}
	}

	/**
	 * A mixed request returns only the readable posts and reports the rest.
	 *
	 * @since x.x.x
	 */
	public function test_mixed_request_returns_only_readable_posts(): void {
		$readable_id = self::factory()->post->create(
			array(
				'post_title'   => 'Zebrafish readable mixed',
				'post_content' => 'Readable body.',
				'post_status'  => 'publish',
			)
		);

		$unreadable_id = self::factory()->post->create(
			array(
				'post_author'  => self::$user_ids['author_secondary'],
				'post_title'   => 'Zebrafish unreadable mixed',
				'post_content' => 'Unreadable body sentinel.',
				'post_status'  => 'draft',
			)
		);

		$this->login_as( 'contributor' );

		$result = $this->execute( array( 'ids' => array( $readable_id, $unreadable_id ) ) );

		$this->assertSame( array( $readable_id ), $this->result_ids( $result ) );
		$this->assertSame( array( $unreadable_id ), $result['unavailable'] );
		$this->assertStringNotContainsString( 'Unreadable body sentinel.', (string) wp_json_encode( $result ) );
	}

	/**
	 * A user who can edit a post receives its editor URL, and one who cannot does not.
	 *
	 * @since x.x.x
	 */
	public function test_edit_link_tracks_edit_capability(): void {
		$author_id = $this->login_as( 'author' );

		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Editlinkable body subject',
				'post_content' => 'A body.',
				'post_status'  => 'publish',
				'post_author'  => $author_id,
			)
		);

		$row = $this->find_row( $this->execute( array( 'ids' => array( $post_id ) ) ), $post_id );

		$this->assertNotNull( $row );
		$this->assertNotSame( '', $row['edit_link'], 'A post the user can edit should carry an editor URL.' );

		$this->login_as( 'contributor' );

		$contributor_row = $this->find_row( $this->execute( array( 'ids' => array( $post_id ) ) ), $post_id );

		$this->assertNotNull( $contributor_row, 'A published post should be readable by a contributor.' );
		$this->assertSame( '', $contributor_row['edit_link'], 'A post the user cannot edit must carry no editor URL.' );
	}
}
