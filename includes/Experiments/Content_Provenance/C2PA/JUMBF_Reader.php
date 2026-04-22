<?php
/**
 * JUMBF box reader for C2PA manifest store parsing.
 *
 * Implements the subset of ISO 19566-5 (JUMBF) required to extract
 * COSE_Sign1 signatures and CBOR claims from C2PA manifest stores
 * for verification.
 *
 * @package WordPress\AI\Experiments\Content_Provenance\C2PA
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Content_Provenance\C2PA;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * JUMBF box reader for C2PA manifest store parsing.
 *
 * @since x.x.x
 */
final class JUMBF_Reader {

	/**
	 * Extracts the COSE_Sign1 bytes from a JUMBF manifest store.
	 *
	 * Walks the JUMBF box hierarchy to locate the signature superbox
	 * (identified by UUID_SIGNATURE) and returns the raw COSE_Sign1 bytes
	 * from its CBOR content box.
	 *
	 * @since x.x.x
	 *
	 * @param string $jumbf_bytes Complete JUMBF manifest store bytes.
	 * @return string|null COSE_Sign1 bytes, or null if not found.
	 */
	public static function extract_cose_signature( string $jumbf_bytes ): ?string {
		return self::find_cbor_content_by_uuid( $jumbf_bytes, JUMBF_Writer::UUID_SIGNATURE );
	}

	/**
	 * Finds the CBOR content of a superbox identified by its description UUID.
	 *
	 * Searches up to three levels deep: manifest store, manifest, target superbox.
	 *
	 * @since x.x.x
	 *
	 * @param string $data        JUMBF bytes.
	 * @param string $target_uuid 16-byte UUID to search for.
	 * @return string|null Content bytes of the first 'cbor' child box, or null.
	 */
	private static function find_cbor_content_by_uuid( string $data, string $target_uuid ): ?string {
		// Level 0: Parse the outermost manifest store superbox.
		$store = self::read_box_header( $data, 0 );

		if ( null === $store || 'jumb' !== $store['type'] ) {
			return null;
		}

		// Iterate children of the manifest store (skip jumd, find jumb manifest).
		$store_children = self::iterate_child_boxes( $data, $store['content_offset'], $store['content_length'] );

		foreach ( $store_children as $child ) {
			if ( 'jumb' !== $child['type'] ) {
				continue;
			}

			// Level 1: This is a manifest superbox. Search its children.
			$manifest_children = self::iterate_child_boxes( $data, $child['content_offset'], $child['content_length'] );

			foreach ( $manifest_children as $mchild ) {
				if ( 'jumb' !== $mchild['type'] ) {
					continue;
				}

				// Level 2: Check this superbox's description UUID.
				$uuid = self::get_superbox_uuid( $data, $mchild['content_offset'], $mchild['content_length'] );

				if ( null !== $uuid && $uuid === $target_uuid ) {
					return self::extract_first_cbor_content( $data, $mchild['content_offset'], $mchild['content_length'] );
				}
			}
		}

		return null;
	}

	/**
	 * Reads a JUMBF box header at the given byte offset.
	 *
	 * Returns the box size, four-character type code, and content region.
	 * Handles both standard (32-bit) and extended (64-bit) box sizes.
	 *
	 * @since x.x.x
	 *
	 * @param string $data   Raw bytes.
	 * @param int    $offset Start offset of the box.
	 * @return array{size: int, type: string, content_offset: int, content_length: int}|null
	 */
	private static function read_box_header( string $data, int $offset ): ?array {
		$data_len = strlen( $data );

		if ( $offset + 8 > $data_len ) {
			return null;
		}

		$unpacked = unpack( 'Nsize', substr( $data, $offset, 4 ) );

		if ( false === $unpacked ) {
			return null;
		}

		$size        = $unpacked['size'];
		$type        = substr( $data, $offset + 4, 4 );
		$header_size = 8;

		// Extended 64-bit size when the 32-bit size field is 1.
		if ( 1 === $size ) {
			if ( $offset + 16 > $data_len ) {
				return null;
			}

			$unpacked = unpack( 'Jsize', substr( $data, $offset + 8, 8 ) );

			if ( false === $unpacked ) {
				return null;
			}

			$size        = (int) $unpacked['size'];
			$header_size = 16;
		}

		if ( $size < $header_size ) {
			return null;
		}

		return array(
			'size'           => $size,
			'type'           => $type,
			'content_offset' => $offset + $header_size,
			'content_length' => $size - $header_size,
		);
	}

	/**
	 * Iterates child boxes within a parent box's content region.
	 *
	 * @since x.x.x
	 *
	 * @param string $data   Raw bytes.
	 * @param int    $start  Start offset of the content region.
	 * @param int    $length Length of the content region.
	 * @return array<int, array{size: int, type: string, content_offset: int, content_length: int}>
	 */
	private static function iterate_child_boxes( string $data, int $start, int $length ): array {
		$boxes  = array();
		$offset = $start;
		$end    = $start + $length;

		while ( $offset < $end ) {
			$box = self::read_box_header( $data, $offset );

			if ( null === $box || $box['size'] <= 0 ) {
				break;
			}

			$boxes[] = $box;
			$offset += $box['size'];
		}

		return $boxes;
	}

	/**
	 * Reads the UUID from the first 'jumd' description box within a superbox.
	 *
	 * @since x.x.x
	 *
	 * @param string $data   Raw bytes.
	 * @param int    $start  Content offset of the parent superbox.
	 * @param int    $length Content length of the parent superbox.
	 * @return string|null 16-byte UUID string, or null if no description box found.
	 */
	private static function get_superbox_uuid( string $data, int $start, int $length ): ?string {
		$children = self::iterate_child_boxes( $data, $start, $length );

		foreach ( $children as $child ) {
			if ( 'jumd' === $child['type'] && $child['content_length'] >= 16 ) {
				return substr( $data, $child['content_offset'], 16 );
			}
		}

		return null;
	}

	/**
	 * Extracts the content of the first 'cbor' box within a superbox.
	 *
	 * @since x.x.x
	 *
	 * @param string $data   Raw bytes.
	 * @param int    $start  Content offset of the parent superbox.
	 * @param int    $length Content length of the parent superbox.
	 * @return string|null CBOR content bytes, or null if not found.
	 */
	private static function extract_first_cbor_content( string $data, int $start, int $length ): ?string {
		$children = self::iterate_child_boxes( $data, $start, $length );

		foreach ( $children as $child ) {
			if ( 'cbor' === $child['type'] ) {
				return substr( $data, $child['content_offset'], $child['content_length'] );
			}
		}

		return null;
	}
}
