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
use WordPress\AI\Abilities\Show_In_Abilities;
use WordPress\AI\Experiments\AI_Workspace\Conversation_Store;
use WordPress\AI\Experiments\AI_Workspace\Draft_Writer;
use WordPress\AI\Experiments\AI_Workspace\Proposal_Store;
use WordPress\AI\Experiments\AI_Workspace\REST\Proposal_Controller;
use WordPress\AI\Logging\AI_Request_Log_Manager;
use WordPress\AI\Logging\AI_Request_Log_Schema;
use WordPress\AI\Logging\Logging_Integration;

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
		);
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

		$this->set_shared_manager( $this->original_shared_manager );

		foreach ( array( 'post', 'page' ) as $post_type ) {
			$object = get_post_type_object( $post_type );
			if ( $object ) {
				unset( $object->show_in_abilities );
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
	 * @return array<string, mixed> The stored proposal.
	 */
	private function store_proposal( int $count, string $conversation_id = self::CONVERSATION, ?array $titles = null ): array {
		$items = array();

		for ( $index = 0; $index < $count; $index++ ) {
			$items[] = array(
				'post_type' => 'post',
				'status'    => 'draft',
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
	 * @return \WP_REST_Response The response.
	 */
	private function execute( string $proposal_id, array $selected, string $conversation_id = self::CONVERSATION ) {
		$request = new WP_REST_Request( 'POST', '/ai/v1/workspace/proposals/' . $proposal_id . '/execute' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'conversation_id' => $conversation_id,
					'selected'        => $selected,
				)
			)
		);

		return rest_get_server()->dispatch( $request );
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

		$second = $this->execute( $proposal['id'], $keys );
		$data   = $second->get_data();

		$this->assertSame( 0, $data['created'], 'A repeat execution must create nothing.' );
		$this->assertSame( 3, $data['duplicate'] );
		$this->assertSame( 3, $this->written_post_count(), 'Exactly three posts may exist after two executions.' );
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
}
