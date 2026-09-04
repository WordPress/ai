<?php
/**
 * REST controller for the MCP Access settings.
 *
 * @package WordPress\AI\Experiments\MCP_Adapter
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\MCP_Adapter;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes the MCP Access settings over the REST API.
 *
 * @since 0.9.0
 */
class Settings_Controller {
	/**
	 * The REST API namespace.
	 *
	 * @since 0.9.0
	 * @var string
	 */
	public const REST_NAMESPACE = 'ai/v1';

	/**
	 * Pattern a valid ability name must match.
	 *
	 * @since 0.9.0
	 * @var string
	 */
	private const ABILITY_NAME_PATTERN = '#^[a-z0-9-]+/[a-z0-9-]+$#';

	/**
	 * Registers the settings routes.
	 *
	 * @since 0.9.0
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/mcp/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'permission_callback' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'args'                => array(
						'overrides' => array(
							'required'          => true,
							'type'              => 'object',
							'validate_callback' => array( $this, 'validate_overrides' ),
							'sanitize_callback' => array( $this, 'sanitize_overrides' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Checks that the current user can manage the site.
	 *
	 * @since 0.9.0
	 *
	 * @return bool Whether the request is allowed.
	 */
	public function permission_callback(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Validates the overrides parameter.
	 *
	 * Runs before sanitization, so boolean-like strings from form-encoded
	 * requests ("true", "1", …) must be accepted here and coerced in
	 * {@see self::sanitize_overrides()}.
	 *
	 * @since 0.9.0
	 *
	 * @param mixed $overrides The raw parameter value.
	 *
	 * @return bool Whether the value is a valid overrides map.
	 */
	public function validate_overrides( $overrides ): bool {
		if ( ! is_array( $overrides ) ) {
			return false;
		}

		foreach ( $overrides as $name => $exposed ) {
			if ( ! is_string( $name ) || 1 !== preg_match( self::ABILITY_NAME_PATTERN, $name ) ) {
				return false;
			}

			if ( null !== $exposed && ! is_bool( $exposed ) && ! rest_is_boolean( $exposed ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Sanitizes the overrides parameter, coercing boolean-like values.
	 *
	 * @since 0.9.0
	 *
	 * @param array<string, mixed> $overrides The validated overrides map.
	 *
	 * @return array<string, bool|null> The sanitized overrides map.
	 */
	public function sanitize_overrides( array $overrides ): array {
		$sanitized = array();

		foreach ( $overrides as $name => $exposed ) {
			if ( null === $exposed ) {
				$sanitized[ $name ] = null;
				continue;
			}

			$sanitized[ $name ] = is_bool( $exposed ) ? $exposed : rest_sanitize_boolean( (string) $exposed );
		}

		return $sanitized;
	}

	/**
	 * Returns the MCP Access settings payload.
	 *
	 * @since 0.9.0
	 *
	 * @return \WP_REST_Response The settings response.
	 */
	public function get_settings(): WP_REST_Response {
		return new WP_REST_Response( $this->build_payload() );
	}

	/**
	 * Persists exposure overrides and returns the updated settings payload.
	 *
	 * @since 0.9.0
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response The updated settings response.
	 */
	public function update_settings( WP_REST_Request $request ): WP_REST_Response {
		/** @var array<string, bool|null> $changes */
		$changes = $request->get_param( 'overrides' );

		Exposure_Overrides::save_overrides( $changes );

		return new WP_REST_Response( $this->build_payload() );
	}

	/**
	 * Builds the settings payload.
	 *
	 * @since 0.9.0
	 *
	 * @return array<string, mixed> The settings payload.
	 */
	private function build_payload(): array {
		$adapter_active = class_exists( '\WP\MCP\Core\McpAdapter' );
		$overrides      = Exposure_Overrides::get_overrides();

		$abilities = array();
		foreach ( wp_get_abilities() as $ability ) {
			$name    = $ability->get_name();
			$default = Exposure_Overrides::get_registration_default( $name );

			if ( null === $default ) {
				$default = Exposure_Overrides::resolve_meta_exposure( $ability->get_meta() );
			}

			$abilities[] = array(
				'name'        => $name,
				'label'       => $ability->get_label(),
				'description' => $ability->get_description(),
				'exposed'     => array_key_exists( $name, $overrides ) ? $overrides[ $name ] : $default,
				'default'     => $default,
			);
		}

		return array(
			'adapter_active' => $adapter_active,
			'abilities'      => $abilities,
			'overrides'      => empty( $overrides ) ? (object) array() : $overrides,
			'endpoint'       => $adapter_active ? $this->get_endpoint_url() : null,
			'plugin'         => Plugin_Installer::get_state(),
		);
	}

	/**
	 * Resolves the MCP server endpoint URL from the adapter's registered servers.
	 *
	 * Prefers the adapter's default server, falls back to the first registered
	 * server, and returns null when no server is registered (for example when
	 * default-server creation is disabled via filter).
	 *
	 * @since 0.9.0
	 *
	 * @return string|null The endpoint URL, or null when no server is registered.
	 */
	private function get_endpoint_url(): ?string {
		if ( ! class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
			return null;
		}

		$adapter = \WP\MCP\Core\McpAdapter::instance();
		$server  = $adapter->get_server( 'mcp-adapter-default-server' );

		if ( null === $server ) {
			$servers = $adapter->get_servers();
			$server  = empty( $servers ) ? null : reset( $servers );
		}

		if ( null === $server ) {
			return null;
		}

		return rest_url( $server->get_server_route_namespace() . '/' . $server->get_server_route() );
	}
}
