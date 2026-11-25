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
 * Decides which abilities the workspace may offer the model, and stores the site owner's narrowing.
 *
 * Not authorization: declarations and annotations are self-attested, and execute-time permission checks still run.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since x.x.x
 */
class Tool_Policy {

	/**
	 * The ability meta key carrying the conversational-surface declaration; provisional until #354 settles it.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const DECLARATION_CHANNEL = 'ai-workspace';

	/**
	 * The general exposure flag every channel falls back to.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const PUBLIC_META_KEY = 'public';

	/**
	 * Option holding the ability names the site owner removed from the surface.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const OWNER_EXCLUSIONS_OPTION = 'wpai_workspace_tool_exclusions';

	/**
	 * Option that switches the admission policy off, leaving only the curated floor; the owner's control, not the release gate.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const POLICY_DISABLED_OPTION = 'wpai_workspace_tool_policy_disabled';

	/**
	 * Exclusion reason: the ability does not resolve to public for this channel.
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
	 * Core abilities never admitted: they expose personal data, configuration or environment to prompt injection.
	 *
	 * When core renames one, add the new name and keep the old (deprecated aliases stay registered).
	 *
	 * @since x.x.x
	 *
	 * @var list<string>
	 */
	private const NEVER_ADMITTED = array( // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is a single array constant.
		'core/get-user-info',
		'core/users-query',
		'core/read-users',
		'core/settings-get',
		'core/read-settings',
		'core/get-environment-info',
		'core/get-site-info',
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
	 * Exclusion reason: the `wpai_workspace_tool_candidates` filter removed the ability.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const REASON_FILTERED = 'filtered';

	/**
	 * Exclusion reason: the ability is admissible, but admission is not enabled yet.
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
	 * Exclusion reason: the admission policy is not running (old WordPress, or switched off by the owner).
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const REASON_POLICY_OFF = 'policy_off';

	/**
	 * Returns the registered ability names the site owner removed; unregistered names stay stored for reactivation.
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
	 * Reports whether declaration-based admission is on: a temporary release gate, off until #354 lands.
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
		 * Filters whether the AI Workspace may admit abilities by declaration. Temporary, until #354.
		 *
		 * @since x.x.x
		 *
		 * @param bool $enabled Whether declared abilities may be admitted.
		 */
		return (bool) apply_filters( 'wpai_workspace_tool_admission_enabled', $enabled );
	}

	/**
	 * Reports whether the admission policy runs on this request; ignores the temporary release gate.
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
	 * @since x.x.x
	 *
	 * @return array<string, string|null> Map of ability name to a REASON_* code, or null when the ability is on the surface.
	 */
	public function get_exclusion_reasons(): array {
		$selector = new Tool_Selector( $this );

		// Built once; per-ability calls would rebuild the surface for every row.
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
	 * The filtered and effect-class lists are side effects of `get_candidates()`, so read them after it.
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
			return self::REASON_CAPABILITY;
		}

		if ( $this->is_owner_excluded( $ability_name ) ) {
			return self::REASON_OWNER_EXCLUDED;
		}

		// Site-code causes come before declaration-side reasons.
		if ( in_array( $ability_name, $surface['filtered'], true ) ) {
			return self::REASON_FILTERED;
		}

		if ( in_array( $ability_name, $surface['effect_class'], true ) ) {
			return self::REASON_EFFECT_CLASS;
		}

		if ( ! $this->is_active() ) {
			return self::REASON_POLICY_OFF;
		}

		if ( $this->is_withheld( $ability->get_name() ) ) {
			return self::REASON_WITHHELD;
		}

		if ( ! $this->is_declared( $ability ) ) {
			return self::REASON_NOT_PUBLIC;
		}

		if ( ! $this->has_admissible_effect_class( $ability ) ) {
			return self::REASON_EFFECT_CLASS;
		}

		if ( ! $this->admission_is_enabled() ) {
			return self::REASON_AWAITING_ENABLE;
		}

		return self::REASON_CAPABILITY;
	}

	/**
	 * Returns the name of the ability discovery function the probe inspects; a test seam for the 7.0 signature.
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
	 * Reads the signature: 7.0 silently drops the arguments and returns the full registry.
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
	 * Returns the discovery arguments for candidate abilities; results must still pass {@see self::is_declared()}.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> Arguments for `wp_get_abilities()`.
	 */
	public function get_discovery_args(): array {
		// No `meta` condition: core's exact matching cannot express the fallback to `meta.public`.
		return array();
	}


	/**
	 * Checks whether an ability declares itself eligible; strict, since the channel key is not type-validated.
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

		// Core's channel precedence: the channel's own key, then `meta.public`, then closed.
		return $meta[ self::DECLARATION_CHANNEL ]['public'] ?? $meta[ self::PUBLIC_META_KEY ] ?? null;
	}

	/**
	 * Checks whether an ability is held back whatever it says about itself.
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
	 * Core defaults annotations to null, so each must be explicit; `open_world` blocks remote exfiltration.
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
