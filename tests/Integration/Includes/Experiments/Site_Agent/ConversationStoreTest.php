<?php
/**
 * Integration tests for Site Agent conversation storage.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\Site_Agent
 */

namespace WordPress\AI\Tests\Integration\Includes\Experiments\Site_Agent;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Site_Agent\Conversation_Store;

/**
 * Conversation store test case.
 *
 * @since x.x.x
 */
class ConversationStoreTest extends WP_UnitTestCase {

	/**
	 * @var int
	 */
	private int $user_id;

	/**
	 * @var int
	 */
	private int $other_user_id;

	public function setUp(): void {
		parent::setUp();

		$this->user_id       = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->other_user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	public function tearDown(): void {
		delete_user_meta( $this->user_id, Conversation_Store::INDEX_META );
		delete_user_meta( $this->other_user_id, Conversation_Store::INDEX_META );

		parent::tearDown();
	}

	public function test_create_get_and_title(): void {
		$store        = new Conversation_Store();
		$conversation = $store->create( $this->user_id, 'First conversation' );

		$this->assertIsArray( $conversation );
		$this->assertSame( 'First conversation', $conversation['title'] );
		$this->assertSame( array(), $conversation['transcript'] );

		$stored = $store->get( $conversation['id'], $this->user_id );

		$this->assertIsArray( $stored );
		$this->assertSame( $conversation['id'], $stored['id'] );
	}

	public function test_save_lists_and_previews(): void {
		$store        = new Conversation_Store();
		$conversation = $store->create( $this->user_id, 'Preview test' );

		$conversation['transcript'][] = array(
			'id'   => 'entry-1',
			'role' => 'assistant',
			'text' => 'A useful next step for the site.',
		);

		$this->assertTrue( $store->save( $conversation ) );

		$all = $store->all( $this->user_id );

		$this->assertCount( 1, $all );
		$this->assertSame( $conversation['id'], $all[0]['id'] );
		$this->assertSame( 'A useful next step for the site.', $all[0]['preview'] );
	}

	public function test_rename_archive_and_delete(): void {
		$store        = new Conversation_Store();
		$conversation = $store->create( $this->user_id, 'Old name' );

		$renamed = $store->rename( $conversation['id'], $this->user_id, 'New name' );

		$this->assertIsArray( $renamed );
		$this->assertSame( 'New name', $renamed['title'] );

		$archived = $store->archive( $conversation['id'], $this->user_id, true );

		$this->assertIsArray( $archived );
		$this->assertTrue( $archived['archived'] );
		$this->assertTrue( $store->all( $this->user_id )[0]['archived'] );

		$store->delete( $conversation['id'], $this->user_id );

		$this->assertNull( $store->get( $conversation['id'], $this->user_id ) );
		$this->assertSame( array(), $store->all( $this->user_id ) );
	}

	public function test_conversations_are_user_scoped(): void {
		$store        = new Conversation_Store();
		$conversation = $store->create( $this->user_id, 'Private' );

		$this->assertNull( $store->get( $conversation['id'], $this->other_user_id ) );
		$this->assertSame( array(), $store->all( $this->other_user_id ) );
	}

	public function test_title_for_prompt_is_shortened(): void {
		$store = new Conversation_Store();
		$title = $store->title_for_prompt( str_repeat( 'a', 200 ) );

		$this->assertSame( Conversation_Store::MAX_TITLE_LENGTH, mb_strlen( $title ) );
		$this->assertStringEndsWith( '…', $title );
	}
}
