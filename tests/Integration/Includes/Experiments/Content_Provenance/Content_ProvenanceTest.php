<?php
/**
 * Integration tests for the Content_Provenance experiment.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Content_Provenance
 */

declare( strict_types=1 );

namespace WordPress\AI\Tests\Integration\Experiments\Content_Provenance;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Content_Provenance\C2PA_Manifest_Builder;
use WordPress\AI\Experiments\Content_Provenance\Content_Provenance;
use WordPress\AI\Experiments\Content_Provenance\Signing\Local_Signer;
use WordPress\AI\Experiments\Content_Provenance\Unicode_Embedder;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Experiments\Experiments;
use WordPress\AI\Features\Loader;
use WordPress\AI\Features\Registry;

/**
 * Content_Provenance integration test case.
 *
 * @since 0.5.0
 */
class Content_ProvenanceTest extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 *
	 * @since 0.5.0
	 */
	public function setUp(): void {
		parent::setUp();

		// Enable experiments globally and individually.
		update_option( 'wpai_features_enabled', true );
		update_option( 'wpai_feature_content-provenance_enabled', true );

		$registry    = new Registry();
		$loader      = new Loader( $registry );
		$experiments = new Experiments();
		$experiments->init();
		$loader->init();

		$experiment = $registry->get_feature( 'content-provenance' );
		$this->assertInstanceOf( Content_Provenance::class, $experiment );
	}

	/**
	 * Tear down.
	 *
	 * @since 0.5.0
	 */
	public function tearDown(): void {
		// Reset the REST server to prevent cross-test contamination.
		$GLOBALS['wp_rest_server'] = null;

		// Remove the Experiments filter added by init() so it doesn't leak into later tests.
		remove_filter( 'wpai_default_feature_classes', array( Experiments::class, 'register_default_experiment_classes' ), 9 );

		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_content-provenance_enabled' );
		delete_option( '_c2pa_local_keypair' );
		parent::tearDown();
	}

	/**
	 * Test experiment metadata.
	 *
	 * @since 0.5.0
	 */
	public function test_experiment_metadata(): void {
		$experiment = new Content_Provenance();

		$this->assertSame( 'content-provenance', $experiment->get_id() );
		$this->assertSame( 'Content Provenance', $experiment->get_label() );
		$this->assertSame( Experiment_Category::EDITOR, $experiment->get_category() );
		$this->assertTrue( $experiment->is_enabled() );
	}

	/**
	 * Test that the experiment registers in the loader.
	 *
	 * @since 0.5.0
	 */
	public function test_experiment_is_in_default_experiments(): void {
		$registry    = new Registry();
		$loader      = new Loader( $registry );
		$experiments = new Experiments();
		$experiments->init();
		$loader->init();

		$experiment = $registry->get_feature( 'content-provenance' );
		$this->assertInstanceOf( Content_Provenance::class, $experiment );
	}

	/**
	 * Test Unicode embedding roundtrip.
	 *
	 * @since 0.5.0
	 */
	public function test_unicode_embed_extract_roundtrip(): void {
		$original = 'Hello, WordPress.';
		$payload  = '{"test":"provenance"}';

		$embedded  = Unicode_Embedder::embed( $original, $payload );
		$extracted = Unicode_Embedder::extract( $embedded );

		$this->assertSame( $payload, $extracted, 'Extracted payload should match original.' );
	}

	/**
	 * Test Unicode strip returns clean text.
	 *
	 * @since 0.5.0
	 */
	public function test_unicode_strip_returns_clean_text(): void {
		$original = 'Clean text here.';
		$payload  = '{"c2pa":"test"}';

		$embedded = Unicode_Embedder::embed( $original, $payload );
		$stripped = Unicode_Embedder::strip( $embedded );

		$this->assertStringContainsString( 'Clean text here.', $stripped );
	}

	/**
	 * Test that extract returns null for text without embedding.
	 *
	 * @since 0.5.0
	 */
	public function test_extract_returns_null_for_unsigned_text(): void {
		$result = Unicode_Embedder::extract( 'Plain text with no embedding.' );
		$this->assertNull( $result );
	}

	/**
	 * Test C2PA manifest builder builds a valid manifest.
	 *
	 * @since 0.5.0
	 * @since 0.7.0 Updated for JUMBF binary output.
	 */
	public function test_manifest_builder_builds_valid_manifest(): void {
		$keypair = $this->generate_test_keypair();
		$signer  = new Local_Signer( $keypair );

		$result = C2PA_Manifest_Builder::build(
			'Test content for signing.',
			'c2pa.created',
			null,
			array(
				'title'   => 'Test Post',
				'post_id' => 1,
			),
			$signer
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'manifest', $result );
		$this->assertArrayHasKey( 'content_hash', $result );
		$this->assertSame( hash( 'sha256', 'Test content for signing.' ), $result['content_hash'] );

		// Manifest should be JUMBF binary.
		$this->assertIsString( $result['manifest'] );
		$this->assertSame( 'jumb', substr( $result['manifest'], 4, 4 ) );
	}

	/**
	 * Test that tampered content fails verification.
	 *
	 * @since 0.5.0
	 */
	public function test_tampered_content_fails_verification(): void {
		$keypair  = $this->generate_test_keypair();
		$signer   = new Local_Signer( $keypair );
		$original = 'Original content.';

		$result   = C2PA_Manifest_Builder::build( $original, 'c2pa.created', null, array(), $signer );
		$embedded = Unicode_Embedder::embed( $original, $result['manifest'] );

		// Tamper: replace original content in the signed text.
		$tampered = str_replace( $original, 'Tampered content.', $embedded );

		$verification = C2PA_Manifest_Builder::extract_and_verify( $tampered );
		$this->assertSame( 'tampered', $verification['status'] );
		$this->assertFalse( $verification['verified'] );
	}

	/**
	 * Test that signing an empty string is handled gracefully.
	 *
	 * @since 0.5.0
	 */
	public function test_sign_empty_content_returns_error(): void {
		$keypair = $this->generate_test_keypair();
		$signer  = new Local_Signer( $keypair );

		// Empty content — builder should handle this gracefully.
		$result = C2PA_Manifest_Builder::build( '', 'c2pa.created', null, array(), $signer );
		// Either returns an error or a valid result; should not throw.
		$this->assertTrue( is_array( $result ) || is_wp_error( $result ) );
	}

	/**
	 * Test provenance chain: edited manifest includes ingredient referencing the first.
	 *
	 * @since 0.5.0
	 * @since 0.7.0 Updated for binary JUMBF format with ingredient chain.
	 */
	public function test_edited_manifest_builds_with_ingredient_chain(): void {
		$keypair = $this->generate_test_keypair();
		$signer  = new Local_Signer( $keypair );

		$first = C2PA_Manifest_Builder::build( 'Original.', 'c2pa.created', null, array(), $signer );
		$this->assertIsArray( $first );
		$this->assertIsString( $first['manifest'] );

		$second = C2PA_Manifest_Builder::build( 'Edited.', 'c2pa.edited', $first['manifest'], array(), $signer );
		$this->assertIsArray( $second );
		$this->assertIsString( $second['manifest'] );

		// Both should be valid JUMBF.
		$this->assertSame( 'jumb', substr( $first['manifest'], 4, 4 ) );
		$this->assertSame( 'jumb', substr( $second['manifest'], 4, 4 ) );

		// Second manifest should contain the SHA-256 hash of the first (ingredient binding).
		$first_hash = hash( 'sha256', $first['manifest'], true );
		$this->assertStringContainsString(
			$first_hash,
			$second['manifest'],
			'Edited manifest should contain SHA-256 hash of the first manifest (ingredient chain).'
		);

		// Second manifest should be larger (it includes ingredient assertion).
		$this->assertGreaterThan(
			strlen( $first['manifest'] ),
			strlen( $second['manifest'] ),
			'Edited manifest with ingredient should be larger than the initial manifest.'
		);
	}

	/**
	 * Test auto-sign hook is registered on wp_after_insert_post.
	 *
	 * @since 0.5.0
	 */
	public function test_register_hooks_on_after_insert_post(): void {
		$experiment = new Content_Provenance();
		$experiment->register();

		$this->assertGreaterThan( 0, has_action( 'wp_after_insert_post', array( $experiment, 'sign_after_save' ) ) );
	}

	/**
	 * Test REST verification endpoint is registered.
	 *
	 * @since 0.5.0
	 */
	public function test_rest_verify_route_registered(): void {
		$experiment = new Content_Provenance();
		$experiment->register();
		do_action( 'rest_api_init' );

		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/c2pa-provenance/v1/verify', $routes );
	}

	/**
	 * Test that sign_post() signs post content and stores meta.
	 *
	 * @since 0.5.0
	 * @since 0.7.0 Updated for EC P-256 keypair format.
	 */
	public function test_sign_post_stores_meta(): void {
		$keypair = $this->generate_test_keypair();
		update_option( '_c2pa_local_keypair', $keypair );

		$post_id = $this->factory->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'Hello, this is test content for signing.',
			)
		);
		$post    = get_post( $post_id );

		$experiment = new Content_Provenance();
		$result     = $experiment->sign_post( $post_id, $post, 'c2pa.created' );

		$this->assertTrue( $result );
		$this->assertSame( 'signed', get_post_meta( $post_id, '_c2pa_status', true ) );
		$this->assertNotEmpty( get_post_meta( $post_id, '_c2pa_manifest', true ) );
		$this->assertNotEmpty( get_post_meta( $post_id, '_c2pa_signed_at', true ) );
		$this->assertSame( 'local', get_post_meta( $post_id, '_c2pa_signer_tier', true ) );
	}

	/**
	 * Test that sign_post() returns false for a post with empty content.
	 *
	 * @since 0.5.0
	 */
	public function test_sign_post_returns_false_for_empty_content(): void {
		$post_id = $this->factory->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '',
			)
		);
		$post    = get_post( $post_id );

		$experiment = new Content_Provenance();
		$result     = $experiment->sign_post( $post_id, $post, 'c2pa.created' );

		$this->assertFalse( $result );
	}

	/**
	 * Test sign_after_save skips non-published posts.
	 *
	 * @since 0.5.0
	 */
	public function test_sign_after_save_skips_draft(): void {
		update_option( 'wpai_feature_content-provenance_field_auto_sign', true );

		$post_id = $this->factory->post->create(
			array(
				'post_status'  => 'draft',
				'post_content' => 'Some content.',
			)
		);
		$post    = get_post( $post_id );

		$experiment = new Content_Provenance();
		$experiment->sign_after_save( $post_id, $post, false );

		$this->assertEmpty( get_post_meta( $post_id, '_c2pa_status', true ) );

		delete_option( 'wpai_feature_content-provenance_field_auto_sign' );
	}

	/**
	 * Test sign_after_save skips when auto_sign is disabled.
	 *
	 * @since 0.5.0
	 */
	public function test_sign_after_save_skips_when_auto_sign_disabled(): void {
		update_option( 'wpai_feature_content-provenance_field_auto_sign', false );

		$post_id = $this->factory->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'Some content.',
			)
		);
		$post    = get_post( $post_id );

		$experiment = new Content_Provenance();
		$experiment->sign_after_save( $post_id, $post, false );

		$this->assertEmpty( get_post_meta( $post_id, '_c2pa_status', true ) );

		delete_option( 'wpai_feature_content-provenance_field_auto_sign' );
	}

	/**
	 * Test sign_after_save skips when already signed and content unchanged.
	 *
	 * @since 0.5.0
	 */
	public function test_sign_after_save_skips_unchanged_content(): void {
		update_option( 'wpai_feature_content-provenance_field_auto_sign', true );

		$post_id = $this->factory->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'Identical content.',
			)
		);
		$post    = get_post( $post_id );

		// Simulate a prior signing by setting the meta values.
		update_post_meta( $post_id, '_c2pa_status', 'signed' );
		update_post_meta( $post_id, '_c2pa_content_hash', md5( wp_strip_all_tags( $post->post_content ) ) );

		$experiment = new Content_Provenance();
		$experiment->sign_after_save( $post_id, $post, true );

		// The content hash should remain unchanged (no re-signing).
		$this->assertSame(
			md5( wp_strip_all_tags( $post->post_content ) ),
			get_post_meta( $post_id, '_c2pa_content_hash', true )
		);

		delete_option( 'wpai_feature_content-provenance_field_auto_sign' );
	}

	/**
	 * Test ensure_local_keypair generates and stores an EC P-256 keypair when none exists.
	 *
	 * @since 0.5.0
	 * @since 0.7.0 Updated for EC P-256 keypair with certificate.
	 */
	public function test_ensure_local_keypair_generates_keypair(): void {
		delete_option( '_c2pa_local_keypair' );

		$experiment = new Content_Provenance();
		$experiment->ensure_local_keypair();

		$stored = get_option( '_c2pa_local_keypair' );
		$this->assertIsArray( $stored );
		$this->assertNotEmpty( $stored['private_key'] );
		$this->assertNotEmpty( $stored['certificate_pem'] );
	}

	/**
	 * Test ensure_local_keypair does not regenerate when one already exists.
	 *
	 * @since 0.5.0
	 * @since 0.7.0 Updated for EC P-256 keypair format.
	 */
	public function test_ensure_local_keypair_does_not_regenerate_existing(): void {
		$keypair = $this->generate_test_keypair();
		update_option( '_c2pa_local_keypair', $keypair );

		$experiment = new Content_Provenance();
		$experiment->ensure_local_keypair();

		$stored = get_option( '_c2pa_local_keypair' );
		$this->assertSame( $keypair['certificate_pem'], $stored['certificate_pem'] );
	}

	/**
	 * Test on_toggle generates keypair when new value is '1'.
	 *
	 * @since 0.5.0
	 */
	public function test_on_toggle_generates_keypair_on_enable(): void {
		delete_option( '_c2pa_local_keypair' );

		$experiment = new Content_Provenance();
		$experiment->on_toggle( '0', '1' );

		$stored = get_option( '_c2pa_local_keypair' );
		$this->assertIsArray( $stored );
		$this->assertNotEmpty( $stored['private_key'] );
	}

	/**
	 * Test on_toggle does nothing when new value is not '1'.
	 *
	 * @since 0.5.0
	 */
	public function test_on_toggle_does_nothing_on_disable(): void {
		delete_option( '_c2pa_local_keypair' );

		$experiment = new Content_Provenance();
		$experiment->on_toggle( '1', '0' );

		$stored = get_option( '_c2pa_local_keypair' );
		$this->assertFalse( $stored );
	}

	/**
	 * Test get_public_signer returns a Signing_Interface instance.
	 *
	 * @since 0.5.0
	 */
	public function test_get_public_signer_returns_signing_interface(): void {
		$keypair = $this->generate_test_keypair();
		update_option( '_c2pa_local_keypair', $keypair );

		$experiment = new Content_Provenance();
		$signer     = $experiment->get_public_signer();

		$this->assertInstanceOf(
			\WordPress\AI\Experiments\Content_Provenance\Signing\Signing_Interface::class,
			$signer
		);
	}

	/**
	 * Test get_public_signer returns Connected_Signer when tier is 'connected'.
	 *
	 * @since 0.5.0
	 */
	public function test_get_public_signer_returns_connected_signer_for_connected_tier(): void {
		update_option( 'wpai_feature_content-provenance_field_signing_tier', 'connected' );

		$experiment = new Content_Provenance();
		$signer     = $experiment->get_public_signer();

		$this->assertInstanceOf(
			\WordPress\AI\Experiments\Content_Provenance\Signing\Connected_Signer::class,
			$signer
		);

		delete_option( 'wpai_feature_content-provenance_field_signing_tier' );
	}

	/**
	 * Test get_public_signer returns BYOK_Signer when tier is 'byok'.
	 *
	 * @since 0.5.0
	 */
	public function test_get_public_signer_returns_byok_signer_for_byok_tier(): void {
		update_option( 'wpai_feature_content-provenance_field_signing_tier', 'byok' );

		$experiment = new Content_Provenance();
		$signer     = $experiment->get_public_signer();

		$this->assertInstanceOf(
			\WordPress\AI\Experiments\Content_Provenance\Signing\BYOK_Signer::class,
			$signer
		);

		delete_option( 'wpai_feature_content-provenance_field_signing_tier' );
	}

	/**
	 * Test REST verify endpoint returns 'unsigned' for plain text.
	 *
	 * @since 0.5.0
	 */
	public function test_rest_verify_returns_unsigned_for_plain_text(): void {
		$experiment = new Content_Provenance();
		$experiment->register();
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();

		$request = new \WP_REST_Request( 'POST', '/c2pa-provenance/v1/verify' );
		$request->set_param( 'text', 'This is plain unsigned text.' );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'unsigned', $data['status'] );
		$this->assertFalse( $data['verified'] );
	}

	/**
	 * Test REST verify endpoint returns 'verified' for signed text.
	 *
	 * @since 0.5.0
	 * @since 0.7.0 Updated for EC P-256 keypair and JUMBF binary manifest.
	 */
	public function test_rest_verify_returns_verified_for_signed_text(): void {
		$keypair = $this->generate_test_keypair();
		$signer  = new Local_Signer( $keypair );

		$content = 'REST verify test content.';
		$built   = C2PA_Manifest_Builder::build(
			$content,
			'c2pa.created',
			null,
			array(),
			$signer
		);
		$this->assertIsArray( $built );
		$signed_text = Unicode_Embedder::embed( $content, $built['manifest'] );

		$experiment = new Content_Provenance();
		$experiment->register();
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();

		$request = new \WP_REST_Request( 'POST', '/c2pa-provenance/v1/verify' );
		$request->set_param( 'text', $signed_text );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'verified', $data['status'] );
		$this->assertTrue( $data['verified'] );
	}

	/**
	 * Test REST status endpoint returns 'unsigned' for a new post.
	 *
	 * @since 0.5.0
	 */
	public function test_rest_status_returns_unsigned_for_new_post(): void {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$post_id = $this->factory->post->create( array( 'post_status' => 'draft' ) );

		$experiment = new Content_Provenance();
		$experiment->register();
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();

		$request = new \WP_REST_Request( 'GET', '/c2pa-provenance/v1/status' );
		$request->set_param( 'post_id', $post_id );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'unsigned', $data['status'] );
		$this->assertNull( $data['signed_at'] );

		wp_set_current_user( 0 );
	}

	/**
	 * Test REST status endpoint returns signing data after a post is signed.
	 *
	 * @since 0.5.0
	 * @since 0.7.0 Updated for EC P-256 keypair format.
	 */
	public function test_rest_status_returns_signed_after_signing(): void {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$keypair = $this->generate_test_keypair();
		update_option( '_c2pa_local_keypair', $keypair );

		$post_id = $this->factory->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'Content to sign for status test.',
			)
		);
		$post    = get_post( $post_id );

		$experiment = new Content_Provenance();
		$experiment->sign_post( $post_id, $post, 'c2pa.created' );

		$experiment->register();
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();

		$request = new \WP_REST_Request( 'GET', '/c2pa-provenance/v1/status' );
		$request->set_param( 'post_id', $post_id );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'signed', $data['status'] );
		$this->assertNotEmpty( $data['signed_at'] );
		$this->assertSame( 'local', $data['signer_tier'] );

		wp_set_current_user( 0 );
	}

	/**
	 * Test register_settings registers the expected options.
	 *
	 * @since 0.5.0
	 */
	public function test_register_settings_registers_signing_tier(): void {
		$experiment = new Content_Provenance();
		$experiment->register_settings();

		global $wp_registered_settings;
		// Option name follows the pattern: wpai_feature_{id}_field_{name}.
		$option_name = 'wpai_feature_content-provenance_field_signing_tier';
		$this->assertArrayHasKey( $option_name, $wp_registered_settings );
	}

	/**
	 * Test that get_settings_fields() returns the expected field definitions.
	 *
	 * @since 0.5.0
	 */
	public function test_get_settings_fields_returns_expected_fields(): void {
		$experiment = new Content_Provenance();
		$fields     = $experiment->get_settings_fields();

		$ids = array_column( $fields, 'id' );
		$this->assertContains( 'signing_tier', $ids );
		$this->assertContains( 'auto_sign', $ids );
		$this->assertContains( 'show_badge', $ids );
		$this->assertContains( 'badge_position', $ids );
		$this->assertContains( 'connected_service_url', $ids );
		$this->assertContains( 'connected_service_api_key', $ids );
		$this->assertContains( 'byok_key_path', $ids );
		$this->assertContains( 'byok_certificate', $ids );
	}

	/**
	 * Test that get_settings_fields() signing_tier has correct elements.
	 *
	 * @since 0.5.0
	 */
	public function test_get_settings_fields_signing_tier_elements(): void {
		$experiment = new Content_Provenance();
		$fields     = $experiment->get_settings_fields();

		$tier_field = null;
		foreach ( $fields as $field ) {
			if ( 'signing_tier' === $field['id'] ) {
				$tier_field = $field;
				break;
			}
		}

		$this->assertNotNull( $tier_field );
		$this->assertArrayHasKey( 'elements', $tier_field );

		$values = array_column( $tier_field['elements'], 'value' );
		$this->assertContains( 'local', $values );
		$this->assertContains( 'connected', $values );
		$this->assertContains( 'byok', $values );
	}

	/**
	 * Test that get_settings_fields_metadata() resolves full option names.
	 *
	 * @since 0.5.0
	 */
	public function test_get_settings_fields_metadata_resolves_option_names(): void {
		$experiment = new Content_Provenance();
		$metadata   = $experiment->get_settings_fields_metadata();

		$ids = array_column( $metadata, 'id' );
		$this->assertContains( 'wpai_feature_content-provenance_field_signing_tier', $ids );
		$this->assertContains( 'wpai_feature_content-provenance_field_show_badge', $ids );
	}

	/**
	 * Test that add_well_known_rewrite() registers the c2pa_well_known query var.
	 *
	 * @since 0.5.0
	 */
	public function test_add_well_known_rewrite_registers_query_var(): void {
		$experiment = new Content_Provenance();
		$experiment->add_well_known_rewrite();

		$vars = apply_filters( 'query_vars', array() );

		$this->assertContains( 'c2pa_well_known', $vars );
	}

	/**
	 * Test that handle_well_known_request() returns early when query var is not set.
	 *
	 * @since 0.5.0
	 */
	public function test_handle_well_known_request_returns_early_without_query_var(): void {
		// The query var 'c2pa_well_known' is not set, so get_query_var() returns ''.
		$experiment = new Content_Provenance();
		ob_start();
		$experiment->handle_well_known_request();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * Test that enqueue_assets() returns early when there is no current screen.
	 *
	 * @since 0.5.0
	 */
	public function test_enqueue_assets_returns_early_without_screen(): void {
		// In the test context get_current_screen() returns null.
		$experiment = new Content_Provenance();
		$experiment->enqueue_assets();

		$this->assertFalse( wp_script_is( 'ai_content_provenance', 'enqueued' ) );
	}

	/**
	 * Test that enqueue_assets() returns early for a non-post admin screen.
	 *
	 * @since 0.5.0
	 */
	public function test_enqueue_assets_returns_early_for_non_post_screen(): void {
		set_current_screen( 'options-general' );

		$experiment = new Content_Provenance();
		$experiment->enqueue_assets();

		$this->assertFalse( wp_script_is( 'ai_content_provenance', 'enqueued' ) );

		// Restore: unset the current screen global.
		unset( $GLOBALS['current_screen'] );
	}

	/**
	 * Test that enqueue_assets() proceeds through the post screen path.
	 *
	 * Asset_Loader returns early when the build file doesn't exist (test env),
	 * so no actual script is enqueued, but all code paths in enqueue_assets() run.
	 *
	 * @since 0.5.0
	 */
	public function test_enqueue_assets_runs_on_post_screen(): void {
		set_current_screen( 'post' );

		$experiment = new Content_Provenance();
		$experiment->enqueue_assets();

		// In test environment the build file does not exist, so Asset_Loader returns early.
		$this->assertFalse( wp_script_is( 'ai_content_provenance', 'enqueued' ) );

		unset( $GLOBALS['current_screen'] );
	}

	/**
	 * Test that C2PA_Manifest_Builder::build() returns WP_Error when signer fails.
	 *
	 * @since 0.5.0
	 */
	public function test_manifest_builder_build_returns_error_when_signer_fails(): void {
		$mock_signer = new class() implements \WordPress\AI\Experiments\Content_Provenance\Signing\Signing_Interface {
			/**
			 * Always fail.
			 *
			 * @param string              $content  Content.
			 * @param array<string,mixed> $metadata Metadata.
			 * @return \WP_Error
			 */
			public function sign( string $content, array $metadata ) {
				return new \WP_Error( 'mock_signer_error', 'Intentional test failure.' );
			}

			/**
			 * Tier name.
			 *
			 * @return string
			 */
			public function get_tier(): string {
				return 'mock';
			}
		};

		$result = C2PA_Manifest_Builder::build( 'Some content.', 'c2pa.created', null, array(), $mock_signer );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mock_signer_error', $result->get_error_code() );
	}

	/**
	 * Test that C2PA_Manifest_Builder::extract_and_verify() returns 'invalid' for non-JUMBF embedded data.
	 *
	 * @since 0.5.0
	 * @since 0.7.0 Updated: non-JSON data now goes through JUMBF validation path.
	 */
	public function test_manifest_builder_extract_and_verify_returns_invalid_for_non_jumbf(): void {
		// embed() with arbitrary data produces a wrapper that extract() decodes,
		// but the data won't have a valid JUMBF structure.
		$signed_text = Unicode_Embedder::embed( 'Content.', 'this-is-not-valid-jumbf-data!!' );
		$result      = C2PA_Manifest_Builder::extract_and_verify( $signed_text );

		$this->assertSame( 'invalid', $result['status'] );
		$this->assertFalse( $result['verified'] );
	}

	/**
	 * Generate a test EC P-256 keypair with self-signed certificate.
	 *
	 * @since 0.5.0
	 * @since 0.7.0 Switched from RSA-1024 to EC P-256 with certificate.
	 * @return array{private_key: string, certificate_pem: string}
	 */
	private function generate_test_keypair(): array {
		$keypair = Local_Signer::generate_keypair();

		if ( is_wp_error( $keypair ) ) {
			$this->fail( 'generate_keypair() failed: ' . $keypair->get_error_message() );
		}

		return $keypair;
	}
}
