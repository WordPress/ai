<?php
/**
 * Tests for the Claim_Builder class.
 *
 * Validates C2PA 2.4 claim and assertion structure generation.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Content_Provenance\C2PA
 */

declare( strict_types=1 );

namespace WordPress\AI\Tests\Integration\Experiments\Content_Provenance\C2PA;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Content_Provenance\C2PA\Claim_Builder;

/**
 * Claim_Builder test case.
 *
 * @since 0.7.0
 */
class Claim_BuilderTest extends WP_UnitTestCase {

	/**
	 * Test that build() returns claim_cbor and assertion_map.
	 */
	public function test_build_returns_expected_keys(): void {
		$builder = new Claim_Builder(
			'Hello, WordPress!',
			'c2pa.created',
			array( 'title' => 'Test Post' ),
			'urn:uuid:test-manifest'
		);

		$result = $builder->build();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'claim_cbor', $result );
		$this->assertArrayHasKey( 'assertion_map', $result );
		$this->assertNotEmpty( $result['claim_cbor'] );
		$this->assertNotEmpty( $result['assertion_map'] );
	}

	/**
	 * Test that assertion map contains required C2PA assertions.
	 */
	public function test_assertion_map_contains_required_assertions(): void {
		$builder = new Claim_Builder(
			'Content to sign.',
			'c2pa.created',
			array(),
			'urn:uuid:test'
		);

		$result = $builder->build();

		$this->assertArrayHasKey( Claim_Builder::ASSERTION_ACTIONS, $result['assertion_map'] );
		$this->assertArrayHasKey( Claim_Builder::ASSERTION_HASH_DATA, $result['assertion_map'] );
		$this->assertArrayHasKey( Claim_Builder::ASSERTION_SOFT_BINDING, $result['assertion_map'] );
	}

	/**
	 * Test that assertion CBOR values are non-empty byte strings.
	 */
	public function test_assertion_cbor_values_are_non_empty(): void {
		$builder = new Claim_Builder( 'Test.', 'c2pa.created', array(), 'urn:uuid:test' );
		$result  = $builder->build();

		foreach ( $result['assertion_map'] as $label => $cbor ) {
			$this->assertIsString( $cbor, "Assertion '{$label}' should be a string." );
			$this->assertNotEmpty( $cbor, "Assertion '{$label}' should not be empty." );
		}
	}

	/**
	 * Test that claim CBOR is non-empty.
	 */
	public function test_claim_cbor_is_non_empty(): void {
		$builder = new Claim_Builder( 'Test.', 'c2pa.created', array(), 'urn:uuid:test' );
		$result  = $builder->build();

		$this->assertNotEmpty( $result['claim_cbor'] );
	}

	/**
	 * Test that hash.data assertion contains correct SHA-256 hash of content.
	 */
	public function test_hash_data_contains_content_sha256(): void {
		$content = 'Specific test content for hashing.';
		$builder = new Claim_Builder( $content, 'c2pa.created', array(), 'urn:uuid:test' );
		$result  = $builder->build();

		$expected_hash = hash( 'sha256', $content, true );

		// The hash bytes should appear in the hash.data assertion CBOR.
		$this->assertStringContainsString(
			$expected_hash,
			$result['assertion_map'][ Claim_Builder::ASSERTION_HASH_DATA ],
			'Hash data assertion should contain SHA-256 of content.'
		);
	}

	/**
	 * Test that different content produces different hashes.
	 */
	public function test_different_content_produces_different_hashes(): void {
		$builder1 = new Claim_Builder( 'Content A', 'c2pa.created', array(), 'urn:uuid:test' );
		$builder2 = new Claim_Builder( 'Content B', 'c2pa.created', array(), 'urn:uuid:test' );

		$result1 = $builder1->build();
		$result2 = $builder2->build();

		$this->assertNotSame(
			$result1['assertion_map'][ Claim_Builder::ASSERTION_HASH_DATA ],
			$result2['assertion_map'][ Claim_Builder::ASSERTION_HASH_DATA ],
			'Different content should produce different hash data assertions.'
		);
	}

	/**
	 * Test that claim CBOR starts with CBOR map marker.
	 */
	public function test_claim_cbor_is_cbor_map(): void {
		$builder = new Claim_Builder( 'Test.', 'c2pa.created', array(), 'urn:uuid:test' );
		$result  = $builder->build();

		$first_byte = ord( $result['claim_cbor'][0] );
		// CBOR map: major type 5, initial byte 0xa0-0xbf.
		$this->assertGreaterThanOrEqual( 0xa0, $first_byte, 'Claim should start with CBOR map header.' );
		$this->assertLessThanOrEqual( 0xbf, $first_byte, 'Claim should start with CBOR map header.' );
	}

	/**
	 * Test that the claim contains the manifest label in signature reference.
	 */
	public function test_claim_contains_manifest_label(): void {
		$label   = 'urn:uuid:my-special-manifest';
		$builder = new Claim_Builder( 'Test.', 'c2pa.created', array(), $label );
		$result  = $builder->build();

		$this->assertStringContainsString(
			$label,
			$result['claim_cbor'],
			'Claim should contain manifest label in signature reference.'
		);
	}

	/**
	 * Test that claim contains claim_generator_info name.
	 */
	public function test_claim_contains_generator_info(): void {
		$builder = new Claim_Builder( 'Test.', 'c2pa.created', array(), 'urn:uuid:test' );
		$result  = $builder->build();

		$this->assertStringContainsString(
			'WordPress AI Plugin',
			$result['claim_cbor'],
			'Claim should contain generator name in claim_generator_info.'
		);
	}

	/**
	 * Test that claim uses v2 structure: created_assertions, no v1 fields.
	 */
	public function test_claim_uses_v2_structure(): void {
		$builder = new Claim_Builder( 'Test.', 'c2pa.created', array( 'title' => 'My Post' ), 'urn:uuid:test' );
		$result  = $builder->build();
		$cbor    = $result['claim_cbor'];

		// v2 fields must be present.
		$this->assertStringContainsString( 'created_assertions', $cbor, 'Claim must use created_assertions (v2).' );
		$this->assertStringContainsString( 'claim_generator_info', $cbor, 'Claim must use claim_generator_info (v2).' );
		$this->assertStringContainsString( 'instanceID', $cbor, 'Claim must contain instanceID.' );

		// v1-only fields must be absent.
		$this->assertStringNotContainsString( 'claimGenerator', $cbor, 'Claim must not contain v1 claimGenerator string.' );
		$this->assertStringNotContainsString( 'dc:format', $cbor, 'Claim must not contain v1 dc:format.' );
	}

	/**
	 * Test that dc:title is conditionally included only when non-empty.
	 */
	public function test_claim_omits_empty_title(): void {
		$builder_no_title = new Claim_Builder( 'Test.', 'c2pa.created', array(), 'urn:uuid:test' );
		$result_no_title  = $builder_no_title->build();

		$builder_with_title = new Claim_Builder( 'Test.', 'c2pa.created', array( 'title' => 'Present' ), 'urn:uuid:test' );
		$result_with_title  = $builder_with_title->build();

		$this->assertStringNotContainsString( 'dc:title', $result_no_title['claim_cbor'], 'Empty title should not appear in claim.' );
		$this->assertStringContainsString( 'dc:title', $result_with_title['claim_cbor'], 'Non-empty title should appear in claim.' );
	}

	/**
	 * Test that assertion references in created_assertions include alg field.
	 */
	public function test_assertion_references_include_alg(): void {
		$builder = new Claim_Builder( 'Test.', 'c2pa.created', array(), 'urn:uuid:test' );
		$result  = $builder->build();
		$cbor    = $result['claim_cbor'];

		// The alg field ("sha256") should appear in assertion references.
		// Count occurrences: top-level alg + one per assertion reference (3 assertions).
		$count = substr_count( $cbor, 'sha256' );
		$this->assertGreaterThanOrEqual( 4, $count, 'Expected alg field in claim and each assertion reference.' );
	}

	/**
	 * Test that actions assertion contains the specified action type.
	 */
	public function test_actions_assertion_contains_action_type(): void {
		$builder = new Claim_Builder( 'Test.', 'c2pa.edited', array(), 'urn:uuid:test' );
		$result  = $builder->build();

		$this->assertStringContainsString(
			'c2pa.edited',
			$result['assertion_map'][ Claim_Builder::ASSERTION_ACTIONS ],
			'Actions assertion should contain the specified action.'
		);
	}

	/**
	 * Test that soft_binding assertion contains required alg and blocks fields.
	 */
	public function test_soft_binding_contains_alg_and_blocks(): void {
		$builder = new Claim_Builder( 'Test.', 'c2pa.created', array(), 'urn:uuid:test' );
		$result  = $builder->build();

		$soft_binding = $result['assertion_map'][ Claim_Builder::ASSERTION_SOFT_BINDING ];

		$this->assertStringContainsString(
			'c2pa.text.vs16',
			$soft_binding,
			'Soft binding should contain c2pa.text.vs16 algorithm.'
		);
		$this->assertStringContainsString(
			'blocks',
			$soft_binding,
			'Soft binding should contain required blocks field per C2PA CDDL.'
		);
		$this->assertStringContainsString(
			'scope',
			$soft_binding,
			'Soft binding block should contain scope field.'
		);
		$this->assertStringContainsString(
			'value',
			$soft_binding,
			'Soft binding block should contain value field.'
		);
	}

	/**
	 * Test that soft_binding block value contains SHA-256 hash of content.
	 */
	public function test_soft_binding_block_value_contains_content_hash(): void {
		$content = 'Specific content for soft binding hash.';
		$builder = new Claim_Builder( $content, 'c2pa.created', array(), 'urn:uuid:test' );
		$result  = $builder->build();

		$expected_hash = hash( 'sha256', $content, true );

		$this->assertStringContainsString(
			$expected_hash,
			$result['assertion_map'][ Claim_Builder::ASSERTION_SOFT_BINDING ],
			'Soft binding block value should contain SHA-256 of content.'
		);
	}

	/**
	 * Test that soft_binding does not contain the non-spec document_length field.
	 */
	public function test_soft_binding_has_no_document_length(): void {
		$builder = new Claim_Builder( 'Test.', 'c2pa.created', array(), 'urn:uuid:test' );
		$result  = $builder->build();

		$this->assertStringNotContainsString(
			'document_length',
			$result['assertion_map'][ Claim_Builder::ASSERTION_SOFT_BINDING ],
			'Soft binding must not contain non-spec document_length field.'
		);
	}

	/**
	 * Test that set_digital_source_type changes the action assertion.
	 */
	public function test_set_digital_source_type(): void {
		$builder = new Claim_Builder( 'Test.', 'c2pa.created', array(), 'urn:uuid:test' );
		$builder->set_digital_source_type( 'http://cv.iptc.org/newscodes/digitalsourcetype/humanEdited' );
		$result = $builder->build();

		$this->assertStringContainsString(
			'humanEdited',
			$result['assertion_map'][ Claim_Builder::ASSERTION_ACTIONS ],
			'Actions assertion should contain the custom digital source type.'
		);
	}

	/**
	 * Test that no ingredient assertion is included without a previous manifest.
	 */
	public function test_no_ingredient_assertion_without_previous_manifest(): void {
		$builder = new Claim_Builder( 'Test.', 'c2pa.created', array(), 'urn:uuid:test' );
		$result  = $builder->build();

		$this->assertArrayNotHasKey(
			Claim_Builder::ASSERTION_INGREDIENT,
			$result['assertion_map'],
			'Assertion map should not contain ingredient when no previous manifest.'
		);
	}

	/**
	 * Test that ingredient assertion is included when a previous manifest is provided.
	 */
	public function test_ingredient_assertion_present_with_previous_manifest(): void {
		$previous = 'fake-previous-manifest-bytes';
		$builder  = new Claim_Builder( 'Test.', 'c2pa.edited', array(), 'urn:uuid:test', $previous );
		$result   = $builder->build();

		$this->assertArrayHasKey(
			Claim_Builder::ASSERTION_INGREDIENT,
			$result['assertion_map'],
			'Assertion map should contain ingredient when previous manifest is provided.'
		);
		$this->assertNotEmpty( $result['assertion_map'][ Claim_Builder::ASSERTION_INGREDIENT ] );
	}

	/**
	 * Test that ingredient assertion contains SHA-256 hash of previous manifest.
	 */
	public function test_ingredient_assertion_contains_previous_manifest_hash(): void {
		$previous      = 'previous-manifest-data-for-hashing';
		$expected_hash = hash( 'sha256', $previous, true );
		$builder       = new Claim_Builder( 'Test.', 'c2pa.edited', array(), 'urn:uuid:test', $previous );
		$result        = $builder->build();

		$ingredient_cbor = $result['assertion_map'][ Claim_Builder::ASSERTION_INGREDIENT ];

		$this->assertStringContainsString(
			$expected_hash,
			$ingredient_cbor,
			'Ingredient assertion should contain SHA-256 hash of previous manifest.'
		);
	}

	/**
	 * Test that ingredient v3 assertion uses Dublin Core field names.
	 */
	public function test_ingredient_v3_uses_dublin_core_field_names(): void {
		$previous = 'fake-previous-manifest';
		$builder  = new Claim_Builder( 'Test.', 'c2pa.edited', array(), 'urn:uuid:test', $previous );
		$result   = $builder->build();

		$ingredient_cbor = $result['assertion_map'][ Claim_Builder::ASSERTION_INGREDIENT ];

		$this->assertStringContainsString(
			'dc:title',
			$ingredient_cbor,
			'Ingredient v3 must use dc:title (Dublin Core prefix).'
		);
		$this->assertStringContainsString(
			'dc:format',
			$ingredient_cbor,
			'Ingredient v3 must use dc:format (Dublin Core prefix).'
		);
		$this->assertStringNotContainsString(
			"\x05title",
			$ingredient_cbor,
			'Ingredient must not use bare title field (non-spec).'
		);
	}

	/**
	 * Test that ingredient v3 assertion uses activeManifest hashedUriMap.
	 */
	public function test_ingredient_v3_uses_active_manifest(): void {
		$previous = 'fake-previous-manifest';
		$label    = 'urn:uuid:ingredient-test';
		$builder  = new Claim_Builder( 'Test.', 'c2pa.edited', array(), $label, $previous );
		$result   = $builder->build();

		$ingredient_cbor = $result['assertion_map'][ Claim_Builder::ASSERTION_INGREDIENT ];

		$this->assertStringContainsString(
			'activeManifest',
			$ingredient_cbor,
			'Ingredient v3 must use activeManifest hashedUriMap.'
		);
		$this->assertStringContainsString(
			'self#jumbf=',
			$ingredient_cbor,
			'activeManifest must contain a JUMBF URI.'
		);
		$this->assertStringContainsString(
			'sha256',
			$ingredient_cbor,
			'activeManifest must contain hash algorithm.'
		);
	}

	/**
	 * Test that ingredient assertion label is c2pa.ingredient.v3.
	 */
	public function test_ingredient_assertion_label_is_v3(): void {
		$this->assertSame(
			'c2pa.ingredient.v3',
			Claim_Builder::ASSERTION_INGREDIENT,
			'Ingredient assertion label must be c2pa.ingredient.v3 per C2PA 2.4.'
		);
	}

	/**
	 * Test that claim references ingredient assertion in created_assertions.
	 */
	public function test_claim_references_ingredient_in_created_assertions(): void {
		$previous = 'some-previous-manifest';
		$label    = 'urn:uuid:chain-test';
		$builder  = new Claim_Builder( 'Test.', 'c2pa.edited', array(), $label, $previous );
		$result   = $builder->build();

		// The claim CBOR should contain the ingredient assertion URI.
		$expected_uri = $label . '/c2pa.assertions/' . Claim_Builder::ASSERTION_INGREDIENT;
		$this->assertStringContainsString(
			$expected_uri,
			$result['claim_cbor'],
			'Claim should contain ingredient assertion URI.'
		);
	}

	/**
	 * Test that empty previous manifest string does not produce an ingredient assertion.
	 */
	public function test_empty_previous_manifest_produces_no_ingredient(): void {
		$builder = new Claim_Builder( 'Test.', 'c2pa.edited', array(), 'urn:uuid:test', '' );
		$result  = $builder->build();

		$this->assertArrayNotHasKey(
			Claim_Builder::ASSERTION_INGREDIENT,
			$result['assertion_map'],
			'Empty string previous manifest should not produce ingredient assertion.'
		);
	}

	/**
	 * Test that hash.data assertion has no exclusions by default.
	 */
	public function test_hash_data_has_no_exclusions_by_default(): void {
		$builder = new Claim_Builder( 'Test.', 'c2pa.created', array(), 'urn:uuid:test' );
		$result  = $builder->build();

		$hash_data = $result['assertion_map'][ Claim_Builder::ASSERTION_HASH_DATA ];
		$this->assertStringNotContainsString(
			'exclusions',
			$hash_data,
			'Hash data assertion should not contain exclusions by default.'
		);
	}

	/**
	 * Test that hash.data assertion contains exclusions when set.
	 */
	public function test_hash_data_contains_exclusions_when_set(): void {
		$builder = new Claim_Builder( 'Test.', 'c2pa.created', array(), 'urn:uuid:test' );
		$builder->set_exclusions( 100, 500 );
		$result = $builder->build();

		$hash_data = $result['assertion_map'][ Claim_Builder::ASSERTION_HASH_DATA ];
		$this->assertStringContainsString( 'exclusions', $hash_data, 'Hash data should contain exclusions field.' );
		$this->assertStringContainsString( 'start', $hash_data, 'Exclusion range should contain start field.' );
		$this->assertStringContainsString( 'length', $hash_data, 'Exclusion range should contain length field.' );
	}

	/**
	 * Test that exclusions do not change the content hash in hash.data assertion.
	 */
	public function test_exclusions_do_not_affect_content_hash(): void {
		$content       = 'Test content for hashing.';
		$expected_hash = hash( 'sha256', $content, true );

		$builder_no_excl = new Claim_Builder( $content, 'c2pa.created', array(), 'urn:uuid:test' );
		$result_no_excl  = $builder_no_excl->build();

		$builder_with_excl = new Claim_Builder( $content, 'c2pa.created', array(), 'urn:uuid:test' );
		$builder_with_excl->set_exclusions( 25, 3000 );
		$result_with_excl = $builder_with_excl->build();

		$this->assertStringContainsString(
			$expected_hash,
			$result_no_excl['assertion_map'][ Claim_Builder::ASSERTION_HASH_DATA ],
			'Hash should be present without exclusions.'
		);
		$this->assertStringContainsString(
			$expected_hash,
			$result_with_excl['assertion_map'][ Claim_Builder::ASSERTION_HASH_DATA ],
			'Hash should be present with exclusions.'
		);
	}

	/**
	 * Test that different exclusion values produce different assertion CBOR.
	 */
	public function test_different_exclusions_produce_different_assertions(): void {
		$builder1 = new Claim_Builder( 'Test.', 'c2pa.created', array(), 'urn:uuid:test' );
		$builder1->set_exclusions( 100, 500 );
		$result1 = $builder1->build();

		$builder2 = new Claim_Builder( 'Test.', 'c2pa.created', array(), 'urn:uuid:test' );
		$builder2->set_exclusions( 200, 600 );
		$result2 = $builder2->build();

		$this->assertNotSame(
			$result1['assertion_map'][ Claim_Builder::ASSERTION_HASH_DATA ],
			$result2['assertion_map'][ Claim_Builder::ASSERTION_HASH_DATA ],
			'Different exclusion values should produce different hash data assertions.'
		);
	}
}
