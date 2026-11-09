<?php
/**
 * Canonical WordPress abilities: `wp/settings-update` ability.
 *
 * Ported from WordPress/ai PR #764 (https://github.com/WordPress/ai/pull/764) by @jorgefilipecosta.
 *
 * @package WordPress\Abilities
 */
declare( strict_types = 1 );

namespace WordPress\Abilities\Settings;

use WP_Error;

use WordPress\Abilities\Abstract_Core_Ability;

/**
 * Registers the `wp/settings-update` ability, which writes the settings
 * `core/settings-get` reads (except the read-only `url` and `email`), and
 * answers with only the updated settings as `core/settings-get` reads them.
 *
 * Upstream keeps the two settings abilities in one class because they share
 * state: the exposed-settings snapshot, computed once at registration. This
 * package ships one class per ability; the shared snapshot and read-back
 * logic therefore live here as a self-contained copy of this package's
 * `Settings_Get` port (a `private`-to-`protected` relaxation there would
 * break the coreify round-trip gate, which diffs the backported class against
 * the tracked core-trunk fixture), alongside the write logic ported from the
 * PR.
 *
 * The companion PR-side changes to `core/settings-get` (endpoint setting
 * names, sanitizing each stored value against its schema and leaving out
 * schema-rejected ones, never-saved settings reading as their registered
 * default, and the `''`-stored boolean reading as `false`) are already
 * present in this package's `core/settings-get` backport (PR #2), which
 * matches core 7.2's landed version; nothing was ported for them.
 *
 * @package WordPress\Abilities
 */
final class Settings_Update extends Abstract_Core_Ability {

	/**
	 * The ability category, as the sibling settings abilities register.
	 *
	 * @var string
	 */
	private const CATEGORY = 'site';

	/**
	 * Options `core/settings-get` reads but `wp/settings-update` does not write.
	 *
	 * A wrong `siteurl` makes wp-admin unreachable, and wp-admin only changes `admin_email` once
	 * the new address confirms it.
	 *
	 * @var list<string>
	 */
	private const READ_ONLY_OPTIONS = array( 'siteurl', 'admin_email' );

	/**
	 * Settings exposed through the Abilities API, computed once at
	 * registration by prepare(). A self-contained copy of the sibling
	 * settings-get's snapshot (see the class docblock for why it is a copy).
	 *
	 * @var array<string, array{option: string, group: string, default: mixed, schema: array<string, mixed>}>
	 */
	private $exposed_settings = array();

	/**
	 * The canonical ability name, under the experimental tier prefix.
	 *
	 * @return string
	 */
	protected function ability_name(): string {
		return 'wp/settings-update';
	}

	/**
	 * The ability label, as the source PR registers it.
	 *
	 * @return string
	 */
	protected function label(): string {
		return __( 'Update Settings' );
	}

	/**
	 * The ability description, as the source PR registers it.
	 *
	 * @return string
	 */
	protected function description(): string {
		return __( 'Updates WordPress settings exposed to abilities, except url and email. Accepts a map of setting name to its new value. For a setting that has a default, null resets the setting to that default. Returns the updated settings with their values after the update; a setting whose value does not match its schema is left out, as in core/settings-get.' );
	}

	/**
	 * The ability category, as the sibling settings abilities register.
	 *
	 * @return string
	 */
	protected function category_slug(): string {
		return self::CATEGORY;
	}

	/**
	 * The input JSON Schema, as the source PR builds it when registering: a map
	 * of each writable setting name to its update schema (which accepts null
	 * for a setting with a registered default).
	 *
	 * @return array<string, mixed> The input JSON Schema.
	 */
	protected function input_schema(): array {
		return array(
			'type'                 => 'object',
			'description'          => __( 'A map of setting name to the new value to store, or to null to reset a setting that has a default to that default. At least one setting is required.' ),
			'properties'           => $this->get_writable_input_properties(),
			'minProperties'        => 1,
			'additionalProperties' => false,
		);
	}

	/**
	 * The output JSON Schema: a map of each updated setting's name to its value
	 * after the update. No property count floor: an update whose only setting
	 * cannot be read back (no registered default, schema-rejected value)
	 * answers an empty object.
	 *
	 * @return array<string, mixed> The output JSON Schema.
	 */
	protected function output_schema(): array {
		return array(
			'type'                 => 'object',
			'description'          => __( 'A map of each updated setting name to its value after the update.' ),
			'properties'           => $this->get_writable_output_properties(),
			'additionalProperties' => false,
		);
	}

	/**
	 * The execute callback, as the source PR registers it.
	 *
	 * @return callable(mixed): (array<string, mixed>|\stdClass|WP_Error) The execute callback.
	 */
	protected function make_execute_callback(): callable {
		return function ( $input = array() ) {
			return $this->execute_settings_update( $input );
		};
	}

	/**
	 * No permission callback, as the sibling settings abilities register.
	 *
	 * The `manage_options` floor from required_capabilities() owns authorization,
	 * as the package's `core/settings-get` registers it (the source PR checks the
	 * same capability in its permission callback).
	 *
	 * @return null No permission callback.
	 */
	protected function make_permission_callback(): ?callable {
		return null;
	}

	/**
	 * The authored ability meta, exactly as the source PR registers it.
	 *
	 * @return array<string, mixed> The ability meta.
	 */
	protected function ability_meta(): array {
		return array(
			'annotations'  => array(
				'readonly'    => false,
				// Overwritten values are not kept.
				'destructive' => true,
				// Repeating an update changes nothing more, but destructive idempotent
				// abilities are served over DELETE, which cannot carry null.
				'idempotent'  => false,
			),
			'public'       => true,
			'show_in_rest' => true,
		);
	}

	/**
	 * The request-level capability floor, as the sibling settings abilities
	 * register (the source PR checks `manage_options` in its permission
	 * callback).
	 *
	 * @return list<string> The required capabilities.
	 */
	protected function required_capabilities(): array {
		return array( 'manage_options' );
	}

	/**
	 * Takes the exposed-settings snapshot, as the sibling settings ability's
	 * register() does before registering.
	 */
	protected function prepare(): void {
		$this->exposed_settings = $this->get_exposed_settings();
	}

	/**
	 * Only registers when at least one exposed setting is writable.
	 *
	 * The source PR skips the update ability when all the exposed settings are
	 * read-only (as on a multisite, where only `siteurl` and `admin_email`
	 * carry those option names): the input properties would be empty, and the
	 * `minProperties: 1` floor would reject every call. The read-only settings
	 * (`url` and `email`) remain readable through `core/settings-get`, which
	 * registers as before.
	 *
	 * @return bool True when at least one exposed setting is writable.
	 */
	public function should_register(): bool {
		$writable = false;

		foreach ( $this->exposed_settings as $setting ) {
			if ( ! in_array( $setting['option'], self::READ_ONLY_OPTIONS, true ) ) {
				$writable = true;
				break;
			}
		}

		return ! empty( $this->exposed_settings ) && $writable;
	}

	/**
	 * Builds the writable settings' input properties.
	 *
	 * @return array<string, array<string, mixed>> Input properties keyed by setting name.
	 */
	private function get_writable_input_properties(): array {
		$properties = array();

		foreach ( $this->exposed_settings as $exposed_name => $setting ) {
			if ( in_array( $setting['option'], self::READ_ONLY_OPTIONS, true ) ) {
				continue;
			}

			$properties[ $exposed_name ] = $this->update_value_schema( $setting );
		}

		return $properties;
	}

	/**
	 * Builds the writable settings' output properties (the answer holds only
	 * updated settings, so it never has a read-only one).
	 *
	 * @return array<string, array<string, mixed>> Output properties keyed by setting name.
	 */
	private function get_writable_output_properties(): array {
		$properties = array();

		foreach ( $this->exposed_settings as $exposed_name => $setting ) {
			if ( in_array( $setting['option'], self::READ_ONLY_OPTIONS, true ) ) {
				continue;
			}

			$properties[ $exposed_name ] = $setting['schema'];
		}

		return $properties;
	}

	/**
	 * Returns the settings exposed through the Abilities API.
	 *
	 * A verbatim copy of this package's `Settings_Get` port (kept here because
	 * `Settings_Get` must stay byte-identical to the coreify round-trip gate's
	 * tracked fixture): reads {@see get_registered_settings()} and keeps only
	 * settings flagged with a truthy `show_in_abilities` argument, of a type
	 * the settings endpoint supports. Each entry is keyed by its exposed name
	 * and carries the underlying option name, the settings group, the
	 * registered default (null when there is none), and a JSON Schema
	 * describing the value.
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
					'0.1.0'
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
	 * Executes the `core/settings-get` read path, as the sibling settings-get
	 * ability answers: a verbatim self-contained copy of this package's
	 * `Settings_Get` port (see get_exposed_settings()). Used by the write
	 * update's answer, which reads back only the updated settings.
	 *
	 * @param mixed $input Optional. The input: `fields` names a subset.
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
	 * Builds the JSON Schema a new value of a setting is validated against.
	 *
	 * A setting with a registered default also accepts null, which resets the setting to that
	 * default. Unlike in the settings endpoint, a setting without a default does not, since it has
	 * no default to reset to.
	 *
	 * @param array<string, mixed> $setting The exposed setting.
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

	/**
	 * Executes the `wp/settings-update` ability.
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
	 * @param mixed $input The ability input: a map of exposed setting name to its new value.
	 * @return array<string, mixed>|\stdClass|\WP_Error Map of each updated setting name to its value after
	 *                                                  the update, an empty object when none can be read back,
	 *                                                  or a WP_Error.
	 */
	private function execute_settings_update( $input = array() ) {
		$input = rest_sanitize_object( $input );

		$options         = array();
		$invalid_params  = array();
		$invalid_details = array();
		$invalid_stored  = '';
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
				 * such values out instead. Unlike the endpoint, which passes false as the default
				 * and so refuses null for a setting with no stored value, a missing value reads as
				 * the registered default, as both answer it, so null can reset such a setting. The
				 * endpoint checks this while writing; checking it here keeps the earlier settings
				 * in the input from being written when the update fails.
				 */
				$stored = get_option( $args['option_name'] );

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
			$invalid = is_wp_error( $args['value'] ) ? $args['value'] : rest_validate_value_from_schema( $args['value'], $this->update_value_schema( $setting ), $name );
			if ( is_wp_error( $invalid ) ) {
				$invalid_params[ $name ]  = implode( ' ', $invalid->get_error_messages() );
				$invalid_details[ $name ] = rest_convert_error_to_response( $invalid )->get_data();
				continue;
			}

			$options[ $name ] = $args;
		}

		if ( $invalid_params ) {
			return new WP_Error(
				'settings_invalid_param',
				/* translators: %s: List of invalid parameters. */
				sprintf( __( 'Invalid parameter(s): %s' ), implode( ', ', array_keys( $invalid_params ) ) ),
				array(
					'status'  => 400,
					'params'  => $invalid_params,
					'details' => $invalid_details,
				)
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
				__( 'Sorry, you are not allowed to manage privacy options on this site.' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		if ( '' !== $invalid_stored ) {
			return new WP_Error(
				'settings_invalid_stored_value',
				/* translators: %s: Property name. */
				sprintf( __( 'The %s property has an invalid stored value, and cannot be updated to null.' ), $invalid_stored ),
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

			/*
			 * update_option() stores nothing when no value is stored and the new one matches the
			 * registered default. Store only that value past it, so a value it refused, such as
			 * one a `pre_update_option_{$option}` filter turned back, stays unstored.
			 */
			$default = get_registered_settings()[ $args['option_name'] ]['default'] ?? null;
			if ( $default !== $args['value'] || false !== get_option( $args['option_name'], false ) ) {
				continue;
			}

			add_option( $args['option_name'], $args['value'] );
		}

		/*
		 * Read back only the updated settings, since an empty `fields` list means every setting.
		 * PHP turns a numeric setting name into an integer key, while `fields` takes strings.
		 */
		$updated = $options ? $this->execute_settings_get( array( 'fields' => array_map( 'strval', array_keys( $options ) ) ) ) : array();

		// Object (not array()) so an answer with no setting is serialized as {}, consistent with type:object.
		return empty( $updated ) ? (object) array() : $updated;
	}
}
