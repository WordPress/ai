<?php
/**
 * Integration tests for the AI Workspace proposal confirmation routes.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace
 */

namespace WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace;

use ReflectionProperty;
use WP_REST_Request;
use WP_UnitTestCase;
use WP_User;
use WordPress\AI\Abilities\Show_In_Abilities;
use WordPress\AI\Experiments\AI_Workspace\Conversation_Store;
use WordPress\AI\Experiments\AI_Workspace\Draft_Writer;
use WordPress\AI\Experiments\AI_Workspace\Proposal_Store;
use WordPress\AI\Experiments\AI_Workspace\REST\Proposal_Controller;
use WordPress\AI\Logging\AI_Request_Log_Manager;
use WordPress\AI\Logging\AI_Request_Log_Schema;
use WordPress\AI\Logging\Logging_Integration;
use WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace\Fixtures\Test_Update_Kind;

/**
 * Proposal_Controller test case.
 *
 * This is the only path in the feature that writes to the database, so the
 * assertions are about the guards rather than the happy path: a proposal is
 * bound to the user and the conversation that created it, it expires, it can
 * only be executed against the items the person actually selected, and a repeat
 * execution creates nothing new.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Experiments\AI_Workspace\REST\Proposal_Controller
 * @covers \WordPress\AI\Experiments\AI_Workspace\Draft_Writer
 * @covers \WordPress\AI\Experiments\AI_Workspace\Proposal_Writer
 * @covers \WordPress\AI\Experiments\AI_Workspace\Proposal_Store
 */
class Proposal_ControllerTest extends WP_UnitTestCase {

	/**
	 * The conversation every proposal in these tests belongs to.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const CONVERSATION = 'conversation-under-test';

	/**
	 * Shared user IDs keyed by label.
	 *
	 * @since x.x.x
	 *
	 * @var array<string, int>
	 */
	private static $user_ids = array();

	/**
	 * Manager the logging integration held before the test replaced it.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Logging\AI_Request_Log_Manager|null
	 */
	private $original_shared_manager = null;

	/**
	 * Creates the shared users.
	 *
	 * Two administrators, because the interesting question is not whether an
	 * unprivileged user is refused but whether a second user holding exactly the
	 * same capability can execute somebody else's proposal.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_UnitTest_Factory $factory The unit test factory.
	 */
	public static function wpSetUpBeforeClass( $factory ): void {
		self::$user_ids = array(
			'owner'   => $factory->user->create( array( 'role' => 'administrator' ) ),
			'peer'    => $factory->user->create( array( 'role' => 'administrator' ) ),
			'author'  => $factory->user->create( array( 'role' => 'author' ) ),
			'limited' => $factory->user->create( array( 'role' => 'author' ) ),
		);

		/*
		 * An approver who reaches the workspace but does not hold
		 * `unfiltered_html`, so the same body can be exercised against both sides
		 * of the capability core keys its own kses pass on.
		 */
		$limited = new WP_User( self::$user_ids['limited'] );
		$limited->add_cap( 'manage_options' );
	}

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		( new Show_In_Abilities() )->register();

		$this->original_shared_manager = Logging_Integration::get_log_manager();
		$this->set_shared_manager( null );

		$this->fresh_rest_server();
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_insert_post_empty_content' );
		remove_all_filters( 'wpai_workspace_proposal_kinds' );
		remove_all_filters( 'wpai_workspace_write_proposals_enabled' );

		$this->set_shared_manager( $this->original_shared_manager );

		foreach ( array( 'post', 'page' ) as $post_type ) {
			$object = get_post_type_object( $post_type );
			if ( $object ) {
				// WordPress 7.2 declares the property, and unsetting a declared property leaves it
				// uninitialized, so restore core's default rather than removing it.
				if ( property_exists( \WP_Post_Type::class, 'show_in_abilities' ) ) {
					$object->show_in_abilities = true;
				} else {
					unset( $object->show_in_abilities );
				}
			}
		}

		delete_option( 'wpai_request_logs_schema_version' );
		wp_clear_scheduled_hook( 'wpai_request_logs_cleanup' );

		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Replaces the REST server so routes from an earlier test cannot answer.
	 *
	 * @since x.x.x
	 */
	private function fresh_rest_server(): void {
		global $wp_rest_server;

		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Rebuilding the REST server between dispatches.

		rest_get_server();

		( new Proposal_Controller() )->register_routes();
	}

	/**
	 * Replaces the manager the logging integration shares.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Logging\AI_Request_Log_Manager|null $manager Manager, or null for a disabled experiment.
	 */
	private function set_shared_manager( ?AI_Request_Log_Manager $manager ): void {
		$property = new ReflectionProperty( Logging_Integration::class, 'log_manager' );
		$property->setAccessible( true );
		$property->setValue( null, $manager );
	}

	/**
	 * Boots the request log table and shares a fresh manager with the integration.
	 *
	 * @since x.x.x
	 */
	private function boot_logging(): void {
		delete_option( 'wpai_request_logs_schema_version' );

		$manager = new AI_Request_Log_Manager();
		$manager->init();

		global $wpdb;
		$table = $wpdb->prefix . AI_Request_Log_Schema::TABLE_NAME;
		$wpdb->query( "DELETE FROM {$table} WHERE 1=1" ); // phpcs:ignore

		$this->set_shared_manager( $manager );
	}

	/**
	 * Returns the write log rows written so far.
	 *
	 * @since x.x.x
	 *
	 * @return list<array<string, mixed>> The rows, oldest first.
	 */
	private function write_log_rows(): array {
		global $wpdb;
		$table = $wpdb->prefix . AI_Request_Log_Schema::TABLE_NAME;

		$rows = $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare( "SELECT * FROM {$table} WHERE operation = %s ORDER BY id ASC", Draft_Writer::LOG_OPERATION ), // phpcs:ignore
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Logs in as one of the shared users.
	 *
	 * @since x.x.x
	 *
	 * @param string $label The user label.
	 * @return int The user ID.
	 */
	private function login_as( string $label ): int {
		$user_id = self::$user_ids[ $label ];
		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * Stores a proposal owned by the current user.
	 *
	 * @since x.x.x
	 *
	 * @param int                       $count           How many items the proposal carries.
	 * @param string                    $conversation_id The owning conversation.
	 * @param list<string>|null         $titles          Optional explicit titles.
	 * @param string                    $status          The status every item asks for.
	 * @return array<string, mixed> The stored proposal.
	 */
	private function store_proposal( int $count, string $conversation_id = self::CONVERSATION, ?array $titles = null, string $status = 'draft' ): array {
		$items = array();

		for ( $index = 0; $index < $count; $index++ ) {
			$items[] = array(
				'post_type' => 'post',
				'status'    => $status,
				'title'     => null === $titles ? 'Proposed draft ' . ( $index + 1 ) : $titles[ $index ],
				'content'   => 'Body ' . ( $index + 1 ),
			);
		}

		$proposal = ( new Proposal_Store() )->create( get_current_user_id(), $conversation_id, $items );

		$this->assertIsArray( $proposal, 'The fixture proposal should store cleanly.' );

		return $proposal;
	}

	/**
	 * Returns every item key of a proposal.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $proposal The proposal.
	 * @return list<string> The item keys.
	 */
	private function keys( array $proposal ): array {
		return array_map(
			static function ( array $item ): string {
				return (string) $item['key'];
			},
			$proposal['items']
		);
	}

	/**
	 * Dispatches an execute request for a proposal.
	 *
	 * @since x.x.x
	 *
	 * @param string       $proposal_id     The proposal ID.
	 * @param list<string> $selected        The approved item keys.
	 * @param string       $conversation_id The conversation the request claims.
	 * @param array|null   $edits           Optional. Edited fields keyed by item key.
	 * @return \WP_REST_Response The response.
	 */
	private function execute( string $proposal_id, array $selected, string $conversation_id = self::CONVERSATION, ?array $edits = null ) {
		$this->ensure_conversation();

		$body = array(
			'conversation_id' => $conversation_id,
			'selected'        => $selected,
		);

		if ( null !== $edits ) {
			$body['edits'] = $edits;
		}

		$request = new WP_REST_Request( 'POST', '/ai/v1/workspace/proposals/' . $proposal_id . '/execute' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Stores the current user's chat the proposals belong to, unless it is there.
	 *
	 * An approval is refused once its chat is gone (R7), so every approval in
	 * these tests needs the chat to exist.
	 *
	 * @since x.x.x
	 */
	private function ensure_conversation(): void {
		$user_id       = get_current_user_id();
		$conversations = new Conversation_Store();

		if ( $user_id <= 0 || null !== $conversations->get( self::CONVERSATION, $user_id ) ) {
			return;
		}

		$conversation       = $conversations->create( $user_id, 'site' );
		$conversation['id'] = self::CONVERSATION;
		$conversations->save( $conversation );
	}

	/**
	 * Counts the posts carrying a proposal idempotency token.
	 *
	 * @since x.x.x
	 *
	 * @return int The number of posts written by the proposal flow.
	 */
	private function written_post_count(): int {
		$posts = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_key'       => Draft_Writer::IDEMPOTENCY_META,
				'meta_compare'   => 'EXISTS',
			)
		);

		return count( $posts );
	}

	/**
	 * Counts posts carrying a proposal idempotency token, trash included.
	 *
	 * `written_post_count()` uses the `any` shorthand, which hides trashed rows —
	 * exactly the rows this counter has to see.
	 *
	 * @since x.x.x
	 *
	 * @return int The number of posts written by the proposal flow.
	 */
	private function token_post_count(): int {
		$posts = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => array_keys( get_post_stati() ),
				'posts_per_page' => 100,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_key'       => Draft_Writer::IDEMPOTENCY_META,
				'meta_compare'   => 'EXISTS',
			)
		);

		return count( $posts );
	}

	/**
	 * Approving a five-item proposal creates exactly five drafts.
	 *
	 * @since x.x.x
	 */
	public function test_approving_a_five_item_proposal_creates_five_drafts(): void {
		$this->login_as( 'owner' );

		$proposal = $this->store_proposal( 5 );
		$response = $this->execute( $proposal['id'], $this->keys( $proposal ) );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();

		$this->assertSame( 5, $data['created'] );
		$this->assertSame( 0, $data['failed'] );
		$this->assertSame( 5, $this->written_post_count() );
	}

	/**
	 * A partially failed batch creates only the items that succeeded.
	 *
	 * @since x.x.x
	 */
	public function test_partial_failure_creates_only_the_successful_items(): void {
		$this->login_as( 'owner' );

		add_filter(
			'wp_insert_post_empty_content',
			static function ( $maybe_empty, $postarr ) {
				if ( is_array( $postarr ) && isset( $postarr['post_title'] ) && false !== strpos( (string) $postarr['post_title'], 'FAIL' ) ) {
					return true;
				}

				return $maybe_empty;
			},
			10,
			2
		);

		$proposal = $this->store_proposal(
			5,
			self::CONVERSATION,
			array( 'Good one', 'FAIL two', 'Good three', 'FAIL four', 'Good five' )
		);

		$response = $this->execute( $proposal['id'], $this->keys( $proposal ) );
		$data     = $response->get_data();

		$this->assertSame( 3, $data['created'], 'Only the three writable items may be created.' );
		$this->assertSame( 2, $data['failed'] );
		$this->assertSame( 3, $this->written_post_count() );

		$outcomes = array();

		foreach ( $data['items'] as $item ) {
			$outcomes[ $item['title'] ] = $item['outcome'];
		}

		$this->assertSame( 'created', $outcomes['Good one'] );
		$this->assertSame( 'failed', $outcomes['FAIL two'] );
		$this->assertSame( 'created', $outcomes['Good three'] );
		$this->assertSame( 'failed', $outcomes['FAIL four'] );
		$this->assertSame( 'created', $outcomes['Good five'] );
	}

	/**
	 * A partially failed batch reports per-item outcomes to the model too.
	 *
	 * @since x.x.x
	 */
	public function test_partial_failure_is_reported_to_the_model(): void {
		$user_id = $this->login_as( 'owner' );

		$conversations = new Conversation_Store();
		$conversation  = $conversations->create( $user_id, 'site' );
		$conversation['id'] = self::CONVERSATION;
		$conversations->save( $conversation );

		add_filter(
			'wp_insert_post_empty_content',
			static function ( $maybe_empty, $postarr ) {
				if ( is_array( $postarr ) && isset( $postarr['post_title'] ) && false !== strpos( (string) $postarr['post_title'], 'FAIL' ) ) {
					return true;
				}

				return $maybe_empty;
			},
			10,
			2
		);

		$proposal = $this->store_proposal( 2, self::CONVERSATION, array( 'Good one', 'FAIL two' ) );

		$this->execute( $proposal['id'], $this->keys( $proposal ) );

		$stored = $conversations->get( self::CONVERSATION, $user_id );

		$this->assertIsArray( $stored );

		$encoded = (string) wp_json_encode( $stored['messages'] );

		$this->assertStringContainsString( 'wp_write_result', $encoded, 'The model must be told what the write actually did.' );
		$this->assertStringContainsString( 'FAIL two', $encoded );
	}

	/**
	 * Puts an executed proposal back to pending, as a replay of its write would find it.
	 *
	 * A second approval of a stored, executed proposal returns the stored
	 * results without writing. The idempotency token is the guard beneath that
	 * one, for a write that runs again anyway, such as after the stored record
	 * was lost, so these tests reach it by resetting the record.
	 *
	 * @since x.x.x
	 *
	 * @param string $proposal_id The proposal ID.
	 */
	private function replay_as_pending( string $proposal_id ): void {
		$store  = new Proposal_Store();
		$stored = $store->get( $proposal_id, get_current_user_id() );

		$this->assertIsArray( $stored );

		$stored['status']  = 'pending';
		$stored['results'] = array();
		$store->save( $stored );
	}

	/**
	 * Re-executing the same proposal creates no duplicates.
	 *
	 * @since x.x.x
	 */
	public function test_re_executing_the_same_proposal_creates_no_duplicates(): void {
		$this->login_as( 'owner' );

		$proposal = $this->store_proposal( 3 );
		$keys     = $this->keys( $proposal );

		$first = $this->execute( $proposal['id'], $keys );
		$this->assertSame( 3, $first->get_data()['created'] );

		$this->replay_as_pending( $proposal['id'] );

		$second = $this->execute( $proposal['id'], $keys );
		$data   = $second->get_data();

		$this->assertSame( 0, $data['created'], 'A repeat execution must create nothing.' );
		$this->assertSame( 3, $data['duplicate'] );
		$this->assertSame( 3, $this->written_post_count(), 'Exactly three posts may exist after two executions.' );
	}

	/**
	 * Trashing a created draft does not let a replay write it again.
	 *
	 * @since x.x.x
	 */
	public function test_re_executing_after_trashing_the_created_post_creates_no_duplicates(): void {
		$this->login_as( 'owner' );

		$proposal = $this->store_proposal( 1 );
		$keys     = $this->keys( $proposal );

		$first = $this->execute( $proposal['id'], $keys );

		$this->assertSame( 1, $first->get_data()['created'] );

		$created_id = (int) $first->get_data()['items'][0]['post_id'];

		$this->assertNotSame( 0, $created_id );
		$this->assertNotFalse( wp_trash_post( $created_id ) );
		$this->assertSame( 'trash', get_post_status( $created_id ) );

		$this->replay_as_pending( $proposal['id'] );

		$second = $this->execute( $proposal['id'], $keys );
		$data   = $second->get_data();

		$this->assertSame( 0, $data['created'], 'A trashed post still counts as already written.' );
		$this->assertSame( 1, $data['duplicate'] );
		$this->assertSame(
			1,
			$this->token_post_count(),
			'The trashed post must remain the only post carrying the token.'
		);
	}

	/**
	 * Declining a proposal writes nothing and leaves nothing to execute.
	 *
	 * @since x.x.x
	 */
	public function test_declining_writes_nothing(): void {
		$this->login_as( 'owner' );

		$proposal = $this->store_proposal( 3 );

		$request  = new WP_REST_Request( 'DELETE', '/ai/v1/workspace/proposals/' . $proposal['id'] );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, $this->written_post_count() );

		$after = $this->execute( $proposal['id'], $this->keys( $proposal ) );

		$this->assertSame( 404, $after->get_status(), 'A declined proposal must no longer be executable.' );
		$this->assertSame( 0, $this->written_post_count() );
	}

	/**
	 * Stores the conversation the fixture proposals belong to.
	 *
	 * @since x.x.x
	 *
	 * @param int $user_id The owner.
	 * @return \WordPress\AI\Experiments\AI_Workspace\Conversation_Store The store holding it.
	 */
	private function store_conversation( int $user_id ): Conversation_Store {
		$conversations      = new Conversation_Store();
		$conversation       = $conversations->create( $user_id, 'site' );
		$conversation['id'] = self::CONVERSATION;
		$conversations->save( $conversation );

		return $conversations;
	}

	/**
	 * Executing a proposal owes the conversation one follow-up.
	 *
	 * @since x.x.x
	 */
	public function test_executing_owes_the_conversation_a_follow_up(): void {
		$user_id       = $this->login_as( 'owner' );
		$conversations = $this->store_conversation( $user_id );

		$proposal = $this->store_proposal( 1 );
		$response = $this->execute( $proposal['id'], $this->keys( $proposal ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['follow_up'], 'The client is told it may ask for the follow-up.' );

		$stored = $conversations->get( self::CONVERSATION, $user_id );
		$this->assertIsArray( $stored );
		$this->assertTrue( $stored[ Conversation_Store::FOLLOW_UP_OWED ] );
	}

	/**
	 * With the chat deleted, the approval is refused and nothing is written (R7).
	 *
	 * This replaces the earlier rule that the write still happened without a
	 * follow-up: a proposal whose chat was deleted, or whose owner's chats were
	 * erased, can no longer be approved.
	 *
	 * @since x.x.x
	 */
	public function test_approving_after_the_chat_was_deleted_is_refused(): void {
		$user_id = $this->login_as( 'owner' );

		$proposal = $this->store_proposal( 1 );

		$this->ensure_conversation();
		( new Conversation_Store() )->delete( self::CONVERSATION, $user_id );

		$request = new WP_REST_Request( 'POST', '/ai/v1/workspace/proposals/' . $proposal['id'] . '/execute' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'conversation_id' => self::CONVERSATION,
					'selected'        => $this->keys( $proposal ),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 410, $response->get_status() );
		$this->assertSame( 'workspace_proposal_chat_deleted', $response->get_data()['code'] );
		$this->assertSame( 0, $this->written_post_count() );
		$this->assertNull( ( new Proposal_Store() )->get( $proposal['id'], $user_id ), 'A proposal of a deleted chat is dropped.' );
	}

	/**
	 * Discarding is reported to the model and owes a follow-up, like an approval.
	 *
	 * @since x.x.x
	 */
	public function test_discarding_is_reported_to_the_model(): void {
		$user_id       = $this->login_as( 'owner' );
		$conversations = $this->store_conversation( $user_id );

		$proposal = $this->store_proposal( 2, self::CONVERSATION, array( 'First idea', 'Second idea' ) );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'DELETE', '/ai/v1/workspace/proposals/' . $proposal['id'] ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['follow_up'] );
		$this->assertSame( self::CONVERSATION, $response->get_data()['conversation_id'] );

		$stored = $conversations->get( self::CONVERSATION, $user_id );
		$this->assertIsArray( $stored );
		$this->assertTrue( $stored[ Conversation_Store::FOLLOW_UP_OWED ] );

		$encoded = (string) wp_json_encode( end( $stored['messages'] ) );

		$this->assertStringContainsString( 'wp_proposal_discarded', $encoded, 'The model must be told the proposal was discarded.' );
		$this->assertStringContainsString( 'First idea', $encoded );
		$this->assertSame( 0, $this->written_post_count() );
	}

	/**
	 * An approved proposal cannot then be discarded and reported as unwritten.
	 *
	 * @since x.x.x
	 */
	public function test_an_executed_proposal_cannot_be_discarded(): void {
		$user_id       = $this->login_as( 'owner' );
		$conversations = $this->store_conversation( $user_id );

		$proposal = $this->store_proposal( 1 );
		$this->execute( $proposal['id'], $this->keys( $proposal ) );

		$before = $conversations->get( self::CONVERSATION, $user_id );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'DELETE', '/ai/v1/workspace/proposals/' . $proposal['id'] ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 1, $this->written_post_count() );
		$this->assertSame( $before['messages'], $conversations->get( self::CONVERSATION, $user_id )['messages'], 'No discard is reported for a proposal that wrote.' );
	}

	/**
	 * With the conversation gone, a discard still succeeds and offers no follow-up.
	 *
	 * @since x.x.x
	 */
	public function test_discarding_without_the_conversation_offers_no_follow_up(): void {
		$this->login_as( 'owner' );

		$proposal = $this->store_proposal( 1 );
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'DELETE', '/ai/v1/workspace/proposals/' . $proposal['id'] ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['follow_up'] );
	}

	/**
	 * Only the selected items of a proposal are written.
	 *
	 * @since x.x.x
	 */
	public function test_deselected_items_are_not_written(): void {
		$this->login_as( 'owner' );

		$proposal = $this->store_proposal( 4 );
		$keys     = $this->keys( $proposal );

		$response = $this->execute( $proposal['id'], array( $keys[0], $keys[2] ) );
		$data     = $response->get_data();

		$this->assertSame( 2, $data['created'] );
		$this->assertSame( 2, $data['deselected'] );
		$this->assertSame( 2, $this->written_post_count() );
	}

	/**
	 * A second user with equal capability cannot execute another user's proposal.
	 *
	 * Capability is not identity. Both users here are administrators, so a
	 * capability check alone would let the second one execute values the first
	 * one approved on screen.
	 *
	 * @since x.x.x
	 */
	public function test_a_peer_cannot_execute_another_users_proposal(): void {
		$this->login_as( 'owner' );
		$proposal = $this->store_proposal( 2 );

		$this->login_as( 'peer' );

		$response = $this->execute( $proposal['id'], $this->keys( $proposal ) );

		$this->assertSame( 404, $response->get_status(), 'A proposal belongs to the user who made it.' );
		$this->assertSame( 0, $this->written_post_count() );

		$read = rest_get_server()->dispatch(
			new WP_REST_Request( 'GET', '/ai/v1/workspace/proposals/' . $proposal['id'] )
		);

		$this->assertSame( 404, $read->get_status(), 'A peer must not be able to read the proposal either.' );
	}

	/**
	 * An expired proposal is refused.
	 *
	 * @since x.x.x
	 */
	public function test_expired_proposal_is_refused(): void {
		$user_id = $this->login_as( 'owner' );

		$store    = new Proposal_Store();
		$proposal = $this->store_proposal( 2 );

		$proposal['expires'] = time() - 1;
		$store->save( $proposal );

		$response = $this->execute( $proposal['id'], $this->keys( $proposal ) );

		$this->assertContains(
			$response->get_status(),
			array( 404, 410 ),
			'An expired proposal must not be executable.'
		);
		$this->assertSame( 0, $this->written_post_count() );
		$this->assertNull( $store->get( $proposal['id'], $user_id ) );
	}

	/**
	 * A proposal executed against a different conversation is refused.
	 *
	 * @since x.x.x
	 */
	public function test_conversation_mismatch_is_refused(): void {
		$this->login_as( 'owner' );

		$proposal = $this->store_proposal( 2 );

		$response = $this->execute( $proposal['id'], $this->keys( $proposal ), 'a-different-conversation' );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 0, $this->written_post_count() );
	}

	/**
	 * A capability revoked between proposal and execution is refused at write time.
	 *
	 * @since x.x.x
	 */
	public function test_capability_revoked_after_proposal_is_refused_at_write_time(): void {
		$user_id = $this->login_as( 'owner' );

		$proposal = $this->store_proposal( 2 );

		$user = wp_get_current_user();
		$this->assertSame( $user_id, (int) $user->ID );
		$user->add_cap( 'edit_posts', false );
		$user->add_cap( 'publish_posts', false );

		$response = $this->execute( $proposal['id'], $this->keys( $proposal ) );
		$data     = $response->get_data();

		$user->remove_cap( 'edit_posts' );
		$user->remove_cap( 'publish_posts' );

		$this->assertSame( 0, $data['created'], 'A user who lost the capability must write nothing.' );
		$this->assertSame( 2, $data['denied'] );
		$this->assertSame( 0, $this->written_post_count() );
	}

	/**
	 * A request naming an item the proposal does not hold is refused.
	 *
	 * @since x.x.x
	 */
	public function test_unknown_item_key_is_refused(): void {
		$this->login_as( 'owner' );

		$proposal = $this->store_proposal( 2 );

		$response = $this->execute( $proposal['id'], array( 'not-an-item-key' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 0, $this->written_post_count() );
	}

	/**
	 * The confirmation surface reads back stored resolved values only.
	 *
	 * @since x.x.x
	 */
	public function test_get_returns_stored_resolved_values_and_no_model_summary(): void {
		$user_id = $this->login_as( 'owner' );

		$proposal = ( new Proposal_Store() )->create(
			$user_id,
			self::CONVERSATION,
			array(
				array(
					'post_type' => 'post',
					'status'    => 'draft',
					'title'     => 'The real title',
					'content'   => 'The real body',
					'summary'   => 'A harmless little draft, nothing more.',
				),
			)
		);

		$this->assertIsArray( $proposal );

		$response = rest_get_server()->dispatch(
			new WP_REST_Request( 'GET', '/ai/v1/workspace/proposals/' . $proposal['id'] )
		);

		$this->assertSame( 200, $response->get_status() );

		$data    = $response->get_data();
		$encoded = (string) wp_json_encode( $data );

		$this->assertSame( 'The real title', $data['items'][0]['title'] );
		$this->assertSame( 'The real body', $data['items'][0]['content'] );
		$this->assertStringNotContainsString(
			'A harmless little draft, nothing more.',
			$encoded,
			'The confirmation must render stored values, never the model\'s summary of them.'
		);
	}

	/**
	 * A draft proposal is stored, presented and executed in exactly the shape the
	 * client and the model already read.
	 *
	 * @since x.x.x
	 */
	public function test_draft_proposal_shapes_are_unchanged(): void {
		$user_id  = $this->login_as( 'owner' );
		$proposal = $this->store_proposal( 1 );

		$stored = get_transient( Proposal_Store::TRANSIENT_PREFIX . md5( $user_id . '|' . $proposal['id'] ) );

		$this->assertIsArray( $stored );
		$this->assertSame( array( 'id', 'user_id', 'conversation_id', 'created', 'expires', 'status', 'items', 'results' ), array_keys( $stored ) );
		$this->assertSame( array( 'key', 'post_type', 'status', 'title', 'content', 'excerpt' ), array_keys( $stored['items'][0] ) );

		$presented = rest_get_server()->dispatch(
			new WP_REST_Request( 'GET', '/ai/v1/workspace/proposals/' . $proposal['id'] )
		)->get_data();

		$this->assertSame( array( 'proposal_id', 'conversation_id', 'status', 'expires', 'max_items', 'items' ), array_keys( $presented ) );
		$this->assertSame( array( 'key', 'post_type', 'status', 'title', 'content', 'excerpt' ), array_keys( $presented['items'][0] ) );

		$conversations = $this->store_conversation( $user_id );

		$executed = $this->execute( $proposal['id'], $this->keys( $proposal ) )->get_data();

		$this->assertSame( array( 'proposal_id', 'conversation_id', 'follow_up', 'items', 'created', 'updated', 'unchanged', 'stale', 'locked', 'failed', 'denied', 'duplicate', 'deselected' ), array_keys( $executed ) );
		$this->assertSame( array( 0, 0, 0, 0 ), array( $executed['updated'], $executed['unchanged'], $executed['stale'], $executed['locked'] ), 'A draft proposal reports the update counts as zero.' );
		$this->assertSame(
			array( 'key', 'title', 'post_type', 'status', 'outcome', 'post_id', 'edit_link', 'error_code', 'error_message' ),
			array_keys( $executed['items'][0] )
		);

		$conversation = $conversations->get( self::CONVERSATION, $user_id );
		$this->assertIsArray( $conversation );

		// The envelope is JSON inside a message part, so it arrives encoded twice.
		$reported = (string) wp_json_encode( end( $conversation['messages'] ) );

		$this->assertStringContainsString( '\"wp_write_result\":{\"operation\":\"ai\\\\\/create-drafts\"', $reported );
	}

	/**
	 * A proposal stored before proposals had kinds still loads and executes as drafts.
	 *
	 * @since x.x.x
	 */
	public function test_proposal_stored_without_a_kind_executes_as_drafts(): void {
		$this->login_as( 'owner' );
		$proposal = $this->store_proposal( 1 );

		unset( $proposal['kind'] );
		( new Proposal_Store() )->save( $proposal );

		$response = $this->execute( $proposal['id'], $this->keys( $proposal ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $response->get_data()['created'] );
		$this->assertSame( 'post', get_post( $response->get_data()['items'][0]['post_id'] )->post_type );
	}

	/**
	 * Every write attempt produces one log row in the shared shape.
	 *
	 * @since x.x.x
	 */
	public function test_every_write_attempt_produces_a_log_row(): void {
		$this->login_as( 'owner' );
		$this->boot_logging();

		add_filter(
			'wp_insert_post_empty_content',
			static function ( $maybe_empty, $postarr ) {
				if ( is_array( $postarr ) && isset( $postarr['post_title'] ) && false !== strpos( (string) $postarr['post_title'], 'FAIL' ) ) {
					return true;
				}

				return $maybe_empty;
			},
			10,
			2
		);

		$proposal = $this->store_proposal( 3, self::CONVERSATION, array( 'Good one', 'FAIL two', 'Good three' ) );
		$keys     = $this->keys( $proposal );

		// The third item is deselected, so it is never attempted and never logged.
		$this->execute( $proposal['id'], array( $keys[0], $keys[1] ) );

		$rows = $this->write_log_rows();

		$this->assertCount( 2, $rows, 'One row per write attempt, and none for an item that was never attempted.' );

		$statuses = array_column( $rows, 'status' );
		sort( $statuses );

		$this->assertSame( array( 'error', 'success' ), $statuses );

		$context = json_decode( (string) $rows[0]['context'], true );

		$this->assertSame( 'ai-workspace', $context['surface'] );
		$this->assertSame( self::CONVERSATION, $context['conversation_id'] );
		$this->assertSame( $proposal['id'], $context['proposal']['id'] );
		$this->assertSame( 'ability', $rows[0]['type'] );
	}

	/**
	 * A user without the workspace capability is refused before anything is read.
	 *
	 * @since x.x.x
	 */
	public function test_user_without_the_workspace_capability_is_refused(): void {
		$this->login_as( 'owner' );
		$proposal = $this->store_proposal( 1 );

		$this->login_as( 'author' );

		$response = $this->execute( $proposal['id'], $this->keys( $proposal ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 0, $this->written_post_count() );
	}

	/**
	 * The publish capability alone, revoked after the proposal, is refused at write time.
	 *
	 * This is a different branch from the test above: that one revokes the
	 * create capability, which refuses every item whatever status it asks for.
	 * Only an item asking for a publish status, proposed while the user could
	 * publish and executed once they no longer can, reaches the publish recheck.
	 *
	 * @since x.x.x
	 */
	public function test_publish_capability_revoked_after_proposal_is_refused_at_write_time(): void {
		$user_id = $this->login_as( 'owner' );

		$proposal = $this->store_proposal( 1, self::CONVERSATION, null, 'publish' );

		$user = wp_get_current_user();
		$this->assertSame( $user_id, (int) $user->ID );

		// Only publish is revoked: the item can still be created, just not published.
		$user->add_cap( 'publish_posts', false );

		$this->assertTrue( current_user_can( 'edit_posts' ), 'The create capability must survive, or the wrong branch is under test.' );

		$response = $this->execute( $proposal['id'], $this->keys( $proposal ) );
		$data     = $response->get_data();

		$user->remove_cap( 'publish_posts' );

		$this->assertSame( 0, $data['created'], 'A user who lost publish access must write nothing.' );
		$this->assertSame( 1, $data['denied'] );
		$this->assertSame( 'denied', $data['items'][0]['outcome'] );
		$this->assertSame(
			'cannot_publish_posts',
			$data['items'][0]['error_code'],
			'The refusal must come from the publish recheck, not the create check.'
		);
		$this->assertSame( 0, $this->written_post_count() );
	}

	/**
	 * Model-authored markup is stripped for an approver who holds unfiltered_html.
	 *
	 * The workspace is gated on `manage_options`, so this is the ordinary case
	 * and the one core does not cover: WordPress skips its own kses pass for a
	 * user with `unfiltered_html`, and the body is model output derived from
	 * content a lower-privileged author can write.
	 *
	 * @since x.x.x
	 */
	public function test_model_markup_is_sanitized_for_an_approver_with_unfiltered_html(): void {
		/*
		 * Multisite withholds `unfiltered_html` from administrators and grants it
		 * only to super admins. The grant is scoped to this test rather than the
		 * class: a super admin holds every capability regardless of role, which
		 * would quietly defeat the revoked-capability tests above.
		 */
		$is_multisite = is_multisite();

		if ( $is_multisite ) {
			grant_super_admin( self::$user_ids['owner'] );
		}

		try {
			$this->login_as( 'owner' );

			$this->assertTrue(
				current_user_can( 'unfiltered_html' ),
				'This test is only meaningful while the approver holds the capability core keys its kses pass on.'
			);

			$this->assert_dangerous_markup_is_sanitized();
		} finally {
			if ( $is_multisite ) {
				revoke_super_admin( self::$user_ids['owner'] );
			}
		}
	}

	/**
	 * Model-authored markup is stripped for an approver without unfiltered_html.
	 *
	 * @since x.x.x
	 */
	public function test_model_markup_is_sanitized_for_an_approver_without_unfiltered_html(): void {
		$this->login_as( 'limited' );

		$this->assertFalse( current_user_can( 'unfiltered_html' ) );
		$this->assertTrue( current_user_can( 'manage_options' ), 'The approver still has to reach the workspace.' );

		$this->assert_dangerous_markup_is_sanitized();
	}

	/**
	 * Proposes hostile markup as the current user and asserts what is shown and written.
	 *
	 * Both the confirmation payload and the created post are checked against the
	 * same expectations, so the property that what a person approved is what got
	 * written stays under test rather than being an argument about where the
	 * sanitizer lives.
	 *
	 * @since x.x.x
	 */
	private function assert_dangerous_markup_is_sanitized(): void {
		$content = '<!-- wp:paragraph --><p>Hello <strong>there</strong></p><!-- /wp:paragraph --><script>alert(1)</script><p onclick="steal()">z</p>';
		$excerpt = 'Summary<script>alert(2)</script><em onmouseover="steal()">tail</em>';

		$proposal = ( new Proposal_Store() )->create(
			get_current_user_id(),
			self::CONVERSATION,
			array(
				array(
					'post_type' => 'post',
					'status'    => 'draft',
					'title'     => 'A proposal carrying markup',
					'content'   => $content,
					'excerpt'   => $excerpt,
				),
			)
		);

		$this->assertIsArray( $proposal );

		$shown = rest_get_server()->dispatch(
			new WP_REST_Request( 'GET', '/ai/v1/workspace/proposals/' . $proposal['id'] )
		);

		$this->assertSame( 200, $shown->get_status() );

		$shown_item = $shown->get_data()['items'][0];

		$response = $this->execute( $proposal['id'], $this->keys( $proposal ) );
		$data     = $response->get_data();

		$this->assertSame( 1, $data['created'], 'The item is legitimate; only its markup is not.' );

		$post = get_post( (int) $data['items'][0]['post_id'] );

		$this->assertInstanceOf( 'WP_Post', $post );

		$bodies = array(
			'shown'   => $shown_item['content'],
			'written' => $post->post_content,
		);

		foreach ( $bodies as $label => $body ) {
			$this->assertStringNotContainsString( '<script', $body, "The {$label} body must carry no script element." );
			$this->assertStringNotContainsString( 'onclick', $body, "The {$label} body must carry no event handler." );
			$this->assertStringContainsString( '<!-- wp:paragraph -->', $body, "The {$label} body must keep its block delimiters." );
			$this->assertStringContainsString( '<!-- /wp:paragraph -->', $body, "The {$label} body must keep its block delimiters." );
			$this->assertStringContainsString( '<p>Hello <strong>there</strong></p>', $body, "The {$label} body must keep its legitimate markup." );
		}

		$excerpts = array(
			'shown'   => $shown_item['excerpt'],
			'written' => $post->post_excerpt,
		);

		foreach ( $excerpts as $label => $value ) {
			$this->assertStringNotContainsString( '<script', $value, "The {$label} excerpt must carry no script element." );
			$this->assertStringNotContainsString( 'onmouseover', $value, "The {$label} excerpt must carry no event handler." );
			$this->assertStringContainsString( '<em>tail</em>', $value, "The {$label} excerpt must keep its legitimate markup." );
		}

		$this->assertSame(
			$shown_item['content'],
			$post->post_content,
			'What the confirmation screen showed must be exactly what was written.'
		);
		$this->assertSame( $shown_item['excerpt'], $post->post_excerpt );
	}

	/**
	 * Builds the option name of a proposal's approval lock.
	 *
	 * @since x.x.x
	 *
	 * @param string $proposal_id The proposal ID.
	 * @return string The option name.
	 */
	private function lock_name( string $proposal_id ): string {
		return Proposal_Store::LOCK_PREFIX . md5( get_current_user_id() . '|' . $proposal_id );
	}

	/**
	 * Registers the test update kind and stores a two-item proposal of it.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace\Fixtures\Test_Update_Kind $kind The kind.
	 * @return array<string, mixed> The stored proposal.
	 */
	private function store_update_proposal( Test_Update_Kind $kind ): array {
		$kind->add_to_stores();

		$proposal = ( new Proposal_Store() )->create(
			get_current_user_id(),
			self::CONVERSATION,
			array( array( 'value' => 'First' ), array( 'value' => 'Second' ) ),
			Test_Update_Kind::KIND
		);

		$this->assertIsArray( $proposal );

		return $proposal;
	}

	/**
	 * A second approval of an executed proposal writes nothing and returns the first result (R6).
	 *
	 * @since x.x.x
	 */
	public function test_approving_an_executed_proposal_again_returns_the_stored_results(): void {
		$user_id  = $this->login_as( 'owner' );
		$proposal = $this->store_proposal( 3 );
		$keys     = $this->keys( $proposal );

		$first = $this->execute( $proposal['id'], array( $keys[0], $keys[1] ) )->get_data();

		$this->assertSame( 2, $first['created'] );
		$this->assertTrue( $first['follow_up'] );

		$messages = ( new Conversation_Store() )->get( self::CONVERSATION, $user_id )['messages'];

		$second = $this->execute( $proposal['id'], $keys );

		$this->assertSame( 200, $second->get_status() );

		$data = $second->get_data();

		$this->assertSame( 2, $data['created'] );
		$this->assertSame( 1, $data['deselected'] );
		$this->assertSame( 0, $data['duplicate'], 'Nothing is attempted again, so nothing is a duplicate.' );
		$this->assertSame( $first['items'], $data['items'] );
		$this->assertFalse( $data['follow_up'], 'The outcome was already reported once.' );
		$this->assertSame( 2, $this->written_post_count() );
		$this->assertSame( $messages, ( new Conversation_Store() )->get( self::CONVERSATION, $user_id )['messages'], 'The model is not told twice.' );
	}

	/**
	 * Reading a proposal back once it was approved returns what was written, not a fresh card.
	 *
	 * @since x.x.x
	 */
	public function test_get_returns_the_stored_results_once_approved(): void {
		$this->login_as( 'owner' );
		$proposal = $this->store_proposal( 2 );
		$keys     = $this->keys( $proposal );

		$before = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/ai/v1/workspace/proposals/' . $proposal['id'] ) )->get_data();

		$this->assertArrayNotHasKey( 'results', $before, 'A pending proposal has nothing written to report.' );

		$first = $this->execute( $proposal['id'], array( $keys[0] ) )->get_data();

		$after = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/ai/v1/workspace/proposals/' . $proposal['id'] ) )->get_data();

		$this->assertSame( 'executed', $after['status'] );
		$this->assertSame( $first['items'], $after['results']['items'] );
		$this->assertSame( 1, $after['results']['created'] );
		$this->assertSame( 1, $after['results']['deselected'] );
		$this->assertFalse( $after['results']['follow_up'], 'Reading the outcome owes no follow-up.' );
	}

	/**
	 * While another request holds the lock, an approval writes nothing (R6).
	 *
	 * @since x.x.x
	 */
	public function test_approval_while_another_request_holds_the_lock_writes_nothing(): void {
		$this->login_as( 'owner' );
		$proposal = $this->store_proposal( 2 );

		add_option( $this->lock_name( $proposal['id'] ), 'running:' . time(), '', false );

		$response = $this->execute( $proposal['id'], $this->keys( $proposal ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, $data['created'] );
		$this->assertSame( array(), $data['items'], 'Nothing has been written yet, so there is nothing to report.' );
		$this->assertFalse( $data['follow_up'] );
		$this->assertSame( 0, $this->written_post_count() );
		$this->assertSame( 'pending', ( new Proposal_Store() )->get( $proposal['id'], get_current_user_id() )['status'] );
	}

	/**
	 * The lock is released once the executed proposal is saved.
	 *
	 * @since x.x.x
	 */
	public function test_lock_is_released_after_execution(): void {
		$this->login_as( 'owner' );
		$proposal = $this->store_proposal( 1 );

		$this->execute( $proposal['id'], $this->keys( $proposal ) );

		$this->assertFalse( get_option( $this->lock_name( $proposal['id'] ) ) );
		$this->assertSame( 'executed', ( new Proposal_Store() )->get( $proposal['id'], get_current_user_id() )['status'] );
	}

	/**
	 * A write that dies on its second item keeps the first result, then reads as failed.
	 *
	 * @since x.x.x
	 */
	public function test_an_interrupted_write_keeps_partial_results_and_reads_as_failed(): void {
		$user_id = $this->login_as( 'owner' );

		$kind           = new Test_Update_Kind();
		$kind->throw_on = 2;
		$proposal       = $this->store_update_proposal( $kind );
		$keys           = $this->keys( $proposal );

		try {
			$this->execute( $proposal['id'], $keys );
			$this->fail( 'The fixture kind should have thrown on its second item.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'The request died mid-write.', $e->getMessage() );
		}

		$store  = new Proposal_Store();
		$stored = $store->find( $proposal['id'], $user_id );

		$this->assertSame( 'executing', $stored['status'] );
		$this->assertCount( 1, $stored['results'], 'The first item\'s result was saved as it was written.' );
		$this->assertSame( $keys[0], $stored['results'][0]['key'] );

		$kind->throw_on = 0;

		// Inside the window the interrupted request still holds the lock.
		$retry = $this->execute( $proposal['id'], $keys )->get_data();

		$this->assertSame( 1, $retry['updated'], 'The stored partial result is returned.' );
		$this->assertCount( 1, $kind->written, 'Nothing more is written.' );

		$stored['executing_since'] = time() - Proposal_Store::LOCK_TTL - 1;
		$store->save( $stored );

		$this->assertSame( 'failed', $store->find( $proposal['id'], $user_id )['status'] );

		$presented = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/ai/v1/workspace/proposals/' . $proposal['id'] ) )->get_data();

		$this->assertSame( 'failed', $presented['status'] );
		$this->assertSame( 1, $presented['results']['updated'], 'The card of a failed proposal shows its partial results.' );
		$this->assertCount( 1, $presented['results']['items'] );

		delete_option( $this->lock_name( $proposal['id'] ) );

		$after = $this->execute( $proposal['id'], $keys );

		$this->assertSame( 200, $after->get_status() );
		$this->assertSame( 1, $after->get_data()['updated'] );
		$this->assertSame( array( $keys[0] ), array_keys( $kind->written ), 'A failed proposal cannot be approved again.' );
		$this->assertSame( 'failed', $store->find( $proposal['id'], $user_id )['status'] );
	}

	/**
	 * A proposal that is still being written cannot be discarded.
	 *
	 * @since x.x.x
	 */
	public function test_an_executing_proposal_cannot_be_discarded(): void {
		$this->login_as( 'owner' );
		$proposal = $this->store_proposal( 1 );

		$proposal['status']          = 'executing';
		$proposal['executing_since'] = time();
		( new Proposal_Store() )->save( $proposal );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'DELETE', '/ai/v1/workspace/proposals/' . $proposal['id'] ) );

		$this->assertSame( 409, $response->get_status() );
	}

	/**
	 * Approving after the expiry, inside the grace, says it expired and drops the proposal.
	 *
	 * @since x.x.x
	 */
	public function test_approving_after_expiry_within_the_grace_returns_an_expiry_error(): void {
		$user_id  = $this->login_as( 'owner' );
		$store    = new Proposal_Store();
		$proposal = $this->store_proposal( 1 );

		$proposal['expires'] = time() - 1;
		$store->save( $proposal );

		$response = $this->execute( $proposal['id'], $this->keys( $proposal ) );

		$this->assertSame( 410, $response->get_status() );
		$this->assertSame( 'workspace_proposal_expired', $response->get_data()['code'] );
		$this->assertSame( 0, $this->written_post_count() );
		$this->assertFalse( get_transient( Proposal_Store::TRANSIENT_PREFIX . md5( $user_id . '|' . $proposal['id'] ) ), 'The expired proposal is deleted.' );
	}

	/**
	 * Drafts take no edits, so an edit for a draft item is refused and nothing is written.
	 *
	 * @since x.x.x
	 */
	public function test_edits_for_a_draft_item_are_rejected(): void {
		$this->login_as( 'owner' );
		$proposal = $this->store_proposal( 1 );
		$keys     = $this->keys( $proposal );

		$response = $this->execute( $proposal['id'], $keys, self::CONVERSATION, array( $keys[0] => array( 'title' => 'Changed' ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'workspace_proposal_edits_not_accepted', $response->get_data()['code'] );
		$this->assertSame( 0, $this->written_post_count() );
	}

	/**
	 * An edit for a key the proposal does not carry is refused.
	 *
	 * @since x.x.x
	 */
	public function test_an_edit_for_an_item_not_in_the_proposal_is_rejected(): void {
		$this->login_as( 'owner' );

		$kind     = new Test_Update_Kind();
		$proposal = $this->store_update_proposal( $kind );

		$response = $this->execute( $proposal['id'], $this->keys( $proposal ), self::CONVERSATION, array( 'not-in-the-proposal' => array( 'value' => 'x' ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'workspace_proposal_unknown_item', $response->get_data()['code'] );
		$this->assertSame( array(), $kind->written );
	}

	/**
	 * An edited value is re-validated, stored, written and reported in place of the model's (R4).
	 *
	 * @since x.x.x
	 */
	public function test_an_edited_value_is_what_is_written_and_reported(): void {
		$user_id = $this->login_as( 'owner' );

		$kind     = new Test_Update_Kind();
		$proposal = $this->store_update_proposal( $kind );
		$keys     = $this->keys( $proposal );

		$response = $this->execute( $proposal['id'], $keys, self::CONVERSATION, array( $keys[0] => array( 'value' => '  Edited  ' ) ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 2, $data['updated'] );
		$this->assertSame( 0, $data['created'] );
		$this->assertSame(
			array(
				$keys[0] => 'Edited',
				$keys[1] => 'Second',
			),
			$kind->written
		);

		$stored = ( new Proposal_Store() )->get( $proposal['id'], $user_id );

		$this->assertSame( 'Edited', $stored['items'][0]['value'] );
		$this->assertSame( Test_Update_Kind::SNAPSHOT, $stored['items'][0][ Test_Update_Kind::SNAPSHOT_FIELD ] );

		$reported = (string) wp_json_encode( end( ( new Conversation_Store() )->get( self::CONVERSATION, $user_id )['messages'] ) );

		$this->assertStringContainsString( '\"updated\":2', $reported, 'The model is told about updates, not only creations.' );
	}

	/**
	 * An edit the kind refuses writes nothing and leaves the proposal approvable.
	 *
	 * @since x.x.x
	 */
	public function test_an_invalid_edit_writes_nothing(): void {
		$user_id = $this->login_as( 'owner' );

		$kind     = new Test_Update_Kind();
		$proposal = $this->store_update_proposal( $kind );
		$keys     = $this->keys( $proposal );

		$response = $this->execute( $proposal['id'], $keys, self::CONVERSATION, array( $keys[1] => array( 'value' => '' ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'test_update_empty', $response->get_data()['code'] );
		$this->assertSame( $keys[1], $response->get_data()['data']['item_key'], 'The error names the edited item, so the card can show it beside the field.' );
		$this->assertSame( 400, $response->get_data()['data']['status'] );
		$this->assertSame( array(), $kind->written );

		$stored = ( new Proposal_Store() )->get( $proposal['id'], $user_id );

		$this->assertSame( 'pending', $stored['status'] );
		$this->assertSame( 'Second', $stored['items'][1]['value'] );
		$this->assertFalse( get_option( $this->lock_name( $proposal['id'] ) ), 'A refused edit takes no lock.' );
	}

	/**
	 * With write proposals turned off, a stored update proposal cannot be approved (R8).
	 *
	 * @since x.x.x
	 */
	public function test_a_proposal_of_a_gated_off_kind_cannot_be_approved(): void {
		$this->login_as( 'owner' );

		$kind     = new Test_Update_Kind();
		$proposal = $this->store_update_proposal( $kind );

		add_filter( 'wpai_workspace_write_proposals_enabled', '__return_false' );

		$response = $this->execute( $proposal['id'], $this->keys( $proposal ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'workspace_proposal_invalid_kind', $response->get_data()['code'] );
		$this->assertSame( array(), $kind->written );
	}
}
