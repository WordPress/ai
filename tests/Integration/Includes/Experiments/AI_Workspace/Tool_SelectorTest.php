<?php
/**
 * Integration tests for the AI Workspace tool selector.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace
 */

namespace WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace;

use WP_Ability;
use WP_UnitTestCase;
use WordPress\AI\Abilities\Content\Read_Content_Bodies;
use WordPress\AI\Abilities\Content\Search_Content;
use WordPress\AI\Abilities\Show_In_Abilities;
use WordPress\AI\Experiments\Abilities_Explorer\Ability_Handler;
use WordPress\AI\Experiments\AI_Workspace\Propose_Drafts;
use WordPress\AI\Experiments\AI_Workspace\Tool_Policy;
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
	 * The provisional declaration meta key.
	 *
	 * Duplicated from {@see Tool_Policy} on purpose: the key is private there
	 * because issue #354 owns its public name, and these tests assert behavior at
	 * the key the policy currently honors.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const DECLARATION_CHANNEL = 'ai-workspace';

	/**
	 * The curated floor, in registration order, by name.
	 *
	 * Written out rather than derived from `Tool_Selector` so that a change to the
	 * shipped surface has to be made deliberately in both places. Several tests
	 * assert equality against this list, which is the whole point of R8: policy
	 * only ever adds to it.
	 *
	 * @since x.x.x
	 *
	 * @var list<string>
	 */
	private const CURATED_SURFACE = array( // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is a single array constant.
		'ai/search-content',
		'ai/read-content-bodies',
		'ai/propose-drafts',
	);

	/**
	 * Names of the fixture abilities registered by the current test.
	 *
	 * @since x.x.x
	 *
	 * @var list<string>
	 */
	private $registered = array();

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

		/*
		 * Declaration-based admission ships off until issue #354 settles the
		 * declaration's public shape. This case is about the policy that runs
		 * once it is on, so the gate is opened here;
		 * {@see self::test_admission_is_off_until_the_declaration_is_public()}
		 * opens no gate and pins the shipped default instead.
		 */
		add_filter( 'wpai_workspace_tool_admission_enabled', '__return_true' );
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		remove_filter( 'wpai_workspace_tool_admission_enabled', '__return_true' );
		remove_filter( 'wpai_workspace_tool_candidates', array( $this, 'add_editor_only_candidate' ) );

		$ability_names = array_merge(
			array( 'ai/search-content', 'ai/read-content-bodies', 'ai/propose-drafts', self::EDITOR_ABILITY ),
			$this->registered
		);

		foreach ( $ability_names as $ability_name ) {
			if ( wp_has_ability( $ability_name ) ) {
				wp_unregister_ability( $ability_name );
			}
		}

		$this->registered = array();

		delete_option( Tool_Policy::POLICY_DISABLED_OPTION );
		delete_option( Tool_Policy::OWNER_EXCLUSIONS_OPTION );

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
						/*
						 * The effect-class check is applied to the merged candidate
						 * map, so a filter-added fixture has to satisfy it before the
						 * capability assertions below can say anything about
						 * capability filtering rather than about annotations.
						 */
						'meta'                => array(
							'annotations' => array(
								'readonly'    => true,
								'destructive' => false,
								'open_world'  => false,
							),
						),
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

		$selector = new Tool_Selector();

		$this->assertNotContains(
			'wpai-test/never-registered',
			$selector->get_tool_names( Tool_Selector::SCOPE_SITE )
		);

		add_filter(
			'wpai_workspace_tool_candidates',
			static function (): array {
				return array( 'wpai-test/never-registered' => '' );
			},
			30
		);

		$this->assertSame(
			Tool_Selector::REASON_NO_CANDIDATES,
			$selector->get_unavailability_reason( Tool_Selector::SCOPE_SITE ),
			'A candidate list whose every name is unregistered is a statement about the registry, not about a surface anyone emptied.'
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

	/**
	 * A declared, read-only, closed-world ability reaches the model.
	 *
	 * @since x.x.x
	 */
	public function test_declared_readonly_ability_is_admitted(): void {
		$this->require_filtered_discovery();
		$this->login_as( 'administrator' );

		$this->register_fixture(
			'wpai-test/declared-reader',
			array(
				self::DECLARATION_CHANNEL => array( 'public' => true ),
				'annotations'         => $this->safe_annotations(),
			)
		);

		$this->assertContains(
			'wpai-test/declared-reader',
			( new Tool_Selector() )->get_tool_names( Tool_Selector::SCOPE_SITE ),
			'An ability that declares itself for the conversational surface and asserts a safe effect class must be admitted.'
		);
	}

	/**
	 * An undeclared ability is not admitted however carefully it is annotated.
	 *
	 * @since x.x.x
	 */
	public function test_undeclared_ability_is_not_admitted_however_annotated(): void {
		$this->require_filtered_discovery();
		$this->login_as( 'administrator' );

		$this->register_fixture(
			'wpai-test/undeclared-reader',
			array( 'annotations' => $this->safe_annotations() )
		);

		$this->assertNotContains(
			'wpai-test/undeclared-reader',
			( new Tool_Selector() )->get_tool_names( Tool_Selector::SCOPE_SITE ),
			'Annotations alone are not a declaration: an ability that never opted in must not be admitted by policy.'
		);
	}

	/**
	 * A declared ability whose effect class is wrong is not admitted.
	 *
	 * @since x.x.x
	 *
	 * @dataProvider data_inadmissible_effect_classes
	 *
	 * @param array<string, mixed> $annotations The annotations to register.
	 * @param string               $ability_id  The fixture ability name.
	 * @param string               $message     The assertion message.
	 */
	public function test_declared_ability_with_wrong_effect_class_is_not_admitted( array $annotations, string $ability_id, string $message ): void {
		$this->require_filtered_discovery();
		$this->login_as( 'administrator' );

		$this->register_fixture(
			$ability_id,
			array(
				self::DECLARATION_CHANNEL => array( 'public' => true ),
				'annotations'         => $annotations,
			)
		);

		$this->assertNotContains(
			$ability_id,
			( new Tool_Selector() )->get_tool_names( Tool_Selector::SCOPE_SITE ),
			$message
		);
	}

	/**
	 * Data provider for declared abilities that fail the effect-class check.
	 *
	 * `open_world` is covered here rather than in its own test because it fails
	 * for the same reason as the other two: the assertion is missing or wrong.
	 * It is the one most easily forgotten, so both the absent and the explicitly
	 * true case are listed.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{array<string, mixed>, string, string}> Provider data.
	 */
	public function data_inadmissible_effect_classes(): array {
		return array(
			'writes'            => array(
				array(
					'readonly'    => false,
					'destructive' => false,
					'open_world'  => false,
				),
				'wpai-test/declared-writer',
				'A declared ability that is not read-only must not be admitted.',
			),
			'destructive'       => array(
				array(
					'readonly'    => true,
					'destructive' => true,
					'open_world'  => false,
				),
				'wpai-test/declared-destroyer',
				'A declared ability that reports itself destructive must not be admitted.',
			),
			'open world'        => array(
				array(
					'readonly'    => true,
					'destructive' => false,
					'open_world'  => true,
				),
				'wpai-test/declared-open-world',
				'A declared read-only ability that may reach external systems must not be admitted.',
			),
			'open world absent' => array(
				array(
					'readonly'    => true,
					'destructive' => false,
				),
				'wpai-test/declared-silent-world',
				'A declared read-only ability that says nothing about open_world must fail closed.',
			),
		);
	}

	/**
	 * With nothing declared, the 7.1 surface is exactly the curated floor.
	 *
	 * This is the assertion the "ships dark" safety argument rests on. Default
	 * deny is only safe if it denies *in addition to* the curated floor rather
	 * than instead of it, and merge order is the easiest thing to get wrong.
	 *
	 * @since x.x.x
	 */
	public function test_surface_is_the_curated_floor_when_nothing_declares(): void {
		$this->require_filtered_discovery();
		$this->use_curated_surface_only();

		$this->assertSame(
			self::CURATED_SURFACE,
			( new Tool_Selector() )->get_tool_names( Tool_Selector::SCOPE_SITE ),
			'On a WordPress that supports filtered discovery, a site where nothing declares must still get the three curated abilities.'
		);
	}

	/**
	 * With filtered discovery unavailable, the surface is the curated floor.
	 *
	 * @since x.x.x
	 */
	public function test_policy_off_on_old_wordpress_returns_the_curated_surface(): void {
		$this->use_curated_surface_only();

		$this->register_fixture(
			'wpai-test/declared-reader',
			array(
				self::DECLARATION_CHANNEL => array( 'public' => true ),
				'annotations'         => $this->safe_annotations(),
			)
		);

		/*
		 * Without this the test passes on a fixture that stopped being
		 * admissible for reasons that have nothing to do with the WordPress
		 * version — the assertion below is satisfied by any absence at all.
		 */
		if ( ( new Tool_Policy() )->supports_filtered_discovery() ) {
			$this->assertContains(
				'wpai-test/declared-reader',
				( new Tool_Selector() )->get_tool_names( Tool_Selector::SCOPE_SITE ),
				'A policy that can filter discovery must admit the fixture first, otherwise this test proves nothing about the version fallback.'
			);
		}

		$this->assertSame(
			self::CURATED_SURFACE,
			$this->selector_without_filtered_discovery()->get_tool_names( Tool_Selector::SCOPE_SITE ),
			'Without filtered discovery the surface must fall back to the curated abilities, never to the whole registry.'
		);
	}

	/**
	 * The kill switch resolves to the same branch as the old-WordPress fallback.
	 *
	 * @since x.x.x
	 */
	public function test_kill_switch_returns_the_curated_surface(): void {
		$this->use_curated_surface_only();

		$this->register_fixture(
			'wpai-test/declared-reader',
			array(
				self::DECLARATION_CHANNEL => array( 'public' => true ),
				'annotations'         => $this->safe_annotations(),
			)
		);

		$selector = new Tool_Selector();

		if ( ( new Tool_Policy() )->supports_filtered_discovery() ) {
			$this->assertContains(
				'wpai-test/declared-reader',
				$selector->get_tool_names( Tool_Selector::SCOPE_SITE ),
				'The declared ability must be admitted before the kill switch is thrown, otherwise this test proves nothing.'
			);
		}

		update_option( Tool_Policy::POLICY_DISABLED_OPTION, true );

		$this->assertSame(
			self::CURATED_SURFACE,
			$selector->get_tool_names( Tool_Selector::SCOPE_SITE ),
			'With the kill switch on, the surface must be the curated abilities and nothing else.'
		);
	}

	/**
	 * The candidates filter still adds an undeclared ability and removes a declared one.
	 *
	 * @since x.x.x
	 */
	public function test_candidates_filter_still_adds_and_removes(): void {
		$this->require_filtered_discovery();
		$this->login_as( 'administrator' );

		$this->register_fixture(
			'wpai-test/declared-reader',
			array(
				self::DECLARATION_CHANNEL => array( 'public' => true ),
				'annotations'         => $this->safe_annotations(),
			)
		);
		$this->register_fixture(
			'wpai-test/filter-only',
			array( 'annotations' => $this->safe_annotations() )
		);

		add_filter(
			'wpai_workspace_tool_candidates',
			static function ( array $candidates ): array {
				unset( $candidates['wpai-test/declared-reader'] );
				$candidates['wpai-test/filter-only'] = '';

				return $candidates;
			},
			20
		);

		$names = ( new Tool_Selector() )->get_tool_names( Tool_Selector::SCOPE_SITE );

		$this->assertContains(
			'wpai-test/filter-only',
			$names,
			'The candidates filter must remain able to add an ability that carries no declaration.'
		);
		$this->assertNotContains(
			'wpai-test/declared-reader',
			$names,
			'The candidates filter must remain able to remove an ability the policy admitted.'
		);
	}

	/**
	 * The candidates filter bypasses the declaration, never the effect class.
	 *
	 * @since x.x.x
	 */
	public function test_filter_cannot_add_a_mutating_ability(): void {
		$this->login_as( 'administrator' );

		$this->register_fixture(
			'wpai-test/filter-writer',
			array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'open_world'  => false,
				),
			)
		);

		add_filter(
			'wpai_workspace_tool_candidates',
			static function ( array $candidates ): array {
				$candidates['wpai-test/filter-writer'] = '';

				return $candidates;
			},
			20
		);

		$this->assertNotContains(
			'wpai-test/filter-writer',
			( new Tool_Selector() )->get_tool_names( Tool_Selector::SCOPE_SITE ),
			'A mutating ability must not reach the model through the candidates filter while the system instruction still promises the model cannot write.'
		);
	}

	/**
	 * A third-party result filter cannot re-admit an undeclared ability.
	 *
	 * `wp_get_abilities_result` is site-wide and fires on the workspace's own
	 * discovery call, and unlike the per-item filters it can hand back an
	 * ability the query never matched. So the query's return is a candidate set
	 * rather than an authority, and everything in it is re-verified here.
	 *
	 * @since x.x.x
	 */
	public function test_third_party_result_filter_cannot_re_admit_an_undeclared_ability(): void {
		$this->require_filtered_discovery();
		$this->login_as( 'administrator' );

		$this->register_fixture(
			'wpai-test/smuggled',
			array( 'annotations' => $this->safe_annotations() )
		);

		add_filter( 'wp_get_abilities_result', array( $this, 'smuggle_undeclared_ability' ) );

		/*
		 * The negative assertion below is a no-op if the smuggle never happened:
		 * if core renamed the hook, or the discovery arguments stopped reaching
		 * it, nothing would be injected and the test would stay green while
		 * proving nothing. So prove the injection first, against the policy's
		 * own discovery query.
		 */
		$this->assertContains(
			'wpai-test/smuggled',
			$this->discovered_names(),
			'The result filter must actually put the undeclared ability into the discovery query, otherwise the refusal below is asserted against a smuggle that never happened.'
		);

		$this->assertNotContains(
			'wpai-test/smuggled',
			( new Tool_Selector() )->get_tool_names( Tool_Selector::SCOPE_SITE ),
			'A plugin hooking the site-wide result filter must not be able to put an undeclared ability on the conversational surface.'
		);

		remove_filter( 'wp_get_abilities_result', array( $this, 'smuggle_undeclared_ability' ) );
	}

	/**
	 * Injects an undeclared ability into a discovery result.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $matched The matched abilities.
	 * @return mixed The matched abilities, with the undeclared fixture added.
	 */
	public function smuggle_undeclared_ability( $matched ) {
		$ability = wp_get_ability( 'wpai-test/smuggled' );

		if ( is_array( $matched ) && $ability instanceof WP_Ability ) {
			$matched['wpai-test/smuggled'] = $ability;
		}

		return $matched;
	}

	/**
	 * Discovery narrows nothing, and the per-item re-check is what holds.
	 *
	 * This used to assert that core's declarative `meta` match ran before
	 * `wp_get_abilities_item_include` fired, so an undeclared ability was gone
	 * before a third party could vote it back in. That guarantee is no longer
	 * available: a channel inherits eligibility from the general `meta.public`
	 * flag, core's `meta` matching cannot express that fallback, and the
	 * discovery query therefore carries no condition at all. Every registered
	 * ability now comes back from discovery.
	 *
	 * So the query is not a boundary and must not be mistaken for one. What
	 * keeps an ineligible ability off the surface is the selector re-resolving
	 * eligibility on each returned item — and this pins that, by widening the
	 * one filter core lets a third party widen and showing the surface unmoved.
	 *
	 * @since x.x.x
	 */
	public function test_surface_holds_when_an_include_filter_widens_discovery(): void {
		$this->require_filtered_discovery();
		$this->login_as( 'administrator' );

		$this->register_fixture(
			'wpai-test/include-smuggled',
			array( 'annotations' => $this->safe_annotations() )
		);

		add_filter( 'wp_get_abilities_item_include', '__return_true', 10, 1 );

		$discovered = $this->discovered_names();

		remove_filter( 'wp_get_abilities_item_include', '__return_true', 10 );

		$this->assertContains(
			'wpai-test/include-smuggled',
			$discovered,
			'Discovery must be understood to narrow nothing: with no meta condition to match on, an undeclared ability comes straight back, and any code treating this query as the admission boundary is wrong.'
		);
		$this->assertNotContains(
			'wpai-test/include-smuggled',
			( new Tool_Selector() )->get_tool_names( Tool_Selector::SCOPE_SITE ),
			'An ability that resolves to not public must not reach the conversational surface, however wide discovery was made: the selector’s per-item re-check is the boundary.'
		);
	}

	/**
	 * Returns the ability names the policy's own discovery query matches.
	 *
	 * @since x.x.x
	 *
	 * @return list<string> The discovered ability names.
	 */
	private function discovered_names(): array {
		$names = array();

		foreach ( wp_get_abilities( ( new Tool_Policy() )->get_discovery_args() ) as $ability ) {
			if ( $ability instanceof WP_Ability ) {
				$names[] = $ability->get_name();
			}
		}

		return $names;
	}

	/**
	 * The policy leaves every other consumer of the registry alone.
	 *
	 * @since x.x.x
	 */
	public function test_explorer_inventory_is_unchanged_by_the_policy(): void {
		$this->login_as( 'administrator' );

		$this->register_fixture(
			'wpai-test/declared-reader',
			array(
				self::DECLARATION_CHANNEL => array( 'public' => true ),
				'annotations'         => $this->safe_annotations(),
			)
		);
		$this->register_fixture(
			'wpai-test/undeclared-reader',
			array( 'annotations' => $this->safe_annotations() )
		);

		$before = $this->explorer_slugs();

		( new Tool_Selector() )->get_tool_names( Tool_Selector::SCOPE_SITE );

		$this->assertSame(
			$before,
			$this->explorer_slugs(),
			'Running the workspace admission policy must not change what the Abilities Explorer inventories.'
		);
		$this->assertContains(
			'wpai-test/undeclared-reader',
			$before,
			'The Explorer must still list an ability the workspace declines to admit, otherwise this test proves nothing.'
		);
	}

	/**
	 * Declaration-based admission is off until the declaration is public.
	 *
	 * The declaration key is private to {@see Tool_Policy}, but this is an open
	 * source plugin: an ability author needs nothing but the literal string to
	 * opt in. So "private" is not what keeps the surface from growing on merge —
	 * the gate is, and it ships closed until issue #354 settles the public
	 * shape.
	 *
	 * @since x.x.x
	 */
	public function test_admission_is_off_until_the_declaration_is_public(): void {
		$this->require_filtered_discovery();
		$this->login_as( 'administrator' );

		$this->register_fixture(
			'wpai-test/gated-reader',
			array(
				self::DECLARATION_CHANNEL => array( 'public' => true ),
				'annotations'         => $this->safe_annotations(),
			)
		);

		$this->assertContains(
			'wpai-test/gated-reader',
			( new Tool_Selector() )->get_tool_names( Tool_Selector::SCOPE_SITE ),
			'With the gate open the fixture must be admitted, otherwise the closed-gate assertion below proves nothing.'
		);

		remove_filter( 'wpai_workspace_tool_admission_enabled', '__return_true' );

		$this->assertFalse(
			( new Tool_Policy() )->admission_is_enabled(),
			'Declaration-based admission must be off by default until the declaration has a public shape.'
		);

		$names = ( new Tool_Selector() )->get_tool_names( Tool_Selector::SCOPE_SITE );

		$this->assertNotContains(
			'wpai-test/gated-reader',
			$names,
			'With admission gated off, a perfectly declared ability must not reach the model.'
		);
		$this->assertContains(
			Tool_Selector::SEARCH_ABILITY,
			$names,
			'The gate withholds admissions, never the curated floor.'
		);
	}

	/**
	 * A surface the owner emptied says so rather than blaming the registry.
	 *
	 * @since x.x.x
	 */
	public function test_unavailability_reason_reports_a_surface_the_owner_emptied(): void {
		$this->use_curated_surface_only();

		$policy = new Tool_Policy();

		foreach ( self::CURATED_SURFACE as $ability_name ) {
			$policy->exclude_from_surface( $ability_name );
		}

		$selector = new Tool_Selector();

		$this->assertSame(
			array(),
			$selector->get_tool_names( Tool_Selector::SCOPE_SITE ),
			'An owner who removed every candidate must be left with no tools at all.'
		);
		$this->assertSame(
			Tool_Selector::REASON_SURFACE_EMPTIED,
			$selector->get_unavailability_reason( Tool_Selector::SCOPE_SITE ),
			'A surface the owner emptied must not be reported as a site that registers no abilities.'
		);
	}

	/**
	 * An owner's removal holds with no bootstrap having run.
	 *
	 * The Abilities Explorer is a separate experiment from the AI Workspace, and
	 * on a site with no function-calling connector the Explorer is on while the
	 * workspace is off. Nothing may have to be hooked for the owner's control to
	 * work, or that site shows a removed ability as one the assistant holds.
	 *
	 * @since x.x.x
	 */
	public function test_owner_removal_holds_without_the_workspace_bootstrap(): void {
		$this->use_curated_surface_only();

		$this->assertContains(
			Tool_Selector::SEARCH_ABILITY,
			( new Tool_Selector() )->get_tool_names( Tool_Selector::SCOPE_SITE ),
			'The curated search ability must be on the surface before its removal can prove anything.'
		);

		( new Tool_Policy() )->exclude_from_surface( Tool_Selector::SEARCH_ABILITY );

		$this->assertNotContains(
			Tool_Selector::SEARCH_ABILITY,
			( new Tool_Selector() )->get_tool_names( Tool_Selector::SCOPE_SITE ),
			'An owner removal must take effect on a request where no experiment bootstrap registered anything.'
		);
	}

	/**
	 * Skips a test on a WordPress that cannot filter ability discovery.
	 *
	 * @since x.x.x
	 */
	private function require_filtered_discovery(): void {
		if ( ! ( new Tool_Policy() )->supports_filtered_discovery() ) {
			$this->markTestSkipped( 'This WordPress does not support filtered ability discovery.' );
		}
	}

	/**
	 * Returns annotations that satisfy the effect-class check.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, bool> The annotations.
	 */
	private function safe_annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'open_world'  => false,
		);
	}

	/**
	 * Returns the ability slugs the Abilities Explorer inventories.
	 *
	 * Slugs rather than rows: the Explorer's row shape is expected to grow a
	 * conversational-surface column, and a structural assertion would go red for
	 * a change that is not a regression.
	 *
	 * @since x.x.x
	 *
	 * @return list<string> The inventoried ability slugs, sorted.
	 */
	private function explorer_slugs(): array {
		$slugs = array();

		foreach ( Ability_Handler::get_all_abilities() as $ability ) {
			if ( isset( $ability['slug'] ) && is_string( $ability['slug'] ) ) {
				$slugs[] = $ability['slug'];
			}
		}

		sort( $slugs );

		return $slugs;
	}

	/**
	 * Registers the two curated abilities that setUp does not, and drops the fixture filter.
	 *
	 * The floor assertions compare the whole surface by name, so the editor-only
	 * fixture the rest of this case relies on has to come back off first.
	 *
	 * @since x.x.x
	 */
	private function use_curated_surface_only(): void {
		remove_filter( 'wpai_workspace_tool_candidates', array( $this, 'add_editor_only_candidate' ) );

		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			( new Read_Content_Bodies() )->register();
			( new Propose_Drafts() )->register();
		} finally {
			array_pop( $wp_current_filter );
		}

		$this->login_as( 'administrator' );

		foreach ( self::CURATED_SURFACE as $ability_name ) {
			$this->assertTrue(
				wp_has_ability( $ability_name ),
				sprintf( 'The curated ability %s must be registered before the floor can be asserted.', $ability_name )
			);
		}
	}

	/**
	 * Builds a selector whose policy probe sees a WordPress 7.0 discovery signature.
	 *
	 * `.wp-env.json` pins core to the latest release, so 7.0's zero-parameter
	 * `wp_get_abilities()` cannot be installed locally. The policy reads the name
	 * of the function it inspects from an overridable seam, and the fixture at the
	 * foot of this file stands in for that signature.
	 *
	 * @since x.x.x
	 *
	 * @return Tool_Selector The selector under test.
	 */
	private function selector_without_filtered_discovery(): Tool_Selector {
		$policy = new class() extends Tool_Policy {

			/**
			 * Returns a discovery function that accepts no arguments.
			 *
			 * @return string The discovery function name.
			 */
			protected function get_discovery_function_name(): string {
				return __NAMESPACE__ . '\\wpai_test_selector_unfiltered_abilities';
			}
		};

		return new Tool_Selector( $policy );
	}

	/**
	 * Registers a fixture ability carrying the given meta.
	 *
	 * @since x.x.x
	 *
	 * @param string               $ability_name The ability name.
	 * @param array<string, mixed> $meta         The ability meta.
	 */
	private function register_fixture( string $ability_name, array $meta ): void {
		$this->ensure_ability_category( 'content' );

		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			wp_register_ability(
				$ability_name,
				array(
					'label'               => 'Selector fixture',
					'description'         => 'A fixture ability used to exercise the admission policy.',
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
						return true;
					},
					'meta'                => $meta,
				)
			);
		} finally {
			array_pop( $wp_current_filter );
		}

		$this->registered[] = $ability_name;

		$this->assertTrue(
			wp_has_ability( $ability_name ),
			sprintf( 'The fixture ability %s must register before the selector can be asked about it.', $ability_name )
		);
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\wpai_test_selector_unfiltered_abilities' ) ) {
	/**
	 * Stands in for WordPress 7.0's `wp_get_abilities()`, which accepts nothing.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, \WP_Ability> Every registered ability.
	 */
	function wpai_test_selector_unfiltered_abilities(): array {
		return function_exists( 'wp_get_abilities' ) ? wp_get_abilities() : array();
	}
}
