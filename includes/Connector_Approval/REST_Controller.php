<?php
/**
 * REST controller for the connector approval UI.
 *
 * @package WordPress\AI\Connector_Approval
 */

declare( strict_types=1 );

namespace WordPress\AI\Connector_Approval;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use function WordPress\AI\get_ai_connectors;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Exposes approval state to the admin UI and accepts approval updates.
 *
 * Endpoints (namespace `ai/v1`):
 * - GET  /connector-approvals                        returns connectors, approvals, pending, active plugins, and active theme
 * - POST /connector-approvals                        sets or revokes approval for a plugin/connector pair
 * - DELETE /connector-approvals/pending/(?P<key>...) dismisses a pending entry without approving
 *
 * @since 1.0.0
 */
final class REST_Controller {
	/**
	 * REST namespace shared across the plugin.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	private const REST_NAMESPACE = 'ai/v1';

	/**
	 * Approvals store.
	 *
	 * @since 1.0.0
	 *
	 * @var \WordPress\AI\Connector_Approval\Approvals_Store
	 */
	private Approvals_Store $store;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param \WordPress\AI\Connector_Approval\Approvals_Store $store Approvals store.
	 */
	public function __construct( Approvals_Store $store ) {
		$this->store = $store;
	}

	/**
	 * Registers the REST routes. Call during `rest_api_init`.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/connector-approvals',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_state' ),
					'permission_callback' => array( $this, 'permission_callback' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_approval' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'args'                => array(
						'plugin_basename' => array(
							'type'     => 'string',
							'required' => true,
						),
						'connector_id'    => array(
							'type'     => 'string',
							'required' => true,
						),
						'approved'        => array(
							'type'     => 'boolean',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/connector-approvals/pending/(?P<key>[^/]+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_pending' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => array(
					'key' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Permission callback; restricts the endpoints to administrators.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function permission_callback(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Returns the full state snapshot used by the UI.
	 *
	 * @since 1.0.0
	 *
	 * @return \WP_REST_Response
	 */
	public function get_state(): WP_REST_Response {
		$pending = $this->store->get_pending();

		$pending_list = array();
		foreach ( $pending as $key => $entry ) {
			$entry['key']   = $key;
			$pending_list[] = $entry;
		}

		return new WP_REST_Response(
			array(
				'connectors' => $this->describe_connectors(),
				'approvals'  => $this->store->get_approvals(),
				'pending'    => $pending_list,
				'plugins'    => $this->list_active_plugins(),
				'themes'     => $this->list_active_themes(),
			),
			200
		);
	}

	/**
	 * Sets or revokes approval for a single plugin/connector pair.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_approval( WP_REST_Request $request ) {
		$plugin_basename = (string) $request->get_param( 'plugin_basename' );
		$connector_id    = (string) $request->get_param( 'connector_id' );
		$approved        = (bool) $request->get_param( 'approved' );

		if ( '' === $plugin_basename || '' === $connector_id ) {
			return new WP_Error(
				'wpai_invalid_approval',
				__( 'Plugin basename and connector ID are required.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		$this->store->set_approval( $plugin_basename, $connector_id, $approved );

		if ( $approved ) {
			$this->store->remove_pending(
				$this->store->pending_key( $plugin_basename, $connector_id )
			);
		}

		return $this->get_state();
	}

	/**
	 * Removes a pending entry without approving it.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_pending( WP_REST_Request $request ) {
		$key = rawurldecode( (string) $request->get_param( 'key' ) );

		if ( ! $this->store->remove_pending( $key ) ) {
			return new WP_Error(
				'wpai_pending_not_found',
				__( 'No pending approval request matches the provided key.', 'ai' ),
				array( 'status' => 404 )
			);
		}

		return $this->get_state();
	}

	/**
	 * Returns the AI provider connectors the UI should render.
	 *
	 * @since 1.0.0
	 *
	 * @return list<array{id: string, name: string}>
	 */
	private function describe_connectors(): array {
		$summary = array();

		foreach ( get_ai_connectors() as $connector_id => $data ) {
			$summary[] = array(
				'id'   => $connector_id,
				'name' => isset( $data['name'] ) && is_string( $data['name'] ) && '' !== $data['name']
					? $data['name']
					: $connector_id,
			);
		}

		usort(
			$summary,
			static fn( array $a, array $b ): int => strcasecmp( $a['name'], $b['name'] )
		);

		return $summary;
	}

	/**
	 * Returns the list of active plugins for the UI.
	 *
	 * @since 1.0.0
	 *
	 * @return list<array{basename: string, name: string}>
	 */
	private function list_active_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins    = get_plugins();
		$active_plugins = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$network        = array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) );
			$active_plugins = array_merge( $active_plugins, $network );
		}

		$list = array();
		foreach ( array_unique( $active_plugins ) as $basename ) {
			if ( ! is_string( $basename ) || '' === $basename ) {
				continue;
			}
			$data   = $all_plugins[ $basename ] ?? array();
			$list[] = array(
				'basename' => $basename,
				'name'     => isset( $data['Name'] ) && is_string( $data['Name'] ) && '' !== $data['Name']
					? $data['Name']
					: $basename,
			);
		}

		usort(
			$list,
			static fn( array $a, array $b ): int => strcasecmp( $a['name'], $b['name'] )
		);

		return $list;
	}

	/**
	 * Returns the active theme for the UI.
	 *
	 * @since 1.0.0
	 *
	 * @return list<array{basename: string, name: string}>
	 */
	private function list_active_themes(): array {
		$theme = wp_get_theme();
		$name  = $theme->get( 'Name' );

		return array(
			array(
				'basename' => get_stylesheet(),
				'name'     => is_string( $name ) && '' !== $name ? $name : get_stylesheet(),
			),
		);
	}
}
