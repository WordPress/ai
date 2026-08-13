<?php
/**
 * The switch and helper for the REST-backed ability implementations.
 *
 * @package WordPress\AI
 *
 * @since 1.3.0
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Rest_Backend
 *
 * The `core/read-content`, `core/read-settings` and `core/read-users` abilities repeat
 * logic that the REST API already implements. Each of them ships a second execute
 * implementation that calls the matching REST endpoint instead, maps the ability input to
 * REST request parameters, and maps the REST response back to the ability output shape.
 *
 * Both implementations are always loaded. This class decides which one runs, so the two
 * can be compared against the same test suite:
 *
 *     // wp-config.php, or tests/bootstrap.php for the test suite.
 *     define( 'WPAI_ABILITIES_REST_BACKEND', true );
 *
 *     // Or at runtime.
 *     add_filter( 'wpai_abilities_rest_backend', '__return_true' );
 *
 * Only the execute callbacks are switched. The permission callbacks stay with the native
 * implementation, because they are the abilities' own authorization contract: the REST
 * endpoints answer a related but different question (for example REST grants `roles` to
 * anyone who can list users, while the ability requires edit access).
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since 1.3.0
 */
final class Rest_Backend {

	/**
	 * The constant that turns the REST-backed implementations on.
	 *
	 * @since 1.3.0
	 * @var string
	 */
	private const CONSTANT = 'WPAI_ABILITIES_REST_BACKEND';

	/**
	 * Checks whether the abilities should execute through the REST API.
	 *
	 * @since 1.3.0
	 *
	 * @return bool True when the REST-backed implementations are active.
	 */
	public static function is_enabled(): bool {
		$enabled = defined( self::CONSTANT ) && constant( self::CONSTANT );

		/**
		 * Filters whether the core read abilities execute through the REST API.
		 *
		 * @since 1.3.0
		 *
		 * @param bool $enabled Whether the REST-backed execute implementations are used.
		 */
		return (bool) apply_filters( 'wpai_abilities_rest_backend', (bool) $enabled );
	}

	/**
	 * Performs an internal `GET` request against a REST route.
	 *
	 * The request never leaves the site: {@see rest_do_request()} dispatches it through the
	 * REST server in the current process, so it runs as the current user and skips HTTP.
	 *
	 * The parameters are set as query parameters, which is what they would be over HTTP.
	 * {@see WP_REST_Request::set_param()} would instead write them to whichever parameter
	 * type comes first in the order, and that order is filterable. With `URL` first they
	 * would land there, and dispatching replaces every URL parameter with the ones matched
	 * from the route, so they would be dropped on the way in.
	 *
	 * @since 1.3.0
	 *
	 * @param string              $route  The REST route, for example `/wp/v2/posts`.
	 * @param array<string, mixed> $params Request parameters.
	 * @return \WP_REST_Response|\WP_Error The response, or the error the endpoint returned.
	 */
	public static function get( string $route, array $params = array() ) {
		$request = new WP_REST_Request( 'GET', $route );
		$request->set_query_params( $params );

		$response = rest_do_request( $request );

		if ( ! $response->is_error() ) {
			return $response;
		}

		// An errored response always carries an error, so the fallback is never reached.
		return $response->as_error() ?? new WP_Error( 'rest_request_failed', __( 'The REST request failed.', 'ai' ) );
	}

	/**
	 * Reads a pagination header from a REST response.
	 *
	 * @since 1.3.0
	 *
	 * @param \WP_REST_Response $response The REST response.
	 * @param string            $header   The header name, for example `X-WP-Total`.
	 * @return int The header value as an integer, or 0 when the header is missing.
	 */
	public static function pagination_header( WP_REST_Response $response, string $header ): int {
		$headers = $response->get_headers();

		return isset( $headers[ $header ] ) && is_scalar( $headers[ $header ] ) ? (int) $headers[ $header ] : 0;
	}

	/**
	 * Returns the response data as an array.
	 *
	 * A successful response that does not carry a list or a map is a response the mapping
	 * cannot read. Reporting it as an empty array would make it look like a valid empty
	 * result, so it is reported as an error instead.
	 *
	 * @since 1.3.0
	 *
	 * @param \WP_REST_Response $response The REST response.
	 * @return array<mixed>|\WP_Error The response data, or an error when it is not a list or map.
	 */
	public static function data( WP_REST_Response $response ) {
		$data = $response->get_data();

		return is_array( $data ) ? $data : self::unexpected_response_error();
	}

	/**
	 * Builds the error for a response the mapping cannot read.
	 *
	 * @since 1.3.0
	 *
	 * @return \WP_Error The unexpected-response error.
	 */
	public static function unexpected_response_error(): WP_Error {
		return new WP_Error(
			'rest_unexpected_response',
			__( 'The REST API returned a response in an unexpected shape.', 'ai' ),
			array( 'status' => 500 )
		);
	}
}
