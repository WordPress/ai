<?php
/**
 * Connected signing backend.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Content_Provenance\Signing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Signs C2PA manifests via a remote signing service.
 *
 * Delegates signing to an external API endpoint. Suitable for organisations
 * that operate a central key-management service with a proper certificate chain.
 * API credentials are stored as WordPress options (never in source control).
 *
 * @since 0.5.0
 */
class Connected_Signer implements Signing_Interface {

	/**
	 * Remote signing service URL.
	 *
	 * @since 0.5.0
	 * @var string
	 */
	private string $service_url;

	/**
	 * API key for authenticating with the signing service.
	 *
	 * @since 0.5.0
	 * @var string
	 */
	private string $api_key;

	/**
	 * Constructor.
	 *
	 * @since 0.5.0
	 *
	 * @param string $service_url Remote signing service base URL.
	 * @param string $api_key     Bearer token for service authentication.
	 */
	public function __construct( string $service_url, string $api_key ) {
		$this->service_url = $service_url;
		$this->api_key     = $api_key;
	}

	/**
	 * {@inheritDoc}
	 *
	 * POSTs content and claims to the configured signing service and returns
	 * the manifest JSON produced by the service.
	 *
	 * @since 0.5.0
	 *
	 * @param string              $content Plain text content to sign.
	 * @param array<string,mixed> $claims  C2PA claims/assertions to embed.
	 * @return string|\WP_Error JSON manifest string or WP_Error on failure.
	 */
	public function sign( string $content, array $claims ) {
		if ( empty( $this->service_url ) ) {
			return new \WP_Error(
				'c2pa_connected_no_url',
				esc_html__( 'Connected signing service URL is not configured.', 'ai' )
			);
		}

		$body = wp_json_encode(
			array(
				'content' => $content,
				'claims'  => $claims,
			)
		);

		if ( false === $body ) {
			return new \WP_Error(
				'c2pa_request_encode_failed',
				esc_html__( 'Failed to encode signing request body.', 'ai' )
			);
		}

		$response = wp_remote_post(
			$this->service_url,
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $this->api_key,
				),
				'body'    => $body,
				'timeout' => 3,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'c2pa_connected_request_failed',
				sprintf(
					/* translators: %s: Error message from the signing service request. */
					esc_html__( 'Connected signing service request failed: %s', 'ai' ),
					esc_html( $response->get_error_message() )
				)
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== (int) $status_code ) {
			return new \WP_Error(
				'c2pa_connected_bad_response',
				sprintf(
					/* translators: %d: HTTP status code returned by the signing service. */
					esc_html__( 'Connected signing service returned HTTP %d.', 'ai' ),
					(int) $status_code
				)
			);
		}

		$body_raw = wp_remote_retrieve_body( $response );
		$decoded  = json_decode( $body_raw, true );

		if ( ! is_array( $decoded ) || empty( $decoded['manifest'] ) ) {
			return new \WP_Error(
				'c2pa_connected_invalid_response',
				esc_html__( 'Connected signing service returned an invalid or empty manifest.', 'ai' )
			);
		}

		return is_string( $decoded['manifest'] ) ? $decoded['manifest'] : (string) wp_json_encode( $decoded['manifest'] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.5.0
	 *
	 * @return string Always 'connected'.
	 */
	public function get_tier(): string {
		return 'connected';
	}
}
