<?php
/**
 * C2PA claim and assertion builder.
 *
 * Translates WordPress content metadata into C2PA 2.4 claim and assertion
 * structures, serialized as CBOR. Produces the semantic data layer that
 * gets wrapped in COSE_Sign1 and JUMBF containers.
 *
 * @package WordPress\AI\Experiments\Content_Provenance\C2PA
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Content_Provenance\C2PA;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * C2PA 2.4 claim and assertion builder.
 *
 * @since x.x.x
 */
final class Claim_Builder {

	/**
	 * C2PA assertion label for actions.
	 *
	 * @var string
	 */
	public const ASSERTION_ACTIONS = 'c2pa.actions.v2';

	/**
	 * C2PA assertion label for content hash binding.
	 *
	 * @var string
	 */
	public const ASSERTION_HASH_DATA = 'c2pa.hash.data';

	/**
	 * C2PA assertion label for soft binding (text embedding).
	 *
	 * @var string
	 */
	public const ASSERTION_SOFT_BINDING = 'c2pa.soft-binding';

	/**
	 * C2PA assertion label for ingredient reference (v3 per C2PA 2.4).
	 *
	 * @var string
	 */
	public const ASSERTION_INGREDIENT = 'c2pa.ingredient.v3';

	/**
	 * Content text to sign.
	 *
	 * @var string
	 */
	private string $content;

	/**
	 * C2PA action type.
	 *
	 * @var string
	 */
	private string $action;

	/**
	 * Post metadata.
	 *
	 * @var array<string, mixed>
	 */
	private array $metadata;

	/**
	 * Manifest label for JUMBF self-references.
	 *
	 * @var string
	 */
	private string $manifest_label;

	/**
	 * Previous manifest bytes for ingredient chain, or null.
	 *
	 * @var string|null
	 */
	private ?string $previous_manifest;

	/**
	 * IPTC digital source type URI.
	 *
	 * @var string
	 */
	private string $digital_source_type = 'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia';

	/**
	 * Exclusion start byte offset in the NFC-normalized UTF-8 text, or null.
	 *
	 * @var int|null
	 */
	private ?int $exclusion_start = null;

	/**
	 * Exclusion byte length of the VS-encoded wrapper, or null.
	 *
	 * @var int|null
	 */
	private ?int $exclusion_length = null;

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param string               $content            Content text to sign.
	 * @param string               $action             C2PA action type (e.g. "c2pa.created", "c2pa.edited").
	 * @param array<string, mixed> $metadata           Post metadata (title, post_id, etc.).
	 * @param string               $manifest_label     Manifest label for JUMBF self-references.
	 * @param string|null          $previous_manifest  Previous manifest bytes for ingredient chain.
	 */
	public function __construct( string $content, string $action, array $metadata, string $manifest_label, ?string $previous_manifest = null ) {
		$this->content           = $content;
		$this->action            = $action;
		$this->metadata          = $metadata;
		$this->manifest_label    = $manifest_label;
		$this->previous_manifest = $previous_manifest;
	}

	/**
	 * Sets the IPTC digital source type for the actions assertion.
	 *
	 * @since x.x.x
	 *
	 * @param string $type IPTC digital source type URI.
	 */
	public function set_digital_source_type( string $type ): void {
		$this->digital_source_type = $type;
	}

	/**
	 * Sets the exclusion range for the c2pa.hash.data assertion.
	 *
	 * The exclusion range identifies the byte offset and length of the
	 * C2PATextManifestWrapper in the NFC-normalized UTF-8 text. Verifiers
	 * use this to locate and remove the wrapper before hashing.
	 *
	 * @since x.x.x
	 *
	 * @param int $start  Byte offset where the wrapper begins in NFC-normalized UTF-8 text.
	 * @param int $length Byte length of the VS-encoded wrapper.
	 */
	public function set_exclusions( int $start, int $length ): void {
		$this->exclusion_start  = $start;
		$this->exclusion_length = $length;
	}

	/**
	 * Builds the complete claim and assertion set.
	 *
	 * Returns an associative array with:
	 * - "claim_cbor": CBOR-encoded claim map
	 * - "assertion_map": array of assertion label => CBOR-encoded assertion bytes
	 *
	 * @since x.x.x
	 *
	 * @return array{claim_cbor: string, assertion_map: array<string, string>}
	 */
	public function build(): array {
		$assertion_map = $this->build_assertions();
		$claim_cbor    = $this->build_claim( $assertion_map );

		return array(
			'claim_cbor'    => $claim_cbor,
			'assertion_map' => $assertion_map,
		);
	}

	/**
	 * Builds all C2PA assertions as CBOR.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, string> Assertion label => CBOR-encoded bytes.
	 */
	private function build_assertions(): array {
		$assertions = array(
			self::ASSERTION_ACTIONS      => $this->build_actions_assertion(),
			self::ASSERTION_HASH_DATA    => $this->build_hash_data_assertion(),
			self::ASSERTION_SOFT_BINDING => $this->build_soft_binding_assertion(),
		);

		if ( null !== $this->previous_manifest && '' !== $this->previous_manifest ) {
			$assertions[ self::ASSERTION_INGREDIENT ] = $this->build_ingredient_assertion();
		}

		return $assertions;
	}

	/**
	 * Builds the c2pa.actions.v2 assertion.
	 *
	 * @since x.x.x
	 *
	 * @return string CBOR-encoded assertion.
	 */
	private function build_actions_assertion(): string {
		$action_map = array(
			'action'            => $this->action,
			'digitalSourceType' => $this->digital_source_type,
		);

		$assertion = array(
			'actions' => array( $action_map ),
		);

		return CBOR_Encoder::encode( $assertion );
	}

	/**
	 * Builds the c2pa.hash.data assertion with SHA-256 content hash.
	 *
	 * Per C2PA 2.4 data-hash-map CDDL, required fields are `hash` (bstr)
	 * and `pad` (zero-filled bstr). The hash covers NFC-normalized UTF-8
	 * text with the manifest wrapper excluded.
	 *
	 * @since x.x.x
	 *
	 * @return string CBOR-encoded assertion.
	 */
	private function build_hash_data_assertion(): string {
		$normalized = self::nfc_normalize( $this->content );
		$hash_bytes = hash( 'sha256', $normalized, true );

		$assertion = array(
			'hash' => CBOR_Encoder::encode_byte_string( $hash_bytes ),
			'pad'  => CBOR_Encoder::encode_byte_string( str_repeat( "\x00", 32 ) ),
		);

		if ( null !== $this->exclusion_start && null !== $this->exclusion_length ) {
			$assertion['exclusions'] = array(
				array(
					'start'  => $this->exclusion_start,
					'length' => $this->exclusion_length,
				),
			);
		}

		return CBOR_Encoder::encode( $assertion );
	}

	/**
	 * Builds the c2pa.soft-binding assertion for text embedding (Section A.7).
	 *
	 * Per the C2PA 2.4 soft-binding-map CDDL, required fields are `alg`
	 * (binding algorithm) and `blocks` (array of soft-binding-block-map).
	 * Each block contains a `scope` (soft-binding-scope-map, all fields
	 * optional) and a `value` (bstr, algorithm-specific binding value).
	 *
	 * For text using c2pa.text.vs16, the scope covers the full document
	 * (empty scope map) and the value is the SHA-256 hash of the
	 * NFC-normalized text.
	 *
	 * @since x.x.x
	 *
	 * @return string CBOR-encoded assertion.
	 */
	private function build_soft_binding_assertion(): string {
		$normalized = self::nfc_normalize( $this->content );

		$block = array(
			'scope' => CBOR_Encoder::encode_map( array() ),
			'value' => CBOR_Encoder::encode_byte_string(
				hash( 'sha256', $normalized, true )
			),
		);

		$assertion = array(
			'alg'    => 'c2pa.text.vs16',
			'blocks' => array( $block ),
		);

		return CBOR_Encoder::encode( $assertion );
	}

	/**
	 * Builds the c2pa.ingredient.v3 assertion referencing a previous manifest.
	 *
	 * Per C2PA 2.4 ingredient-map-v3 CDDL, `relationship` is the only
	 * required field. `dc:title` and `dc:format` are optional in v3 but
	 * included for interoperability. The previous manifest is referenced
	 * via `activeManifest`, a hashed-uri-map containing a JUMBF URI, hash
	 * algorithm, and SHA-256 hash of the previous manifest store.
	 *
	 * @since x.x.x
	 *
	 * @return string CBOR-encoded assertion.
	 */
	private function build_ingredient_assertion(): string {
		/** @var string $previous_manifest — guaranteed non-null by caller. */
		$previous_manifest = (string) $this->previous_manifest;

		$assertion = array(
			'relationship'   => 'parentOf',
			'dc:title'       => 'Previous version',
			'dc:format'      => 'application/c2pa',
			'activeManifest' => array(
				'url'  => 'self#jumbf=' . $this->manifest_label . '/c2pa.assertions/' . self::ASSERTION_INGREDIENT,
				'alg'  => 'sha256',
				'hash' => CBOR_Encoder::encode_byte_string( hash( 'sha256', $previous_manifest, true ) ),
			),
		);

		return CBOR_Encoder::encode( $assertion );
	}

	/**
	 * Builds the C2PA v2 claim structure referencing assertion hashes.
	 *
	 * Produces a claim map with v2 field names: `created_assertions` (not
	 * `assertions`), `claim_generator_info` (not `claimGenerator`), and
	 * no `dc:format`. See C2PA 2.4 Section 10 for the claim structure.
	 *
	 * Per the spec, hashed URI references hash the JUMBF superbox content
	 * (description box + content boxes, excluding the superbox header).
	 *
	 * @since x.x.x
	 *
	 * @param array<string, string> $assertion_map Assertion label => CBOR bytes.
	 * @return string CBOR-encoded claim map.
	 */
	private function build_claim( array $assertion_map ): string {
		$assertion_refs = array();
		foreach ( $assertion_map as $label => $cbor_bytes ) {
			// Build the JUMBF assertion box and hash its content (everything
			// after the 8-byte superbox header) per C2PA Hashing JUMBF Boxes.
			$jumbf_box     = JUMBF_Writer::build_assertion_box( $label, $cbor_bytes );
			$jumbf_content = substr( $jumbf_box, 8 );

			$assertion_refs[] = array(
				'url'  => 'self#jumbf=' . $this->manifest_label . '/c2pa.assertions/' . $label,
				'hash' => CBOR_Encoder::encode_byte_string( hash( 'sha256', $jumbf_content, true ) ),
				'alg'  => 'sha256',
			);
		}

		$plugin_version = self::get_plugin_version();

		$claim = array(
			'instanceID'           => 'urn:uuid:' . wp_generate_uuid4(),
			'claim_generator_info' => array(
				'name'    => 'WordPress AI Plugin',
				'version' => $plugin_version,
			),
			'signature'            => 'self#jumbf=' . $this->manifest_label . '/c2pa.signature',
			'created_assertions'   => $assertion_refs,
			'alg'                  => 'sha256',
		);

		$title = isset( $this->metadata['title'] ) ? (string) $this->metadata['title'] : '';

		if ( '' !== $title ) {
			$claim['dc:title'] = $title;
		}

		return CBOR_Encoder::encode( $claim );
	}

	/**
	 * Normalizes a string to Unicode NFC form.
	 *
	 * Falls back to the original string when the intl extension is not available.
	 *
	 * @since x.x.x
	 *
	 * @param string $text Input text.
	 * @return string NFC-normalized text.
	 */
	private static function nfc_normalize( string $text ): string {
		if ( class_exists( 'Normalizer' ) ) {
			$normalized = \Normalizer::normalize( $text, \Normalizer::FORM_C );

			if ( false !== $normalized ) {
				return $normalized;
			}
		}

		return $text;
	}

	/**
	 * Reads the plugin version from the main plugin file header.
	 *
	 * @since x.x.x
	 *
	 * @return string Plugin version string, or '0.0.0' if unreadable.
	 */
	private static function get_plugin_version(): string {
		static $version = null;

		if ( null !== $version ) {
			return $version;
		}

		$plugin_file = defined( 'WPAI_DIR' ) ? WPAI_DIR . '/ai.php' : '';

		if ( '' === $plugin_file ) {
			$version = '0.0.0';
			return $version;
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$data    = get_plugin_data( $plugin_file, false, false );
		$version = $data['Version'] ?? '0.0.0';

		return $version;
	}
}
