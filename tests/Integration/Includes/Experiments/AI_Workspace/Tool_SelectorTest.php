<?php
/**
 * Integration tests for the AI Workspace tool selector.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace
 */

namespace WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace;

use WP_Ability;
use WP_UnitTestCase;
use WordPress\AI\Abilities\Content\List_Terms;
use WordPress\AI\Abilities\Content\Read_Content_Bodies;
use WordPress\AI\Abilities\Content\Search_Content;
use WordPress\AI\Abilities\Show_In_Abilities;
use WordPress\AI\Abilities\Site\Find_Admin_Screens;
use WordPress\AI\Abilities\Site\Get_Site_Info;
use WordPress\AI\Experiments\AI_Workspace\Ask_User;
use WordPress\AI\Experiments\AI_Workspace\Propose_Drafts;
use WordPress\AI\Experiments\AI_Workspace\Propose_Post_Changes;
use WordPress\AI\Experiments\AI_Workspace\Propose_Site_Identity;
use WordPress\AI\Experiments\AI_Workspace\Propose_Template;
use WordPress\AI\Experiments\AI_Workspace\Tool_Policy;
use WordPress\AI\Experiments\AI_Workspace\Tool_Selector;
use WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace\Fixtures\Test_Update_Kind;
use WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace\Fixtures\Workspace_Tools;

/**
 * Tool_Selector test case.
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
	 * Duplicated rather than read from Tool_Policy, where the key is private.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const DECLARATION_CHANNEL = 'ai-workspace';

	/**
	 * The curated floor, in registration order, by name.
	 *
	 * Written out, not derived from Tool_Selector, so a surface change must be made in both places.
	 *
	 * @since x.x.x
	 *
	 * @var list<string>
	 */
	private const CURATED_SURFACE = array( // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is a single array constant.
		'ai/search-content',
		'ai/read-content-bodies',
		'ai/list-terms',
		'ai/get-site-info',
		'ai/find-admin-screens',
		'ai/propose-drafts',
		'ai/propose-template',
		'ai/propose-post-changes',
		'ai/propose-site-identity',
		'ai/ask-user',
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
		remove_all_filters( 'wpai_workspace_proposal_kinds' );
		remove_all_filters( 'wpai_workspace_write_proposals_enabled' );
		Test_Update_Kind::unregister_ability();

		$ability_names = array_merge(
			array( 'ai/search-content', 'ai/read-content-bodies', 'ai/list-terms', 'ai/get-site-info', 'ai/find-admin-screens', 'ai/propose-drafts', 'ai/propose-template', 'ai/propose-post-changes', 'ai/propose-site-identity', 'ai/ask-user', self::EDITOR_ABILITY ),
			$this->registered
		);

		foreach ( $ability_names as $ability_name ) {
			if ( ! wp_has_ability( $ability_name ) ) {
				continue;
			}

			wp_unregister_ability( $ability_name );
		}

		$this->registered = array();

		delete_option( Tool_Policy::POLICY_DISABLED_OPTION );
		delete_option( Tool_Policy::OWNER_EXCLUSIONS_OPTION );

		foreach ( array( 'post', 'page' ) as $post_type ) {
			$object = get_post_type_object( $post_type );
			if ( ! $object ) {
				continue;
			}

			// Unsetting a property WordPress 7.2 declares leaves it uninitialized, so restore the default.
			if ( property_exists( \WP_Post_Type::class, 'show_in_abilities' ) ) {
				$object->show_in_abilities = true;
			} else {
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
	 * An ability is declared to the model only for users who could run it.
	 *
	 * @since x.x.x
	 *
	 * @dataProvider data_editor_only_ability_by_role
	 *
	 * @param string $role     The role to log in as.
	 * @param bool   $expected Whether the editor-only ability should be declared.
	 */
	public function test_editor_only_ability_is_declared_only_to_roles_that_can_run_it( string $role, bool $expected ): void {
		$this->login_as( $role );

		$names = ( new Tool_Selector() )->get_tool_names( Tool_Selector::SCOPE_SITE );

		$this->assertSame(
			$expected,
			in_array( self::EDITOR_ABILITY, $names, true ),
			sprintf(
				$expected
					? 'The %s role must be offered the ability gated on edit_others_posts.'
					: 'The %s role must never be offered an ability gated on edit_others_posts.',
				$role
			)
		);
	}

	/**
	 * Data provider for the editor-only ability by role.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{string, bool}> Provider data.
	 */
	public function data_editor_only_ability_by_role(): array {
		return array(
			'subscriber'  => array( 'subscriber', false ),
			'contributor' => array( 'contributor', false ),
			'author'      => array( 'author', false ),
			'editor'      => array( 'editor', true ),
		);
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
	 * With nothing declared, the 7.1 surface is exactly the curated floor.
	 *
	 * @since x.x.x
	 */
	public function test_surface_is_the_curated_floor_when_nothing_declares(): void {
		$this->require_filtered_discovery();
		$this->use_curated_surface_only();

		$this->assertSame(
			self::CURATED_SURFACE,
			( new Tool_Selector() )->get_tool_names( Tool_Selector::SCOPE_SITE ),
			'On a WordPress that supports filtered discovery, a site where nothing declares must still get exactly the curated abilities.'
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
				'annotations'             => $this->safe_annotations(),
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
				'annotations'             => $this->safe_annotations(),
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
				'annotations'             => $this->safe_annotations(),
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
	 * A third-party result filter cannot put a rejected ability back on the surface.
	 *
	 * @since x.x.x
	 */
	public function test_third_party_result_filter_cannot_re_admit_a_rejected_ability(): void {
		$this->require_filtered_discovery();
		$this->login_as( 'administrator' );

		$this->register_fixture(
			'wpai-test/smuggled',
			array(
				self::DECLARATION_CHANNEL => array( 'public' => true ),
				'annotations'             => array_merge( $this->safe_annotations(), array( 'readonly' => false ) ),
			)
		);

		$smuggled = false;
		$smuggle  = static function ( $matched, $args ) use ( &$smuggled ) {
			$ability = wp_get_ability( 'wpai-test/smuggled' );

			// Only the selector's own discovery call carries an include callback.
			if ( ! is_array( $matched ) || ! isset( $args['item_include_callback'] ) || ! $ability instanceof WP_Ability ) {
				return $matched;
			}

			if ( ! isset( $matched['wpai-test/smuggled'] ) ) {
				$matched['wpai-test/smuggled'] = $ability;
				$smuggled                      = true;
			}

			return $matched;
		};

		add_filter( 'wp_get_abilities_result', $smuggle, 10, 2 );

		$selector = new Tool_Selector();
		$names    = $selector->get_tool_names( Tool_Selector::SCOPE_SITE );

		remove_filter( 'wp_get_abilities_result', $smuggle, 10 );

		$this->assertTrue(
			$smuggled,
			'The selector\'s discovery query must have rejected the fixture and the result filter must have put it back, otherwise the refusals below are asserted against a smuggle that never happened.'
		);
		$this->assertNotContains(
			'wpai-test/smuggled',
			$names,
			'A plugin hooking the site-wide result filter must not be able to put a mutating ability on the conversational surface.'
		);
		$this->assertNotContains(
			'wpai-test/smuggled',
			$selector->get_effect_class_rejections(),
			'Admission must re-check what discovery returned and refuse the smuggled ability itself, rather than leaving it to the later candidate-map check.'
		);
	}

	/**
	 * Discovery is not a boundary: widening it leaves the surface unmoved.
	 *
	 * @since x.x.x
	 */
	public function test_surface_holds_when_an_include_filter_widens_discovery(): void {
		$this->require_filtered_discovery();
		$this->login_as( 'administrator' );

		$this->register_fixture(
			'wpai-test/include-smuggled',
			array(
				self::DECLARATION_CHANNEL => array( 'public' => true ),
				'annotations'             => array_merge( $this->safe_annotations(), array( 'open_world' => true ) ),
			)
		);

		$widened = false;
		$widen   = static function ( $included, $ability, $args ) use ( &$widened ): bool {
			if (
				! $included
				&& isset( $args['item_include_callback'] )
				&& $ability instanceof WP_Ability
				&& 'wpai-test/include-smuggled' === $ability->get_name()
			) {
				$widened = true;
			}

			return true;
		};

		add_filter( 'wp_get_abilities_item_include', $widen, 10, 3 );

		$selector = new Tool_Selector();
		$names    = $selector->get_tool_names( Tool_Selector::SCOPE_SITE );

		remove_filter( 'wp_get_abilities_item_include', $widen, 10 );

		$this->assertTrue(
			$widened,
			'The include filter must have overridden the selector\'s own rejection of the fixture during the selector\'s discovery call, otherwise the refusals below prove nothing about widening.'
		);
		$this->assertNotContains(
			'wpai-test/include-smuggled',
			$names,
			'An ability that reaches outside the site must not reach the conversational surface, however wide discovery was made.'
		);
		$this->assertNotContains(
			'wpai-test/include-smuggled',
			$selector->get_effect_class_rejections(),
			'Admission must re-check what discovery returned and refuse the widened-in ability itself, rather than leaving it to the later candidate-map check.'
		);
	}

	/**
	 * Declaration-based admission is off until the declaration is public.
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
				'annotations'             => $this->safe_annotations(),
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
	 * Skips a test on a WordPress that cannot filter ability discovery.
	 *
	 * @since x.x.x
	 */
	private function require_filtered_discovery(): void {
		if ( ( new Tool_Policy() )->supports_filtered_discovery() ) {
			return;
		}

		$this->markTestSkipped( 'This WordPress does not support filtered ability discovery.' );
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
	 * Registers the curated abilities that setUp does not, and drops the fixture filter.
	 *
	 * @since x.x.x
	 */
	private function use_curated_surface_only(): void {
		remove_filter( 'wpai_workspace_tool_candidates', array( $this, 'add_editor_only_candidate' ) );

		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			( new Read_Content_Bodies() )->register();
			( new List_Terms() )->register();
			( new Get_Site_Info() )->register();
			( new Find_Admin_Screens() )->register();
			Workspace_Tools::register( new Propose_Drafts() );
			Workspace_Tools::register( new Propose_Template() );
			Workspace_Tools::register( new Propose_Post_Changes() );
			Workspace_Tools::register( new Propose_Site_Identity() );
			Workspace_Tools::register( new Ask_User() );
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
	 * wp-env cannot install WordPress 7.0, so a fixture stands in for its `wp_get_abilities()`.
	 *
	 * @since x.x.x
	 *
	 * @return \WordPress\AI\Experiments\AI_Workspace\Tool_Selector The selector under test.
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

	/**
	 * A kind registered with the proposal store is offered without editing the selector.
	 *
	 * @since x.x.x
	 */
	public function test_a_newly_registered_kind_is_offered(): void {
		$this->login_as( 'administrator' );

		( new Test_Update_Kind() )->add_to_stores();
		Test_Update_Kind::register_ability();

		$selector   = new Tool_Selector();
		$candidates = $selector->get_candidates();

		$this->assertArrayHasKey( Test_Update_Kind::ABILITY, $candidates );
		$this->assertSame( '', $candidates[ Test_Update_Kind::ABILITY ], 'The kind supplies its own capability.' );
		$this->assertContains( Test_Update_Kind::ABILITY, $selector->get_tool_names( Tool_Selector::SCOPE_SITE ), 'Like the built-in kinds, it is exempt from the effect-class check.' );
	}

	/**
	 * The built-in kinds keep their place and capability in the curated floor.
	 *
	 * @since x.x.x
	 */
	public function test_built_in_kinds_keep_their_capabilities(): void {
		$candidates = ( new Tool_Selector() )->get_candidates();

		$this->assertSame( 'edit_posts', $candidates['ai/propose-drafts'] );
		$this->assertSame( 'edit_theme_options', $candidates['ai/propose-template'] );
		$this->assertSame( 'edit_posts', $candidates['ai/propose-post-changes'] );
		$this->assertSame( 'manage_options', $candidates['ai/propose-site-identity'] );
		$this->assertSame(
			array( 'ai/search-content', 'ai/read-content-bodies', 'ai/list-terms', 'ai/get-site-info', 'ai/find-admin-screens', 'ai/propose-drafts', 'ai/propose-template', 'ai/propose-post-changes', 'ai/propose-site-identity', 'ai/ask-user' ),
			array_slice( array_keys( $candidates ), 0, 10 )
		);
	}

	/**
	 * Turning write proposals off removes the update kinds' propose abilities from the tools (R8).
	 *
	 * @since x.x.x
	 */
	public function test_write_gate_off_withholds_update_kinds(): void {
		$this->login_as( 'administrator' );

		( new Test_Update_Kind() )->add_to_stores();
		Test_Update_Kind::register_ability();
		add_filter( 'wpai_workspace_write_proposals_enabled', '__return_false' );

		$selector = new Tool_Selector();

		$this->assertNotContains( Test_Update_Kind::ABILITY, $selector->get_tool_names( Tool_Selector::SCOPE_SITE ) );
		$this->assertArrayHasKey( 'ai/propose-drafts', $selector->get_candidates(), 'Kinds that only create stay on.' );
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
