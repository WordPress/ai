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
 * Also registers `core/settings-update`, which writes those settings, except `url` and
 * `email`, the way the settings endpoint updates them, and answers with the updated
 * settings as `core/settings-get` reads them.
 *
 * The exposed settings are captured when the abilities register, the first time the abilities
 * registry is used in a request. Settings registered later in that request are not exposed.
 * register() registers core's own settings first, so they are always in time.
 *
 * This class is kept almost identical to the WordPress core class `WP_Abilities_Settings`
 * so the two implementations stay in sync. Differences from the core class are marked with
 * `// Plugin:` comments. Additionally, all user-facing strings use the 'ai' text domain.
 * `core/settings-update` is not part of the core class yet, so its code carries no markers.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since 1.1.0
 */
final class Settings {

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
	 * @since 1.1.0
	 * @var array<string, array{option: string, group: string, schema: array<string, mixed>}>
	 */
	private $exposed_settings = array();

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

		$groups = array_values( array_unique( array_filter( array_column( $this->exposed_settings, 'group' ) ) ) );

		wp_register_ability(
			'core/settings-get',
			array(
				'label'               => __( 'Settings Get', 'ai' ),
				'description'         => __( 'Returns WordPress settings as a flat map of setting name to value. By default returns all settings exposed to abilities, or optionally a subset filtered by settings group, by setting name, or both. A setting whose value does not match its schema is left out.', 'ai' ),
				'category'            => 'site',
				'input_schema'        => $this->get_settings_input_schema( $groups, array_map( 'strval', array_keys( $this->exposed_settings ) ) ),
				'output_schema'       => array(
					'type'                 => 'object',
					'description'          => __( 'A map of setting name to its current value.', 'ai' ),
					'properties'           => wp_list_pluck( $this->exposed_settings, 'schema' ),
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
					// Plugin: core sets only public, which WordPress 7.0 does not read.
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
	 * Every setting `core/settings-get` reads is writable except `url` and `email`.
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
		foreach ( $this->exposed_settings as $exposed_name => $setting ) {
			if ( in_array( $setting['option'], self::READ_ONLY_OPTIONS, true ) ) {
				continue;
			}

			$input_properties[ $exposed_name ] = $this->update_value_schema( $setting );
			// The answer holds only updated settings, so it never has a read-only one.
			$output_properties[ $exposed_name ] = $setting['schema'];
		}

		// With no writable setting, `minProperties` would reject every input.
		if ( empty( $input_properties ) ) {
			return;
		}

		wp_register_ability(
			'core/settings-update',
			array(
				'label'               => __( 'Settings Update', 'ai' ),
				'description'         => __( 'Updates WordPress settings exposed to abilities, except url and email. Accepts a map of setting name to its new value. For a setting that has a default, null resets the setting to that default. Returns the updated settings with their values after the update; a setting whose value does not match its schema is left out, as in core/settings-get.', 'ai' ),
				'category'            => 'site',
				'input_schema'        => array(
					'type'                 => 'object',
					'description'          => __( 'A map of setting name to the new value to store, or to null to reset a setting that has a default to that default. At least one setting is required.', 'ai' ),
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
						// Overwritten values are not kept.
						'destructive' => true,
						// Repeating an update changes nothing more, but destructive idempotent
						// abilities are served over DELETE, which cannot carry null.
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
	 * @since x.x.x Sanitizes values against their schema, and leaves out a value the schema rejects
	 *              before or after sanitizing.
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed> Map of exposed setting name to current value.
	 */
	public function execute_get_settings( $input = array() ): array {
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

			$value = get_option( $setting['option'] );

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
	 * Executes the `core/settings-update` ability.
	 *
	 * Updates the settings as the settings endpoint does. The Abilities API has already rejected
	 * input with an unknown setting or an invalid value, but a `wp_ability_validate_input` filter
	 * can skip that validation, so read-only settings are skipped here too. These checks then run
	 * in order, all before any setting is written, so an error leaves every setting unchanged:
	 *
	 * 1. A value that fails sanitizing against its schema, or that the input schema refuses after
	 *    sanitizing, is refused with a 400 error. The endpoint sanitizes its parameters the same
	 *    way before the update runs.
	 * 2. A change to the privacy policy page is refused with a 403 error when the user cannot
	 *    manage privacy options.
	 * 3. A null is refused with a 500 error when the setting's stored value fails validation.
	 *
	 * The settings are then written in the order they were registered. A null stores the setting's
	 * registered default, where the endpoint only deletes the stored value.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input The ability input: a map of exposed setting name to its new value.
	 * @return array<string, mixed>|\stdClass|\WP_Error Map of each updated setting name to its value after
	 *                                                  the update, an empty object when none can be read back,
	 *                                                  or a WP_Error.
	 */
	public function execute_update_settings( $input = array() ) {
		$input = rest_sanitize_object( $input );

		$options        = array();
		$invalid_params = array();
		$invalid_stored = '';
		foreach ( $this->exposed_settings as $name => $setting ) {
			if ( ! array_key_exists( $name, $input ) || in_array( $setting['option'], self::READ_ONLY_OPTIONS, true ) ) {
				continue;
			}

			$args = array(
				'option_name' => $setting['option'],
				'schema'      => $setting['schema'],
				'value'       => $input[ $name ],
			);

			if ( is_null( $args['value'] ) ) {
				/*
				 * As in the settings endpoint, a stored value that does not pass validation
				 * cannot be updated to null. The endpoint answers such values as null, and the
				 * abilities share its setting names, so this keeps a client that sends an
				 * endpoint answer back from resetting them by mistake; core/settings-get leaves
				 * such values out instead. Since get_option() is passed false as the default,
				 * null can also be refused for a setting with no stored value. The endpoint checks
				 * this while writing; checking it here keeps the earlier settings in the input
				 * from being written when the update fails.
				 */
				$stored = get_option( $args['option_name'], false );

				// WordPress stores false as '', which core/settings-get reads as false. The endpoint refuses null for it.
				if ( '' === $stored && 'boolean' === $args['schema']['type'] ) {
					$stored = false;
				}

				if ( '' === $invalid_stored && is_wp_error( rest_validate_value_from_schema( $stored, $args['schema'] ) ) ) {
					$invalid_stored = $name;
				}
			} else {
				// The endpoint's sanitize callback keeps null as is, and sanitizes anything else.
				$args['value'] = rest_sanitize_value_from_schema( $args['value'], $args['schema'], $name );
			}

			/*
			 * Unlike the endpoint, also refuse a value that sanitizing makes invalid, which
			 * core/settings-get would leave out. Checking it against the input schema also refuses
			 * a null for a setting without a default when validation was skipped.
			 */
			if ( is_wp_error( $args['value'] ) || is_wp_error( rest_validate_value_from_schema( $args['value'], $this->update_value_schema( $setting ) ) ) ) {
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

		/*
		 * As in the settings endpoint, only users who can manage privacy options may change the
		 * privacy policy page; on multisite, only network administrators can. The endpoint skips
		 * the setting without an error, while the ability refuses the whole update.
		 */
		if ( in_array( 'wp_page_for_privacy_policy', array_column( $options, 'option_name' ), true ) && ! current_user_can( 'manage_privacy_options' ) ) {
			return new WP_Error(
				'settings_cannot_manage_privacy_options',
				__( 'Sorry, you are not allowed to manage privacy options on this site.', 'ai' ),
				array( 'status' => rest_authorization_required_code() )
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
			if ( is_null( $args['value'] ) ) {
				/*
				 * Delete the stored value, as the settings endpoint does, then store the registered
				 * default: a default only applies in requests that register the setting, and core
				 * registers its own settings only in REST and abilities requests, so other requests
				 * would read a deleted value as false. The delete comes first because
				 * sanitize_option() turns a language that is not installed, such as the en_US
				 * default, into the current value, which after the delete is the default.
				 */
				delete_option( $args['option_name'] );
				add_option( $args['option_name'], get_registered_settings()[ $args['option_name'] ]['default'] );
				continue;
			}

			update_option( $args['option_name'], $args['value'] );

			// update_option() stores nothing when no value is stored and the new one matches the registered default.
			if ( false !== get_option( $args['option_name'], false ) ) {
				continue;
			}

			add_option( $args['option_name'], $args['value'] );
		}

		/*
		 * Read back only the updated settings, since an empty `fields` list means every setting.
		 * PHP turns a numeric setting name into an integer key, while `fields` takes strings.
		 */
		$updated = $options ? $this->execute_get_settings( array( 'fields' => array_map( 'strval', array_keys( $options ) ) ) ) : array();

		// Object (not array()) so an answer with no setting is serialized as {}, consistent with type:object.
		return empty( $updated ) ? (object) array() : $updated;
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
			'default'              => array(),
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
	 * `show_in_abilities` argument, of a type the settings endpoint supports. Each entry is
	 * keyed by its exposed name and carries the underlying option name, the settings group,
	 * and a JSON Schema describing the value.
	 *
	 * @since 1.1.0
	 * @since x.x.x Leaves out settings of a type the settings endpoint does not support, and
	 *              exposes a setting flagged with `true` as the REST API does.
	 *
	 * @return array<string, array{option: string, group: string, schema: array<string, mixed>}> Settings keyed by exposed name.
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

			$option_name = (string) $option_name;

			// Plugin: a name that is not a string falls back to the option name, where core fails on it as an array key.
			$settings[ empty( $show['name'] ) || ! is_string( $show['name'] ) ? $option_name : $show['name'] ] = array(
				'option' => $option_name,
				'group'  => $args['group'] ?? '',
				'schema' => $schema,
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
	 * @since x.x.x
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
	 * @since 1.1.0
	 * @since x.x.x Objects in the schema reject properties they do not declare.
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

	/**
	 * Builds the JSON Schema a new value of a setting is validated against.
	 *
	 * A setting with a registered default also accepts null, which resets the setting to that
	 * default. Unlike in the settings endpoint, a setting without a default does not, since it has
	 * no default to reset to.
	 *
	 * @since x.x.x
	 *
	 * @param array{option: string, group: string, schema: array<string, mixed>} $setting The exposed setting.
	 * @return array<string, mixed> The JSON Schema for the new value.
	 */
	private function update_value_schema( array $setting ): array {
		$schema = $setting['schema'];
		if ( ! isset( get_registered_settings()[ $setting['option'] ]['default'] ) ) {
			return $schema;
		}

		$schema['type'] = array( $schema['type'], 'null' );
		// rest_validate_value_from_schema() ignores an empty enum, which would allow only null with null added.
		if ( ! empty( $schema['enum'] ) && is_array( $schema['enum'] ) && ! in_array( null, $schema['enum'], true ) ) {
			$schema['enum'][] = null;
		}

		return $schema;
	}
}
