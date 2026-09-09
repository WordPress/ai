<?php
/**
 * Admission primitives for the AI Workspace tool surface.
 *
 * @package WordPress\AI\Experiments\AI_Workspace
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\AI_Workspace;

use ReflectionException;
use ReflectionFunction;
use WP_Ability;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Answers the two questions the workspace's admission policy rests on.
 *
 * The first is "can core filter abilities here?". WordPress 7.1 added an `$args`
 * parameter to `wp_get_abilities()`; 7.0's version takes none. Because PHP
 * silently discards extra arguments to userland functions, a filtered call on
 * 7.0 returns the entire registry instead of failing, which would turn the
 * workspace's default-deny admission into default-allow on the plugin's stated
 * minimum. The probe therefore inspects the signature and never infers support
 * from a call's return value.
 *
 * The second is "does this ability declare conversational-surface eligibility?".
 * Absence is not eligibility, an explicit `false` is an opt-out that stays
 * distinguishable from absence, and the comparison is strict — matching core's
 * strict meta comparison — so a value stored as `1` or `"true"` fails closed.
 *
 * Alongside those sits the effect-class predicate, which admits an ability only
 * when it strictly asserts that it reads, does not destroy, and does not reach
 * outside the site. Core defaults every annotation to `null`, so each of the
 * three must be present and explicit.
 *
 * This class answers questions only, with one exception it owns outright: the
 * site owner's narrowing. The abilities an owner removed, and the site-wide
 * kill switch, are persisted here and the removals are applied through the
 * workspace's own candidate filter, so an owner's decision reaches the next
 * turn rather than only the screen that displays it. Explaining a non-match is
 * a separate enumeration of the whole registry — the admission query returns
 * only what already matched, so it can never say why something did not.
 *
 * It still enforces no authorization: execute-time permission checks are
 * unchanged and still run inside `WP_Ability::execute()`.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since x.x.x
 */
class Tool_Policy {

	/**
	 * The ability meta key carrying the conversational-surface declaration.
	 *
	 * Provisional and deliberately private. Issue #354 owns the public name and
	 * shape of this declaration; until it lands, nothing outside this class may
	 * depend on the key, and it is not advertised as a public constant. When
	 * #354 settles, this constant — and, if the declaration lands as a top-level
	 * registration argument rather than under `meta`, {@see self::get_declaration()}
	 * and {@see self::get_discovery_args()} with it — is what changes.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const DECLARATION_META_KEY = 'wpai_conversational_surface';

	/**
	 * Option holding the ability names the site owner removed from the surface.
	 *
	 * Stored as a list of ability names, non-autoloaded. The `wpai_` prefix is
	 * what `Admin\Uninstall::delete_options()` cleans by, so nothing has to be
	 * added there.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const OWNER_EXCLUSIONS_OPTION = 'wpai_workspace_tool_exclusions';

	/**
	 * Exclusion reason: the ability carries no declaration at all.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const REASON_NOT_DECLARED = 'not_declared';

	/**
	 * Exclusion reason: a declaration is present but is not strictly `true`.
	 *
	 * Reported separately from an absent declaration on purpose. An author who
	 * stored `1` or `"true"` has opted in and made a typo; telling them "not
	 * declared" sends them to re-check something they already did.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const REASON_DECLARATION_MALFORMED = 'declaration_malformed';

	/**
	 * Exclusion reason: the ability's annotations fail the effect-class check.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const REASON_EFFECT_CLASS = 'effect_class';

	/**
	 * Exclusion reason: the current user does not clear the coarse capability.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const REASON_CAPABILITY = 'capability';

	/**
	 * Exclusion reason: the site owner removed this ability from the surface.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const REASON_OWNER_EXCLUDED = 'owner_excluded';

	/**
	 * Exclusion reason: the admission policy is not running at all.
	 *
	 * Either this WordPress cannot filter ability discovery, or the site owner
	 * switched the policy off. Both land on the same fail-closed branch, and an
	 * ability excluded by it is excluded whatever it declares — so reporting a
	 * declaration-specific reason here would be a lie.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const REASON_POLICY_OFF = 'policy_off';

	/**
	 * Constructor.
	 *
	 * Registers the owner's narrowing against the workspace's own candidate
	 * filter. That filter is the one place `Tool_Selector` lets anything remove
	 * a candidate — including one from the curated floor — so hooking it is what
	 * makes an owner's removal take effect on the next turn rather than only in
	 * the screen that displays it.
	 *
	 * Registration happens here rather than at a bootstrap because
	 * `Tool_Selector` constructs a policy before it applies the filter, so any
	 * selector that can ask the policy a question has already installed the
	 * narrowing. `add_filter()` de-duplicates a static array callable by name,
	 * so constructing many policies registers it once.
	 *
	 * @since x.x.x
	 */
	public static function register_owner_exclusions(): void {
		/*
		 * Late, so the owner's removal is the last word among filters: a site
		 * hooking at the default priority to add candidates cannot re-add an
		 * ability the owner took off the surface.
		 *
		 * Registered from the experiment bootstrap rather than a constructor.
		 * Hooking on construction would make the owner's removal depend on
		 * something happening to build a policy first, so a candidate read that
		 * did not would quietly serve a tool the owner took away -- the one
		 * direction this control must never fail in.
		 */
		add_filter( 'wpai_workspace_tool_candidates', array( self::class, 'filter_owner_exclusions' ), 100 );
	}

	/**
	 * Removes the owner's excluded abilities from the workspace candidate map.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $candidates Map of ability name to required capability.
	 * @return mixed The candidate map with owner-excluded abilities removed.
	 */
	public static function filter_owner_exclusions( $candidates ) {
		if ( ! is_array( $candidates ) ) {
			return $candidates;
		}

		foreach ( self::read_owner_exclusions() as $ability_name ) {
			unset( $candidates[ $ability_name ] );
		}

		return $candidates;
	}

	/**
	 * Returns the ability names the site owner removed from the surface.
	 *
	 * Validated against the registry on read: a stored name that no longer
	 * resolves to an ability is not reported, so a deactivated plugin does not
	 * leave phantom rows on the screen. The stored value is left alone, so
	 * reactivating that plugin restores the owner's decision rather than
	 * silently re-admitting the ability.
	 *
	 * @since x.x.x
	 *
	 * @return list<string> The excluded ability names that are currently registered.
	 */
	public function get_owner_excluded_names(): array {
		$names = array();

		foreach ( self::read_owner_exclusions() as $ability_name ) {
			if ( ! wp_has_ability( $ability_name ) ) {
				continue;
			}

			$names[] = $ability_name;
		}

		return $names;
	}

	/**
	 * Reports whether the site owner removed an ability from the surface.
	 *
	 * @since x.x.x
	 *
	 * @param string $ability_name The ability name.
	 * @return bool True when the owner removed it.
	 */
	public function is_owner_excluded( string $ability_name ): bool {
		return in_array( $ability_name, self::read_owner_exclusions(), true );
	}

	/**
	 * Removes an ability from the workspace's tool surface.
	 *
	 * @since x.x.x
	 *
	 * @param string $ability_name The ability name.
	 * @return bool True when the stored list changed.
	 */
	public function exclude_from_surface( string $ability_name ): bool {
		$excluded = self::read_owner_exclusions();

		if ( '' === $ability_name || in_array( $ability_name, $excluded, true ) ) {
			return false;
		}

		$excluded[] = $ability_name;

		return self::write_owner_exclusions( $excluded );
	}

	/**
	 * Returns an ability the owner had removed to the workspace's tool surface.
	 *
	 * @since x.x.x
	 *
	 * @param string $ability_name The ability name.
	 * @return bool True when the stored list changed.
	 */
	public function restore_to_surface( string $ability_name ): bool {
		$excluded = self::read_owner_exclusions();

		if ( ! in_array( $ability_name, $excluded, true ) ) {
			return false;
		}

		return self::write_owner_exclusions(
			array_values(
				array_filter(
					$excluded,
					static function ( string $stored ) use ( $ability_name ): bool {
						return $stored !== $ability_name;
					}
				)
			)
		);
	}

	/**
	 * Reads the raw stored exclusion list.
	 *
	 * @since x.x.x
	 *
	 * @return list<string> The stored ability names.
	 */
	private static function read_owner_exclusions(): array {
		$stored = get_option( self::OWNER_EXCLUSIONS_OPTION, array() );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$names = array();

		foreach ( $stored as $ability_name ) {
			if ( ! is_string( $ability_name ) || '' === $ability_name ) {
				continue;
			}

			$names[] = $ability_name;
		}

		return $names;
	}

	/**
	 * Persists the exclusion list without autoloading it.
	 *
	 * @since x.x.x
	 *
	 * @param list<string> $names The ability names to store.
	 * @return bool True when the option was written.
	 */
	private static function write_owner_exclusions( array $names ): bool {
		if ( false === get_option( self::OWNER_EXCLUSIONS_OPTION, false ) ) {
			return add_option( self::OWNER_EXCLUSIONS_OPTION, $names, '', false );
		}

		return update_option( self::OWNER_EXCLUSIONS_OPTION, $names, false );
	}

	/**
	 * Reports whether the site owner switched the admission policy off.
	 *
	 * @since x.x.x
	 *
	 * @return bool True when the kill switch is on.
	 */
	public function is_policy_disabled(): bool {
		return (bool) get_option( Tool_Selector::POLICY_DISABLED_OPTION, false );
	}

	/**
	 * Switches the admission policy on or off for the whole site.
	 *
	 * @since x.x.x
	 *
	 * @param bool $disabled Whether the policy should be off.
	 * @return bool True when the option was written.
	 */
	public function set_policy_disabled( bool $disabled ): bool {
		if ( false === get_option( Tool_Selector::POLICY_DISABLED_OPTION, false ) ) {
			return add_option( Tool_Selector::POLICY_DISABLED_OPTION, $disabled, '', false );
		}

		return update_option( Tool_Selector::POLICY_DISABLED_OPTION, $disabled, false );
	}

	/**
	 * Reports whether the admission policy runs at all on this request.
	 *
	 * Mirrors the gate `Tool_Selector` applies before it queries for declared
	 * abilities. It is restated here rather than shared because the selector's
	 * copy is private and the reason-reporting path must not be able to answer
	 * "policy off" differently from the path that admits.
	 *
	 * @since x.x.x
	 *
	 * @return bool True when abilities may be admitted by policy.
	 */
	public function is_active(): bool {
		if ( $this->is_policy_disabled() ) {
			return false;
		}

		return $this->supports_filtered_discovery();
	}

	/**
	 * Explains, for every registered ability, why it is not on the surface.
	 *
	 * Enumerates the registry argument-free on purpose. The admission query
	 * returns only what already matched, so it can never explain a non-match;
	 * reporting has to start from everything that exists.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, string|null> Map of ability name to a REASON_* code, or null when the ability is on the surface.
	 */
	public function get_exclusion_reasons(): array {
		$selector = new Tool_Selector( $this );

		/*
		 * Resolved once for the whole registry. Both are derived from a full
		 * discovery pass, so asking the selector per ability would rebuild the
		 * surface once for every row it explains.
		 */
		$tool_names = $selector->get_tool_names( Tool_Selector::SCOPE_SITE );
		$candidates = $selector->get_candidates();

		$reasons = array();

		foreach ( wp_get_abilities() as $ability ) {
			if ( ! $ability instanceof WP_Ability ) {
				continue;
			}

			$reasons[ $ability->get_name() ] = $this->resolve_exclusion_reason( $ability, $tool_names, $candidates );
		}

		return $reasons;
	}

	/**
	 * Explains why one ability is not on the workspace's tool surface.
	 *
	 * The order of the checks is the answer's quality. The surface itself is
	 * consulted first, so nothing that is actually declared to the model is ever
	 * reported as excluded; then the candidate map, which is what separates "the
	 * policy would admit this, but you cannot use it" from every other cause;
	 * then the owner's own decision, which outranks the remaining reasons
	 * because it is the one the reader can act on directly.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Ability                                              $ability  The ability to explain.
	 * @param \WordPress\AI\Experiments\AI_Workspace\Tool_Selector|null $selector Optional. The selector to read the surface from. Default a new instance.
	 * @return string|null A REASON_* code, or null when the ability is on the surface.
	 */
	public function get_exclusion_reason( WP_Ability $ability, ?Tool_Selector $selector = null ): ?string {
		$selector = null === $selector ? new Tool_Selector( $this ) : $selector;

		return $this->resolve_exclusion_reason(
			$ability,
			$selector->get_tool_names( Tool_Selector::SCOPE_SITE ),
			$selector->get_candidates()
		);
	}

	/**
	 * Resolves one ability's exclusion reason against an already-built surface.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Ability           $ability    The ability to explain.
	 * @param list<string>          $tool_names The ability names actually declared to the model.
	 * @param array<string, string> $candidates The merged candidate map.
	 * @return string|null A REASON_* code, or null when the ability is on the surface.
	 */
	private function resolve_exclusion_reason( WP_Ability $ability, array $tool_names, array $candidates ): ?string {
		$ability_name = $ability->get_name();

		if ( in_array( $ability_name, $tool_names, true ) ) {
			return null;
		}

		if ( array_key_exists( $ability_name, $candidates ) ) {
			/*
			 * It survived admission and the effect-class check but was not
			 * declared to the model, and the coarse capability gate is the only
			 * thing left between the two.
			 */
			return self::REASON_CAPABILITY;
		}

		if ( $this->is_owner_excluded( $ability_name ) ) {
			return self::REASON_OWNER_EXCLUDED;
		}

		if ( ! $this->is_active() ) {
			return self::REASON_POLICY_OFF;
		}

		if ( ! $this->has_declaration( $ability ) ) {
			return self::REASON_NOT_DECLARED;
		}

		if ( ! $this->is_declared( $ability ) ) {
			return self::REASON_DECLARATION_MALFORMED;
		}

		if ( ! $this->has_admissible_effect_class( $ability ) ) {
			return self::REASON_EFFECT_CLASS;
		}

		return self::REASON_CAPABILITY;
	}

	/**
	 * Returns the name of the ability discovery function the probe inspects.
	 *
	 * Exists as a seam. `.wp-env.json` pins core to the latest release, so a
	 * developer cannot install WordPress 7.0's zero-parameter
	 * `wp_get_abilities()` locally; tests override this to point the probe at a
	 * function with that signature. CI runs the real thing on 7.0.
	 *
	 * @since x.x.x
	 *
	 * @return string The discovery function name.
	 */
	protected function get_discovery_function_name(): string {
		return 'wp_get_abilities';
	}

	/**
	 * Checks whether this WordPress supports filtered ability discovery.
	 *
	 * Reads the function's declared signature rather than calling it. Calling it
	 * cannot answer the question: on WordPress 7.0 the arguments are discarded
	 * and the full registry comes back, which is indistinguishable from a filter
	 * that matched everything.
	 *
	 * @since x.x.x
	 *
	 * @return bool True when ability discovery accepts filtering arguments.
	 */
	public function supports_filtered_discovery(): bool {
		$function_name = $this->get_discovery_function_name();

		if ( ! function_exists( $function_name ) ) {
			return false;
		}

		try {
			$reflection = new ReflectionFunction( $function_name );
		} catch ( ReflectionException $exception ) {
			return false;
		}

		return $reflection->getNumberOfParameters() > 0;
	}

	/**
	 * Returns the discovery arguments that select declared abilities.
	 *
	 * Keeps the declaration key inside this class: callers ask for the query
	 * rather than building one from a key they were told. Core matches meta
	 * strictly, so this selects only a declaration stored as boolean `true`.
	 *
	 * The returned set is a candidate set, not an authority. Core's
	 * `wp_get_abilities_item_include` filter fires on every call, including this
	 * one, so anything discovered with these arguments must still be re-checked
	 * with {@see self::is_declared()} and {@see self::has_admissible_effect_class()}
	 * before it is admitted.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> Arguments for `wp_get_abilities()`.
	 */
	public function get_discovery_args(): array {
		return array(
			'meta' => array( self::DECLARATION_META_KEY => true ),
		);
	}

	/**
	 * Checks whether an ability carries a conversational-surface declaration.
	 *
	 * Answers presence, not eligibility. An explicit `false` opt-out and a
	 * malformed value both count as present, which is what lets a caller tell
	 * "never declared" apart from "declared, but not eligible" — the same
	 * explicit-opt-out handling `Show_In_Abilities` applies to the
	 * `show_in_abilities` flag.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Ability $ability The ability to inspect.
	 * @return bool True when the declaration key is present at all.
	 */
	public function has_declaration( WP_Ability $ability ): bool {
		return array_key_exists( self::DECLARATION_META_KEY, $ability->get_meta() );
	}

	/**
	 * Checks whether an ability declares itself eligible for the conversational surface.
	 *
	 * Strict on purpose. Core's meta matching is strict, so a declaration stored
	 * as `1` or `"true"` would never be returned by the discovery query either;
	 * accepting it here would admit an ability the query had already skipped.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Ability $ability The ability to inspect.
	 * @return bool True only when the declaration is boolean true.
	 */
	public function is_declared( WP_Ability $ability ): bool {
		return true === $this->get_declaration( $ability );
	}

	/**
	 * Returns the raw declaration value, or null when it is absent.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Ability $ability The ability to inspect.
	 * @return mixed The declaration value, or null when absent.
	 */
	private function get_declaration( WP_Ability $ability ) {
		$meta = $ability->get_meta();

		return $meta[ self::DECLARATION_META_KEY ] ?? null;
	}

	/**
	 * Checks whether an ability's annotations place it in an admissible effect class.
	 *
	 * Admits only an ability that strictly asserts all three of: it is read-only,
	 * it is not destructive, and it does not reach outside the site.
	 *
	 * All three matter. `open_world` is the one easiest to miss: an ability that
	 * genuinely only reads, but calls a remote API to do it, is an exfiltration
	 * path for exactly the injected instructions this surface has to assume are
	 * present in site content. Core defaults every annotation to `null`, so
	 * absence fails closed on each of the three independently.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Ability $ability The ability to inspect.
	 * @return bool True when the ability asserts it reads, does not destroy, and stays local.
	 */
	public function has_admissible_effect_class( WP_Ability $ability ): bool {
		$annotations = $ability->get_meta_item( 'annotations' );

		if ( ! is_array( $annotations ) ) {
			return false;
		}

		return true === ( $annotations['readonly'] ?? null )
			&& false === ( $annotations['destructive'] ?? null )
			&& false === ( $annotations['open_world'] ?? null );
	}
}
