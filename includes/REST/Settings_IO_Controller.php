<?php
/**
 * REST controller for AI settings import and export.
 *
 * @package WordPress\AI\REST
 *
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\REST;

use WordPress\AI\Settings\Settings_Registration;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Handles the settings export (GET) and import (POST) REST endpoints.
 *
 * @since x.x.x
 */
final class Settings_IO_Controller {

	/**
	 * The REST API namespace.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const API_NAMESPACE = 'ai/v1';

	/**
	 * The export route path.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const EXPORT_ROUTE = '/settings/export';

	/**
	 * The import route path.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const IMPORT_ROUTE = '/settings/import';

	/**
	 * The current export/import schema version.
	 *
	 * Increment this constant when the export format changes in a
	 * backward-incompatible way so that older files are rejected.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	public const SCHEMA_VERSION = 1;

	/**
	 * Substrings that indicate a sensitive option name.
	 *
	 * Any setting whose name contains one of these strings (case-insensitive)
	 * is excluded from exports and rejected during imports.
	 *
	 * @since x.x.x
	 *
	 * @var list<string>
	 */
	private const SENSITIVE_PATTERNS = array( 'api_key', 'token', 'secret', 'credential', 'password', 'auth' ); // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition

	/**
	 * Initializes the REST routes.
	 *
	 * @since x.x.x
	 */
	public function init(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the export and import REST routes.
	 *
	 * @since x.x.x
	 */
	public function register_routes(): void {
		register_rest_route(
			self::API_NAMESPACE,
			self::EXPORT_ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'export_settings' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			self::IMPORT_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'import_settings' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'version'        => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'exported_at'    => array(
						'type'     => 'string',
						'required' => false,
						'default'  => '',
					),
					'plugin_version' => array(
						'type'     => 'string',
						'required' => false,
						'default'  => '',
					),
					'providers'      => array(
						'type'     => 'object',
						'required' => false,
						'default'  => array(),
					),
					'settings'       => array(
						'type'     => 'object',
						'required' => false,
						'default'  => array(),
					),
				),
			)
		);
	}

	/**
	 * Checks whether the current user may access these endpoints.
	 *
	 * @since x.x.x
	 *
	 * @return bool True if the user has the required capability.
	 */
	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Returns the non-sensitive AI configuration as a portable JSON structure.
	 *
	 * The response body matches the schema accepted by the import endpoint.
	 *
	 * @since x.x.x
	 *
	 * @return \WP_REST_Response The export payload.
	 */
	public function export_settings(): \WP_REST_Response {
		$exportable = $this->get_exportable_option_names();
		$settings   = array();
		$providers  = array();

		foreach ( $exportable as $option_name ) {
			$value = get_option( $option_name );

			// Skip options that have never been set.
			if ( false === $value ) {
				continue;
			}

			if ( $this->is_developer_config_option( $option_name ) ) {
				$providers[ $option_name ] = $value;
			} else {
				$settings[ $option_name ] = $value;
			}
		}

		$payload = array(
			'version'        => self::SCHEMA_VERSION,
			'exported_at'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'plugin_version' => WPAI_VERSION,
			'providers'      => $providers,
			'settings'       => $settings,
		);

		return new \WP_REST_Response( $payload, 200 );
	}

	/**
	 * Imports AI settings from a previously exported payload.
	 *
	 * Only settings that are currently registered in the plugin's option group
	 * and are not flagged as sensitive will be written. All other keys in the
	 * payload are silently ignored.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response|\WP_Error The response or an error.
	 */
	public function import_settings( \WP_REST_Request $request ) {
		$version = (int) $request->get_param( 'version' );

		if ( self::SCHEMA_VERSION !== $version ) {
			return new \WP_Error(
				'unsupported_schema_version',
				sprintf(
					/* translators: %d: schema version number. */
					__( 'Unsupported schema version: %d.', 'ai' ),
					$version
				),
				array( 'status' => 422 )
			);
		}

		$exportable = array_flip( $this->get_exportable_option_names() );
		$providers  = $request->get_param( 'providers' );
		$settings   = $request->get_param( 'settings' );

		if ( ! is_array( $providers ) ) {
			$providers = array();
		}

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$all_values = array_merge( $settings, $providers );
		$imported   = 0;

		foreach ( $all_values as $option_name => $value ) {
			if ( ! is_string( $option_name ) ) {
				continue;
			}

			// Only import options that are registered and allowed.
			if ( ! isset( $exportable[ $option_name ] ) ) {
				continue;
			}

			update_option( $option_name, $value );
			++$imported;
		}

		return new \WP_REST_Response(
			array(
				'imported' => $imported,
				'message'  => __( 'Settings imported successfully.', 'ai' ),
			),
			200
		);
	}

	/**
	 * Returns the option names that are safe to export and import.
	 *
	 * Returns every option that belongs to the plugin's settings group
	 * and whose name does not match any sensitive pattern.
	 *
	 * @since x.x.x
	 *
	 * @return list<string> Exportable option names.
	 */
	public function get_exportable_option_names(): array {
		$registered = get_registered_settings();
		$exportable = array();

		foreach ( $registered as $option_name => $args ) {
			// Ensure $option_name is a string
			$option_name = (string) $option_name;

			if ( ( $args['group'] ?? '' ) !== Settings_Registration::OPTION_GROUP ) {
				continue;
			}

			if ( $this->is_sensitive_option( $option_name ) ) {
				continue;
			}

			$exportable[] = $option_name;
		}

		return $exportable;
	}

	/**
	 * Checks whether an option name contains a sensitive keyword.
	 *
	 * @since x.x.x
	 *
	 * @param string $option_name The option name to inspect.
	 * @return bool True if the option should be excluded.
	 */
	private function is_sensitive_option( string $option_name ): bool {
		$lower = strtolower( $option_name );

		foreach ( self::SENSITIVE_PATTERNS as $pattern ) {
			if ( false !== strpos( $lower, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Checks whether an option stores a developer model configuration.
	 *
	 * Developer config options hold provider and model identifier strings
	 * (e.g. `{"provider":"openai","model":"gpt-4.1-mini"}`) and are placed
	 * in the `providers` section of the export payload.
	 *
	 * @since x.x.x
	 *
	 * @param string $option_name The option name to inspect.
	 * @return bool True if the option is a developer model config.
	 */
	private function is_developer_config_option( string $option_name ): bool {
		return false !== strpos( $option_name, '_field_developer' );
	}
}
