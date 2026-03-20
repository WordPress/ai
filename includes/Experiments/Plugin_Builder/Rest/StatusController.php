<?php
/**
 * REST API Status controller.
 *
 * @package WordPress\AI\Experiments
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Plugin_Builder\Rest;

use WP_REST_Request;
use WP_REST_Response;
use WordPress\AI\Experiments\Plugin_Builder\Ai\Pipeline;
use WordPress\AI\Experiments\Plugin_Builder\Config;

/**
 * GET /wordpress-ai-plugin-builder/v1/status/{job_id} — read job state from transient.
 *
 * @since x.x.x
 */
class StatusController {

	private const ROUTE_NAMESPACE = 'wordpress-ai-plugin-builder/v1';

	/**
	 * Register routes.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/status/(?P<job_id>[a-zA-Z0-9-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => static function () {
					return current_user_can( Config::generate_capability() );
				},
				'args'                => array(
					'job_id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Handle request.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$job_id        = $request->get_param( 'job_id' );
		$transient_key = 'apb_job_' . $job_id;

		// Use direct database access to bypass object cache issues on shared hosting.
		$state = Pipeline::get_job_state( $transient_key );

		if ( false === $state || ! is_array( $state ) ) {
			$response = new WP_REST_Response(
				array( 'error' => 'Job not found.' ),
				404
			);
			$this->add_no_cache_headers( $response );
			return $response;
		}

		// Add server timestamp for debugging cache issues.
		$state['_server_time'] = microtime( true );

		$response = new WP_REST_Response( $state, 200 );
		$this->add_no_cache_headers( $response );

		return $response;
	}

	/**
	 * Add aggressive no-cache headers for all caching layers.
	 *
	 * @param \WP_REST_Response $response The response.
	 */
	private function add_no_cache_headers( WP_REST_Response $response ): void {
		// Standard HTTP cache control.
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'Expires', 'Thu, 01 Jan 1970 00:00:00 GMT' );

		// LiteSpeed Cache (used by Hostinger).
		$response->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );

		// Cloudflare and other CDNs.
		$response->header( 'CDN-Cache-Control', 'no-store' );

		// Vary header to prevent cache sharing.
		$response->header( 'Vary', '*' );
	}
}
