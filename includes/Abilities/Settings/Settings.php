<?php
/**
 * The `core/settings-get` WordPress Ability.
 *
 * @package WordPress\AI
 *
 * @since 1.1.0
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Settings;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Settings
 *
 * Registers the read-only `core/settings-get` ability, which returns WordPress settings as a
 * flat map of setting name to value. Only settings flagged with `show_in_abilities` are
 * exposed. It is structured to also back a future write-oriented `core/settings-update`
 * ability via the shared helpers (get_exposed_settings(), value_schema()).
 *
 * The exposed settings are captured when the ability registers on `wp_abilities_api_init`.
 * That hook fires lazily on first use of the abilities registry, which is not ordered
 * relative to `rest_api_init` (where core registers its own settings) and can happen
 * without it entirely, e.g. on cron or WP-CLI. register() therefore ensures core's
 * initial settings are registered before the snapshot is computed. Other plugin settings
 * flagged with `show_in_abilities` must be registered before the abilities registry is
 * first used in a request; registering them on `init` is reliable.
 *
 * This class is kept almost identical to the WordPress core class `WP_Abilities_Settings`
 * so the two implementations stay in sync. Differences from the core class are marked with
 * `// Plugin:` comments. Additionally, all user-facing strings use the 'ai' text domain.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since 1.1.0
 * @since x.x.x Synced with the WordPress core class `WP_Abilities_Settings`.
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
	 * Settings exposed through the Abilities API, computed once at registration.
	 *
	 * @since 1.1.0
	 * @var array<string, array{option: string, group: string, default: mixed, schema: array<string, mixed>}>
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
	 * @since x.x.x Registers nothing when no setting is exposed to abilities.
	 */
	public function register(): void {
		// Plugin: core does this in _wp_register_initial_settings_for_abilities(), hooked to
		// `wp_abilities_api_init` at priority 1.
		$this->register_initial_settings_for_abilities();

		// Plugin: unregister any core-provided copy first so the plugin's version wins.
		if ( wp_has_ability( 'core/settings-get' ) ) {
			wp_unregister_ability( 'core/settings-get' );
		}

		$this->exposed_settings = $this->get_exposed_settings();
		if ( empty( $this->exposed_settings ) ) {
			return;
		}

		$this->register_settings_get();
	}

	/**
	 * Registers the default settings when the Abilities API initializes.
	 *
	 * The default settings are registered on `rest_api_init`, which fires lazily and
	 * independently of `wp_abilities_api_init`: on cron, WP-CLI, or any request where
	 * abilities are used before the REST server loads, it may not have fired, or may be
	 * mid-fire at a priority before register_initial_settings() runs. This makes sure the
	 * settings exist before the abilities read them. Registering them again later on
	 * `rest_api_init` is harmless.
	 *
	 * Plugin: mirrors the core function `_wp_register_initial_settings_for_abilities()`.
	 *
	 * @since x.x.x
	 */
	private function register_initial_settings_for_abilities(): void {
		if ( did_action( 'rest_api_init' ) && ! doing_action( 'rest_api_init' ) ) {
			return;
		}

		$allowed_options = $GLOBALS['new_allowed_options'] ?? null;

		register_initial_settings();

		/*
		 * Registering a setting also allows it on the options screen of its group. Restore
		 * the list, so saving Settings > General does not try to save `admin_email`, which
		 * that screen sends as `new_admin_email`.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the WordPress core global to its state before register_initial_settings().
		$GLOBALS['new_allowed_options'] = $allowed_options;
	}

	/**
	 * Registers the read-only `core/settings-get` ability.
	 *
	 * @since 1.1.0
	 * @since 1.4.0 Renamed from `core/read-settings`.
	 */
	private function register_settings_get(): void {
		$groups = array_values( array_unique( array_filter( array_column( $this->exposed_settings, 'group' ) ) ) );

		wp_register_ability(
			'core/settings-get',
			array(
				'label'               => __( 'Get Settings', 'ai' ),
				'description'         => __( 'Returns WordPress settings as a flat map of setting name to value. By default returns all settings exposed to abilities, or optionally a subset filtered by settings group, by setting name, or both. A setting whose value does not match its schema is left out. Requires an authenticated user who can manage options.', 'ai' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->get_settings_get_input_schema( $groups, array_map( 'strval', array_keys( $this->exposed_settings ) ) ),
				'output_schema'       => $this->get_settings_get_output_schema(),
				'execute_callback'    => function ( $input = array() ): array {
					return $this->execute_settings_get( $input );
				},
				'permission_callback' => function (): bool {
					return $this->check_permission();
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'public'       => true,
					// Plugin: set explicitly so the ability is exposed over REST on all supported WordPress versions.
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Executes the `core/settings-get` ability.
	 *
	 * @since 1.1.0
	 * @since x.x.x Leaves out a setting whose value does not match its schema.
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
	 * Permission callback for the settings abilities.
	 *
	 * The settings abilities read site options, so they are gated on the `manage_options`
	 * capability regardless of the input.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if the current user can manage options.
	 */
	private function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Builds the input schema for the `core/settings-get` ability: optional filters by group and/or name.
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
	private function get_settings_get_input_schema( array $groups, array $field_names ): array {
		return array(
			'type'                 => 'object',
			'default'              => array(),
			'properties'           => array(
				'group'  => array(
					'type'        => 'string',
					'enum'        => $groups,
					'description' => __( 'Return only settings that belong to this settings group. If omitted, settings of every group are returned.', 'ai' ),
				),
				'fields' => array(
					'type'        => 'array',
					'uniqueItems' => true,
					'items'       => array(
						'type' => 'string',
						'enum' => $field_names,
					),
					'description' => __( 'Return only the settings with these names. If omitted or empty, all settings allowed by the group filter are returned. When both filters are given, only settings matching both are returned.', 'ai' ),
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
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The output JSON Schema.
	 */
	private function get_settings_get_output_schema(): array {
		return array(
			'type'                 => 'object',
			'description'          => __( 'A map of setting name to its current value.', 'ai' ),
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
	 * @since 1.1.0
	 * @since x.x.x A setting exposed with `true` uses its `show_in_rest` name and schema.
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
						esc_html__( 'The "%1$s" setting is exposed to abilities as "%2$s", a name already used by the "%3$s" setting, which is no longer exposed.', 'ai' ),
						esc_html( $option_name ),
						esc_html( $exposed_name ),
						esc_html( $settings[ $exposed_name ]['option'] )
					),
					'x.x.x'
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
	 * @since x.x.x Objects reject properties they do not declare, unless the schema allows them.
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
