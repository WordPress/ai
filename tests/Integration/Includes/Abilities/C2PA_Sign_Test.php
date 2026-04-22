<?php
/**
 * Integration tests for C2PA_Sign ability.
 *
 * @package WordPress\AI\Tests\Integration\Abilities
 */

declare( strict_types=1 );

namespace WordPress\AI\Tests\Integration\Abilities;

use WP_UnitTestCase;
use WordPress\AI\Abilities\Content_Provenance\C2PA_Sign;
use WordPress\AI\Experiments\Content_Provenance\Signing\Local_Signer;

/**
 * C2PA_Sign ability test case.
 *
 * @since 0.5.0
 */
class C2PA_Sign_Test extends WP_UnitTestCase {

	/**
	 * Tear down after each test.
	 *
	 * @since 0.5.0
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( '_c2pa_local_keypair' );
		parent::tearDown();
	}

	/**
	 * Test that the ability instantiates correctly.
	 *
	 * @since 0.5.0
	 */
	public function test_ability_name(): void {
		$ability = new C2PA_Sign( 'c2pa/sign', array( 'label' => 'C2PA: Sign Content', 'description' => 'Embed C2PA provenance into text content.' ) );
		$this->assertInstanceOf( C2PA_Sign::class, $ability );
	}

	/**
	 * Test that execute_callback returns WP_Error for empty text.
	 *
	 * @since 0.5.0
	 */
	public function test_sign_empty_text_returns_error(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$ability = new C2PA_Sign( 'c2pa/sign', array( 'label' => 'C2PA: Sign Content', 'description' => 'Embed C2PA provenance into text content.' ) );
		$ref     = new \ReflectionMethod( $ability, 'execute_callback' );
		$ref->setAccessible( true );
		$result = $ref->invoke( $ability, array( 'text' => '' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'c2pa_empty_text', $result->get_error_code() );
	}

	/**
	 * Test that execute_callback signs valid text and returns expected keys.
	 *
	 * @since 0.5.0
	 * @since 0.7.0 Updated for EC P-256 keypair format.
	 */
	public function test_sign_valid_text_returns_signed_text(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		// Pre-store an EC P-256 keypair so the fallback local signer works.
		$keypair = Local_Signer::generate_keypair();
		$this->assertIsArray( $keypair );
		update_option( '_c2pa_local_keypair', $keypair );

		$ability = new C2PA_Sign( 'c2pa/sign', array( 'label' => 'C2PA: Sign Content', 'description' => 'Embed C2PA provenance into text content.' ) );
		$ref     = new \ReflectionMethod( $ability, 'execute_callback' );
		$ref->setAccessible( true );
		$result = $ref->invoke( $ability, array( 'text' => 'Content to sign.' ) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'signed_text', $result );
		$this->assertArrayHasKey( 'manifest', $result );
		$this->assertArrayHasKey( 'signer_tier', $result );
		$this->assertStringContainsString( 'Content to sign.', $result['signed_text'] );
	}

	/**
	 * Test that execute_callback handles non-array input gracefully.
	 *
	 * @since 0.5.0
	 */
	public function test_sign_non_array_input_returns_error(): void {
		$ability = new C2PA_Sign( 'c2pa/sign', array( 'label' => 'C2PA: Sign Content', 'description' => 'Embed C2PA provenance into text content.' ) );
		$ref     = new \ReflectionMethod( $ability, 'execute_callback' );
		$ref->setAccessible( true );
		$result = $ref->invoke( $ability, null );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Test that permission_callback returns false for unauthenticated users.
	 *
	 * @since 0.5.0
	 */
	public function test_permission_callback_returns_false_for_unauthenticated(): void {
		wp_set_current_user( 0 );

		$ability = new C2PA_Sign( 'c2pa/sign', array( 'label' => 'C2PA: Sign Content', 'description' => 'Embed C2PA provenance into text content.' ) );
		$ref     = new \ReflectionMethod( $ability, 'permission_callback' );
		$ref->setAccessible( true );

		$this->assertFalse( $ref->invoke( $ability, array() ) );
	}

	/**
	 * Test that permission_callback returns true for a user with edit_posts.
	 *
	 * @since 0.5.0
	 */
	public function test_permission_callback_returns_true_for_editor(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$ability = new C2PA_Sign( 'c2pa/sign', array( 'label' => 'C2PA: Sign Content', 'description' => 'Embed C2PA provenance into text content.' ) );
		$ref     = new \ReflectionMethod( $ability, 'permission_callback' );
		$ref->setAccessible( true );

		$this->assertTrue( $ref->invoke( $ability, array() ) );
	}

	/**
	 * Test that input_schema requires 'text'.
	 *
	 * @since 0.5.0
	 */
	public function test_input_schema_requires_text(): void {
		$ability = new C2PA_Sign( 'c2pa/sign', array( 'label' => 'C2PA: Sign Content', 'description' => 'Embed C2PA provenance into text content.' ) );
		$ref     = new \ReflectionMethod( $ability, 'input_schema' );
		$ref->setAccessible( true );
		$schema = $ref->invoke( $ability );

		$this->assertContains( 'text', $schema['required'] );
	}

	/**
	 * Test that output_schema includes expected keys.
	 *
	 * @since 0.5.0
	 */
	public function test_output_schema_has_expected_properties(): void {
		$ability = new C2PA_Sign( 'c2pa/sign', array( 'label' => 'C2PA: Sign Content', 'description' => 'Embed C2PA provenance into text content.' ) );
		$ref     = new \ReflectionMethod( $ability, 'output_schema' );
		$ref->setAccessible( true );
		$schema = $ref->invoke( $ability );

		$this->assertArrayHasKey( 'signed_text', $schema['properties'] );
		$this->assertArrayHasKey( 'manifest', $schema['properties'] );
		$this->assertArrayHasKey( 'signer_tier', $schema['properties'] );
	}

	/**
	 * Test that execute_callback uses the experiment signer when provided via filter.
	 *
	 * Covers the '$experiment ? $experiment->get_public_signer() : ...' branch
	 * when get_experiment() returns a non-null Content_Provenance instance.
	 *
	 * @since 0.5.0
	 * @since 0.7.0 Updated for EC P-256 keypair format.
	 */
	public function test_sign_uses_experiment_signer_when_filter_provides_experiment(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		// Pre-store an EC P-256 keypair so the experiment's local signer works.
		$keypair = Local_Signer::generate_keypair();
		$this->assertIsArray( $keypair );
		update_option( '_c2pa_local_keypair', $keypair );

		$experiment = new \WordPress\AI\Experiments\Content_Provenance\Content_Provenance();

		add_filter(
			'wpai_content_provenance_experiment_instance',
			static function () use ( $experiment ) {
				return $experiment;
			}
		);

		$ability = new C2PA_Sign( 'c2pa/sign', array( 'label' => 'C2PA: Sign Content', 'description' => 'Embed C2PA provenance into text content.' ) );
		$ref     = new \ReflectionMethod( $ability, 'execute_callback' );
		$ref->setAccessible( true );
		$result = $ref->invoke( $ability, array( 'text' => 'Content via experiment signer.' ) );

		remove_all_filters( 'wpai_content_provenance_experiment_instance' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'signed_text', $result );
		$this->assertArrayHasKey( 'manifest', $result );
		$this->assertSame( 'local', $result['signer_tier'] );
	}

	/**
	 * Test that execute_callback returns WP_Error when the signer itself fails.
	 *
	 * Covers the 'is_wp_error($result)' branch in execute_callback.
	 *
	 * @since 0.5.0
	 */
	public function test_sign_returns_error_when_signer_fails(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		// Provide a mock experiment whose signer always fails.
		$mock_signer = new class() implements \WordPress\AI\Experiments\Content_Provenance\Signing\Signing_Interface {
			/**
			 * Always fail.
			 *
			 * @param string              $content  Content.
			 * @param array<string,mixed> $metadata Metadata.
			 * @return \WP_Error
			 */
			public function sign( string $content, array $metadata ) {
				return new \WP_Error( 'signer_error', 'Signing failed.' );
			}

			/**
			 * Return tier.
			 *
			 * @return string
			 */
			public function get_tier(): string {
				return 'mock';
			}
		};

		$mock_experiment = $this->createMock( \WordPress\AI\Experiments\Content_Provenance\Content_Provenance::class );
		$mock_experiment->method( 'get_public_signer' )->willReturn( $mock_signer );

		add_filter(
			'wpai_content_provenance_experiment_instance',
			static function () use ( $mock_experiment ) {
				return $mock_experiment;
			}
		);

		$ability = new C2PA_Sign( 'c2pa/sign', array( 'label' => 'C2PA: Sign Content', 'description' => 'Embed C2PA provenance into text content.' ) );
		$ref     = new \ReflectionMethod( $ability, 'execute_callback' );
		$ref->setAccessible( true );
		$result = $ref->invoke( $ability, array( 'text' => 'Content to sign.' ) );

		remove_all_filters( 'wpai_content_provenance_experiment_instance' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'signer_error', $result->get_error_code() );
	}
}
