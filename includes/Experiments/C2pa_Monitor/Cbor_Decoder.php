<?php
/**
 * Minimal CBOR (RFC 8949) decoder for C2PA claims and assertions.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\C2pa_Monitor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decodes the subset of CBOR used by C2PA claims and assertions.
 *
 * Indefinite-length items are unused by C2PA and rejected.
 *
 * @since x.x.x
 */
class Cbor_Decoder {
	/**
	 * Deepest array/map/tag nesting to follow.
	 *
	 * @var int
	 */
	private const MAX_DEPTH = 32;

	/**
	 * Most data items to decode per call.
	 *
	 * Each item becomes a PHP value tens of times larger than its encoding,
	 * so this bounds memory, not just the declared lengths.
	 *
	 * @var int
	 */
	private const MAX_ITEMS = 10000;

	/**
	 * Buffer being decoded.
	 *
	 * @var string
	 */
	private string $bytes = '';

	/**
	 * Read cursor.
	 *
	 * @var int
	 */
	private int $offset = 0;

	/**
	 * Offset just past the last byte that may be read.
	 *
	 * @var int
	 */
	private int $end = 0;

	/**
	 * Data items decoded so far.
	 *
	 * @var int
	 */
	private int $items = 0;

	/**
	 * Decodes the first CBOR data item in $bytes[$offset, $offset + $length).
	 *
	 * @since x.x.x
	 *
	 * @param string   $bytes  Buffer holding the CBOR item.
	 * @param int      $offset Offset of the item.
	 * @param int|null $length Bytes available to the item; null for the rest of the buffer.
	 * @return mixed
	 *
	 * @throws \UnexpectedValueException When the item is malformed, truncated or exceeds a bound.
	 */
	public function decode( string $bytes, int $offset = 0, ?int $length = null ) {
		$total = strlen( $bytes );
		$end   = null === $length ? $total : $offset + $length;

		if ( $offset < 0 || $end > $total || $end <= $offset ) {
			throw new \UnexpectedValueException( esc_html__( 'The CBOR data is empty or out of range.', 'ai' ) );
		}

		$this->bytes  = $bytes;
		$this->offset = $offset;
		$this->end    = $end;
		$this->items  = 0;

		return $this->read_item( 0 );
	}

	/**
	 * Reads one data item at the cursor.
	 *
	 * @since x.x.x
	 *
	 * @param int $depth Current nesting depth.
	 * @return mixed
	 *
	 * @throws \UnexpectedValueException When the item is malformed, truncated or exceeds a bound.
	 */
	private function read_item( int $depth ) {
		if ( ++$this->items > self::MAX_ITEMS ) {
			throw new \UnexpectedValueException( esc_html__( 'The CBOR data contains too many items.', 'ai' ) );
		}

		if ( $depth > self::MAX_DEPTH ) {
			throw new \UnexpectedValueException( esc_html__( 'The CBOR data is nested too deeply.', 'ai' ) );
		}

		$initial = ord( $this->take( 1 ) );
		$major   = $initial >> 5;
		$info    = $initial & 0x1F;

		switch ( $major ) {
			case 0:
				return $this->read_argument( $info );

			case 1:
				$value = $this->read_argument( $info );
				return is_int( $value ) ? -1 - $value : -1.0 - $value;

			case 2:
				return $this->take( $this->read_length( $info ) );

			case 3:
				$text = $this->take( $this->read_length( $info ) );
				if ( ! wp_is_valid_utf8( $text ) ) {
					throw new \UnexpectedValueException( esc_html__( 'A CBOR text string is not valid UTF-8.', 'ai' ) );
				}
				return $text;

			case 4:
				$count = $this->read_length( $info );
				$items = array();
				for ( $i = 0; $i < $count; $i++ ) {
					$items[] = $this->read_item( $depth + 1 );
				}
				return $items;

			case 5:
				// Every key and value takes at least one byte.
				$count = $this->read_length( $info, 2 );
				$map   = array();
				for ( $i = 0; $i < $count; $i++ ) {
					$key = $this->read_item( $depth + 1 );
					if ( ! is_int( $key ) && ! is_string( $key ) ) {
						throw new \UnexpectedValueException( esc_html__( 'A CBOR map key is not an integer or string.', 'ai' ) );
					}
					$map[ $key ] = $this->read_item( $depth + 1 );
				}
				return $map;

			case 6:
				$this->read_argument( $info );
				return $this->read_item( $depth + 1 );

			default:
				return $this->read_simple( $info );
		}
	}

	/**
	 * Reads a major type 7 simple value or float.
	 *
	 * @since x.x.x
	 *
	 * @param int $info Additional information bits of the initial byte.
	 * @return bool|float|null
	 *
	 * @throws \UnexpectedValueException When the value is reserved or indefinite.
	 */
	private function read_simple( int $info ) {
		switch ( $info ) {
			case 20:
				return false;
			case 21:
				return true;
			case 22:
			case 23:
				return null;
			case 24:
				// Unassigned one-byte simple value.
				$this->take( 1 );
				return null;
			case 25:
				return self::decode_half( (int) $this->take_unpacked( 'n', 2 ) );
			case 26:
				return (float) $this->take_unpacked( 'G', 4 );
			case 27:
				return (float) $this->take_unpacked( 'E', 8 );
		}

		if ( $info < 20 ) {
			// Unassigned simple value.
			return null;
		}

		throw new \UnexpectedValueException( esc_html__( 'The CBOR data uses an unsupported encoding.', 'ai' ) );
	}

	/**
	 * Reads the argument that follows an initial byte.
	 *
	 * @since x.x.x
	 *
	 * @param int $info Additional information bits of the initial byte.
	 * @return int|float
	 *
	 * @throws \UnexpectedValueException When the argument is reserved or indefinite.
	 */
	private function read_argument( int $info ) {
		if ( $info < 24 ) {
			return $info;
		}

		switch ( $info ) {
			case 24:
				return ord( $this->take( 1 ) );
			case 25:
				return (int) $this->take_unpacked( 'n', 2 );
			case 26:
				return $this->take_uint32();
			case 27:
				$high = $this->take_uint32();
				$low  = $this->take_uint32();
				if ( is_int( $high ) && is_int( $low ) && PHP_INT_SIZE >= 8 && $high <= 0x7FFFFFFF ) {
					return ( $high << 32 ) | $low;
				}
				return $high * 4294967296.0 + $low;
		}

		throw new \UnexpectedValueException( esc_html__( 'The CBOR data uses an unsupported encoding.', 'ai' ) );
	}

	/**
	 * Reads a length or count, rejecting values larger than the remaining bytes.
	 *
	 * @since x.x.x
	 *
	 * @param int $info          Additional information bits of the initial byte.
	 * @param int $min_item_size Minimum bytes each counted unit occupies.
	 * @return int
	 *
	 * @throws \UnexpectedValueException When the length exceeds the remaining bytes.
	 */
	private function read_length( int $info, int $min_item_size = 1 ): int {
		$length = $this->read_argument( $info );

		if ( ! is_int( $length ) || $length > intdiv( $this->end - $this->offset, $min_item_size ) ) {
			throw new \UnexpectedValueException( esc_html__( 'A CBOR item declares a length larger than the data.', 'ai' ) );
		}

		return $length;
	}

	/**
	 * Consumes $length bytes at the cursor.
	 *
	 * @since x.x.x
	 *
	 * @param int $length Bytes to consume.
	 * @return string
	 *
	 * @throws \UnexpectedValueException When fewer than $length bytes remain.
	 */
	private function take( int $length ): string {
		if ( $length > $this->end - $this->offset ) {
			throw new \UnexpectedValueException( esc_html__( 'The CBOR data is truncated.', 'ai' ) );
		}

		$chunk         = (string) substr( $this->bytes, $this->offset, $length );
		$this->offset += $length;

		return $chunk;
	}

	/**
	 * Consumes a big-endian unsigned 32-bit integer at the cursor.
	 *
	 * On 32-bit PHP, unpack( 'N' ) returns values of 2^31 and above as negative
	 * integers; those are returned as floats instead, so lengths never go negative.
	 *
	 * @since x.x.x
	 *
	 * @return int|float
	 *
	 * @throws \UnexpectedValueException When fewer than 4 bytes remain.
	 */
	private function take_uint32() {
		$value = (int) $this->take_unpacked( 'N', 4 );

		return $value < 0 ? $value + 4294967296.0 : $value;
	}

	/**
	 * Consumes $length bytes at the cursor and unpacks them with $format.
	 *
	 * @since x.x.x
	 *
	 * @param string $format unpack() format code for a single value.
	 * @param int    $length Bytes to consume.
	 * @return int|float
	 *
	 * @throws \UnexpectedValueException When fewer than $length bytes remain.
	 */
	private function take_unpacked( string $format, int $length ) {
		$unpacked = unpack( $format, $this->take( $length ) );
		if ( false === $unpacked ) {
			throw new \UnexpectedValueException( esc_html__( 'The CBOR data is truncated.', 'ai' ) );
		}

		return $unpacked[1];
	}

	/**
	 * Converts an IEEE 754 half-precision value to a float.
	 *
	 * @since x.x.x
	 *
	 * @param int $half 16-bit encoded value.
	 * @return float
	 */
	private static function decode_half( int $half ): float {
		$exponent = ( $half >> 10 ) & 0x1F;
		$mantissa = $half & 0x3FF;

		if ( 0 === $exponent ) {
			$value = $mantissa * 2 ** -24;
		} elseif ( 31 === $exponent ) {
			$value = 0 === $mantissa ? INF : NAN;
		} else {
			$value = ( $mantissa + 1024 ) * 2 ** ( $exponent - 25 );
		}

		return $half & 0x8000 ? -$value : (float) $value;
	}
}
