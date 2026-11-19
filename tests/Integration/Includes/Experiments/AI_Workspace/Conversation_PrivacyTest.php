<?php
/**
 * Integration tests for AI Workspace chats in personal-data export and erase.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace
 */

namespace WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace;

use WP_UnitTestCase;
use WordPress\AI\Experiments\AI_Workspace\Conversation_Privacy;
use WordPress\AI\Experiments\AI_Workspace\Conversation_Store;

/**
 * Conversation_Privacy test case.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Experiments\AI_Workspace\Conversation_Privacy
 * @covers \WordPress\AI\Experiments\AI_Workspace\Conversation_Store
 */
class Conversation_PrivacyTest extends WP_UnitTestCase {

	/**
	 * Shared user IDs keyed by label.
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
			'owner' => $factory->user->create(
				array(
					'role'       => 'administrator',
					'user_email' => 'owner@example.org',
				)
			),
			'peer'  => $factory->user->create(
				array(
					'role'       => 'administrator',
					'user_email' => 'peer@example.org',
				)
			),
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
	 * Saves a chat for a user and returns its conversation.
	 *
	 * @since x.x.x
	 *
	 * @param int                              $user_id The owner.
	 * @param list<array<string, mixed>>|null  $entries Optional. The turns. Default one turn.
	 * @param bool                             $archive Optional. Whether to archive it. Default false.
	 * @return array<string, mixed> The conversation.
	 */
	private function save_chat( int $user_id, ?array $entries = null, bool $archive = false ): array {
		$store        = new Conversation_Store();
		$conversation = $store->create( $user_id, 'site' );

		$conversation['entries'] = $entries ?? array(
			array(
				'prompt'     => 'Hello there',
				'text'       => 'Hi.',
				'tool_calls' => array(),
			),
		);

		$this->assertTrue( $store->save( $conversation ) );

		if ( $archive ) {
			$this->assertTrue( $store->set_archived( $conversation['id'], $user_id, true ) );
		}

		return $conversation;
	}

	/**
	 * Returns the registered callback of the AI chats exporter or eraser.
	 *
	 * @since x.x.x
	 *
	 * @param string $filter `wp_privacy_personal_data_exporters` or `wp_privacy_personal_data_erasers`.
	 * @return callable The callback.
	 */
	private function privacy_callback( string $filter ): callable {
		$registered = apply_filters( $filter, array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Core's privacy filters.

		$this->assertArrayHasKey( Conversation_Privacy::ID, $registered );

		return $registered[ Conversation_Privacy::ID ]['callback'];
	}

	/**
	 * Unregisters the chat post type and archived status, as when the experiment is off.
	 *
	 * @since x.x.x
	 */
	private function unregister_chat_types(): void {
		unregister_post_type( Conversation_Store::POST_TYPE );
		unset( $GLOBALS['wp_post_statuses'][ Conversation_Store::STATUS_ARCHIVED ] );
	}

	/**
	 * Counts a user's chats in any status, straight from the posts table.
	 *
	 * @since x.x.x
	 *
	 * @param int $user_id The owner.
	 * @return int The count.
	 */
	private function chat_count( int $user_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Counting fixtures in any status.
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_author = %d", Conversation_Store::POST_TYPE, $user_id )
		);
	}

	/**
	 * Reads one export item's fields as a name to value map.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $item The export item.
	 * @return array<string, string> The fields.
	 */
	private function fields( array $item ): array {
		return array_column( $item['data'], 'value', 'name' );
	}

	/**
	 * Exporting returns every chat, archived ones included, with each turn's prompt and reply.
	 *
	 * @since x.x.x
	 */
	public function test_export_returns_every_chat_with_its_prompts_and_replies(): void {
		$owner    = self::$user_ids['owner'];
		$active   = $this->save_chat(
			$owner,
			array(
				array(
					'prompt'     => 'Find posts about remote work',
					'text'       => 'I found three.',
					'tool_calls' => array(
						array(
							'ability' => 'ai/search-content',
							'result'  => array( 'secret' => 'a tool result' ),
						),
					),
				),
				array(
					'prompt'    => '',
					'text'      => 'The draft was created.',
					'follow_up' => true,
				),
			)
		);
		$archived = $this->save_chat( $owner, null, true );
		$this->save_chat( self::$user_ids['peer'] );

		$export = $this->privacy_callback( 'wp_privacy_personal_data_exporters' )( 'owner@example.org', 1 );

		$this->assertTrue( $export['done'] );
		$this->assertCount( 2, $export['data'] );

		$by_id = array();
		foreach ( $export['data'] as $item ) {
			$this->assertSame( Conversation_Privacy::ID, $item['group_id'] );
			$this->assertSame( 'AI Workspace spaces', $item['group_label'] );
			$by_id[ $item['item_id'] ] = $this->fields( $item );
		}

		$first = $by_id[ 'wpai-chat-' . $active['id'] ];

		$this->assertSame( 'Find posts about remote work', $first['Name'] );
		$this->assertSame( 'No', $first['Archived'] );
		$this->assertSame( 'Find posts about remote work', $first['Turn 1: your message'] );
		$this->assertSame( 'I found three.', $first['Turn 1: reply'] );
		$this->assertArrayNotHasKey( 'Turn 2: your message', $first, 'A follow-up has no message of the person\'s.' );
		$this->assertSame( 'The draft was created.', $first['Turn 2: reply'] );
		$this->assertArrayHasKey( 'Created', $first );
		$this->assertArrayHasKey( 'Updated', $first );
		$this->assertStringNotContainsString( 'a tool result', (string) wp_json_encode( $export ), 'Tool results are left out.' );

		$this->assertSame( 'Yes', $by_id[ 'wpai-chat-' . $archived['id'] ]['Archived'] );
	}

	/**
	 * With the experiment off, archived chats are exported too.
	 *
	 * @since x.x.x
	 */
	public function test_export_with_the_experiment_off_includes_archived_chats(): void {
		$owner = self::$user_ids['owner'];
		$this->save_chat( $owner );
		$this->save_chat( $owner, null, true );

		$this->unregister_chat_types();

		$export = $this->privacy_callback( 'wp_privacy_personal_data_exporters' )( 'owner@example.org', 1 );

		$this->assertCount( 2, $export['data'] );
	}

	/**
	 * The export pages through a person's chats twenty at a time.
	 *
	 * @since x.x.x
	 */
	public function test_export_pages_at_twenty_chats(): void {
		$owner = self::$user_ids['owner'];

		for ( $index = 0; $index < Conversation_Privacy::PER_PAGE + 1; $index++ ) {
			$this->save_chat( $owner );
		}

		$exporter = $this->privacy_callback( 'wp_privacy_personal_data_exporters' );
		$first    = $exporter( 'owner@example.org', 1 );
		$second   = $exporter( 'owner@example.org', 2 );

		$this->assertCount( Conversation_Privacy::PER_PAGE, $first['data'] );
		$this->assertFalse( $first['done'] );
		$this->assertCount( 1, $second['data'] );
		$this->assertTrue( $second['done'] );
		$this->assertSame( array(), array_intersect( array_column( $first['data'], 'item_id' ), array_column( $second['data'], 'item_id' ) ) );
	}

	/**
	 * Erasing removes every chat the person owns, archived ones included, and no one else's.
	 *
	 * @since x.x.x
	 */
	public function test_erase_removes_the_persons_chats_only(): void {
		$owner = self::$user_ids['owner'];
		$peer  = self::$user_ids['peer'];
		$this->save_chat( $owner );
		$this->save_chat( $owner, null, true );
		$this->save_chat( $peer );

		$this->unregister_chat_types();

		$result = $this->privacy_callback( 'wp_privacy_personal_data_erasers' )( 'owner@example.org', 1 );

		$this->assertTrue( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertTrue( $result['done'] );
		$this->assertSame( 0, $this->chat_count( $owner ) );
		$this->assertSame( 1, $this->chat_count( $peer ), 'Another user\'s chats are untouched.' );

		$again = $this->privacy_callback( 'wp_privacy_personal_data_erasers' )( 'owner@example.org', 1 );

		$this->assertFalse( $again['items_removed'], 'Nothing is left to remove.' );
	}

	/**
	 * An email with no matching user exports nothing and erases nothing.
	 *
	 * @since x.x.x
	 */
	public function test_an_unknown_email_exports_and_erases_nothing(): void {
		$this->save_chat( self::$user_ids['owner'] );

		$export = $this->privacy_callback( 'wp_privacy_personal_data_exporters' )( 'nobody@example.org', 1 );
		$erase  = $this->privacy_callback( 'wp_privacy_personal_data_erasers' )( 'nobody@example.org', 1 );

		$this->assertSame(
			array(
				'data' => array(),
				'done' => true,
			),
			$export
		);
		$this->assertFalse( $erase['items_removed'] );
		$this->assertFalse( $erase['items_retained'] );
		$this->assertTrue( $erase['done'] );
		$this->assertSame( 1, $this->chat_count( self::$user_ids['owner'] ) );
	}
}
