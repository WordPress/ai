<?php
/**
 * COSE_Sign1 builder for C2PA signing.
 *
 * Builds RFC 9052 COSE_Sign1 structures with ES256 (ECDSA P-256) signing.
 * The output is CBOR-encoded and tagged with CBOR tag 18, ready for
 * inclusion in a JUMBF signature box.
 *
 * @package WordPress\AI\Experiments\Content_Provenance\C2PA
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Content_Provenance\C2PA;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * COSE_Sign1 builder with ES256 signing.
 *
 * @since x.x.x
 */
final class COSE_Sign1_Builder {

	/**
	 * COSE algorithm identifier for ES256 (ECDSA w/ SHA-256).
	 *
	 * @var int
	 */
	public const ALG_ES256 = -7;

	/**
	 * COSE header label for algorithm.
	 *
	 * @var int
	 */
	public const HEADER_ALG = 1;

	/**
	 * COSE header label for X.509 certificate chain.
	 *
	 * @var int
	 */
	public const HEADER_X5CHAIN = 33;

	/**
	 * PEM-encoded EC private key.
	 *
	 * @var string
	 */
	private string $private_key_pem;

	/**
	 * DER-encoded X.509 certificate.
	 *
	 * @var string
	 */
	private string $certificate_der;

	/**
	 * Payload bytes (CBOR-encoded claim).
	 *
	 * @var string
	 */
	private string $payload;

	/**
	 * CBOR-encoded protected headers.
	 *
	 * @var string
	 */
	private string $protected_headers;

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param string $private_key_pem  PEM-encoded EC P-256 private key.
	 * @param string $certificate_der  DER-encoded X.509 certificate.
	 * @param string $payload          CBOR-encoded claim bytes (the COSE payload).
	 */
	public function __construct( string $private_key_pem, string $certificate_der, string $payload ) {
		$this->private_key_pem   = $private_key_pem;
		$this->certificate_der   = $certificate_der;
		$this->payload           = $payload;
		$this->protected_headers = CBOR_Encoder::encode_map( array( self::HEADER_ALG => self::ALG_ES256 ) );
	}

	/**
	 * Builds the complete COSE_Sign1 structure.
	 *
	 * Returns CBOR-encoded tag(18, [protected, unprotected, payload, signature]).
	 *
	 * @since x.x.x
	 *
	 * @return string CBOR-encoded COSE_Sign1 bytes.
	 */
	public function build(): string {
		$sig_structure = $this->build_sig_structure();
		$signature_raw = $this->sign_es256( $sig_structure );

		// COSE_Sign1 = [protected, unprotected, payload, signature]
		// protected:   byte string of CBOR-encoded header map
		// unprotected: map with x5chain
		// payload:     byte string of claim CBOR
		// signature:   byte string of raw R||S

		$protected_bstr  = CBOR_Encoder::encode_byte_string( $this->protected_headers );
		$unprotected_map = CBOR_Encoder::encode_map(
			array( self::HEADER_X5CHAIN => CBOR_Encoder::encode_byte_string( $this->certificate_der ) )
		);
		$payload_bstr    = CBOR_Encoder::encode_byte_string( $this->payload );
		$signature_bstr  = CBOR_Encoder::encode_byte_string( $signature_raw );

		// Build the 4-element array manually for precise control.
		$array_content = $protected_bstr . $unprotected_map . $payload_bstr . $signature_bstr;
		$cose_array    = "\x84" . $array_content;

		// Wrap in CBOR tag 18.
		return CBOR_Encoder::encode_tagged( 18, array() ) [0] . $cose_array;
	}

	/**
	 * Builds the Sig_structure1 CBOR structure (to-be-signed bytes).
	 *
	 * Sig_structure1 = [
	 *   "Signature1",       -- context: tstr
	 *   body_protected: bstr,
	 *   external_aad: bstr (empty),
	 *   payload: bstr
	 * ]
	 *
	 * Constructs the array manually rather than passing pre-encoded byte
	 * strings through encode(), which would re-encode them as CBOR text
	 * strings (major type 3) instead of preserving byte strings (major
	 * type 2). This matches RFC 9052 and ensures cross-verifier compat.
	 *
	 * @since x.x.x
	 *
	 * @return string CBOR-encoded Sig_structure1.
	 */
	private function build_sig_structure(): string {
		$body = CBOR_Encoder::encode( 'Signature1' )
			. CBOR_Encoder::encode_byte_string( $this->protected_headers )
			. CBOR_Encoder::encode_byte_string( '' )
			. CBOR_Encoder::encode_byte_string( $this->payload );

		return "\x84" . $body;
	}

	/**
	 * Signs the Sig_structure1 bytes with ECDSA P-256 (ES256).
	 *
	 * PHP's openssl_sign() returns DER-encoded ECDSA signatures. COSE requires
	 * raw R||S format (64 bytes for P-256). This method handles the conversion.
	 *
	 * @since x.x.x
	 *
	 * @param string $to_be_signed CBOR-encoded Sig_structure1.
	 * @return string Raw signature bytes (R||S, 64 bytes).
	 *
	 * @throws \RuntimeException If signing fails.
	 */
	private function sign_es256( string $to_be_signed ): string {
		$key = openssl_pkey_get_private( $this->private_key_pem );
		if ( false === $key ) {
			throw new \RuntimeException( 'Failed to load EC private key.' );
		}

		$success = openssl_sign( $to_be_signed, $der_signature, $key, OPENSSL_ALGO_SHA256 );
		if ( ! $success ) {
			throw new \RuntimeException( 'openssl_sign failed: ' . (string) openssl_error_string() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception, never rendered.
		}

		return self::der_to_raw_ecdsa( $der_signature );
	}

	/**
	 * Converts a DER-encoded ECDSA signature to fixed-length R||S format.
	 *
	 * OpenSSL produces ASN.1 DER signatures: SEQUENCE { INTEGER r, INTEGER s }.
	 * COSE expects raw concatenated R and S as 32-byte big-endian unsigned
	 * integers, zero-padded on the left.
	 *
	 * @since x.x.x
	 *
	 * @param string $der_signature DER-encoded ECDSA signature.
	 * @return string 64-byte R||S signature.
	 *
	 * @throws \RuntimeException If the DER structure is invalid.
	 */
	public static function der_to_raw_ecdsa( string $der_signature ): string {
		$offset = 0;

		// SEQUENCE tag.
		if ( ord( $der_signature[ $offset ] ) !== 0x30 ) {
			throw new \RuntimeException( 'Invalid DER: expected SEQUENCE tag.' );
		}
		++$offset;

		// SEQUENCE length (skip it — we parse children directly).
		$seq_len = ord( $der_signature[ $offset ] );
		++$offset;
		if ( $seq_len > 0x80 ) {
			// Long form length (unlikely for ECDSA P-256, but handle it).
			$num_len_bytes = $seq_len & 0x7F;
			$offset       += $num_len_bytes;
		}

		// Parse INTEGER r.
		$r = self::parse_der_integer( $der_signature, $offset );

		// Parse INTEGER s.
		$s = self::parse_der_integer( $der_signature, $offset );

		// Pad or trim to exactly 32 bytes each.
		$r = self::pad_to_32( $r );
		$s = self::pad_to_32( $s );

		return $r . $s;
	}

	/**
	 * Parses a DER INTEGER and returns the raw unsigned bytes.
	 *
	 * @since x.x.x
	 *
	 * @param string $data   DER data.
	 * @param int    $offset Current offset (modified by reference).
	 * @return string Raw integer bytes (leading zeros stripped).
	 *
	 * @throws \RuntimeException If the DER structure is invalid.
	 */
	private static function parse_der_integer( string $data, int &$offset ): string {
		if ( ord( $data[ $offset ] ) !== 0x02 ) {
			throw new \RuntimeException( 'Invalid DER: expected INTEGER tag at offset ' . $offset ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception, never rendered.
		}
		++$offset;

		$length = ord( $data[ $offset ] );
		++$offset;

		$integer = substr( $data, $offset, $length );
		$offset += $length;

		// Strip leading zero byte added by DER for positive integers with high bit set.
		if ( strlen( $integer ) > 1 && ord( $integer[0] ) === 0x00 ) {
			$integer = substr( $integer, 1 );
		}

		return $integer;
	}

	/**
	 * Pads or trims an integer to exactly 32 bytes (P-256 field size).
	 *
	 * @since x.x.x
	 *
	 * @param string $bytes Raw integer bytes.
	 * @return string Exactly 32 bytes, left-padded with zeros.
	 */
	private static function pad_to_32( string $bytes ): string {
		$len = strlen( $bytes );

		if ( $len < 32 ) {
			return str_repeat( "\x00", 32 - $len ) . $bytes;
		}

		if ( $len > 32 ) {
			return substr( $bytes, $len - 32 );
		}

		return $bytes;
	}
}
