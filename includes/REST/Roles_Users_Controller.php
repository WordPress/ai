<?php
/**
 * REST controller for roles and users.
 *
 * @package WordPress\AI\REST
 */

declare( strict_types=1 );

namespace WordPress\AI\REST;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the GET /ai/v1/roles-users REST endpoint.
 *
 * Returns roles and users for access control configuration.
 *
 * @since x.x.x
 */
class Roles_Users_Controller {

	/**
	 * The REST API namespace.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const API_NAMESPACE = 'ai/v1';

	/**
	 * The REST API route.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const ROUTE = '/roles-users';

	/**
	 * Maximum number of users to retrieve.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	private const MAX_USERS = 10;

	/**
	 * Initializes the REST routes.
	 *
	 * @since x.x.x
	 */
	public function init(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the REST routes.
	 *
	 * @since x.x.x
	 */
	public function register_routes(): void {
		register_rest_route(
			self::API_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_roles_users' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'search' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Checks whether the current user can access this endpoint.
	 *
	 * @since x.x.x
	 *
	 * @return bool True if the user has permission.
	 */
	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Returns roles and users for the access control endpoint.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response Response object containing roles and users.
	 */
	public function get_roles_users( \WP_REST_Request $request ): \WP_REST_Response {
		$roles = array();

		foreach ( wp_roles()->roles as $role_id => $role ) {
			if ( in_array( $role_id, array( 'subscriber', 'contributor' ), true ) ) {
				continue;
			}

			$roles[] = array(
				'id'   => $role_id,
				'name' => translate_user_role( $role['name'] ),
			);
		}

		$search         = (string) $request->get_param( 'search' );
		$get_users_args = array(
			'fields' => array( 'ID', 'display_name' ),
			'number' => self::MAX_USERS,
		);

		if ( '' !== $search ) {
			$get_users_args['search']         = '*' . $search . '*';
			$get_users_args['search_columns'] = array( 'user_login', 'display_name', 'user_email' );
		}

		$users    = array();
		$wp_users = get_users( $get_users_args );

		foreach ( $wp_users as $user ) {
			$users[] = array(
				'id'   => (int) $user->ID,
				'name' => $user->display_name,
			);
		}

		return new \WP_REST_Response(
			array(
				'roles' => $roles,
				'users' => $users,
			),
			200
		);
	}
}
