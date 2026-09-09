<?php
/**
 * Integration tests for the AI Workspace tool admission policy primitives.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace
 */

namespace WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace;

use WP_UnitTestCase;
use WordPress\AI\Experiments\AI_Workspace\Tool_Policy;

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
 * eligibility?". Absence is not eligibility, an explicit `false` is an opt-out
 * distinguishable from absence, and the comparison is strict so that `1` and
 * `"true"` are not eligibility either.
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
	private const DECLARATION_KEY = 'wpai_conversational_surface';

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
	public function tearDown(): void {
		foreach ( $this->registered as $ability_name ) {
			if ( wp_has_ability( $ability_name ) ) {
				wp_unregister_ability( $ability_name );
			}
		}

		$this->registered = array();

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
		$ability = $this->register_fixture( 'wpai-test/declared', array( self::DECLARATION_KEY => true ) );
		$policy  = new Tool_Policy();

		$this->assertTrue(
			$policy->is_declared( $ability ),
			'An ability whose declaration is boolean true must be recognized as declared.'
		);
		$this->assertTrue(
			$policy->has_declaration( $ability ),
			'An ability whose declaration is boolean true must be reported as carrying a declaration.'
		);
	}

	/**
	 * An ability with no declaration is not declared.
	 *
	 * @since x.x.x
	 */
	public function test_absent_declaration_is_not_declared(): void {
		$ability = $this->register_fixture( 'wpai-test/undeclared', array() );
		$policy  = new Tool_Policy();

		$this->assertFalse(
			$policy->is_declared( $ability ),
			'An ability with no declaration must not be admitted by policy.'
		);
		$this->assertFalse(
			$policy->has_declaration( $ability ),
			'An ability with no declaration must not be reported as carrying one.'
		);
	}

	/**
	 * An explicit `false` is an opt-out, and is distinguishable from absence.
	 *
	 * @since x.x.x
	 */
	public function test_explicit_false_declaration_is_an_opt_out_distinct_from_absence(): void {
		$opted_out  = $this->register_fixture( 'wpai-test/opted-out', array( self::DECLARATION_KEY => false ) );
		$undeclared = $this->register_fixture( 'wpai-test/silent', array() );
		$policy     = new Tool_Policy();

		$this->assertFalse(
			$policy->is_declared( $opted_out ),
			'An ability declaring false must not be admitted by policy.'
		);
		$this->assertTrue(
			$policy->has_declaration( $opted_out ),
			'An explicit false opt-out must be reported as carrying a declaration.'
		);
		$this->assertNotSame(
			$policy->has_declaration( $undeclared ),
			$policy->has_declaration( $opted_out ),
			'An explicit false opt-out must be distinguishable from an absent declaration.'
		);
	}

	/**
	 * Loosely truthy declarations do not match.
	 *
	 * Core's meta matching is strict, so a declaration stored as `1` or `"true"`
	 * would never match the discovery query either. The accessor must agree
	 * rather than admit something the query would have skipped.
	 *
	 * @since x.x.x
	 *
	 * @dataProvider data_loose_declarations
	 *
	 * @param mixed  $value      The declaration value to store.
	 * @param string $ability_id The fixture ability name to register.
	 */
	public function test_loose_declarations_do_not_match( $value, string $ability_id ): void {
		$ability = $this->register_fixture( $ability_id, array( self::DECLARATION_KEY => $value ) );
		$policy  = new Tool_Policy();

		$this->assertFalse(
			$policy->is_declared( $ability ),
			'A declaration that is not strictly boolean true must not be admitted by policy.'
		);
		$this->assertTrue(
			$policy->has_declaration( $ability ),
			'A malformed declaration must still be reported as present, so it can be told apart from absence.'
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
	 * The discovery arguments query the declaration without exposing the key.
	 *
	 * @since x.x.x
	 */
	public function test_discovery_args_query_the_declaration_meta_key(): void {
		$args = ( new Tool_Policy() )->get_discovery_args();

		$this->assertSame(
			array( 'meta' => array( self::DECLARATION_KEY => true ) ),
			$args,
			'Discovery must ask core for abilities whose declaration meta is strictly true.'
		);
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
