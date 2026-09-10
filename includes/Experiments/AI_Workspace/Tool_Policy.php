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
 * It resolves the way WordPress 7.1 says every channel resolves: this surface's
 * own key, then the general `meta.public` flag, then closed. So an ability that
 * is public is eligible here without naming this surface, and a channel value
 * overrides that in either direction.
 *
 * There is no "the author said nothing" answer left. Core writes `meta.public`
 * onto every ability at registration and validates it as a boolean, so an
 * ability with no opinion of its own is eligible-false by core's default rather
 * than undeclared. The comparison stays strict because the channel key gets no
 * such validation, and a value stored as `1` or `"true"` should not read as an
 * opt-in.
 *
 * The effect-class predicate admits an ability only when it strictly asserts
 * that it reads, does not destroy, and does not reach outside the site. Core defaults every annotation to `null`, so each of the
 * three must be present and explicit.
 *
 * This class answers questions, apart from one thing it owns outright: the site
 * owner's narrowing. The abilities an owner removed, and the site-wide
 * kill switch, are persisted here, and {@see Tool_Selector::get_candidates()}
 * reads them directly on every candidate build. Nothing has to be hooked for an
 * owner's removal to take effect, so it holds on a site where the workspace
 * experiment never booted, the ordinary state of a site running the Abilities
 * Explorer without a function-calling connector. Explaining a
 * non-match is a separate enumeration of the whole registry: the admission
 * query returns only what already matched, so it can never say why something
 * did not.
 *
 * Two switches sit side by side here and are not the same thing.
 * {@see self::admission_is_enabled()} is a temporary release gate, off by
 * default until issue #354 settles the declaration's public shape.
 * {@see self::is_policy_disabled()} is the site owner's own runtime control,
 * on by default and thrown from the Abilities Explorer.
 *
 * None of it is authorization. The declaration and the annotations are both the
 * ability author's self-attestation, and execute-time permission checks run
 * unchanged inside `WP_Ability::execute()`.
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
	private const DECLARATION_CHANNEL = 'ai-workspace';

	/**
	 * The general exposure flag every channel falls back to.
	 *
	 * WordPress 7.1 added `meta.public` and the precedence a channel resolves
	 * by: its own key, then this flag, then the channel's default. `mcp.public`
	 * reads the same way.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const PUBLIC_META_KEY = 'public';

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
	 * Option that switches the admission policy off entirely.
	 *
	 * The site owner's runtime control, thrown from the Abilities Explorer, and
	 * separate from {@see self::admission_is_enabled()}: this one is on by
	 * default and exists for as long as the feature does, while that one is a
	 * temporary release gate. Truthy means "policy off": the surface falls back
	 * to the curated floor, on the same branch as a WordPress that cannot filter
	 * ability discovery. The `wpai_` prefix is what
	 * `Admin\Uninstall::delete_options()` cleans by, so nothing has to be added
	 * there.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const POLICY_DISABLED_OPTION = 'wpai_workspace_tool_policy_disabled';

	/**
	 * Exclusion reason: the ability does not resolve to public for this channel.
	 *
	 * Covers every way that can happen: the channel opted out, the general
	 * `meta.public` flag is false, or the author expressed no opinion and core's
	 * registration default answered for them. One code, because from here they
	 * are the same answer.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const REASON_NOT_PUBLIC = 'not_public';

	/**
	 * Exclusion reason: the ability's annotations fail the effect-class check.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const REASON_EFFECT_CLASS = 'effect_class';

	/**
	 * Exclusion reason: the ability is held back whatever it says about itself.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const REASON_WITHHELD = 'withheld';

	/**
	 * Abilities never admitted, whatever their exposure and annotations say.
	 *
	 * The effect class asks whether an ability writes or reaches outside the
	 * site. It cannot ask whether handing it to a model is a bad idea, and for
	 * these the answer is yes however they are annotated: they read people's
	 * personal data, the site's configuration, or its environment, and the
	 * assistant's surface is reachable by instructions embedded in content
	 * someone else wrote.
	 *
	 * These are registered by WordPress, not by this plugin, so this list is the
	 * only place the decision can live. `core/get-user-info` is already
	 * `public => true`, `readonly => true` and not destructive; the one thing
	 * keeping it off the surface is an absent `open_world` hint, and
	 * `open_world => false` would be a correct thing for core to add.
	 *
	 * @since x.x.x
	 *
	 * @var list<string>
	 */
	private const NEVER_ADMITTED = array( // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is a single array constant.
		'core/get-user-info',
		'core/read-users',
		'core/read-settings',
		'core/get-environment-info',
	);

	/**
	 * Exclusion reason: the current user does not clear the coarse capability.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const REASON_CAPABILITY = 'capability';

	/**
	 * Exclusion reason: site code removed the ability from the candidate map.
	 *
	 * Reported for a name the `wpai_workspace_tool_candidates` filter took out.
	 * Nothing about the ability's own declaration explains its absence, and
	 * blaming the reader's capabilities would send them to check roles for
	 * something roles had no part in.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const REASON_FILTERED = 'filtered';

	/**
	 * Exclusion reason: the ability is admissible, but admission is not enabled yet.
	 *
	 * Not a rejection. The ability declared itself, its annotations pass, and the
	 * only thing between it and the surface is the temporary release gate
	 * {@see self::admission_is_enabled()} describes.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const REASON_AWAITING_ENABLE = 'awaiting_enable';

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
	 * The owner's runtime control, not the temporary #354 release gate
	 * {@see self::admission_is_enabled()} describes.
	 *
	 * @since x.x.x
	 *
	 * @return bool True when the kill switch is on.
	 */
	public function is_policy_disabled(): bool {
		return (bool) get_option( self::POLICY_DISABLED_OPTION, false );
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
		if ( false === get_option( self::POLICY_DISABLED_OPTION, false ) ) {
			return add_option( self::POLICY_DISABLED_OPTION, $disabled, '', false );
		}

		return update_option( self::POLICY_DISABLED_OPTION, $disabled, false );
	}

	/**
	 * Reports whether declaration-based admission is switched on at all.
	 *
	 * A temporary release gate, and the one thing that keeps this feature dark
	 * on merge. The declaration key is `private const` on this class, but the
	 * plugin is open source, so an ability author needs nothing more than the
	 * literal string to opt in: "provisional and private" is a statement about
	 * support, not a barrier. Until issue #354 settles the declaration's public
	 * name and shape, admission therefore refuses by default, and the curated
	 * floor is the whole surface.
	 *
	 * Distinct from {@see self::is_policy_disabled()}, which is the site owner's
	 * runtime control and stays after this gate is gone. This one is removed
	 * with #354; that one is not.
	 *
	 * @since x.x.x
	 *
	 * @return bool True when the policy may admit declared abilities.
	 */
	public function admission_is_enabled(): bool {
		$enabled = defined( 'WPAI_WORKSPACE_TOOL_ADMISSION' )
			? (bool) constant( 'WPAI_WORKSPACE_TOOL_ADMISSION' )
			: false;

		/**
		 * Filters whether the AI Workspace may admit abilities by declaration.
		 *
		 * Temporary. It exists so a site can try declaration-based admission
		 * before issue #354 settles the declaration's public shape, and is
		 * removed when that lands.
		 *
		 * @since x.x.x
		 *
		 * @param bool $enabled Whether declared abilities may be admitted.
		 */
		return (bool) apply_filters( 'wpai_workspace_tool_admission_enabled', $enabled );
	}

	/**
	 * Reports whether the admission policy runs at all on this request.
	 *
	 * The single owner of this gate: {@see Tool_Selector} asks this rather than
	 * keeping a copy, so the path that admits and the path that explains a
	 * non-match cannot answer "policy off" differently.
	 *
	 * Covers the owner's kill switch and the WordPress version, not the
	 * temporary release gate: an ability held back only by
	 * {@see self::admission_is_enabled()} is eligible rather than rejected, and
	 * is reported as {@see self::REASON_AWAITING_ENABLE}.
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
		 * Resolved once for the whole registry. All of it is derived from a full
		 * discovery pass, so asking the selector per ability would rebuild the
		 * surface once for every row it explains.
		 */
		$surface = $this->snapshot_surface( $selector );

		$reasons = array();

		foreach ( wp_get_abilities() as $ability ) {
			if ( ! $ability instanceof WP_Ability ) {
				continue;
			}

			$reasons[ $ability->get_name() ] = $this->resolve_exclusion_reason( $ability, $surface );
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

		return $this->resolve_exclusion_reason( $ability, $this->snapshot_surface( $selector ) );
	}

	/**
	 * Captures everything a reason needs from one pass over the surface.
	 *
	 * Ordering is load bearing. `get_candidates()` records what it dropped and
	 * what site code removed as a side effect of building the map, so those two
	 * lists are read from the selector only after that call has run.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Experiments\AI_Workspace\Tool_Selector $selector The selector to read from.
	 * @return array{tools: list<string>, candidates: array<string, string>, filtered: list<string>, effect_class: list<string>} The surface snapshot.
	 */
	private function snapshot_surface( Tool_Selector $selector ): array {
		$tool_names = $selector->get_tool_names( Tool_Selector::SCOPE_SITE );
		$candidates = $selector->get_candidates();

		return array(
			'tools'        => $tool_names,
			'candidates'   => $candidates,
			'filtered'     => $selector->get_filtered_out_names(),
			'effect_class' => $selector->get_effect_class_rejections(),
		);
	}

	/**
	 * Resolves one ability's exclusion reason against an already-built surface.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Ability                                                                                                    $ability The ability to explain.
	 * @param array{tools: list<string>, candidates: array<string, string>, filtered: list<string>, effect_class: list<string>} $surface The surface snapshot to resolve against.
	 * @return string|null A REASON_* code, or null when the ability is on the surface.
	 */
	private function resolve_exclusion_reason( WP_Ability $ability, array $surface ): ?string {
		$ability_name = $ability->get_name();

		if ( in_array( $ability_name, $surface['tools'], true ) ) {
			return null;
		}

		if ( array_key_exists( $ability_name, $surface['candidates'] ) ) {
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

		/*
		 * The two causes that have nothing to do with this ability's author, and
		 * so must be answered before any declaration-side reason: site code took
		 * the name out of the candidate map, or a name site code put in was
		 * dropped for its effect class. Reporting either as a capability
		 * exclusion sends the reader to check roles that had no part in it.
		 */
		if ( in_array( $ability_name, $surface['filtered'], true ) ) {
			return self::REASON_FILTERED;
		}

		if ( in_array( $ability_name, $surface['effect_class'], true ) ) {
			return self::REASON_EFFECT_CLASS;
		}

		if ( ! $this->is_active() ) {
			return self::REASON_POLICY_OFF;
		}

		/*
		 * One reason, not two. Since the surface inherits from `meta.public`, and
		 * core writes that key onto every ability at registration, an ability
		 * without its own opinion is not undeclared -- it is declared false by
		 * core's default. There is no "the author forgot" state left to report,
		 * and reporting one would send an author to add a key that is already
		 * there with the value they meant.
		 */
		if ( $this->is_withheld( $ability->get_name() ) ) {
			return self::REASON_WITHHELD;
		}

		if ( ! $this->is_declared( $ability ) ) {
			return self::REASON_NOT_PUBLIC;
		}

		if ( ! $this->has_admissible_effect_class( $ability ) ) {
			return self::REASON_EFFECT_CLASS;
		}

		/*
		 * Declared, annotated and admissible: the only thing holding it back is
		 * the temporary release gate, which is an eligibility state rather than
		 * a rejection.
		 */
		if ( ! $this->admission_is_enabled() ) {
			return self::REASON_AWAITING_ENABLE;
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
		/*
		 * No `meta` condition, deliberately. A channel inherits from
		 * `meta.public` when its own key is absent, and core's meta matching is
		 * an exact match on the keys it is given -- it cannot express that
		 * fallback. Querying the channel key alone would skip every ability that
		 * relies on the general flag, so resolution happens per item instead and
		 * this returns nothing to match on.
		 */
		return array();
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

		/*
		 * The precedence WordPress 7.1 defines for every channel: the channel's
		 * own key, then the general `meta.public` flag, then the channel's
		 * default. Inheriting means an ability marked public is eligible here
		 * without naming this surface, which is why the effect-class check still
		 * has to pass -- being public says nothing about whether an ability is
		 * safe for something a model can be talked into calling.
		 */
		return $meta[ self::DECLARATION_CHANNEL ]['public'] ?? $meta[ self::PUBLIC_META_KEY ] ?? null;
	}

	/**
	 * Checks whether an ability is held back whatever it says about itself.
	 *
	 * The effect class asks whether an ability writes or reaches outside the
	 * site. It cannot ask whether handing it to a model is a bad idea, and for a
	 * handful of core abilities the answer is yes however they are annotated.
	 *
	 * @since x.x.x
	 *
	 * @param string $ability_name The ability name to check.
	 * @return bool True when the ability is never admitted.
	 */
	public function is_withheld( string $ability_name ): bool {
		/**
		 * Filters the abilities the AI Workspace never admits.
		 *
		 * A site that wants one of these on the assistant's surface can remove it
		 * here, which is a deliberate act by someone with code access rather than
		 * a default deciding on their behalf.
		 *
		 * @since x.x.x
		 *
		 * @param list<string> $withheld Ability names never admitted.
		 */
		$withheld = apply_filters( 'wpai_workspace_withheld_abilities', self::NEVER_ADMITTED );

		return is_array( $withheld ) && in_array( $ability_name, $withheld, true );
	}

	/**
	 * Checks whether an ability's annotations place it in an admissible effect class.
	 *
	 * @since x.x.x
	 *
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
