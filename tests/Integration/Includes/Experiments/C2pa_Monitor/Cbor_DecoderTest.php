<?php
/**
 * Unit tests for the C2PA Monitor Cbor_Decoder.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\C2pa_Monitor
 */

declare( strict_types=1 );

namespace WordPress\AI\Tests\Integration\Includes\Experiments\C2pa_Monitor;

use WP_UnitTestCase;
use WordPress\AI\Experiments\C2pa_Monitor\Cbor_Decoder;

require_once __DIR__ . '/Fixtures.php';

/**
 * Verifies Cbor_Decoder decodes the CBOR subset used by C2PA and rejects
 * malformed or hostile input with an \UnexpectedValueException.
 *
 * @since x.x.x
 */
class Cbor_DecoderTest extends WP_UnitTestCase {
	/**
	 * Decodes $hex with a fresh decoder.
	 *
	 * @param string $hex CBOR bytes as hex.
	 * @return mixed
	 */
	private function decode_hex( string $hex ) {
		return ( new Cbor_Decoder() )->decode( (string) hex2bin( $hex ) );
	}

	/**
	 * RFC 8949 Appendix A examples for the supported types.
	 *
	 * @return array<string, array{0: string, 1: mixed}>
	 */
	public function data_rfc_examples(): array {
		return array(
			'zero'            => array( '00', 0 ),
			'small uint'      => array( '17', 23 ),
			'uint8'           => array( '1818', 24 ),
			'uint16'          => array( '1903e8', 1000 ),
			'uint32'          => array( '1a000f4240', 1000000 ),
			'uint64'          => array( '1b000000e8d4a51000', 1000000000000 ),
			'negative'        => array( '20', -1 ),
			'negative uint16' => array( '3903e7', -1000 ),
			'false'           => array( 'f4', false ),
			'true'            => array( 'f5', true ),
			'null'            => array( 'f6', null ),
			'undefined'       => array( 'f7', null ),
			'half float'      => array( 'f93e00', 1.5 ),
			'half subnormal'  => array( 'f90001', 5.960464477539063e-8 ),
			'single float'    => array( 'fa47c35000', 100000.0 ),
			'double float'    => array( 'fb3ff199999999999a', 1.1 ),
			'empty text'      => array( '60', '' ),
			'text'            => array( '6449455446', 'IETF' ),
			'utf8 text'       => array( '62c3bc', "\u{00fc}" ),
			'bytes'           => array( '4401020304', "\x01\x02\x03\x04" ),
			'array'           => array( '83010203', array( 1, 2, 3 ) ),
			'nested array'    => array( '8301820203820405', array( 1, array( 2, 3 ), array( 4, 5 ) ) ),
			'map'             => array( 'a26161016162820203', array( 'a' => 1, 'b' => array( 2, 3 ) ) ),
			'int keyed map'   => array( 'a201020304', array( 1 => 2, 3 => 4 ) ),
			'date tag'        => array( 'c074323031332d30332d32315432303a30343a30305a', '2013-03-21T20:04:00Z' ),
			'nested tags'     => array( 'd818d8184101', "\x01" ),
		);
	}

	/**
	 * Supported items decode to their PHP equivalents.
	 *
	 * @dataProvider data_rfc_examples
	 *
	 * @param string $hex      CBOR bytes as hex.
	 * @param mixed  $expected Decoded value.
	 */
	public function test_decodes_rfc_examples( string $hex, $expected ): void {
		$this->assertSame( $expected, $this->decode_hex( $hex ) );
	}

	/**
	 * A uint64 above PHP_INT_MAX is returned as a float rather than wrapping.
	 */
	public function test_uint64_above_int_max_decodes_to_float(): void {
		$this->assertSame( 18446744073709551615.0, $this->decode_hex( '1bffffffffffffffff' ) );
	}

	/**
	 * Only the bytes in [offset, offset + length) are read.
	 */
	public function test_decodes_within_offset_and_length(): void {
		$bytes = 'junk' . Fixtures::cbor( array( 'action' => 'c2pa.created' ) ) . 'trailer';

		$value = ( new Cbor_Decoder() )->decode( $bytes, 4, strlen( $bytes ) - 4 - strlen( 'trailer' ) );

		$this->assertSame( array( 'action' => 'c2pa.created' ), $value );
	}

	/**
	 * Malformed and hostile inputs.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function data_malformed(): array {
		return array(
			'truncated uint16'          => array( '19 03' ),
			'text longer than data'     => array( '65 4945' ),
			'huge declared text length' => array( '7a ffffffff 41' ),
			'huge declared array count' => array( '9a ffffffff 01' ),
			'map count past data'       => array( 'a2 0101' ),
			'invalid utf8 text'         => array( '62 c328' ),
			'indefinite array'          => array( '9f 01 ff' ),
			'indefinite text'           => array( '7f 6161 ff' ),
			'break outside container'   => array( 'ff' ),
			'reserved argument'         => array( '1c' ),
			'array map key'             => array( 'a1 8101 01' ),
			'missing array item'        => array( '82 01' ),
		);
	}

	/**
	 * Malformed input raises \UnexpectedValueException rather than a PHP error.
	 *
	 * @dataProvider data_malformed
	 *
	 * @param string $hex CBOR bytes as hex, spaces ignored.
	 */
	public function test_rejects_malformed_input( string $hex ): void {
		$this->expectException( \UnexpectedValueException::class );
		$this->decode_hex( str_replace( ' ', '', $hex ) );
	}

	/**
	 * Nesting deeper than the bound is rejected before the stack grows.
	 */
	public function test_rejects_excessive_nesting(): void {
		$this->expectException( \UnexpectedValueException::class );
		$this->decode_hex( str_repeat( '81', 200 ) . '01' );
	}

	/**
	 * Empty input and out-of-range windows are rejected.
	 */
	public function test_rejects_empty_and_out_of_range_input(): void {
		$decoder = new Cbor_Decoder();

		foreach ( array( array( '', 0, null ), array( "\x01", 0, 2 ), array( "\x01", 1, null ) ) as $case ) {
			try {
				$decoder->decode( $case[0], $case[1], $case[2] );
				$this->fail( 'Expected an exception for an empty or out-of-range window.' );
			} catch ( \UnexpectedValueException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}
}
