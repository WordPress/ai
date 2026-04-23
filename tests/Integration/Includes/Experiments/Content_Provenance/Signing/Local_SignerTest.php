<?php
/**
 * Integration tests for Local_Signer.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Content_Provenance\Signing
 */

declare( strict_types=1 );

namespace WordPress\AI\Tests\Integration\Experiments\Content_Provenance\Signing;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Content_Provenance\Signing\Local_Signer;

/**
 * Local_Signer test case.
 *
 * @since 0.5.0
 */
class Local_SignerTest extends WP_UnitTestCase {

	/**
	 * Test that get_tier() returns 'local'.
	 *
	 * @since 0.7.0
	 */
	public function test_get_tier_returns_local(): void {
		$signer = new Local_Signer(
			array(
				'private_key'     => '',
				'certificate_pem' => '',
			)
		);
		$this->assertSame( 'local', $signer->get_tier() );
	}

	/**
	 * Test that sign() with a valid EC keypair returns JUMBF binary bytes.
	 *
	 * @since 0.7.0
	 */
	public function test_sign_with_valid_keypair_returns_jumbf_bytes(): void {
		$keypair = $this->generate_test_keypair();
		$signer  = new Local_Signer( $keypair );

		$result = $signer->sign( 'Test content.', array( 'title' => 'Test' ) );

		$this->assertIsString( $result );
		// JUMBF box: 4-byte big-endian size followed by the 'jumb' box type.
		$this->assertSame( 'jumb', substr( $result, 4, 4 ) );
	}

	/**
	 * Test that sign() with an empty private key returns a WP_Error with code 'c2pa_key_load_failed'.
	 *
	 * @since 0.7.0
	 */
	public function test_sign_with_empty_private_key_returns_error(): void {
		$signer = new Local_Signer(
			array(
				'private_key'     => '',
				'certificate_pem' => '',
			)
		);

		$result = $signer->sign( 'Test.', array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'c2pa_key_load_failed', $result->get_error_code() );
	}

	/**
	 * Test that sign() with a valid key but empty certificate_pem returns a WP_Error with code 'c2pa_cert_invalid'.
	 *
	 * @since 0.7.0
	 */
	public function test_sign_with_missing_certificate_returns_error(): void {
		$keypair = $this->generate_test_keypair();
		$signer  = new Local_Signer(
			array(
				'private_key'     => $keypair['private_key'],
				'certificate_pem' => '',
			)
		);

		$result = $signer->sign( 'Test.', array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'c2pa_cert_invalid', $result->get_error_code() );
	}

	/**
	 * Test that generate_keypair() returns an EC P-256 keypair with both keys present.
	 *
	 * @since 0.7.0
	 */
	public function test_generate_keypair_returns_ec_p256(): void {
		$keypair = Local_Signer::generate_keypair( 'Test Site' );

		$this->assertIsArray( $keypair );
		$this->assertArrayHasKey( 'private_key', $keypair );
		$this->assertArrayHasKey( 'certificate_pem', $keypair );
		$this->assertNotEmpty( $keypair['private_key'] );
		$this->assertNotEmpty( $keypair['certificate_pem'] );

		$key_resource = openssl_pkey_get_private( $keypair['private_key'] );
		$this->assertNotFalse( $key_resource, 'Private key must be a valid PEM key.' );

		$details = openssl_pkey_get_details( $key_resource );
		$this->assertIsArray( $details );
		$this->assertSame( OPENSSL_KEYTYPE_EC, $details['type'] );
		$this->assertSame( 'prime256v1', $details['ec']['curve_name'] );
	}

	/**
	 * Test that pem_to_der() converts a certificate PEM to DER bytes starting with the ASN.1 SEQUENCE tag (0x30).
	 *
	 * @since 0.7.0
	 */
	public function test_pem_to_der_converts_certificate(): void {
		$keypair = $this->generate_test_keypair();

		$der = Local_Signer::pem_to_der( $keypair['certificate_pem'] );

		$this->assertNotEmpty( $der );
		// ASN.1 SEQUENCE tag is 0x30 (decimal 48).
		$this->assertSame( 0x30, ord( $der[0] ) );
	}

	/**
	 * Test that sign() produces different JUMBF output for different content strings.
	 *
	 * @since 0.7.0
	 */
	public function test_sign_produces_different_manifests_for_different_content(): void {
		$keypair = $this->generate_test_keypair();
		$signer  = new Local_Signer( $keypair );

		$result_a = $signer->sign( 'Content A.', array( 'title' => 'Post A' ) );
		$result_b = $signer->sign( 'Content B.', array( 'title' => 'Post B' ) );

		$this->assertIsString( $result_a );
		$this->assertIsString( $result_b );
		$this->assertNotSame( $result_a, $result_b );
	}

	/**
	 * Generate an EC P-256 test keypair using Local_Signer::generate_keypair().
	 *
	 * @since 0.7.0
	 * @return array{private_key: string, certificate_pem: string}
	 */
	private function generate_test_keypair(): array {
		$keypair = Local_Signer::generate_keypair( 'Test' );

		if ( is_wp_error( $keypair ) ) {
			$this->fail( 'generate_keypair() failed: ' . $keypair->get_error_message() );
		}

		return $keypair;
	}
}
