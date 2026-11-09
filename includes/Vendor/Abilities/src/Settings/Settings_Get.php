<?php
/**
 * Canonical WordPress abilities: `settings` get ability.
 *
 * @package WordPress\Abilities
 */
declare( strict_types = 1 );

namespace WordPress\Abilities\Settings;

use WordPress\Abilities\Abstract_Core_Ability;

/**
 * Canonical `core/settings-get` ability, backported from core.
 *
 * Registers the read-only `core/settings-get` ability, which returns WordPress settings as
 * a flat map of setting name to value. Only settings registered with a truthy
 * `show_in_abilities` argument are exposed.
 *
 * @since x.x.x
 *
 * @access private
 */
final class Settings_Get extends Abstract_Core_Ability {

	/**
	 * The ability category used for settings abilities.
	 *
	 * @since 7.2.0
	 * @var string
	 */
	private const CATEGORY = 'site';

	/**
	 * Settings exposed through the Abilities API, computed once at registration.
	 *
	 * @since 7.2.0
	 * @var array<string, array{option: string, group: string, default: mixed, schema: array<string, mixed>}>
	 */
	private $exposed_settings = array();

	/**
	 * The canonical ability name.
	 *
	 * @return string The ability name.
	 */
	protected function ability_name(): string {
		return 'core/settings-get';
	}

	/**
	 * The ability label, as registered in core.
	 *
	 * @return string The ability label.
	 */
	protected function label(): string {
		return __( 'Get Settings' );
	}

	/**
	 * The ability description, as registered in core.
	 *
	 * @return string The ability description.
	 */
	protected function description(): string {
		return __( 'Returns WordPress settings as a flat map of setting name to value. By default returns all settings exposed to abilities, or optionally a subset filtered by settings group, by setting name, or both. A setting whose value does not match its schema is left out. Requires an authenticated user who can manage options.' );
	}

	/**
	 * The ability category, as registered in core.
	 *
	 * @return string The category slug.
	 */
	protected function category_slug(): string {
		return self::CATEGORY;
	}

	/**
	 * The input JSON Schema, as core builds it when registering.
	 *
	 * @return array<string, mixed> The input JSON Schema.
	 */
	protected function input_schema(): array {
		$groups = array_values( array_unique( array_filter( array_column( $this->exposed_settings, 'group' ) ) ) );

		return $this->get_settings_get_input_schema( $groups, array_map( 'strval', array_keys( $this->exposed_settings ) ) );
	}

	/**
	 * The output JSON Schema, as core builds it when registering.
	 *
	 * @return array<string, mixed> The output JSON Schema.
	 */
	protected function output_schema(): array {
		return $this->get_settings_get_output_schema();
	}

	/**
	 * The execute callback, as registered in core.
	 *
	 * @return callable(mixed): array<string, mixed> The execute callback.
	 */
	protected function make_execute_callback(): callable {
		return function ( $input = array() ): array {
			return $this->execute_settings_get( $input );
		};
	}

	/**
	 * No permission callback, as registered in core.
	 *
	 * The `manage_options` floor from required_capabilities() owns authorization.
	 *
	 * @return null No permission callback.
	 */
	protected function make_permission_callback(): ?callable {
		return null;
	}

	/**
	 * The authored ability meta: core-verbatim, plus one marked package accommodation.
	 *
	 * The `show_in_rest` key below is the one addition (see its `// Package:` marker):
	 * core's registration omits the flag and relies on `public` seeding it, which
	 * WordPress 7.1+ only does, so the flag is set explicitly to stay exposed over the
	 * REST abilities routes on every supported WordPress (6.9+).
	 *
	 * @return array<string, mixed> The ability meta.
	 */
	protected function ability_meta(): array {
		return array(
			'annotations'  => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'public'       => true,
			// Package: set explicitly so the ability is exposed over REST on all
			// supported WordPress versions; core's registration relies on the
			// `public` flag seeding `show_in_rest`, which WordPress 7.1+ only does.
			'show_in_rest' => true,
		);
	}

	/**
	 * The request-level capability floor, as registered in core.
	 *
	 * @return list<string> The required capabilities.
	 */
	protected function required_capabilities(): array {
		return array( 'manage_options' );
	}

	/**
	 * Takes the exposed-settings snapshot, as core's register() does before registering.
	 */
	protected function prepare(): void {
		$this->exposed_settings = $this->get_exposed_settings();
	}

	/**
	 * Whether any setting is exposed, as core's register() checks before registering.
	 *
	 * @return bool True when at least one setting is exposed.
	 */
	public function should_register(): bool {
		return ! empty( $this->exposed_settings );
	}

	/**
	 * Executes the `core/settings-get` ability.
	 *
	 * @since 7.2.0
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed> Map of exposed setting name to current value.
	 */
	private function execute_settings_get( $input = array() ): array {
		$input  = rest_sanitize_object( $input );
		$group  = isset( $input['group'] ) && is_string( $input['group'] ) ? $input['group'] : '';
		$fields = rest_sanitize_array( $input['fields'] ?? array() );

		$result = array();
		foreach ( $this->exposed_settings as $exposed_name => $setting ) {
			if ( '' !== $group && $setting['group'] !== $group ) {
				continue;
			}
			if ( ! empty( $fields ) && ! in_array( (string) $exposed_name, $fields, true ) ) {
				continue;
			}

			/*
			 * As the settings endpoint does, read a setting that was never saved as its registered
			 * default, or leave it out when it has none: get_option() would return false, which a
			 * boolean schema accepts as a value and an array schema reads as an empty list.
			 */
			$value = get_option( $setting['option'], $setting['default'] );
			if ( null === $value ) {
				continue;
			}

			// WordPress stores false as '', which the boolean schema rejects.
			if ( '' === $value && 'boolean' === $setting['schema']['type'] ) {
				$value = false;
			}

			/*
			 * Leave out a value its schema rejects, before sanitizing (which could make it pass) or
			 * after (which could make it fail), instead of failing output validation for every setting.
			 */
			if ( is_wp_error( rest_validate_value_from_schema( $value, $setting['schema'] ) ) ) {
				continue;
			}

			$value = rest_sanitize_value_from_schema( $value, $setting['schema'] );
			if ( is_wp_error( rest_validate_value_from_schema( $value, $setting['schema'] ) ) ) {
				continue;
			}

			// Object (not array()) so an empty object value is serialized as {}, consistent with type:object.
			$result[ $exposed_name ] = 'object' === $setting['schema']['type'] ? (object) $value : $value;
		}

		return $result;
	}

	/**
	 * Builds the input schema for the `core/settings-get` ability: optional filters by group and/or name.
	 *
	 * Both `group` and `fields` are optional; supplying both narrows the response to their
	 * intersection, and supplying neither returns every exposed setting.
	 *
	 * @since 7.2.0
	 *
	 * @param list<string> $groups      Available settings groups.
	 * @param list<string> $field_names Available exposed setting names.
	 * @return array<string, mixed> The input JSON Schema.
	 */
	private function get_settings_get_input_schema( array $groups, array $field_names ): array {
		return array(
			'type'                 => 'object',
			'default'              => array(),
			'properties'           => array(
				'group'  => array(
					'type'        => 'string',
					'enum'        => $groups,
					'description' => __( 'Return only settings that belong to this settings group. If omitted, settings of every group are returned.' ),
				),
				'fields' => array(
					'type'        => 'array',
					'uniqueItems' => true,
					'items'       => array(
						'type' => 'string',
						'enum' => $field_names,
					),
					'description' => __( 'Return only the settings with these names. If omitted or empty, all settings allowed by the group filter are returned. When both filters are given, only settings matching both are returned.' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Builds the output schema for the `core/settings-get` ability.
	 *
	 * No setting is marked required because the `group` and `fields` inputs let the caller
	 * request any subset, and a setting whose value does not match its schema is left out.
	 *
	 * @since 7.2.0
	 *
	 * @return array<string, mixed> The output JSON Schema.
	 */
	private function get_settings_get_output_schema(): array {
		return array(
			'type'                 => 'object',
			'description'          => __( 'A map of setting name to its current value.' ),
			'properties'           => wp_list_pluck( $this->exposed_settings, 'schema' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the settings exposed through the Abilities API.
	 *
	 * Reads {@see get_registered_settings()} and keeps only settings flagged with a truthy
	 * `show_in_abilities` argument, of a type the settings endpoint supports. Each entry is
	 * keyed by its exposed name and carries the underlying option name, the settings group,
	 * the registered default (null when there is none), and a JSON Schema describing the value.
	 *
	 * Two settings exposed under the same name trigger a notice, and the later one is exposed.
	 *
	 * @since 7.2.0
	 *
	 * @return array<string, array{option: string, group: string, default: mixed, schema: array<string, mixed>}> Settings keyed by exposed name.
	 */
	private function get_exposed_settings(): array {
		$settings = array();

		foreach ( get_registered_settings() as $option_name => $args ) {
			if ( empty( $args['show_in_abilities'] ) ) {
				continue;
			}

			$show = $this->get_exposure_args( $args );

			$schema = $this->value_schema( $args, $show );
			if ( ! in_array( $schema['type'], array( 'number', 'integer', 'string', 'boolean', 'array', 'object' ), true ) ) {
				continue;
			}

			$option_name  = (string) $option_name;
			$exposed_name = empty( $show['name'] ) ? $option_name : (string) $show['name'];

			if ( isset( $settings[ $exposed_name ] ) ) {
				_doing_it_wrong(
					__METHOD__,
					sprintf(
						/* translators: 1: Option name, 2: Setting name exposed to abilities, 3: Option name. */
						__( 'The "%1$s" setting is exposed to abilities as "%2$s", a name already used by the "%3$s" setting, which is no longer exposed.' ),
						esc_html( $option_name ),
						esc_html( $exposed_name ),
						esc_html( $settings[ $exposed_name ]['option'] )
					),
					'7.2.0'
				);
			}

			$settings[ $exposed_name ] = array(
				'option'  => $option_name,
				'group'   => $args['group'] ?? '',
				'default' => $args['default'] ?? null,
				'schema'  => $schema,
			);
		}

		return $settings;
	}

	/**
	 * Returns the name and schema overrides used to expose a setting to abilities.
	 *
	 * When `show_in_abilities` is `true`, the setting is exposed the same way as in the
	 * REST API: it uses the `name` and `schema` from `show_in_rest`. An array is used
	 * as is.
	 *
	 * @since 7.2.0
	 *
	 * @param array<string, mixed> $args The setting registration arguments.
	 * @return array<string, mixed> The exposure arguments, with optional `name` and `schema` keys.
	 */
	private function get_exposure_args( array $args ): array {
		if ( is_array( $args['show_in_abilities'] ) ) {
			return $args['show_in_abilities'];
		}

		return is_array( $args['show_in_rest'] ) ? $args['show_in_rest'] : array();
	}

	/**
	 * Builds the JSON Schema describing a single setting's value.
	 *
	 * As in the settings endpoint, objects in the schema reject properties they do not declare,
	 * unless the schema allows them.
	 *
	 * @since 7.2.0
	 *
	 * @param array<string, mixed> $args The setting registration arguments.
	 * @param array<string, mixed> $show The exposure arguments, see get_exposure_args().
	 * @return array<string, mixed> The value JSON Schema.
	 */
	private function value_schema( array $args, $show ): array {
		$schema = array(
			'type' => $args['type'],
		);
		if ( ! empty( $args['label'] ) ) {
			$schema['title'] = $args['label'];
		}
		if ( ! empty( $args['description'] ) ) {
			$schema['description'] = $args['description'];
		}
		if ( isset( $show['schema'] ) && is_array( $show['schema'] ) ) {
			/** @var array<string, mixed> $show_schema */
			$show_schema = $show['schema'];
			$schema      = array_merge( $schema, $show_schema );
		}

		return rest_default_additional_properties_to_false( $schema );
	}
}
