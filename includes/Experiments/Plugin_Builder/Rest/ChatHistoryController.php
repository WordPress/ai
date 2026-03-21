<?php
/**
 * REST API controller for interacting with plugin builder chat history.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Plugin_Builder\Rest;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Server;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Controller for getting and saving chat histories.
 *
 * @since x.x.x
 */
class ChatHistoryController extends WP_REST_Controller {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = 'wordpress-ai-plugin-builder/v1';
		$this->rest_base = 'history';
	}

	public function register(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'per_page' => array(
							'type'    => 'integer',
							'default' => 3,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'title'       => array(
							'type'     => 'string',
							'required' => false,
						),
						'messages'    => array(
							'type'     => 'string',
							'required' => true,
						),
						'plugin_slug' => array(
							'type'     => 'string',
							'required' => false,
						),
						'post_id'     => array(
							'type'     => 'integer',
							'required' => false,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'id' => array(
							'type'     => 'integer',
							'required' => true,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'id' => array(
							'type'     => 'integer',
							'required' => true,
						),
					),
				),
			)
		);
	}
	/**
	 * Checks if a given request has access to manage chat history.
	 *
	 * @param \WP_REST_Request $request Full data about the request.
	 * @return true|\WP_Error True if the request has read access, WP_Error object otherwise.
	 */
	public function permissions_check( $request ) {
		if ( ! current_user_can( 'install_plugins' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to manage plugin builder chats.', 'ai' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}
		return true;
	}

	/**
	 * Retrieves a collection of chat histories.
	 *
	 * @param \WP_REST_Request $request Full data about the request.
	 * @return \WP_REST_Response|\WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_items( $request ) {
		$per_page = $request->get_param( 'per_page' ) ?: 3;

		$args = array(
			'post_type'      => 'abp-chat',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		$query = new \WP_Query( $args );

		$data = array();

		foreach ( $query->posts as $post ) {
			$messages_json = $post->post_content ?: get_post_meta( $post->ID, '_abp_messages', true );
			$plugin_slug   = get_post_meta( $post->ID, '_abp_plugin_slug', true );

			$data[] = array(
				'id'          => $post->ID,
				'title'       => $post->post_title,
				'date'        => mysql2date( 'c', $post->post_date ),
				'messages'    => $messages_json ? json_decode( $messages_json, true ) : array(),
				'plugin_slug' => $plugin_slug,
			);
		}

		return rest_ensure_response( $data );
	}

	/**
	 * Retrieves a single chat history item.
	 *
	 * @param \WP_REST_Request $request Full data about the request.
	 * @return \WP_REST_Response|\WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_item( $request ) {
		$id   = $request->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || 'abp-chat' !== $post->post_type ) {
			return new WP_Error( 'not_found', 'Chat history not found.', array( 'status' => 404 ) );
		}

		$messages_json = $post->post_content ?: get_post_meta( $post->ID, '_abp_messages', true );
		$plugin_slug   = get_post_meta( $post->ID, '_abp_plugin_slug', true );

		$data = array(
			'id'          => $post->ID,
			'title'       => $post->post_title,
			'date'        => mysql2date( 'c', $post->post_date ),
			'messages'    => $messages_json ? json_decode( $messages_json, true ) : array(),
			'plugin_slug' => $plugin_slug,
		);

		return rest_ensure_response( $data );
	}

	/**
	 * Creates or updates a chat history item.
	 *
	 * @param \WP_REST_Request $request Full data about the request.
	 * @return \WP_REST_Response|\WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function create_item( $request ) {
		$post_id     = $request->get_param( 'post_id' );
		$title       = $request->get_param( 'title' );
		$messages    = $request->get_param( 'messages' );
		$plugin_slug = $request->get_param( 'plugin_slug' );

		if ( ! $title ) {
			$title = 'Plugin Builder Chat';
		}

		$post_data = array(
			'post_type'    => 'abp-chat',
			'post_title'   => sanitize_text_field( $title ),
			'post_content' => wp_slash( $messages ),
			'post_status'  => 'publish',
		);

		if ( $post_id ) {
			// Update an existing chat
			$post_data['ID'] = $post_id;
			$result          = wp_update_post( $post_data, true );
		} else {
			// Create a new chat
			$result = wp_insert_post( $post_data, true );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$post_id = $result;

		if ( $plugin_slug ) {
			update_post_meta( $post_id, '_abp_plugin_slug', sanitize_text_field( $plugin_slug ) );
		}

		return rest_ensure_response(
			array(
				'id'          => $post_id,
				'title'       => $post_data['post_title'],
				'plugin_slug' => $plugin_slug,
			)
		);
	}

	/**
	 * Deletes a single chat history item.
	 *
	 * @param \WP_REST_Request $request Full data about the request.
	 * @return \WP_REST_Response|\WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function delete_item( $request ) {
		$id   = $request->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || 'abp-chat' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Chat history not found.', 'ai' ), array( 'status' => 404 ) );
		}

		$result = wp_delete_post( $id, true );

		if ( ! $result ) {
			return new WP_Error( 'cant_delete', __( 'Could not delete chat history.', 'ai' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response( array( 'deleted' => true ) );
	}
}
