<?php
/**
 * Integration tests for Connected_Signer.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Content_Provenance\Signing
 */

declare( strict_types=1 );

namespace WordPress\AI\Tests\Integration\Experiments\Content_Provenance\Signing;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Content_Provenance\Signing\Connected_Signer;

/**
 * Connected_Signer test case.
 *
 * @since 0.5.0
 */
class Connected_SignerTest extends WP_UnitTestCase {

	/**
	 * Test that get_tier returns 'connected'.
	 *
	 * @since 0.5.0
	 */
	public function test_get_tier_returns_connected(): void {
		$signer = new Connected_Signer( 'https://example.com/sign', 'api-key' );
		$this->assertSame( 'connected', $signer->get_tier() );
	}

	/**
	 * Test that sign() with empty service URL returns WP_Error.
	 *
	 * @since 0.5.0
	 */
	public function test_sign_with_empty_url_returns_error(): void {
		$signer = new Connected_Signer( '', 'api-key' );
		$result = $signer->sign( 'Content.', array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'c2pa_connected_no_url', $result->get_error_code() );
	}

	/**
	 * Test that sign() returns WP_Error when the HTTP request itself fails.
	 *
	 * @since 0.5.0
	 */
	public function test_sign_returns_error_on_http_failure(): void {
		add_filter(
			'pre_http_request',
			static function () {
				return new \WP_Error( 'http_request_failed', 'Connection refused.' );
			}
		);

		$signer = new Connected_Signer( 'https://example.com/sign', 'api-key' );
		$result = $signer->sign( 'Content.', array() );

		remove_all_filters( 'pre_http_request' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'c2pa_connected_request_failed', $result->get_error_code() );
	}

	/**
	 * Test that sign() returns WP_Error on a non-200 HTTP response.
	 *
	 * @since 0.5.0
	 */
	public function test_sign_returns_error_on_bad_status_code(): void {
		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'response' => array(
						'code'    => 500,
						'message' => 'Internal Server Error',
					),
					'body'     => '',
					'headers'  => array(),
				);
			}
		);

		$signer = new Connected_Signer( 'https://example.com/sign', 'api-key' );
		$result = $signer->sign( 'Content.', array() );

		remove_all_filters( 'pre_http_request' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'c2pa_connected_bad_response', $result->get_error_code() );
	}

	/**
	 * Test that sign() returns WP_Error when response body is missing the manifest key.
	 *
	 * @since 0.5.0
	 */
	public function test_sign_returns_error_on_invalid_response_body(): void {
		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode( array( 'unexpected' => 'data' ) ),
					'headers'  => array(),
				);
			}
		);

		$signer = new Connected_Signer( 'https://example.com/sign', 'api-key' );
		$result = $signer->sign( 'Content.', array() );

		remove_all_filters( 'pre_http_request' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'c2pa_connected_invalid_response', $result->get_error_code() );
	}

	/**
	 * Test that sign() returns the manifest string on a successful response.
	 *
	 * @since 0.5.0
	 */
	public function test_sign_returns_manifest_on_success(): void {
		$manifest = wp_json_encode(
			array(
				'magic'  => 'test',
				'signer' => 'connected',
			)
		);

		add_filter(
			'pre_http_request',
			static function () use ( $manifest ) {
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode( array( 'manifest' => $manifest ) ),
					'headers'  => array(),
				);
			}
		);

		$signer = new Connected_Signer( 'https://example.com/sign', 'api-key' );
		$result = $signer->sign( 'Content.', array() );

		remove_all_filters( 'pre_http_request' );

		$this->assertSame( $manifest, $result );
	}
}
