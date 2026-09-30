<?php
/**
 * Decides which abilities a page exposes as WebMCP tools, and converts them.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\WebMCP;

use WP_Ability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposure rules and ability-to-tool conversion for the WebMCP experiment.
 *
 * An ability is exposed in a context when any of these holds:
 *
 * 1. The ability opts in through its `meta`: `'webmcp' => true` (every
 *    context), `'webmcp' => 'admin'` or `'visitor'` (one context), or
 *    `'webmcp' => array( 'admin' => true, 'visitor' => false )`.
 * 2. The `wpai_webmcp_exposed_abilities` filter adds its name for the context.
 * 3. The site owner lists its name in the experiment's settings for the context.
 *
 * Abilities were written for server-side callers; a browser agent is a
 * different trust context, so nothing is exposed by default.
 *
 * @since x.x.x
 */
class Tool_Curator {

	/**
	 * What stands in for `/` in a tool name on the wire.
	 *
	 * Ability names contain `/`, and a URL-encoded slash is rejected by stock
	 * Apache before WordPress runs. Tool names travel as `core__get-post`, and
	 * the execute route maps them back. An ability name must therefore not
	 * contain `__` itself.
	 *
	 * @since x.x.x
	 */
	public const SEPARATOR = '__';

	/**
	 * Default cap on tools registered on one page.
	 *
	 * Agent browsers impose a per-page budget; registering a few hundred
	 * tools disabled WebMCP for the document with no error in testing, while
	 * about thirty worked. Filterable through `wpai_webmcp_max_tools`.
	 *
	 * @since x.x.x
	 */
	public const DEFAULT_MAX_TOOLS = 30;

	/**
	 * Feature ID, used to read the experiment's settings options.
	 *
	 * @since x.x.x
	 * @var string
	 */
	private string $feature_id;

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param string $feature_id Feature ID of the experiment that owns the settings.
	 */
	public function __construct( string $feature_id ) {
		$this->feature_id = $feature_id;
	}

	/**
	 * Returns the page contexts the experiment knows.
	 *
	 * @since x.x.x
	 *
	 * @return list<string> Context names.
	 */
	public static function get_contexts(): array {
		return array( WebMCP::CONTEXT_ADMIN, WebMCP::CONTEXT_VISITOR );
	}

	/**
	 * Whether a string names a known context.
	 *
	 * @since x.x.x
	 *
	 * @param string $context Candidate.
	 * @return bool True when known.
	 */
	public static function is_valid_context( string $context ): bool {
		return in_array( $context, self::get_contexts(), true );
	}

	/**
	 * Converts an ability name to the tool name sent to the browser.
	 *
	 * @since x.x.x
	 *
	 * @param string $ability_name Ability name, for example `core/get-post`.
	 * @return string Tool name, for example `core__get-post`.
	 */
	public static function to_tool_name( string $ability_name ): string {
		return str_replace( '/', self::SEPARATOR, $ability_name );
	}

	/**
	 * Converts a tool name from the browser back to the ability name.
	 *
	 * @since x.x.x
	 *
	 * @param string $tool_name Tool name, for example `core__get-post`.
	 * @return string Ability name, for example `core/get-post`.
	 */
	public static function to_ability_name( string $tool_name ): string {
		return str_replace( self::SEPARATOR, '/', $tool_name );
	}

	/**
	 * Returns the cap on tools per page.
	 *
	 * @since x.x.x
	 *
	 * @return int Cap, at least 1.
	 */
	public function get_max_tools(): int {
		/**
		 * Filters how many tools one page may register with the browser.
		 *
		 * @since x.x.x
		 *
		 * @param int $max_tools Cap. Default 30.
		 */
		$max_tools = (int) apply_filters( 'wpai_webmcp_max_tools', self::DEFAULT_MAX_TOOLS );

		return max( 1, $max_tools );
	}

	/**
	 * Returns the names of the abilities exposed in a context, sorted.
	 *
	 * Only registered abilities are returned; names that do not resolve are
	 * dropped rather than reported, because the settings field is free text.
	 *
	 * @since x.x.x
	 *
	 * @param string $context Page context.
	 * @return list<string> Ability names.
	 */
	public function get_exposed_names( string $context ): array {
		if ( ! self::is_valid_context( $context ) ) {
			return array();
		}

		$names = array_merge(
			$this->get_opted_in_names( $context ),
			$this->get_settings_names( $context )
		);

		/**
		 * Filters the abilities exposed as WebMCP tools in a page context.
		 *
		 * @since x.x.x
		 *
		 * @param list<string> $names   Ability names, for example `core/get-post`.
		 * @param string       $context Page context: `admin` or `visitor`.
		 */
		$names = apply_filters( 'wpai_webmcp_exposed_abilities', $names, $context );

		if ( ! is_array( $names ) ) {
			return array();
		}

		// Names come from free text and filters, so they are checked against the
		// registry list rather than looked up one by one: wp_get_ability() on an
		// unknown name raises a "doing it wrong" notice, which is not the caller's fault here.
		$registered = $this->get_registered();
		$exposed    = array();
		foreach ( $names as $name ) {
			if ( ! is_string( $name ) || '' === $name ) {
				continue;
			}
			if ( ! isset( $registered[ $name ] ) ) {
				continue;
			}
			$exposed[ $name ] = true;
		}

		$exposed = array_keys( $exposed );
		sort( $exposed );

		return $exposed;
	}

	/**
	 * Whether a context exposes anything at all.
	 *
	 * @since x.x.x
	 *
	 * @param string $context Page context.
	 * @return bool True when at least one ability is exposed.
	 */
	public function has_exposed_abilities( string $context ): bool {
		return array() !== $this->get_exposed_names( $context );
	}

	/**
	 * Whether one ability is exposed in a context.
	 *
	 * @since x.x.x
	 *
	 * @param string $ability_name Ability name.
	 * @param string $context      Page context.
	 * @return bool True when exposed.
	 */
	public function is_exposed( string $ability_name, string $context ): bool {
		return in_array( $ability_name, $this->get_exposed_names( $context ), true );
	}

	/**
	 * Returns the tools a page registers, for the current user, within the cap.
	 *
	 * Abilities the current user may not run are left out, so the agent never
	 * sees a tool that would only ever answer with a permission error.
	 *
	 * @since x.x.x
	 *
	 * @param string $context Page context.
	 * @return array{tools: list<array<string, mixed>>, truncated: int} Tools and how many were cut by the cap.
	 */
	public function get_tools( string $context ): array {
		$registered = $this->get_registered();
		$tools      = array();
		foreach ( $this->get_exposed_names( $context ) as $name ) {
			$ability = $registered[ $name ] ?? null;
			if ( ! $ability instanceof WP_Ability ) {
				continue;
			}
			if ( ! $this->can_run( $ability ) ) {
				continue;
			}
			$tools[] = $this->convert( $ability );
		}

		$max       = $this->get_max_tools();
		$truncated = max( 0, count( $tools ) - $max );

		return array(
			'tools'     => array_slice( $tools, 0, $max ),
			'truncated' => $truncated,
		);
	}

	/**
	 * Converts one ability to the tool shape WebMCP expects.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Ability $ability Ability.
	 * @return array<string, mixed> Tool: name, description, inputSchema, annotations.
	 */
	public function convert( WP_Ability $ability ): array {
		$description = trim( (string) $ability->get_description() );
		$label       = trim( (string) $ability->get_label() );
		if ( '' === $description ) {
			$description = $label;
		} elseif ( '' !== $label && 0 !== strpos( $description, $label ) ) {
			$description = $label . '. ' . $description;
		}

		$input_schema = $ability->get_input_schema();
		if ( ! is_array( $input_schema ) || array() === $input_schema ) {
			$input_schema = array( 'type' => 'object' );
		}
		if ( ( $input_schema['type'] ?? null ) === 'object' && empty( $input_schema['properties'] ) ) {
			// An empty PHP array encodes as `[]`; agents expect `{}` here.
			$input_schema['properties'] = new \stdClass();
		}

		$meta        = $ability->get_meta();
		$annotations = is_array( $meta['annotations'] ?? null ) ? $meta['annotations'] : array();

		return array(
			'name'        => self::to_tool_name( $ability->get_name() ),
			'description' => $description,
			'inputSchema' => $input_schema,
			'annotations' => array(
				'readOnlyHint'    => ! empty( $annotations['readonly'] ),
				'destructiveHint' => ! empty( $annotations['destructive'] ),
				'idempotentHint'  => ! empty( $annotations['idempotent'] ),
			),
		);
	}

	/**
	 * Whether the current user may run an ability, per its own permission callback.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Ability $ability Ability.
	 * @return bool True when allowed.
	 */
	private function can_run( WP_Ability $ability ): bool {
		return true === $ability->check_permissions();
	}

	/**
	 * Registered abilities keyed by name.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, \WP_Ability> Abilities.
	 */
	private function get_registered(): array {
		$registered = array();
		foreach ( wp_get_abilities() as $ability ) {
			if ( ! ( $ability instanceof WP_Ability ) ) {
				continue;
			}

			$registered[ $ability->get_name() ] = $ability;
		}

		return $registered;
	}

	/**
	 * Abilities that opt in through their meta.
	 *
	 * @since x.x.x
	 *
	 * @param string $context Page context.
	 * @return list<string> Ability names.
	 */
	private function get_opted_in_names( string $context ): array {
		$names = array();
		foreach ( $this->get_registered() as $ability ) {
			$meta = $ability->get_meta();
			if ( ! self::meta_opts_in( $meta['webmcp'] ?? null, $context ) ) {
				continue;
			}

			$names[] = $ability->get_name();
		}

		return $names;
	}

	/**
	 * Reads the `webmcp` meta value of an ability for one context.
	 *
	 * @since x.x.x
	 *
	 * @param mixed  $value   Meta value.
	 * @param string $context Page context.
	 * @return bool True when the value opts the ability into the context.
	 */
	public static function meta_opts_in( $value, string $context ): bool {
		if ( true === $value ) {
			return true;
		}
		if ( is_string( $value ) ) {
			return $value === $context || 'all' === $value;
		}
		if ( is_array( $value ) ) {
			return ! empty( $value[ $context ] );
		}

		return false;
	}

	/**
	 * Abilities listed in the experiment's settings for a context.
	 *
	 * @since x.x.x
	 *
	 * @param string $context Page context.
	 * @return list<string> Ability names.
	 */
	private function get_settings_names( string $context ): array {
		$option = get_option( "wpai_feature_{$this->feature_id}_field_{$context}_abilities", '' );
		if ( ! is_string( $option ) || '' === trim( $option ) ) {
			return array();
		}

		$names = array();
		$parts = preg_split( '/[\s,]+/', $option );
		if ( false === $parts ) {
			return array();
		}

		foreach ( $parts as $name ) {
			$name = trim( $name );
			if ( '' === $name ) {
				continue;
			}

			$names[] = $name;
		}

		return $names;
	}
}
