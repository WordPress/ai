<?php
/**
 * Integration tests for AI Workspace chat storage.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace
 */

namespace WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace;

use WP_UnitTestCase;
use WordPress\AI\Experiments\AI_Workspace\Conversation_Store;

/**
 * Conversation_Store test case.
 *
 * Chats are private posts kept until their owner deletes them. These tests pin
 * who can reach a chat, what survives WordPress's own cleanup, and that a stored
 * transcript comes back exactly as it was written.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Experiments\AI_Workspace\Conversation_Store
 */
class Conversation_StoreTest extends WP_UnitTestCase {

	/**
	 * Shared user IDs keyed by role.
	 *
	 * @since x.x.x
	 *
	 * @var array<string, int>
	 */
	private static $user_ids = array();

	/**
	 * Creates the shared users.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_UnitTest_Factory $factory The unit test factory.
	 */
	public static function wpSetUpBeforeClass( $factory ): void {
		self::$user_ids = array(
			'owner'  => $factory->user->create( array( 'role' => 'administrator' ) ),
			'peer'   => $factory->user->create( array( 'role' => 'administrator' ) ),
			'editor' => $factory->user->create( array( 'role' => 'editor' ) ),
		);
	}

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		Conversation_Store::register_post_type();
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Unregisters the chat post type and archived status, as when the experiment is off.
	 *
	 * The next test's set-up registers them again.
	 *
	 * @since x.x.x
	 */
	private function unregister_chat_types(): void {
		unregister_post_type( Conversation_Store::POST_TYPE );
		unset( $GLOBALS['wp_post_statuses'][ Conversation_Store::STATUS_ARCHIVED ] );
	}

	/**
	 * Saves a chat for a user and returns its conversation.
	 *
	 * @since x.x.x
	 *
	 * @param int    $user_id The owner.
	 * @param string $prompt  The first message.
	 * @return array<string, mixed> The conversation.
	 */
	private function save_chat( int $user_id, string $prompt = 'Hello there' ): array {
		$store        = new Conversation_Store();
		$conversation = $store->create( $user_id, 'site' );

		$conversation['entries'] = array(
			array(
				'prompt' => $prompt,
				'text'   => 'Hi.',
			),
		);

		$this->assertTrue( $store->save( $conversation ) );

		return $conversation;
	}

	/**
	 * Returns the post a chat is stored as.
	 *
	 * @since x.x.x
	 *
	 * @param string $conversation_id The conversation ID.
	 * @return \WP_Post|null The post.
	 */
	private function chat_post( string $conversation_id ): ?\WP_Post {
		$posts = get_posts( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.get_posts_get_posts -- A test fixture lookup.
			array(
				'post_type'   => Conversation_Store::POST_TYPE,
				'post_status' => array( Conversation_Store::STATUS_ACTIVE, Conversation_Store::STATUS_ARCHIVED ),
				'name'        => $conversation_id,
			)
		);

		return $posts[0] ?? null;
	}

	/**
	 * A first save stores one private post owned by the user, named after the first message.
	 *
	 * @since x.x.x
	 */
	public function test_first_save_creates_one_chat_named_after_the_first_message(): void {
		$owner        = self::$user_ids['owner'];
		$long         = str_repeat( 'Plan a series about composting at home ', 4 );
		$conversation = $this->save_chat( $owner, $long );

		$post = $this->chat_post( $conversation['id'] );

		$this->assertInstanceOf( \WP_Post::class, $post );
		$this->assertSame( $owner, (int) $post->post_author );
		$this->assertSame( Conversation_Store::STATUS_ACTIVE, $post->post_status );
		$this->assertSame( '', $post->post_content );
		$this->assertLessThanOrEqual( Conversation_Store::AUTO_NAME_LENGTH, mb_strlen( $post->post_title ) );
		$this->assertStringStartsWith( 'Plan a series about composting', $post->post_title );

		$stored = ( new Conversation_Store() )->get( $conversation['id'], $owner );

		$this->assertIsArray( $stored );
		$this->assertSame( $post->post_title, $stored['name'] );
		$this->assertFalse( $stored['archived'] );
	}

	/**
	 * Saving the same conversation again updates its chat and keeps its name.
	 *
	 * @since x.x.x
	 */
	public function test_a_later_save_updates_the_same_chat(): void {
		$owner        = self::$user_ids['owner'];
		$store        = new Conversation_Store();
		$conversation = $this->save_chat( $owner, 'First question' );

		$conversation['entries'][] = array( 'prompt' => 'Second question' );
		$store->save( $conversation );

		$posts = get_posts( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.get_posts_get_posts -- A test fixture lookup.
			array(
				'post_type'   => Conversation_Store::POST_TYPE,
				'post_status' => 'any',
				'author'      => $owner,
			)
		);

		$this->assertCount( 1, $posts );
		$this->assertSame( 'First question', $posts[0]->post_title );
		$this->assertCount( 2, $store->get( $conversation['id'], $owner )['entries'] );
	}

	/**
	 * Another user's save cannot take over a chat by reusing its ID.
	 *
	 * @since x.x.x
	 */
	public function test_save_never_writes_over_another_users_chat(): void {
		$store        = new Conversation_Store();
		$conversation = $this->save_chat( self::$user_ids['owner'] );

		$stolen             = $conversation;
		$stolen['user_id']  = self::$user_ids['peer'];
		$stolen['messages'] = array( array( 'role' => 'user' ) );

		$this->assertFalse( $store->save( $stolen ) );
		$this->assertNull( $store->get( $conversation['id'], self::$user_ids['peer'] ) );
		$this->assertSame( array(), $store->get( $conversation['id'], self::$user_ids['owner'] )['messages'] );
	}

	/**
	 * Another administrator holds no capability over the chat.
	 *
	 * @since x.x.x
	 */
	public function test_another_administrator_cannot_read_or_edit_the_chat(): void {
		$conversation = $this->save_chat( self::$user_ids['owner'] );
		$post_id      = $this->chat_post( $conversation['id'] )->ID;

		$owner = self::$user_ids['owner'];
		$peer  = self::$user_ids['peer'];

		$this->assertTrue( user_can( $owner, 'read_post', $post_id ) );
		$this->assertTrue( user_can( $owner, 'edit_post', $post_id ) );
		$this->assertTrue( user_can( $owner, 'delete_post', $post_id ) );

		$this->assertFalse( user_can( $peer, 'read_post', $post_id ), 'Another administrator must not read the chat.' );
		$this->assertFalse( user_can( $peer, 'edit_post', $post_id ), 'Another administrator must not edit the chat.' );
		$this->assertFalse( user_can( $peer, 'delete_post', $post_id ), 'Another administrator must not delete the chat.' );
	}

	/**
	 * An archived chat survives the scheduled trash cleanup, and restoring it lists it again.
	 *
	 * @since x.x.x
	 */
	public function test_archived_chat_survives_scheduled_delete_and_can_be_restored(): void {
		$owner        = self::$user_ids['owner'];
		$store        = new Conversation_Store();
		$conversation = $this->save_chat( $owner );

		$this->assertTrue( $store->set_archived( $conversation['id'], $owner, true ) );
		$this->assertSame( array(), $store->list_for_user( $owner, false, 1, 20 )['items'] );

		wp_scheduled_delete();

		$this->assertIsArray( $store->get( $conversation['id'], $owner ) );
		$this->assertSame( array( $conversation['id'] ), wp_list_pluck( $store->list_for_user( $owner, true, 1, 20 )['items'], 'id' ) );

		$this->assertTrue( $store->set_archived( $conversation['id'], $owner, false ) );
		$this->assertSame( array( $conversation['id'] ), wp_list_pluck( $store->list_for_user( $owner, false, 1, 20 )['items'], 'id' ) );
	}

	/**
	 * Deleting a user and reassigning their content gives the new owner none of their chats.
	 *
	 * @since x.x.x
	 */
	public function test_deleting_a_user_deletes_their_chats_before_reassignment(): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';

		$leaving      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$heir         = self::$user_ids['peer'];
		$conversation = $this->save_chat( $leaving );
		$post         = self::factory()->post->create( array( 'post_author' => $leaving ) );

		if ( is_multisite() ) {
			remove_user_from_blog( $leaving, get_current_blog_id(), $heir );
		} else {
			wp_delete_user( $leaving, $heir );
		}

		$this->assertSame( $heir, (int) get_post( $post )->post_author, 'Ordinary content should still be reassigned.' );
		$this->assertNull( $this->chat_post( $conversation['id'] ), 'The chat must be deleted, not reassigned.' );
		$this->assertSame( 0, ( new Conversation_Store() )->list_for_user( $heir, false, 1, 20 )['total'] );
	}

	/**
	 * An archived chat is deleted with its owner even while the experiment is off.
	 *
	 * Neither the post type nor the archived status is registered then, and a
	 * query by status would skip the archived chat and leave it to be reassigned.
	 *
	 * @since x.x.x
	 */
	public function test_deleting_a_user_deletes_archived_chats_while_the_experiment_is_off(): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';

		$leaving  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$heir     = self::$user_ids['peer'];
		$active   = $this->save_chat( $leaving, 'Still open' );
		$archived = $this->save_chat( $leaving, 'Put away' );

		$this->assertTrue( ( new Conversation_Store() )->set_archived( $archived['id'], $leaving, true ) );

		$this->unregister_chat_types();

		if ( is_multisite() ) {
			remove_user_from_blog( $leaving, get_current_blog_id(), $heir );
		} else {
			wp_delete_user( $leaving, $heir );
		}

		global $wpdb;
		$left = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Counting fixtures in any status.
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", Conversation_Store::POST_TYPE )
		);

		$this->assertSame( 0, $left, 'Both chats, the archived one included, must be deleted rather than reassigned.' );
		$this->assertNotSame( $active['id'], $archived['id'] );
	}

	/**
	 * A chat deleted while a turn is using it is not written back by the turn.
	 *
	 * @since x.x.x
	 */
	public function test_a_chat_deleted_mid_turn_stays_deleted(): void {
		$owner = self::$user_ids['owner'];
		$store = new Conversation_Store();
		$saved = $this->save_chat( $owner );

		// The copy a turn holds, read back before the deletion lands.
		$in_use = $store->get( $saved['id'], $owner );
		$this->assertIsArray( $in_use );

		$store->delete( $saved['id'], $owner );

		$in_use['entries'][] = array(
			'prompt' => 'Another question',
			'text'   => 'Another answer.',
		);

		$this->assertFalse( $store->save( $in_use ), 'Saving a chat that was deleted must fail.' );
		$this->assertNull( $store->get( $saved['id'], $owner ), 'The deleted chat must not come back.' );
	}

	/**
	 * A WXR export of everything contains no chat.
	 *
	 * @since x.x.x
	 */
	public function test_export_contains_no_chats(): void {
		require_once ABSPATH . 'wp-admin/includes/export.php';

		$conversation = $this->save_chat( self::$user_ids['owner'], 'A private question' );

		// The export sends download headers, which the test runner has already
		// passed; only that warning is swallowed.
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Swallowing the headers-already-sent warning only.
			static function ( int $errno, string $errstr ): bool {
				return E_WARNING === $errno && false !== strpos( $errstr, 'headers already sent' );
			}
		);

		ob_start();

		try {
			export_wp( array( 'content' => 'all' ) );
		} finally {
			$xml = (string) ob_get_clean();
			restore_error_handler();
		}

		$this->assertStringContainsString( '<rss', $xml );
		$this->assertStringNotContainsString( Conversation_Store::POST_TYPE, $xml );
		$this->assertStringNotContainsString( $conversation['id'], $xml );
	}

	/**
	 * Quotes, newlines and markup round-trip unchanged for a user without unfiltered_html.
	 *
	 * @since x.x.x
	 */
	public function test_transcript_round_trips_unchanged_without_unfiltered_html(): void {
		$editor = self::$user_ids['editor'];
		wp_set_current_user( $editor );

		if ( is_multisite() ) {
			$this->assertFalse( current_user_can( 'unfiltered_html' ) );
		}

		kses_init();

		$text  = "She said \"don't\" — then <script>alert('x')</script>\nand a \\backslash & <b>bold</b>.";
		$store = new Conversation_Store();

		$conversation             = $store->create( $editor, 'site' );
		$conversation['messages'] = array(
			array(
				'role'  => 'user',
				'parts' => array(
					array(
						'type' => 'text',
						'text' => $text,
					),
				),
			),
		);
		$conversation['entries']  = array(
			array(
				'prompt' => $text,
				'text'   => $text,
			),
		);

		$store->save( $conversation );

		$stored = $store->get( $conversation['id'], $editor );

		$this->assertSame( $conversation['messages'], $stored['messages'] );
		$this->assertSame( $conversation['entries'], $stored['entries'] );
	}

	/**
	 * A name with markup and an ampersand is stored as plain text, unescaped.
	 *
	 * @since x.x.x
	 */
	public function test_rename_keeps_plain_text_for_a_user_without_unfiltered_html(): void {
		$editor = self::$user_ids['editor'];
		wp_set_current_user( $editor );
		kses_init();

		$store        = new Conversation_Store();
		$conversation = $this->save_chat( $editor );

		$this->assertTrue( $store->rename( $conversation['id'], $editor, 'Tom & Jerry\'s "plan"' ) );
		$store->save( $store->get( $conversation['id'], $editor ) );

		$this->assertSame( 'Tom & Jerry\'s "plan"', $store->get( $conversation['id'], $editor )['name'] );
	}

	/**
	 * Delete removes the chat for good.
	 *
	 * @since x.x.x
	 */
	public function test_delete_removes_the_chat_for_good(): void {
		$owner        = self::$user_ids['owner'];
		$store        = new Conversation_Store();
		$conversation = $this->save_chat( $owner );
		$post_id      = $this->chat_post( $conversation['id'] )->ID;

		$store->delete( $conversation['id'], $owner );

		$this->assertNull( get_post( $post_id ) );
		$this->assertNull( $store->get( $conversation['id'], $owner ) );
	}

	/**
	 * A capability check handed the post object, not its ID, is still owner-only.
	 *
	 * @since x.x.x
	 */
	public function test_ownership_holds_when_the_check_is_given_a_post_object(): void {
		$conversation = $this->save_chat( self::$user_ids['owner'] );
		$post         = $this->chat_post( $conversation['id'] );

		// A user holding the type's primitive capability, as a super admin would.
		$peer = get_user_by( 'id', self::$user_ids['peer'] );
		$peer->add_cap( 'edit_wpai_chat' );
		$peer->add_cap( 'read_wpai_chat' );

		$this->assertFalse( user_can( $peer, 'edit_post', $post ) );
		$this->assertFalse( user_can( $peer, 'read_post', $post ) );
		$this->assertTrue( user_can( self::$user_ids['owner'], 'edit_post', $post ) );
	}

	/**
	 * A transcript JSON cannot hold leaves the stored chat as it was.
	 *
	 * @since x.x.x
	 */
	public function test_unencodable_save_keeps_the_stored_transcript(): void {
		$owner        = self::$user_ids['owner'];
		$store        = new Conversation_Store();
		$conversation = $this->save_chat( $owner, 'Keep me' );

		$broken                         = $store->get( $conversation['id'], $owner );
		$broken['messages']             = array( array( 'value' => INF ) );
		$broken['entries'][0]['prompt'] = 'Changed';

		$this->assertFalse( $store->save( $broken ) );
		$this->assertSame( 'Keep me', $store->get( $conversation['id'], $owner )['entries'][0]['prompt'] );
	}

	/**
	 * Removing a user's chats stops when a chat cannot be deleted, rather than looping.
	 *
	 * @since x.x.x
	 */
	public function test_user_cleanup_stops_when_a_chat_cannot_be_deleted(): void {
		$owner = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->save_chat( $owner, 'Undeletable' );

		add_filter( 'pre_delete_post', '__return_false' );

		$started = microtime( true );
		Conversation_Store::delete_user_chats( $owner );

		$this->assertLessThan( 10, microtime( true ) - $started );
		$this->assertSame( 1, ( new Conversation_Store() )->list_for_user( $owner, false, 1, 20 )['total'] );
	}

	/**
	 * A chat is not exposed anywhere WordPress publishes posts.
	 *
	 * @since x.x.x
	 */
	public function test_post_type_is_private(): void {
		$type = get_post_type_object( Conversation_Store::POST_TYPE );

		$this->assertNotNull( $type );
		$this->assertFalse( $type->public );
		$this->assertFalse( $type->publicly_queryable );
		$this->assertFalse( $type->show_ui );
		$this->assertFalse( $type->show_in_rest );
		$this->assertTrue( $type->exclude_from_search );
		$this->assertFalse( $type->can_export );
		$this->assertSame( 'edit_wpai_chats', $type->cap->edit_posts );
	}
}
