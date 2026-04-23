<?php
/**
 * C2PA conformance tests.
 *
 * Validates that the PHP C2PA protocol layer produces manifests that pass
 * structural validation against the C2PA v2.4 specification. Uses the
 * c2pa-conformance-suite to verify JUMBF structure, COSE signatures, and
 * assertion correctness.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Content_Provenance\C2PA
 */

declare( strict_types=1 );

namespace WordPress\AI\Tests\Integration\Experiments\Content_Provenance\C2PA;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Content_Provenance\Signing\Local_Signer;
use WordPress\AI\Experiments\Content_Provenance\Unicode_Embedder;
use WordPress\AI\Experiments\Content_Provenance\C2PA\Claim_Builder;
use WordPress\AI\Experiments\Content_Provenance\C2PA\CBOR_Encoder;

/**
 * C2PA conformance test case.
 *
 * Requires the c2pa-conformance-suite to be installed at
 * /home/developer/code/c2pa-conformance-suite with a working uv environment.
 *
 * @since x.x.x
 */
class C2PA_ConformanceTest extends WP_UnitTestCase {

	/**
	 * Path to the conformance suite CLI.
	 *
	 * @var string
	 */
	private const CONFORM_CMD = '/home/developer/.local/bin/uv run --project /home/developer/code/c2pa-conformance-suite c2pa-conform';

	/**
	 * Test that a locally signed text manifest passes c2pa-conform structural validation.
	 */
	public function test_local_signed_manifest_passes_conformance_validation(): void {
		if ( ! $this->conformance_suite_available() ) {
			$this->markTestSkipped( 'c2pa-conformance-suite not available.' );
		}

		$keypair = Local_Signer::generate_keypair( 'Conformance Test' );
		if ( is_wp_error( $keypair ) ) {
			$this->fail( 'generate_keypair() failed: ' . $keypair->get_error_message() );
		}

		$content = 'This is a test document for C2PA v2.4 conformance validation.';
		$signer  = new Local_Signer( $keypair );
		$jumbf   = $signer->sign( $content, array( 'title' => 'Conformance Test Post' ) );

		$this->assertIsString( $jumbf, 'Local_Signer::sign() must return JUMBF bytes.' );
		$this->assertSame( 'jumb', substr( $jumbf, 4, 4 ), 'Output must be a JUMBF superbox.' );

		$signed_text = Unicode_Embedder::embed( $content, $jumbf );

		$tmp_file = tempnam( sys_get_temp_dir(), 'c2pa_' ) . '.txt';
		file_put_contents( $tmp_file, $signed_text );

		try {
			$output     = array();
			$return_var = 0;
			exec( self::CONFORM_CMD . ' validate ' . escapeshellarg( $tmp_file ) . ' --output-format json 2>&1', $output, $return_var );

			$json_output = implode( "\n", $output );

			$this->assertSame(
				0,
				$return_var,
				'c2pa-conform validate must exit 0. Output: ' . $json_output
			);
		} finally {
			unlink( $tmp_file );
		}
	}

	/**
	 * Test that the claim structure matches C2PA v2.4 ClaimMapV2 CDDL.
	 *
	 * Verifies required fields: instanceID, claim_generator_info, signature,
	 * created_assertions, alg. Verifies deprecated specVersion is absent.
	 */
	public function test_claim_structure_matches_v2_cddl(): void {
		$builder = new Claim_Builder(
			'Test content for claim validation.',
			'c2pa.created',
			array( 'title' => 'CDDL Test' ),
			'urn:uuid:cddl-test'
		);

		$result = $builder->build();
		$cbor   = $result['claim_cbor'];

		// Required ClaimMapV2 fields.
		$this->assertStringContainsString( 'instanceID', $cbor );
		$this->assertStringContainsString( 'claim_generator_info', $cbor );
		$this->assertStringContainsString( 'signature', $cbor );
		$this->assertStringContainsString( 'created_assertions', $cbor );
		$this->assertStringContainsString( 'sha256', $cbor );

		// specVersion is deprecated in v2.4 — must not be emitted.
		$this->assertStringNotContainsString( 'specVersion', $cbor );
	}

	/**
	 * Test that soft binding assertion has required blocks field per CDDL.
	 */
	public function test_soft_binding_has_required_blocks_field(): void {
		$builder = new Claim_Builder( 'Content.', 'c2pa.created', array(), 'urn:uuid:sb-test' );
		$result  = $builder->build();

		$sb_cbor = $result['assertion_map'][ Claim_Builder::ASSERTION_SOFT_BINDING ];

		// Required soft-binding-map fields per CDDL.
		$this->assertStringContainsString( 'alg', $sb_cbor );
		$this->assertStringContainsString( 'blocks', $sb_cbor );

		// Block must contain scope and value.
		$this->assertStringContainsString( 'scope', $sb_cbor );
		$this->assertStringContainsString( 'value', $sb_cbor );

		// Non-spec field must be absent.
		$this->assertStringNotContainsString( 'document_length', $sb_cbor );
	}

	/**
	 * Test that soft binding scope encodes as a CBOR map (0xa0), not an array.
	 */
	public function test_soft_binding_scope_is_cbor_map(): void {
		$builder = new Claim_Builder( 'Content.', 'c2pa.created', array(), 'urn:uuid:scope-test' );
		$result  = $builder->build();

		$sb_cbor = $result['assertion_map'][ Claim_Builder::ASSERTION_SOFT_BINDING ];

		// The scope should be an empty CBOR map (0xa0), not an empty array (0x80).
		$this->assertStringContainsString( "\xa0", $sb_cbor, 'Scope must encode as empty CBOR map (0xa0).' );
	}

	/**
	 * Test that ingredient v3 assertion has correct CDDL field names.
	 */
	public function test_ingredient_v3_field_names_match_cddl(): void {
		$previous = 'previous-manifest-bytes';
		$builder  = new Claim_Builder( 'Content.', 'c2pa.edited', array(), 'urn:uuid:ingr-test', $previous );
		$result   = $builder->build();

		$ingredient = $result['assertion_map'][ Claim_Builder::ASSERTION_INGREDIENT ];

		// Required field.
		$this->assertStringContainsString( 'relationship', $ingredient );

		// Dublin Core prefixed fields (optional in v3, included for interop).
		$this->assertStringContainsString( 'dc:title', $ingredient );
		$this->assertStringContainsString( 'dc:format', $ingredient );

		// v3 uses activeManifest (hashedUriMap), not bare hash.
		$this->assertStringContainsString( 'activeManifest', $ingredient );

		// Bare field names from the old buggy implementation must be absent.
		// Use a CBOR text string header + "title" to distinguish from "dc:title".
		$bare_title = CBOR_Encoder::encode( 'title' );
		$this->assertStringNotContainsString(
			$bare_title,
			$ingredient,
			'Ingredient must not contain bare "title" key (should be dc:title).'
		);
	}

	/**
	 * Test that the CBOR encoder correctly passes pre-encoded maps through encode_map.
	 *
	 * Validates the is_preencoded_cbor fix: empty CBOR maps (0xa0) must not be
	 * re-encoded as text strings when used as values in encode_map.
	 */
	public function test_cbor_encoder_passes_preencoded_maps(): void {
		$empty_map = CBOR_Encoder::encode_map( array() );
		$this->assertSame( "\xa0", $empty_map, 'Empty map must encode as 0xa0.' );

		// When used as a value in another map, it should be passed through, not re-encoded.
		$outer = CBOR_Encoder::encode_map( array( 'inner' => $empty_map ) );
		$this->assertStringContainsString( "\xa0", $outer, 'Pre-encoded map must survive encode_map passthrough.' );

		// Sanity: a non-pre-encoded string should be encoded as text.
		$text_outer = CBOR_Encoder::encode_map( array( 'key' => 'value' ) );
		$this->assertStringNotContainsString( "\xa0", $text_outer );
	}

	/**
	 * Check whether the c2pa-conformance-suite CLI is available.
	 *
	 * @return bool
	 */
	private function conformance_suite_available(): bool {
		$output     = array();
		$return_var = 0;
		exec( self::CONFORM_CMD . ' --help 2>/dev/null', $output, $return_var );

		return 0 === $return_var;
	}
}
