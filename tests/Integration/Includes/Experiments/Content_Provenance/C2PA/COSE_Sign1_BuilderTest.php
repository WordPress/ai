<?php
/**
 * Tests for the COSE_Sign1_Builder class.
 *
 * Validates RFC 9052 COSE_Sign1 structure with ES256 signing.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Content_Provenance\C2PA
 */

declare( strict_types=1 );

namespace WordPress\AI\Tests\Integration\Experiments\Content_Provenance\C2PA;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Content_Provenance\C2PA\CBOR_Encoder;
use WordPress\AI\Experiments\Content_Provenance\C2PA\COSE_Sign1_Builder;

/**
 * COSE_Sign1_Builder test case.
 *
 * @since 0.7.0
 */
class COSE_Sign1_BuilderTest extends WP_UnitTestCase {

	/**
	 * EC P-256 private key PEM.
	 *
	 * @var string
	 */
	private string $private_key_pem = '';

	/**
	 * DER-encoded self-signed certificate.
	 *
	 * @var string
	 */
	private string $certificate_der = '';

	/**
	 * Generate a test EC P-256 keypair and self-signed certificate.
	 */
	public function setUp(): void {
		parent::setUp();

		$key = openssl_pkey_new(
			array(
				'curve_name'       => 'prime256v1',
				'private_key_type' => OPENSSL_KEYTYPE_EC,
			)
		);

		$this->assertNotFalse( $key, 'Failed to generate EC P-256 key.' );

		openssl_pkey_export( $key, $pem );
		$this->private_key_pem = $pem;

		// Generate self-signed certificate.
		$dn  = array( 'commonName' => 'C2PA Test Signer' );
		$csr = openssl_csr_new( $dn, $key, array( 'digest_alg' => 'sha256' ) );
		$this->assertNotFalse( $csr, 'Failed to generate CSR.' );

		$cert = openssl_csr_sign( $csr, null, $key, 365, array( 'digest_alg' => 'sha256' ) );
		$this->assertNotFalse( $cert, 'Failed to sign certificate.' );

		openssl_x509_export( $cert, $cert_pem );

		// Convert PEM to DER.
		$cert_pem_body        = preg_replace( '/-----[A-Z ]+-----/', '', $cert_pem );
		$this->certificate_der = base64_decode( str_replace( array( "\r", "\n" ), '', $cert_pem_body ), true );

		$this->assertNotEmpty( $this->certificate_der, 'Certificate DER should not be empty.' );
	}

	/**
	 * Test that build() returns non-empty bytes.
	 */
	public function test_build_returns_bytes(): void {
		$payload = CBOR_Encoder::encode_map( array( 'test' => 'claim' ) );
		$builder = new COSE_Sign1_Builder( $this->private_key_pem, $this->certificate_der, $payload );
		$result  = $builder->build();

		$this->assertIsString( $result );
		$this->assertNotEmpty( $result );
	}

	/**
	 * Test that output starts with CBOR tag 18 (COSE_Sign1).
	 */
	public function test_output_has_cose_sign1_tag(): void {
		$payload = CBOR_Encoder::encode( 'test payload' );
		$builder = new COSE_Sign1_Builder( $this->private_key_pem, $this->certificate_der, $payload );
		$result  = $builder->build();

		// CBOR tag 18 is encoded as 0xd2 (major type 6, value 18).
		$this->assertSame( "\xd2", $result[0], 'First byte should be CBOR tag 18.' );
	}

	/**
	 * Test that the COSE_Sign1 array has exactly 4 elements.
	 */
	public function test_cose_sign1_has_four_elements(): void {
		$payload = CBOR_Encoder::encode( 'test' );
		$builder = new COSE_Sign1_Builder( $this->private_key_pem, $this->certificate_der, $payload );
		$result  = $builder->build();

		// After tag 18 (0xd2), should be a 4-element CBOR array (0x84).
		$this->assertSame( "\x84", $result[1], 'Second byte should be CBOR array of 4 items.' );
	}

	/**
	 * Test that protected header contains alg=ES256 ({1: -7}).
	 */
	public function test_protected_header_contains_es256(): void {
		$payload = CBOR_Encoder::encode( 'test' );
		$builder = new COSE_Sign1_Builder( $this->private_key_pem, $this->certificate_der, $payload );
		$result  = $builder->build();

		// Protected header is a byte string wrapping CBOR {1: -7} = a1 01 26.
		// After tag(0xd2) + array(0x84), the first element is a byte string.
		// The encoded protected header bytes {1: -7} = "\xa1\x01\x26" (3 bytes).
		// As a CBOR byte string: 0x43 a1 01 26.
		$this->assertSame( "\x43", $result[2], 'Third byte should be byte string of length 3.' );
		$this->assertSame( "\xa1\x01\x26", substr( $result, 3, 3 ), 'Protected header should be {1: -7}.' );
	}

	/**
	 * Test that the signature can be verified with the public key.
	 */
	public function test_signature_verifies_with_public_key(): void {
		$payload = CBOR_Encoder::encode_map( array( 'content' => 'Hello, C2PA!' ) );
		$builder = new COSE_Sign1_Builder( $this->private_key_pem, $this->certificate_der, $payload );
		$result  = $builder->build();

		// Extract the public key from the certificate.
		$cert    = openssl_x509_read( 'file://' . $this->write_temp_cert() );
		$pub_key = openssl_pkey_get_public( $cert );

		// Build the Sig_structure1 that was signed (manually, matching the builder).
		$protected_header = CBOR_Encoder::encode_map( array( 1 => -7 ) );
		$sig_structure    = "\x84"
			. CBOR_Encoder::encode( 'Signature1' )
			. CBOR_Encoder::encode_byte_string( $protected_header )
			. CBOR_Encoder::encode_byte_string( '' )
			. CBOR_Encoder::encode_byte_string( $payload );

		// Extract the signature from the COSE_Sign1 structure.
		$signature_raw = $this->extract_signature_from_cose( $result );
		$this->assertSame( 64, strlen( $signature_raw ), 'ES256 signature should be 64 bytes (R||S).' );

		// Convert raw R||S back to DER for openssl_verify.
		$signature_der = $this->raw_to_der_ecdsa( $signature_raw );

		$verify_result = openssl_verify( $sig_structure, $signature_der, $pub_key, OPENSSL_ALGO_SHA256 );
		$this->assertSame( 1, $verify_result, 'Signature should verify successfully.' );
	}

	/**
	 * Test that DER-to-raw conversion produces exactly 64 bytes for ES256.
	 */
	public function test_der_to_raw_produces_64_bytes(): void {
		$key = openssl_pkey_get_private( $this->private_key_pem );
		$this->assertNotFalse( $key );

		openssl_sign( 'test data for der conversion', $der_sig, $key, OPENSSL_ALGO_SHA256 );

		$raw = COSE_Sign1_Builder::der_to_raw_ecdsa( $der_sig );
		$this->assertSame( 64, strlen( $raw ), 'Raw ECDSA signature should be exactly 64 bytes.' );
	}

	/**
	 * Test that unprotected header contains x5chain with certificate.
	 */
	public function test_unprotected_header_contains_certificate(): void {
		$payload = CBOR_Encoder::encode( 'test' );
		$builder = new COSE_Sign1_Builder( $this->private_key_pem, $this->certificate_der, $payload );
		$result  = $builder->build();

		// The certificate DER bytes should appear somewhere in the COSE structure.
		$this->assertStringContainsString( $this->certificate_der, $result, 'COSE should contain the certificate.' );
	}

	/**
	 * Test building with different payloads produces different signatures.
	 */
	public function test_different_payloads_produce_different_signatures(): void {
		$builder1 = new COSE_Sign1_Builder(
			$this->private_key_pem,
			$this->certificate_der,
			CBOR_Encoder::encode( 'payload one' )
		);
		$builder2 = new COSE_Sign1_Builder(
			$this->private_key_pem,
			$this->certificate_der,
			CBOR_Encoder::encode( 'payload two' )
		);

		$sig1 = $this->extract_signature_from_cose( $builder1->build() );
		$sig2 = $this->extract_signature_from_cose( $builder2->build() );

		$this->assertNotSame( $sig1, $sig2, 'Different payloads should produce different signatures.' );
	}

	/**
	 * Test that the Sig_Structure uses CBOR byte strings for cross-verifier compatibility.
	 *
	 * The Sig_structure1 must use CBOR major type 2 (byte strings) for
	 * protected headers, external AAD, and payload, matching RFC 9052.
	 * Verifiers in other languages (Python cbor2, etc.) reconstruct the
	 * Sig_Structure with byte strings and will reject signatures over
	 * text-string-encoded structures.
	 */
	public function test_sig_structure_byte_strings_for_cross_verification(): void {
		$payload = CBOR_Encoder::encode_map( array( 'cross' => 'verify' ) );
		$builder = new COSE_Sign1_Builder( $this->private_key_pem, $this->certificate_der, $payload );
		$result  = $builder->build();

		// Extract public key.
		$cert    = openssl_x509_read( 'file://' . $this->write_temp_cert() );
		$pub_key = openssl_pkey_get_public( $cert );

		// Build a Sig_Structure manually with explicit byte strings (no encode() dispatch).
		// This is what a Python/Rust/Go verifier would construct.
		$protected_header = CBOR_Encoder::encode_map( array( 1 => -7 ) );
		$sig_structure    = "\x84"                                             // array(4)
			. CBOR_Encoder::encode( 'Signature1' )                             // tstr "Signature1"
			. CBOR_Encoder::encode_byte_string( $protected_header )            // bstr(protected)
			. CBOR_Encoder::encode_byte_string( '' )                           // bstr(external_aad)
			. CBOR_Encoder::encode_byte_string( $payload );                    // bstr(payload)

		// Extract signature and verify against the manually-built structure.
		$signature_raw = $this->extract_signature_from_cose( $result );
		$signature_der = $this->raw_to_der_ecdsa( $signature_raw );

		$verify_result = openssl_verify( $sig_structure, $signature_der, $pub_key, OPENSSL_ALGO_SHA256 );
		$this->assertSame( 1, $verify_result, 'Signature must verify against RFC 9052 byte-string Sig_Structure.' );
	}

	/**
	 * Writes the certificate PEM to a temp file and returns the path.
	 *
	 * @return string Temp file path.
	 */
	private function write_temp_cert(): string {
		$pem  = "-----BEGIN CERTIFICATE-----\n";
		$pem .= chunk_split( base64_encode( $this->certificate_der ), 64, "\n" );
		$pem .= '-----END CERTIFICATE-----';

		$path = sys_get_temp_dir() . '/c2pa_test_cert_' . uniqid() . '.pem';
		file_put_contents( $path, $pem );
		return $path;
	}

	/**
	 * Extracts the raw signature bytes from a COSE_Sign1 CBOR structure.
	 *
	 * Walks the CBOR structure: tag(18) → array[4] → skip 3 elements → byte string.
	 *
	 * @param string $cose_bytes COSE_Sign1 CBOR bytes.
	 * @return string Raw signature bytes.
	 */
	private function extract_signature_from_cose( string $cose_bytes ): string {
		// The signature is always the last element of the COSE_Sign1 array.
		// For ES256 it is a 64-byte byte string preceded by CBOR marker 0x58 0x40.
		$len = strlen( $cose_bytes );
		$this->assertGreaterThanOrEqual( 66, $len, 'COSE structure too short for signature.' );
		$this->assertSame( "\x58\x40", substr( $cose_bytes, $len - 66, 2 ), 'Expected 64-byte bstr marker before final signature.' );
		return substr( $cose_bytes, $len - 64 );
	}

	/**
	 * Converts raw R||S ECDSA signature back to DER format for openssl_verify.
	 *
	 * @param string $raw_signature 64-byte R||S signature.
	 * @return string DER-encoded signature.
	 */
	private function raw_to_der_ecdsa( string $raw_signature ): string {
		$r = substr( $raw_signature, 0, 32 );
		$s = substr( $raw_signature, 32, 32 );

		// Ensure positive integers (add leading 0x00 if high bit is set).
		if ( ord( $r[0] ) >= 0x80 ) {
			$r = "\x00" . $r;
		}
		if ( ord( $s[0] ) >= 0x80 ) {
			$s = "\x00" . $s;
		}

		// Strip leading zeros (but keep at least one byte).
		$r = ltrim( $r, "\x00" ) ?: "\x00";
		$s = ltrim( $s, "\x00" ) ?: "\x00";

		// Re-add leading zero if high bit is set after stripping.
		if ( ord( $r[0] ) >= 0x80 ) {
			$r = "\x00" . $r;
		}
		if ( ord( $s[0] ) >= 0x80 ) {
			$s = "\x00" . $s;
		}

		$r_der = "\x02" . chr( strlen( $r ) ) . $r;
		$s_der = "\x02" . chr( strlen( $s ) ) . $s;

		return "\x30" . chr( strlen( $r_der ) + strlen( $s_der ) ) . $r_der . $s_der;
	}
}
