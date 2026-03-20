<?php
/**
 * REST API Generate controller.
 *
 * @package WordPress\AI\Experiments
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Plugin_Builder\Rest;

use WP_REST_Request;
use WP_REST_Response;
use WordPress\AI\Experiments\Plugin_Builder\Ai\BackgroundJob;
use WordPress\AI\Experiments\Plugin_Builder\Ai\IntentDetector;
use WordPress\AI\Experiments\Plugin_Builder\Ai\Pipeline;
use WordPress\AI\Experiments\Plugin_Builder\Config;

/**
 * POST /wordpress-ai-plugin-builder/v1/generate — dispatch a background pipeline job.
 *
 * @since x.x.x
 */
class GenerateController {

	private const ROUTE_NAMESPACE = 'wordpress-ai-plugin-builder/v1';

	/**
	 * Register routes.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/generate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => static function () {
					return current_user_can( Config::generate_capability() );
				},
				'args'                => array(
					'description'    => array(
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => static function ( $value ) {
							if ( ! is_string( $value ) ) {
								return new \WP_Error( 'invalid_type', 'Description must be a string.' );
							}
							$len = mb_strlen( trim( $value ) );
							if ( $len < 10 ) {
								return new \WP_Error( 'too_short', 'Description must be at least 10 characters.' );
							}
							if ( $len > 5000 ) {
								return new \WP_Error( 'too_long', 'Description must not exceed 5000 characters.' );
							}
							return true;
						},
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'complexity'     => array(
						'type'              => 'string',
						'default'           => 'simple',
						'enum'              => array( 'simple', 'complex' ),
						'validate_callback' => 'rest_validate_request_arg',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'previous_plan'  => array(
						'type'    => array( 'object', 'null' ),
						'default' => null,
					),
					'previous_files' => array(
						'type'    => array( 'array', 'null' ),
						'default' => null,
					),
				),
			)
		);
	}

	/**
	 * Handle generation request.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$description    = $request->get_param( 'description' );
		$complexity     = $request->get_param( 'complexity' );
		$previous_plan  = $request->get_param( 'previous_plan' );
		$previous_files = $request->get_param( 'previous_files' );

		// Detect user intent before running the full pipeline.
		$detector       = new IntentDetector();
		$classification = $detector->classify( $description, $previous_plan );

		if ( is_wp_error( $classification ) ) {
			return new WP_REST_Response(
				array(
					'message' => 'Failed to process request: ' . $classification->get_error_message(),
				),
				500
			);
		}

		$intent = $classification['intent'];

		// Handle non-plugin requests immediately without background job.
		if ( 'question' === $intent || 'other' === $intent ) {
			return new WP_REST_Response(
				array(
					'type'     => $intent,
					'response' => $classification['response'],
				),
				200
			);
		}

		// For plugin_request or modification_request, proceed with generation.
		$job_id = wp_generate_uuid4();

		// Store initial state using direct database access to bypass object cache.
		Pipeline::set_job_state(
			'apb_job_' . $job_id,
			array(
				'job_id'          => $job_id,
				'status'          => 'queued',
				'current_step'    => 'Queued for processing...',
				'plan'            => null,
				'files'           => array(),
				'review'          => null,
				'error'           => null,
				'previous_plan'   => $previous_plan,
				'previous_files'  => $previous_files,
				'is_modification' => 'modification_request' === $intent,
			)
		);

		// Dispatch background job with context.
		BackgroundJob::dispatch( $job_id, $description, $complexity, $previous_plan, $previous_files );

		return new WP_REST_Response(
			array(
				'job_id' => $job_id,
				'status' => 'queued',
				'type'   => $intent,
			),
			202
		);
	}
}
