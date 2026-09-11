<?php
/**
 * Integration tests for the AI Workspace tool admission policy primitives.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace
 */

namespace WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace;

use WP_UnitTestCase;
use WordPress\AI\Abilities\Content\Read_Content_Bodies;
use WordPress\AI\Abilities\Content\Search_Content;
use WordPress\AI\Abilities\Show_In_Abilities;
use WordPress\AI\Experiments\AI_Workspace\Propose_Drafts;
use WordPress\AI\Experiments\AI_Workspace\Tool_Policy;
use WordPress\AI\Experiments\AI_Workspace\Tool_Selector;

/**
 * Tool_Policy test case.
 *
 * Two questions are under test, and both must fail closed.
 *
 * The first is "can core filter abilities here?". On WordPress 7.0
 * `wp_get_abilities()` takes no parameters, and PHP silently discards extra
 * arguments to userland functions, so a probe that only asks whether the
 * function exists reports "supported" on a WordPress that ignores the filter
 * and hands back the entire registry. That is default-allow on the plugin's
 * stated minimum, so the probe has to inspect the signature rather than trust
 * the name.
 *
 * The second is "does this ability declare conversational-surface
 * eligibility?". WordPress 7.1 answers that through one precedence chain: the
 * `ai-workspace` channel's own `public` key, then the general `meta.public`
 * flag, then the channel's default. Nothing is eligible by silence — core seeds
 * `meta.public` to `false` on every ability at registration, so an author who
 * expressed no opinion has already been given one — and the comparison is
 * strict, so `1` and `"true"` are not eligibility either.
 *
 * Inheritance widens who is eligible, which is why the effect-class check
 * carries more weight than it used to: "public" says an ability may be shown to
 * clients, not that it is safe to hand to a model that can be talked into
 * calling it.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Experiments\AI_Workspace\Tool_Policy
 */
class Tool_PolicyTest extends WP_UnitTestCase {

	/**
	 * The provisional declaration meta key.
	 *
	 * Deliberately duplicated here rather than read from the class: the key is
	 * private to {@see Tool_Policy} because issue #354 owns its public name, and
	 * the test asserts behavior at the key the class currently honors.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const DECLARATION_CHANNEL = 'ai-workspace';

	/**
	 * The curated floor, in registration order, by name.
	 *
	 * Written out rather than derived from {@see Tool_Selector} so a change to
	 * the shipped surface has to be made deliberately in both places.
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
	 * Names of the fixture abilities registered by a test.
	 *
	 * @since x.x.x
	 *
	 * @var list<string>
	 */
	private $registered = array();

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	/**
	 * Sets up the test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		/*
		 * Declaration-based admission ships off until issue #354 settles the
		 * declaration's public shape. Everything below tests the policy that
		 * runs once it is on, so the gate is opened here; the tests that pin the
		 * shipped default open no gate and assert against it directly.
		 */
		add_filter( 'wpai_workspace_tool_admission_enabled', '__return_true' );
	}

	public function tearDown(): void {
		remove_filter( 'wpai_workspace_tool_admission_enabled', '__return_true' );

		foreach ( $this->registered as $ability_name ) {
			if ( wp_has_ability( $ability_name ) ) {
				wp_unregister_ability( $ability_name );
			}
		}

		$this->registered = array();

		delete_option( Tool_Policy::OWNER_EXCLUSIONS_OPTION );
		delete_option( Tool_Policy::POLICY_DISABLED_OPTION );

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
	 * The probe reports unsupported when filtered discovery is unavailable.
	 *
	 * This is the WordPress 7.0 shape: the discovery function exists, but its
	 * signature accepts nothing, so any arguments passed to it are discarded and
	 * the caller gets the whole registry back. Reporting "supported" here would
	 * turn default-deny into default-allow.
	 *
	 * @since x.x.x
	 */
	public function test_probe_reports_unsupported_when_discovery_takes_no_arguments(): void {
		$policy = $this->policy_probing( __NAMESPACE__ . '\\wpai_test_zero_parameter_abilities' );

		$this->assertFalse(
			$policy->supports_filtered_discovery(),
			'A discovery function that accepts no arguments must not be reported as supporting filtering.'
		);
	}

	/**
	 * The probe reports unsupported when the discovery function is absent.
	 *
	 * @since x.x.x
	 */
	public function test_probe_reports_unsupported_when_discovery_function_is_missing(): void {
		$policy = $this->policy_probing( __NAMESPACE__ . '\\wpai_test_absent_abilities_function' );

		$this->assertFalse(
			$policy->supports_filtered_discovery(),
			'A discovery function that does not exist must not be reported as supporting filtering.'
		);
	}

	/**
	 * The probe reports supported when the discovery function accepts arguments.
	 *
	 * @since x.x.x
	 */
	public function test_probe_reports_supported_when_discovery_accepts_arguments(): void {
		$policy = $this->policy_probing( __NAMESPACE__ . '\\wpai_test_filtered_abilities' );

		$this->assertTrue(
			$policy->supports_filtered_discovery(),
			'A discovery function that accepts an arguments array must be reported as supporting filtering.'
		);
	}

	/**
	 * The probe answers for the real discovery function on this WordPress.
	 *
	 * WordPress 7.1 added the `$args` parameter. This asserts the probe agrees
	 * with the signature actually installed, rather than with a hard-coded
	 * expectation, so the same assertion is meaningful on every version in the
	 * CI matrix.
	 *
	 * @since x.x.x
	 */
	public function test_probe_matches_the_installed_discovery_signature(): void {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			$this->markTestSkipped( 'WordPress Abilities API not available.' );
		}

		$accepts_args = ( new \ReflectionFunction( 'wp_get_abilities' ) )->getNumberOfParameters() > 0;

		$this->assertSame(
			$accepts_args,
			( new Tool_Policy() )->supports_filtered_discovery(),
			'The probe must agree with the signature of the installed wp_get_abilities().'
		);
	}

	/**
	 * An ability declaring eligibility as strict `true` is recognized.
	 *
	 * @since x.x.x
	 */
	public function test_strict_true_declaration_is_recognized(): void {
		$ability = $this->register_fixture( 'wpai-test/declared', array( self::DECLARATION_CHANNEL => array( 'public' => true ) ) );
		$policy  = new Tool_Policy();

		$this->assertTrue(
			$policy->is_declared( $ability ),
			'An ability whose declaration is boolean true must be recognized as declared.'
		);
	}

	/**
	 * An ability carrying no exposure opinion of its own is not declared.
	 *
	 * There is no "the author declared nothing" state left to test. Core writes
	 * `meta.public` onto every ability at registration and defaults it to
	 * `false`, so an author who said nothing has already been answered for, and
	 * the channel inherits that answer. The assertion on the seeded meta is not
	 * decoration: if core stopped seeding the key, this surface would be
	 * resolving against an absent value rather than an explicit `false`, and
	 * this test would be passing for the wrong reason.
	 *
	 * @since x.x.x
	 */
	public function test_ability_with_no_exposure_opinion_inherits_cores_public_false_default(): void {
		$ability = $this->register_fixture( 'wpai-test/undeclared', array() );

		$this->assertFalse(
			$ability->get_meta_item( 'public' ),
			'Core must seed meta.public as false on an ability whose author set nothing, or the inheritance this policy resolves through is not the one under test.'
		);
		$this->assertFalse(
			( new Tool_Policy() )->is_declared( $ability ),
			'An ability that expresses no exposure opinion of its own must resolve to not declared through core’s public => false default.'
		);
	}

	/**
	 * The channel's explicit opt-out beats a general `public` flag of true.
	 *
	 * The precedence WordPress 7.1 defines, in the direction that has to hold
	 * for an author to be able to say "show this to clients, but not to the
	 * assistant". If the general flag won here, naming the channel would be
	 * unable to narrow anything.
	 *
	 * @since x.x.x
	 */
	public function test_channel_opt_out_beats_a_true_general_public_flag(): void {
		$this->require_filtered_discovery();
		$this->login_as_administrator();

		$ability = $this->register_fixture(
			'wpai-test/opted-out',
			array(
				'public'                  => true,
				self::DECLARATION_CHANNEL => array( 'public' => false ),
				'annotations'             => $this->safe_annotations(),
			)
		);

		$this->assertFalse(
			( new Tool_Policy() )->is_declared( $ability ),
			'An ability that is generally public but sets the ai-workspace channel to false must not be declared: the channel’s own key outranks the general flag.'
		);
		$this->assertNotContains(
			'wpai-test/opted-out',
			( new Tool_Selector() )->get_tool_names( Tool_Selector::SCOPE_SITE ),
			'An ability whose author opted the ai-workspace channel out must not be declared to the model, however public it is elsewhere.'
		);
		$this->assertSame(
			Tool_Policy::REASON_NOT_PUBLIC,
			( new Tool_Policy() )->get_exclusion_reason( $ability ),
			'A channel opt-out must be explained as not public for the assistant, rather than blamed on the effect class or the reader’s capabilities.'
		);
	}

	/**
	 * The channel's explicit opt-in beats a general `public` flag of false.
	 *
	 * The other direction of the same rule: an ability an author keeps out of
	 * general client exposure can still be offered to this surface deliberately.
	 *
	 * @since x.x.x
	 */
	public function test_channel_opt_in_beats_a_false_general_public_flag(): void {
		$this->require_filtered_discovery();
		$this->login_as_administrator();

		$ability = $this->register_fixture(
			'wpai-test/opted-in',
			array(
				'public'                  => false,
				self::DECLARATION_CHANNEL => array( 'public' => true ),
				'annotations'             => $this->safe_annotations(),
			)
		);

		$this->assertTrue(
			( new Tool_Policy() )->is_declared( $ability ),
			'An ability that is not generally public but sets the ai-workspace channel to true must be declared: the channel’s own key outranks the general flag.'
		);
		$this->assertContains(
			'wpai-test/opted-in',
			( new Tool_Selector() )->get_tool_names( Tool_Selector::SCOPE_SITE ),
			'An author who opted the ai-workspace channel in must reach the surface without also having to make the ability generally public.'
		);
	}

	/**
	 * A generally public ability is admitted without ever naming the channel.
	 *
	 * This is the inheritance working, and the reason the discovery query can no
	 * longer carry a meta condition on the channel key: this ability would never
	 * have matched one.
	 *
	 * @since x.x.x
	 */
	public function test_general_public_flag_admits_an_ability_that_never_names_the_channel(): void {
		$this->require_filtered_discovery();
		$this->login_as_administrator();

		$ability = $this->register_fixture(
			'wpai-test/inherits-public',
			array(
				'public'      => true,
				'annotations' => $this->safe_annotations(),
			)
		);

		$this->assertArrayNotHasKey(
			self::DECLARATION_CHANNEL,
			$ability->get_meta(),
			'The fixture must not name the ai-workspace channel, or it proves nothing about inheritance.'
		);
		$this->assertTrue(
			( new Tool_Policy() )->is_declared( $ability ),
			'An ability marked meta.public true must inherit eligibility for this channel without naming it.'
		);
		$this->assertContains(
			'wpai-test/inherits-public',
			( new Tool_Selector() )->get_tool_names( Tool_Selector::SCOPE_SITE ),
			'An ability that is generally public and whose annotations are safe must reach the surface through inheritance alone.'
		);
	}

	/**
	 * A public ability with an unsafe effect class is still not admitted.
	 *
	 * The check inheritance makes load bearing. `meta.public` is a statement
	 * about client exposure written for surfaces that only read what an author
	 * chose to publish; it says nothing about whether an ability is safe to hand
	 * to a model that site content can talk into calling it. If the effect class
	 * stopped being enforced here, inheriting from the general flag would mean
	 * "every public ability is callable by the assistant".
	 *
	 * @since x.x.x
	 *
	 * @dataProvider data_unsafe_effect_classes
	 *
	 * @param array<string, mixed> $annotations  The annotations to register.
	 * @param string               $ability_name The fixture ability name.
	 * @param string               $message      The assertion message.
	 */
	public function test_public_ability_with_an_unsafe_effect_class_is_not_admitted( array $annotations, string $ability_name, string $message ): void {
		$this->require_filtered_discovery();
		$this->login_as_administrator();

		$ability = $this->register_fixture(
			$ability_name,
			array(
				'public'      => true,
				'annotations' => $annotations,
			)
		);

		$this->assertTrue(
			( new Tool_Policy() )->is_declared( $ability ),
			'The fixture must be declared, or the effect class is not what is being tested.'
		);
		$this->assertNotContains(
			$ability_name,
			( new Tool_Selector() )->get_tool_names( Tool_Selector::SCOPE_SITE ),
			$message
		);
		$this->assertSame(
			Tool_Policy::REASON_EFFECT_CLASS,
			( new Tool_Policy() )->get_exclusion_reason( $ability ),
			'A public ability held back by its effect class must be told so, rather than being sent to re-check an exposure flag that is already correct.'
		);
	}

	/**
	 * Data provider for public abilities with an inadmissible effect class.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{array<string, mixed>, string, string}> Provider data.
	 */
	public function data_unsafe_effect_classes(): array {
		$safe = $this->safe_annotations();

		return array(
			'not readonly'       => array(
				array_merge( $safe, array( 'readonly' => false ) ),
				'wpai-test/public-writes',
				'A public ability that writes must not be admitted; being public says nothing about being safe to call.',
			),
			'destructive'        => array(
				array_merge( $safe, array( 'destructive' => true ) ),
				'wpai-test/public-destructive',
				'A public destructive ability must not be admitted; being public says nothing about being safe to call.',
			),
			'open world true'    => array(
				array_merge( $safe, array( 'open_world' => true ) ),
				'wpai-test/public-open-world',
				'A public ability that may reach outside the site must not be admitted: it is an exfiltration path for injected instructions, whatever it declares about reading.',
			),
			'open world absent'  => array(
				array(
					'readonly'    => true,
					'destructive' => false,
				),
				'wpai-test/public-no-open-world',
				'A public ability that does not assert open_world false must not be admitted; core defaults the annotation to null and absence has to fail closed.',
			),
			'no annotations set' => array(
				array(),
				'wpai-test/public-unannotated',
				'A public ability carrying no annotations at all must not be admitted, or every public ability on the site would be callable by the model.',
			),
		);
	}

	/**
	 * Loosely truthy channel declarations do not match.
	 *
	 * Core validates the general `meta.public` flag as a boolean at
	 * registration, so it cannot arrive loose. The channel's own key gets no
	 * such validation, which makes this the one place a typo could be read as an
	 * opt-in, and strictness is what stops it.
	 *
	 * @since x.x.x
	 *
	 * @dataProvider data_loose_declarations
	 *
	 * @param mixed  $value      The declaration value to store.
	 * @param string $ability_id The fixture ability name to register.
	 */
	public function test_loose_declarations_do_not_match( $value, string $ability_id ): void {
		$ability = $this->register_fixture( $ability_id, array( self::DECLARATION_CHANNEL => array( 'public' => $value ) ) );
		$policy  = new Tool_Policy();

		$this->assertFalse(
			$policy->is_declared( $ability ),
			'A declaration that is not strictly boolean true must not be admitted by policy.'
		);
	}

	/**
	 * Data provider for loosely truthy declaration values.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{mixed, string}> Provider data.
	 */
	public function data_loose_declarations(): array {
		return array(
			'integer one'   => array( 1, 'wpai-test/loose-int' ),
			'string true'   => array( 'true', 'wpai-test/loose-string' ),
			'string one'    => array( '1', 'wpai-test/loose-string-one' ),
			'string yes'    => array( 'yes', 'wpai-test/loose-yes' ),
			'array wrapper' => array( array( 'enabled' => true ), 'wpai-test/loose-array' ),
		);
	}

	/**
	 * The effect-class predicate admits only read-only, additive, closed-world abilities.
	 *
	 * @since x.x.x
	 *
	 * @dataProvider data_effect_class_annotations
	 *
	 * @param array<string, mixed> $annotations The annotations to register.
	 * @param bool                 $expected    Whether the predicate should admit.
	 * @param string               $ability_id  The fixture ability name to register.
	 * @param string               $message     The assertion message.
	 */
	public function test_effect_class_predicate( array $annotations, bool $expected, string $ability_id, string $message ): void {
		$ability = $this->register_fixture( $ability_id, array( 'annotations' => $annotations ) );

		$this->assertSame(
			$expected,
			( new Tool_Policy() )->has_admissible_effect_class( $ability ),
			$message
		);
	}

	/**
	 * Data provider for the effect-class predicate.
	 *
	 * Each of the three annotations is dropped independently, because core
	 * defaults every annotation to `null` and absence has to fail closed on all
	 * three rather than on the first one somebody remembered.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{array<string, mixed>, bool, string, string}> Provider data.
	 */
	public function data_effect_class_annotations(): array {
		$safe = array(
			'readonly'    => true,
			'destructive' => false,
			'open_world'  => false,
		);

		$without = static function ( string $key ) use ( $safe ): array {
			unset( $safe[ $key ] );

			return $safe;
		};

		return array(
			'all three asserted safely' => array( $safe, true, 'wpai-test/effect-safe', 'An ability asserting readonly true, destructive false and open_world false must be admitted.' ),
			'readonly absent'           => array( $without( 'readonly' ), false, 'wpai-test/effect-no-readonly', 'An ability that does not assert readonly must be rejected.' ),
			'destructive absent'        => array( $without( 'destructive' ), false, 'wpai-test/effect-no-destructive', 'An ability that does not assert destructive false must be rejected.' ),
			'open_world absent'         => array( $without( 'open_world' ), false, 'wpai-test/effect-no-open-world', 'An ability that does not assert open_world false must be rejected.' ),
			'readonly false'            => array( array_merge( $safe, array( 'readonly' => false ) ), false, 'wpai-test/effect-writes', 'A write-capable ability must be rejected.' ),
			'destructive true'          => array( array_merge( $safe, array( 'destructive' => true ) ), false, 'wpai-test/effect-destructive', 'A destructive ability must be rejected.' ),
			'open_world true'           => array( array_merge( $safe, array( 'open_world' => true ) ), false, 'wpai-test/effect-open-world', 'An ability that may reach external systems must be rejected.' ),
			'readonly loosely true'     => array( array_merge( $safe, array( 'readonly' => 1 ) ), false, 'wpai-test/effect-loose', 'A loosely truthy readonly annotation must be rejected.' ),
			'no annotations at all'     => array( array(), false, 'wpai-test/effect-none', 'An ability with no annotations must be rejected.' ),
		);
	}

	/**
	 * Discovery carries no meta condition, because meta cannot express the fallback.
	 *
	 * This is the test that stops the query being "optimised" back into a meta
	 * match on the channel key. Core matches `meta` exactly on the keys it is
	 * given; it has no way to say "this key, or that one if this is absent". An
	 * ability that is eligible only through the general `meta.public` flag —
	 * which, since inheritance landed, is the ordinary case — carries no
	 * `ai-workspace` key at all, so a query on that key would drop it silently,
	 * before anything in this plugin ever saw it. Narrowing therefore has to
	 * happen per item, and this returns nothing to match on.
	 *
	 * @since x.x.x
	 */
	public function test_discovery_args_carry_no_meta_condition_because_meta_cannot_express_the_public_fallback(): void {
		$this->assertSame(
			array(),
			( new Tool_Policy() )->get_discovery_args(),
			'Discovery must add no meta condition. Core matches meta exactly, so querying the ai-workspace channel key alone would silently skip every ability that inherits eligibility from the general meta.public flag; resolution belongs per item, not in the query.'
		);
	}


	/**
	 * A declared, admissible ability on the surface has no exclusion reason.
	 *
	 * @since x.x.x
	 */
	public function test_admitted_ability_reports_no_exclusion_reason(): void {
		$this->require_filtered_discovery();
		$this->login_as_administrator();

		$ability = $this->register_fixture(
			'wpai-test/reason-admitted',
			array(
				self::DECLARATION_CHANNEL => array( 'public' => true ),
				'annotations'         => $this->safe_annotations(),
			)
		);

		$this->assertNull(
			( new Tool_Policy() )->get_exclusion_reason( $ability ),
			'An ability the assistant actually holds must not be reported as excluded.'
		);
	}

	/**
	 * "Not public" and "wrong effect class" are told apart; nothing else is.
	 *
	 * One reason code on the exposure side now, not two. An ability that carries
	 * no opinion of its own and an ability whose channel value is a loosely
	 * truthy `1` are both simply not public here, and core's own default is why:
	 * it writes `meta.public` onto every ability at registration, so there is no
	 * "the author forgot" state left to distinguish. What still has to be kept
	 * apart is the ability whose exposure flag is perfect and whose annotations
	 * are not — telling that author to re-check the flag would send them back to
	 * something they already got right.
	 *
	 * @since x.x.x
	 */
	public function test_exclusion_reasons_separate_not_public_from_the_effect_class(): void {
		$this->require_filtered_discovery();
		$this->login_as_administrator();

		$undeclared = $this->register_fixture(
			'wpai-test/reason-undeclared',
			array( 'annotations' => $this->safe_annotations() )
		);
		$loose      = $this->register_fixture(
			'wpai-test/reason-loosely-true',
			array(
				self::DECLARATION_CHANNEL => array( 'public' => 1 ),
				'annotations'         => $this->safe_annotations(),
			)
		);
		$writes     = $this->register_fixture(
			'wpai-test/reason-effect-class',
			array(
				self::DECLARATION_CHANNEL => array( 'public' => true ),
				'annotations'         => array_merge( $this->safe_annotations(), array( 'readonly' => false ) ),
			)
		);

		$policy = new Tool_Policy();

		$this->assertSame(
			Tool_Policy::REASON_NOT_PUBLIC,
			$policy->get_exclusion_reason( $undeclared ),
			'An ability with no exposure opinion of its own must be reported as not public, which is what core’s default already made it.'
		);
		$this->assertSame(
			Tool_Policy::REASON_NOT_PUBLIC,
			$policy->get_exclusion_reason( $loose ),
			'A channel value that is present but not strictly true lands on the same reason as one that was never written: it is not public either way, and there is no separate malformed state to report.'
		);
		$this->assertSame(
			Tool_Policy::REASON_EFFECT_CLASS,
			$policy->get_exclusion_reason( $writes ),
			'A correctly declared ability rejected for its effect class must be told so, not sent to re-check a correct declaration.'
		);
	}

	/**
	 * A candidate the current user cannot clear reports the capability reason.
	 *
	 * @since x.x.x
	 */
	public function test_capability_exclusion_is_reported_for_a_candidate_the_user_cannot_use(): void {
		$this->login_as( 'subscriber' );

		$ability = $this->register_fixture(
			'wpai-test/reason-capability',
			array( 'annotations' => $this->safe_annotations() )
		);

		add_filter( 'wpai_workspace_tool_candidates', array( $this, 'add_manage_options_candidate' ) );

		$this->assertSame(
			Tool_Policy::REASON_CAPABILITY,
			( new Tool_Policy() )->get_exclusion_reason( $ability ),
			'An ability the workspace would offer but this user cannot clear must be reported as a capability exclusion, not as undeclared.'
		);
	}

	/**
	 * Adds the capability fixture to the workspace candidate map.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, string> $candidates The candidate map.
	 * @return array<string, string> The filtered candidate map.
	 */
	public function add_manage_options_candidate( array $candidates ): array {
		$candidates['wpai-test/reason-capability'] = 'manage_options';

		return $candidates;
	}

	/**
	 * Removing an ability as owner drops it from the next turn's surface.
	 *
	 * @since x.x.x
	 */
	public function test_owner_exclusion_removes_the_ability_from_the_next_turn(): void {
		$this->require_filtered_discovery();
		$this->login_as_administrator();

		$ability = $this->register_fixture(
			'wpai-test/reason-narrowed',
			array(
				self::DECLARATION_CHANNEL => array( 'public' => true ),
				'annotations'         => $this->safe_annotations(),
			)
		);

		$this->assertContains(
			'wpai-test/reason-narrowed',
			( new Tool_Selector() )->get_tool_names( Tool_Selector::SCOPE_SITE ),
			'The fixture must reach the surface before its removal can prove anything.'
		);

		$this->assertTrue(
			( new Tool_Policy() )->exclude_from_surface( 'wpai-test/reason-narrowed' ),
			'Removing an ability from the surface must persist the owner’s decision.'
		);

		$this->assertNotContains(
			'wpai-test/reason-narrowed',
			( new Tool_Selector() )->get_tool_names( Tool_Selector::SCOPE_SITE ),
			'An ability the owner removed must not be declared to the model on the next turn.'
		);
		$this->assertSame(
			Tool_Policy::REASON_OWNER_EXCLUDED,
			( new Tool_Policy() )->get_exclusion_reason( $ability ),
			'An ability the owner removed must say so, rather than blaming the author’s declaration.'
		);
	}

	/**
	 * Returning a removed ability puts it back on the surface.
	 *
	 * @since x.x.x
	 */
	public function test_restoring_an_ability_returns_it_to_the_surface(): void {
		$this->require_filtered_discovery();
		$this->login_as_administrator();

		$this->register_fixture(
			'wpai-test/reason-restored',
			array(
				self::DECLARATION_CHANNEL => array( 'public' => true ),
				'annotations'         => $this->safe_annotations(),
			)
		);

		$policy = new Tool_Policy();
		$policy->exclude_from_surface( 'wpai-test/reason-restored' );

		$this->assertTrue(
			$policy->restore_to_surface( 'wpai-test/reason-restored' ),
			'Returning an ability the owner had removed must persist.'
		);
		$this->assertContains(
			'wpai-test/reason-restored',
			( new Tool_Selector() )->get_tool_names( Tool_Selector::SCOPE_SITE ),
			'An ability the owner returned must be declared to the model again.'
		);
	}

	/**
	 * A stored exclusion for an ability that is gone is not reported.
	 *
	 * The stored value is deliberately left alone, so deactivating and
	 * reactivating the plugin that owns the ability restores the owner's
	 * decision rather than silently re-admitting it.
	 *
	 * @since x.x.x
	 */
	public function test_owner_exclusions_are_validated_against_the_registry_on_read(): void {
		update_option(
			Tool_Policy::OWNER_EXCLUSIONS_OPTION,
			array( 'wpai-test/never-registered', 42, '' ),
			false
		);

		$this->assertSame(
			array(),
			( new Tool_Policy() )->get_owner_excluded_names(),
			'A stored name that no longer resolves to a registered ability must not be reported to the owner.'
		);
		$this->assertTrue(
			( new Tool_Policy() )->is_owner_excluded( 'wpai-test/never-registered' ),
			'The stored decision must survive the ability going away, so reactivating its plugin does not silently re-admit it.'
		);
	}

	/**
	 * With the kill switch on, the surface is the curated abilities by name.
	 *
	 * @since x.x.x
	 */
	public function test_kill_switch_returns_the_curated_surface_by_name(): void {
		$this->login_as_administrator();
		$this->register_curated_surface();

		$this->register_fixture(
			'wpai-test/killed-declared',
			array(
				self::DECLARATION_CHANNEL => array( 'public' => true ),
				'annotations'         => $this->safe_annotations(),
			)
		);

		update_option( Tool_Policy::POLICY_DISABLED_OPTION, true );

		$this->assertSame(
			self::CURATED_SURFACE,
			( new Tool_Selector() )->get_tool_names( Tool_Selector::SCOPE_SITE ),
			'With the kill switch on, the surface must be the three curated abilities and nothing else.'
		);
	}

	/**
	 * On the fail-closed branch every non-curated ability blames the policy.
	 *
	 * The declaration-specific reasons would all be lies here: with the policy
	 * off, an ability that declared perfectly and one that declared nothing are
	 * excluded for exactly the same cause.
	 *
	 * @since x.x.x
	 */
	public function test_policy_off_reports_policy_off_for_every_non_curated_ability(): void {
		$this->login_as_administrator();
		$this->register_curated_surface();

		$declared = $this->register_fixture(
			'wpai-test/off-declared',
			array(
				self::DECLARATION_CHANNEL => array( 'public' => true ),
				'annotations'         => $this->safe_annotations(),
			)
		);
		$silent   = $this->register_fixture(
			'wpai-test/off-undeclared',
			array( 'annotations' => $this->safe_annotations() )
		);
		$writes   = $this->register_fixture(
			'wpai-test/off-writes',
			array(
				self::DECLARATION_CHANNEL => array( 'public' => true ),
				'annotations'         => array_merge( $this->safe_annotations(), array( 'readonly' => false ) ),
			)
		);

		update_option( Tool_Policy::POLICY_DISABLED_OPTION, true );

		$policy = new Tool_Policy();

		foreach ( array( $declared, $silent, $writes ) as $ability ) {
			$this->assertSame(
				Tool_Policy::REASON_POLICY_OFF,
				$policy->get_exclusion_reason( $ability ),
				sprintf(
					'With the policy off, %s must blame the policy rather than its own declaration.',
					$ability->get_name()
				)
			);
		}

		$reasons = $policy->get_exclusion_reasons();

		foreach ( self::CURATED_SURFACE as $ability_name ) {
			$this->assertArrayHasKey(
				$ability_name,
				$reasons,
				sprintf( 'The report must cover the curated ability %s.', $ability_name )
			);
			$this->assertNull(
				$reasons[ $ability_name ],
				sprintf( 'The curated ability %s is a floor, not a policy admission, so the kill switch must not exclude it.', $ability_name )
			);
		}
	}

	/**
	 * The report covers abilities admission never admitted.
	 *
	 * This is the whole reason reporting is a separate, argument-free
	 * enumeration of the registry. Admission hands back only what it admitted,
	 * so it can never explain a non-match — and an ability that was not admitted
	 * is exactly the one the owner needs explained. That holds whether the
	 * narrowing happens in the discovery query or, as it does now, per item
	 * after it.
	 *
	 * @since x.x.x
	 */
	public function test_report_enumerates_abilities_admission_never_admitted(): void {
		$this->require_filtered_discovery();
		$this->login_as_administrator();

		$this->register_fixture(
			'wpai-test/report-undeclared',
			array( 'annotations' => $this->safe_annotations() )
		);

		$reasons = ( new Tool_Policy() )->get_exclusion_reasons();

		$this->assertArrayHasKey(
			'wpai-test/report-undeclared',
			$reasons,
			'An ability admission refused must still appear in the report, or the owner cannot be told why it is missing.'
		);
		$this->assertSame(
			Tool_Policy::REASON_NOT_PUBLIC,
			$reasons['wpai-test/report-undeclared'],
			'The enumerated report must carry the same reason the single-ability accessor gives.'
		);
	}

	/**
	 * An ability site code removed says so, rather than blaming the reader.
	 *
	 * "Withheld from you because of your capabilities" would send an owner to
	 * check roles for a removal that roles had no part in.
	 *
	 * @since x.x.x
	 */
	public function test_filter_removal_is_reported_as_a_removal_by_site_code(): void {
		$this->require_filtered_discovery();
		$this->login_as_administrator();

		$ability = $this->register_fixture(
			'wpai-test/reason-filtered',
			array(
				self::DECLARATION_CHANNEL => array( 'public' => true ),
				'annotations'         => $this->safe_annotations(),
			)
		);

		$this->assertNull(
			( new Tool_Policy() )->get_exclusion_reason( $ability ),
			'The fixture must be on the surface before a filter removing it can prove anything.'
		);

		add_filter( 'wpai_workspace_tool_candidates', array( $this, 'remove_filtered_candidate' ) );

		$this->assertSame(
			Tool_Policy::REASON_FILTERED,
			( new Tool_Policy() )->get_exclusion_reason( $ability ),
			'An ability the candidates filter removed must be reported as removed by site code.'
		);

		remove_filter( 'wpai_workspace_tool_candidates', array( $this, 'remove_filtered_candidate' ) );
	}

	/**
	 * Removes the filtered-reason fixture from the workspace candidate map.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, string> $candidates The candidate map.
	 * @return array<string, string> The filtered candidate map.
	 */
	public function remove_filtered_candidate( array $candidates ): array {
		unset( $candidates['wpai-test/reason-filtered'] );

		return $candidates;
	}

	/**
	 * A filter-added ability dropped for its effect class is told so.
	 *
	 * It carries no declaration, so the declaration-side walk would call it
	 * undeclared — true, and not the reason it is missing. Site code did opt it
	 * in; the effect class is what refused it.
	 *
	 * @since x.x.x
	 */
	public function test_filter_added_ability_dropped_on_the_effect_class_reports_the_effect_class(): void {
		$this->login_as_administrator();

		$ability = $this->register_fixture(
			'wpai-test/reason-filter-writer',
			array(
				'annotations' => array_merge( $this->safe_annotations(), array( 'readonly' => false ) ),
			)
		);

		add_filter( 'wpai_workspace_tool_candidates', array( $this, 'add_filter_writer_candidate' ) );

		$this->assertSame(
			Tool_Policy::REASON_EFFECT_CLASS,
			( new Tool_Policy() )->get_exclusion_reason( $ability ),
			'An ability site code added and the effect-class check dropped must blame the effect class, not a declaration nobody claimed to have written.'
		);

		remove_filter( 'wpai_workspace_tool_candidates', array( $this, 'add_filter_writer_candidate' ) );
	}

	/**
	 * Adds the mutating fixture to the workspace candidate map.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, string> $candidates The candidate map.
	 * @return array<string, string> The filtered candidate map.
	 */
	public function add_filter_writer_candidate( array $candidates ): array {
		$candidates['wpai-test/reason-filter-writer'] = '';

		return $candidates;
	}

	/**
	 * An admissible ability held only by the release gate is not a rejection.
	 *
	 * @since x.x.x
	 */
	public function test_gated_admission_reports_an_ability_as_awaiting_the_gate(): void {
		$this->require_filtered_discovery();
		$this->login_as_administrator();

		$ability = $this->register_fixture(
			'wpai-test/reason-awaiting',
			array(
				self::DECLARATION_CHANNEL => array( 'public' => true ),
				'annotations'         => $this->safe_annotations(),
			)
		);

		$this->assertNull(
			( new Tool_Policy() )->get_exclusion_reason( $ability ),
			'With the gate open the fixture must be on the surface, otherwise the gated assertion below proves nothing.'
		);

		remove_filter( 'wpai_workspace_tool_admission_enabled', '__return_true' );

		$this->assertSame(
			Tool_Policy::REASON_AWAITING_ENABLE,
			( new Tool_Policy() )->get_exclusion_reason( $ability ),
			'An ability that declared correctly and is only held back by the release gate must be reported as eligible, not as rejected.'
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
	 * Logs in as a user holding the given role.
	 *
	 * @since x.x.x
	 *
	 * @param string $role The role to create the user with.
	 * @return int The user ID.
	 */
	private function login_as( string $role ): int {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * Logs in as an administrator.
	 *
	 * @since x.x.x
	 *
	 * @return int The user ID.
	 */
	private function login_as_administrator(): int {
		return $this->login_as( 'administrator' );
	}

	/**
	 * Registers the three curated abilities that make up the floor.
	 *
	 * @since x.x.x
	 */
	private function register_curated_surface(): void {
		global $wp_current_filter;
		( new Show_In_Abilities() )->register();

		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			( new Search_Content() )->register();
			( new Read_Content_Bodies() )->register();
			( new Propose_Drafts() )->register();
		} finally {
			array_pop( $wp_current_filter );
		}

		foreach ( self::CURATED_SURFACE as $ability_name ) {
			$this->registered[] = $ability_name;

			$this->assertTrue(
				wp_has_ability( $ability_name ),
				sprintf( 'The curated ability %s must be registered before the floor can be asserted.', $ability_name )
			);
		}
	}

	/**
	 * Builds a policy whose probe targets a named fixture function.
	 *
	 * `.wp-env.json` pins core to the latest release, so WordPress 7.0's
	 * zero-parameter `wp_get_abilities()` cannot be installed locally. The probe
	 * therefore reads the name of the function it inspects from an overridable
	 * seam, and the fixture functions at the foot of this file stand in for the
	 * two signatures that matter.
	 *
	 * @since x.x.x
	 *
	 * @param string $function_name The discovery function the probe should inspect.
	 * @return Tool_Policy The policy under test.
	 */
	private function policy_probing( string $function_name ): Tool_Policy {
		return new class( $function_name ) extends Tool_Policy {

			/**
			 * The discovery function name to inspect.
			 *
			 * @var string
			 */
			private $function_name;

			/**
			 * Constructor.
			 *
			 * @param string $function_name The discovery function name to inspect.
			 */
			public function __construct( string $function_name ) {
				$this->function_name = $function_name;
			}

			/**
			 * Returns the fixture discovery function name.
			 *
			 * @return string The discovery function name.
			 */
			protected function get_discovery_function_name(): string {
				return $this->function_name;
			}
		};
	}

	/**
	 * A withheld ability is refused however impeccably it annotates itself.
	 *
	 * The effect class asks whether an ability writes or reaches outside the
	 * site. It cannot ask whether handing it to a model is a bad idea. Since the
	 * surface inherits from `meta.public`, and `core/get-user-info` ships public,
	 * read-only and not destructive, the only thing keeping a reader of people's
	 * personal data off the surface was an absent `open_world` hint -- an
	 * annotation it would be correct for core to add. This is the lock that does
	 * not depend on core never adding it.
	 *
	 * @since x.x.x
	 */
	public function test_withheld_ability_is_refused_despite_perfect_annotations(): void {
		$this->require_filtered_discovery();

		$ability = $this->register_fixture(
			'wpai-test/withheld',
			array(
				'ai-workspace' => array( 'public' => true ),
				'annotations'  => $this->safe_annotations(),
			)
		);

		add_filter(
			'wpai_workspace_withheld_abilities',
			static function () {
				return array( 'wpai-test/withheld' );
			}
		);

		$policy = new Tool_Policy();

		$this->assertTrue(
			$policy->is_declared( $ability ),
			'The fixture must be declared, so the refusal is provably the withheld list and not the declaration.'
		);
		$this->assertTrue(
			$policy->has_admissible_effect_class( $ability ),
			'The fixture must pass the effect class, so the refusal is provably the withheld list and not the annotations.'
		);
		$this->assertNotContains(
			'wpai-test/withheld',
			( new Tool_Selector() )->get_tool_names( Tool_Selector::SCOPE_SITE ),
			'An ability on the withheld list must never reach the model, whatever it declares about itself.'
		);
		$this->assertSame(
			Tool_Policy::REASON_WITHHELD,
			$policy->get_exclusion_reason( $ability ),
			'A withheld ability must say so, rather than blaming a declaration or an annotation that is in fact correct.'
		);
	}

	/**
	 * The shipped list names the core abilities that read sensitive data.
	 *
	 * @since x.x.x
	 */
	public function test_shipped_withheld_list_covers_the_sensitive_core_abilities(): void {
		$policy = new Tool_Policy();

		foreach ( array( 'core/get-user-info', 'core/users-query', 'core/read-users', 'core/read-settings', 'core/get-environment-info' ) as $ability_name ) {
			$this->assertTrue(
				$policy->is_withheld( $ability_name ),
				sprintf( '%s reads sensitive data and must be withheld by default. A rename upstream leaves a hole here until the new name is added, so both sides of core/read-users to core/users-query are listed.', $ability_name )
			);
		}
	}

	/**
	 * Registers a fixture ability carrying the given meta.
	 *
	 * @since x.x.x
	 *
	 * @param string               $ability_name The ability name.
	 * @param array<string, mixed> $meta         The ability meta.
	 * @return \WP_Ability The registered ability.
	 */
	private function register_fixture( string $ability_name, array $meta ): \WP_Ability {
		$this->ensure_ability_category( 'content' );

		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			wp_register_ability(
				$ability_name,
				array(
					'label'               => 'Policy fixture',
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

		$ability = wp_get_ability( $ability_name );

		$this->assertInstanceOf(
			\WP_Ability::class,
			$ability,
			'The fixture ability must register successfully before the policy can be asked about it.'
		);

		return $ability;
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
}

if ( ! function_exists( 'wpai_test_zero_parameter_abilities' ) ) {
	/**
	 * Stands in for WordPress 7.0's `wp_get_abilities()`, which accepts nothing.
	 *
	 * PHP discards extra arguments to userland functions, so calling this with a
	 * filter returns the whole registry regardless.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, \WP_Ability> Every registered ability.
	 */
	function wpai_test_zero_parameter_abilities(): array {
		return function_exists( 'wp_get_abilities' ) ? wp_get_abilities() : array();
	}
}

if ( ! function_exists( 'wpai_test_filtered_abilities' ) ) {
	/**
	 * Stands in for WordPress 7.1's `wp_get_abilities( $args )`.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $args Optional. Discovery arguments. Default empty array.
	 * @return array<string, \WP_Ability> The matching abilities.
	 */
	function wpai_test_filtered_abilities( array $args = array() ): array {
		unset( $args );

		return array();
	}
}
