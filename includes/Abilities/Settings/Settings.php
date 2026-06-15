<?php
/**
 * The `core/settings` WordPress Ability.
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
 * Registers the read-only `core/settings` ability, which returns WordPress settings as a
 * flat map of setting name to value. Only settings flagged with `show_in_abilities` are
 * exposed. It is structured to also back a future write-oriented `core/manage-settings`
 * ability via the shared helpers (get_exposed_settings(), value_schema(), cast_value()).
 *
 * This class is kept almost identical to the WordPress core class `WP_Settings_Abilities`
 * so the two implementations stay in sync. Differences from the core class are marked with
 * `// Plugin:` comments. Additionally, all user-facing strings use esc_html__() with the
 * 'ai' text domain rather than core's __().
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since 1.1.0
 */
class Settings {

	/**
	 * The ability category used for settings abilities.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	public const CATEGORY = 'site';

	/**
	 * Hooks the ability into the Abilities API.
	 *
	 * Plugin: this method has no equivalent in the core class. In core, register() is
	 * invoked directly from wp_register_core_abilities() (already on the
	 * `wp_abilities_api_init` hook). The plugin instead hooks register() slightly later
	 * (priority 11) so it can override any core-provided copy, and registers the category
	 * as a fallback in case core has not.
	 *
	 * @since 1.1.0
	 */
	public static function init(): void {
		add_action( 'wp_abilities_api_categories_init', array( self::class, 'register_category' ), 11 );
		add_action( 'wp_abilities_api_init', array( self::class, 'register' ), 11 );
	}

	/**
	 * Registers the `site` ability category if it is not already registered.
	 *
	 * Plugin: this method has no equivalent in the core class; core relies on
	 * wp_register_core_ability_categories() to register the `site` category.
	 *
	 * @since 1.1.0
	 */
	public static function register_category(): void {
		if ( wp_has_ability_category( self::CATEGORY ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => esc_html__( 'Site', 'ai' ),
				'description' => esc_html__( 'Abilities that retrieve or modify site information and settings.', 'ai' ),
			)
		);
	}

	/**
	 * Registers all settings abilities.
	 *
	 * Must run on the `wp_abilities_api_init` hook.
	 *
	 * @since 1.1.0
	 */
	public static function register(): void {
		self::register_get_settings();

		/*
		 * A future write-oriented ability can be registered here, reusing the shared
		 * helpers below (get_exposed_settings(), value_schema(), cast_value()):
		 *
		 *     self::register_manage_settings();
		 */
	}

	/**
	 * Registers the read-only `core/settings` ability.
	 *
	 * @since 1.1.0
	 */
	public static function register_get_settings(): void {
		// Plugin: unregister any core-provided copy first so the plugin's version wins.
		if ( wp_has_ability( 'core/settings' ) ) {
			wp_unregister_ability( 'core/settings' );
		}

		$settings      = self::get_exposed_settings();
		$groups        = array_values( array_unique( array_filter( wp_list_pluck( $settings, 'group' ) ) ) );
		$setting_names = array_keys( $settings );
		$properties    = array();
		foreach ( $settings as $exposed_name => $setting ) {
			$properties[ $exposed_name ] = $setting['schema'];
		}

		wp_register_ability(
			'core/settings',
			array(
				'label'               => esc_html__( 'Get Settings', 'ai' ),
				'description'         => esc_html__( 'Returns WordPress settings as a flat map of setting name to value. By default returns all settings exposed to abilities, or optionally a subset filtered by settings group or by setting name.', 'ai' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::get_settings_input_schema( $groups, $setting_names ),
				'output_schema'       => array(
					'type'                 => 'object',
					'description'          => esc_html__( 'A map of setting name to its current value.', 'ai' ),
					'properties'           => $properties,
					'additionalProperties' => false,
				),
				'execute_callback'    => array( self::class, 'execute_get_settings' ),
				'permission_callback' => array( self::class, 'has_permission' ),
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
	 * Executes the `core/settings` ability.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed> Map of exposed setting name to current value.
	 */
	public static function execute_get_settings( $input = array() ): array {
		$input = is_array( $input ) ? $input : array();

		$settings = self::get_exposed_settings();
		$group    = isset( $input['group'] ) ? (string) $input['group'] : '';
		$names    = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : array();

		$result = array();
		foreach ( $settings as $exposed_name => $setting ) {
			if ( '' !== $group && $setting['group'] !== $group ) {
				continue;
			}
			if ( ! empty( $names ) && ! in_array( $exposed_name, $names, true ) ) {
				continue;
			}

			$type  = isset( $setting['schema']['type'] ) ? (string) $setting['schema']['type'] : 'string';
			$value = get_option( $setting['option'], $setting['default'] );

			$result[ $exposed_name ] = self::cast_value( $value, $type );
		}

		return $result;
	}

	/**
	 * Checks whether the current user may use the settings abilities.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if the current user can manage options.
	 */
	public static function has_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Builds the input schema for the get ability: filter by group XOR by name.
	 *
	 * @since 1.1.0
	 *
	 * @param string[] $groups        Available settings groups.
	 * @param string[] $setting_names Available exposed setting names.
	 * @return array<string, mixed> The input JSON Schema.
	 */
	protected static function get_settings_input_schema( array $groups, array $setting_names ): array {
		return array(
			'type'    => 'object',
			'default' => array(),
			// Filter by group OR by name, but not both at once.
			'oneOf'   => array(
				array(
					'title'                => esc_html__( 'All settings', 'ai' ),
					'type'                 => 'object',
					'additionalProperties' => false,
				),
				array(
					'title'                => esc_html__( 'Filter by group', 'ai' ),
					'type'                 => 'object',
					'required'             => array( 'group' ),
					'properties'           => array(
						'group' => array(
							'type'        => 'string',
							'enum'        => $groups,
							'description' => esc_html__( 'Return only settings that belong to this settings group.', 'ai' ),
						),
					),
					'additionalProperties' => false,
				),
				array(
					'title'                => esc_html__( 'Filter by name', 'ai' ),
					'type'                 => 'object',
					'required'             => array( 'settings' ),
					'properties'           => array(
						'settings' => array(
							'type'        => 'array',
							'items'       => array(
								'type' => 'string',
								'enum' => $setting_names,
							),
							'description' => esc_html__( 'Return only the settings with these names.', 'ai' ),
						),
					),
					'additionalProperties' => false,
				),
			),
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
	protected static function get_exposed_settings(): array {
		$settings = array();

		foreach ( get_registered_settings() as $option_name => $args ) {
			$show = $args['show_in_abilities'] ?? false;
			if ( empty( $show ) ) {
				continue;
			}

			$option_name  = (string) $option_name;
			$exposed_name = is_array( $show ) && ! empty( $show['name'] ) ? (string) $show['name'] : $option_name;

			$settings[ $exposed_name ] = array(
				'option'  => $option_name,
				'group'   => isset( $args['group'] ) ? (string) $args['group'] : '',
				'default' => array_key_exists( 'default', $args ) ? $args['default'] : false,
				'schema'  => self::value_schema( $args, $show ),
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
	protected static function value_schema( array $args, $show ): array {
		$schema = array(
			'type' => isset( $args['type'] ) ? (string) $args['type'] : 'string',
		);
		if ( ! empty( $args['label'] ) ) {
			$schema['title'] = $args['label'];
		}
		if ( ! empty( $args['description'] ) ) {
			$schema['description'] = $args['description'];
		}
		if ( is_array( $show ) && isset( $show['schema'] ) && is_array( $show['schema'] ) ) {
			$schema = array_merge( $schema, $show['schema'] );
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
	protected static function cast_value( $value, string $type ) {
		switch ( $type ) {
			case 'boolean':
				return (bool) $value;
			case 'integer':
				return (int) $value;
			case 'number':
				return (float) $value;
			case 'array':
			case 'object':
				return is_array( $value ) ? $value : array();
			default:
				return is_scalar( $value ) ? (string) $value : $value;
		}
	}
}
