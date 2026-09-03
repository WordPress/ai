<?php
/**
 * Integration tests for the AI Workspace tool selector.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace
 */

namespace WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace;

use WP_UnitTestCase;
use WordPress\AI\Abilities\Content\Search_Content;
use WordPress\AI\Abilities\Show_In_Abilities;
use WordPress\AI\Experiments\AI_Workspace\Tool_Selector;

/**
 * Tool_Selector test case.
 *
 * The load-bearing assertion here is the first one: an ability the current user
 * could never invoke is never declared to the model. Everything else in the unit
 * assumes the declared list is already capability-filtered.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Experiments\AI_Workspace\Tool_Selector
 */
class Tool_SelectorTest extends WP_UnitTestCase {

	/**
	 * Ability name used as the editor-only fixture tool.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const EDITOR_ABILITY = 'wpai-test/editor-only';

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
			'editor'        => $factory->user->create( array( 'role' => 'editor' ) ),
			'author'        => $factory->user->create( array( 'role' => 'author' ) ),
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
		$this->register_abilities();

		add_filter( 'wpai_workspace_tool_candidates', array( $this, 'add_editor_only_candidate' ) );
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		remove_filter( 'wpai_workspace_tool_candidates', array( $this, 'add_editor_only_candidate' ) );

		foreach ( array( 'ai/search-content', self::EDITOR_ABILITY ) as $ability_name ) {
			if ( wp_has_ability( $ability_name ) ) {
				wp_unregister_ability( $ability_name );
			}
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
	 * Adds the editor-only fixture ability to the workspace candidate list.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, string> $candidates The candidate map.
	 * @return array<string, string> The filtered candidate map.
	 */
	public function add_editor_only_candidate( array $candidates ): array {
		$candidates[ self::EDITOR_ABILITY ] = 'edit_others_posts';

		return $candidates;
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
	 * Registers the search ability and the editor-only fixture ability.
	 *
	 * @since x.x.x
	 */
	private function register_abilities(): void {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			( new Search_Content() )->register();

			if ( ! wp_has_ability( self::EDITOR_ABILITY ) ) {
				wp_register_ability(
					self::EDITOR_ABILITY,
					array(
						'label'               => 'Editor only',
						'description'         => 'A fixture ability only an editor may invoke.',
						'category'            => 'content',
						'input_schema'        => array(
							'type'       => 'object',
							'properties' => array( 'value' => array( 'type' => 'string' ) ),
						),
						'output_schema'       => array( 'type' => 'string' ),
						'execute_callback'    => static function () {
							return 'ok';
						},
						'permission_callback' => static function () {
							return current_user_can( 'edit_others_posts' );
						},
					)
				);
			}
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
	 * An ability the user cannot run is never declared to the model.
	 *
	 * @since x.x.x
	 */
	public function test_editor_only_ability_is_not_declared_to_a_subscriber(): void {
		$this->login_as( 'subscriber' );

		$names = ( new Tool_Selector() )->get_tool_names( Tool_Selector::SCOPE_SITE );

		$this->assertNotContains(
			self::EDITOR_ABILITY,
			$names,
			'A subscriber must never be offered an ability gated on edit_others_posts.'
		);
	}

	/**
	 * The same ability is declared to a user who can run it.
	 *
	 * @since x.x.x
	 */
	public function test_editor_only_ability_is_declared_to_an_editor(): void {
		$this->login_as( 'editor' );

		$names = ( new Tool_Selector() )->get_tool_names( Tool_Selector::SCOPE_SITE );

		$this->assertContains( self::EDITOR_ABILITY, $names );
	}

	/**
	 * Contributors and authors do not receive the editor-only ability either.
	 *
	 * @since x.x.x
	 */
	public function test_lower_roles_do_not_receive_the_editor_only_ability(): void {
		foreach ( array( 'contributor', 'author' ) as $role ) {
			$this->login_as( $role );

			$this->assertNotContains(
				self::EDITOR_ABILITY,
				( new Tool_Selector() )->get_tool_names( Tool_Selector::SCOPE_SITE ),
				sprintf( 'The %s role must not be offered the editor-only ability.', $role )
			);
		}
	}

	/**
	 * A logged-out request is offered nothing at all.
	 *
	 * @since x.x.x
	 */
	public function test_logged_out_request_is_offered_no_tools(): void {
		wp_set_current_user( 0 );

		$this->assertSame( array(), ( new Tool_Selector() )->get_tool_names( Tool_Selector::SCOPE_SITE ) );
	}

	/**
	 * General Knowledge scope declares no tools even when the user could run several.
	 *
	 * @since x.x.x
	 */
	public function test_general_scope_declares_no_tools(): void {
		$this->login_as( 'administrator' );

		$this->assertNotSame(
			array(),
			( new Tool_Selector() )->get_tool_names( Tool_Selector::SCOPE_SITE ),
			'The administrator should have tools in Site Context, otherwise this test proves nothing.'
		);

		$this->assertSame(
			array(),
			( new Tool_Selector() )->get_tool_names( Tool_Selector::SCOPE_GENERAL )
		);
	}

	/**
	 * An unregistered candidate is skipped rather than declared.
	 *
	 * @since x.x.x
	 */
	public function test_unregistered_candidate_is_skipped(): void {
		$this->login_as( 'administrator' );

		add_filter(
			'wpai_workspace_tool_candidates',
			static function ( array $candidates ): array {
				$candidates['wpai-test/never-registered'] = '';

				return $candidates;
			}
		);

		$this->assertNotContains(
			'wpai-test/never-registered',
			( new Tool_Selector() )->get_tool_names( Tool_Selector::SCOPE_SITE )
		);
	}

	/**
	 * The selector explains why Site Context has no tools instead of staying silent.
	 *
	 * @since x.x.x
	 */
	public function test_unavailability_reason_distinguishes_scope_from_capability(): void {
		$this->login_as( 'subscriber' );

		add_filter(
			'wpai_workspace_tool_candidates',
			static function (): array {
				return array( 'wpai-test/editor-only' => 'edit_others_posts' );
			},
			20
		);

		$selector = new Tool_Selector();

		$this->assertSame( array(), $selector->get_tool_names( Tool_Selector::SCOPE_SITE ) );
		$this->assertSame(
			Tool_Selector::REASON_NOT_PERMITTED,
			$selector->get_unavailability_reason( Tool_Selector::SCOPE_SITE )
		);
		$this->assertSame(
			Tool_Selector::REASON_GENERAL_SCOPE,
			$selector->get_unavailability_reason( Tool_Selector::SCOPE_GENERAL )
		);
	}
}
