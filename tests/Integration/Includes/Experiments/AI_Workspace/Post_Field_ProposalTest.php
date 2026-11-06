<?php
/**
 * Integration tests for AI Workspace post-field proposals.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace
 */

namespace WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace;

use ReflectionProperty;
use WP_REST_Request;
use WP_UnitTestCase;
use WordPress\AI\Abilities\Show_In_Abilities;
use WordPress\AI\Experiments\AI_Workspace\Conversation_Store;
use WordPress\AI\Experiments\AI_Workspace\Post_Field_Writer;
use WordPress\AI\Experiments\AI_Workspace\Proposal_Kind;
use WordPress\AI\Experiments\AI_Workspace\Proposal_Store;
use WordPress\AI\Experiments\AI_Workspace\Propose_Post_Changes;
use WordPress\AI\Experiments\AI_Workspace\REST\Proposal_Controller;
use WordPress\AI\Experiments\AI_Workspace\Turn_Context;
use WordPress\AI\Logging\AI_Request_Log_Manager;
use WordPress\AI\Logging\Logging_Integration;

/**
 * Post-field proposal test case.
 *
 * A change to a published post is live as soon as it is approved, so these
 * tests pin what may be proposed, what the card is shown, and that approval
 * writes exactly the value that was shown, only when nobody changed the field in
 * between, and reports it to the model.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Experiments\AI_Workspace\Post_Field_Writer
 * @covers \WordPress\AI\Experiments\AI_Workspace\Propose_Post_Changes
 * @covers \WordPress\AI\Experiments\AI_Workspace\Proposal_Store
 * @covers \WordPress\AI\Experiments\AI_Workspace\REST\Proposal_Controller
 */
class Post_Field_ProposalTest extends WP_UnitTestCase {

	/**
	 * The conversation every proposal in these tests belongs to.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const CONVERSATION = 'post-field-conversation';

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
	 * @since x.x.x
	 *
	 * @param \WP_UnitTest_Factory $factory The unit test factory.
	 */
	public static function wpSetUpBeforeClass( $factory ): void {
		self::$user_ids = array(
			'owner' => $factory->user->create( array( 'role' => 'administrator' ) ),
			'other' => $factory->user->create( array( 'role' => 'editor' ) ),
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
		get_post_type_object( 'post' )->show_in_abilities = true;
		get_post_type_object( 'page' )->show_in_abilities = true;

		// Writes are logged; with no log table in these tests, nothing is shared to log to.
		$this->original_shared_manager = Logging_Integration::get_log_manager();
		$this->set_shared_manager( null );

		global $wp_rest_server, $wp_current_filter;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Rebuilding the REST server between dispatches.
		rest_get_server();
		( new Proposal_Controller() )->register_routes();

		if ( ! wp_has_ability_category( 'content' ) ) {
			$wp_current_filter[] = 'wp_abilities_api_categories_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
			try {
				( new Propose_Post_Changes() )->register_category();
			} finally {
				array_pop( $wp_current_filter );
			}
		}

		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			( new Propose_Post_Changes() )->register();
		} finally {
			array_pop( $wp_current_filter );
		}

		wp_set_current_user( self::$user_ids['owner'] );
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		Turn_Context::leave();

		$this->set_shared_manager( $this->original_shared_manager );

		if ( wp_has_ability( Propose_Post_Changes::ABILITY ) ) {
			wp_unregister_ability( Propose_Post_Changes::ABILITY );
		}

		wp_set_current_user( 0 );

		parent::tearDown();
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
	 * Creates a post owned by the test owner.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $args Optional. Post arguments. Default empty array.
	 * @return int The post ID.
	 */
	private function make_post( array $args = array() ): int {
		return self::factory()->post->create(
			array_merge(
				array(
					'post_status'  => 'draft',
					'post_title'   => 'Old title',
					'post_excerpt' => 'Old excerpt',
					'post_content' => '<!-- wp:paragraph --><p>Old content.</p><!-- /wp:paragraph -->',
					'post_author'  => self::$user_ids['owner'],
				),
				$args
			)
		);
	}

	/**
	 * Calls the propose ability inside a workspace turn.
	 *
	 * @since x.x.x
	 *
	 * @param list<array<string, mixed>> $items The proposed items.
	 * @return mixed The ability result.
	 */
	private function propose( array $items ) {
		Turn_Context::enter( self::CONVERSATION, get_current_user_id() );

		try {
			return wp_get_ability( Propose_Post_Changes::ABILITY )->execute( array( 'items' => $items ) );
		} finally {
			Turn_Context::leave();
		}
	}

	/**
	 * Proposes changes and returns the stored proposal.
	 *
	 * @since x.x.x
	 *
	 * @param list<array<string, mixed>> $items The proposed items.
	 * @return array<string, mixed> The stored proposal.
	 */
	private function store_changes( array $items ): array {
		$result = $this->propose( $items );

		$this->assertIsArray( $result, 'The fixture changes should be proposed cleanly.' );

		$proposal = ( new Proposal_Store() )->get( $result['proposal_id'], get_current_user_id() );

		$this->assertIsArray( $proposal );

		return $proposal;
	}

	/**
	 * Dispatches a GET for a proposal.
	 *
	 * @since x.x.x
	 *
	 * @param string $proposal_id The proposal ID.
	 * @return array<string, mixed> The response data.
	 */
	private function present( string $proposal_id ): array {
		return rest_get_server()->dispatch(
			new WP_REST_Request( 'GET', '/ai/v1/workspace/proposals/' . $proposal_id )
		)->get_data();
	}

	/**
	 * Dispatches an execute request for every item of a proposal.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed>      $proposal The proposal.
	 * @param array<string, mixed>|null $edits    Optional. Edits keyed by item key. Default none.
	 * @return \WP_REST_Response The response.
	 */
	private function execute( array $proposal, ?array $edits = null ) {
		// An approval is refused once its chat is gone (R7), so the chat is stored first.
		$conversations = new Conversation_Store();

		if ( null === $conversations->get( self::CONVERSATION, get_current_user_id() ) ) {
			$conversation       = $conversations->create( get_current_user_id(), 'site' );
			$conversation['id'] = self::CONVERSATION;
			$conversations->save( $conversation );
		}

		$body = array(
			'conversation_id' => self::CONVERSATION,
			'selected'        => array_column( $proposal['items'], 'key' ),
		);

		if ( null !== $edits ) {
			$body['edits'] = $edits;
		}

		$request = new WP_REST_Request( 'POST', '/ai/v1/workspace/proposals/' . $proposal['id'] . '/execute' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Returns the write result last reported to the model in the test conversation.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The `wp_write_result` envelope.
	 */
	private function reported_result(): array {
		$conversation = ( new Conversation_Store() )->get( self::CONVERSATION, get_current_user_id() );

		$this->assertIsArray( $conversation );

		$last    = end( $conversation['messages'] );
		$decoded = json_decode( $last['parts'][0]['text'], true );

		$this->assertIsArray( $decoded );
		$this->assertArrayHasKey( 'wp_write_result', $decoded );

		return $decoded['wp_write_result'];
	}

	/**
	 * Writes a post column directly, past every save filter.
	 *
	 * @since x.x.x
	 *
	 * @param int    $post_id The post ID.
	 * @param string $column  The column.
	 * @param string $value   The raw value.
	 */
	private function set_raw( int $post_id, string $column, string $value ): void {
		global $wpdb;

		$wpdb->update( $wpdb->posts, array( $column => $value ), array( 'ID' => $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Setting a raw value past the save filters.
		clean_post_cache( $post_id );
	}

	/**
	 * A title change on a draft the person can edit is written and reported in full.
	 *
	 * @since x.x.x
	 */
	public function test_a_title_change_on_a_draft_is_written(): void {
		$post_id  = $this->make_post();
		$proposal = $this->store_changes(
			array(
				array(
					'post_id' => $post_id,
					'field'   => 'title',
					'value'   => '  New title  ',
				),
			)
		);

		$this->assertSame( hash( 'sha256', 'Old title' ), $proposal['items'][0][ Proposal_Kind::SNAPSHOT_FIELD ], 'The item carries a hash of the title it was proposed against.' );

		$response = $this->execute( $proposal );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $data['updated'] );
		$this->assertSame( 'updated', $data['items'][0]['outcome'] );
		$this->assertSame( 'New title', $data['items'][0]['value'] );
		$this->assertSame( $post_id, $data['items'][0]['post_id'] );

		$post = get_post( $post_id );
		$this->assertSame( 'New title', $post->post_title );
		$this->assertSame( 'draft', $post->post_status, 'The status never changes.' );
		$this->assertSame( (string) self::$user_ids['owner'], $post->post_author, 'The author never changes.' );
		$this->assertSame( 'post', $post->post_type, 'The type never changes.' );

		$reported = $this->reported_result();
		$this->assertSame( Post_Field_Writer::LOG_OPERATION, $reported['operation'] );
		$this->assertSame( 1, $reported['updated'] );
		$this->assertSame( 'title', $reported['items'][0]['field'] );
		$this->assertSame( 'New title', $reported['items'][0]['value'], 'The model is told the title it wrote, in full.' );
	}

	/**
	 * A title change on a published post is written, and the card says it is live.
	 *
	 * @since x.x.x
	 */
	public function test_a_title_change_on_a_published_post_is_marked_live(): void {
		$post_id  = $this->make_post( array( 'post_status' => 'publish' ) );
		$proposal = $this->store_changes(
			array(
				array(
					'post_id' => $post_id,
					'field'   => 'title',
					'value'   => 'Live title',
				),
			)
		);

		$presented = $this->present( $proposal['id'] );

		$this->assertSame( Post_Field_Writer::KIND, $presented['kind'] );

		$item = $presented['items'][0];

		$this->assertTrue( $item['live'] );
		$this->assertSame( 'publish', $item['status'] );
		$this->assertSame( 'Old title', $item['current'] );
		$this->assertSame( 'Old title', $item['title'] );
		$this->assertSame( 'Live title', $item['value'] );
		$this->assertSame( 'title', $item['field'] );
		$this->assertSame( 'post', $item['post_type'] );
		$this->assertSame( $post_id, $item['post_id'] );
		$this->assertArrayNotHasKey( Proposal_Kind::SNAPSHOT_FIELD, $item, 'The snapshot stays on the server.' );

		$this->assertSame( 1, $this->execute( $proposal )->get_data()['updated'] );
		$this->assertSame( 'Live title', get_post( $post_id )->post_title );
		$this->assertSame( 'publish', get_post_status( $post_id ) );
	}

	/**
	 * A draft is not live, so its card carries no live-change notice.
	 *
	 * @since x.x.x
	 */
	public function test_a_draft_is_not_marked_live(): void {
		$post_id  = $this->make_post();
		$proposal = $this->store_changes(
			array(
				array(
					'post_id' => $post_id,
					'field'   => 'excerpt',
					'value'   => 'New excerpt',
				),
			)
		);

		$this->assertFalse( $this->present( $proposal['id'] )['items'][0]['live'] );
	}

	/**
	 * An excerpt that already has the new value is reported unchanged and left untouched.
	 *
	 * @since x.x.x
	 */
	public function test_an_excerpt_already_at_the_new_value_is_unchanged(): void {
		$post_id = $this->make_post( array( 'post_excerpt' => 'Same excerpt' ) );
		$this->set_raw( $post_id, 'post_modified', '2020-01-01 00:00:00' );
		$this->set_raw( $post_id, 'post_modified_gmt', '2020-01-01 00:00:00' );

		$proposal = $this->store_changes(
			array(
				array(
					'post_id' => $post_id,
					'field'   => 'excerpt',
					'value'   => 'Same excerpt',
				),
			)
		);

		$data = $this->execute( $proposal )->get_data();

		$this->assertSame( 1, $data['unchanged'] );
		$this->assertSame( 0, $data['updated'] );
		$this->assertSame( '2020-01-01 00:00:00', get_post( $post_id )->post_modified, 'Nothing was written.' );
	}

	/**
	 * A title someone else changed after the proposal is reported stale and survives.
	 *
	 * @since x.x.x
	 */
	public function test_a_title_changed_since_the_proposal_is_stale(): void {
		$post_id  = $this->make_post();
		$proposal = $this->store_changes(
			array(
				array(
					'post_id' => $post_id,
					'field'   => 'title',
					'value'   => 'Agent title',
				),
			)
		);

		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Someone else title',
			)
		);

		$data = $this->execute( $proposal )->get_data();

		$this->assertSame( 1, $data['stale'] );
		$this->assertSame( 'stale', $data['items'][0]['outcome'] );
		$this->assertSame( 'Someone else title', get_post( $post_id )->post_title );
	}

	/**
	 * Two fields of one post both write; the first write does not make the second stale.
	 *
	 * @since x.x.x
	 */
	public function test_two_fields_of_one_post_both_write(): void {
		$post_id  = $this->make_post();
		$proposal = $this->store_changes(
			array(
				array(
					'post_id' => $post_id,
					'field'   => 'title',
					'value'   => 'Both title',
				),
				array(
					'post_id' => $post_id,
					'field'   => 'excerpt',
					'value'   => 'Both excerpt',
				),
			)
		);

		$data = $this->execute( $proposal )->get_data();

		$this->assertSame( 2, $data['updated'] );
		$this->assertSame( 0, $data['stale'] );

		$post = get_post( $post_id );
		$this->assertSame( 'Both title', $post->post_title );
		$this->assertSame( 'Both excerpt', $post->post_excerpt );

		$reported = $this->reported_result();
		$this->assertSame( 'Both excerpt', $reported['items'][1]['value'], 'The model is told the excerpt it wrote, in full.' );
	}

	/**
	 * A post another user is editing is reported locked and left alone.
	 *
	 * @since x.x.x
	 */
	public function test_a_post_locked_by_another_user_is_locked(): void {
		$post_id  = $this->make_post();
		$proposal = $this->store_changes(
			array(
				array(
					'post_id' => $post_id,
					'field'   => 'title',
					'value'   => 'Locked title',
				),
			)
		);

		update_post_meta( $post_id, '_edit_lock', time() . ':' . self::$user_ids['other'] );

		$data = $this->execute( $proposal )->get_data();

		$this->assertSame( 1, $data['locked'] );
		$this->assertSame( 'Old title', get_post( $post_id )->post_title );
	}

	/**
	 * A user who loses `edit_post` between propose and approve is denied.
	 *
	 * @since x.x.x
	 */
	public function test_losing_edit_post_before_approval_is_denied(): void {
		$post_id  = $this->make_post();
		$proposal = $this->store_changes(
			array(
				array(
					'post_id' => $post_id,
					'field'   => 'title',
					'value'   => 'Denied title',
				),
			)
		);

		add_filter(
			'map_meta_cap',
			static function ( $caps, $cap, $user_id, $args ) use ( $post_id ) {
				unset( $user_id );

				return 'edit_post' === $cap && isset( $args[0] ) && (int) $args[0] === $post_id ? array( 'do_not_allow' ) : $caps;
			},
			10,
			4
		);

		$data = $this->execute( $proposal )->get_data();

		$this->assertSame( 1, $data['denied'] );
		$this->assertSame( 'Old title', get_post( $post_id )->post_title );
	}

	/**
	 * A post the person cannot edit is refused at propose time.
	 *
	 * @since x.x.x
	 */
	public function test_a_post_the_person_cannot_edit_is_refused(): void {
		$post_id = $this->make_post();

		add_filter(
			'map_meta_cap',
			static function ( $caps, $cap, $user_id, $args ) use ( $post_id ) {
				unset( $user_id );

				return 'edit_post' === $cap && isset( $args[0] ) && (int) $args[0] === $post_id ? array( 'do_not_allow' ) : $caps;
			},
			10,
			4
		);

		$result = $this->propose(
			array(
				array(
					'post_id' => $post_id,
					'field'   => 'title',
					'value'   => 'Nope',
				),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'workspace_post_change_denied', $result->get_error_code() );
	}

	/**
	 * Script in proposed content is stripped when it is stored, for an administrator too.
	 *
	 * @since x.x.x
	 */
	public function test_script_in_content_is_stripped_for_an_administrator(): void {
		$post_id  = $this->make_post();
		$proposal = $this->store_changes(
			array(
				array(
					'post_id' => $post_id,
					'field'   => 'content',
					'value'   => '<!-- wp:paragraph --><p>Safe.</p><!-- /wp:paragraph --><script>alert(1)</script>',
				),
			)
		);

		$this->assertStringNotContainsString( '<script', $proposal['items'][0]['value'] );

		$data = $this->execute( $proposal )->get_data();

		$this->assertSame( 1, $data['updated'] );

		$content = get_post( $post_id )->post_content;
		$this->assertStringNotContainsString( '<script', $content );
		$this->assertStringContainsString( '<p>Safe.</p>', $content );

		$reported = $this->reported_result();
		$this->assertSame( 'content', $reported['items'][0]['field'] );
		$this->assertArrayNotHasKey( 'value', $reported['items'][0], 'Content is not repeated back to the model.' );
		$this->assertSame( mb_strlen( $content ), $reported['items'][0]['length'] );
	}

	/**
	 * A title with markup is refused for an administrator, script or event handler alike.
	 *
	 * @since x.x.x
	 */
	public function test_a_title_with_markup_is_refused_for_an_administrator(): void {
		$post_id = $this->make_post();

		foreach ( array( 'Hi <script>alert(1)</script>', '<img src="x" onerror="alert(1)">Hi', '<b>Bold</b>' ) as $title ) {
			$result = $this->propose(
				array(
					array(
						'post_id' => $post_id,
						'field'   => 'title',
						'value'   => $title,
					),
				)
			);

			$this->assertWPError( $result, $title );
			$this->assertSame( 'workspace_post_change_title_markup', $result->get_error_code(), $title );
		}

		$this->assertSame( 'Old title', get_post( $post_id )->post_title );
	}

	/**
	 * A title over the length cap is refused.
	 *
	 * @since x.x.x
	 */
	public function test_a_title_over_the_cap_is_refused(): void {
		$result = $this->propose(
			array(
				array(
					'post_id' => $this->make_post(),
					'field'   => 'title',
					'value'   => str_repeat( 'a', Post_Field_Writer::TITLE_MAX_LENGTH + 1 ),
				),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'workspace_post_change_title_too_long', $result->get_error_code() );
	}

	/**
	 * Content holding an iframe is refused; the same post's title and excerpt still change.
	 *
	 * @since x.x.x
	 */
	public function test_content_with_an_iframe_is_refused_but_other_fields_work(): void {
		$post_id = $this->make_post();
		$this->set_raw( $post_id, 'post_content', '<p>Watch:</p><iframe src="https://example.com/embed"></iframe>' );

		$result = $this->propose(
			array(
				array(
					'post_id' => $post_id,
					'field'   => 'content',
					'value'   => '<p>Rewritten.</p>',
				),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'workspace_post_change_unsafe_content', $result->get_error_code() );

		$proposal = $this->store_changes(
			array(
				array(
					'post_id' => $post_id,
					'field'   => 'title',
					'value'   => 'Iframe post',
				),
				array(
					'post_id' => $post_id,
					'field'   => 'excerpt',
					'value'   => 'Iframe excerpt',
				),
			)
		);

		$this->assertSame( 2, $this->execute( $proposal )->get_data()['updated'] );
		$this->assertStringContainsString( '<iframe', get_post( $post_id )->post_content, 'The embed survives.' );
	}

	/**
	 * A trashed post, a revision and a field the post type does not support are refused.
	 *
	 * @since x.x.x
	 */
	public function test_trashed_posts_revisions_and_unsupported_fields_are_refused(): void {
		$trashed = $this->make_post();
		wp_trash_post( $trashed );

		$page = $this->make_post( array( 'post_type' => 'page' ) );

		$revisioned = $this->make_post();
		wp_update_post(
			array(
				'ID'         => $revisioned,
				'post_title' => 'Revised',
			)
		);
		$revisions = wp_get_post_revisions( $revisioned );
		$revision  = (int) array_key_first( $revisions );

		$this->assertGreaterThan( 0, $revision, 'The fixture should have a revision.' );

		$cases = array(
			'workspace_post_change_trashed'           => array( $trashed, 'title' ),
			'workspace_post_change_unsupported_field' => array( $page, 'excerpt' ),
			'workspace_post_change_invalid_post'      => array( $revision, 'title' ),
			'workspace_post_change_not_found'         => array( 999999, 'title' ),
		);

		foreach ( $cases as $code => $case ) {
			$result = $this->propose(
				array(
					array(
						'post_id' => $case[0],
						'field'   => $case[1],
						'value'   => 'Changed',
					),
				)
			);

			$this->assertWPError( $result, $code );
			$this->assertSame( $code, $result->get_error_code() );
		}

		// The tool's schema already limits the field; the writer refuses any other one too, such as from an edit.
		$other = ( new Post_Field_Writer() )->normalize_item(
			array(
				'post_id' => $page,
				'field'   => 'slug',
				'value'   => 'changed',
			)
		);

		$this->assertWPError( $other );
		$this->assertSame( 'workspace_post_change_invalid_field', $other->get_error_code() );
	}

	/**
	 * An edited title over the cap or with markup is refused, and nothing is written.
	 *
	 * @since x.x.x
	 */
	public function test_an_invalid_edit_is_refused_server_side(): void {
		$post_id  = $this->make_post();
		$proposal = $this->store_changes(
			array(
				array(
					'post_id' => $post_id,
					'field'   => 'title',
					'value'   => 'Model title',
				),
			)
		);
		$key      = $proposal['items'][0]['key'];

		$long = $this->execute( $proposal, array( $key => array( 'value' => str_repeat( 'b', Post_Field_Writer::TITLE_MAX_LENGTH + 1 ) ) ) );

		$this->assertSame( 400, $long->get_status() );
		$this->assertSame( 'workspace_post_change_title_too_long', $long->get_data()['code'] );

		$markup = $this->execute( $proposal, array( $key => array( 'value' => 'Hi <script>x</script>' ) ) );

		$this->assertSame( 400, $markup->get_status() );
		$this->assertSame( 'workspace_post_change_title_markup', $markup->get_data()['code'] );

		$this->assertSame( 'Old title', get_post( $post_id )->post_title );
		$this->assertSame( 'pending', ( new Proposal_Store() )->get( $proposal['id'], get_current_user_id() )['status'], 'A refused edit leaves the proposal approvable.' );
	}

	/**
	 * An edit can change only the proposed value, never which post or field the item targets.
	 *
	 * @since x.x.x
	 */
	public function test_an_edit_cannot_retarget_the_item(): void {
		$post_id  = $this->make_post();
		$other_id = $this->make_post();
		$proposal = $this->store_changes(
			array(
				array(
					'post_id' => $post_id,
					'field'   => 'title',
					'value'   => 'Model title',
				),
			)
		);
		$key      = $proposal['items'][0]['key'];

		foreach ( array( array( 'post_id' => $other_id ), array( 'field' => 'excerpt' ) ) as $retarget ) {
			$response = $this->execute( $proposal, array( $key => $retarget + array( 'value' => 'Edited' ) ) );

			$this->assertSame( 400, $response->get_status() );
			$this->assertSame( 'workspace_proposal_invalid_edit', $response->get_data()['code'] );
		}

		$this->assertSame( 'Old title', get_post( $post_id )->post_title );
		$this->assertSame( 'Old title', get_post( $other_id )->post_title );
	}

	/**
	 * A valid edit is what gets written and reported.
	 *
	 * @since x.x.x
	 */
	public function test_a_valid_edit_is_written_and_reported(): void {
		$post_id  = $this->make_post();
		$proposal = $this->store_changes(
			array(
				array(
					'post_id' => $post_id,
					'field'   => 'title',
					'value'   => 'Model title',
				),
			)
		);
		$key      = $proposal['items'][0]['key'];

		$data = $this->execute( $proposal, array( $key => array( 'value' => 'Person title' ) ) )->get_data();

		$this->assertSame( 1, $data['updated'] );
		$this->assertSame( 'Person title', $data['items'][0]['value'] );
		$this->assertSame( 'Person title', get_post( $post_id )->post_title );
		$this->assertSame( 'Person title', $this->reported_result()['items'][0]['value'] );
	}

	/**
	 * A title with a backslash is written exactly as approved.
	 *
	 * @since x.x.x
	 */
	public function test_a_title_with_a_backslash_survives_the_write(): void {
		$post_id  = $this->make_post();
		$proposal = $this->store_changes(
			array(
				array(
					'post_id' => $post_id,
					'field'   => 'title',
					'value'   => 'C:\\Users\\wapuu',
				),
			)
		);

		$this->assertSame( 1, $this->execute( $proposal )->get_data()['updated'] );
		$this->assertSame( 'C:\\Users\\wapuu', get_post( $post_id )->post_title );
	}

	/**
	 * Proposing needs a workspace turn, and the kind is offered and withheld through the store.
	 *
	 * @since x.x.x
	 */
	public function test_proposing_needs_a_turn_and_follows_the_write_gate(): void {
		$result = wp_get_ability( Propose_Post_Changes::ABILITY )->execute(
			array(
				'items' => array(
					array(
						'post_id' => $this->make_post(),
						'field'   => 'title',
						'value'   => 'Outside',
					),
				),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'workspace_context_required', $result->get_error_code() );

		$this->assertContains( Propose_Post_Changes::ABILITY, ( new Proposal_Store() )->proposal_abilities() );

		add_filter( 'wpai_workspace_write_proposals_enabled', '__return_false' );

		$this->assertNotContains( Propose_Post_Changes::ABILITY, ( new Proposal_Store() )->proposal_abilities(), 'Turning write proposals off removes the kind.' );
	}

	/**
	 * Discarding a post-field proposal tells the model which posts it named.
	 *
	 * @since x.x.x
	 */
	public function test_discarding_reports_the_post_titles(): void {
		$post_id  = $this->make_post();
		$proposal = $this->store_changes(
			array(
				array(
					'post_id' => $post_id,
					'field'   => 'title',
					'value'   => 'Discarded title',
				),
			)
		);

		$conversations      = new Conversation_Store();
		$conversation       = $conversations->create( get_current_user_id(), 'site' );
		$conversation['id'] = self::CONVERSATION;
		$conversations->save( $conversation );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'DELETE', '/ai/v1/workspace/proposals/' . $proposal['id'] ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['declined'] );
		$this->assertSame( 'Old title', get_post( $post_id )->post_title );
	}
}
