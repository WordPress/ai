<?php
/**
 * Integration tests for the Well_Known_Handler class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Content_Provenance
 */

declare( strict_types=1 );

namespace WordPress\AI\Tests\Integration\Experiments\Content_Provenance;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Content_Provenance\Well_Known_Handler;

/**
 * Well_Known_Handler integration test case.
 *
 * @since 0.5.0
 */
class Well_Known_HandlerTest extends WP_UnitTestCase {

	/**
	 * Test that the QUERY_VAR constant is set.
	 *
	 * @since 0.5.0
	 */
	public function test_query_var_constant(): void {
		$this->assertSame( 'c2pa_well_known', Well_Known_Handler::QUERY_VAR );
	}

	/**
	 * Test that add_rewrite_rule registers the c2pa_well_known query var.
	 *
	 * @since 0.5.0
	 */
	public function test_add_rewrite_rule_registers_query_var(): void {
		Well_Known_Handler::add_rewrite_rule();

		$vars = apply_filters( 'query_vars', array() );

		$this->assertContains( 'c2pa_well_known', $vars );
	}

	/**
	 * Test that maybe_handle returns early (no output) when the query var is not set.
	 *
	 * @since 0.5.0
	 */
	public function test_maybe_handle_returns_early_without_query_var(): void {
		ob_start();
		Well_Known_Handler::maybe_handle();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * Test that build_document returns a valid discovery document structure.
	 *
	 * @since 0.5.0
	 * @since 0.7.0 Updated for C2PA spec-compliant field names.
	 */
	public function test_build_document_returns_valid_structure(): void {
		$document = Well_Known_Handler::build_document();

		$this->assertIsArray( $document );
		$this->assertArrayHasKey( 'c2pa_version', $document );
		$this->assertArrayHasKey( 'publisher', $document );
		$this->assertArrayHasKey( 'url', $document );
		$this->assertArrayHasKey( 'signing', $document );
		$this->assertArrayHasKey( 'verify', $document );
		$this->assertArrayHasKey( 'trust_anchors', $document );
	}

	/**
	 * Test that build_document includes the C2PA version.
	 *
	 * @since 0.7.0
	 */
	public function test_build_document_c2pa_version(): void {
		$document = Well_Known_Handler::build_document();

		$this->assertSame( '2.3', $document['c2pa_version'] );
	}

	/**
	 * Test that build_document includes signing metadata with ES256 algorithm.
	 *
	 * @since 0.5.0
	 * @since 0.7.0 Updated for ES256 algorithm field.
	 */
	public function test_build_document_signing_metadata(): void {
		$document = Well_Known_Handler::build_document();

		$this->assertIsArray( $document['signing'] );
		$this->assertTrue( $document['signing']['active'] );
		$this->assertSame( 'ES256', $document['signing']['algorithm'] );
	}

	/**
	 * Test that build_document includes the verification endpoint.
	 *
	 * @since 0.5.0
	 */
	public function test_build_document_verify_endpoint(): void {
		$document = Well_Known_Handler::build_document();

		$this->assertIsArray( $document['verify'] );
		$this->assertStringContainsString( 'c2pa-provenance/v1/verify', $document['verify']['endpoint'] );
	}

	/**
	 * Test that build_document includes the site URL as url field.
	 *
	 * @since 0.5.0
	 */
	public function test_build_document_url_matches_home(): void {
		$document = Well_Known_Handler::build_document();

		$this->assertSame( home_url(), $document['url'] );
	}

	/**
	 * Test that build_document includes the site name as publisher.
	 *
	 * @since 0.5.0
	 */
	public function test_build_document_publisher_matches_site_name(): void {
		$document = Well_Known_Handler::build_document();

		$this->assertSame( get_bloginfo( 'name' ), $document['publisher'] );
	}

	/**
	 * Test that build_document includes trust_anchors as an array.
	 *
	 * @since 0.7.0
	 */
	public function test_build_document_trust_anchors(): void {
		$document = Well_Known_Handler::build_document();

		$this->assertIsArray( $document['trust_anchors'] );
	}
}
