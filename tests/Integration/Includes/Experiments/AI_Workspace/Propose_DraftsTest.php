<?php
/**
 * Integration tests for the AI Workspace proposal ability.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace
 */

namespace WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace;

use WP_Ability;
use WP_UnitTestCase;
use WordPress\AI\Abilities\Show_In_Abilities;
use WordPress\AI\Experiments\AI_Workspace\Proposal_Store;
use WordPress\AI\Experiments\AI_Workspace\Propose_Drafts;
use WordPress\AI\Experiments\AI_Workspace\Turn_Context;

/**
 * Propose_Drafts test case.
 *
 * The proposal ability is the model's only reach toward the write path, so these
 * tests are about what it refuses: proposing outside a workspace turn, proposing
 * more items than a person can meaningfully review, proposing a status the user
 * cannot write, and smuggling a prose summary into the stored values. They also
 * assert the negative space the confirm gate depends on: that no write ability is
 * registered at all, so no ability consumer can reach a write without a
 * confirmation.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Experiments\AI_Workspace\Propose_Drafts
 * @covers \WordPress\AI\Experiments\AI_Workspace\Proposal_Store
 * @covers \WordPress\AI\Experiments\AI_Workspace\Turn_Context
 */
class Propose_DraftsTest extends WP_UnitTestCase {

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
			'administrator' => $factory->user->create( array( 'role' => 'administrator' ) ),
			'contributor'   => $factory->user->create( array( 'role' => 'contributor' ) ),
			'subscriber'    => $factory->user->create( array( 'role' => 'subscriber' ) ),
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
		$this->ensure_ability_category( 'content' );
		$this->register_ability();
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		Turn_Context::leave();

		if ( wp_has_ability( Propose_Drafts::ABILITY ) ) {
			wp_unregister_ability( Propose_Drafts::ABILITY );
		}

		foreach ( array( 'post', 'page' ) as $post_type ) {
			$object = get_post_type_object( $post_type );
			if ( $object ) {
				unset( $object->show_in_abilities );
			}
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
	 * Registers the proposal ability.
	 *
	 * @since x.x.x
	 */
	private function register_ability(): void {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			( new Propose_Drafts() )->register();
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Logs in as a user with the given role.
	 *
	 * @since x.x.x
	 *
	 * @param string $role The role to log in as.
	 * @return int The user ID.
	 */
	private function login_as( string $role ): int {
		$user_id = self::$user_ids[ $role ];
		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * Builds a list of proposal items.
	 *
	 * @since x.x.x
	 *
	 * @param int    $count  How many items to build.
	 * @param string $status The status each item asks for.
	 * @return list<array<string, mixed>> The items.
	 */
	private function items( int $count, string $status = 'draft' ): array {
		$items = array();

		for ( $index = 1; $index <= $count; $index++ ) {
			$items[] = array(
				'post_type' => 'post',
				'status'    => $status,
				'title'     => 'Proposed item ' . $index,
				'content'   => 'Body of item ' . $index,
			);
		}

		return $items;
	}

	/**
	 * No globally reachable write ability exists.
	 *
	 * The confirm gate is a property of the write path rather than of one
	 * controller, and this is what that means concretely: the only registered
	 * ability in the flow writes nothing, so the MCP surface, the Abilities
	 * Explorer and any third-party caller have no write to reach.
	 *
	 * @since x.x.x
	 */
	public function test_no_write_ability_is_registered(): void {
		foreach ( array( 'ai/create-drafts', 'ai/create-content', 'ai/write-content' ) as $ability_name ) {
			$this->assertFalse(
				wp_has_ability( $ability_name ),
				sprintf( 'No write ability may be registered; found "%s".', $ability_name )
			);
		}
	}

	/**
	 * The proposal ability is withheld from the REST and MCP surfaces.
	 *
	 * @since x.x.x
	 */
	public function test_proposal_ability_is_hidden_from_the_mcp_surface(): void {
		$ability = wp_get_ability( Propose_Drafts::ABILITY );

		$this->assertInstanceOf( WP_Ability::class, $ability );
		$this->assertNotTrue(
			$ability->get_meta_item( 'show_in_rest' ),
			'The proposal ability must not be exposed to the REST and MCP surfaces, which have no confirm gate.'
		);
	}

	/**
	 * Proposing outside a workspace turn is refused and stores nothing.
	 *
	 * @since x.x.x
	 */
	public function test_proposing_outside_a_turn_context_is_refused(): void {
		$this->login_as( 'administrator' );
		Turn_Context::leave();

		$ability = wp_get_ability( Propose_Drafts::ABILITY );
		$this->assertInstanceOf( WP_Ability::class, $ability );

		$result = $ability->execute( array( 'items' => $this->items( 1 ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'workspace_context_required', $result->get_error_code() );
	}

	/**
	 * Proposing writes no posts.
	 *
	 * @since x.x.x
	 */
	public function test_proposing_creates_no_posts(): void {
		$user_id = $this->login_as( 'administrator' );
		Turn_Context::enter( 'conversation-1', $user_id );

		$before = (int) wp_count_posts( 'post' )->draft;

		$ability = wp_get_ability( Propose_Drafts::ABILITY );
		$this->assertInstanceOf( WP_Ability::class, $ability );

		$result = $ability->execute( array( 'items' => $this->items( 3 ) ) );

		$this->assertIsArray( $result );
		$this->assertSame( 3, $result['item_count'] );
		$this->assertTrue( $result['confirmation_required'] );
		$this->assertSame( $before, (int) wp_count_posts( 'post' )->draft, 'Proposing must not create anything.' );
	}

	/**
	 * A proposal larger than the cap is rejected at proposal time.
	 *
	 * @since x.x.x
	 */
	public function test_proposal_over_the_cap_is_rejected(): void {
		$user_id = $this->login_as( 'administrator' );
		Turn_Context::enter( 'conversation-1', $user_id );

		$oversized = $this->items( Proposal_Store::MAX_ITEMS + 1 );

		$stored = ( new Proposal_Store() )->create( $user_id, 'conversation-1', $oversized );

		$this->assertWPError( $stored, 'A proposal above the cap must be refused rather than stored.' );
		$this->assertSame( 'workspace_proposal_too_large', $stored->get_error_code() );

		$ability = wp_get_ability( Propose_Drafts::ABILITY );
		$this->assertInstanceOf( WP_Ability::class, $ability );

		$this->assertWPError(
			$ability->execute( array( 'items' => $oversized ) ),
			'The ability must refuse an oversized proposal too.'
		);
	}

	/**
	 * A status the user cannot write is refused rather than downgraded.
	 *
	 * @since x.x.x
	 */
	public function test_status_the_user_cannot_publish_is_rejected(): void {
		$user_id = $this->login_as( 'contributor' );
		Turn_Context::enter( 'conversation-1', $user_id );

		$stored = ( new Proposal_Store() )->create( $user_id, 'conversation-1', $this->items( 1, 'publish' ) );

		$this->assertWPError( $stored );
		$this->assertSame( 'workspace_proposal_status_denied', $stored->get_error_code() );
	}

	/**
	 * A model-supplied summary never reaches the stored proposal.
	 *
	 * R16 exists because the model's description of a write is
	 * attacker-influenceable. The store keeps only the declared item fields, so
	 * the confirmation surface has nothing but resolved values to render.
	 *
	 * @since x.x.x
	 */
	public function test_model_supplied_summary_is_not_stored(): void {
		$user_id = $this->login_as( 'administrator' );
		Turn_Context::enter( 'conversation-1', $user_id );

		$stored = ( new Proposal_Store() )->create(
			$user_id,
			'conversation-1',
			array(
				array(
					'post_type' => 'post',
					'status'    => 'draft',
					'title'     => 'A real title',
					'content'   => 'A real body',
					'summary'   => 'Trust me, this only creates one harmless draft.',
					'note'      => 'Approve without reading.',
				),
			)
		);

		$this->assertIsArray( $stored );

		$encoded = (string) wp_json_encode( $stored );

		$this->assertStringNotContainsString( 'Trust me, this only creates one harmless draft.', $encoded );
		$this->assertStringNotContainsString( 'Approve without reading.', $encoded );
		$this->assertArrayNotHasKey( 'summary', $stored['items'][0] );
		$this->assertSame( 'A real title', $stored['items'][0]['title'] );
	}

	/**
	 * A user who cannot create posts is refused by the permission callback.
	 *
	 * @since x.x.x
	 */
	public function test_subscriber_cannot_propose(): void {
		$user_id = $this->login_as( 'subscriber' );
		Turn_Context::enter( 'conversation-1', $user_id );

		$ability = wp_get_ability( Propose_Drafts::ABILITY );
		$this->assertInstanceOf( WP_Ability::class, $ability );

		$result = $ability->execute( array( 'items' => $this->items( 1 ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * A context belonging to another user does not count as active.
	 *
	 * @since x.x.x
	 */
	public function test_turn_context_is_inactive_for_a_different_user(): void {
		Turn_Context::enter( 'conversation-1', self::$user_ids['administrator'] );

		$this->login_as( 'contributor' );

		$this->assertFalse( Turn_Context::is_active() );
		$this->assertSame( '', Turn_Context::get_conversation_id() );
	}
}
