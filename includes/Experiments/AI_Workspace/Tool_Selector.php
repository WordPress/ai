<?php
/**
 * Chooses which abilities are declared to the model for a workspace turn.
 *
 * @package WordPress\AI\Experiments\AI_Workspace
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\AI_Workspace;

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
	 * Candidate abilities, mapped to the coarse capability each one requires.
	 *
	 * @since x.x.x
	 *
	 * @var array<string, string>
	 */
	private const DEFAULT_CANDIDATES = array( 'ai/search-content' => '' ); // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is a single array constant.

	/**
	 * Returns the candidate abilities and their coarse capability requirements.
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
		$candidates = apply_filters( 'wpai_workspace_tool_candidates', self::DEFAULT_CANDIDATES );

		if ( ! is_array( $candidates ) ) {
			return array();
		}

		$normalized = array();

		foreach ( $candidates as $ability_name => $capability ) {
			if ( ! is_string( $ability_name ) || '' === $ability_name ) {
				continue;
			}

			$normalized[ $ability_name ] = is_string( $capability ) ? $capability : '';
		}

		return $normalized;
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

		return current_user_can( $capability ); // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- The capability is declared alongside the ability in the candidate map.
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

		$registered = 0;

		foreach ( array_keys( $this->get_candidates() ) as $ability_name ) {
			if ( ! wp_has_ability( $ability_name ) ) {
				continue;
			}

			++$registered;
		}

		return 0 === $registered ? self::REASON_NO_CANDIDATES : self::REASON_NOT_PERMITTED;
	}
}
