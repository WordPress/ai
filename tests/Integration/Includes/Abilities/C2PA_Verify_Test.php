<?php
/**
 * Integration tests for C2PA_Verify ability.
 *
 * @package WordPress\AI\Tests\Integration\Abilities
 */

declare( strict_types=1 );

namespace WordPress\AI\Tests\Integration\Abilities;

use WP_UnitTestCase;
use WordPress\AI\Abilities\Content_Provenance\C2PA_Verify;
use WordPress\AI\Experiments\Content_Provenance\C2PA_Manifest_Builder;
use WordPress\AI\Experiments\Content_Provenance\Signing\Local_Signer;
use WordPress\AI\Experiments\Content_Provenance\Unicode_Embedder;

/**
 * C2PA_Verify ability test case.
 *
 * @since 0.5.0
 */
class C2PA_Verify_Test extends WP_UnitTestCase {

	/**
	 * Test that execute_callback returns WP_Error for empty text.
	 *
	 * @since 0.5.0
	 */
	public function test_execute_callback_returns_error_for_empty_text(): void {
		$ability = new C2PA_Verify();
		$result  = $this->invoke_execute( $ability, array( 'text' => '' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'c2pa_empty_text', $result->get_error_code() );
	}

	/**
	 * Test that execute_callback returns WP_Error for whitespace-only text.
	 *
	 * @since 0.5.0
	 */
	public function test_execute_callback_returns_error_for_whitespace_text(): void {
		$ability = new C2PA_Verify();
		$result  = $this->invoke_execute( $ability, array( 'text' => '   ' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Test that execute_callback returns 'unsigned' status for plain text.
	 *
	 * @since 0.5.0
	 */
	public function test_execute_callback_returns_unsigned_for_plain_text(): void {
		$ability = new C2PA_Verify();
		$result  = $this->invoke_execute( $ability, array( 'text' => 'Plain text, no provenance.' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'unsigned', $result['status'] );
		$this->assertFalse( $result['verified'] );
		$this->assertNull( $result['manifest'] );
	}

	/**
	 * Test that execute_callback returns 'verified' for properly signed text.
	 *
	 * @since 0.5.0
	 */
	public function test_execute_callback_returns_verified_for_signed_text(): void {
		$keypair = $this->generate_test_keypair();
		$signer  = new Local_Signer( $keypair );

		$content = 'Signed content for verification.';
		$built   = C2PA_Manifest_Builder::build( $content, 'c2pa.created', null, array(), $signer );
		$this->assertIsArray( $built );

		$signed_text = Unicode_Embedder::embed( $content, $built['manifest'] );

		$ability = new C2PA_Verify();
		$result  = $this->invoke_execute( $ability, array( 'text' => $signed_text ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'verified', $result['status'] );
		$this->assertTrue( $result['verified'] );
	}

	/**
	 * Test that execute_callback handles non-array input gracefully.
	 *
	 * @since 0.5.0
	 */
	public function test_execute_callback_handles_non_array_input(): void {
		$ability = new C2PA_Verify();
		$result  = $this->invoke_execute( $ability, null );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Test that permission_callback always returns true (public access).
	 *
	 * @since 0.5.0
	 */
	public function test_permission_callback_returns_true(): void {
		$ability = new C2PA_Verify();
		$ref     = new \ReflectionMethod( $ability, 'permission_callback' );
		$ref->setAccessible( true );

		$this->assertTrue( $ref->invoke( $ability, array() ) );
	}

	/**
	 * Test that input_schema requires 'text' property.
	 *
	 * @since 0.5.0
	 */
	public function test_input_schema_has_required_text(): void {
		$ability = new C2PA_Verify();
		$ref     = new \ReflectionMethod( $ability, 'input_schema' );
		$ref->setAccessible( true );
		$schema = $ref->invoke( $ability );

		$this->assertContains( 'text', $schema['required'] );
	}

	/**
	 * Test that output_schema includes verified, status, manifest, error properties.
	 *
	 * @since 0.5.0
	 */
	public function test_output_schema_has_expected_properties(): void {
		$ability = new C2PA_Verify();
		$ref     = new \ReflectionMethod( $ability, 'output_schema' );
		$ref->setAccessible( true );
		$schema = $ref->invoke( $ability );

		$this->assertArrayHasKey( 'verified', $schema['properties'] );
		$this->assertArrayHasKey( 'status', $schema['properties'] );
		$this->assertArrayHasKey( 'manifest', $schema['properties'] );
		$this->assertArrayHasKey( 'error', $schema['properties'] );
	}

	/**
	 * Invoke execute_callback via reflection.
	 *
	 * @since 0.5.0
	 *
	 * @param \WordPress\AI\Abilities\Content_Provenance\C2PA_Verify $ability The ability instance.
	 * @param mixed       $input   Input to pass.
	 * @return mixed
	 */
	private function invoke_execute( C2PA_Verify $ability, $input ) {
		$ref = new \ReflectionMethod( $ability, 'execute_callback' );
		$ref->setAccessible( true );
		return $ref->invoke( $ability, $input );
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
