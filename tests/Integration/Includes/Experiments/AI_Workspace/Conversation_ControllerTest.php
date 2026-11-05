<?php
/**
 * Integration tests for the AI Workspace chat history routes.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace
 */

namespace WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace;

use WP_REST_Request;
use WP_UnitTestCase;
use WordPress\AI\Experiments\AI_Workspace\Conversation_Store;
use WordPress\AI\Experiments\AI_Workspace\REST\Conversation_Controller;

/**
 * Conversation_Controller test case.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Experiments\AI_Workspace\REST\Conversation_Controller
 */
class Conversation_ControllerTest extends WP_UnitTestCase {

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
			'owner'      => $factory->user->create( array( 'role' => 'administrator' ) ),
			'peer'       => $factory->user->create( array( 'role' => 'administrator' ) ),
			'subscriber' => $factory->user->create( array( 'role' => 'subscriber' ) ),
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

		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Rebuilding the REST server for each test.
		rest_get_server();

		( new Conversation_Controller() )->register_routes();
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Resetting the REST server after the test.

		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Saves a chat and returns its ID.
	 *
	 * @since x.x.x
	 *
	 * @param int                        $user_id  The owner.
	 * @param string                     $prompt   The first message.
	 * @param list<array<string, mixed>> $messages Optional. Stored model messages.
	 * @return string The conversation ID.
	 */
	private function save_chat( int $user_id, string $prompt = 'Hello', array $messages = array() ): string {
		$store        = new Conversation_Store();
		$conversation = $store->create( $user_id, 'site' );

		$conversation['messages'] = $messages;
		$conversation['entries']  = array(
			array(
				'prompt' => $prompt,
				'text'   => 'Reply to ' . $prompt,
			),
		);

		$store->save( $conversation );

		return $conversation['id'];
	}

	/**
	 * Dispatches a request.
	 *
	 * @since x.x.x
	 *
	 * @param string               $method The HTTP method.
	 * @param string               $path   The route path after the namespace.
	 * @param array<string, mixed> $params The request parameters.
	 * @return \WP_REST_Response The response.
	 */
	private function request( string $method, string $path, array $params = array() ) {
		$request = new WP_REST_Request( $method, '/ai/v1/workspace/conversations' . $path );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * The list holds only the caller's active chats unless archived ones are asked for.
	 *
	 * @since x.x.x
	 */
	public function test_list_excludes_archived_chats_unless_asked(): void {
		$owner = self::$user_ids['owner'];
		wp_set_current_user( $owner );

		$active   = $this->save_chat( $owner, 'Active one' );
		$archived = $this->save_chat( $owner, 'Archived one' );
		$this->save_chat( self::$user_ids['peer'], 'Someone else' );

		( new Conversation_Store() )->set_archived( $archived, $owner, true );

		$default = $this->request( 'GET', '' );

		$this->assertSame( 200, $default->get_status() );
		$this->assertSame( array( $active ), wp_list_pluck( $default->get_data()['conversations'], 'id' ) );
		$this->assertSame( '1', $default->get_headers()['X-WP-Total'] );

		$with_archived = $this->request( 'GET', '', array( 'archived' => 'true' ) );

		$this->assertSame( array( $archived ), wp_list_pluck( $with_archived->get_data()['conversations'], 'id' ) );
		$this->assertTrue( $with_archived->get_data()['conversations'][0]['archived'] );
	}

	/**
	 * The list pages through chats, most recently active first.
	 *
	 * @since x.x.x
	 */
	public function test_list_pages_most_recent_first(): void {
		global $wpdb;

		$owner = self::$user_ids['owner'];
		wp_set_current_user( $owner );

		$ids = array();

		foreach ( array( 3, 1, 2 ) as $days_ago ) {
			$id    = $this->save_chat( $owner, 'Chat ' . $days_ago );
			$ids[] = $id;
			$post  = get_posts( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.get_posts_get_posts -- A test fixture lookup.
				array(
					'post_type' => Conversation_Store::POST_TYPE,
					'name'      => $id,
				)
			)[0];
			$date  = gmdate( 'Y-m-d H:i:s', time() - $days_ago * DAY_IN_SECONDS );

			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Backdating fixtures.
				$wpdb->posts,
				array(
					'post_modified'     => $date,
					'post_modified_gmt' => $date,
				),
				array( 'ID' => $post->ID )
			);
			clean_post_cache( $post->ID );
		}

		$first = $this->request( 'GET', '', array( 'per_page' => 2 ) );

		$this->assertSame( array( $ids[1], $ids[2] ), wp_list_pluck( $first->get_data()['conversations'], 'id' ) );
		$this->assertSame( 2, $first->get_data()['total_pages'] );

		$second = $this->request(
			'GET',
			'',
			array(
				'per_page' => 2,
				'page'     => 2,
			)
		);

		$this->assertSame( array( $ids[0] ), wp_list_pluck( $second->get_data()['conversations'], 'id' ) );
	}

	/**
	 * Opening a chat returns its entries and any reported proposal outcome.
	 *
	 * @since x.x.x
	 */
	public function test_get_returns_the_entries_and_write_results(): void {
		$owner = self::$user_ids['owner'];
		wp_set_current_user( $owner );

		$outcome = array(
			'wp_write_result' => array(
				'proposal_id' => 'p-1',
				'created'     => 1,
			),
		);

		$id = $this->save_chat(
			$owner,
			'Draft one',
			array(
				array(
					'role'  => 'user',
					'parts' => array(
						array(
							'type' => 'text',
							'text' => 'Draft one',
						),
					),
				),
				array(
					'role'  => 'user',
					'parts' => array(
						array(
							'type' => 'text',
							'text' => (string) wp_json_encode( $outcome ),
						),
					),
				),
			)
		);

		$response = $this->request( 'GET', '/' . $id );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $id, $data['id'] );
		$this->assertSame( 'Draft one', $data['name'] );
		$this->assertSame( 'site', $data['scope'] );
		$this->assertSame( 'Draft one', $data['entries'][0]['prompt'] );
		$this->assertSame( array( $outcome['wp_write_result'] ), $data['write_results'] );
		$this->assertArrayNotHasKey( 'messages', $data );
	}

	/**
	 * A written item carries its post's current type, status and edit link.
	 *
	 * @since x.x.x
	 */
	public function test_write_results_link_to_the_posts_they_wrote(): void {
		$owner = self::$user_ids['owner'];
		wp_set_current_user( $owner );

		$draft = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => $owner,
			)
		);
		$gone  = self::factory()->post->create();
		wp_delete_post( $gone, true );

		$outcome = array(
			'wp_write_result' => array(
				'proposal_id' => 'p-2',
				'created'     => 2,
				'items'       => array(
					array(
						'key'     => 'a',
						'title'   => 'Kept',
						'outcome' => 'created',
						'post_id' => $draft,
					),
					array(
						'key'     => 'b',
						'title'   => 'Since deleted',
						'outcome' => 'created',
						'post_id' => $gone,
					),
				),
			),
		);

		$id = $this->save_chat(
			$owner,
			'Draft two',
			array(
				array(
					'role'  => 'user',
					'parts' => array(
						array(
							'type' => 'text',
							'text' => (string) wp_json_encode( $outcome ),
						),
					),
				),
			)
		);

		$items = $this->request( 'GET', '/' . $id )->get_data()['write_results'][0]['items'];

		$this->assertSame( 'post', $items[0]['post_type'] );
		$this->assertSame( 'draft', $items[0]['status'] );
		$this->assertSame( get_edit_post_link( $draft, 'raw' ), $items[0]['edit_link'] );
		$this->assertSame( '', $items[1]['edit_link'], 'A post that is gone has no link.' );
	}

	/**
	 * Nobody but the owner can open, rename, archive or delete a chat, and they are told it is not there.
	 *
	 * @since x.x.x
	 */
	public function test_another_users_chat_is_not_found(): void {
		$id = $this->save_chat( self::$user_ids['owner'] );

		wp_set_current_user( self::$user_ids['peer'] );

		$this->assertSame( 404, $this->request( 'GET', '/' . $id )->get_status() );
		$this->assertSame( 404, $this->request( 'POST', '/' . $id, array( 'name' => 'Mine now' ) )->get_status() );
		$this->assertSame( 404, $this->request( 'POST', '/' . $id, array( 'archived' => true ) )->get_status() );
		$this->assertSame( 404, $this->request( 'DELETE', '/' . $id )->get_status() );

		$stored = ( new Conversation_Store() )->get( $id, self::$user_ids['owner'] );

		$this->assertSame( 'Hello', $stored['name'] );
		$this->assertFalse( $stored['archived'] );
	}

	/**
	 * Rename trims and strips markup, and refuses an empty name.
	 *
	 * @since x.x.x
	 */
	public function test_rename_sanitizes_and_refuses_an_empty_name(): void {
		$owner = self::$user_ids['owner'];
		wp_set_current_user( $owner );

		$id = $this->save_chat( $owner );

		$response = $this->request( 'POST', '/' . $id, array( 'name' => "  <b>Launch</b> plan\n " ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Launch plan', $response->get_data()['name'] );

		$empty = $this->request( 'POST', '/' . $id, array( 'name' => '  <i></i> ' ) );

		$this->assertSame( 400, $empty->get_status() );
		$this->assertSame( 'workspace_conversation_empty_name', $empty->get_data()['code'] );
		$this->assertSame( 'Launch plan', ( new Conversation_Store() )->get( $id, $owner )['name'] );
	}

	/**
	 * An update with nothing to change, or a name over the limit, is refused.
	 *
	 * @since x.x.x
	 */
	public function test_update_refuses_an_empty_request_and_an_overlong_name(): void {
		$owner = self::$user_ids['owner'];
		wp_set_current_user( $owner );

		$id     = $this->save_chat( $owner );
		$before = ( new Conversation_Store() )->get( $id, $owner )['name'];

		$nothing = $this->request( 'POST', '/' . $id );

		$this->assertSame( 400, $nothing->get_status() );
		$this->assertSame( 'workspace_conversation_nothing_to_update', $nothing->get_data()['code'] );

		$long = $this->request( 'POST', '/' . $id, array( 'name' => str_repeat( 'a', Conversation_Store::MAX_NAME_LENGTH + 1 ) ) );

		$this->assertSame( 400, $long->get_status() );
		$this->assertSame( $before, ( new Conversation_Store() )->get( $id, $owner )['name'] );
	}

	/**
	 * Archive and restore round trip through the update route.
	 *
	 * @since x.x.x
	 */
	public function test_archive_and_restore(): void {
		$owner = self::$user_ids['owner'];
		wp_set_current_user( $owner );

		$id = $this->save_chat( $owner );

		$this->assertTrue( $this->request( 'POST', '/' . $id, array( 'archived' => true ) )->get_data()['archived'] );
		$this->assertSame( array(), $this->request( 'GET', '' )->get_data()['conversations'] );

		$this->assertFalse( $this->request( 'POST', '/' . $id, array( 'archived' => false ) )->get_data()['archived'] );
		$this->assertSame( array( $id ), wp_list_pluck( $this->request( 'GET', '' )->get_data()['conversations'], 'id' ) );
	}

	/**
	 * Delete removes the chat for good, and opening it again is not found.
	 *
	 * @since x.x.x
	 */
	public function test_delete_removes_the_chat(): void {
		$owner = self::$user_ids['owner'];
		wp_set_current_user( $owner );

		$id = $this->save_chat( $owner );

		$response = $this->request( 'DELETE', '/' . $id );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['deleted'] );
		$this->assertSame( 404, $this->request( 'GET', '/' . $id )->get_status() );
		$this->assertSame(
			0,
			count(
				get_posts( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.get_posts_get_posts -- A test fixture lookup.
					array(
						'post_type'   => Conversation_Store::POST_TYPE,
						'post_status' => 'any',
						'author'      => $owner,
					)
				)
			)
		);
	}

	/**
	 * A user who owns chats but has lost the workspace capability is refused.
	 *
	 * @since x.x.x
	 */
	public function test_owner_without_the_workspace_capability_is_refused(): void {
		$owner = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$id    = $this->save_chat( $owner );

		$user = get_user_by( 'id', $owner );
		$user->set_role( 'editor' );
		wp_set_current_user( $owner );

		$this->assertSame( 403, $this->request( 'GET', '' )->get_status() );
		$this->assertSame( 403, $this->request( 'GET', '/' . $id )->get_status() );
	}

	/**
	 * Subscribers and logged-out visitors cannot reach the routes.
	 *
	 * @since x.x.x
	 */
	public function test_routes_refuse_insufficient_capability(): void {
		wp_set_current_user( self::$user_ids['subscriber'] );
		$this->assertSame( 403, $this->request( 'GET', '' )->get_status() );

		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->request( 'GET', '' )->get_status() );
	}
}
