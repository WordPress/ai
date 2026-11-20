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
 * The capability gate is input-free on purpose: content permission callbacks need input, so
 * `check_permissions()` with null would deny everything. Execute-time checks remain the authority.
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
	 * Reason code returned when site code or the owner removed every candidate.
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
	 * Candidate names the `wpai_workspace_tool_candidates` filter removed on the last build.
	 *
	 * @since x.x.x
	 *
	 * @var list<string>
	 */
	private $filtered_out_names = array();

	/**
	 * Candidate names the effect-class check dropped on the last build.
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
	 * @param \WordPress\AI\Experiments\AI_Workspace\Tool_Policy|null    $policy    Optional. The admission policy. Default a new instance.
	 * @param \WordPress\AI\Experiments\AI_Workspace\Proposal_Store|null $proposals Optional. The proposal store whose kinds are offered. Default a new instance.
	 */
	public function __construct( ?Tool_Policy $policy = null, ?Proposal_Store $proposals = null ) {
		$this->policy    = null === $policy ? new Tool_Policy() : $policy;
		$this->proposals = null === $proposals ? new Proposal_Store() : $proposals;
	}

	/**
	 * Candidate abilities, mapped to the coarse capability each one requires.
	 *
	 * @since x.x.x
	 *
	 * @var array<string, string>
	 */
	private const DEFAULT_CANDIDATES = array( // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is a single array constant.
		self::SEARCH_ABILITY    => '',
		// Rows are filtered per user at execute time; a tighter gate would only withhold legitimate reads.
		self::READ_ABILITY      => '',
		// Versions are added only for `manage_options`; the rest is public.
		'ai/get-site-info'      => '',
		// Each screen is checked against the user's capabilities before it is returned.
		'ai/find-admin-screens' => '',
	);

	/**
	 * The proposal store, whose kinds supply the propose abilities.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Experiments\AI_Workspace\Proposal_Store
	 */
	private Proposal_Store $proposals;

	/**
	 * Returns the curated floor: the reads, each proposal kind's propose ability, and the question tool.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, string> Map of ability name to required capability.
	 */
	private function curated_candidates(): array {
		$candidates = self::DEFAULT_CANDIDATES;

		foreach ( $this->proposals->kinds() as $kind ) {
			$candidates[ $kind->ability() ] = $kind->capability();
		}

		$candidates[ Ask_User::ABILITY ] = '';

		return $candidates;
	}

	/**
	 * Returns the candidate abilities and their coarse capability requirements.
	 *
	 * Order matters: floor, policy additions, filter, owner removals (last word), then the effect-class check
	 * (the floor is exempt, since propose abilities are not read-only).
	 *
	 * @since x.x.x
	 *
	 * @return array<string, string> Map of ability name to required capability.
	 */
	public function get_candidates(): array {
		$candidates = $this->curated_candidates();

		foreach ( $this->get_policy_admitted_names() as $ability_name ) {
			if ( array_key_exists( $ability_name, $candidates ) ) {
				continue;
			}

			$candidates[ $ability_name ] = '';
		}

		$before_filter = $candidates;

		/**
		 * Filters the abilities the AI Workspace may declare to the model.
		 *
		 * @since x.x.x
		 *
		 * @param array<string, string> $candidates Map of ability name to coarse capability; empty means any logged-in user.
		 */
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

		$curated = $this->curated_candidates();

		foreach ( $candidates as $ability_name => $capability ) {
			if ( ! is_string( $ability_name ) || '' === $ability_name ) {
				continue;
			}

			if ( ! $this->candidate_survives_effect_class( $ability_name, $curated ) ) {
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

			if ( $this->policy->is_withheld( $ability->get_name() ) || ! $this->policy->is_declared( $ability ) || ! $this->policy->has_admissible_effect_class( $ability ) ) {
				continue;
			}

			$names[] = $ability->get_name();
		}

		return $names;
	}

	/**
	 * Reports whether a merged candidate survives the effect-class check.
	 *
	 * Fails open for the curated floor and for unregistered names, which `get_tool_names()` drops anyway.
	 *
	 * @since x.x.x
	 *
	 * @param string                $ability_name The candidate ability name.
	 * @param array<string, string> $curated      The curated floor, from {@see self::curated_candidates()}.
	 * @return bool True when the candidate may be declared.
	 */
	private function candidate_survives_effect_class( string $ability_name, array $curated ): bool {
		// Before the floor exemption, so the withheld list holds on every route.
		if ( $this->policy->is_withheld( $ability_name ) ) {
			return false;
		}

		if ( array_key_exists( $ability_name, $curated ) ) {
			return true;
		}

		if ( ! wp_has_ability( $ability_name ) ) {
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
	 * Reports whether the current user clears a candidate's coarse capability; empty means any logged-in user.
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

		// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- From the candidate map; a coarse pre-filter, not the authorization decision.
		return current_user_can( $capability );
	}

	/**
	 * Explains why a scope declared no tools.
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
			return self::REASON_NO_CANDIDATES;
		}

		// Read after `get_candidates()`, which records the removals.
		if ( array() !== $this->filtered_out_names || array() !== $this->policy->get_owner_excluded_names() ) {
			return self::REASON_SURFACE_EMPTIED;
		}

		return self::REASON_NO_CANDIDATES;
	}
}
