<?php
/**
 * Integration tests for AI Workspace site title and tagline proposals.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace
 */

namespace WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace;

use ReflectionProperty;
use WP_REST_Request;
use WP_UnitTestCase;
use WordPress\AI\Experiments\AI_Workspace\Conversation_Store;
use WordPress\AI\Experiments\AI_Workspace\Proposal_Kind;
use WordPress\AI\Experiments\AI_Workspace\Proposal_Store;
use WordPress\AI\Experiments\AI_Workspace\Propose_Site_Identity;
use WordPress\AI\Experiments\AI_Workspace\REST\Proposal_Controller;
use WordPress\AI\Experiments\AI_Workspace\Site_Identity_Writer;
use WordPress\AI\Experiments\AI_Workspace\Turn_Context;
use WordPress\AI\Logging\AI_Request_Log_Manager;
use WordPress\AI\Logging\Logging_Integration;

/**
 * Site identity proposal test case.
 *
 * The site title and tagline change for every visitor the moment they are
 * written, so these tests pin which options may be proposed, by whom, what the
 * card is shown, and that approval writes exactly the value shown, only when
 * nobody changed the option in between.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Experiments\AI_Workspace\Site_Identity_Writer
 * @covers \WordPress\AI\Experiments\AI_Workspace\Propose_Site_Identity
 * @covers \WordPress\AI\Experiments\AI_Workspace\Proposal_Store
 * @covers \WordPress\AI\Experiments\AI_Workspace\REST\Proposal_Controller
 */
class Site_Identity_ProposalTest extends WP_UnitTestCase {

	/**
	 * The conversation every proposal in these tests belongs to.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const CONVERSATION = 'site-identity-conversation';

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
			'owner'  => $factory->user->create( array( 'role' => 'administrator' ) ),
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

		// Writes are logged; with no log table in these tests, nothing is shared to log to.
		$this->original_shared_manager = Logging_Integration::get_log_manager();
		$this->set_shared_manager( null );

		global $wp_rest_server, $wp_current_filter;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Rebuilding the REST server between dispatches.
		rest_get_server();
		( new Proposal_Controller() )->register_routes();

		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			( new Propose_Site_Identity() )->register();
		} finally {
			array_pop( $wp_current_filter );
		}

		update_option( 'blogname', 'Old Site' );
		update_option( 'blogdescription', 'Just another site' );

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

		if ( wp_has_ability( Propose_Site_Identity::ABILITY ) ) {
			wp_unregister_ability( Propose_Site_Identity::ABILITY );
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
			return wp_get_ability( Propose_Site_Identity::ABILITY )->execute( array( 'items' => $items ) );
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
	 * Proposes a new tagline and returns the stored proposal.
	 *
	 * @since x.x.x
	 *
	 * @param string $value The proposed tagline.
	 * @return array<string, mixed> The stored proposal.
	 */
	private function store_tagline( string $value ): array {
		return $this->store_changes(
			array(
				array(
					'option' => 'blogdescription',
					'value'  => $value,
				),
			)
		);
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
	 * Stores the test conversation, which an approval needs (R7).
	 *
	 * @since x.x.x
	 */
	private function ensure_conversation(): void {
		$conversations = new Conversation_Store();

		if ( null !== $conversations->get( self::CONVERSATION, get_current_user_id() ) ) {
			return;
		}

		$conversation       = $conversations->create( get_current_user_id(), 'site' );
		$conversation['id'] = self::CONVERSATION;
		$conversations->save( $conversation );
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
		$this->ensure_conversation();

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
	 * Changing the tagline as an administrator writes it, reports `updated`, and tells the model.
	 *
	 * @since x.x.x
	 */
	public function test_a_tagline_change_by_an_administrator_is_written(): void {
		$proposal = $this->store_tagline( '  Notes on gardening and slow cooking  ' );

		$this->assertSame( 'Just another site', $proposal['items'][0][ Proposal_Kind::SNAPSHOT_FIELD ], 'The item carries the tagline it was proposed against.' );

		$response = $this->execute( $proposal );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $data['updated'] );
		$this->assertSame( 'updated', $data['items'][0]['outcome'] );
		$this->assertSame( 'Notes on gardening and slow cooking', $data['items'][0]['value'] );
		$this->assertSame( 'blogdescription', $data['items'][0]['option'] );
		$this->assertSame( 'Notes on gardening and slow cooking', get_option( 'blogdescription' ) );
		$this->assertSame( 'Old Site', get_option( 'blogname' ), 'Only the proposed option changes.' );

		$reported = $this->reported_result();
		$this->assertSame( Site_Identity_Writer::LOG_OPERATION, $reported['operation'] );
		$this->assertSame( 1, $reported['updated'] );
		$this->assertSame( 'blogdescription', $reported['items'][0]['field'] );
		$this->assertSame( 'Notes on gardening and slow cooking', $reported['items'][0]['value'], 'The model is told the tagline it wrote.' );
	}

	/**
	 * The card shows the live value next to the new one and says the change is live.
	 *
	 * @since x.x.x
	 */
	public function test_the_card_shows_current_next_to_new_and_is_live(): void {
		$proposal  = $this->store_changes(
			array(
				array(
					'option' => 'blogname',
					'value'  => 'New Site',
				),
				array(
					'option' => 'blogdescription',
					'value'  => 'A new tagline',
				),
			)
		);
		$presented = $this->present( $proposal['id'] );

		$this->assertSame( Site_Identity_Writer::KIND, $presented['kind'] );
		$this->assertCount( 2, $presented['items'] );

		$title = $presented['items'][0];

		$this->assertSame( 'blogname', $title['option'] );
		$this->assertSame( 'Old Site', $title['current'] );
		$this->assertSame( 'New Site', $title['value'] );
		$this->assertTrue( $title['live'] );
		$this->assertSame( 'option', $title['post_type'] );
		$this->assertSame( 'Site Title', $title['title'] );
		$this->assertArrayNotHasKey( Proposal_Kind::SNAPSHOT_FIELD, $title, 'The snapshot stays on the server.' );

		$this->assertSame( 'Tagline', $presented['items'][1]['title'] );
		$this->assertSame( 'Just another site', $presented['items'][1]['current'] );

		$this->assertSame( 2, $this->execute( $proposal )->get_data()['updated'] );
		$this->assertSame( 'New Site', get_option( 'blogname' ) );
		$this->assertSame( 'A new tagline', get_option( 'blogdescription' ) );
	}

	/**
	 * Any option other than the site title or tagline is refused, at propose time and by the writer.
	 *
	 * @since x.x.x
	 */
	public function test_any_other_option_is_refused(): void {
		foreach ( array( 'siteurl', 'admin_email', 'users_can_register', 'default_role' ) as $option ) {
			$result = $this->propose(
				array(
					array(
						'option' => $option,
						'value'  => 'administrator',
					),
				)
			);

			$this->assertWPError( $result, $option );

			// The schema already limits the option; the writer refuses any other one too.
			$normalized = ( new Site_Identity_Writer() )->normalize_item(
				array(
					'option' => $option,
					'value'  => 'administrator',
				)
			);

			$this->assertWPError( $normalized, $option );
			$this->assertSame( 'workspace_site_identity_invalid_option', $normalized->get_error_code(), $option );
		}

		$this->assertSame( 'subscriber', get_option( 'default_role' ) );
	}

	/**
	 * An editor, who lacks `manage_options`, cannot propose a site title or tagline.
	 *
	 * @since x.x.x
	 */
	public function test_an_editor_cannot_propose(): void {
		wp_set_current_user( self::$user_ids['editor'] );

		$this->assertWPError(
			$this->propose(
				array(
					array(
						'option' => 'blogdescription',
						'value'  => 'Editor tagline',
					),
				)
			)
		);

		$normalized = ( new Site_Identity_Writer() )->normalize_item(
			array(
				'option' => 'blogdescription',
				'value'  => 'Editor tagline',
			)
		);

		$this->assertWPError( $normalized );
		$this->assertSame( 'workspace_site_identity_denied', $normalized->get_error_code() );
		$this->assertSame( 'Just another site', get_option( 'blogdescription' ) );
	}

	/**
	 * An administrator demoted before approving is refused by the route and denied by the writer.
	 *
	 * @since x.x.x
	 */
	public function test_an_administrator_demoted_before_approving_is_denied(): void {
		$proposal = $this->store_tagline( 'Demoted tagline' );

		$this->ensure_conversation();

		$demote = static function ( $allcaps ) {
			$allcaps['manage_options'] = false;

			return $allcaps;
		};
		add_filter( 'user_has_cap', $demote );

		// The approval route needs `manage_options` itself.
		$this->assertSame( 403, $this->execute( $proposal )->get_status() );

		// The writer re-checks too, for any path that reaches it without the route's check.
		$result = ( new Site_Identity_Writer() )->write( $proposal, array_column( $proposal['items'], 'key' ) );

		remove_filter( 'user_has_cap', $demote );

		$this->assertSame( 1, $result['denied'] );
		$this->assertSame( 'denied', $result['items'][0]['outcome'] );
		$this->assertSame( 'Just another site', get_option( 'blogdescription' ) );
	}

	/**
	 * A tagline already equal to the new value is reported unchanged, not as an error.
	 *
	 * @since x.x.x
	 */
	public function test_a_tagline_already_at_the_new_value_is_unchanged(): void {
		$proposal = $this->store_tagline( 'Just another site' );

		$writes = 0;
		$count  = static function () use ( &$writes ) {
			++$writes;
		};
		add_action( 'update_option_blogdescription', $count );

		$data = $this->execute( $proposal )->get_data();

		$this->assertSame( 1, $data['unchanged'] );
		$this->assertSame( 0, $data['updated'] );
		$this->assertSame( 0, $data['failed'] );
		$this->assertSame( 'unchanged', $data['items'][0]['outcome'] );
		$this->assertSame( 0, $writes, 'Nothing was written.' );
	}

	/**
	 * A title with an ampersand, which core stores escaped, round-trips as plain text and compares equal.
	 *
	 * @since x.x.x
	 */
	public function test_a_title_with_an_ampersand_is_compared_as_stored(): void {
		update_option( 'blogname', 'Tom & Jerry' );

		$this->assertSame( 'Tom &amp; Jerry', get_option( 'blogname' ), 'Core stores the title escaped.' );

		$proposal = $this->store_changes(
			array(
				array(
					'option' => 'blogname',
					'value'  => 'Tom & Jerry',
				),
			)
		);

		$this->assertSame( 'Tom & Jerry', $this->present( $proposal['id'] )['items'][0]['current'], 'The card shows the title as Settings does.' );
		$this->assertSame( 1, $this->execute( $proposal )->get_data()['unchanged'] );

		$proposal = $this->store_changes(
			array(
				array(
					'option' => 'blogname',
					'value'  => 'Tom & Jerry\'s',
				),
			)
		);

		$this->assertSame( 1, $this->execute( $proposal )->get_data()['updated'] );
		$this->assertSame( 'Tom & Jerry\'s', wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES ) );
	}

	/**
	 * A tagline changed in Settings after the proposal is reported stale, and the Settings value survives.
	 *
	 * @since x.x.x
	 */
	public function test_a_tagline_changed_in_settings_since_the_proposal_is_stale(): void {
		$proposal = $this->store_tagline( 'Agent tagline' );

		update_option( 'blogdescription', 'Settings tagline' );

		$data = $this->execute( $proposal )->get_data();

		$this->assertSame( 1, $data['stale'] );
		$this->assertSame( 'stale', $data['items'][0]['outcome'] );
		$this->assertSame( 'Settings tagline', get_option( 'blogdescription' ) );
	}

	/**
	 * A value holding HTML is stored as plain text, its tags stripped.
	 *
	 * @since x.x.x
	 */
	public function test_a_value_with_html_is_stored_as_plain_text(): void {
		$proposal = $this->store_tagline( 'Notes on <b>slow</b> cooking<script>alert(1)</script>' );

		$this->assertSame( 'Notes on slow cooking', $proposal['items'][0]['value'], 'The card is shown the plain text that will be written.' );

		$this->assertSame( 1, $this->execute( $proposal )->get_data()['updated'] );
		$this->assertSame( 'Notes on slow cooking', get_option( 'blogdescription' ) );
	}

	/**
	 * A value over its option's cap is refused rather than truncated; one at the cap is accepted.
	 *
	 * @since x.x.x
	 */
	public function test_a_value_over_the_cap_is_refused(): void {
		$caps = array(
			'blogname'        => Site_Identity_Writer::TITLE_MAX_LENGTH,
			'blogdescription' => Site_Identity_Writer::TAGLINE_MAX_LENGTH,
		);

		$this->assertSame( 200, $caps['blogname'] );
		$this->assertSame( 300, $caps['blogdescription'] );

		foreach ( $caps as $option => $cap ) {
			$result = $this->propose(
				array(
					array(
						'option' => $option,
						'value'  => str_repeat( 'a', $cap + 1 ),
					),
				)
			);

			$this->assertWPError( $result, $option );
			$this->assertSame( 'workspace_site_identity_too_long', $result->get_error_code(), $option );

			$this->assertIsArray(
				$this->propose(
					array(
						array(
							'option' => $option,
							'value'  => str_repeat( 'a', $cap ),
						),
					)
				),
				$option
			);
		}

		$this->assertSame( 'Old Site', get_option( 'blogname' ) );
	}

	/**
	 * The site title cannot be emptied; the tagline can be.
	 *
	 * @since x.x.x
	 */
	public function test_an_empty_title_is_refused_but_an_empty_tagline_is_not(): void {
		$result = $this->propose(
			array(
				array(
					'option' => 'blogname',
					'value'  => '  <b></b> ',
				),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'workspace_site_identity_empty', $result->get_error_code() );

		$proposal = $this->store_tagline( '' );

		$this->assertSame( 1, $this->execute( $proposal )->get_data()['updated'] );
		$this->assertSame( '', get_option( 'blogdescription' ) );
	}

	/**
	 * A proposal names each option at most once, and holds at most two items.
	 *
	 * @since x.x.x
	 */
	public function test_each_option_is_proposed_at_most_once(): void {
		$result = $this->propose(
			array(
				array(
					'option' => 'blogdescription',
					'value'  => 'First',
				),
				array(
					'option' => 'blogdescription',
					'value'  => 'Second',
				),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'workspace_site_identity_duplicate_option', $result->get_error_code() );

		$this->assertSame( 2, ( new Site_Identity_Writer() )->max_items() );
		$this->assertSame( 'Just another site', get_option( 'blogdescription' ) );
	}

	/**
	 * An edited value goes through the same rules, and a valid one is what gets written and reported.
	 *
	 * @since x.x.x
	 */
	public function test_an_edit_is_revalidated_and_written(): void {
		$proposal = $this->store_tagline( 'Model tagline' );
		$key      = $proposal['items'][0]['key'];

		$long = $this->execute( $proposal, array( $key => array( 'value' => str_repeat( 'b', Site_Identity_Writer::TAGLINE_MAX_LENGTH + 1 ) ) ) );

		$this->assertSame( 400, $long->get_status() );
		$this->assertSame( 'workspace_site_identity_too_long', $long->get_data()['code'] );
		$this->assertSame( 'Just another site', get_option( 'blogdescription' ) );
		$this->assertSame( 'pending', ( new Proposal_Store() )->get( $proposal['id'], get_current_user_id() )['status'], 'A refused edit leaves the proposal approvable.' );

		$data = $this->execute( $proposal, array( $key => array( 'value' => 'Person <i>tagline</i>' ) ) )->get_data();

		$this->assertSame( 1, $data['updated'] );
		$this->assertSame( 'Person tagline', $data['items'][0]['value'] );
		$this->assertSame( 'Person tagline', get_option( 'blogdescription' ) );
		$this->assertSame( 'Person tagline', $this->reported_result()['items'][0]['value'] );
	}

	/**
	 * An edit can change only the proposed value, never which option the item targets.
	 *
	 * @since x.x.x
	 */
	public function test_an_edit_cannot_retarget_the_option(): void {
		$proposal = $this->store_tagline( 'Model tagline' );
		$key      = $proposal['items'][0]['key'];

		foreach ( array( 'blogname', 'admin_email' ) as $option ) {
			$response = $this->execute(
				$proposal,
				array(
					$key => array(
						'option' => $option,
						'value'  => 'Edited',
					),
				)
			);

			$this->assertSame( 400, $response->get_status() );
			$this->assertSame( 'workspace_proposal_invalid_edit', $response->get_data()['code'] );
		}

		$this->assertSame( 'Old Site', get_option( 'blogname' ) );
		$this->assertSame( 'Just another site', get_option( 'blogdescription' ) );
	}

	/**
	 * Proposing needs a workspace turn, the tool is withheld from MCP and REST, and the write gate removes it.
	 *
	 * @since x.x.x
	 */
	public function test_proposing_needs_a_turn_and_follows_the_write_gate(): void {
		$result = wp_get_ability( Propose_Site_Identity::ABILITY )->execute(
			array(
				'items' => array(
					array(
						'option' => 'blogdescription',
						'value'  => 'Outside',
					),
				),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'workspace_context_required', $result->get_error_code() );

		$meta = wp_get_ability( Propose_Site_Identity::ABILITY )->get_meta();
		$this->assertFalse( $meta['mcp']['public'] );
		$this->assertFalse( $meta['show_in_rest'] );

		$store = new Proposal_Store();
		$this->assertContains( Propose_Site_Identity::ABILITY, $store->proposal_abilities() );
		$this->assertSame( 'manage_options', $store->kinds()[ Site_Identity_Writer::KIND ]->capability() );

		add_filter( 'wpai_workspace_write_proposals_enabled', '__return_false' );

		$this->assertNotContains( Propose_Site_Identity::ABILITY, ( new Proposal_Store() )->proposal_abilities(), 'Turning write proposals off removes the kind.' );
	}

	/**
	 * Discarding a site identity proposal tells the model which settings it named.
	 *
	 * @since x.x.x
	 */
	public function test_discarding_reports_the_setting_labels(): void {
		$proposal = $this->store_tagline( 'Discarded tagline' );

		$this->ensure_conversation();

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'DELETE', '/ai/v1/workspace/proposals/' . $proposal['id'] ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['declined'] );
		$this->assertSame( 'Just another site', get_option( 'blogdescription' ) );

		$conversation = ( new Conversation_Store() )->get( self::CONVERSATION, get_current_user_id() );
		$last         = end( $conversation['messages'] );
		$decoded      = json_decode( $last['parts'][0]['text'], true );

		$this->assertSame( 'Tagline', $decoded['wp_proposal_discarded']['items'][0]['title'] );
		$this->assertSame( 'option', $decoded['wp_proposal_discarded']['items'][0]['post_type'] );
	}
}
