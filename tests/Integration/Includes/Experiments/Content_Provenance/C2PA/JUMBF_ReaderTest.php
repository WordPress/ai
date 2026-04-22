<?php
/**
 * Integration tests for JUMBF_Reader.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Content_Provenance\C2PA
 */

declare( strict_types=1 );

namespace WordPress\AI\Tests\Integration\Experiments\Content_Provenance\C2PA;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Content_Provenance\C2PA\CBOR_Encoder;
use WordPress\AI\Experiments\Content_Provenance\C2PA\Claim_Builder;
use WordPress\AI\Experiments\Content_Provenance\C2PA\COSE_Sign1_Builder;
use WordPress\AI\Experiments\Content_Provenance\C2PA\JUMBF_Reader;
use WordPress\AI\Experiments\Content_Provenance\C2PA\JUMBF_Writer;
use WordPress\AI\Experiments\Content_Provenance\Signing\Local_Signer;

/**
 * JUMBF_Reader test case.
 *
 * @since x.x.x
 */
class JUMBF_ReaderTest extends WP_UnitTestCase {

	/**
	 * Test that extract_cose_signature returns COSE bytes from a valid manifest store.
	 *
	 * @since x.x.x
	 */
	public function test_extract_cose_signature_returns_cose_bytes(): void {
		$cose_bytes = "\xD2\x84" . str_repeat( "\x00", 20 );

		$manifest_store = JUMBF_Writer::build_manifest_store(
			CBOR_Encoder::encode( array( 'test' => 'claim' ) ),
			array( 'c2pa.hash.data' => CBOR_Encoder::encode( array( 'hash' => 'test' ) ) ),
			$cose_bytes,
			'urn:uuid:test-manifest'
		);

		$extracted = JUMBF_Reader::extract_cose_signature( $manifest_store );

		$this->assertNotNull( $extracted );
		$this->assertSame( $cose_bytes, $extracted );
	}

	/**
	 * Test that extract_cose_signature returns null for non-JUMBF data.
	 *
	 * @since x.x.x
	 */
	public function test_extract_cose_signature_returns_null_for_invalid_data(): void {
		$this->assertNull( JUMBF_Reader::extract_cose_signature( 'not jumbf data' ) );
	}

	/**
	 * Test that extract_cose_signature returns null for empty input.
	 *
	 * @since x.x.x
	 */
	public function test_extract_cose_signature_returns_null_for_empty(): void {
		$this->assertNull( JUMBF_Reader::extract_cose_signature( '' ) );
	}

	/**
	 * Test that extract_cose_signature returns null for truncated JUMBF.
	 *
	 * @since x.x.x
	 */
	public function test_extract_cose_signature_returns_null_for_truncated(): void {
		$this->assertNull( JUMBF_Reader::extract_cose_signature( "\x00\x00\x00\x10jumb" ) );
	}

	/**
	 * Test roundtrip: COSE bytes built by COSE_Sign1_Builder survive JUMBF write/read.
	 *
	 * @since x.x.x
	 */
	public function test_roundtrip_with_real_cose_signature(): void {
		$keypair = Local_Signer::generate_keypair();
		$this->assertIsArray( $keypair );

		/** @var array{private_key: string, certificate_pem: string} $keypair */
		$certificate_der = Local_Signer::pem_to_der( $keypair['certificate_pem'] );
		$claim_cbor      = CBOR_Encoder::encode( array( 'test' => 'claim' ) );

		$cose_builder = new COSE_Sign1_Builder(
			$keypair['private_key'],
			$certificate_der,
			$claim_cbor
		);
		$cose_bytes = $cose_builder->build();

		$manifest_store = JUMBF_Writer::build_manifest_store(
			$claim_cbor,
			array( 'c2pa.hash.data' => CBOR_Encoder::encode( array( 'hash' => 'test' ) ) ),
			$cose_bytes,
			'urn:uuid:test'
		);

		$extracted = JUMBF_Reader::extract_cose_signature( $manifest_store );

		$this->assertNotNull( $extracted );
		$this->assertSame( $cose_bytes, $extracted );
	}
}
