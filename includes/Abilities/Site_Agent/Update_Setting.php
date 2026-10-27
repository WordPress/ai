<?php
/**
 * The `ai/site-agent-update-setting` ability.
 *
 * @package WordPress\AI\Abilities\Site_Agent
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Site_Agent;

use WP_Error;
use WordPress\AI\Experiments\Site_Agent\Site_Agent;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Updates one of two fixed site settings.
 *
 * The allowlist is deliberately tiny: the site title and the tagline. URLs,
 * email addresses, roles, plugins, rewrite rules, cron, and secrets are not
 * reachable through this ability.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since x.x.x
 */
final class Update_Setting {

	/**
	 * The ability name.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const ABILITY = 'ai/site-agent-update-setting';

	/**
	 * The settings this ability may change.
	 *
	 * @since x.x.x
	 *
	 * @var list<string>
	 */
	private const ALLOWED = array( 'blogname', 'blogdescription' ); // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is a single array constant.

	/**
	 * Hooks the ability into the Abilities API.
	 *
	 * @since x.x.x
	 */
	public function init(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register' ), 11 );
	}

	/**
	 * Registers the ability.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		if ( wp_has_ability( self::ABILITY ) ) {
			return;
		}

		wp_register_ability(
			self::ABILITY,
			array(
				'label'               => __( 'Update a site setting', 'ai' ),
				'description'         => __( 'Updates the site title or tagline. No other setting is reachable through this ability.', 'ai' ),
				'category'            => WPAI_DEFAULT_ABILITY_CATEGORY,
				'input_schema'        => $this->get_input_schema(),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'name'  => array( 'type' => 'string' ),
						'value' => array( 'type' => 'string' ),
					),
					'required'   => array( 'name', 'value' ),
				),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'meta'                => array(
					'public'       => false,
					'show_in_rest' => false,
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);
	}

	/**
	 * Checks whether the current user may change settings.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input The ability input.
	 * @return bool|\WP_Error True when permitted, WP_Error otherwise.
	 */
	public function check_permission( $input = null ) {
		unset( $input );

		if ( ! Site_Agent::is_active() || ! Site_Agent::writes_allowed() ) {
			return new WP_Error(
				'site_agent_writes_disabled',
				__( 'Write proposals are disabled.', 'ai' ),
				array( 'status' => 403 )
			);
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'site_agent_settings_denied',
				__( 'You do not have permission to change site settings.', 'ai' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Updates the setting.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input The ability input.
	 * @return array<string, mixed>|\WP_Error The updated setting, or an error.
	 */
	public function execute( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$name  = isset( $input['name'] ) ? sanitize_key( (string) $input['name'] ) : '';
		$value = isset( $input['value'] ) && is_scalar( $input['value'] ) ? sanitize_text_field( (string) $input['value'] ) : '';

		if ( ! in_array( $name, self::ALLOWED, true ) ) {
			return new WP_Error(
				'site_agent_invalid_setting',
				__( 'That setting is not available.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		if ( '' === $value ) {
			return new WP_Error(
				'site_agent_empty_setting',
				__( 'A setting value is required.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		if ( (string) get_option( $name ) === $value ) {
			return array(
				'name'  => $name,
				'value' => $value,
			);
		}

		if ( ! update_option( $name, $value ) ) {
			return new WP_Error(
				'site_agent_setting_not_updated',
				__( 'The setting was not changed.', 'ai' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'name'  => $name,
			'value' => (string) get_option( $name ),
		);
	}

	/**
	 * Builds the input schema.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The schema.
	 */
	private function get_input_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'name', 'value' ),
			'additionalProperties' => false,
			'properties'           => array(
				'name'  => array(
					'type'        => 'string',
					'enum'        => self::ALLOWED,
					'description' => __( 'The setting to update.', 'ai' ),
				),
				'value' => array(
					'type'        => 'string',
					'maxLength'   => 500,
					'description' => __( 'The new value.', 'ai' ),
				),
			),
		);
	}
}
