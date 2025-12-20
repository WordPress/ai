<?php
/**
 * REST controller to manage message history for the AI Playground implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\AI_Playground;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WordPress\AI_Client\Capabilities\Capabilities_Manager;
use WordPress\AI_Client\REST_API\JSON_Schema_To_WP_Schema_Converter;
use WordPress\AiClient\Messages\DTO\Message;

/**
 * REST controller to manage message history for the AI Playground.
 *
 * @since n.e.x.t
 *
 * @phpstan-import-type MessageArrayShape from Message
 *
 * @phpstan-type PlaygroundMessage array{
 *   type: string,
 *   content: MessageArrayShape,
 *   provider?: array{
 *     id: string,
 *     name: string
 *   },
 *   model?: array{
 *     id: string,
 *     name: string
 *   },
 *   capability?: string,
 *   attachments?: array<string, mixed>[]
 * }
 *
 * @phpstan-type UpdateMessagesRequestParams array{
 *   messages: PlaygroundMessage[]
 * }
 */
class AI_Playground_Messages_REST_Controller {

	/**
	 * Registers the REST routes.
	 *
	 * @since n.e.x.t
	 */
	public function register_routes(): void {
		register_rest_route(
			'ai/v1',
			'/playground-messages',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'process_list_messages_request' ),
					'permission_callback' => array( $this, 'permissions_check_playground_access' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'process_update_messages_request' ),
					'permission_callback' => array( $this, 'permissions_check_playground_access' ),
					'args'                => $this->get_update_messages_args(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'process_delete_messages_request' ),
					'permission_callback' => array( $this, 'permissions_check_playground_access' ),
				),
				'schema' => array( $this, 'get_message_schema' ),
			)
		);
	}

	/**
	 * Checks if the user has permission to access the AI Playground.
	 *
	 * @since n.e.x.t
	 *
	 * @return bool|\WP_Error True if authorized, WP_Error otherwise.
	 */
	public function permissions_check_playground_access() {
		// phpcs:ignore WordPress.WP.Capabilities.Undetermined
		if ( current_user_can( Capabilities_Manager::PROMPT_AI_CAPABILITY ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'Sorry, you are not allowed to access the AI Playground.', 'ai' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Retrieves the list of AI Playground messages for the current user.
	 *
	 * @since n.e.x.t
	 *
	 * @return \WP_REST_Response|\WP_Error The response object or error.
	 */
	public function process_list_messages_request() {
		$messages_history = get_user_option( 'ai_playground_messages_history', get_current_user_id() );
		if ( ! is_array( $messages_history ) || ! isset( $messages_history['messages'] ) || ! is_array( $messages_history['messages'] ) ) {
			return new WP_REST_Response( array(), 200 );
		}
		return new WP_REST_Response( $messages_history['messages'], 200 );
	}

	/**
	 * Updates the list of AI Playground messages for the current user.
	 *
	 * @since n.e.x.t
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response|\WP_Error The response object or error.
	 *
	 * @phpstan-param WP_REST_Request<UpdateMessagesRequestParams> $request
	 */
	public function process_update_messages_request( WP_REST_Request $request ) {
		update_user_option(
			get_current_user_id(),
			'ai_playground_messages_history',
			array(
				'messages'    => $request['messages'],
				'lastUpdated' => current_time( 'mysql', true ),
			)
		);

		// Return the updated history.
		return $this->process_list_messages_request();
	}

	/**
	 * Deletes / resets all AI Playground messages for the current user.
	 *
	 * @since n.e.x.t
	 *
	 * @return \WP_REST_Response|\WP_Error The response object or error.
	 */
	public function process_delete_messages_request() {
		$previous_messages = $this->process_list_messages_request();

		delete_user_option( get_current_user_id(), 'ai_playground_messages_history' );

		// Return the original messages history before deletion.
		return $previous_messages;
	}

	/**
	 * Retrieves the arguments schema for updating AI Playground messages.
	 *
	 * @since n.e.x.t
	 *
	 * @return array<string, mixed> The arguments schema.
	 */
	public function get_update_messages_args(): array {
		$playground_message_schema = $this->get_message_schema();

		return array(
			'messages' => array(
				'type'        => 'array',
				'description' => __( 'The list of AI Playground messages.', 'ai' ),
				'items'       => array(
					'type'       => 'object',
					'properties' => $playground_message_schema['properties'],
				),
				'required'    => true,
			),
		);
	}

	/**
	 * Retrieves the schema for an AI Playground message.
	 *
	 * @since n.e.x.t
	 *
	 * @return array<string, mixed> The message schema.
	 */
	public function get_message_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'playground_message',
			'type'       => 'object',
			'properties' => array(
				'type'        => array(
					'type'        => 'string',
					'description' => __( 'The type of message (e.g., user, model, error).', 'ai' ),
					'required'    => true,
				),
				'content'     => array_merge(
					JSON_Schema_To_WP_Schema_Converter::convert( Message::getJsonSchema() ),
					array(
						'description' => __( 'The actual message content, including role and parts.', 'ai' ),
						'required'    => true,
					)
				),
				'provider'    => array(
					'type'        => 'object',
					'description' => __( 'The AI provider information.', 'ai' ),
					'properties'  => array(
						'id'   => array(
							'type'        => 'string',
							'description' => __( 'The provider ID.', 'ai' ),
						),
						'name' => array(
							'type'        => 'string',
							'description' => __( 'The provider name.', 'ai' ),
						),
					),
				),
				'model'       => array(
					'type'        => 'object',
					'description' => __( 'The AI model information.', 'ai' ),
					'properties'  => array(
						'id'   => array(
							'type'        => 'string',
							'description' => __( 'The model ID.', 'ai' ),
						),
						'name' => array(
							'type'        => 'string',
							'description' => __( 'The model name.', 'ai' ),
						),
					),
				),
				'capability'  => array(
					'type'        => 'string',
					'description' => __( 'The AI capability associated with the message.', 'ai' ),
				),
				'attachments' => array(
					'type'        => 'array',
					'description' => __( 'Attachments associated with the message.', 'ai' ),
					'items'       => array( 'type' => array( 'object', 'null' ) ),
				),
			),
			'required'   => array( 'type', 'content' ),
		);
	}
}
