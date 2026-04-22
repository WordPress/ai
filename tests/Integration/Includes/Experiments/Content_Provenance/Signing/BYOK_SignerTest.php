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
use WordPress\AI\Experiments\Content_Provenance\Signing\Local_Signer;

/**
 * BYOK_Signer test case.
 *
 * @since 0.7.0
 */
class BYOK_SignerTest extends WP_UnitTestCase {

	/**
	 * Path to a temporary PEM private key file created for tests.
	 *
	 * @since 0.7.0
	 * @var string
	 */
	private string $temp_key_file = '';

	/**
	 * Path to a temporary PEM certificate file created for tests.
	 *
	 * @since 0.7.0
	 * @var string
	 */
	private string $temp_cert_file = '';

	/**
	 * Set up: generate an EC P-256 keypair and write private key and certificate to separate temp files.
	 *
	 * @since 0.7.0
	 */
	public function setUp(): void {
		parent::setUp();

		$keypair = Local_Signer::generate_keypair();
		$this->assertIsArray( $keypair );

		/** @var array{private_key: string, certificate_pem: string} $keypair */
		$tmp_dir = ABSPATH . 'wp-content/';
		$tmp     = $tmp_dir . 'byok_test_' . uniqid();

		$this->temp_key_file  = $tmp . '_key.pem';
		$this->temp_cert_file = $tmp . '_cert.pem';

		file_put_contents( $this->temp_key_file, $keypair['private_key'] );
		file_put_contents( $this->temp_cert_file, $keypair['certificate_pem'] );
	}

	/**
	 * Tear down: remove temporary key and cert files if they exist.
	 *
	 * @since 0.7.0
	 */
	public function tearDown(): void {
		if ( $this->temp_key_file && file_exists( $this->temp_key_file ) ) {
			unlink( $this->temp_key_file );
		}
		if ( $this->temp_cert_file && file_exists( $this->temp_cert_file ) ) {
			unlink( $this->temp_cert_file );
		}
		parent::tearDown();
	}

	/**
	 * Test that get_tier returns 'byok'.
	 *
	 * @since 0.7.0
	 */
	public function test_get_tier_returns_byok(): void {
		$signer = new BYOK_Signer( '/some/path.pem' );
		$this->assertSame( 'byok', $signer->get_tier() );
	}

	/**
	 * Test that sign() with empty key_path returns WP_Error with c2pa_byok_no_cert.
	 *
	 * @since 0.7.0
	 */
	public function test_sign_with_empty_key_path_returns_error(): void {
		$signer = new BYOK_Signer( '' );
		$result = $signer->sign( 'Content.', array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'c2pa_byok_no_cert', $result->get_error_code() );
	}

	/**
	 * Test that sign() with a nonexistent key_path returns WP_Error with c2pa_byok_path_invalid.
	 *
	 * @since 0.7.0
	 */
	public function test_sign_with_unreadable_key_path_returns_error(): void {
		$signer = new BYOK_Signer( '/nonexistent/path/key.pem' );
		$result = $signer->sign( 'Content.', array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'c2pa_byok_path_invalid', $result->get_error_code() );
	}

	/**
	 * Test that sign() with a valid key file but empty cert_path returns WP_Error with c2pa_byok_no_cert_file.
	 *
	 * @since 0.7.0
	 */
	public function test_sign_with_missing_cert_path_returns_error(): void {
		$signer = new BYOK_Signer( $this->temp_key_file, '' );
		$result = $signer->sign( 'Content.', array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'c2pa_byok_no_cert_file', $result->get_error_code() );
	}

	/**
	 * Test that sign() with valid key and cert files returns JUMBF binary bytes.
	 *
	 * JUMBF starts with a box header: 4-byte size + 'jumb'.
	 *
	 * @since 0.7.0
	 */
	public function test_sign_with_valid_key_and_cert_returns_jumbf_bytes(): void {
		$signer = new BYOK_Signer( $this->temp_key_file, $this->temp_cert_file );
		$result = $signer->sign( 'Test content.', array( 'title' => 'Test' ) );

		$this->assertIsString( $result );
		$this->assertGreaterThan( 16, strlen( $result ) );
		$this->assertSame( 'jumb', substr( $result, 4, 4 ) );
	}

	/**
	 * Test that sign() with invalid key data returns WP_Error with c2pa_byok_key_load_failed.
	 *
	 * @since 0.7.0
	 */
	public function test_sign_with_invalid_key_returns_error(): void {
		file_put_contents( $this->temp_key_file, 'not-a-key' );

		$signer = new BYOK_Signer( $this->temp_key_file, $this->temp_cert_file );
		$result = $signer->sign( 'Content.', array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'c2pa_byok_key_load_failed', $result->get_error_code() );
	}
}
