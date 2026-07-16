<?php
/**
 * Text to speech REST controller.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Text_To_Speech;

use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

use function WordPress\AI\has_text_to_speech_support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the REST routes for the Text to Speech background flow.
 *
 * @since x.x.x
 */
class REST_Controller {

	/**
	 * The REST namespace.
	 *
	 * @since x.x.x
	 * @var string
	 */
	public const REST_NAMESPACE = 'ai/v1';

	/**
	 * The REST route base.
	 *
	 * @since x.x.x
	 * @var string
	 */
	public const REST_BASE = '/text-to-speech';

	/**
	 * Registers the REST routes.
	 *
	 * @since x.x.x
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_BASE . '/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'start_job' ),
					'permission_callback' => array( $this, 'can_generate' ),
					'args'                => $this->get_route_args(),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_status' ),
					'permission_callback' => array( $this, 'can_view_status' ),
					'args'                => $this->get_route_args(),
				),
			)
		);
	}

	/**
	 * Returns the shared route argument definitions.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array<string, mixed>> The route args.
	 */
	protected function get_route_args(): array {
		return array(
			'id' => array(
				'description'       => esc_html__( 'The post ID.', 'ai' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * Checks whether the current user can trigger audio generation.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return true|\WP_Error True if permitted, WP_Error otherwise.
	 */
	public function can_generate( WP_REST_Request $request ) {
		$post_check = $this->check_post( $request );

		if ( is_wp_error( $post_check ) ) {
			return $post_check;
		}

		$post_id = absint( $request['id'] );

		if ( ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( 'upload_files' ) ) {
			return new WP_Error(
				'rest_forbidden',
				esc_html__( 'You do not have permission to generate audio for this post.', 'ai' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Checks whether the current user can view generation status.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return true|\WP_Error True if permitted, WP_Error otherwise.
	 */
	public function can_view_status( WP_REST_Request $request ) {
		$post_check = $this->check_post( $request );

		if ( is_wp_error( $post_check ) ) {
			return $post_check;
		}

		if ( ! current_user_can( 'edit_post', absint( $request['id'] ) ) ) {
			return new WP_Error(
				'rest_forbidden',
				esc_html__( 'You do not have permission to view audio generation status for this post.', 'ai' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Checks that the requested post exists and is REST-visible.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return true|\WP_Error True if valid, WP_Error otherwise.
	 */
	protected function check_post( WP_REST_Request $request ) {
		$post_id = absint( $request['id'] );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'rest_post_not_found',
				esc_html__( 'Post not found.', 'ai' ),
				array( 'status' => 404 )
			);
		}

		$post_type_obj = get_post_type_object( (string) get_post_type( $post_id ) );

		if ( ! $post_type_obj || empty( $post_type_obj->show_in_rest ) ) {
			return new WP_Error(
				'rest_invalid_post_type',
				esc_html__( 'Audio generation is not available for this post type.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Starts (or restarts) a background audio generation job.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error The status payload or an error.
	 */
	public function start_job( WP_REST_Request $request ) {
		if ( ! has_text_to_speech_support( true ) ) {
			return new WP_Error(
				'unsupported',
				esc_html__( 'No connected AI provider supports text to speech.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		$result = ( new Job_Manager() )->start_job( absint( $request['id'] ), get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Returns the current job/audio status for a post.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response The status payload.
	 */
	public function get_status( WP_REST_Request $request ) {
		return rest_ensure_response( ( new Job_Manager() )->get_status( absint( $request['id'] ) ) );
	}
}
