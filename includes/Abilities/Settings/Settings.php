<?php
/**
 * The `core/settings` WordPress Ability.
 *
 * @package WordPress\AI
 *
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Settings;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Settings
 *
 * Registers the read-only `core/settings` ability and the write-oriented `core/manage-settings`
 * ability. Both operate on the same settings — those flagged with `show_in_abilities` — as a flat
 * map of setting name to value: `core/settings` reads them and `core/manage-settings` updates them,
 * sharing the helpers get_exposed_settings(), value_schema() and cast_value().
 *
 * Plugin: WordPress core's WP_Settings_Abilities currently reserves `core/manage-settings` but does
 * not implement it; the plugin ships the write ability ahead of core.
 *
 * This class is kept almost identical to the WordPress core class `WP_Settings_Abilities`
 * so the two implementations stay in sync. Differences from the core class are marked with
 * `// Plugin:` comments. Additionally, all user-facing strings use the 'ai' text domain.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since x.x.x
 */
final class Settings {

	/**
	 * The ability category used for settings abilities.
	 *
	 * @since x.x.x
	 * @var string
	 */
	private const CATEGORY = 'site';

	/**
	 * Settings exposed through the Abilities API, computed once at registration.
	 *
	 * Plugin: cached so the input/output schema and the executed result derive from the exact
	 * same structure, and {@see get_registered_settings()} is only walked once per request.
	 *
	 * @since x.x.x
	 * @var array<string, array{option: string, group: string, default: mixed, schema: array<string, mixed>}>|null
	 */
	private $exposed_settings = null;

	/**
	 * Hooks the ability into the Abilities API.
	 *
	 * Plugin: this method has no equivalent in the core class. In core, register() is
	 * invoked directly from wp_register_core_abilities() (already on the
	 * `wp_abilities_api_init` hook). The plugin instead hooks register() slightly later
	 * (priority 11) so it can override any core-provided copy.
	 *
	 * @since x.x.x
	 */
	public function init(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register' ), 11 );
	}

	/**
	 * Registers all settings abilities.
	 *
	 * Must run on the `wp_abilities_api_init` hook.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		$this->register_get_settings();
		$this->register_manage_settings();
	}

	/**
	 * Registers the read-only `core/settings` ability.
	 *
	 * @since x.x.x
	 */
	private function register_get_settings(): void {
		// Plugin: unregister any core-provided copy first so the plugin's version wins.
		if ( wp_has_ability( 'core/settings' ) ) {
			wp_unregister_ability( 'core/settings' );
		}

		// Compute once; execute_get_settings() reuses this exact structure.
		$this->exposed_settings = $this->get_exposed_settings();

		$settings    = $this->exposed_settings;
		$field_names = array_keys( $settings );
		$groups      = array();
		$properties  = array();
		foreach ( $settings as $exposed_name => $setting ) {
			$properties[ $exposed_name ] = $setting['schema'];
			if ( '' === $setting['group'] || in_array( $setting['group'], $groups, true ) ) {
				continue;
			}
			$groups[] = $setting['group'];
		}

		wp_register_ability(
			'core/settings',
			array(
				'label'               => __( 'Get Settings', 'ai' ),
				'description'         => __( 'Returns WordPress settings as a flat map of setting name to value. By default returns all settings exposed to abilities, or optionally a subset filtered by settings group, by setting name, or both.', 'ai' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->get_settings_input_schema( $groups, $field_names ),
				'output_schema'       => array(
					'type'                 => 'object',
					'description'          => __( 'A map of setting name to its current value.', 'ai' ),
					'properties'           => $properties,
					'additionalProperties' => false,
				),
				'execute_callback'    => array( $this, 'execute_get_settings' ),
				'permission_callback' => array( $this, 'has_permission' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Registers the write-oriented `core/manage-settings` ability.
	 *
	 * Accepts a map of exposed setting name to its new value and stores each one, returning the
	 * updated values. Every setting exposed to abilities is writable: a truthy `show_in_abilities`
	 * flag grants both read (via `core/settings`) and write (via this ability), so the input and
	 * output schemas reuse the same per-setting schemas as the read ability.
	 *
	 * The Abilities API validates the input against those schemas (with `additionalProperties`
	 * disabled) before {@see execute_manage_settings()} runs, so an invalid or unknown value aborts
	 * the whole call before any option is written — matching the all-or-nothing behavior of the core
	 * REST settings controller.
	 *
	 * Plugin: WordPress core reserves this ability in WP_Settings_Abilities::register() but does not
	 * yet implement it. The plugin implements it here, reusing the exposed-settings snapshot that
	 * register_get_settings() computed.
	 *
	 * @since x.x.x
	 */
	private function register_manage_settings(): void {
		// Plugin: unregister any core-provided copy first so the plugin's version wins.
		if ( wp_has_ability( 'core/manage-settings' ) ) {
			wp_unregister_ability( 'core/manage-settings' );
		}

		$settings   = (array) $this->exposed_settings;
		$properties = array();
		foreach ( $settings as $exposed_name => $setting ) {
			$properties[ $exposed_name ] = $setting['schema'];
		}

		wp_register_ability(
			'core/manage-settings',
			array(
				'label'               => __( 'Manage Settings', 'ai' ),
				'description'         => __( 'Updates one or more WordPress settings exposed to abilities. Accepts a map of setting name to its new value and returns the updated values.', 'ai' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'description'          => __( 'A map of setting name to the new value to store. At least one setting is required.', 'ai' ),
					'properties'           => $properties,
					'minProperties'        => 1,
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'description'          => __( 'A map of each updated setting name to its new value.', 'ai' ),
					'properties'           => $properties,
					'additionalProperties' => false,
				),
				'execute_callback'    => array( $this, 'execute_manage_settings' ),
				'permission_callback' => array( $this, 'has_permission' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Executes the `core/settings` ability.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed> Map of exposed setting name to current value.
	 */
	public function execute_get_settings( $input = array() ): array {
		$input = is_array( $input ) ? $input : array();

		$settings = $this->exposed_settings;
		if ( null === $settings ) {
			// The cache is populated in register_get_settings() before the ability is
			// registered, so this is unreachable in practice; bail defensively otherwise.
			return array();
		}

		$group  = isset( $input['group'] ) && is_string( $input['group'] ) ? $input['group'] : '';
		$fields = isset( $input['fields'] ) && is_array( $input['fields'] ) ? $input['fields'] : array();

		$result = array();
		foreach ( $settings as $exposed_name => $setting ) {
			if ( '' !== $group && $setting['group'] !== $group ) {
				continue;
			}
			if ( ! empty( $fields ) && ! in_array( $exposed_name, $fields, true ) ) {
				continue;
			}

			$type  = isset( $setting['schema']['type'] ) && is_string( $setting['schema']['type'] ) ? $setting['schema']['type'] : 'string';
			$value = get_option( $setting['option'], $setting['default'] );

			$result[ $exposed_name ] = $this->cast_value( $value, $type );
		}

		return $result;
	}

	/**
	 * Executes the `core/manage-settings` ability.
	 *
	 * The Abilities API validates the input against the registered input schema (each setting's own
	 * value schema, with `additionalProperties` disabled) before this runs, so every value reaching
	 * here is known and valid; an invalid value aborts the call before any option is written. Each
	 * value is sanitized against its schema and stored, then read back and cast for the response.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input The ability input: a map of exposed setting name to its new value.
	 * @return array<string, mixed> Map of each updated setting name to its stored value.
	 */
	public function execute_manage_settings( $input = array() ): array {
		$input = is_array( $input ) ? $input : array();

		$settings = $this->exposed_settings;
		if ( null === $settings ) {
			// The cache is populated in register_get_settings() before the ability is
			// registered, so this is unreachable in practice; bail defensively otherwise.
			return array();
		}

		$result = array();
		foreach ( $input as $exposed_name => $value ) {
			if ( ! is_string( $exposed_name ) || ! isset( $settings[ $exposed_name ] ) ) {
				// `additionalProperties: false` already rejects unknown keys upstream; guard defensively.
				continue;
			}

			$setting = $settings[ $exposed_name ];

			// Sanitize against the declared schema before storing; update_option() additionally
			// runs the setting's own registered sanitize_callback.
			$value = rest_sanitize_value_from_schema( $value, $setting['schema'], $exposed_name );

			update_option( $setting['option'], $value );

			$type   = isset( $setting['schema']['type'] ) && is_string( $setting['schema']['type'] ) ? $setting['schema']['type'] : 'string';
			$stored = get_option( $setting['option'], $setting['default'] );

			$result[ $exposed_name ] = $this->cast_value( $stored, $type );
		}

		return $result;
	}

	/**
	 * Checks whether the current user may use the settings abilities.
	 *
	 * @since x.x.x
	 *
	 * @return bool True if the current user can manage options.
	 */
	public function has_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Builds the input schema for the get ability: optional filters by group and/or name.
	 *
	 * Both `group` and `fields` are optional; supplying both narrows the response to their
	 * intersection, and supplying neither returns every exposed setting.
	 *
	 * @since x.x.x
	 *
	 * @param list<string> $groups      Available settings groups.
	 * @param list<string> $field_names Available exposed setting names.
	 * @return array<string, mixed> The input JSON Schema.
	 */
	private function get_settings_input_schema( array $groups, array $field_names ): array {
		return array(
			'type'                 => 'object',
			// Object (not array()) so the serialized schema default is {}, consistent with type:object.
			'default'              => (object) array(),
			'properties'           => array(
				'group'  => array(
					'type'        => 'string',
					'enum'        => $groups,
					'description' => __( 'Return only settings that belong to this settings group.', 'ai' ),
				),
				'fields' => array(
					'type'        => 'array',
					'items'       => array(
						'type' => 'string',
						'enum' => $field_names,
					),
					'description' => __( 'Return only the settings with these names.', 'ai' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the settings exposed through the Abilities API.
	 *
	 * Reads {@see get_registered_settings()} and keeps only settings flagged with a truthy
	 * `show_in_abilities` argument. Each entry is keyed by its exposed name and carries the
	 * underlying option name, the settings group, the registration default, and a JSON Schema
	 * describing the value.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{option: string, group: string, default: mixed, schema: array<string, mixed>}> Settings keyed by exposed name.
	 */
	private function get_exposed_settings(): array {
		$settings = array();

		foreach ( get_registered_settings() as $option_name => $args ) {
			$show = $args['show_in_abilities'] ?? false;
			if ( empty( $show ) ) {
				continue;
			}

			$option_name  = (string) $option_name;
			$exposed_name = is_array( $show ) && isset( $show['name'] ) && is_string( $show['name'] ) && '' !== $show['name'] ? $show['name'] : $option_name;

			$settings[ $exposed_name ] = array(
				'option'  => $option_name,
				'group'   => isset( $args['group'] ) && is_string( $args['group'] ) ? $args['group'] : '',
				'default' => array_key_exists( 'default', $args ) ? $args['default'] : false,
				'schema'  => $this->value_schema( $args, $show ),
			);
		}

		return $settings;
	}

	/**
	 * Builds the JSON Schema describing a single setting's value.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed>      $args The setting registration arguments.
	 * @param bool|array<string, mixed> $show The setting's `show_in_abilities` value.
	 * @return array<string, mixed> The value JSON Schema.
	 */
	private function value_schema( array $args, $show ): array {
		$schema = array(
			'type' => isset( $args['type'] ) && is_string( $args['type'] ) ? $args['type'] : 'string',
		);
		if ( ! empty( $args['label'] ) ) {
			$schema['title'] = $args['label'];
		}
		if ( ! empty( $args['description'] ) ) {
			$schema['description'] = $args['description'];
		}
		if ( is_array( $show ) && isset( $show['schema'] ) && is_array( $show['schema'] ) ) {
			/** @var array<string, mixed> $show_schema */
			$show_schema = $show['schema'];
			$schema      = array_merge( $schema, $show_schema );
		}

		return $schema;
	}

	/**
	 * Casts a stored option value to the type declared in its settings registration.
	 *
	 * @since x.x.x
	 *
	 * @param mixed  $value The raw option value.
	 * @param string $type  The registered setting type.
	 * @return mixed The value cast to the declared type.
	 */
	private function cast_value( $value, string $type ) {
		switch ( $type ) {
			case 'boolean':
				return (bool) $value;
			case 'integer':
				return is_scalar( $value ) ? (int) $value : 0;
			case 'number':
				return is_scalar( $value ) ? (float) $value : 0.0;
			case 'array':
				return is_array( $value ) ? $value : array();
			case 'object':
				// Cast to object so an empty/non-array value serializes as {} (not []) and
				// satisfies the `object` output schema validated by execute().
				return (object) ( is_array( $value ) ? $value : array() );
			default:
				return is_scalar( $value ) ? (string) $value : $value;
		}
	}
}
