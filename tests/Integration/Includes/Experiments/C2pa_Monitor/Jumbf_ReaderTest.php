<?php
/**
 * Unit tests for the C2PA Monitor Jumbf_Reader.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\C2pa_Monitor
 */

declare( strict_types=1 );

namespace WordPress\AI\Tests\Integration\Includes\Experiments\C2pa_Monitor;

use WP_UnitTestCase;
use WordPress\AI\Experiments\C2pa_Monitor\Format_Detector;
use WordPress\AI\Experiments\C2pa_Monitor\Jumbf_Reader;
use WordPress\AI\Experiments\C2pa_Monitor\Manifest_Reader;

require_once __DIR__ . '/Fixtures.php';

/**
 * Verifies Jumbf_Reader walks the superbox tree and rejects malformed or
 * hostile stores with an \UnexpectedValueException.
 *
 * @since x.x.x
 */
class Jumbf_ReaderTest extends WP_UnitTestCase {
	/**
	 * Superbox labels, UUIDs, content boxes and nesting are reported.
	 */
	public function test_parses_superbox_tree(): void {
		$store = Fixtures::jumbf_superbox(
			'63327061',
			'c2pa',
			array(
				Fixtures::jumbf_superbox(
					'63326d61',
					'urn:uuid:one',
					array(
						Fixtures::jumbf_box( 'free', 'xyz' ),
						Fixtures::jumbf_superbox( '6332636c', 'c2pa.claim', array( Fixtures::jumbf_box( 'cbor', "\xA0" ) ) ),
					)
				),
			)
		);

		$root = ( new Jumbf_Reader() )->parse( $store );

		$this->assertSame( 'c2pa', $root['label'] );
		$this->assertSame( '6332706100110010800000aa00389b71', $root['uuid'] );
		$this->assertSame( array(), $root['boxes'] );
		$this->assertCount( 1, $root['children'] );

		$manifest = $root['children'][0];
		$this->assertSame( 'urn:uuid:one', $manifest['label'] );
		$this->assertSame( 'free', $manifest['boxes'][0]['type'] );
		$this->assertSame( 'xyz', substr( $store, $manifest['boxes'][0]['offset'], $manifest['boxes'][0]['length'] ) );

		$claim = $manifest['children'][0];
		$this->assertSame( 'c2pa.claim', $claim['label'] );
		$this->assertSame( 'cbor', $claim['boxes'][0]['type'] );
		$this->assertSame( "\xA0", substr( $store, $claim['boxes'][0]['offset'], $claim['boxes'][0]['length'] ) );
	}

	/**
	 * A description box without the label toggle yields a null label.
	 */
	public function test_unlabelled_superbox_has_null_label(): void {
		$jumd  = Fixtures::jumbf_box( 'jumd', str_repeat( "\x00", 16 ) . "\x01" );
		$store = Fixtures::jumbf_box( 'jumb', $jumd );

		$root = ( new Jumbf_Reader() )->parse( $store );

		$this->assertNull( $root['label'] );
	}

	/**
	 * LBox = 1 (64-bit XLBox) and LBox = 0 (to end of parent) are honoured.
	 */
	public function test_handles_extended_and_open_ended_box_sizes(): void {
		$jumd     = Fixtures::jumbf_box( 'jumd', str_repeat( "\x00", 16 ) . "\x03root\x00" );
		$extended = pack( 'N', 1 ) . 'cbor' . pack( 'NN', 0, 16 + 2 ) . "\x82\x01";
		$open     = pack( 'N', 0 ) . 'json' . '{}';
		$store    = Fixtures::jumbf_box( 'jumb', $jumd . $extended . $open );

		$root = ( new Jumbf_Reader() )->parse( $store );

		$this->assertSame( array( 'cbor', 'json' ), array_column( $root['boxes'], 'type' ) );
		$this->assertSame( "\x82\x01", substr( $store, $root['boxes'][0]['offset'], $root['boxes'][0]['length'] ) );
		$this->assertSame( '{}', substr( $store, $root['boxes'][1]['offset'], $root['boxes'][1]['length'] ) );
	}

	/**
	 * The real signed XCA.jpg store parses into one manifest with a claim
	 * and an assertion store.
	 */
	public function test_parses_real_manifest_store(): void {
		$path = dirname( __DIR__, 4 ) . '/fixtures/c2pa/XCA.jpg';
		if ( ! is_file( $path ) ) {
			$this->markTestSkipped( 'XCA.jpg fixture not found.' );
		}

		$location = ( new Format_Detector() )->find_manifest_location( $path, 'jpeg' );
		$this->assertIsArray( $location );
		$manifest = ( new Manifest_Reader() )->read( $path, $location );
		$this->assertNotNull( $manifest );

		$root = ( new Jumbf_Reader() )->parse( $manifest->bytes );

		$this->assertSame( 'c2pa', $root['label'] );
		$this->assertCount( 1, $root['children'] );
		$this->assertSame( 'contentauth:urn:uuid:ee7fc739-e619-44d3-a110-c9ff5dd2588d', $root['children'][0]['label'] );
		$this->assertSame(
			array( 'c2pa.assertions', 'c2pa.claim', 'c2pa.signature' ),
			array_column( $root['children'][0]['children'], 'label' )
		);
	}

	/**
	 * Malformed stores.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function data_malformed(): array {
		$jumd = Fixtures::jumbf_box( 'jumd', str_repeat( "\x00", 16 ) . "\x03x\x00" );

		return array(
			'empty'                     => array( '' ),
			'truncated header'          => array( "\x00\x00\x00\x10jum" ),
			'not a superbox'            => array( Fixtures::jumbf_box( 'cbor', "\xA0" ) ),
			'missing description box'   => array( Fixtures::jumbf_box( 'jumb', Fixtures::jumbf_box( 'cbor', "\xA0" ) ) ),
			'truncated description box' => array( Fixtures::jumbf_box( 'jumb', Fixtures::jumbf_box( 'jumd', 'short' ) ) ),
			'box overruns store'        => array( pack( 'N', 4096 ) . 'jumb' . $jumd ),
			'child overruns parent'     => array( Fixtures::jumbf_box( 'jumb', $jumd . pack( 'N', 64 ) . 'cbor' . "\xA0" ) ),
			'lbox below header size'    => array( Fixtures::jumbf_box( 'jumb', $jumd . pack( 'N', 4 ) . 'cbor' ) ),
			'xlbox above 4 GiB'         => array( Fixtures::jumbf_box( 'jumb', $jumd . pack( 'N', 1 ) . 'cbor' . pack( 'NN', 1, 0 ) ) ),
		);
	}

	/**
	 * Malformed stores raise \UnexpectedValueException rather than a PHP error.
	 *
	 * @dataProvider data_malformed
	 *
	 * @param string $bytes Store bytes.
	 */
	public function test_rejects_malformed_store( string $bytes ): void {
		$this->expectException( \UnexpectedValueException::class );
		( new Jumbf_Reader() )->parse( $bytes );
	}

	/**
	 * Superbox nesting deeper than the bound is rejected.
	 */
	public function test_rejects_excessive_nesting(): void {
		$store = Fixtures::jumbf_superbox( '63327061', 'leaf', array() );
		for ( $i = 0; $i < 40; $i++ ) {
			$store = Fixtures::jumbf_superbox( '63327061', 'level', array( $store ) );
		}

		$this->expectException( \UnexpectedValueException::class );
		( new Jumbf_Reader() )->parse( $store );
	}

	/**
	 * A store with more boxes than the bound is rejected.
	 */
	public function test_rejects_too_many_boxes(): void {
		$store = Fixtures::jumbf_superbox( '63327061', 'c2pa', array_fill( 0, 20000, Fixtures::jumbf_box( 'free', '' ) ) );

		$this->expectException( \UnexpectedValueException::class );
		( new Jumbf_Reader() )->parse( $store );
	}
}
