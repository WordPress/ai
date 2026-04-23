<?php
/**
 * COSE_Sign1 signature verifier.
 *
 * Parses RFC 9052 COSE_Sign1 structures and verifies ES256 (ECDSA P-256)
 * signatures against the embedded X.509 certificate. Used during C2PA
 * manifest verification to confirm the claim has not been tampered with.
 *
 * @package WordPress\AI\Experiments\Content_Provenance\C2PA
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Content_Provenance\C2PA;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * COSE_Sign1 signature verifier with ES256 support.
 *
 * @since x.x.x
 */
final class COSE_Sign1_Verifier {

	/**
	 * Verifies a COSE_Sign1 structure against its embedded certificate.
	 *
	 * Parses the CBOR-encoded COSE_Sign1 array, reconstructs the
	 * Sig_structure1 to-be-signed bytes, and verifies the ECDSA P-256
	 * signature using the certificate from the unprotected x5chain header.
	 *
	 * @since x.x.x
	 *
	 * @param string $cose_bytes Raw COSE_Sign1 bytes (CBOR tag 18 + 4-element array).
	 * @return array{valid: bool, error: string|null}
	 */
	public static function verify( string $cose_bytes ): array {
		$parsed = self::parse_cose_sign1( $cose_bytes );

		if ( is_wp_error( $parsed ) ) {
			return array(
				'valid' => false,
				'error' => $parsed->get_error_message(),
			);
		}

		// Reconstruct Sig_structure1 per RFC 9052: ["Signature1", bstr, bstr, bstr].
		// Constructed manually to ensure byte strings (major type 2), not text
		// strings (major type 3), for cross-verifier compatibility.
		$sig_structure = "\x84"
			. CBOR_Encoder::encode( 'Signature1' )
			. CBOR_Encoder::encode_byte_string( $parsed['protected_raw'] )
			. CBOR_Encoder::encode_byte_string( '' )
			. CBOR_Encoder::encode_byte_string( $parsed['payload_raw'] );

		// Convert raw R||S to DER format for openssl_verify.
		$der_signature = self::raw_to_der_ecdsa( $parsed['signature_raw'] );

		// Extract public key from the embedded certificate.
		$cert_pem = "-----BEGIN CERTIFICATE-----\n"
			. chunk_split( base64_encode( $parsed['certificate_der'] ), 64, "\n" )
			. '-----END CERTIFICATE-----';

		$cert_resource = openssl_x509_read( $cert_pem );

		if ( false === $cert_resource ) {
			return array(
				'valid' => false,
				'error' => 'Invalid embedded certificate.',
			);
		}

		$pubkey = openssl_pkey_get_public( $cert_resource );

		if ( false === $pubkey ) {
			return array(
				'valid' => false,
				'error' => 'Cannot extract public key from certificate.',
			);
		}

		$result = openssl_verify( $sig_structure, $der_signature, $pubkey, OPENSSL_ALGO_SHA256 );

		if ( 1 === $result ) {
			return array(
				'valid' => true,
				'error' => null,
			);
		}

		return array(
			'valid' => false,
			'error' => 'ECDSA signature verification failed.',
		);
	}

	/**
	 * Parses a COSE_Sign1 structure into its four components.
	 *
	 * COSE_Sign1 = tag(18, [protected, unprotected, payload, signature])
	 *
	 * @since x.x.x
	 *
	 * @param string $data COSE_Sign1 bytes.
	 * @return array{protected_raw: string, certificate_der: string, payload_raw: string, signature_raw: string}|\WP_Error
	 */
	private static function parse_cose_sign1( string $data ) {
		$offset = 0;
		$len    = strlen( $data );

		if ( $len < 4 ) {
			return new \WP_Error( 'cose_too_short', 'COSE_Sign1 data too short.' );
		}

		// CBOR tag 18 = 0xD2.
		if ( 0xD2 !== ord( $data[ $offset ] ) ) {
			return new \WP_Error( 'cose_no_tag', 'Missing CBOR tag 18.' );
		}
		++$offset;

		// Array of 4 elements = 0x84.
		if ( 0x84 !== ord( $data[ $offset ] ) ) {
			return new \WP_Error( 'cose_not_array4', 'Expected 4-element CBOR array.' );
		}
		++$offset;

		// [0] Protected headers (byte string).
		$protected_raw = self::read_cbor_byte_string( $data, $offset );

		if ( null === $protected_raw ) {
			return new \WP_Error( 'cose_bad_protected', 'Failed to read protected headers.' );
		}

		// [1] Unprotected headers (map) — extract x5chain certificate.
		$certificate_der = self::read_unprotected_x5chain( $data, $offset );

		if ( null === $certificate_der ) {
			return new \WP_Error( 'cose_no_cert', 'No x5chain certificate in unprotected headers.' );
		}

		// [2] Payload (byte string).
		$payload_raw = self::read_cbor_byte_string( $data, $offset );

		if ( null === $payload_raw ) {
			return new \WP_Error( 'cose_bad_payload', 'Failed to read payload.' );
		}

		// [3] Signature (byte string, 64 bytes for ES256).
		$signature_raw = self::read_cbor_byte_string( $data, $offset );

		if ( null === $signature_raw ) {
			return new \WP_Error( 'cose_bad_signature', 'Failed to read signature.' );
		}

		if ( 64 !== strlen( $signature_raw ) ) {
			return new \WP_Error(
				'cose_bad_sig_length',
				'Expected 64-byte ES256 signature, got ' . strlen( $signature_raw ) . '.'
			);
		}

		return array(
			'protected_raw'   => $protected_raw,
			'certificate_der' => $certificate_der,
			'payload_raw'     => $payload_raw,
			'signature_raw'   => $signature_raw,
		);
	}

	/**
	 * Reads a CBOR byte string (major type 2) at the current offset.
	 *
	 * @since x.x.x
	 *
	 * @param string $data   Raw bytes.
	 * @param int    $offset Current offset (modified by reference).
	 * @return string|null Byte string contents, or null on parse error.
	 */
	private static function read_cbor_byte_string( string $data, int &$offset ): ?string {
		if ( $offset >= strlen( $data ) ) {
			return null;
		}

		$initial    = ord( $data[ $offset ] );
		$major_type = ( $initial >> 5 ) & 0x07;
		$additional = $initial & 0x1F;

		if ( 2 !== $major_type ) {
			return null;
		}
		++$offset;

		$length = self::read_cbor_argument( $data, $offset, $additional );

		if ( null === $length || $offset + $length > strlen( $data ) ) {
			return null;
		}

		$bytes   = substr( $data, $offset, $length );
		$offset += $length;

		return $bytes;
	}

	/**
	 * Reads the unprotected headers map and extracts the x5chain certificate.
	 *
	 * Expected CBOR structure: map(N) { ... 33: bstr(certificate_der) ... }
	 *
	 * @since x.x.x
	 *
	 * @param string $data   Raw bytes.
	 * @param int    $offset Current offset (modified by reference).
	 * @return string|null Certificate DER bytes, or null if not found.
	 */
	private static function read_unprotected_x5chain( string $data, int &$offset ): ?string {
		if ( $offset >= strlen( $data ) ) {
			return null;
		}

		$initial    = ord( $data[ $offset ] );
		$major_type = ( $initial >> 5 ) & 0x07;
		$additional = $initial & 0x1F;

		// Must be a map (major type 5).
		if ( 5 !== $major_type ) {
			return null;
		}
		++$offset;

		$count = self::read_cbor_argument( $data, $offset, $additional );

		if ( null === $count ) {
			return null;
		}

		$certificate = null;

		for ( $i = 0; $i < $count; $i++ ) {
			// Read key (unsigned integer, major type 0).
			$key = self::read_cbor_uint( $data, $offset );

			if ( null === $key ) {
				return null;
			}

			// Read value (byte string, major type 2).
			$value = self::read_cbor_byte_string( $data, $offset );

			if ( null === $value ) {
				return null;
			}

			// COSE header label 33 = x5chain.
			if ( COSE_Sign1_Builder::HEADER_X5CHAIN !== $key ) {
				continue;
			}

			$certificate = $value;
		}

		return $certificate;
	}

	/**
	 * Reads a CBOR unsigned integer (major type 0) at the current offset.
	 *
	 * @since x.x.x
	 *
	 * @param string $data   Raw bytes.
	 * @param int    $offset Current offset (modified by reference).
	 * @return int|null Integer value, or null on parse error.
	 */
	private static function read_cbor_uint( string $data, int &$offset ): ?int {
		if ( $offset >= strlen( $data ) ) {
			return null;
		}

		$initial    = ord( $data[ $offset ] );
		$major_type = ( $initial >> 5 ) & 0x07;
		$additional = $initial & 0x1F;

		if ( 0 !== $major_type ) {
			return null;
		}
		++$offset;

		return self::read_cbor_argument( $data, $offset, $additional );
	}

	/**
	 * Reads a CBOR additional info argument value.
	 *
	 * Handles all argument sizes: tiny (0-23), 1-byte (24), 2-byte (25),
	 * 4-byte (26), and 8-byte (27).
	 *
	 * @since x.x.x
	 *
	 * @param string $data       Raw bytes.
	 * @param int    $offset     Current offset (modified by reference).
	 * @param int    $additional Additional info value from the initial byte.
	 * @return int|null Argument value, or null on parse error.
	 */
	private static function read_cbor_argument( string $data, int &$offset, int $additional ): ?int {
		if ( $additional <= 23 ) {
			return $additional;
		}

		$data_len = strlen( $data );

		if ( 24 === $additional ) {
			if ( $offset >= $data_len ) {
				return null;
			}
			$val = ord( $data[ $offset ] );
			++$offset;
			return $val;
		}

		if ( 25 === $additional ) {
			if ( $offset + 2 > $data_len ) {
				return null;
			}
			$unpacked = unpack( 'n', substr( $data, $offset, 2 ) );
			$offset  += 2;
			return false === $unpacked ? null : $unpacked[1];
		}

		if ( 26 === $additional ) {
			if ( $offset + 4 > $data_len ) {
				return null;
			}
			$unpacked = unpack( 'N', substr( $data, $offset, 4 ) );
			$offset  += 4;
			return false === $unpacked ? null : $unpacked[1];
		}

		if ( 27 === $additional ) {
			if ( $offset + 8 > $data_len ) {
				return null;
			}
			$unpacked = unpack( 'J', substr( $data, $offset, 8 ) );
			$offset  += 8;
			return false === $unpacked ? null : (int) $unpacked[1];
		}

		return null;
	}

	/**
	 * Converts a raw 64-byte R||S ECDSA signature to DER format.
	 *
	 * Reverse of COSE_Sign1_Builder::der_to_raw_ecdsa(). OpenSSL's
	 * openssl_verify() expects DER-encoded ECDSA signatures.
	 *
	 * @since x.x.x
	 *
	 * @param string $raw 64-byte R||S concatenated signature.
	 * @return string DER-encoded ECDSA signature.
	 */
	private static function raw_to_der_ecdsa( string $raw ): string {
		$r = substr( $raw, 0, 32 );
		$s = substr( $raw, 32, 32 );

		// Strip leading zero bytes but keep at least one byte.
		$r = ltrim( $r, "\x00" );
		if ( '' === $r ) {
			$r = "\x00";
		}

		$s = ltrim( $s, "\x00" );
		if ( '' === $s ) {
			$s = "\x00";
		}

		// DER integers are signed: prepend 0x00 if high bit is set.
		if ( ord( $r[0] ) & 0x80 ) {
			$r = "\x00" . $r;
		}

		if ( ord( $s[0] ) & 0x80 ) {
			$s = "\x00" . $s;
		}

		$r_tlv = "\x02" . chr( strlen( $r ) ) . $r;
		$s_tlv = "\x02" . chr( strlen( $s ) ) . $s;

		$sequence = $r_tlv . $s_tlv;

		return "\x30" . chr( strlen( $sequence ) ) . $sequence;
	}
}
