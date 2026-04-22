<?php
/**
 * JUMBF box writer for C2PA manifest stores.
 *
 * Implements the subset of ISO 19566-5 (JUMBF) required to produce C2PA
 * manifest store containers. All C2PA manifests are wrapped in a hierarchy
 * of JUMBF superboxes containing CBOR-encoded claims and COSE signatures.
 *
 * @package WordPress\AI\Experiments\Content_Provenance\C2PA
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Content_Provenance\C2PA;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * JUMBF box writer for C2PA manifest stores.
 *
 * @since x.x.x
 */
final class JUMBF_Writer {

	/**
	 * C2PA JUMBF namespace suffix shared by all C2PA-defined UUIDs.
	 *
	 * @var string
	 */
	private const JUMBF_NAMESPACE = "\x00\x11\x00\x10\x80\x00\x00\xAA\x00\x38\x9B\x71";

	/**
	 * C2PA manifest store superbox UUID (ASCII tag: "c2pa").
	 *
	 * @var string
	 */
	public const UUID_MANIFEST_STORE = "\x63\x32\x70\x61" . self::JUMBF_NAMESPACE;

	/**
	 * C2PA manifest superbox UUID (ASCII tag: "c2ma").
	 *
	 * @var string
	 */
	public const UUID_MANIFEST = "\x63\x32\x6D\x61" . self::JUMBF_NAMESPACE;

	/**
	 * C2PA claim superbox UUID (ASCII tag: "c2cl").
	 *
	 * @var string
	 */
	public const UUID_CLAIM = "\x63\x32\x63\x6C" . self::JUMBF_NAMESPACE;

	/**
	 * C2PA assertion store superbox UUID (ASCII tag: "c2as").
	 *
	 * @var string
	 */
	public const UUID_ASSERTION_STORE = "\x63\x32\x61\x73" . self::JUMBF_NAMESPACE;

	/**
	 * C2PA claim signature superbox UUID (ASCII tag: "c2cs").
	 *
	 * @var string
	 */
	public const UUID_SIGNATURE = "\x63\x32\x63\x73" . self::JUMBF_NAMESPACE;

	/**
	 * CBOR content type UUID (ASCII tag: "cbor").
	 *
	 * @var string
	 */
	public const UUID_CBOR = "\x63\x62\x6F\x72" . self::JUMBF_NAMESPACE;

	/**
	 * Writes a raw JUMBF box: 4-byte big-endian size + 4-byte type + content.
	 *
	 * @since x.x.x
	 *
	 * @param string $box_type 4-byte ASCII box type (e.g. "jumb", "jumd", "cbor").
	 * @param string $content  Box content bytes.
	 * @return string Complete box bytes.
	 */
	public static function write_box( string $box_type, string $content ): string {
		$size = 8 + strlen( $content );

		// Use extended (64-bit) size for very large boxes.
		if ( $size > 0xFFFFFFFF ) {
			$extended_size = 16 + strlen( $content );
			return pack( 'N', 1 ) . $box_type . pack( 'J', $extended_size ) . $content;
		}

		return pack( 'N', $size ) . $box_type . $content;
	}

	/**
	 * Writes a JUMBF description box (type "jumd").
	 *
	 * Contains: 16-byte UUID + 1-byte toggles + NUL-terminated label string.
	 *
	 * @since x.x.x
	 *
	 * @param string $uuid    16-byte UUID identifying the box type.
	 * @param string $label   Human-readable label (NUL-terminated in output).
	 * @param int    $toggles Toggle bits (default 3 = requestable + label present).
	 * @return string Complete description box bytes.
	 */
	public static function write_description_box( string $uuid, string $label, int $toggles = 3 ): string {
		$content = $uuid . pack( 'C', $toggles ) . $label . "\x00";
		return self::write_box( 'jumd', $content );
	}

	/**
	 * Writes a JUMBF superbox (type "jumb"): description box + child boxes.
	 *
	 * @since x.x.x
	 *
	 * @param string          $uuid     16-byte UUID for the description box.
	 * @param string          $label    Label for the description box.
	 * @param array<string>   $children Array of pre-built child box byte strings.
	 * @return string Complete superbox bytes.
	 */
	public static function write_superbox( string $uuid, string $label, array $children ): string {
		$desc    = self::write_description_box( $uuid, $label );
		$content = $desc;

		foreach ( $children as $child ) {
			$content .= $child;
		}

		return self::write_box( 'jumb', $content );
	}

	/**
	 * Builds a single assertion JUMBF box containing CBOR content.
	 *
	 * Structure: jumb superbox → jumd (CBOR UUID + label) + cbor content box.
	 *
	 * @since x.x.x
	 *
	 * @param string $label     Assertion label (e.g. "c2pa.hash.data").
	 * @param string $cbor_data CBOR-encoded assertion content.
	 * @return string Complete assertion box bytes.
	 */
	public static function build_assertion_box( string $label, string $cbor_data ): string {
		$cbor_box = self::write_box( 'cbor', $cbor_data );
		return self::write_superbox( self::UUID_CBOR, $label, array( $cbor_box ) );
	}

	/**
	 * Builds the assertion store superbox from assertion label-to-CBOR pairs.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, string> $assertions Assertion label => CBOR bytes.
	 * @return string Complete assertion store superbox bytes.
	 */
	public static function build_assertion_store( array $assertions ): string {
		$children = array();
		foreach ( $assertions as $label => $cbor_data ) {
			$children[] = self::build_assertion_box( $label, $cbor_data );
		}

		return self::write_superbox( self::UUID_ASSERTION_STORE, 'c2pa.assertions', $children );
	}

	/**
	 * Builds the claim superbox wrapping CBOR claim bytes.
	 *
	 * @since x.x.x
	 *
	 * @param string $claim_cbor CBOR-encoded claim.
	 * @return string Complete claim superbox bytes.
	 */
	public static function build_claim_box( string $claim_cbor ): string {
		$cbor_box = self::write_box( 'cbor', $claim_cbor );
		return self::write_superbox( self::UUID_CLAIM, 'c2pa.claim', array( $cbor_box ) );
	}

	/**
	 * Builds the signature superbox wrapping COSE_Sign1 bytes.
	 *
	 * @since x.x.x
	 *
	 * @param string $cose_bytes COSE_Sign1 encoded bytes.
	 * @return string Complete signature superbox bytes.
	 */
	public static function build_signature_box( string $cose_bytes ): string {
		$cose_box = self::write_box( 'cbor', $cose_bytes );
		return self::write_superbox( self::UUID_SIGNATURE, 'c2pa.signature', array( $cose_box ) );
	}

	/**
	 * Builds a complete C2PA manifest store JUMBF structure.
	 *
	 * Hierarchy:
	 *   manifest store (c2pa) → manifest (c2ma) → {
	 *     claim (c2cl),
	 *     assertion store (c2as) → { assertion boxes... },
	 *     signature (c2cs)
	 *   }
	 *
	 * @since x.x.x
	 *
	 * @param string                $claim_cbor      CBOR-encoded claim.
	 * @param array<string, string> $assertion_boxes  Assertion label => CBOR bytes.
	 * @param string                $cose_sign1_bytes COSE_Sign1 encoded bytes.
	 * @param string                $manifest_label   Manifest label (e.g. "urn:uuid:...").
	 * @return string Complete manifest store JUMBF bytes.
	 */
	public static function build_manifest_store(
		string $claim_cbor,
		array $assertion_boxes,
		string $cose_sign1_bytes,
		string $manifest_label
	): string {
		$claim_box     = self::build_claim_box( $claim_cbor );
		$assertion_box = self::build_assertion_store( $assertion_boxes );
		$sig_box       = self::build_signature_box( $cose_sign1_bytes );

		$manifest = self::write_superbox(
			self::UUID_MANIFEST,
			$manifest_label,
			array( $claim_box, $assertion_box, $sig_box )
		);

		return self::write_superbox(
			self::UUID_MANIFEST_STORE,
			'c2pa',
			array( $manifest )
		);
	}
}
