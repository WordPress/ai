<?php
/**
 * Integration tests for the AI Workspace proposal store.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace
 */

namespace WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace;

use ReflectionProperty;
use WP_UnitTestCase;
use WordPress\AI\Abilities\Show_In_Abilities;
use WordPress\AI\Experiments\AI_Workspace\Draft_Writer;
use WordPress\AI\Experiments\AI_Workspace\Post_Field_Writer;
use WordPress\AI\Experiments\AI_Workspace\Proposal_Store;
use WordPress\AI\Experiments\AI_Workspace\Propose_Drafts;
use WordPress\AI\Experiments\AI_Workspace\Propose_Post_Changes;
use WordPress\AI\Experiments\AI_Workspace\Propose_Site_Identity;
use WordPress\AI\Experiments\AI_Workspace\Propose_Template;
use WordPress\AI\Experiments\AI_Workspace\Site_Identity_Writer;
use WordPress\AI\Experiments\AI_Workspace\Template_Writer;
use WordPress\AI\Logging\Logging_Integration;
use WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace\Fixtures\Test_Update_Kind;

/**
 * Proposal_Store test case.
 *
 * Covers the store's side of single approval (the claim, the five-minute window
 * after which an interrupted write reads as failed, the expiry grace), the kind
 * registry the tool list and the turn runner are built from, the write gate,
 * and edits re-validated through the kind.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Experiments\AI_Workspace\Proposal_Store
 */
class Proposal_StoreTest extends WP_UnitTestCase {

	/**
	 * The conversation proposals here belong to.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const CONVERSATION = 'store-conversation';

	/**
	 * The proposing user.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	private int $user_id = 0;

	/**
	 * Manager the logging integration held before the test replaced it.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Logging\AI_Request_Log_Manager|null
	 */
	private $original_shared_manager = null;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		// Storing a proposal records an agent event; nothing here reads the log, so none is written.
		$this->original_shared_manager = Logging_Integration::get_log_manager();
		$this->set_shared_manager( null );

		( new Show_In_Abilities() )->register();

		$this->user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->user_id );
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		remove_all_filters( 'wpai_workspace_proposal_kinds' );
		remove_all_filters( 'wpai_workspace_write_proposals_enabled' );

		$object = get_post_type_object( 'post' );

		if ( $object ) {
			// WordPress 7.2 declares the property, and unsetting a declared property leaves it
			// uninitialized, so restore core's default rather than removing it.
			if ( property_exists( \WP_Post_Type::class, 'show_in_abilities' ) ) {
				$object->show_in_abilities = true;
			} else {
				unset( $object->show_in_abilities );
			}
		}

		wp_set_current_user( 0 );

		$this->set_shared_manager( $this->original_shared_manager );

		parent::tearDown();
	}

	/**
	 * Replaces the manager the logging integration shares.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Logging\AI_Request_Log_Manager|null $manager Manager, or null for a disabled experiment.
	 */
	private function set_shared_manager( ?\WordPress\AI\Logging\AI_Request_Log_Manager $manager ): void {
		$property = new ReflectionProperty( Logging_Integration::class, 'log_manager' );
		$property->setAccessible( true );
		$property->setValue( null, $manager );
	}

	/**
	 * Stores a test-kind proposal with two items.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Experiments\AI_Workspace\Proposal_Store $store The store.
	 * @return array<string, mixed> The proposal.
	 */
	private function store_test_proposal( Proposal_Store $store ): array {
		$proposal = $store->create(
			$this->user_id,
			self::CONVERSATION,
			array( array( 'value' => 'First' ), array( 'value' => 'Second' ) ),
			Test_Update_Kind::KIND
		);

		$this->assertIsArray( $proposal );

		return $proposal;
	}

	/**
	 * Returns one valid draft item.
	 *
	 * @since x.x.x
	 *
	 * @return list<array<string, string>> The items.
	 */
	private function draft_items(): array {
		return array(
			array(
				'post_type' => 'post',
				'status'    => 'draft',
				'title'     => 'T',
				'content'   => 'Body',
			),
		);
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
		return Proposal_Store::LOCK_PREFIX . md5( $this->user_id . '|' . $proposal_id );
	}

	/**
	 * With nothing filtered, the store accepts drafts, templates, post-field and site identity changes, in that order.
	 *
	 * @since x.x.x
	 */
	public function test_default_kinds_are_drafts_templates_post_fields_and_site_identity(): void {
		$store = new Proposal_Store();

		$this->assertSame( array( Draft_Writer::KIND, Template_Writer::KIND, Post_Field_Writer::KIND, Site_Identity_Writer::KIND ), array_keys( $store->kinds() ) );
		$this->assertSame( array( Propose_Drafts::ABILITY, Propose_Template::ABILITY, Propose_Post_Changes::ABILITY, Propose_Site_Identity::ABILITY ), $store->proposal_abilities() );
	}

	/**
	 * Each built-in kind names the ability, capability and sentence that used to be hand-kept lists.
	 *
	 * @since x.x.x
	 */
	public function test_built_in_kinds_describe_their_propose_ability(): void {
		$kinds = ( new Proposal_Store() )->kinds();

		$this->assertSame( 'edit_posts', $kinds[ Draft_Writer::KIND ]->capability() );
		$this->assertSame( 'edit_theme_options', $kinds[ Template_Writer::KIND ]->capability() );
		$this->assertStringContainsString( 'call the proposal tool', $kinds[ Draft_Writer::KIND ]->instruction() );
		$this->assertStringContainsString( 'call the template proposal tool', $kinds[ Template_Writer::KIND ]->instruction() );
		$this->assertFalse( $kinds[ Draft_Writer::KIND ]->is_update() );
		$this->assertFalse( $kinds[ Template_Writer::KIND ]->accepts_edits() );
	}

	/**
	 * A kind added through the filter is accepted and its ability listed.
	 *
	 * @since x.x.x
	 */
	public function test_a_kind_added_through_the_filter_is_registered(): void {
		( new Test_Update_Kind() )->add_to_stores();

		$store = new Proposal_Store();

		$this->assertArrayHasKey( Test_Update_Kind::KIND, $store->kinds() );
		$this->assertContains( Test_Update_Kind::ABILITY, $store->proposal_abilities() );
		$this->assertIsArray( $this->store_test_proposal( $store ) );
	}

	/**
	 * A filter that returns something other than kinds cannot break the store.
	 *
	 * @since x.x.x
	 */
	public function test_a_malformed_kinds_filter_is_ignored_item_by_item(): void {
		add_filter(
			'wpai_workspace_proposal_kinds',
			static function ( $kinds ) {
				$kinds[] = 'not a kind';

				return $kinds;
			}
		);

		$this->assertSame( array( Draft_Writer::KIND, Template_Writer::KIND, Post_Field_Writer::KIND, Site_Identity_Writer::KIND ), array_keys( ( new Proposal_Store() )->kinds() ) );

		add_filter( 'wpai_workspace_proposal_kinds', '__return_false' );

		$this->assertSame( array(), ( new Proposal_Store() )->kinds() );
	}

	/**
	 * Turning write proposals off leaves the update kinds out, and only them (R8).
	 *
	 * @since x.x.x
	 */
	public function test_write_gate_leaves_update_kinds_out(): void {
		( new Test_Update_Kind() )->add_to_stores();
		add_filter( 'wpai_workspace_write_proposals_enabled', '__return_false' );

		$store = new Proposal_Store();

		$this->assertSame( array( Draft_Writer::KIND, Template_Writer::KIND ), array_keys( $store->kinds() ) );
		$this->assertNotContains( Test_Update_Kind::ABILITY, $store->proposal_abilities() );

		$refused = $store->create( $this->user_id, self::CONVERSATION, array( array( 'value' => 'x' ) ), Test_Update_Kind::KIND );

		$this->assertWPError( $refused );
		$this->assertSame( 'workspace_proposal_invalid_kind', $refused->get_error_code() );
	}

	/**
	 * The gate is on by default, and it never removes a kind that only creates things.
	 *
	 * @since x.x.x
	 */
	public function test_write_gate_keeps_kinds_that_create(): void {
		( new Test_Update_Kind( false ) )->add_to_stores();
		add_filter( 'wpai_workspace_write_proposals_enabled', '__return_false' );

		$this->assertArrayHasKey( Test_Update_Kind::KIND, ( new Proposal_Store() )->kinds() );

		remove_all_filters( 'wpai_workspace_write_proposals_enabled' );
		remove_all_filters( 'wpai_workspace_proposal_kinds' );
		( new Test_Update_Kind() )->add_to_stores();

		$this->assertArrayHasKey( Test_Update_Kind::KIND, ( new Proposal_Store() )->kinds(), 'Write proposals are allowed by default.' );
	}

	/**
	 * The client's fallback list matches the built-in kinds.
	 *
	 * The page passes the server's full list, including kinds another plugin
	 * registers (see Admin_PageTest). The client copy is only the fallback for
	 * page data without one, so it must still name every built-in kind.
	 *
	 * @since x.x.x
	 */
	public function test_client_proposal_abilities_match_the_server(): void {
		$source = file_get_contents( dirname( __DIR__, 5 ) . '/src/experiments/ai-workspace/utils/proposal.ts' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local source file.

		$this->assertIsString( $source );
		$this->assertSame( 1, preg_match( '/export const PROPOSAL_ABILITIES = \[(.*?)\];/s', $source, $match ), 'The client list must still be declared as a literal.' );

		preg_match_all( "/'([^']+)'/", $match[1], $names );

		$this->assertSame( ( new Proposal_Store() )->proposal_abilities(), $names[1] );
	}

	/**
	 * The transient outlives the proposal's expiry by the grace, so expiry can be observed.
	 *
	 * @since x.x.x
	 */
	public function test_transient_outlives_the_expiry_by_the_grace(): void {
		$store    = new Proposal_Store();
		$proposal = $store->create( $this->user_id, self::CONVERSATION, $this->draft_items() );

		$this->assertIsArray( $proposal );

		$timeout = (int) get_option( '_transient_timeout_' . Proposal_Store::TRANSIENT_PREFIX . md5( $this->user_id . '|' . $proposal['id'] ) );

		$this->assertGreaterThanOrEqual( $proposal['expires'] + Proposal_Store::GRACE, $timeout );
	}

	/**
	 * An expired proposal is still found within the grace, and `get()` still refuses it.
	 *
	 * @since x.x.x
	 */
	public function test_expired_proposal_is_found_within_the_grace(): void {
		$store    = new Proposal_Store();
		$proposal = $store->create( $this->user_id, self::CONVERSATION, $this->draft_items() );

		$proposal['expires'] = time() - 1;
		$store->save( $proposal );

		$found = $store->find( $proposal['id'], $this->user_id );

		$this->assertIsArray( $found );
		$this->assertTrue( $store->is_expired( $found ) );
		$this->assertNull( $store->get( $proposal['id'], $this->user_id ) );
		$this->assertNull( $store->find( $proposal['id'], $this->user_id ), 'Reading it through get() deletes it.' );
	}

	/**
	 * Another user cannot find a proposal either.
	 *
	 * @since x.x.x
	 */
	public function test_find_is_scoped_to_the_owner(): void {
		$store    = new Proposal_Store();
		$proposal = $store->create( $this->user_id, self::CONVERSATION, $this->draft_items() );

		$this->assertNull( $store->find( $proposal['id'], $this->user_id + 1 ) );
	}

	/**
	 * A proposal still executing after the lock window reads as failed, with its partial results.
	 *
	 * @since x.x.x
	 */
	public function test_executing_past_the_window_reads_as_failed(): void {
		( new Test_Update_Kind() )->add_to_stores();

		$store    = new Proposal_Store();
		$proposal = $this->store_test_proposal( $store );

		$proposal['status']          = 'executing';
		$proposal['executing_since'] = time() - Proposal_Store::LOCK_TTL - 1;
		$proposal['results']         = array(
			array(
				'key'     => $proposal['items'][0]['key'],
				'outcome' => 'updated',
			),
		);
		$store->save( $proposal );

		$found = $store->find( $proposal['id'], $this->user_id );

		$this->assertSame( 'failed', $found['status'] );
		$this->assertCount( 1, $found['results'] );
		$this->assertSame( 'failed', $store->get( $proposal['id'], $this->user_id )['status'] );
	}

	/**
	 * A proposal executing inside the window is still executing.
	 *
	 * @since x.x.x
	 */
	public function test_executing_inside_the_window_stays_executing(): void {
		$store    = new Proposal_Store();
		$proposal = $store->create( $this->user_id, self::CONVERSATION, $this->draft_items() );

		$proposal['status']          = 'executing';
		$proposal['executing_since'] = time() - 10;
		$store->save( $proposal );

		$this->assertSame( 'executing', $store->find( $proposal['id'], $this->user_id )['status'] );
	}

	/**
	 * Only one claim on a proposal succeeds until it is released.
	 *
	 * @since x.x.x
	 */
	public function test_claim_is_exclusive_until_released(): void {
		$store = new Proposal_Store();

		$this->assertTrue( $store->claim( 'proposal-a', $this->user_id ) );
		$this->assertFalse( $store->claim( 'proposal-a', $this->user_id ), 'A second claim must fail while the first holds.' );
		$this->assertTrue( $store->claim( 'proposal-b', $this->user_id ), 'Claims are per proposal.' );

		$store->release( 'proposal-a', $this->user_id );

		$this->assertFalse( get_option( $this->lock_name( 'proposal-a' ) ) );
		$this->assertTrue( $store->claim( 'proposal-a', $this->user_id ) );
	}

	/**
	 * A lock row another request inserted, unseen by this request's options cache, still blocks the claim.
	 */
	public function test_a_lock_row_written_outside_the_cache_blocks_the_claim(): void {
		global $wpdb;

		$store = new Proposal_Store();

		// Prime the cache with a miss, as a request that checked before the other one inserted would have.
		$this->assertFalse( get_option( $this->lock_name( 'proposal-a' ) ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Simulates the concurrent request's insert.
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' )", $this->lock_name( 'proposal-a' ), 'running:' . time() ) );

		$this->assertFalse( $store->claim( 'proposal-a', $this->user_id ), 'An existing lock row must win whatever the cache says.' );
	}

	/**
	 * A claim older than the window is released, so a crash cannot hold it forever.
	 *
	 * @since x.x.x
	 */
	public function test_a_stale_claim_is_released(): void {
		add_option( $this->lock_name( 'proposal-a' ), 'running:' . ( time() - Proposal_Store::LOCK_TTL - MINUTE_IN_SECONDS ), '', false );

		$this->assertTrue( ( new Proposal_Store() )->claim( 'proposal-a', $this->user_id ) );
	}

	/**
	 * A stale lock another request replaced after this one read it is left alone.
	 *
	 * Two requests can read the same stale lock. The first deletes it and takes
	 * a fresh one; the second must then delete nothing, rather than the first
	 * one's fresh lock, and must not claim the proposal.
	 *
	 * @since x.x.x
	 */
	public function test_a_stale_lock_replaced_before_the_delete_is_not_deleted(): void {
		global $wpdb;

		$name = $this->lock_name( 'proposal-a' );

		// A few seconds old, so it cannot be mistaken for a lock this request inserts.
		$fresh = 'running:' . ( time() - 5 );

		add_option( $name, 'running:' . ( time() - Proposal_Store::LOCK_TTL - MINUTE_IN_SECONDS ), '', false );

		$replaced = false;

		// Just before this request's delete runs, another request takes the lock.
		$replace = static function ( $query ) use ( &$replaced, $name, $fresh, $wpdb ) {
			if ( $replaced || ! is_string( $query ) || 0 !== stripos( ltrim( $query ), 'DELETE' ) || false === strpos( $query, $name ) ) {
				return $query;
			}

			$replaced = true;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Simulates the concurrent request's fresh lock.
			$wpdb->update( $wpdb->options, array( 'option_value' => $fresh ), array( 'option_name' => $name ) );

			return $query;
		};

		add_filter( 'query', $replace );

		try {
			$claimed = ( new Proposal_Store() )->claim( 'proposal-a', $this->user_id );
		} finally {
			remove_filter( 'query', $replace );
		}

		$this->assertTrue( $replaced, 'The stale lock should have been deleted by a query naming it.' );
		$this->assertFalse( $claimed, 'The other request holds the proposal now.' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The lock row is read past the options cache.
		$held = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );

		$this->assertSame( $fresh, $held, 'The other request\'s fresh lock survives.' );
	}

	/**
	 * An edit goes back through the kind, keeping its key and its snapshot (R4, KTD4).
	 *
	 * @since x.x.x
	 */
	public function test_edits_are_renormalized_keeping_key_and_snapshot(): void {
		( new Test_Update_Kind() )->add_to_stores();

		$store    = new Proposal_Store();
		$proposal = $this->store_test_proposal( $store );
		$key      = $proposal['items'][1]['key'];

		$edited = $store->apply_edits(
			$proposal,
			array(
				$key => array( 'value' => '  Edited  ' ),
			)
		);

		$this->assertIsArray( $edited );
		$this->assertSame( $proposal['items'][0], $edited['items'][0], 'An item without an edit is untouched.' );
		$this->assertSame( $key, $edited['items'][1]['key'] );
		$this->assertSame( 'Edited', $edited['items'][1]['value'], 'The edit is sanitized by the kind.' );
		$this->assertSame( Test_Update_Kind::SNAPSHOT, $edited['items'][1][ Test_Update_Kind::SNAPSHOT_FIELD ], 'The snapshot is the one taken at propose time.' );
	}

	/**
	 * An edit that names anything but the value, such as a forged key or snapshot, is refused (R4).
	 *
	 * @since x.x.x
	 */
	public function test_an_edit_naming_more_than_the_value_is_refused(): void {
		( new Test_Update_Kind() )->add_to_stores();

		$store    = new Proposal_Store();
		$proposal = $this->store_test_proposal( $store );
		$key      = $proposal['items'][1]['key'];

		foreach ( array( array( 'key' => 'forged-key' ), array( Test_Update_Kind::SNAPSHOT_FIELD => 'forged snapshot' ) ) as $forged ) {
			$edited = $store->apply_edits( $proposal, array( $key => $forged + array( 'value' => 'Edited' ) ) );

			$this->assertWPError( $edited );
			$this->assertSame( 'workspace_proposal_invalid_edit', $edited->get_error_code() );
		}
	}

	/**
	 * An edit the kind's own rules refuse is refused.
	 *
	 * @since x.x.x
	 */
	public function test_an_invalid_edit_is_refused_by_the_kind(): void {
		( new Test_Update_Kind() )->add_to_stores();

		$store    = new Proposal_Store();
		$proposal = $this->store_test_proposal( $store );

		$refused = $store->apply_edits( $proposal, array( $proposal['items'][0]['key'] => array( 'value' => '   ' ) ) );

		$this->assertWPError( $refused );
		$this->assertSame( 'test_update_empty', $refused->get_error_code() );
	}

	/**
	 * An edit for a key the proposal does not carry is refused.
	 *
	 * @since x.x.x
	 */
	public function test_an_edit_for_an_unknown_key_is_refused(): void {
		( new Test_Update_Kind() )->add_to_stores();

		$store    = new Proposal_Store();
		$proposal = $this->store_test_proposal( $store );

		$refused = $store->apply_edits( $proposal, array( 'not-a-key' => array( 'value' => 'x' ) ) );

		$this->assertWPError( $refused );
		$this->assertSame( 'workspace_proposal_unknown_item', $refused->get_error_code() );
	}

	/**
	 * Drafts and templates take no edits.
	 *
	 * @since x.x.x
	 */
	public function test_drafts_refuse_edits(): void {
		$store    = new Proposal_Store();
		$proposal = $store->create( $this->user_id, self::CONVERSATION, $this->draft_items() );

		$refused = $store->apply_edits( $proposal, array( $proposal['items'][0]['key'] => array( 'title' => 'Changed' ) ) );

		$this->assertWPError( $refused );
		$this->assertSame( 'workspace_proposal_edits_not_accepted', $refused->get_error_code() );
	}

	/**
	 * An edit that is not a set of fields is refused.
	 *
	 * @since x.x.x
	 */
	public function test_a_malformed_edit_is_refused(): void {
		( new Test_Update_Kind() )->add_to_stores();

		$store    = new Proposal_Store();
		$proposal = $this->store_test_proposal( $store );

		$refused = $store->apply_edits( $proposal, array( $proposal['items'][0]['key'] => 'just a string' ) );

		$this->assertWPError( $refused );
		$this->assertSame( 'workspace_proposal_invalid_edit', $refused->get_error_code() );
	}
}
