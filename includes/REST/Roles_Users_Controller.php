<?php

declare( strict_types=1 );

namespace WordPress\AI\REST;

defined( 'ABSPATH' ) || exit;

class Roles_Users_Controller {

	private const API_NAMESPACE = 'ai/v1';

	private const ROUTE = '/roles-users';

	public function init(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::API_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_roles_users' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	public function get_roles_users( \WP_REST_Request $request ) {
		$roles = array();
		$editable_roles = wp_roles()->roles;

		foreach ( $editable_roles as $role_id => $role ) {
			$roles[] = array(
				'id'   => $role_id,
				'name' => translate_user_role( $role['name'] ),
			);
		}

		$users = array();
		$wp_users = get_users( array( 'fields' => array( 'ID', 'display_name' ) ) );

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
