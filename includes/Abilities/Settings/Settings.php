<?php
/**
 * The `core/settings-get` and `core/settings-update` WordPress Abilities.
 *
 * @package WordPress\AI
 *
 * @since 1.1.0
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Settings;

use WP_Error;

use function WordPress\AI\register_deprecated_ability_alias;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Settings
 *
 * Registers the read-only `core/settings-get` ability, which returns WordPress settings as a
 * flat map of setting name to value. Only settings flagged with `show_in_abilities` are
 * exposed.
 *
 * Also registers `core/settings-update`, which writes the same settings the way the settings
 * endpoint updates them, and answers with the updated settings as `core/settings-get` reads them.
 *
 * The exposed settings are captured when the ability registers on `wp_abilities_api_init`.
 * That hook fires lazily on first use of the abilities registry, which is not ordered
 * relative to `rest_api_init` (where core registers its own settings) and can happen
 * without it entirely, e.g. on cron or WP-CLI. register() therefore ensures core's
 * initial settings are registered before the snapshot is computed. Other plugin settings
 * flagged with `show_in_abilities` must be registered before the abilities registry is
 * first used in a request; registering them on `init` is reliable.
 *
 * This class is kept almost identical to the WordPress core class `WP_Settings_Abilities`
 * so the two implementations stay in sync. Differences from the core class are marked with
 * `// Plugin:` comments. Additionally, all user-facing strings use the 'ai' text domain.
 * The changes that come with `core/settings-update` are not part of the core class yet, so
 * they carry no markers.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since 1.1.0
 */
final class Settings {

	/**
	 * The ability category used for settings abilities.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	private const CATEGORY = 'site';

	/**
	 * Options `core/settings-get` reads but `core/settings-update` does not write, for now.
	 *
	 * A wrong `siteurl` makes wp-admin unreachable, and wp-admin only changes `admin_email` once
	 * the new address confirms it.
	 *
	 * @since x.x.x
	 * @var string[]
	 */
	private const READ_ONLY_OPTIONS = array( 'siteurl', 'admin_email' ); // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is used as an array const.

	/**
	 * Settings exposed through the Abilities API, computed once at registration.
	 *
	 * Plugin: cached so the input/output schema and the executed result derive from the exact
	 * same structure, and {@see get_registered_settings()} is only walked once per request.
	 *
	 * @since 1.1.0
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
	 * @since 1.1.0
	 */
	public function init(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register' ), 11 );
	}

	/**
	 * Registers all settings abilities.
	 *
	 * Must run on the `wp_abilities_api_init` hook. Registers nothing when no setting is
	 * exposed to abilities.
	 *
	 * @since 1.1.0
	 * @since 1.2.0 Ensures core's initial settings are registered before taking the snapshot.
	 * @since 1.4.0 Preserves $new_allowed_options to prevent polluting options.php form handling.
	 * @since x.x.x Registers `core/settings-update`, and registers nothing when no setting is exposed.
	 */
	public function register(): void {
		/*
		 * Core's initial settings register on `rest_api_init`, which fires lazily and
		 * independently of `wp_abilities_api_init`: on cron, WP-CLI, or any request where
		 * abilities are used before the REST server loads, it may not have fired — or may
		 * be mid-fire at a priority before register_initial_settings() runs. Ensure the
		 * core settings exist before the exposed-settings snapshot below is computed;
		 * re-registering them again later on `rest_api_init` is harmless.
		 */
		if ( ! did_action( 'rest_api_init' ) || doing_action( 'rest_api_init' ) ) {
			$prev_new_allowed_options = $GLOBALS['new_allowed_options'] ?? null;

			register_initial_settings();

			// Restore $new_allowed_options so early registration doesn't pollute options.php.
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the WordPress core global to its state before register_initial_settings().
			$GLOBALS['new_allowed_options'] = $prev_new_allowed_options;
		}

		// Compute once; the schemas and execute callbacks of both abilities reuse this exact structure.
		$this->exposed_settings = $this->get_exposed_settings();
		if ( empty( $this->exposed_settings ) ) {
			return;
		}

		$this->register_get_settings();
		$this->register_update_settings();
	}

	/**
	 * Registers the read-only `core/settings-get` ability.
	 *
	 * Also registers `core/read-settings` as a deprecated alias.
	 *
	 * @since 1.1.0
	 * @since 1.4.0 Renamed from `core/read-settings`.
	 */
	private function register_get_settings(): void {
		// Plugin: unregister any core-provided copy first so the plugin's version wins.
		if ( wp_has_ability( 'core/settings-get' ) ) {
			wp_unregister_ability( 'core/settings-get' );
		}

		$settings    = (array) $this->exposed_settings;
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
			'core/settings-get',
			array(
				'label'               => __( 'Settings Get', 'ai' ),
				'description'         => __( 'Returns WordPress settings as a flat map of setting name to value. By default returns all settings exposed to abilities, or optionally a subset filtered by settings group, by setting name, or both. A setting whose value does not match its schema is left out.', 'ai' ),
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
					'public'       => true,
					'show_in_rest' => true,
				),
			)
		);

		// @todo Remove the alias after a few releases.
		register_deprecated_ability_alias( 'core/read-settings', 'core/settings-get', '1.4.0' );
	}

	/**
	 * Registers the `core/settings-update` ability.
	 *
	 * Every setting `core/settings-get` reads is writable except `siteurl` and `admin_email`.
	 * Unlike the settings endpoint, which answers an update with the whole settings object, the
	 * ability answers with only the updated settings, as `core/settings-get` reads them. Not
	 * registered when none of the exposed settings is writable.
	 *
	 * @since x.x.x
	 */
	private function register_update_settings(): void {
		// Unregister any core-provided copy first so the plugin's version wins.
		if ( wp_has_ability( 'core/settings-update' ) ) {
			wp_unregister_ability( 'core/settings-update' );
		}

		$input_properties  = array();
		$output_properties = array();
		foreach ( (array) $this->exposed_settings as $exposed_name => $setting ) {
			$output_properties[ $exposed_name ] = $setting['schema'];

			if ( in_array( $setting['option'], self::READ_ONLY_OPTIONS, true ) ) {
				continue;
			}

			$input_properties[ $exposed_name ] = $this->update_value_schema( $setting['schema'] );
		}

		// With no writable setting, `minProperties` would reject every input.
		if ( empty( $input_properties ) ) {
			return;
		}

		wp_register_ability(
			'core/settings-update',
			array(
				'label'               => __( 'Settings Update', 'ai' ),
				'description'         => __( 'Updates WordPress settings exposed to abilities, except `siteurl` and `admin_email`. Accepts a map of setting name to its new value, where null deletes the stored value so the setting falls back to its default. Returns the updated settings with their values after the update; a setting whose value does not match its schema is left out, as in `core/settings-get`.', 'ai' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'description'          => __( 'A map of setting name to the new value to store, or to null to delete the stored value. At least one setting is required.', 'ai' ),
					'properties'           => $input_properties,
					'minProperties'        => 1,
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'description'          => __( 'A map of each updated setting name to its value after the update.', 'ai' ),
					'properties'           => $output_properties,
					'additionalProperties' => false,
				),
				'execute_callback'    => array( $this, 'execute_update_settings' ),
				'permission_callback' => array( $this, 'has_permission' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						// Overwritten values are not kept, and null deletes the stored value.
						'destructive' => true,
						// A repeated null can fail once the stored value is gone, as in the settings
						// endpoint, and destructive idempotent abilities are served over DELETE,
						// which cannot carry null.
						'idempotent'  => false,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Executes the `core/settings-get` ability.
	 *
	 * @since 1.1.0
	 * @since x.x.x Leaves out a value its schema rejects.
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed> Map of exposed setting name to current value.
	 */
	public function execute_get_settings( $input = array() ): array {
		$input = is_array( $input ) ? $input : array();

		$settings = $this->exposed_settings;
		if ( null === $settings ) {
			// The cache is populated in register() before the ability is
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
			$value = $this->cast_value( get_option( $setting['option'], $setting['default'] ), $type );

			/*
			 * Leave out a value its schema rejects instead of failing output validation for
			 * every setting; the settings endpoint answers null for it. A setting without
			 * a registered default that `core/settings-update` reset to null reads this way.
			 */
			if ( is_wp_error( rest_validate_value_from_schema( $value, $setting['schema'] ) ) ) {
				continue;
			}

			$result[ $exposed_name ] = $value;
		}

		return $result;
	}

	/**
	 * Executes the `core/settings-update` ability.
	 *
	 * Updates the settings as the settings endpoint does. The Abilities API has already rejected
	 * input with an unknown setting or an invalid value. Every value is then sanitized against
	 * its schema, as the endpoint sanitizes its parameters before the update runs, and every
	 * null is checked against the stored value, all before any setting is written, so an error
	 * leaves every setting unchanged. The settings are written in the order they were registered.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input The ability input: a map of exposed setting name to its new value.
	 * @return array<string, mixed>|\WP_Error Map of each updated setting name to its value after the update, or a WP_Error.
	 */
	public function execute_update_settings( $input = array() ) {
		$input = rest_sanitize_object( $input );

		$options        = array();
		$invalid_params = array();
		$invalid_stored = '';
		foreach ( (array) $this->exposed_settings as $name => $setting ) {
			if ( ! array_key_exists( $name, $input ) ) {
				continue;
			}

			$args = array(
				'option_name' => $setting['option'],
				'schema'      => rest_default_additional_properties_to_false( $setting['schema'] ),
				'value'       => $input[ $name ],
			);

			if ( is_null( $args['value'] ) ) {
				/*
				 * As in the settings endpoint, a stored value that does not pass validation
				 * cannot be updated to null. The endpoint returns such values as null, so this
				 * keeps a client that sends a response back from deleting them by mistake.
				 * The endpoint checks this while writing; checking it here keeps the earlier
				 * settings in the input from being written when the update fails.
				 */
				if ( '' === $invalid_stored && is_wp_error( rest_validate_value_from_schema( get_option( $args['option_name'], false ), $args['schema'] ) ) ) {
					$invalid_stored = $name;
				}
			} else {
				// The endpoint's sanitize callback keeps null as is, and sanitizes anything else.
				$args['value'] = rest_sanitize_value_from_schema( $args['value'], $args['schema'], $name );
			}

			if ( is_wp_error( $args['value'] ) ) {
				$invalid_params[] = $name;
				continue;
			}

			$options[ $name ] = $args;
		}

		if ( $invalid_params ) {
			return new WP_Error(
				'settings_invalid_param',
				/* translators: %s: List of invalid parameters. */
				sprintf( __( 'Invalid parameter(s): %s', 'ai' ), implode( ', ', $invalid_params ) ),
				array( 'status' => 400 )
			);
		}

		if ( '' !== $invalid_stored ) {
			return new WP_Error(
				'settings_invalid_stored_value',
				/* translators: %s: Property name. */
				sprintf( __( 'The %s property has an invalid stored value, and cannot be updated to null.', 'ai' ), $invalid_stored ),
				array( 'status' => 500 )
			);
		}

		foreach ( $options as $args ) {
			/*
			 * A null value for an option would have the same effect as
			 * deleting the option from the database, and relying on the
			 * default value.
			 */
			if ( is_null( $args['value'] ) ) {
				delete_option( $args['option_name'] );
			} else {
				update_option( $args['option_name'], $args['value'] );
			}
		}

		return $this->execute_get_settings( array( 'fields' => array_keys( $options ) ) );
	}

	/**
	 * Checks whether the current user may use the settings abilities.
	 *
	 * @since 1.1.0
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
	 * @since 1.1.0
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
	 * @since 1.1.0
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
	 * @since 1.1.0
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
	 * Builds the JSON Schema a new value of a setting is validated against.
	 *
	 * As in the settings endpoint, objects in the schema reject properties they do not
	 * declare unless the schema allows them, and every setting accepts null, which deletes
	 * the stored value.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $schema The setting's value schema.
	 * @return array<string, mixed> The JSON Schema for the new value.
	 */
	private function update_value_schema( array $schema ): array {
		$schema = rest_default_additional_properties_to_false( $schema );

		$schema['type'] = array_values( array_unique( array_merge( (array) $schema['type'], array( 'null' ) ) ) );
		if ( isset( $schema['enum'] ) && is_array( $schema['enum'] ) && ! in_array( null, $schema['enum'], true ) ) {
			$schema['enum'][] = null;
		}

		return $schema;
	}

	/**
	 * Casts a stored option value to the type declared in its settings registration.
	 *
	 * @since 1.1.0
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
