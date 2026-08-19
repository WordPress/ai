<?php
/**
 * Settings registration for the AI plugin.
 *
 * @package WordPress\AI
 *
 * @since 0.1.0
 */

declare(strict_types=1);

namespace WordPress\AI\Settings;

use WordPress\AI\Features\Registry;
use WordPress\AI\REST\Models_Controller;
use WordPress\AI\REST\Roles_Users_Controller;
use WordPress\AI\REST\Settings_IO_Controller;

/**
 * Handles registration of settings for the AI plugin.
 *
 * @since 0.1.0
 */
class Settings_Registration {

	/**
	 * The experiment registry instance.
	 *
	 * @since 0.1.0
	 *
	 * @var \WordPress\AI\Features\Registry
	 */
	private Registry $registry;

	/**
	 * The option group name for settings registration.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const OPTION_GROUP = 'ai_experiments';

	/**
	 * The option name for the global experiments toggle.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const GLOBAL_OPTION = 'wpai_features_enabled';

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param \WordPress\AI\Features\Registry $registry The feature registry.
	 */
	public function __construct( Registry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Initializes the settings registration hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function init(): void {
		$this->register_settings();

		// Initialize the provider/model discovery REST endpoint.
		( new Models_Controller() )->init();

		// Initialize the settings import/export REST endpoints.
		( new Settings_IO_Controller() )->init();
		( new Roles_Users_Controller() )->init();
	}

	/**
	 * Registers all settings.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register_settings(): void {
		// Register the global toggle.
		register_setting(
			self::OPTION_GROUP,
			self::GLOBAL_OPTION,
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'show_in_rest'      => true,
			)
		);

		// Register settings for each experiment.
		foreach ( $this->registry->get_all_features() as $feature ) {
			$feature_id = $feature::get_id();

			register_setting(
				self::OPTION_GROUP,
				"wpai_feature_{$feature_id}_enabled",
				array(
					'type'              => 'boolean',
					'default'           => false,
					'sanitize_callback' => 'rest_sanitize_boolean',
					'show_in_rest'      => true,
				)
			);

			register_setting(
				self::OPTION_GROUP,
				"wpai_feature_{$feature_id}_field_developer",
				array(
					'type'         => 'object',
					'default'      => array(),
					'show_in_rest' => array(
						'schema' => array(
							'type'       => 'object',
							'properties' => array(
								'provider' => array(
									'type'    => 'string',
									'default' => '',
								),
								'model'    => array(
									'type'    => 'string',
									'default' => '',
								),
							),
						),
					),
				)
			);

			register_setting(
				self::OPTION_GROUP,
				"wpai_feature_{$feature_id}_roles",
				array(
					'type'              => 'array',
					'default'           => array(),
					'sanitize_callback' => static function ( $roles ) {
						if ( ! is_array( $roles ) ) {
							return array();
						}

						$valid_roles = array_keys( wp_roles()->roles );

						return array_values(
							array_filter(
								$roles,
								static function ( $role ) use ( $valid_roles ) {
									return is_string( $role ) && in_array( $role, $valid_roles, true );
								}
							)
						);
					},
					'show_in_rest'      => array(
						'schema' => array(
							'type'  => 'array',
							'items' => array(
								'type' => 'string',
							),
						),
					),
				)
			);

			register_setting(
				self::OPTION_GROUP,
				"wpai_feature_{$feature_id}_users",
				array(
					'type'              => 'array',
					'default'           => array(),
					'sanitize_callback' => static function ( $users ) {
						if ( ! is_array( $users ) ) {
							return array();
						}

						return array_values(
							array_filter(
								array_map( 'absint', $users ),
								static function ( $user_id ) {
									return $user_id > 0 && false !== get_userdata( $user_id );
								}
							)
						);
					},
					'show_in_rest'      => array(
						'schema' => array(
							'type'  => 'array',
							'items' => array(
								'type' => 'integer',
							),
						),
					),
				)
			);

			// Allow experiments to register their own custom settings.
			if ( ! method_exists( $feature, 'register_settings' ) ) {
				continue;
			}

			$feature->register_settings();
		}
	}
}
