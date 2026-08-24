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
			'ai/v1',
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

			if ( null !== $exposed && ! is_bool( $exposed ) ) {
				return false;
			}
		}

		return true;
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
			$abilities[] = array(
				'name'        => $ability->get_name(),
				'label'       => $ability->get_label(),
				'description' => $ability->get_description(),
				'exposed'     => $this->is_exposed( $ability->get_meta() ),
			);
		}

		return array(
			'adapter_active' => $adapter_active,
			'abilities'      => $abilities,
			'overrides'      => empty( $overrides ) ? (object) array() : $overrides,
			'endpoint'       => $adapter_active ? rest_url( 'mcp/mcp-adapter-default-server' ) : null,
		);
	}

	/**
	 * Resolves effective MCP exposure from ability meta.
	 *
	 * Mirrors the MCP Adapter's resolution: an explicit `meta.mcp.public`
	 * wins, otherwise exposure is inherited from `meta.public`.
	 *
	 * @since 0.9.0
	 *
	 * @param array<string, mixed> $meta Ability meta.
	 *
	 * @return bool Whether the ability is exposed through MCP.
	 */
	private function is_exposed( array $meta ): bool {
		$mcp_meta = $meta['mcp'] ?? array();

		if ( ! is_array( $mcp_meta ) ) {
			return false;
		}

		if ( isset( $mcp_meta['public'] ) ) {
			return (bool) $mcp_meta['public'];
		}

		return true === ( $meta['public'] ?? false );
	}
}
