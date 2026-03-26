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
	 * Test that get_tier returns 'local'.
	 *
	 * @since 0.5.0
	 */
	public function test_get_tier_returns_local(): void {
		$signer = new Local_Signer(
			array(
				'private_key' => '',
				'public_key'  => '',
			)
		);
		$this->assertSame( 'local', $signer->get_tier() );
	}

	/**
	 * Test that sign() returns a JSON string with a valid keypair.
	 *
	 * @since 0.5.0
	 */
	public function test_sign_with_valid_keypair_returns_string(): void {
		$keypair = $this->generate_test_keypair();
		$signer  = new Local_Signer( $keypair );

		$result = $signer->sign( 'Test content.', array( 'title' => 'Test' ) );

		$this->assertIsString( $result );
		$decoded = json_decode( $result, true );
		$this->assertIsArray( $decoded );
		$this->assertArrayHasKey( 'signature', $decoded );
		$this->assertSame( 'local', $decoded['signer'] );
	}

	/**
	 * Test that sign() with invalid private key returns WP_Error.
	 *
	 * @since 0.5.0
	 */
	public function test_sign_with_invalid_private_key_returns_error(): void {
		$signer = new Local_Signer(
			array(
				'private_key' => 'not-a-key',
				'public_key'  => '',
			)
		);
		$result = $signer->sign( 'Test.', array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'c2pa_key_load_failed', $result->get_error_code() );
	}

	/**
	 * Test that sign() embeds the public key in the manifest.
	 *
	 * @since 0.5.0
	 */
	public function test_sign_embeds_public_key(): void {
		$keypair = $this->generate_test_keypair();
		$signer  = new Local_Signer( $keypair );
		$result  = $signer->sign( 'Content.', array() );

		$this->assertIsString( $result );
		$decoded = json_decode( $result, true );
		$this->assertSame( $keypair['public_key'], $decoded['public_key'] );
	}

	/**
	 * Generate a test RSA keypair.
	 *
	 * @since 0.5.0
	 * @return array{private_key: string, public_key: string}
	 */
	private function generate_test_keypair(): array {
		$res = openssl_pkey_new(
			array(
				'private_key_bits' => 1024,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);
		openssl_pkey_export( $res, $private_key );
		$details = openssl_pkey_get_details( $res );
		return array(
			'private_key' => $private_key,
			'public_key'  => $details['key'],
		);
	}
}
