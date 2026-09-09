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
 * This class answers questions only. It performs no discovery and enforces no
 * authorization: execute-time permission checks are unchanged and still run
 * inside `WP_Ability::execute()`.
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
