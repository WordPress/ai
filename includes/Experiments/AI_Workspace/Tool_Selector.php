<?php
/**
 * Chooses which abilities are declared to the model for a workspace turn.
 *
 * @package WordPress\AI\Experiments\AI_Workspace
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\AI_Workspace;

use WP_Ability;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Builds the per-request tool allowlist for the AI Workspace.
 *
 * Selection is deliberately coarse. Each candidate ability is paired with an
 * input-free capability — "could this user ever invoke this ability" — and only
 * candidates that clear it are declared to the model (R21, KTD5).
 *
 * The filter is not `WP_Ability::check_permissions()` with a null input, and must
 * not become it. This plugin's content permission callbacks are input dependent:
 * without a post ID, slug, or exposed post type they return false, so a null-input
 * filter would deny every tool to every user, administrators included. Object-level
 * authorization is left where it belongs, inside `WP_Ability::execute()` at call
 * time, which is also what keeps the workspace and the MCP surface on one
 * permission path.
 *
 * On a WordPress that can filter ability discovery, abilities that declare
 * themselves fit for a conversational surface are admitted alongside the curated
 * ones. Admission is default-deny and asks for two things: the declaration, and
 * annotations strictly asserting the ability reads, does not destroy, and does
 * not reach outside the site.
 *
 * Both are the ability author's own self-attestation: keys in one `meta` array
 * written by one hand, where an author who sets the declaration can set the
 * annotations on the next line. The effect class is declared intent, not a
 * property this code can enforce, because nothing here inspects what an
 * ability's callback does. The boundaries that hold are the site owner's
 * controls in the Abilities Explorer and the execute-time `permission_callback`
 * inside `WP_Ability::execute()`, which remains the authority on every call.
 *
 * The discovery query is not trusted. `wp_get_abilities_result` is a site-wide
 * filter that fires on this call too and can inject an ability the query never
 * matched, so every returned ability is re-verified here.
 *
 * Admission decides only what the model is told exists. It widens nothing a user
 * may do: execute-time permission checks, provenance wrapping,
 * propose-then-confirm and per-invocation logging are untouched.
 *
 * @since x.x.x
 */
final class Tool_Selector {

	/**
	 * Scope in which the assistant may call tools.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const SCOPE_SITE = 'site';

	/**
	 * Scope in which no tools are declared at all.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const SCOPE_GENERAL = 'general';

	/**
	 * Reason code returned when no ability is registered as a candidate.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const REASON_NO_CANDIDATES = 'no_tools_registered';

	/**
	 * Reason code returned when site code removed every candidate.
	 *
	 * Told apart from {@see self::REASON_NO_CANDIDATES} because the two send the
	 * reader to different places: "no tools registered" is a statement about the
	 * site's abilities, and would be false on a site whose owner emptied the
	 * surface themselves.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const REASON_SURFACE_EMPTIED = 'surface_emptied';

	/**
	 * Reason code returned when every candidate failed the capability check.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const REASON_NOT_PERMITTED = 'insufficient_capabilities';

	/**
	 * Reason code returned when the scope itself declares no tools.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const REASON_GENERAL_SCOPE = 'general_knowledge_scope';

	/**
	 * The ability that searches site content.
	 *
	 * Named here because this class already owns the candidate list, so consumers
	 * that have to recognize a particular tool do not restate its name.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const SEARCH_ABILITY = 'ai/search-content';

	/**
	 * The ability that reads full post bodies by ID.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const READ_ABILITY = 'ai/read-content-bodies';

	/**
	 * Answers the admission questions the candidate list is built from.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Experiments\AI_Workspace\Tool_Policy
	 */
	private $policy;

	/**
	 * Candidate names the `wpai_workspace_tool_candidates` filter removed.
	 *
	 * Recorded by {@see self::get_candidates()} and describing that call only.
	 * It exists so {@see Tool_Policy} can say "site code removed this" rather
	 * than falling through to a reason about the reader's capabilities.
	 *
	 * @since x.x.x
	 *
	 * @var list<string>
	 */
	private $filtered_out_names = array();

	/**
	 * Candidate names the effect-class check dropped from the merged map.
	 *
	 * Recorded by {@see self::get_candidates()} and describing that call only.
	 * Only a filter-added name can land here: a policy-admitted one already
	 * passed the same check, and the curated floor is exempt from it.
	 *
	 * @since x.x.x
	 *
	 * @var list<string>
	 */
	private $effect_class_rejections = array();

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Experiments\AI_Workspace\Tool_Policy|null $policy Optional. The admission policy. Default a new instance.
	 */
	public function __construct( ?Tool_Policy $policy = null ) {
		$this->policy = null === $policy ? new Tool_Policy() : $policy;
	}

	/**
	 * Candidate abilities, mapped to the coarse capability each one requires.
	 *
	 * @since x.x.x
	 *
	 * @var array<string, string>
	 */
	private const DEFAULT_CANDIDATES = array( // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is a single array constant.
		self::SEARCH_ABILITY => '',
		/*
		 * Reading is filtered row by row at execute time against the requesting
		 * user's own capabilities, so the coarse gate has nothing left to add: a
		 * capability tighter than "authenticated" would withhold the tool from a
		 * user who could legitimately read something with it, while the rows they
		 * may not read are withheld either way.
		 */
		self::READ_ABILITY   => '',
		/*
		 * Proposing drafts writes nothing, but a user who cannot edit any post
		 * has nothing to gain from being offered it, and its own permission
		 * callback refuses them anyway.
		 */
		'ai/propose-drafts'  => 'edit_posts',
	);

	/**
	 * Returns the candidate abilities and their coarse capability requirements.
	 *
	 * Built in one fixed order, and the order matters:
	 *
	 * 1. The curated floor, which is present on every WordPress version.
	 * 2. Abilities the policy admits, which can only add to that floor.
	 * 3. The `wpai_workspace_tool_candidates` filter, the site owner's escape
	 *    hatch, which may add an ability carrying no declaration or remove one
	 *    the policy admitted.
	 * 4. The site owner's own removals, from {@see Tool_Policy}, applied after
	 *    the filter so they are the last word: a site hooking that filter to add
	 *    candidates cannot re-add an ability the owner took off the surface.
	 *    They are read here rather than hooked from a bootstrap, so an owner's
	 *    removal holds even where the workspace experiment never registered —
	 *    the ordinary state of a site running the Abilities Explorer without a
	 *    function-calling connector.
	 * 5. The effect-class check, applied last to the merged map so the filter
	 *    bypasses the declaration without also bypassing the effect class. The
	 *    curated floor is exempt: `ai/propose-drafts` deliberately registers
	 *    `readonly => false` and writes only through the confirm gate.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, string> Map of ability name to required capability.
	 */
	public function get_candidates(): array {
		/**
		 * Filters the abilities the AI Workspace may declare to the model.
		 *
		 * @since x.x.x
		 *
		 * @param array<string, string> $candidates Map of ability name to the coarse
		 *                                          capability required to declare it.
		 *                                          An empty capability means any
		 *                                          authenticated user.
		 */
		/*
		 * The curated abilities are a floor, not a fallback. Policy only ever adds
		 * to them, on every WordPress version: default-deny with nothing yet
		 * declaring would otherwise empty the workspace's tool surface.
		 */
		$candidates = self::DEFAULT_CANDIDATES;

		foreach ( $this->get_policy_admitted_names() as $ability_name ) {
			if ( array_key_exists( $ability_name, $candidates ) ) {
				continue;
			}

			$candidates[ $ability_name ] = '';
		}

		$before_filter = $candidates;

		$candidates = apply_filters( 'wpai_workspace_tool_candidates', $candidates );

		$this->filtered_out_names      = array();
		$this->effect_class_rejections = array();

		if ( ! is_array( $candidates ) ) {
			$this->filtered_out_names = array_keys( $before_filter );

			return array();
		}

		foreach ( array_keys( $before_filter ) as $ability_name ) {
			if ( array_key_exists( $ability_name, $candidates ) ) {
				continue;
			}

			$this->filtered_out_names[] = $ability_name;
		}

		foreach ( $this->policy->get_owner_excluded_names() as $ability_name ) {
			unset( $candidates[ $ability_name ] );
		}

		$normalized = array();

		foreach ( $candidates as $ability_name => $capability ) {
			if ( ! is_string( $ability_name ) || '' === $ability_name ) {
				continue;
			}

			if ( ! $this->candidate_survives_effect_class( $ability_name ) ) {
				$this->effect_class_rejections[] = $ability_name;

				continue;
			}

			$normalized[ $ability_name ] = is_string( $capability ) ? $capability : '';
		}

		return $normalized;
	}

	/**
	 * Returns the names the candidates filter removed on the last build.
	 *
	 * @since x.x.x
	 *
	 * @return list<string> The removed ability names.
	 */
	public function get_filtered_out_names(): array {
		return $this->filtered_out_names;
	}

	/**
	 * Returns the names the effect-class check dropped on the last build.
	 *
	 * @since x.x.x
	 *
	 * @return list<string> The dropped ability names.
	 */
	public function get_effect_class_rejections(): array {
		return $this->effect_class_rejections;
	}

	/**
	 * Returns the ability names the policy admits on this WordPress.
	 *
	 * Two separate gates come first, and neither implies the other. The
	 * temporary release gate is off until issue #354 lands, and the owner's kill
	 * switch and the WordPress version are what `Tool_Policy::is_active()`
	 * answers. Either one closed admits nothing; the curated floor still stands
	 * either way, because it is merged before this is ever consulted.
	 *
	 * @since x.x.x
	 *
	 * @return list<string> The admitted ability names.
	 */
	private function get_policy_admitted_names(): array {
		if ( ! $this->policy->admission_is_enabled() ) {
			return array();
		}

		if ( ! $this->policy->is_active() ) {
			return array();
		}

		$discovered = wp_get_abilities(
			array_merge(
				$this->policy->get_discovery_args(),
				array(
					'item_include_callback' => function ( $ability ): bool {
						return $ability instanceof WP_Ability && $this->policy->has_admissible_effect_class( $ability );
					},
				)
			)
		);

		$names = array();

		foreach ( $discovered as $ability ) {
			if ( ! $ability instanceof WP_Ability ) {
				continue;
			}

			if ( ! $this->policy->is_declared( $ability ) || ! $this->policy->has_admissible_effect_class( $ability ) ) {
				continue;
			}

			$names[] = $ability->get_name();
		}

		return $names;
	}

	/**
	 * Reports whether a merged candidate survives the effect-class check.
	 *
	 * Deliberately not named for {@see Tool_Policy::has_admissible_effect_class()},
	 * which it is not a version of. That one is strict and answers about an
	 * ability; this one answers about a name in the candidate map and fails open
	 * three times over — for the curated floor, for a name nothing registered,
	 * and for a name that does not resolve to a `WP_Ability`.
	 *
	 * @since x.x.x
	 *
	 * @param string $ability_name The candidate ability name.
	 * @return bool True when the candidate may be declared.
	 */
	private function candidate_survives_effect_class( string $ability_name ): bool {
		if ( array_key_exists( $ability_name, self::DEFAULT_CANDIDATES ) ) {
			return true;
		}

		if ( ! wp_has_ability( $ability_name ) ) {
			/*
			 * Nothing is known about an unregistered name, and `get_tool_names()`
			 * drops it anyway. Answering true here keeps the candidate map — which
			 * `get_unavailability_reason()` also reads — the same shape it has
			 * always had.
			 */
			return true;
		}

		$ability = wp_get_ability( $ability_name );

		if ( ! $ability instanceof WP_Ability ) {
			return true;
		}

		return $this->policy->has_admissible_effect_class( $ability );
	}

	/**
	 * Returns the ability names to declare for a scope.
	 *
	 * @since x.x.x
	 *
	 * @param string $scope The conversation scope.
	 * @return list<string> The ability names to declare.
	 */
	public function get_tool_names( string $scope ): array {
		if ( self::SCOPE_SITE !== $scope ) {
			return array();
		}

		$names = array();

		foreach ( $this->get_candidates() as $ability_name => $capability ) {
			if ( ! wp_has_ability( $ability_name ) ) {
				continue;
			}

			if ( ! $this->can_declare( $capability ) ) {
				continue;
			}

			$names[] = $ability_name;
		}

		return $names;
	}

	/**
	 * Reports whether the current user clears a candidate's coarse capability.
	 *
	 * An empty capability means "any authenticated user"; the ability's own
	 * permission callback remains the authority at execution time.
	 *
	 * A policy-admitted ability always lands on the empty capability, because
	 * nothing on `WP_Ability` exposes one to read. That is not a loosening: the
	 * coarse gate has never been the authorization boundary, and an ability
	 * admitted by policy is still refused at execute time by its own
	 * `permission_callback` exactly as a curated one is.
	 *
	 * @since x.x.x
	 *
	 * @param string $capability The coarse capability, or an empty string.
	 * @return bool True when the ability may be declared to the model.
	 */
	public function can_declare( string $capability ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		if ( '' === $capability ) {
			return true;
		}

		// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- The capability is not a literal here: it comes from the candidate map, where it is either declared by this class, supplied by a site owner through `wpai_workspace_tool_candidates`, or empty for a policy-admitted ability, and it is a coarse pre-filter rather than the authorization decision.
		return current_user_can( $capability );
	}

	/**
	 * Explains why a scope declared no tools.
	 *
	 * Site Context reports its own unavailability rather than quietly behaving
	 * like General Knowledge (R7).
	 *
	 * @since x.x.x
	 *
	 * @param string $scope The conversation scope.
	 * @return string One of the REASON_* constants.
	 */
	public function get_unavailability_reason( string $scope ): string {
		if ( self::SCOPE_SITE !== $scope ) {
			return self::REASON_GENERAL_SCOPE;
		}

		$candidates = $this->get_candidates();
		$registered = 0;

		foreach ( array_keys( $candidates ) as $ability_name ) {
			if ( ! wp_has_ability( $ability_name ) ) {
				continue;
			}

			++$registered;
		}

		if ( 0 !== $registered ) {
			return self::REASON_NOT_PERMITTED;
		}

		if ( array() !== $candidates ) {
			// Names are on the list; none of them resolves to a registered
			// ability, which is a statement about the site's registry.
			return self::REASON_NO_CANDIDATES;
		}

		/*
		 * Nothing is left on the list at all. The removals are read after the
		 * `get_candidates()` call above, which is what records them: an owner who
		 * removed every candidate, or site code that filtered them all away, has
		 * emptied the surface deliberately, and telling them the site registers
		 * no abilities the assistant can call would be false.
		 */
		if ( array() !== $this->filtered_out_names || array() !== $this->policy->get_owner_excluded_names() ) {
			return self::REASON_SURFACE_EMPTIED;
		}

		return self::REASON_NO_CANDIDATES;
	}
}
