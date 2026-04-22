<?php
/**
 * Integration tests for COSE_Sign1_Verifier.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Content_Provenance\C2PA
 */

declare( strict_types=1 );

namespace WordPress\AI\Tests\Integration\Experiments\Content_Provenance\C2PA;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Content_Provenance\C2PA\CBOR_Encoder;
use WordPress\AI\Experiments\Content_Provenance\C2PA\COSE_Sign1_Builder;
use WordPress\AI\Experiments\Content_Provenance\C2PA\COSE_Sign1_Verifier;
use WordPress\AI\Experiments\Content_Provenance\Signing\Local_Signer;

/**
 * COSE_Sign1_Verifier test case.
 *
 * @since x.x.x
 */
class COSE_Sign1_VerifierTest extends WP_UnitTestCase {

	/**
	 * Cached test keypair.
	 *
	 * @var array{private_key: string, certificate_pem: string}|null
	 */
	private static ?array $test_keypair = null;

	/**
	 * Gets or generates a test EC P-256 keypair.
	 *
	 * @return array{private_key: string, certificate_pem: string}
	 */
	private function get_test_keypair(): array {
		if ( null === self::$test_keypair ) {
			$keypair = Local_Signer::generate_keypair();
			$this->assertIsArray( $keypair );

			/** @var array{private_key: string, certificate_pem: string} $keypair */
			self::$test_keypair = $keypair;
		}

		return self::$test_keypair;
	}

	/**
	 * Builds a COSE_Sign1 structure from a payload using the test keypair.
	 *
	 * @param string $payload CBOR payload bytes.
	 * @return string COSE_Sign1 bytes.
	 */
	private function build_cose( string $payload ): string {
		$keypair         = $this->get_test_keypair();
		$certificate_der = Local_Signer::pem_to_der( $keypair['certificate_pem'] );

		$builder = new COSE_Sign1_Builder(
			$keypair['private_key'],
			$certificate_der,
			$payload
		);

		return $builder->build();
	}

	/**
	 * Test that a valid COSE_Sign1 signature verifies successfully.
	 *
	 * @since x.x.x
	 */
	public function test_verify_valid_signature(): void {
		$payload    = CBOR_Encoder::encode( array( 'test' => 'payload' ) );
		$cose_bytes = $this->build_cose( $payload );

		$result = COSE_Sign1_Verifier::verify( $cose_bytes );

		$this->assertTrue( $result['valid'] );
		$this->assertNull( $result['error'] );
	}

	/**
	 * Test that a tampered payload fails verification.
	 *
	 * @since x.x.x
	 */
	public function test_verify_tampered_payload_fails(): void {
		$payload    = CBOR_Encoder::encode( array( 'test' => 'payload' ) );
		$cose_bytes = $this->build_cose( $payload );

		// Tamper with the payload: flip a byte near the end of the COSE structure.
		// The payload is the third element. Flipping a byte in the middle should
		// corrupt either the payload or signature, causing verification to fail.
		$tampered = $cose_bytes;
		$mid      = (int) ( strlen( $tampered ) / 2 );
		$tampered[ $mid ] = chr( ord( $tampered[ $mid ] ) ^ 0xFF );

		$result = COSE_Sign1_Verifier::verify( $tampered );

		$this->assertFalse( $result['valid'] );
		$this->assertNotNull( $result['error'] );
	}

	/**
	 * Test that verify returns error for data too short.
	 *
	 * @since x.x.x
	 */
	public function test_verify_too_short(): void {
		$result = COSE_Sign1_Verifier::verify( "\xD2" );

		$this->assertFalse( $result['valid'] );
		$this->assertSame( 'COSE_Sign1 data too short.', $result['error'] );
	}

	/**
	 * Test that verify returns error for missing CBOR tag 18.
	 *
	 * @since x.x.x
	 */
	public function test_verify_missing_tag(): void {
		$result = COSE_Sign1_Verifier::verify( "\x84\x40\xA0\x40\x40" );

		$this->assertFalse( $result['valid'] );
		$this->assertSame( 'Missing CBOR tag 18.', $result['error'] );
	}

	/**
	 * Test that verify returns error for wrong array size.
	 *
	 * @since x.x.x
	 */
	public function test_verify_wrong_array_size(): void {
		$result = COSE_Sign1_Verifier::verify( "\xD2\x83\x40\xA0\x40" );

		$this->assertFalse( $result['valid'] );
		$this->assertSame( 'Expected 4-element CBOR array.', $result['error'] );
	}

	/**
	 * Test roundtrip: sign with builder, verify with verifier.
	 *
	 * @since x.x.x
	 */
	public function test_sign_verify_roundtrip(): void {
		$claim_data = array(
			'dc:title'       => 'Test Post',
			'dc:format'      => 'text/plain',
			'claimGenerator' => 'WordPress/AI c2pa-php/test',
		);
		$payload = CBOR_Encoder::encode( $claim_data );

		$cose_bytes = $this->build_cose( $payload );

		// Verify the signature.
		$result = COSE_Sign1_Verifier::verify( $cose_bytes );

		$this->assertTrue( $result['valid'] );
		$this->assertNull( $result['error'] );
	}

	/**
	 * Test that different payloads produce different signatures that both verify.
	 *
	 * @since x.x.x
	 */
	public function test_different_payloads_both_verify(): void {
		$payload_a = CBOR_Encoder::encode( array( 'content' => 'alpha' ) );
		$payload_b = CBOR_Encoder::encode( array( 'content' => 'beta' ) );

		$cose_a = $this->build_cose( $payload_a );
		$cose_b = $this->build_cose( $payload_b );

		$this->assertNotSame( $cose_a, $cose_b );
		$this->assertTrue( COSE_Sign1_Verifier::verify( $cose_a )['valid'] );
		$this->assertTrue( COSE_Sign1_Verifier::verify( $cose_b )['valid'] );
	}
}
