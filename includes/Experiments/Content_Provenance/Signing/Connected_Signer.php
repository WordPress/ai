<?php
/**
 * Connected signing backend via CA-verified provider.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Content_Provenance\Signing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Signs C2PA manifests via a CA-verified signing provider.
 *
 * Delegates signing to an external API endpoint whose operator holds a
 * CA-issued certificate on the C2PA trust list, producing manifests that
 * standard verifiers recognise as trusted. Any compatible provider can be
 * used; see KNOWN_PROVIDERS for a maintained list.
 *
 * @since x.x.x
 */
class Connected_Signer implements Signing_Interface {

	/**
	 * Known compatible signing services and their endpoints.
	 *
	 * @since x.x.x
	 * @var array<string, array{url: string, name: string}>
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- False positive: single const with nested array, not multi-const.
	public const KNOWN_PROVIDERS = array(
		'encypher' => array(
			'url'  => 'https://api.encypher.com/v1/sign',
			'name' => 'Encypher',
		),
	);

	/**
	 * Default signing service endpoint.
	 *
	 * @since x.x.x
	 * @var string
	 */
	public const DEFAULT_SERVICE_URL = 'https://api.encypher.com/v1/sign';

	/**
	 * Remote signing service URL.
	 *
	 * @since x.x.x
	 * @var string
	 */
	private string $service_url;

	/**
	 * API key for authenticating with the signing service.
	 *
	 * @since x.x.x
	 * @var string
	 */
	private string $api_key;

	/**
	 * Constructor.
	 *
	 * @since x.x.x
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
	 * POSTs content and metadata to the signing service. The service builds
	 * a spec-compliant C2PA JUMBF manifest store and returns it base64-encoded.
	 *
	 * @since x.x.x
	 *
	 * @param string               $content  Plain text content to sign.
	 * @param array<string, mixed> $metadata Post metadata (title, post_id, etc.).
	 * @return string|\WP_Error JUMBF manifest store bytes or WP_Error on failure.
	 */
	public function sign( string $content, array $metadata ) {
		if ( empty( $this->service_url ) ) {
			return new \WP_Error(
				'c2pa_connected_no_url',
				esc_html__( 'Connected signing service URL is not configured.', 'ai' )
			);
		}

		$request_data = array(
			'content'  => $content,
			'metadata' => $metadata,
			'format'   => 'jumbf',
		);

		// Include previous manifest for ingredient chain when available.
		if ( isset( $metadata['previous_manifest'] ) && '' !== $metadata['previous_manifest'] ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary manifest must be base64-encoded for JSON transport.
			$request_data['previous_manifest'] = base64_encode( (string) $metadata['previous_manifest'] );
		}

		$body = wp_json_encode( $request_data );

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
				'timeout' => 10, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- Signing service may take several seconds.
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

		// The service returns the JUMBF manifest store as base64.
		$manifest_bytes = base64_decode( (string) $decoded['manifest'], true );

		if ( false === $manifest_bytes || '' === $manifest_bytes ) {
			return new \WP_Error(
				'c2pa_connected_invalid_manifest',
				esc_html__( 'Connected signing service returned a manifest that could not be decoded.', 'ai' )
			);
		}

		return $manifest_bytes;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 *
	 * @return string Always 'connected'.
	 */
	public function get_tier(): string {
		return 'connected';
	}
}
