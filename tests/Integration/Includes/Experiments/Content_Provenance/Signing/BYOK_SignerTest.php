<?php
/**
 * Integration tests for BYOK_Signer.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Content_Provenance\Signing
 */

declare( strict_types=1 );

namespace WordPress\AI\Tests\Integration\Experiments\Content_Provenance\Signing;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Content_Provenance\Signing\BYOK_Signer;

/**
 * BYOK_Signer test case.
 *
 * @since 0.5.0
 */
class BYOK_SignerTest extends WP_UnitTestCase {

	/**
	 * Path to a temporary PEM key file created for tests.
	 *
	 * @since 0.5.0
	 * @var string
	 */
	private string $temp_key_file = '';

	/**
	 * Set up: write a temporary PEM key file.
	 *
	 * @since 0.5.0
	 */
	public function setUp(): void {
		parent::setUp();

		$res = openssl_pkey_new(
			array(
				'private_key_bits' => 1024,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);
		openssl_pkey_export( $res, $pem );

		$this->temp_key_file = sys_get_temp_dir() . '/byok_test_' . uniqid() . '.pem';
		file_put_contents( $this->temp_key_file, $pem );
	}

	/**
	 * Tear down: remove temporary key file.
	 *
	 * @since 0.5.0
	 */
	public function tearDown(): void {
		if ( $this->temp_key_file && file_exists( $this->temp_key_file ) ) {
			unlink( $this->temp_key_file );
		}
		parent::tearDown();
	}

	/**
	 * Test that get_tier returns 'byok'.
	 *
	 * @since 0.5.0
	 */
	public function test_get_tier_returns_byok(): void {
		$signer = new BYOK_Signer( '/some/path.pem' );
		$this->assertSame( 'byok', $signer->get_tier() );
	}

	/**
	 * Test that sign() with empty cert_path returns WP_Error.
	 *
	 * @since 0.5.0
	 */
	public function test_sign_with_empty_cert_path_returns_error(): void {
		$signer = new BYOK_Signer( '' );
		$result = $signer->sign( 'Content.', array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'c2pa_byok_no_cert', $result->get_error_code() );
	}

	/**
	 * Test that sign() with an unreadable cert path returns WP_Error.
	 *
	 * @since 0.5.0
	 */
	public function test_sign_with_unreadable_cert_path_returns_error(): void {
		$signer = new BYOK_Signer( '/nonexistent/path/key.pem' );
		$result = $signer->sign( 'Content.', array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'c2pa_byok_cert_unreadable', $result->get_error_code() );
	}

	/**
	 * Test that sign() with a valid key file returns a signed manifest string.
	 *
	 * @since 0.5.0
	 */
	public function test_sign_with_valid_key_returns_manifest_string(): void {
		$signer = new BYOK_Signer( $this->temp_key_file );
		$result = $signer->sign( 'Test content.', array( 'title' => 'Test' ) );

		$this->assertIsString( $result );
		$decoded = json_decode( $result, true );
		$this->assertIsArray( $decoded );
		$this->assertArrayHasKey( 'signature', $decoded );
		$this->assertSame( 'byok', $decoded['signer'] );
	}

	/**
	 * Test that sign() with a valid key file includes the public key in the manifest.
	 *
	 * @since 0.5.0
	 */
	public function test_sign_includes_public_key_in_manifest(): void {
		$signer = new BYOK_Signer( $this->temp_key_file );
		$result = $signer->sign( 'Content.', array() );

		$this->assertIsString( $result );
		$decoded = json_decode( $result, true );
		$this->assertNotEmpty( $decoded['public_key'] );
	}
}
