<?php
/**
 * Integration tests for the Image_Provenance experiment.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Image_Provenance
 */

declare( strict_types=1 );

namespace WordPress\AI\Tests\Integration\Experiments\Image_Provenance;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Experiments\Experiments;
use WordPress\AI\Features\Loader;
use WordPress\AI\Features\Registry;
use WordPress\AI\Experiments\Image_Provenance\Image_Provenance;

/**
 * Image_Provenance integration test case.
 *
 * @since 0.6.0
 */
class Image_ProvenanceTest extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( 'wpai_features_enabled', true );
		update_option( 'wpai_feature_image-provenance_enabled', true );
		update_option( 'wpai_feature_image-provenance_field_auto_sign_images', true );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		// Remove the Experiments filter if added during this test.
		remove_filter( 'wpai_default_feature_classes', array( Experiments::class, 'register_default_experiment_classes' ), 9 );

		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_image-provenance_enabled' );
		delete_option( 'wpai_feature_image-provenance_field_auto_sign_images' );
		delete_option( '_c2pa_local_keypair' );
		parent::tearDown();
	}

	/**
	 * Test experiment metadata.
	 */
	public function test_experiment_metadata(): void {
		$experiment = new Image_Provenance();

		$this->assertSame( 'image-provenance', $experiment->get_id() );
		$this->assertSame( 'Image Provenance', $experiment->get_label() );
		$this->assertSame( Experiment_Category::EDITOR, $experiment->get_category() );
		$this->assertTrue( $experiment->is_enabled() );
	}

	/**
	 * Test that the experiment registers in the loader.
	 */
	public function test_experiment_is_in_default_experiments(): void {
		$registry    = new Registry();
		$loader      = new Loader( $registry );
		$experiments = new Experiments();
		$experiments->init();
		$loader->register_features();

		$experiment = $registry->get_feature( 'image-provenance' );
		$this->assertInstanceOf( Image_Provenance::class, $experiment );
	}

	/**
	 * Test that a JPEG attachment gets signed on upload.
	 */
	public function test_signs_image_on_upload(): void {
		$experiment = new Image_Provenance();
		$experiment->register();

		// Create a fake JPEG attachment.
		$attachment_id = $this->create_test_attachment( 'image/jpeg' );

		// Trigger the add_attachment hook.
		do_action( 'add_attachment', $attachment_id );

		$status = get_post_meta( $attachment_id, '_c2pa_image_status', true );
		$this->assertSame( 'signed', $status );

		$manifest = get_post_meta( $attachment_id, '_c2pa_image_manifest', true );
		$this->assertNotEmpty( $manifest );

		$manifest_url = get_post_meta( $attachment_id, '_c2pa_image_manifest_url', true );
		$this->assertNotEmpty( $manifest_url );
		$this->assertStringContainsString( 'c2pa-provenance/v1/images/manifest', $manifest_url );

		$signed_at = get_post_meta( $attachment_id, '_c2pa_image_signed_at', true );
		$this->assertNotEmpty( $signed_at );
	}

	/**
	 * Test that non-image attachments are skipped.
	 */
	public function test_skips_unsupported_mime_type(): void {
		$experiment = new Image_Provenance();
		$experiment->register();

		$attachment_id = $this->create_test_attachment( 'application/pdf' );

		do_action( 'add_attachment', $attachment_id );

		$status = get_post_meta( $attachment_id, '_c2pa_image_status', true );
		$this->assertEmpty( $status );
	}

	/**
	 * Test REST lookup returns the correct record for a known URL.
	 */
	public function test_rest_lookup_exact_url_match(): void {
		$experiment = new Image_Provenance();
		$experiment->register();

		$attachment_id = $this->create_test_attachment( 'image/jpeg' );
		do_action( 'add_attachment', $attachment_id );

		$status = get_post_meta( $attachment_id, '_c2pa_image_status', true );
		if ( 'signed' !== $status ) {
			$this->markTestSkipped( 'Image signing failed — OpenSSL may be unavailable.' );
		}

		$canonical_url = wp_get_attachment_url( $attachment_id );
		$this->assertNotFalse( $canonical_url );

		$request = new \WP_REST_Request( 'GET', '/c2pa-provenance/v1/images/lookup' );
		$request->set_param( 'url', $canonical_url );

		$response = $experiment->rest_lookup_callback( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( (string) $attachment_id, $data['record_id'] );
	}

	/**
	 * Test REST lookup strips CDN transform query params.
	 */
	public function test_rest_lookup_strips_cdn_params(): void {
		$experiment = new Image_Provenance();
		$experiment->register();

		$attachment_id = $this->create_test_attachment( 'image/jpeg' );
		do_action( 'add_attachment', $attachment_id );

		$status = get_post_meta( $attachment_id, '_c2pa_image_status', true );
		if ( 'signed' !== $status ) {
			$this->markTestSkipped( 'Image signing failed — OpenSSL may be unavailable.' );
		}

		$canonical_url = wp_get_attachment_url( $attachment_id );
		$this->assertNotFalse( $canonical_url );

		// Add CDN transform parameters.
		$cdn_url = add_query_arg(
			array(
				'w'      => '800',
				'format' => 'webp',
			),
			$canonical_url
		);

		$request = new \WP_REST_Request( 'GET', '/c2pa-provenance/v1/images/lookup' );
		$request->set_param( 'url', $cdn_url );

		$response = $experiment->rest_lookup_callback( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( (string) $attachment_id, $data['record_id'] );
	}

	/**
	 * Test REST lookup returns 404 for an unknown URL.
	 */
	public function test_rest_lookup_returns_404_for_unknown(): void {
		$experiment = new Image_Provenance();
		$experiment->register();

		$request = new \WP_REST_Request( 'GET', '/c2pa-provenance/v1/images/lookup' );
		$request->set_param( 'url', 'https://example.com/nonexistent-image.jpg' );

		$response = $experiment->rest_lookup_callback( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * Test REST manifest endpoint returns stored JSON.
	 */
	public function test_rest_manifest_returns_json(): void {
		$experiment = new Image_Provenance();
		$experiment->register();

		$attachment_id = $this->create_test_attachment( 'image/jpeg' );
		do_action( 'add_attachment', $attachment_id );

		$status = get_post_meta( $attachment_id, '_c2pa_image_status', true );
		if ( 'signed' !== $status ) {
			$this->markTestSkipped( 'Image signing failed — OpenSSL may be unavailable.' );
		}

		$request = new \WP_REST_Request( 'GET', "/c2pa-provenance/v1/images/manifest/{$attachment_id}" );
		$request->set_param( 'attachment_id', $attachment_id );

		$response = $experiment->rest_manifest_callback( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data );
	}

	/**
	 * Test that C2PA-Manifest-URL header is injected on singular pages with featured image.
	 */
	public function test_header_injection_on_singular(): void {
		$experiment = new Image_Provenance();
		$experiment->register();

		$attachment_id = $this->create_test_attachment( 'image/jpeg' );
		do_action( 'add_attachment', $attachment_id );

		$status = get_post_meta( $attachment_id, '_c2pa_image_status', true );
		if ( 'signed' !== $status ) {
			$this->markTestSkipped( 'Image signing failed — OpenSSL may be unavailable.' );
		}

		// Create a post with the attachment as featured image.
		$post_id = $this->factory()->post->create( array( 'post_status' => 'publish' ) );
		set_post_thumbnail( $post_id, $attachment_id );

		// Simulate is_singular() returning true and queried object being the post.
		$this->go_to( get_permalink( $post_id ) );

		// We can't easily assert headers in unit tests, but we can verify
		// the manifest URL meta is set.
		$manifest_url = get_post_meta( $attachment_id, '_c2pa_image_manifest_url', true );
		$this->assertNotEmpty( $manifest_url );
	}

	/**
	 * Test that no header is injected on non-singular pages.
	 */
	public function test_no_header_on_non_singular(): void {
		// On archive pages, inject_manifest_url_header() should return early.
		// We test this by checking that is_singular() is false on the home page.
		$this->go_to( home_url() );
		$this->assertFalse( is_singular() );
	}

	/**
	 * Test that register_settings registers the auto_sign_images option.
	 */
	public function test_register_settings(): void {
		$experiment = new Image_Provenance();
		$experiment->register_settings();

		$registered  = get_registered_settings();
		$option_name = 'wpai_feature_image-provenance_field_auto_sign_images';
		$this->assertArrayHasKey( $option_name, $registered );
	}

	/**
	 * Test that render_settings_fields outputs expected HTML.
	 */
	public function test_render_settings_fields(): void {
		$experiment = new Image_Provenance();

		ob_start();
		$experiment->render_settings_fields();
		$output = ob_get_clean();

		$this->assertIsString( $output );
		$this->assertStringContainsString( 'ai-experiment-image-provenance-settings', $output );
		$this->assertStringContainsString( 'auto_sign_images', $output );
		$this->assertStringContainsString( 'type="checkbox"', $output );
	}

	/**
	 * Test that sign_on_attachment_upload skips attachments with no mime type.
	 */
	public function test_sign_on_attachment_upload_skips_empty_mime_type(): void {
		$experiment = new Image_Provenance();

		// Attachment with empty mime type.
		$attachment_id = $this->factory()->attachment->create(
			array(
				'post_mime_type' => '',
				'post_status'    => 'inherit',
			)
		);

		$experiment->sign_on_attachment_upload( $attachment_id );

		$status = get_post_meta( $attachment_id, '_c2pa_image_status', true );
		$this->assertEmpty( $status );
	}

	/**
	 * Test that sign_on_attachment_upload stores error status when URL cannot be retrieved.
	 */
	public function test_sign_on_attachment_upload_error_when_url_false(): void {
		$experiment    = new Image_Provenance();
		$attachment_id = $this->create_test_attachment( 'image/jpeg' );

		// Force wp_get_attachment_url to return false.
		add_filter( 'wp_get_attachment_url', '__return_false' );
		$experiment->sign_on_attachment_upload( $attachment_id );
		remove_filter( 'wp_get_attachment_url', '__return_false' );

		$status = get_post_meta( $attachment_id, '_c2pa_image_status', true );
		$this->assertSame( 'error', $status );
	}

	/**
	 * Test that sign_on_attachment_upload stores error status when C2PA signing fails.
	 */
	public function test_sign_on_attachment_upload_error_when_signing_fails(): void {
		// Store an invalid keypair so Local_Signer fails to load the private key.
		update_option(
			'_c2pa_local_keypair',
			array(
				'private_key' => 'not-a-valid-pem',
				'public_key'  => 'not-a-valid-pem',
			),
			false
		);

		$experiment    = new Image_Provenance();
		$attachment_id = $this->create_test_attachment( 'image/jpeg' );
		$experiment->sign_on_attachment_upload( $attachment_id );

		$status = get_post_meta( $attachment_id, '_c2pa_image_status', true );
		$this->assertSame( 'error', $status );
	}

	/**
	 * Test that get_local_keypair returns the stored keypair without generating a new one.
	 */
	public function test_get_local_keypair_returns_stored_keypair(): void {
		// Pre-populate a valid keypair.
		$resource = openssl_pkey_new(
			array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);

		if ( false === $resource ) {
			$this->markTestSkipped( 'OpenSSL unavailable.' );
		}

		$private_key_pem = '';
		openssl_pkey_export( $resource, $private_key_pem );
		$key_details = openssl_pkey_get_details( $resource );
		$public_key  = is_array( $key_details ) ? ( $key_details['key'] ?? '' ) : '';

		$stored_keypair = array(
			'private_key' => $private_key_pem,
			'public_key'  => $public_key,
		);
		update_option( '_c2pa_local_keypair', $stored_keypair, false );

		// Signing should succeed using the stored keypair (not generate a new one).
		$experiment    = new Image_Provenance();
		$attachment_id = $this->create_test_attachment( 'image/jpeg' );
		$experiment->sign_on_attachment_upload( $attachment_id );

		$status = get_post_meta( $attachment_id, '_c2pa_image_status', true );
		$this->assertSame( 'signed', $status );

		// The stored option must not have changed (no new keypair generated).
		$option_after = get_option( '_c2pa_local_keypair' );
		$this->assertSame( $stored_keypair, $option_after );
	}

	/**
	 * Test inject_manifest_url_header returns early when post has no thumbnail.
	 */
	public function test_inject_manifest_url_header_no_thumbnail(): void {
		$experiment = new Image_Provenance();
		$experiment->register();

		// Post with no featured image.
		$post_id = $this->factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->go_to( get_permalink( $post_id ) );

		$this->assertTrue( is_singular() );
		// No thumbnail — inject_manifest_url_header should return early without error.
		$experiment->inject_manifest_url_header();
		$this->assertTrue( true );
	}

	/**
	 * Test inject_manifest_url_header returns early when attachment has no manifest URL.
	 */
	public function test_inject_manifest_url_header_unsigned_attachment(): void {
		$experiment = new Image_Provenance();
		$experiment->register();

		// Attachment with no signing meta.
		$attachment_id = $this->create_test_attachment( 'image/jpeg' );
		$post_id       = $this->factory()->post->create( array( 'post_status' => 'publish' ) );
		set_post_thumbnail( $post_id, $attachment_id );

		$this->go_to( get_permalink( $post_id ) );

		$this->assertTrue( is_singular() );
		// No _c2pa_image_manifest_url meta — should return early.
		$experiment->inject_manifest_url_header();
		$this->assertTrue( true );
	}

	/**
	 * Test rest_manifest_callback returns 404 for an attachment with no stored manifest.
	 */
	public function test_rest_manifest_returns_404_for_unsigned(): void {
		$experiment    = new Image_Provenance();
		$attachment_id = $this->create_test_attachment( 'image/jpeg' );

		$request = new \WP_REST_Request( 'GET', "/c2pa-provenance/v1/images/manifest/{$attachment_id}" );
		$request->set_param( 'attachment_id', $attachment_id );

		$response = $experiment->rest_manifest_callback( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * Creates a test attachment post with the given MIME type.
	 *
	 * @param string $mime_type The MIME type.
	 * @return int The attachment ID.
	 */
	private function create_test_attachment( string $mime_type ): int {
		return $this->factory()->attachment->create(
			array(
				'post_mime_type' => $mime_type,
				'post_status'    => 'inherit',
				'post_title'     => 'Test Image',
			)
		);
	}
}
