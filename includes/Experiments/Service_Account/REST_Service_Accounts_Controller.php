<?php
/**
 * REST API: Service Accounts Controller.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Service_Account;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API controller for service accounts.
 *
 * This controller follows WordPress Core REST API patterns and is designed
 * to be portable to Core with minimal modifications.
 *
 * @since 0.3.0
 *
 * @see WP_REST_Controller
 */
class REST_Service_Accounts_Controller extends \WP_REST_Controller {
	/**
	 * The service account manager instance.
	 *
	 * @since 0.3.0
	 * @var Service_Account_Manager
	 */
	protected Service_Account_Manager $manager;

	/**
	 * Constructor.
	 *
	 * @since 0.3.0
	 */
	public function __construct() {
		$this->namespace = 'wp/v2';
		$this->rest_base = 'service-accounts';
		$this->manager   = Service_Account_Manager::get_instance();
	}

	/**
	 * Registers the routes for service accounts.
	 *
	 * @since 0.3.0
	 *
	 * @see register_rest_route()
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( \WP_REST_Server::CREATABLE ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				'args'   => array(
					'id' => array(
						'description' => __( 'Unique identifier for the service account.', 'ai' ),
						'type'        => 'integer',
					),
				),
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(
						'context' => $this->get_context_param( array( 'default' => 'view' ) ),
					),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( \WP_REST_Server::EDITABLE ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'delete_item_permissions_check' ),
					'args'                => array(
						'force'    => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Required to be true, as service accounts do not support trashing.', 'ai' ),
						),
						'reassign' => array(
							'type'        => 'integer',
							'description' => __( 'Reassign the deleted user\'s posts and links to this user ID.', 'ai' ),
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		// Application password regeneration endpoint.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/app-password',
			array(
				'args' => array(
					'id' => array(
						'description' => __( 'Unique identifier for the service account.', 'ai' ),
						'type'        => 'integer',
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'regenerate_app_password' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => array(
						'name' => array(
							'type'        => 'string',
							'description' => __( 'Name for the application password.', 'ai' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Checks if a given request has access to read service accounts.
	 *
	 * @since 0.3.0
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return true|\WP_Error True if the request has read access, WP_Error object otherwise.
	 */
	public function get_items_permissions_check( $request ) {
		if ( ! current_user_can( 'list_users' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to list service accounts.', 'ai' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Retrieves all service accounts.
	 *
	 * @since 0.3.0
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response|\WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_items( $request ) {
		$args = array(
			'number'  => $request->get_param( 'per_page' ),
			'offset'  => ( $request->get_param( 'page' ) - 1 ) * $request->get_param( 'per_page' ),
			'orderby' => $request->get_param( 'orderby' ),
			'order'   => $request->get_param( 'order' ),
			'search'  => $request->get_param( 'search' ),
		);

		if ( $request->get_param( 'search' ) ) {
			$args['search'] = '*' . $request->get_param( 'search' ) . '*';
		}

		$users = $this->manager->get_service_accounts( $args );

		// Get total count for pagination.
		$total = $this->manager->get_service_account_count();

		$response_users = array();
		foreach ( $users as $user ) {
			$data = $this->prepare_item_for_response( $user, $request );
			$response_users[] = $this->prepare_response_for_collection( $data );
		}

		$response = rest_ensure_response( $response_users );

		// Add pagination headers.
		$max_pages = (int) ceil( $total / $request->get_param( 'per_page' ) );

		$response->header( 'X-WP-Total', $total );
		$response->header( 'X-WP-TotalPages', $max_pages );

		return $response;
	}

	/**
	 * Checks if a given request has access to read a service account.
	 *
	 * @since 0.3.0
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return true|\WP_Error True if the request has read access for the item, WP_Error object otherwise.
	 */
	public function get_item_permissions_check( $request ) {
		$user = $this->manager->get_service_account( (int) $request->get_param( 'id' ) );

		if ( is_wp_error( $user ) ) {
			return $user;
		}

		if ( ! current_user_can( 'list_users' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to view this service account.', 'ai' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Retrieves a single service account.
	 *
	 * @since 0.3.0
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response|\WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_item( $request ) {
		$user = $this->manager->get_service_account( (int) $request->get_param( 'id' ) );

		if ( is_wp_error( $user ) ) {
			$user->add_data( array( 'status' => 404 ) );
			return $user;
		}

		return $this->prepare_item_for_response( $user, $request );
	}

	/**
	 * Checks if a given request has access to create a service account.
	 *
	 * @since 0.3.0
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return true|\WP_Error True if the request has access to create items, WP_Error object otherwise.
	 */
	public function create_item_permissions_check( $request ) {
		if ( ! current_user_can( 'create_users' ) ) {
			return new \WP_Error(
				'rest_cannot_create_user',
				__( 'Sorry, you are not allowed to create service accounts.', 'ai' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Creates a single service account.
	 *
	 * @since 0.3.0
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response|\WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function create_item( $request ) {
		if ( ! empty( $request->get_param( 'id' ) ) ) {
			return new \WP_Error(
				'rest_user_exists',
				__( 'Cannot create existing service account.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		$user = $this->manager->create_service_account(
			array(
				'name'        => $request->get_param( 'name' ),
				'description' => $request->get_param( 'description' ),
			)
		);

		if ( is_wp_error( $user ) ) {
			$user->add_data( array( 'status' => 400 ) );
			return $user;
		}

		$response = $this->prepare_item_for_response( $user, $request );
		$response = rest_ensure_response( $response );
		$response->set_status( 201 );

		$response->header(
			'Location',
			rest_url( sprintf( '%s/%s/%d', $this->namespace, $this->rest_base, $user->ID ) )
		);

		return $response;
	}

	/**
	 * Checks if a given request has access to update a service account.
	 *
	 * @since 0.3.0
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return true|\WP_Error True if the request has access to update the item, WP_Error object otherwise.
	 */
	public function update_item_permissions_check( $request ) {
		$user = $this->manager->get_service_account( (int) $request->get_param( 'id' ) );

		if ( is_wp_error( $user ) ) {
			return $user;
		}

		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return new \WP_Error(
				'rest_cannot_edit',
				__( 'Sorry, you are not allowed to edit this service account.', 'ai' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Updates a single service account.
	 *
	 * @since 0.3.0
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response|\WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function update_item( $request ) {
		$user_id = (int) $request->get_param( 'id' );

		$args = array();

		// Only name and description can be updated (username/email are auto-generated).
		if ( $request->has_param( 'name' ) ) {
			$args['display_name'] = $request->get_param( 'name' );
		}

		if ( $request->has_param( 'description' ) ) {
			$args['description'] = $request->get_param( 'description' );
		}

		$user = $this->manager->update_service_account( $user_id, $args );

		if ( is_wp_error( $user ) ) {
			$user->add_data( array( 'status' => 400 ) );
			return $user;
		}

		return $this->prepare_item_for_response( $user, $request );
	}

	/**
	 * Checks if a given request has access to delete a service account.
	 *
	 * @since 0.3.0
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return true|\WP_Error True if the request has access to delete the item, WP_Error object otherwise.
	 */
	public function delete_item_permissions_check( $request ) {
		$user = $this->manager->get_service_account( (int) $request->get_param( 'id' ) );

		if ( is_wp_error( $user ) ) {
			return $user;
		}

		if ( ! current_user_can( 'delete_user', $user->ID ) ) {
			return new \WP_Error(
				'rest_user_cannot_delete',
				__( 'Sorry, you are not allowed to delete this service account.', 'ai' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Deletes a single service account.
	 *
	 * @since 0.3.0
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response|\WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function delete_item( $request ) {
		$user_id  = (int) $request->get_param( 'id' );
		$reassign = $request->get_param( 'reassign' );
		$force    = $request->get_param( 'force' );

		if ( ! $force ) {
			return new \WP_Error(
				'rest_trash_not_supported',
				__( 'Service accounts do not support trashing. Set force=true to delete.', 'ai' ),
				array( 'status' => 501 )
			);
		}

		// Get the user data before deletion for the response.
		$user     = $this->manager->get_service_account( $user_id );
		$response = $this->prepare_item_for_response( $user, $request );

		$result = $this->manager->delete_service_account( $user_id, $reassign );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 500 ) );
			return $result;
		}

		$data = $response->get_data();
		$data['deleted'] = true;

		return new \WP_REST_Response( $data );
	}

	/**
	 * Regenerates an application password for a service account.
	 *
	 * @since 0.3.0
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response|\WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function regenerate_app_password( $request ) {
		$user_id = (int) $request->get_param( 'id' );
		$name    = $request->get_param( 'name' ) ?? '';

		$result = $this->manager->regenerate_app_password( $user_id, $name );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 500 ) );
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Prepares a single service account output for response.
	 *
	 * @since 0.3.0
	 *
	 * @param \WP_User         $user    User object.
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response Response object.
	 */
	public function prepare_item_for_response( $user, $request ) {
		$data = array(
			'id'                => $user->ID,
			'username'          => $user->user_login,
			'name'              => $user->display_name,
			'email'             => $user->user_email,
			'description'       => $user->description,
			'registered_date'   => gmdate( 'c', strtotime( $user->user_registered ) ),
			'roles'             => array_values( $user->roles ),
			'capabilities'      => $this->get_user_capabilities( $user ),
			'meta'              => array(
				'is_service_account' => true,
			),
		);

		// Add additional fields from the schema.
		$context = $request->get_param( 'context' ) ?? 'view';

		/**
		 * Filters the service account data for the REST API response.
		 *
		 * @since 0.3.0
		 *
		 * @param array            $data    The prepared response data.
		 * @param \WP_User         $user    The user object.
		 * @param \WP_REST_Request $request The request object.
		 */
		$data = apply_filters( 'rest_prepare_service_account', $data, $user, $request );

		$response = rest_ensure_response( $data );

		$response->add_links( $this->prepare_links( $user ) );

		return $response;
	}

	/**
	 * Gets the capabilities to expose for a service account.
	 *
	 * @since 0.3.0
	 *
	 * @param \WP_User $user The user object.
	 * @return array<string, bool> Subset of capabilities relevant for service accounts.
	 */
	protected function get_user_capabilities( \WP_User $user ): array {
		$relevant_caps = array(
			'read',
			'edit_posts',
			'publish_posts',
			'delete_posts',
			'edit_others_posts',
			'upload_files',
		);

		$caps = array();
		foreach ( $relevant_caps as $cap ) {
			$caps[ $cap ] = $user->has_cap( $cap );
		}

		return $caps;
	}

	/**
	 * Prepares links for the response.
	 *
	 * @since 0.3.0
	 *
	 * @param \WP_User $user User object.
	 * @return array<string, array<array<string, mixed>>> Links for the given user.
	 */
	protected function prepare_links( \WP_User $user ): array {
		return array(
			'self'       => array(
				array(
					'href' => rest_url( sprintf( '%s/%s/%d', $this->namespace, $this->rest_base, $user->ID ) ),
				),
			),
			'collection' => array(
				array(
					'href' => rest_url( sprintf( '%s/%s', $this->namespace, $this->rest_base ) ),
				),
			),
		);
	}

	/**
	 * Retrieves the query params for collections.
	 *
	 * @since 0.3.0
	 *
	 * @return array<string, array<string, mixed>> Collection parameters.
	 */
	public function get_collection_params(): array {
		$query_params = parent::get_collection_params();

		$query_params['context']['default'] = 'view';

		$query_params['orderby'] = array(
			'description' => __( 'Sort collection by user attribute.', 'ai' ),
			'type'        => 'string',
			'default'     => 'registered',
			'enum'        => array(
				'id',
				'name',
				'registered',
				'email',
			),
		);

		$query_params['order'] = array(
			'description' => __( 'Order sort attribute ascending or descending.', 'ai' ),
			'type'        => 'string',
			'default'     => 'desc',
			'enum'        => array( 'asc', 'desc' ),
		);

		return $query_params;
	}

	/**
	 * Retrieves the service account's schema, conforming to JSON Schema.
	 *
	 * @since 0.3.0
	 *
	 * @return array<string, mixed> Item schema data.
	 */
	public function get_item_schema(): array {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'service-account',
			'type'       => 'object',
			'properties' => array(
				'id'              => array(
					'description' => __( 'Unique identifier for the service account.', 'ai' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit', 'embed' ),
					'readonly'    => true,
				),
				'username'        => array(
					'description' => __( 'Auto-generated login name for the service account.', 'ai' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'name'            => array(
					'description' => __( 'Human-readable name for the service account.', 'ai' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit', 'embed' ),
					'required'    => true,
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'email'           => array(
					'description' => __( 'Auto-generated email address for the service account.', 'ai' ),
					'type'        => 'string',
					'format'      => 'email',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'description'     => array(
					'description' => __( 'Description of the service account\'s purpose.', 'ai' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'registered_date' => array(
					'description' => __( 'Registration date for the service account.', 'ai' ),
					'type'        => 'string',
					'format'      => 'date-time',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'roles'           => array(
					'description' => __( 'Roles assigned to the service account.', 'ai' ),
					'type'        => 'array',
					'items'       => array(
						'type' => 'string',
					),
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'capabilities'    => array(
					'description' => __( 'All capabilities assigned to the service account.', 'ai' ),
					'type'        => 'object',
					'context'     => array( 'edit' ),
					'readonly'    => true,
				),
				'meta'            => array(
					'description' => __( 'Meta fields.', 'ai' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
					'properties'  => array(
						'is_service_account' => array(
							'type' => 'boolean',
						),
					),
				),
			),
		);

		return $this->add_additional_fields_schema( $this->schema );
	}
}
